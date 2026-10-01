<?php
/**
 * routes/agent.php - 客服工作台（tenant + agent 通用）
 * 所有查询强制 tenant_id 过滤；agent 只能看到未分配 + 自己的会话
 */
declare(strict_types=1);

/** 会话可见性 SQL 片段：agent 限定（agent_id=0 OR 自己） */
function session_visible_sql(array $ctx): string
{
    return $ctx['role'] === 'agent' ? ' AND (s.agent_id = 0 OR s.agent_id = ' . (int)$ctx['id'] . ')' : '';
}

/** 校验会话归属并返回行（不存在/越权 → 404） */
function session_of(array $ctx, int $sid): array
{
    $sql = 'SELECT * FROM v_sessions s WHERE s.id = ? AND s.tenant_id = ?' . session_visible_sql($ctx);
    $st = db()->prepare($sql);
    $st->execute([$sid, $ctx['tenant_id']]);
    $s = $st->fetch();
    if (!$s) json_error('会话不存在', 400);
    return $s;
}

/* ---------- 会话列表 ---------- */
function handle_sessions(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $pdo = db();
    $cfg = config();
    $onlineSql = "CASE WHEN s.status = 'closed' THEN 'closed'
        WHEN s.last_online_at IS NOT NULL AND s.last_online_at > DATE_SUB(NOW(), INTERVAL " . (int)$cfg['session_online_secs'] . " SECOND) THEN 'online'
        ELSE 'offline' END";
    $sql = "SELECT s.id, s.agent_id, a.nickname AS agent_name, s.visitor_name AS name,
        (SELECT value FROM contacts WHERE session_id = s.id AND type = 'note' LIMIT 1) AS note,
        s.ip, s.from_url AS `from`, s.last_msg AS lastMsg, s.last_time AS lastTime, s.unread, s.source,
        $onlineSql AS status,
        (SELECT COUNT(*) FROM messages WHERE session_id = s.id) AS message_count
        FROM v_sessions s LEFT JOIN agents a ON a.id = s.agent_id AND a.tenant_id = s.tenant_id
        WHERE s.tenant_id = ? AND s.status != 'closed'" . session_visible_sql($ctx) . "
        ORDER BY s.last_time DESC LIMIT 500";
    $rows = $pdo->prepare($sql);
    $rows->execute([$ctx['tenant_id']]);
    $out = $rows->fetchAll();
    foreach ($out as &$r) {
        $r['lastTime'] = dt_to_iso($r['lastTime']);
        $r['unread'] = (int)$r['unread'];
        $r['agent_id'] = (int)$r['agent_id'];
        $r['message_count'] = (int)$r['message_count'];
    }
    unset($r);
    json_out($out);
}

