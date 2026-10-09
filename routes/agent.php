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
/** 会话分区过滤（v2.25 三级）：online=在线（未结束未归档） archived=已归档 closed=已结束
 *  v2.60.11 加第四级 trash=回收站（软删除，30 天内可恢复）；其他三个分区都把回收站里的排除掉 */
function session_scope_sql(string $scope): string
{
    if ($scope === 'trash') return " AND s.deleted_at IS NOT NULL";
    $base = " AND s.deleted_at IS NULL";
    if ($scope === 'archived') return $base . " AND s.status = 'archived'";
    if ($scope === 'closed') return $base . " AND s.status = 'closed'";
    return $base . " AND s.status NOT IN ('closed', 'archived')";
}

/** v2.66：只把「访客真的说过话」的会话放进列表。
 *  访客点开聊天页就会建一条 v_sessions（last_msg 是「等待接入...」占位），
 *  人还没开口时列表里不该出现 —— 等他一发消息（messages.from_type='visitor'）自然就进来了。 */
function session_spoken_sql(): string
{
    /* v2.86 访客刚扫码进来、人还在线（有心跳）就出现在列表里 —— 不用等对方先发消息；
       v2.88.3 一句话都没说的也别「待会就没了」：24 小时内一直留在列表里
       （客户扫码进来后可能先放着不说话，客服正看着聊天窗呢突然空了会被吓一跳）。
       超过 24 小时还是没说话、人也早就走了的，才不再占列表位置。 */
    $secs = (int)(config()['n'] ?? 180);   // 'n' = 访客心跳多少秒内算在线（见 config.php）
    return " AND (EXISTS (SELECT 1 FROM messages m WHERE m.session_id = s.id AND m.from_type = 'visitor')
        OR (s.last_online_at IS NOT NULL AND s.last_online_at > DATE_SUB(NOW(), INTERVAL " . $secs . " SECOND))
        OR s.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR))";
}

/** 已结束会话自动清理（poll 时懒执行；closed_keep_days=0 表示永久保留） */
function cleanup_closed_sessions(int $tenantId): void
{
    /* v2.60.11 回收站超时清理：删掉的会话保留 30 天，过期自动彻底删除 */
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT id FROM v_sessions WHERE tenant_id = ? AND deleted_at IS NOT NULL AND deleted_at < ? LIMIT 200');
        $st->execute([$tenantId, now_dt(time() - 30 * 86400)]);
        $old = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($old) {
            $in = implode(',', array_map('intval', $old));
            $pdo->exec("DELETE FROM messages WHERE session_id IN ($in)");
            $pdo->exec("DELETE FROM contacts WHERE session_id IN ($in)");
            $pdo->exec("DELETE FROM welcome_sent WHERE session_id IN ($in)");
            $pdo->exec("DELETE FROM v_sessions WHERE id IN ($in)");
        }
    } catch (\Throwable $e) { /* 清理失败不影响主流程 */ }
    $days = fetch_count(db(), 'SELECT closed_keep_days FROM tenants WHERE id = ?', [$tenantId]);
    if ($days <= 0) return;
    $pdo = db();
    // 注意：回收站里的（deleted_at 不为空）不在这里清，交给上面的 30 天逻辑，否则刚删的会话会被「已结束自动清理」顺手干掉
    $st = $pdo->prepare('SELECT id FROM v_sessions WHERE tenant_id = ? AND deleted_at IS NULL AND status = \'closed\' AND closed_at IS NOT NULL AND closed_at < ? LIMIT 100');
    $st->execute([$tenantId, now_dt(time() - $days * 86400)]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $idList = implode(',', array_map('intval', $ids));
    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM messages WHERE tenant_id = $tenantId AND session_id IN ($idList)");
        $pdo->exec("DELETE FROM contacts WHERE tenant_id = $tenantId AND session_id IN ($idList)");
        $pdo->exec("DELETE FROM welcome_sent WHERE tenant_id = $tenantId AND session_id IN ($idList)");
        $pdo->exec("DELETE FROM v_sessions WHERE tenant_id = $tenantId AND id IN ($idList)");
        $pdo->commit();
    } catch (\Throwable $e) { try { $pdo->rollBack(); } catch (\Throwable $e2) {} }
}