/* ---------- 同租户客服列表（转接用） ---------- */
function handle_agents_list(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $st = db()->prepare("SELECT id, username, nickname, avatar, work_status, status FROM agents WHERE tenant_id = ? AND status = 'active' ORDER BY id");
    $st->execute([$ctx['tenant_id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    unset($r);
    json_out(['agents' => $rows, 'me' => ['role' => $ctx['role'], 'id' => $ctx['id'],
        'nickname' => $ctx['name'], 'username' => $ctx['row']['username'] ?? $ctx['kami'] ?? '']]);
}

/* ---------- 会话消息 ---------- */
function handle_session_messages(string $method, array $m): void
{
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $pdo = db();

    if ($method === 'GET') {
        $since = (int)input('since', 0);
        $sql = 'SELECT id, session_id, from_type AS `from`, sender_id, sender_name, text, msg_type, attachments, recalled_at, created_at FROM messages WHERE session_id = ?' . ($since > 0 ? ' AND id > ?' : '') . ' ORDER BY id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($since > 0 ? [$sid, $since] : [$sid]);
        json_out(['messages' => array_map('to_msg', $st->fetchAll())]);
    }

    if ($method === 'POST') {
        $text = mb_substr(trim((string)input('text', '')), 0, 2000);
        $attachArr = input('attachments', []);
        $attachArr = is_array($attachArr) ? $attachArr : [];
        if ($text === '' && !$attachArr) json_error('消息不能为空');

        // 记录前先取会话（拿 agent_id 判断接手）
        $sess = session_of($ctx, $sid);
        $now = now_dt();
        $msgType = resolve_msg_type($text, $attachArr);
        $attachJson = $attachArr ? json_encode($attachArr, JSON_UNESCAPED_UNICODE) : '';
        $senderName = $ctx['name'] ?: '客服';

        $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_id, sender_name, text, msg_type, attachments, created_at) VALUES (?,?,'agent',?,?,?,?,?,?)")
            ->execute([$ctx['tenant_id'], $sid, $ctx['id'], $senderName, $text, $msgType, $attachJson, $now]);
        $mid = (int)$pdo->lastInsertId();

        // 未分配会话：开启自动接手的客服发首条消息时接手
        $newAgentId = (int)$sess['agent_id'];
        if ($newAgentId === 0 && $ctx['role'] === 'agent' && (int)$ctx['row']['auto_accept'] === 1) {
            $newAgentId = $ctx['id'];
            $pdo->prepare('UPDATE v_sessions SET agent_id = ? WHERE id = ?')->execute([$newAgentId, $sid]);
        }
        $pdo->prepare('UPDATE v_sessions SET last_msg = ?, last_time = ?, unread = 0 WHERE id = ?')
            ->execute([msg_summary($text, $attachArr), $now, $sid]);

        json_out(['message' => ['id' => $mid, 'from' => 'agent', 'sender_id' => $ctx['id'], 'sender_name' => $senderName,
            'text' => $text, 'time' => dt_to_iso($now), 'session_id' => $sid, 'msg_type' => $msgType, 'attachments' => $attachArr]]);
    }

    json_error('方法不允许', 405);
}

/* ---------- 修改访客名称 ---------- */
function handle_session_rename(string $method, array $m): void
{
    if ($method !== 'PUT') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $name = mb_substr(trim((string)input('name', '')), 0, 30);
    if ($name === '') json_error('名称不能为空');
    db()->prepare('UPDATE v_sessions SET visitor_name = ? WHERE id = ?')->execute([$name, $sid]);
    json_out(['ok' => true, 'name' => $name]);
}

/* ---------- 关闭会话 ---------- */
function handle_session_close(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $pdo = db();
    $pdo->prepare("UPDATE v_sessions SET status = 'closed' WHERE id = ?")->execute([$sid]);
    $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, created_at) VALUES (?,?,'system','系统','会话已结束','text',?)")
        ->execute([$ctx['tenant_id'], $sid, now_dt()]);
    json_out(['ok' => true]);
}

/* ---------- 标记已读 ---------- */
function handle_session_read(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    db()->prepare('UPDATE v_sessions SET unread = 0 WHERE id = ?')->execute([$sid]);
    json_out(['ok' => true]);
}

/* ---------- 撤回消息（2 分钟内自己的消息） ---------- */
function handle_session_recall(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $msgId = (int)input('msg_id', 0);
    if (!$msgId) json_error('缺少 msg_id');
    $st = db()->prepare('SELECT * FROM messages WHERE id = ? AND session_id = ? AND tenant_id = ?');
    $st->execute([$msgId, $sid, $ctx['tenant_id']]);
    $msg = $st->fetch();
    if (!$msg) json_error('消息不存在', 400);
    if ($msg['recalled_at']) json_out(['ok' => true]);
    $own = $ctx['role'] === 'tenant' || (int)$msg['sender_id'] === (int)$ctx['id'];
    if (!$own || $msg['from_type'] !== 'agent') json_error('只能撤回自己发送的客服消息', 403);
    if (time() - strtotime($msg['created_at']) > 120) json_error('超过 2 分钟，无法撤回', 403);
    db()->prepare('UPDATE messages SET recalled_at = ?, text = ?, attachments = ? WHERE id = ?')
        ->execute([now_dt(), '消息已撤回', '', $msgId]);
    json_out(['ok' => true, 'msg_id' => $msgId]);
}

/* ---------- 拉黑该会话访客 IP ---------- */
function handle_session_block(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace(); // 统一补 tenant_id（tenant/agent 均可操作）
    $s = session_of($ctx, $sid);
    $ip = $s['ip'] ?: get_client_ip();
    if (!$ip) json_error('该会话无 IP 记录');
    try {
        db()->prepare('INSERT IGNORE INTO ip_blacklist (tenant_id, ip, reason) VALUES (?,?,?)')
            ->execute([$ctx['tenant_id'], $ip, '会话内手动拉黑']);
    } catch (\Throwable $e) {}
    json_out(['ok' => true, 'ip' => $ip]);
}

/* ---------- 转接 ---------- */
function handle_session_transfer(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $toAgent = (int)input('to_agent_id', -1);
    if ($toAgent === -1) json_error('请选择转接目标');

    $pdo = db();
    $target = null;
    if ($toAgent > 0) {
        $st = $pdo->prepare("SELECT id, nickname FROM agents WHERE tenant_id = ? AND id = ? AND status = 'active'");
        $st->execute([$ctx['tenant_id'], $toAgent]);
        $target = $st->fetch();
        if (!$target) json_error('转接目标客服不存在', 400);
    }
    $pdo->prepare('UPDATE v_sessions SET agent_id = ? WHERE id = ?')->execute([$toAgent, $sid]);
    $txt = $toAgent === 0 ? '会话已退回待接入池' : ('已转接给 ' . ($target['nickname'] ?: '客服'));
    $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, created_at) VALUES (?,?,'system','系统',?,'text',?)")
        ->execute([$ctx['tenant_id'], $sid, $txt, now_dt()]);
    json_out(['ok' => true, 'to_agent_id' => $toAgent]);
}