function handle_sessions(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $pdo = db();
    $cfg = config();
    $scope = in_array((string)input('scope', 'online'), ['online', 'archived', 'closed', 'trash'], true) ? (string)input('scope', 'online') : 'online';
    $onlineSql = "CASE WHEN s.status IN ('closed','archived') THEN s.status
        WHEN s.last_online_at IS NOT NULL AND s.last_online_at > DATE_SUB(NOW(), INTERVAL " . (int)$cfg['session_online_secs'] . " SECOND) THEN 'online'
        ELSE 'offline' END";
    $sql = "SELECT s.id, s.agent_id, a.nickname AS agent_name, s.visitor_name AS name, s.visitor_avatar,
        (SELECT value FROM contacts WHERE session_id = s.id AND type = 'note' LIMIT 1) AS note,
        s.ip, s.from_url AS `from`, s.last_msg AS lastMsg, s.last_time AS lastTime, s.unread, s.source, s.geo, s.ua,
        s.pinned, s.pinned_at,
        s.archived_at, s.closed_at, s.last_online_at AS lastOnline,
        $onlineSql AS status,
        (SELECT COUNT(*) FROM messages WHERE session_id = s.id) AS message_count
        FROM v_sessions s LEFT JOIN agents a ON a.id = s.agent_id AND a.tenant_id = s.tenant_id
        WHERE s.tenant_id = ?" . session_scope_sql($scope) . session_visible_sql($ctx) . ($scope === 'trash' ? '' : session_spoken_sql()) . "
        ORDER BY s.pinned DESC, s.pinned_at DESC, s.last_time DESC LIMIT 500";
    $rows = $pdo->prepare($sql);
    $rows->execute([$ctx['tenant_id']]);
    $out = $rows->fetchAll();
    foreach ($out as &$r) {
        $r['lastTime'] = dt_to_iso($r['lastTime']);
        $r['lastOnline'] = dt_to_iso($r['lastOnline'] ?? null);   // v2.60.1 访客最后上线（心跳时间）
        $r['unread'] = (int)$r['unread'];
        $r['agent_id'] = (int)$r['agent_id'];
        $r['message_count'] = (int)$r['message_count'];
        $r['pinned'] = (int)($r['pinned'] ?? 0);
        // 访客头像：优先已分配，其次按会话 id 稳定派生（老会话也有头像）
        $r['visitor_avatar'] = visitor_avatar_resolve((int)$r['id'], (string)($r['visitor_avatar'] ?? ''));
        // 访客标签（v2.29）：地区（省市+运营商，如 广东广州 电信）；设备/渠道仅悬停全文用
        $uaInfo = parse_ua($r['ua'] ?? null);
        $r['devTag'] = $uaInfo['dev_tag'];
        $r['channel'] = $uaInfo['channel'];
        $r['city'] = geo_city($r['geo'] ?? null);
        $r['region'] = geo_region($r['geo'] ?? null);
        unset($r['ua']);
    }
    unset($r);
    // 各分区计数（供 tab 徽标：在线/归档的未读合计提示）
    $vis = session_visible_sql($ctx) . session_spoken_sql();
    $cnt = $pdo->prepare("SELECT
        SUM(status NOT IN ('closed','archived')) AS online_cnt,
        SUM(status = 'archived') AS archived_cnt,
        SUM(status = 'closed') AS closed_cnt,
        COALESCE(SUM(CASE WHEN status NOT IN ('closed','archived') THEN unread ELSE 0 END), 0) AS online_unread,
        COALESCE(SUM(CASE WHEN status = 'archived' THEN unread ELSE 0 END), 0) AS archived_unread
        FROM v_sessions s WHERE s.tenant_id = ? AND s.deleted_at IS NULL" . $vis);
    $cnt->execute([$ctx['tenant_id']]);
    $c = $cnt->fetch() ?: [];
    // 回收站数量（软删除、未过期）：不带「访客说过话」过滤，删了就该看得见
    $trashCnt = fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.deleted_at IS NOT NULL'
        . session_visible_sql($ctx), [$ctx['tenant_id']]);
    json_out(['sessions' => $out, 'counts' => [
        'online' => (int)($c['online_cnt'] ?? 0),
        'archived' => (int)($c['archived_cnt'] ?? 0),
        'closed' => (int)($c['closed_cnt'] ?? 0),
        'trash' => $trashCnt,
        'online_unread' => (int)($c['online_unread'] ?? 0),
        'archived_unread' => (int)($c['archived_unread'] ?? 0),
    ]]);
}

/** v2.89.12「刷新访客端」：客服点一下刷新，客户那边的页面会自动刷新一次。
    做法：往会话里插一条 msg_type='sys_reload' 的系统消息 —— 两端都不显示这条消息，
    访客端轮询收到就执行 location.reload()（用于客户页面卡住、样式错乱、图片不显示等）。 */
function handle_session_reload(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $pdo = db();
    $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, created_at)
        VALUES (?,?,'system','系统','刷新访客页面','sys_reload',?)")
        ->execute([(int)$ctx['tenant_id'], $sid, now_dt()]);
    json_out(['ok' => true]);
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
        $sql = 'SELECT id, session_id, from_type AS `from`, sender_id, sender_name, text, msg_type, attachments, reply_to, recalled_at, edited_at, created_at FROM messages WHERE session_id = ?' . ($since > 0 ? ' AND id > ?' : '') . ' ORDER BY id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($since > 0 ? [$sid, $since] : [$sid]);
        json_out(['messages' => array_map('to_msg', $st->fetchAll())]);
    }

    if ($method === 'POST') {
        $text = mb_substr(trim((string)input('text', '')), 0, 2000);
        $attachArr = input('attachments', []);
        $attachArr = is_array($attachArr) ? $attachArr : [];
        // v2.32：允许指定消息类型（如「请求访客上传图片」卡片）
        $reqType = strtolower(trim((string)input('msg_type', '')));
        $allowTypes = ['text', 'image', 'audio', 'video', 'file', 'location', 'mixed', 'ask_image'];
        $reqType = in_array($reqType, $allowTypes, true) ? $reqType : '';
        $isAsk = ($reqType === 'ask_image');
        // 「请求访客上传图片」的默认话术：租户可在「访客策略 → 聊天话术」里自定义（留空则用内置默认）
        if ($isAsk && $text === '') {
            $askTxt = trim((string)ts_get((int)$ctx['tenant_id'], 'ask_image_text', ''));
            $text = $askTxt !== '' ? $askTxt : '方便的话，请上传一下相关图片，帮您更快定位问题～';
        }
        if ($text === '' && !$attachArr) json_error('消息不能为空');

        // 记录前先取会话（拿 agent_id 判断接手）
        $sess = session_of($ctx, $sid);
        $now = now_dt();
        $msgType = $isAsk ? 'ask_image' : ($reqType !== '' && $reqType !== 'text' ? $reqType : resolve_msg_type($text, $attachArr));
        $attachJson = $attachArr ? json_encode($attachArr, JSON_UNESCAPED_UNICODE) : '';
        $senderName = $ctx['name'] ?: '客服';
        // v2.43 引用回复：reply_to 指向本会话内被引用的消息 id
        $replyTo = (int)input('reply_to', 0);
        if ($replyTo > 0) {
            $chk = $pdo->prepare('SELECT id FROM messages WHERE id = ? AND session_id = ?');
            $chk->execute([$replyTo, $sid]);
            if (!$chk->fetchColumn()) $replyTo = 0;
        }

        $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_id, sender_name, text, msg_type, attachments, reply_to, created_at) VALUES (?,?,'agent',?,?,?,?,?,?,?)")
            ->execute([$ctx['tenant_id'], $sid, $ctx['id'], $senderName, $text, $msgType, $attachJson, $replyTo, $now]);
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
            'text' => $text, 'time' => dt_to_iso($now), 'session_id' => $sid, 'msg_type' => $msgType,
            'attachments' => $attachArr, 'reply_to' => $replyTo]]);
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

/* ---------- 关闭会话（移入已结束，按保留天数自动清理） ---------- */
function handle_session_close(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $pdo = db();
    $pdo->prepare("UPDATE v_sessions SET status = 'closed', closed_at = ?, archived_at = NULL WHERE id = ?")->execute([now_dt(), $sid]);
    $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, created_at) VALUES (?,?,'system','系统','会话已结束','text',?)")
        ->execute([$ctx['tenant_id'], $sid, now_dt()]);
    json_out(['ok' => true]);
}

/* ---------- 归档 / 取消归档 ---------- */
function handle_session_archive(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    $s = session_of($ctx, $sid);
    $on = ((string)input('on', '1')) === '1';
    $pdo = db();
    if ($on) {
        if ($s['status'] === 'closed') json_error('已结束的会话不能归档，请先取消结束', 400);
        $pdo->prepare("UPDATE v_sessions SET status = 'archived', archived_at = ? WHERE id = ?")->execute([now_dt(), $sid]);
    } else {
        if ($s['status'] !== 'archived') json_error('该会话不在归档中', 400);
        $pdo->prepare("UPDATE v_sessions SET status = 'online', archived_at = NULL WHERE id = ?")->execute([$sid]);
    }
    json_out(['ok' => true, 'archived' => $on]);
}

/* ---------- 删除会话（右键菜单，彻底删除腾出会话额度；仅租户可删任意会话） ---------- */
function handle_session_delete(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    if ($ctx['role'] !== 'tenant') json_error('仅租户可删除会话', 403);
    $tid = (int)$ctx['tenant_id'];
    $pdo = db();
    /* v2.60.11 改成软删除（进回收站）：30 天内可以在「已删除」页签里自己恢复，
       也能在刚删完的提示里点「撤销」。会话额度按未删除的算，所以删了就等于腾出额度。 */
    $pdo->prepare("UPDATE v_sessions SET deleted_at = ? WHERE tenant_id = ? AND id = ?")->execute([now_dt(), $tid, $sid]);
    json_out(['ok' => true, 'soft' => true, 'sid' => $sid]);
}

/* v2.60.14 会话合并：同一个人用微信来一次、浏览器又来一次 → 列表里变成两条。
   把「from」这条的聊天记录、联系方式整体并到「target」（通常是当前正在接待的那条），
   只留一条，客户和客服看到的都是完整上下文。 */
function handle_session_merge(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $target = (int)$m[1];
    $ctx = require_workspace();
    if ($ctx['role'] !== 'tenant') json_error('仅租户可合并会话', 403);
    $from = (int)input('from', 0);
    if ($from <= 0 || $from === $target) json_error('参数不对');
    $tid = (int)$ctx['tenant_id'];
    $pdo = db();
    $ta = session_of($ctx, $target);
    $fa = session_of($ctx, $from);

    $moved = 0;
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE messages SET session_id = ? WHERE session_id = ? AND tenant_id = ?');
        $st->execute([$target, $from, $tid]);
        $moved = $st->rowCount();
        // 联系方式：目标没有的才补，别把已有的覆盖掉
        $q = $pdo->prepare('SELECT type, value FROM contacts WHERE session_id = ? AND tenant_id = ?');
        $q->execute([$from, $tid]);
        $ins = $pdo->prepare('INSERT INTO contacts (tenant_id, session_id, type, value, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($q->fetchAll() as $c) {
            $has = $pdo->prepare('SELECT 1 FROM contacts WHERE session_id = ? AND type = ? LIMIT 1');
            $has->execute([$target, $c['type']]);
            if (!$has->fetch()) $ins->execute([$tid, $target, $c['type'], $c['value'], now_dt()]);
        }
        $pdo->prepare('DELETE FROM contacts WHERE session_id = ? AND tenant_id = ?')->execute([$from, $tid]);
        $pdo->prepare('DELETE FROM welcome_sent WHERE session_id = ? AND tenant_id = ?')->execute([$from, $tid]);
        $pdo->prepare('DELETE FROM v_sessions WHERE id = ? AND tenant_id = ?')->execute([$from, $tid]);
        // 目标会话的最后消息/时间跟着更新成合并后的最新一条
        $last = $pdo->prepare('SELECT text, msg_type, created_at FROM messages WHERE session_id = ? ORDER BY id DESC LIMIT 1');
        $last->execute([$target]);
        $lm = $last->fetch();
        if ($lm) {
            $summary = msg_summary((string)$lm['text'], []);
            $pdo->prepare('UPDATE v_sessions SET last_msg = ?, last_time = ? WHERE id = ?')->execute([$summary, $lm['created_at'], $target]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        json_error('合并失败：' . $e->getMessage(), 500);
    }
    json_out(['ok' => true, 'moved' => $moved, 'target' => $target, 'from_name' => $fa['visitor_name'], 'into_name' => $ta['visitor_name']]);
}

/* v2.60.13 工作台用恢复码找回会话：客户报一串恢复码，客服在这里输入就能直接跳到那段对话
   （客户换了手机/浏览器，或者我们这边把会话删进回收站了，都能靠它接回来） */
function handle_session_by_resume(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $rc = strtolower(trim((string)input('code', '')));
    if ($rc === '' || !preg_match('/^[a-z0-9]{4,16}$/', $rc)) json_error('恢复码格式不对（8 位字母数字）');
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM v_sessions WHERE tenant_id = ? AND resume_code = ? AND status <> 'closed'"
        . session_visible_sql($ctx) . ' ORDER BY last_time DESC LIMIT 1');
    $st->execute([$ctx['tenant_id'], $rc]);
    $row = $st->fetch();
    if (!$row) json_error('没找到这段对话：恢复码不对，或这段对话已经结束', 404);
    $fromTrash = !empty($row['deleted_at']);
    if ($fromTrash) {
        $pdo->prepare('UPDATE v_sessions SET deleted_at = NULL WHERE id = ?')->execute([(int)$row['id']]);
    }
    json_out(['ok' => true, 'session_id' => (int)$row['id'], 'from_trash' => $fromTrash, 'name' => $row['visitor_name']]);
}