/* ---------- 联系方式 ---------- */
function handle_session_contacts(string $method, array $m): void
{
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $type = $m[2] ?? null;
    $pdo = db();
    $types = ['note', 'mobile', 'qq', 'wechat', 'email', 'other'];

    if ($method === 'GET') {
        $st = $pdo->prepare('SELECT type, value, updated_at FROM contacts WHERE session_id = ? ORDER BY id');
        $st->execute([$sid]);
        $map = [];
        foreach ($st->fetchAll() as $r) $map[$r['type']] = $r;
        json_out(array_map(fn($t) => $map[$t] ?? ['type' => $t, 'value' => '', 'updated_at' => null], $types));
    }

    if ($method === 'PUT' && $type && in_array($type, $types, true)) {
        $value = mb_substr((string)input('value', ''), 0, 200);
        $pdo->prepare('INSERT INTO contacts (tenant_id, session_id, type, value, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)')
            ->execute([$ctx['tenant_id'], $sid, $type, $value, now_dt()]);
        json_out(['ok' => true, 'type' => $type, 'value' => $value]);
    }

    json_error('方法不允许', 405);
}

/* ---------- 访客资料 ---------- */
function handle_session_profile(string $method, array $m): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    $s = session_of($ctx, $sid);
    $pdo = db();
    $visitCount = fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions WHERE tenant_id = ? AND ip = ? AND ip != \'\'',
        [$ctx['tenant_id'], $s['ip']]);
    $msgCount = fetch_count($pdo, 'SELECT COUNT(*) FROM messages WHERE session_id = ?', [$sid]);
    $ua = parse_ua($s['ua'] ?? null); // 访客进入时记录的真实 UA
    $firstTime = $pdo->prepare('SELECT created_at FROM messages WHERE session_id = ? ORDER BY id ASC LIMIT 1');
    $firstTime->execute([$sid]);
    $ft = $firstTime->fetchColumn();
    json_out([
        'session' => ['id' => (int)$s['id'], 'name' => $s['visitor_name'], 'status' => $s['status'],
            'last_msg' => $s['last_msg'], 'last_time' => dt_to_iso($s['last_time']), 'unread' => (int)$s['unread'],
            'agent_id' => (int)$s['agent_id'], 'source' => $s['source'], 'created_at' => dt_to_iso($s['created_at'])],
        'ip' => $s['ip'], 'from_url' => $s['from_url'],
        'os' => $ua['os'], 'browser' => $ua['browser'], 'device' => $ua['device'],
        'geo' => lookup_geo($s['ip']),
        'ua' => $ua,
        'visit_count' => $visitCount, 'message_count' => $msgCount,
        'first_time' => dt_to_iso($ft ?: $s['created_at']),
        'stay_seconds' => $ft ? max(0, time() - strtotime($ft)) : 0,
    ]);
}