/* v2.60.11 回收站：恢复一个被删除的会话 */
function handle_session_restore(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    if ($ctx['role'] !== 'tenant') json_error('仅租户可恢复会话', 403);
    $pdo = db();
    $pdo->prepare("UPDATE v_sessions SET deleted_at = NULL WHERE tenant_id = ? AND id = ?")->execute([(int)$ctx['tenant_id'], $sid]);
    json_out(['ok' => true]);
}

/* v2.60.11 回收站：彻底删除（只在已删除的会话上允许，不可恢复） */
function handle_session_purge(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    $row = session_of($ctx, $sid);
    if ($ctx['role'] !== 'tenant') json_error('仅租户可彻底删除会话', 403);
    if (empty($row['deleted_at'])) json_error('请先删除会话再彻底删除', 400);
    $tid = (int)$ctx['tenant_id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM messages WHERE tenant_id = ? AND session_id = ?")->execute([$tid, $sid]);
        $pdo->prepare("DELETE FROM contacts WHERE tenant_id = ? AND session_id = ?")->execute([$tid, $sid]);
        $pdo->prepare("DELETE FROM welcome_sent WHERE tenant_id = ? AND session_id = ?")->execute([$tid, $sid]);
        $pdo->prepare("DELETE FROM v_sessions WHERE tenant_id = ? AND id = ?")->execute([$tid, $sid]);
        $pdo->commit();
    } catch (\Throwable $e) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        json_error('删除失败：' . $e->getMessage(), 500);
    }
    json_out(['ok' => true]);
}

/* ---------- 会话批量操作（v2.67）：多选后批量 归档 / 取消归档 / 结束 / 删除 / 标已读 ----------
   入参：{ ids: [1,2,3], action: 'archive'|'unarchive'|'close'|'delete'|'read' }
   越权的 id 与「状态不允许」的 id 直接跳过并回报，不整批失败。 */
function handle_sessions_bulk(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $data = body();
    $action = (string)($data['action'] ?? '');
    $raw = $data['ids'] ?? [];
    if (!is_array($raw)) json_error('参数错误：ids 需要是数组');
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($v) => $v > 0)));
    if (!$ids) json_error('没有选中任何会话');
    if (count($ids) > 500) json_error('一次最多操作 500 个会话');
    if (!in_array($action, ['archive', 'unarchive', 'close', 'delete', 'read'], true)) json_error('不支持的操作');
    if ($action === 'delete' && $ctx['role'] !== 'tenant') json_error('仅租户可删除会话', 403);

    $tid = (int)$ctx['tenant_id'];
    $pdo = db();
    $ph = implode(',', array_fill(0, count($ids), '?'));
    // 只取「本租户 + 自己有权看到」的会话，越权 id 自动落空
    $st = $pdo->prepare("SELECT s.id, s.status FROM v_sessions s WHERE s.tenant_id = ? AND s.id IN ($ph)" . session_visible_sql($ctx));
    $st->execute(array_merge([$tid], $ids));
    $rows = $st->fetchAll();

    $group = [];
    foreach ($rows as $r) $group[(string)$r['status']][] = (int)$r['id'];
    $all = array_column($rows, 'id');
    $now = now_dt();
    $skipped = count($ids) - count($rows);   // 不存在 / 越权

    // 各操作能作用到的 id 集合
    if ($action === 'read') {
        $target = $all;
    } elseif ($action === 'archive') {
        $target = $group['online'] ?? [];                 // 已结束的不能归档
    } elseif ($action === 'unarchive') {
        $target = $group['archived'] ?? [];
    } elseif ($action === 'close') {
        $target = array_merge($group['online'] ?? [], $group['archived'] ?? []);
    } else { // delete：任何状态都能删
        $target = $all;
    }
    if ($action !== 'delete') $skipped += count($rows) - count($target);

    if ($target) {
        $in = implode(',', array_map('intval', $target));
        if ($action === 'read') {
            $pdo->exec("UPDATE v_sessions SET unread = 0 WHERE tenant_id = $tid AND id IN ($in)");
        } elseif ($action === 'archive') {
            $pdo->exec("UPDATE v_sessions SET status = 'archived', archived_at = " . $pdo->quote($now) . " WHERE tenant_id = $tid AND id IN ($in)");
        } elseif ($action === 'unarchive') {
            $pdo->exec("UPDATE v_sessions SET status = 'online', archived_at = NULL WHERE tenant_id = $tid AND id IN ($in)");
        } elseif ($action === 'close') {
            $pdo->exec("UPDATE v_sessions SET status = 'closed', closed_at = " . $pdo->quote($now) . ", archived_at = NULL WHERE tenant_id = $tid AND id IN ($in)");
            // 与单条结束一致：给每个会话补一条系统提示（访客端据此显示「会话已结束」）
            $ins = $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, created_at) VALUES (?,?,'system','系统','会话已结束','text',?)");
            foreach ($target as $sid) $ins->execute([$tid, $sid, $now]);
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->exec("DELETE FROM messages WHERE tenant_id = $tid AND session_id IN ($in)");
                $pdo->exec("DELETE FROM contacts WHERE tenant_id = $tid AND session_id IN ($in)");
                $pdo->exec("DELETE FROM welcome_sent WHERE tenant_id = $tid AND session_id IN ($in)");
                $pdo->exec("DELETE FROM v_sessions WHERE tenant_id = $tid AND id IN ($in)");
                $pdo->commit();
            } catch (\Throwable $e) {
                try { $pdo->rollBack(); } catch (\Throwable $e2) {}
                json_error('删除失败：' . $e->getMessage(), 500);
            }
        }
    }
    $done = count($target);

    json_out(['ok' => true, 'action' => $action, 'done' => $done, 'skipped' => $skipped]);
}

/* ---------- 标记已读 ---------- */
function handle_session_read(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    // v2.43：unread=1 时反向「标为未读」（自己记个待办，不是访客真发了消息）
    if ((int)input('unread', 0) === 1) {
        db()->prepare('UPDATE v_sessions SET unread = GREATEST(1, unread) WHERE id = ?')->execute([$sid]);
        json_out(['ok' => true, 'unread' => 1]);
    }
    db()->prepare('UPDATE v_sessions SET unread = 0 WHERE id = ?')->execute([$sid]);
    json_out(['ok' => true, 'unread' => 0]);
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

/* ---------- 编辑自己发的客服消息（v2.56）----------
   只能改「自己发的纯文字消息」；写 edited_at，双方轮询会把新文字同步过去并显示「已编辑」。 */
function handle_message_edit(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $msgId = (int)$m[2];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $text = trim((string)input('text', ''));
    if ($text === '') json_error('内容不能为空');
    if (mb_strlen($text) > 2000) json_error('内容太长了（最多 2000 字）');
    $st = db()->prepare('SELECT * FROM messages WHERE id = ? AND session_id = ? AND tenant_id = ?');
    $st->execute([$msgId, $sid, $ctx['tenant_id']]);
    $msg = $st->fetch();
    if (!$msg) json_error('消息不存在', 400);
    if ($msg['recalled_at']) json_error('已撤回的消息不能编辑', 403);
    $own = $ctx['role'] === 'tenant' || (int)$msg['sender_id'] === (int)$ctx['id'];
    if (!$own || $msg['from_type'] !== 'agent') json_error('只能编辑自己发送的客服消息', 403);
    if (($msg['msg_type'] ?? 'text') !== 'text') json_error('只能编辑纯文字消息', 403);
    $now = now_dt();
    db()->prepare('UPDATE messages SET text = ?, edited_at = ? WHERE id = ?')->execute([$text, $now, $msgId]);
    json_out(['ok' => true, 'msg_id' => $msgId, 'text' => $text, 'edited_at' => dt_to_iso($now)]);
}

/* ---------- 快捷消息：拖动排序（v2.50） ----------
   前端把拖完的顺序（id 数组）发过来，这里重写 sort（10/20/30…），
   GET 侧按 `sort` ASC, id DESC 返回，所以聊天窗口的快捷按钮顺序跟着变。 */
function handle_quick_reorder(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    if ($ctx['role'] !== 'tenant') json_error('仅账户主人可调整顺序', 403);
    $ids = input('ids', []);
    if (!is_array($ids) || !$ids) json_error('缺少顺序数据', 400);
    $pdo = db();
    $st = $pdo->prepare('UPDATE quick_msgs SET `sort` = ? WHERE id = ? AND tenant_id = ?');
    try {
        $pdo->beginTransaction();
        $n = 0;
        foreach ($ids as $id) {
            $n += 10;
            $st->execute([$n, (int)$id, $ctx['tenant_id']]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('保存顺序失败：' . $e->getMessage(), 500);
    }
    json_out(['ok' => true, 'count' => count($ids)]);
}

/* ---------- 会话置顶 / 取消置顶（v2.43） ---------- */
function handle_session_pin(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $in = input('pinned', null);
    $pdo = db();
    if ($in === null) {
        $cur = $pdo->prepare('SELECT pinned FROM v_sessions WHERE id = ? AND tenant_id = ?');
        $cur->execute([$sid, $ctx['tenant_id']]);
        $pinned = ((int)$cur->fetchColumn() === 1) ? 0 : 1;
    } else {
        $pinned = ((int)$in === 1) ? 1 : 0;
    }
    $pdo->prepare('UPDATE v_sessions SET pinned = ?, pinned_at = ? WHERE id = ? AND tenant_id = ?')
        ->execute([$pinned, $pinned ? now_dt() : null, $sid, $ctx['tenant_id']]);
    json_out(['ok' => true, 'pinned' => $pinned]);
}

/* ---------- 删除单条消息（v2.43）：租户可删任意消息，子客服只能删自己发的客服消息 ---------- */
function handle_message_delete(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)$m[1];
    $mid = (int)$m[2];
    $ctx = require_workspace();
    session_of($ctx, $sid);
    $pdo = db();
    $st = $pdo->prepare('SELECT id, from_type, sender_id FROM messages WHERE id = ? AND session_id = ? AND tenant_id = ?');
    $st->execute([$mid, $sid, $ctx['tenant_id']]);
    $msg = $st->fetch();
    if (!$msg) json_error('消息不存在或已删除', 400);
    if ($ctx['role'] !== 'tenant') {
        $own = ((int)$msg['sender_id'] === (int)$ctx['id']) && $msg['from_type'] === 'agent';
        if (!$own) json_error('只能删除自己发送的消息', 403);
    }
    $pdo->prepare('DELETE FROM messages WHERE id = ? AND session_id = ? AND tenant_id = ?')
        ->execute([$mid, $sid, $ctx['tenant_id']]);
    // 引用了这条消息的回复，把引用清掉（避免前端指向不存在的消息）
    $pdo->prepare('UPDATE messages SET reply_to = 0 WHERE session_id = ? AND reply_to = ?')->execute([$sid, $mid]);
    // 会话列表的"最近一条"跟着更新
    $last = $pdo->prepare('SELECT text, msg_type, attachments FROM messages WHERE session_id = ? ORDER BY id DESC LIMIT 1');
    $last->execute([$sid]);
    $row = $last->fetch();
    $summary = $row ? msg_summary((string)$row['text'], parse_attachments($row['attachments'] ?? null)) : '暂无消息';
    $pdo->prepare('UPDATE v_sessions SET last_msg = ? WHERE id = ?')->execute([$summary, $sid]);
    json_out(['ok' => true, 'msg_id' => $mid, 'last_msg' => $summary]);
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
        'dev_tag' => $ua['dev_tag'], 'channel' => $ua['channel'],
        'geo' => ($geoStr = lookup_geo($s['ip'])),
        'city' => geo_city($geoStr),
        'region' => geo_region($geoStr),
        'ua' => $ua,
        'visit_count' => $visitCount, 'message_count' => $msgCount,
        'first_time' => dt_to_iso($ft ?: $s['created_at']),
        'stay_seconds' => $ft ? max(0, time() - strtotime($ft)) : 0,
        // v2.60.9 恢复码：老会话第一次查看时自动补一个，客服在「访客信息」里复制给客户
        'resume_code' => resume_code_for($sid),
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
        // quick-msgs 支持自定义顺序：按 sort 升序（sort 相同再按 id 倒序 = 新加的排前面）
        'quick-msgs'       => ['table' => 'quick_msgs', 'fields' => ['title', 'content', 'group_name', 'sort', 'attachments'], 'order' => '`sort` ASC, id DESC'],
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
        $orderBy = !empty($cfg['order']) ? (string)$cfg['order'] : 'id DESC';
        $st = $pdo->prepare("SELECT id, $selCols FROM $table WHERE tenant_id = ? ORDER BY $orderBy");
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
        if ($name === 'ip-blacklist') bl_touch();
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
        if ($name === 'ip-blacklist') bl_touch();
        json_out(crud_transform($name, $st->fetch()));
    }

    if ($method === 'DELETE') {
        $st = $pdo->prepare("DELETE FROM $table WHERE id = ? AND tenant_id = ?");
        $st->execute([$id, $tid]);
        if ($name === 'ip-blacklist') bl_touch();
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
    // v2.60.11 统计口径：回收站里的（软删除）不算数——删了就等于腾出额度
    $vis = session_visible_sql($ctx) . ' AND s.deleted_at IS NULL'; // agent 只统计自己可见会话
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-6 days'));

    // v2.47 修正口径：卡片写的是「今日新会话」，就该按 created_at 数，而不是「今天最后活跃过的会话」
    // （老口径会把昨天创建、今天又说了句话的老会话也算成"今天的新会话"，和下面明细表的「新会话」对不上）
    $today_sessions = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND date(s.created_at) = ?$vis", [$tid, $today]);
    $today_active   = fetch_count($pdo, "SELECT COUNT(DISTINCT m.session_id) FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) = ? AND m.from_type = 'visitor'$vis", [$tid, $today]);
    $today_replied  = fetch_count($pdo, "SELECT COUNT(*) FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) = ? AND m.from_type = 'agent'$vis", [$tid, $today]);
    $online_visitors= fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.status != 'closed' AND s.last_online_at > DATE_SUB(NOW(), INTERVAL 25 SECOND)$vis", [$tid]);
    $total_sessions = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ?$vis", [$tid]);
    $waiting        = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.status != 'closed' AND s.agent_id = 0$vis", [$tid]);

    // 来源分布（仅 tenant 视角有额度意义；agent 也按可见范围统计）—— 同样按 created_at 口径
    $srcApp = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.quota_key = 'app' AND date(s.created_at) >= ?$vis", [$tid, $weekAgo]);
    $srcWeb = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.quota_key = 'web' AND date(s.created_at) >= ?$vis", [$tid, $weekAgo]);

    // 近 7 天趋势在下面用 recMap/actMap/repMap 一起算（口径与「近 7 天接待明细」完全一致）

    // 每日接待明细（口径统一，均可对账）：
    //   新会话   = 当天创建的会话数（created_at）
    //   活跃访客 = 当天发过言的去重会话数（访客消息）
    //   客服回复 = 当天被客服回复过的去重会话数（回复率 = 客服回复 / 活跃访客，≤100%）
    //   回复条数 = 当天客服发出的消息条数
    $st = $pdo->prepare("SELECT date(s.created_at) day, COUNT(*) c FROM v_sessions s WHERE s.tenant_id = ? AND date(s.created_at) >= ?$vis GROUP BY day");
    $st->execute([$tid, $weekAgo]);
    $recMap = [];
    foreach ($st->fetchAll() as $r) $recMap[$r['day']] = (int)$r['c'];
    $st = $pdo->prepare("SELECT date(m.created_at) day, COUNT(DISTINCT m.session_id) c FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) >= ? AND m.from_type = 'visitor'$vis GROUP BY day");
    $st->execute([$tid, $weekAgo]);
    $actMap = [];
    foreach ($st->fetchAll() as $r) $actMap[$r['day']] = (int)$r['c'];
    $st = $pdo->prepare("SELECT date(m.created_at) day, COUNT(DISTINCT m.session_id) c FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) >= ? AND m.from_type = 'agent'$vis GROUP BY day");
    $st->execute([$tid, $weekAgo]);
    $repMap = [];
    foreach ($st->fetchAll() as $r) $repMap[$r['day']] = (int)$r['c'];
    $st = $pdo->prepare("SELECT date(m.created_at) day, COUNT(*) c FROM messages m JOIN v_sessions s ON s.id = m.session_id WHERE s.tenant_id = ? AND date(m.created_at) >= ? AND m.from_type = 'agent'$vis GROUP BY day");
    $st->execute([$tid, $weekAgo]);
    $repMsgMap = [];
    foreach ($st->fetchAll() as $r) $repMsgMap[$r['day']] = (int)$r['c'];
    $daily = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $received = $recMap[$d] ?? 0; $active = $actMap[$d] ?? 0; $replied = $repMap[$d] ?? 0;
        $daily[] = ['date' => $d, 'received' => $received, 'active' => $active, 'replied' => $replied,
            'replied_msgs' => $repMsgMap[$d] ?? 0,
            'reply_rate' => $active > 0 ? min(100, round($replied / $active * 100, 1)) : 0];
    }

    // 近 7 天趋势（v2.47 修正）：
    //   旧口径 = 按 date(last_time) 分组，等于"每条会话只算在它最后一次说话的那天"，
    //           既不是新增也不是活跃，还会把老会话挪到后一天 —— 用户反馈"趋势不对"。
    //   新口径 = 和「接待明细」同一套数（同一天同一份数据，图表和表格必须对得上）：
    //           count  = 当天新会话（created_at）
    //           active = 当天有发言的去重会话（访客消息）
    //           replied= 当天被客服回复过的去重会话
    $trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trend[] = ['date' => substr($d, 5),
            'count'   => $recMap[$d] ?? 0,
            'active'  => $actMap[$d] ?? 0,
            'replied' => $repMap[$d] ?? 0];
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
            // 会话总额度（管理员设置；0=不限）
            'sess_limit' => (int)($t['max_sessions'] ?? 0), 'sess_used' => $total_sessions,
        ];
    }
    json_out($out);
}