/* ---------- 上传（工作台） ---------- */
function handle_upload(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_workspace();
    $data = (string)input('data', '');
    $kind = (string)input('kind', '');
    $filename = (string)input('filename', '');
    if ($data === '' || $kind === '') json_error('缺少文件数据');
    try { json_out(save_upload($data, $kind, $filename)); }
    catch (\Throwable $e) { json_error($e->getMessage()); }
}

/* ==================================================================
 * 租户级 CRUD：快捷回复 / 欢迎语 / 自动回复 / IP黑名单 / 询前表单
 * ================================================================== */

function crud_config(string $name): array
{
    static $map = [
        'quick-msgs'       => ['table' => 'quick_msgs', 'fields' => ['title', 'content', 'group_name', 'sort', 'attachments']],
        'welcome-messages' => ['table' => 'welcome_msgs', 'fields' => ['content', 'delay', 'enabled', 'sort', 'attachments', 'trig']],
        'auto-reply'       => ['table' => 'auto_replies', 'fields' => ['keyword', 'reply', 'enabled']],
        'ip-blacklist'     => ['table' => 'ip_blacklist', 'fields' => ['ip', 'reason'], 'tenant_only' => true],
        'pre-inquiry'      => ['table' => 'pre_inquiries', 'fields' => ['label', 'type', 'required', 'placeholder', 'sort'], 'tenant_only' => true],
    ];
    return $map[$name] ?? [];
}

function crud_transform(string $name, array $row): array
{
    if ($name === 'quick-msgs' || $name === 'welcome-messages') {
        $row['attachments'] = parse_attachments($row['attachments'] ?? null);
    }
    if ($name === 'welcome-messages' || $name === 'auto-reply') {
        $row['enabled'] = (bool)$row['enabled'];
    }
    if ($name === 'pre-inquiry') {
        $row['required'] = (bool)$row['required'];
    }
    return $row;
}

function norm_col(string $f): string
{
    return str_contains($f, '`') ? $f : "`$f`";
}

function handle_crud(string $method, string $name): void
{
    $cfg = crud_config($name);
    if (!$cfg) { json_error('接口不存在', 400); return; }
    $ctx = require_workspace();
    if (!empty($cfg['tenant_only']) && $ctx['role'] !== 'tenant') json_error('仅账户主人可管理此项', 403);
    $table = $cfg['table'];
    $fields = $cfg['fields'];
    $tid = $ctx['tenant_id'];
    $pdo = db();
    $selCols = implode(',', array_map('norm_col', $fields));

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT id, $selCols FROM $table WHERE tenant_id = ? ORDER BY id DESC");
        $st->execute([$tid]);
        json_out(array_map(fn($r) => crud_transform($name, $r), $st->fetchAll()));
    }

    if ($method === 'POST') {
        $data = body();
        $cols = array_values(array_filter($fields, fn($f) => array_key_exists($f, $data)));
        if (!$cols) json_error('无有效字段');
        $insCols = implode(',', array_map('norm_col', $cols));
        $place = implode(',', array_fill(0, count($cols), '?'));
        $vals = array_map(function ($c) use ($data) {
            $v = $data[$c];
            return (is_array($v) || is_object($v)) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        }, $cols);
        $pdo->prepare("INSERT INTO $table (tenant_id, $insCols) VALUES (?," . str_repeat('?,', count($cols) - 1) . '?)')
            ->execute(array_merge([$tid], $vals));
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare("SELECT id, $selCols FROM $table WHERE id = ?");
        $st->execute([$id]);
        json_out(crud_transform($name, $st->fetch()), 201);
    }

    json_error('方法不允许', 405);
}

function handle_crud_id(string $method, string $name, int $id): void
{
    $cfg = crud_config($name);
    if (!$cfg) { json_error('接口不存在', 400); return; }
    $ctx = require_workspace();
    if (!empty($cfg['tenant_only']) && $ctx['role'] !== 'tenant') json_error('仅账户主人可管理此项', 403);
    $table = $cfg['table'];
    $fields = $cfg['fields'];
    $tid = $ctx['tenant_id'];
    $pdo = db();
    $selCols = implode(',', array_map('norm_col', $fields));

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT id, $selCols FROM $table WHERE id = ? AND tenant_id = ?");
        $st->execute([$id, $tid]);
        $row = $st->fetch();
        if (!$row) json_error('记录不存在', 400);
        json_out(crud_transform($name, $row));
    }

    if ($method === 'PUT') {
        $data = body();
        $cols = array_values(array_filter($fields, fn($f) => array_key_exists($f, $data)));
        if (!$cols) json_error('无有效字段');
        $setstr = implode(', ', array_map(fn($c) => norm_col($c) . ' = ?', $cols));
        $vals = array_map(function ($c) use ($data) {
            $v = $data[$c];
            return (is_array($v) || is_object($v)) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        }, $cols);
        $vals[] = $id; $vals[] = $tid;
        $pdo->prepare("UPDATE $table SET $setstr WHERE id = ? AND tenant_id = ?")->execute($vals);
        $st = $pdo->prepare("SELECT id, $selCols FROM $table WHERE id = ? AND tenant_id = ?");
        $st->execute([$id, $tid]);
        json_out(crud_transform($name, $st->fetch()));
    }

    if ($method === 'DELETE') {
        $st = $pdo->prepare("DELETE FROM $table WHERE id = ? AND tenant_id = ?");
        $st->execute([$id, $tid]);
        json_out(['ok' => true]);
    }

    json_error('方法不允许', 405);
}

/* ---------- 群发（限 tenant；agent 也可给自己可见会话群发？限 tenant 更安全） ---------- */
function handle_broadcast_send(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_role('tenant');
    $text = mb_substr(trim((string)input('text', '')), 0, 2000);
    $title = mb_substr((string)input('title', ''), 0, 128);
    if ($text === '') json_error('群发内容不能为空');

    $pdo = db();
    $tid = $ctx['id'];
    $now = now_dt();
    // 发给近 24 小时活跃的未关闭会话
    $st = $pdo->prepare("SELECT id FROM v_sessions WHERE tenant_id = ? AND status != 'closed' AND last_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $st->execute([$tid]);
    $ins = $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, created_at) VALUES (?,?,'agent',?,?,?,?)");
    $count = 0;
    foreach ($st->fetchAll() as $s) {
        $ins->execute([$tid, (int)$s['id'], $ctx['name'] ?: '客服', $text, 'text', $now]);
        $pdo->prepare('UPDATE v_sessions SET last_msg = ?, last_time = ?, unread = unread + 1 WHERE id = ?')
            ->execute([mb_substr($text, 0, 60), $now, (int)$s['id']]);
        $count++;
    }
    $pdo->prepare('INSERT INTO broadcasts (tenant_id, title, content, count, created_at) VALUES (?,?,?,?,?)')
        ->execute([$tid, $title, $text, $count, $now]);
    json_out(['ok' => true, 'count' => $count]);
}