/* ---------- 导出 CSV ---------- */
/* ---------- 语音转文字（v2.41）：AIHubMix / OpenAI 兼容 STT，结果按消息缓存 ---------- */

function asr_cfg(): array
{
    return [
        'on'    => kv_get('asr_on', '1') === '1',
        'key'   => kv_get('asr_key', ''),
        'base'  => rtrim(kv_get('asr_base', 'https://aihubmix.com/v1'), '/'),
        'model' => kv_get('asr_model', 'whisper-large-v3'),
        'lang'  => kv_get('asr_lang', 'zh'),
    ];
}

function handle_msg_transcribe(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $mid = (int)input('message_id', 0);
    if ($mid <= 0) json_error('参数缺失');
    $pdo = db();
    $st = $pdo->prepare('SELECT id, session_id, tenant_id, msg_type, attachments FROM messages WHERE id = ? AND tenant_id = ?');
    $st->execute([$mid, $ctx['tenant_id']]);
    $msg = $st->fetch();
    if (!$msg) json_error('消息不存在', 404);
    session_of($ctx, (int)$msg['session_id']);   // 越权检查
    $atts = parse_attachments($msg['attachments']);
    $url = '';
    foreach ($atts as $a) { if (($a['type'] ?? '') === 'audio' && !empty($a['url'])) { $url = (string)$a['url']; break; } }
    if ($url === '') json_error('这条消息里没有语音');

    // 命中缓存直接返回（同一条语音只转一次）
    $c = $pdo->prepare('SELECT text FROM msg_transcripts WHERE message_id = ? AND url = ? LIMIT 1');
    $c->execute([$mid, $url]);
    $hit = $c->fetchColumn();
    if ($hit !== false && $hit !== null) { json_out(['text' => (string)$hit, 'cached' => true]); return; }

    $cfg = asr_cfg();
    if (!$cfg['on'] || $cfg['key'] === '') json_error('语音转文字未开通，请联系平台管理员', 503);

    // 只允许读站内的语音文件（防目录穿越）
    $file = '';
    if (str_starts_with($url, '/uploads/')) {
        $p = realpath(__DIR__ . '/../uploads/' . basename($url));
        $base = realpath(__DIR__ . '/../uploads');
        if ($p && $base && str_starts_with($p, $base)) $file = $p;
    } elseif (str_starts_with($url, '/assets/')) {
        $p = realpath(__DIR__ . '/..' . $url);
        $base = realpath(__DIR__ . '/../assets');
        if ($p && $base && str_starts_with($p, $base)) $file = $p;
    }
    if ($file === '' || !is_file($file)) json_error('语音文件不存在或不是本站文件');
    if (filesize($file) > 25 * 1024 * 1024) json_error('语音太大（>25MB），无法转写');

    $api = $cfg['base'] . '/audio/transcriptions';
    $post = [
        'file' => new CURLFile($file),
        'model' => $cfg['model'],
        'response_format' => 'json',
        'temperature' => '0.2',
    ];
    if ($cfg['lang'] !== '') $post['language'] = $cfg['lang'];
    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['key']],
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $j = json_decode($body, true);
    $text = '';
    if (is_array($j)) $text = trim((string)($j['text'] ?? ''));
    if ($code < 200 || $code >= 300 || $text === '') {
        $why = is_array($j) ? (string)($j['error']['message'] ?? $j['message'] ?? '') : ($err ?: '转写失败');
        json_error('转写失败：' . mb_substr($why !== '' ? $why : ('HTTP ' . $code), 0, 200), 502);
    }
    $text = mb_substr($text, 0, 2000);
    $pdo->prepare('INSERT INTO msg_transcripts (tenant_id, message_id, url, text) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE text = VALUES(text)')->execute([$ctx['tenant_id'], $mid, $url, $text]);
    json_out(['text' => $text, 'cached' => false]);
}