/* ---------- 统计面板 ---------- */
function handle_stats_dashboard(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $pdo = db();
    $tid = $ctx['tenant_id'];
    $vis = session_visible_sql($ctx); // agent 只统计自己可见会话
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-6 days'));

    $today_sessions = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND date(s.last_time) = ?$vis", [$tid, $today]);
    $today_active   = fetch_count($pdo, "SELECT COUNT(DISTINCT m.session_id) FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) = ? AND m.from_type = 'visitor'$vis", [$tid, $today]);
    $today_replied  = fetch_count($pdo, "SELECT COUNT(*) FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) = ? AND m.from_type = 'agent'$vis", [$tid, $today]);
    $online_visitors= fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.status != 'closed' AND s.last_online_at > DATE_SUB(NOW(), INTERVAL 25 SECOND)$vis", [$tid]);
    $total_sessions = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ?$vis", [$tid]);
    $waiting        = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.status != 'closed' AND s.agent_id = 0$vis", [$tid]);

    // 近 7 天趋势
    $st = $pdo->prepare("SELECT date(last_time) AS day, COUNT(*) c FROM v_sessions WHERE tenant_id = ? AND date(last_time) >= ? GROUP BY day");
    $st->execute([$tid, $weekAgo]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[$r['day']] = (int)$r['c'];
    $trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trend[] = ['date' => substr($d, 5), 'count' => $map[$d] ?? 0];
    }

    // 来源分布（仅 tenant 视角有额度意义；agent 也按可见范围统计）
    $srcApp = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.quota_key = 'app' AND date(s.last_time) >= ?$vis", [$tid, $weekAgo]);
    $srcWeb = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.quota_key = 'web' AND date(s.last_time) >= ?$vis", [$tid, $weekAgo]);

    // 每日接待
    $st = $pdo->prepare("SELECT date(last_time) day, COUNT(*) c FROM v_sessions WHERE tenant_id = ? AND date(last_time) >= ? GROUP BY day");
    $st->execute([$tid, $weekAgo]);
    $recMap = [];
    foreach ($st->fetchAll() as $r) $recMap[$r['day']] = (int)$r['c'];
    $st = $pdo->prepare("SELECT date(m.created_at) day, COUNT(DISTINCT m.session_id) c FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) >= ? AND m.from_type = 'visitor' GROUP BY day");
    $st->execute([$tid, $weekAgo]);
    $actMap = [];
    foreach ($st->fetchAll() as $r) $actMap[$r['day']] = (int)$r['c'];
    $st = $pdo->prepare("SELECT date(m.created_at) day, COUNT(*) c FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) >= ? AND m.from_type = 'agent' GROUP BY day");
    $st->execute([$tid, $weekAgo]);
    $repMap = [];
    foreach ($st->fetchAll() as $r) $repMap[$r['day']] = (int)$r['c'];
    $daily = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $received = $recMap[$d] ?? 0; $active = $actMap[$d] ?? 0; $replied = $repMap[$d] ?? 0;
        $daily[] = ['date' => $d, 'received' => $received, 'active' => $active, 'replied' => $replied,
            'reply_rate' => $active > 0 ? round($replied / $active * 100, 1) : 0];
    }

    $out = [
        'today_sessions' => $today_sessions, 'today_active' => $today_active, 'today_replied' => $today_replied,
        'online_visitors' => $online_visitors, 'total_sessions' => $total_sessions, 'waiting' => $waiting,
        'trend7' => $trend, 'sources' => ['app' => $srcApp, 'web' => $srcWeb], 'daily' => $daily,
    ];

    // tenant 附加：额度信息
    if ($ctx['role'] === 'tenant') {
        $t = $ctx['row'];
        $out['quota'] = [
            'web_limit' => (int)$t['quota_web'], 'app_limit' => (int)$t['quota_app'],
            'web_used' => quota_used($tid, 'web'), 'app_used' => quota_used($tid, 'app'),
            'expires_at' => $t['expires_at'],
        ];
    }
    json_out($out);
}

/* ---------- 导出 CSV ---------- */
function handle_export_sessions(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $pdo = db();
    $vis = session_visible_sql($ctx);
    $rows = $pdo->prepare("SELECT s.id, s.visitor_name, s.ip, s.from_url, s.last_msg, s.last_time, s.status, s.source,
        (SELECT COUNT(*) FROM messages WHERE session_id = s.id) msg_count,
        (SELECT COUNT(*) FROM messages WHERE session_id = s.id AND from_type = 'visitor') visitor_msg,
        (SELECT value FROM contacts WHERE session_id = s.id AND type = 'mobile' LIMIT 1) mobile,
        (SELECT value FROM contacts WHERE session_id = s.id AND type = 'wechat' LIMIT 1) wechat
        FROM v_sessions s WHERE s.tenant_id = ?$vis ORDER BY s.id DESC");
    $rows->execute([$ctx['tenant_id']]);
    $out = array_map(fn($r) => [$r['id'], $r['visitor_name'], $r['mobile'], $r['wechat'], $r['ip'], $r['from_url'],
        $r['last_msg'], $r['last_time'], $r['status'], $r['source'], $r['msg_count'], $r['visitor_msg']], $rows->fetchAll());
    send_csv('sessions_' . date('Ymd_His') . '.csv',
        ['会话ID', '访客名称', '手机', '微信', 'IP', '来源页', '最后消息', '最后时间', '状态', '进入渠道', '消息总数', '访客消息数'], $out);
}

/* ---------- 客服端轮询 ---------- */
function handle_agent_poll(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $pdo = db();
    $cfg = config();
    $onlineSql = "CASE WHEN s.status = 'closed' THEN 'closed'
        WHEN s.last_online_at IS NOT NULL AND s.last_online_at > DATE_SUB(NOW(), INTERVAL " . (int)$cfg['session_online_secs'] . " SECOND) THEN 'online'
        ELSE 'offline' END";
    $st = $pdo->prepare("SELECT s.id, s.agent_id, a.nickname AS agent_name, s.visitor_name AS name,
        (SELECT value FROM contacts WHERE session_id = s.id AND type = 'note' LIMIT 1) AS note,
        s.ip, s.from_url AS `from`, s.last_msg AS lastMsg, s.last_time AS lastTime, s.unread, s.source,
        $onlineSql AS status,
        (SELECT COUNT(*) FROM messages WHERE session_id = s.id) AS message_count
        FROM v_sessions s LEFT JOIN agents a ON a.id = s.agent_id AND a.tenant_id = s.tenant_id
        WHERE s.tenant_id = ? AND s.status != 'closed'" . session_visible_sql($ctx) . "
        ORDER BY s.last_time DESC LIMIT 500");
    $st->execute([$ctx['tenant_id']]);
    $sessions = [];
    foreach ($st->fetchAll() as $r) {
        $r['lastTime'] = dt_to_iso($r['lastTime']);
        $r['unread'] = (int)$r['unread'];
        $r['agent_id'] = (int)$r['agent_id'];
        $r['message_count'] = (int)$r['message_count'];
        $sessions[] = $r;
    }

    $msgs = [];
    $readUpto = 0;
    $session = (int)input('session', 0);
    $sinceMsg = (int)input('sinceMsg', 0);
    if ($session) {
        $ru = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM messages WHERE session_id = ? AND from_type = 'agent' AND read_at IS NOT NULL");
        $ru->execute([$session]);
        $readUpto = (int)$ru->fetchColumn();
    }
    if ($session && $sinceMsg > 0) {
        session_of($ctx, $session); // 越权检查
        $st = $pdo->prepare('SELECT id, session_id, from_type AS `from`, sender_id, sender_name, text, msg_type, attachments, recalled_at, created_at FROM messages WHERE session_id = ? AND id > ? ORDER BY id ASC');
        $st->execute([$session, $sinceMsg]);
        $msgs = array_map('to_msg', $st->fetchAll());
    }
    json_out(['sessions' => $sessions, 'messages' => $msgs, 'readUpto' => $readUpto, 'now' => time()]);
}