function handle_export_sessions(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $pdo = db();
    $vis = session_visible_sql($ctx) . ' AND s.deleted_at IS NULL';   // 导出不含回收站
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
    presence_touch('a:' . $ctx['id']);      // 在线状态随轮询刷新（内存只留在线的，停活由清理逻辑删除）
    presence_prune();                        // 惰性清理：超过窗口未活动的直接从内存删除
    $cfg = config();
    // 已结束会话自动清理（懒执行；closed_keep_days=0 永久保留）
    try { cleanup_closed_sessions($ctx['tenant_id']); } catch (\Throwable $e) {}
    $scope = in_array((string)input('scope', 'online'), ['online', 'archived', 'closed', 'trash'], true) ? (string)input('scope', 'online') : 'online';
    $onlineSql = "CASE WHEN s.status IN ('closed','archived') THEN s.status
        WHEN s.last_online_at IS NOT NULL AND s.last_online_at > DATE_SUB(NOW(), INTERVAL " . (int)$cfg['session_online_secs'] . " SECOND) THEN 'online'
        ELSE 'offline' END";
    $st = $pdo->prepare("SELECT s.id, s.agent_id, a.nickname AS agent_name, s.visitor_name AS name, s.visitor_avatar,
        (SELECT value FROM contacts WHERE session_id = s.id AND type = 'note' LIMIT 1) AS note,
        s.ip, s.from_url AS `from`, s.last_msg AS lastMsg, s.last_time AS lastTime, s.unread, s.source, s.geo, s.ua,
        s.archived_at, s.closed_at, s.last_online_at AS lastOnline,
        s.pinned, s.pinned_at,
        $onlineSql AS status,
        (SELECT COUNT(*) FROM messages WHERE session_id = s.id) AS message_count
        FROM v_sessions s LEFT JOIN agents a ON a.id = s.agent_id AND a.tenant_id = s.tenant_id
        WHERE s.tenant_id = ?" . session_scope_sql($scope) . session_visible_sql($ctx) . ($scope === 'trash' ? '' : session_spoken_sql()) . "
        ORDER BY s.pinned DESC, s.pinned_at DESC, s.last_time DESC LIMIT 500");
    $st->execute([$ctx['tenant_id']]);
    $sessions = [];
    foreach ($st->fetchAll() as $r) {
        $r['lastTime'] = dt_to_iso($r['lastTime']);
        $r['lastOnline'] = dt_to_iso($r['lastOnline'] ?? null);   // v2.60.1 访客最后上线（心跳时间）
        $r['unread'] = (int)$r['unread'];
        $r['agent_id'] = (int)$r['agent_id'];
        $r['message_count'] = (int)$r['message_count'];
        $r['pinned'] = (int)($r['pinned'] ?? 0);
        $r['visitor_avatar'] = visitor_avatar_resolve((int)$r['id'], (string)($r['visitor_avatar'] ?? ''));
        $uaInfo = parse_ua($r['ua'] ?? null);          // 访客标签（设备/渠道保留给悬停全文）
        $r['devTag'] = $uaInfo['dev_tag'];
        $r['channel'] = $uaInfo['channel'];
        $r['city'] = geo_city($r['geo'] ?? null);
        $r['region'] = geo_region($r['geo'] ?? null);   // v2.29：列表主标签=地区（省市+运营商）
        unset($r['ua']);
        $sessions[] = $r;
    }
    // 枢纽模式：总控内存在线表优先（边缘节点的轮询也会经过总控）；未启用时前端自动回退 DB 判定
    if (mem_ok()) {
        $pres = presence_all();
        foreach ($sessions as &$rowS) {
            $rowS['mem_online'] = isset($pres['v:' . (int)$rowS['id']]);
        }
        unset($rowS);
    }
    // 各分区计数（tab 徽标 + 未读提示）
    $vis = session_visible_sql($ctx) . session_spoken_sql();
    $cnt = $pdo->prepare("SELECT
        SUM(status NOT IN ('closed','archived')) AS online_cnt,
        SUM(status = 'archived') AS archived_cnt,
        SUM(status = 'closed') AS closed_cnt,
        COALESCE(SUM(CASE WHEN status NOT IN ('closed','archived') THEN unread ELSE 0 END), 0) AS online_unread,
        COALESCE(SUM(CASE WHEN status = 'archived' THEN unread ELSE 0 END), 0) AS archived_unread
        FROM v_sessions s WHERE s.tenant_id = ? AND s.deleted_at IS NULL" . $vis);
    $cnt->execute([$ctx['tenant_id']]);
    $c = $cnt->fetch() ?: [];
    $counts = [
        'online' => (int)($c['online_cnt'] ?? 0),
        'archived' => (int)($c['archived_cnt'] ?? 0),
        'closed' => (int)($c['closed_cnt'] ?? 0),
        'trash' => fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = ? AND s.deleted_at IS NOT NULL' . session_visible_sql($ctx), [$ctx['tenant_id']]),
        'online_unread' => (int)($c['online_unread'] ?? 0),
        'archived_unread' => (int)($c['archived_unread'] ?? 0),
    ];

    $msgs = [];
    $readUpto = 0;
    $recalledIds = [];
    $editedMsgs = [];
    $session = (int)input('session', 0);
    $sinceMsg = (int)input('sinceMsg', 0);
    if ($session) {
        $ru = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM messages WHERE session_id = ? AND from_type = 'agent' AND read_at IS NOT NULL");
        $ru->execute([$session]);
        $readUpto = (int)$ru->fetchColumn();
        // 撤回同步：最近 10 分钟内被撤回的消息 id（前端据此标记本地消息）
        $rc2 = $pdo->prepare('SELECT id FROM messages WHERE session_id = ? AND recalled_at IS NOT NULL AND recalled_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        $rc2->execute([$session]);
        $recalledIds = array_map('intval', $rc2->fetchAll(PDO::FETCH_COLUMN));
        // v2.56 编辑同步：最近 10 分钟被编辑过的消息（id + 新文字）
        $ed2 = $pdo->prepare('SELECT id, text FROM messages WHERE session_id = ? AND edited_at IS NOT NULL AND edited_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        $ed2->execute([$session]);
        $editedMsgs = array_map(fn($r) => ['id' => (int)$r['id'], 'text' => (string)$r['text']], $ed2->fetchAll());
    }
    if ($session && $sinceMsg > 0) {
        session_of($ctx, $session); // 越权检查
        // v2.60.23 修 bug：这里原来漏了 reply_to —— 客服端轮询拿新消息时拿不到「回复的是哪条」，
        // 访客回复某句话时客服端就看不到引用条（初次打开会话是另一条 SQL，那条是有的，所以只有轮询到的消息有问题）
        $st = $pdo->prepare('SELECT id, session_id, from_type AS `from`, sender_id, sender_name, text, msg_type, attachments, reply_to, recalled_at, edited_at, created_at FROM messages WHERE session_id = ? AND id > ? ORDER BY id ASC');
        $st->execute([$session, $sinceMsg]);
        $msgs = array_map('to_msg', $st->fetchAll());
    }
    // 地理信息渐进补查：每轮最多补 2 个未解析的 IP（写回 v_sessions.geo，配合 1h 缓存）
    try {
        $needGeo = [];
        foreach ($sessions as $row) {
            if (count($needGeo) >= 2) break;
            if (empty($row['geo']) && !empty($row['ip'])) $needGeo[$row['id']] = $row['ip'];
        }
        foreach ($needGeo as $sidG => $ipG) {
            $g = lookup_geo($ipG);
            if ($g !== '') {
                $pdo->prepare('UPDATE v_sessions SET geo = ? WHERE id = ?')->execute([$g, $sidG]);
                foreach ($sessions as &$rowUpd) {
                    if ((int)$rowUpd['id'] === $sidG) { $rowUpd['geo'] = $g; break; }
                }
                unset($rowUpd);
            }
        }
    } catch (\Throwable $e) { /* 地理补查失败不影响轮询 */ }
    json_out(['sessions' => $sessions, 'messages' => $msgs, 'readUpto' => $readUpto, 'recalledIds' => $recalledIds, 'editedMsgs' => $editedMsgs, 'now' => time(), 'counts' => $counts]);
}
