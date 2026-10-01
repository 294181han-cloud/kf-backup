<?php
/**
 * routes/visitor.php - 访客端（公开接口）
 *
 * 租户定位优先级：?code=客服码 → ?t=卡密 → 子域名(domain_links)
 */
declare(strict_types=1);

/** 解析访客上下文：返回 ['tenant'=>行, 'agent_id'=>int] 或 null */
function resolve_visitor_context(): ?array
{
    $pdo = db();
    $code = trim((string)input('code', ''));
    $t = trim((string)input('t', ''));

    if ($code !== '') {
        $st = $pdo->prepare('SELECT sc.agent_id, tn.* FROM service_codes sc JOIN tenants tn ON tn.id = sc.tenant_id WHERE sc.code = ? LIMIT 1');
        $st->execute([$code]);
        $r = $st->fetch();
        if ($r) return ['tenant' => $r, 'agent_id' => (int)$r['agent_id']];
    }

    if ($t !== '') {
        $st = $pdo->prepare('SELECT * FROM tenants WHERE kami = ? LIMIT 1');
        $st->execute([$t]);
        $r = $st->fetch();
        if ($r) return ['tenant' => $r, 'agent_id' => 0];
    }

    // 子域名匹配：sub.rootdomain 或泛解析前缀
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host !== '') {
        $st = $pdo->prepare('SELECT dl.subdomain, tn.* FROM domain_links dl JOIN tenants tn ON tn.id = dl.tenant_id WHERE dl.active = 1');
        $st->execute();
        foreach ($st->fetchAll() as $dl) {
            $sub = strtolower($dl['subdomain']);
            if ($host === $sub || str_starts_with($host, $sub . '.')) {
                return ['tenant' => $dl, 'agent_id' => 0];
            }
        }
    }
    return null;
}

/** 租户可用性：ok / 停用 / 到期 / 超额 */
function tenant_availability(array $tenant): array
{
    if ($tenant['status'] !== 'active') return ['ok' => false, 'reason' => '该客服账号已被停用'];
    if ($tenant['expires_at'] && strtotime($tenant['expires_at']) < time()) return ['ok' => false, 'reason' => '该客服账号已到期'];
    return ['ok' => true, 'reason' => ''];
}

/** 近 7 天该租户对应渠道进粉数 */
function quota_used(int $tenantId, string $key): int
{
    return fetch_count(db(), 'SELECT COUNT(*) FROM v_sessions WHERE tenant_id = ? AND quota_key = ? AND last_time >= ?',
        [$tenantId, $key, now_dt(strtotime('-6 days'))]);
}

/** 租户品牌配置 */
function tenant_brand(array $tenant): array
{
    $tid = (int)$tenant['id'];
    return [
        'company_name'  => ts_get($tid, 'brand_company', $tenant['nickname'] ?: '在线客服'),
        'welcome_title' => ts_get($tid, 'brand_title', '您好，欢迎咨询！'),
        'welcome_sub'   => ts_get($tid, 'brand_sub', '请简单描述您的问题，客服会尽快回复'),
        'avatar'        => ts_get($tid, 'brand_avatar', '💬'),
        'theme_color'   => ts_get($tid, 'brand_color', '#1677ff'),
        'pre_inquiry_on' => ts_get($tid, 'pre_inquiry_on', '0') === '1',
        'show_position' => ts_get($tid, 'brand_position', 'right-bottom'),
        'notice_on'     => ts_get($tid, 'notice_on', '0') === '1',
        'notice_text'   => ts_get($tid, 'notice_text', ''),
    ];
}

/* ---------- GET /api/visitor/config ---------- */
function handle_visitor_config(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = resolve_visitor_context();
    if (!$ctx) json_error('链接无效，请联系客服获取新的链接', 400);
    $t = $ctx['tenant'];
    $av = tenant_availability($t);
    $brand = tenant_brand($t);
    // 超额只对新建会话有影响，config 阶段不拦截（前端显示名额已满提示）
    $webFull = quota_used((int)$t['id'], 'web') >= (int)$t['quota_web'];
    $appFull = quota_used((int)$t['id'], 'app') >= (int)$t['quota_app'];
    json_out([
        'kami' => $t['kami'],
        'available' => $av['ok'],
        'unavailable_reason' => $av['reason'],
        'brand' => $brand,
        'quota_full' => $webFull && $appFull ? 'all' : ($appFull ? 'app' : ($webFull ? 'web' : '')),
        'online' => true,
    ]);
}

/* ---------- GET /api/visitor/pre-inquiry ---------- */
function handle_visitor_pre_inquiry(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = resolve_visitor_context();
    if (!$ctx) json_error('链接无效', 400);
    $tid = (int)$ctx['tenant']['id'];
    $rows = db()->query('SELECT id, label, type, required, placeholder FROM pre_inquiries WHERE tenant_id = ' . $tid . ' ORDER BY sort ASC, id ASC')->fetchAll();
    $out = array_map(function ($r) {
        $key = 'other';
        if (preg_match('/称呼|名字|姓名|昵称|贵姓/', $r['label'])) $key = 'name';
        elseif (preg_match('/联系|手机|电话|邮箱|微信|QQ|qq/', $r['label'])) $key = 'phone';
        elseif (preg_match('/问题|咨询|描述|需求|留言|想/', $r['label'])) $key = 'question';
        return $r + ['required' => (bool)$r['required'], 'key' => $key];
    }, $rows);
    json_out($out);
}

/* ---------- POST /api/visitor/init ---------- */
function handle_visitor_init(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = resolve_visitor_context();
    if (!$ctx) json_error('链接无效，请联系客服获取新的链接', 400);
    $t = $ctx['tenant'];
    $tid = (int)$t['id'];
    $av = tenant_availability($t);
    if (!$av['ok']) json_error($av['reason'], 403);

    $ip = get_client_ip();
    if (is_ip_blocked($tid, $ip)) json_error('您的访问受限，如有疑问请更换网络或联系客服', 403);

    $pdo = db();
    $now = now_dt();
    $visitorToken = trim((string)input('visitor_token', ''));

    // ① 复用该租户下最近未关闭会话；开启「访客去重」时 24 小时内的已关闭会话也复用（重开，不占新额度）
    if ($visitorToken !== '') {
        $dedup = ts_get($tid, 'dedup_on', '0') === '1';
        if ($dedup) {
            $st = $pdo->prepare("SELECT * FROM v_sessions WHERE tenant_id = ? AND visitor_token = ? AND (status != 'closed' OR last_time >= ?) ORDER BY last_time DESC LIMIT 1");
            $st->execute([$tid, $visitorToken, now_dt(time() - 86400)]);
        } else {
            $st = $pdo->prepare("SELECT * FROM v_sessions WHERE tenant_id = ? AND visitor_token = ? AND status != 'closed' ORDER BY last_time DESC LIMIT 1");
            $st->execute([$tid, $visitorToken]);
        }
        $old = $st->fetch();
        if ($old) {
            $sid = (int)$old['id'];
            if ($old['status'] === 'closed') {
                $pdo->prepare("UPDATE v_sessions SET status = 'online', last_online_at = ? WHERE id = ?")->execute([$now, $sid]);
                $old['status'] = 'online';
            } else {
                $pdo->prepare('UPDATE v_sessions SET last_online_at = ? WHERE id = ?')->execute([$now, $sid]);
            }
            json_out([
                'session_id' => $sid,
                'visitor_token' => $visitorToken,
                'reused' => true,
                'session' => visitor_session_out($old),
            ]);
        }
    }

    // ② 新建会话：IP 频率限制（每分钟新建会话超过 N 个自动拉黑）
    if (ts_get($tid, 'ip_limit_on', '0') === '1') {
        $limit = max(1, (int)ts_get($tid, 'ip_limit_count', '5'));
        $recent = fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions WHERE tenant_id = ? AND ip = ? AND created_at > ?',
            [$tid, $ip, now_dt(time() - 60)]);
        if ($recent >= $limit) {
            try {
                $pdo->prepare('INSERT IGNORE INTO ip_blacklist (tenant_id, ip, reason) VALUES (?,?,?)')
                    ->execute([$tid, $ip, 'IP 频率超限自动拉黑']);
            } catch (\Throwable $e) {}
            json_error('访问过于频繁，请稍后再试', 403);
        }
    }

    // ③ 新建会话（额度检查）
    $code = trim((string)input('code', ''));
    $quotaKey = $code !== '' ? 'app' : 'web';
    $limit = (int)($quotaKey === 'app' ? $t['quota_app'] : $t['quota_web']);
    $used = quota_used($tid, $quotaKey);
    if ($used >= $limit) {
        json_error('本周客服名额已满，请下周再来或联系客服', 403);
    }

    // 访客名：自定义 or 自动编号
    $rawName = mb_substr(trim((string)input('name', '')), 0, 30);
    $isAuto = $rawName === '' || (bool)preg_match('/^\d{1,2}-\d{1,2} \d{4}$/', $rawName);
    $name = $isAuto ? gen_visitor_name($tid) : $rawName;
    $phone = mb_substr(trim((string)input('phone', '')), 0, 50);
    $question = mb_substr(trim((string)input('question', '')), 0, 500);
    $fromUrl = mb_substr((string)input('from_url', '直接访问'), 0, 255) ?: '直接访问';
    if ($visitorToken === '') $visitorToken = 'vt_' . bin2hex(random_bytes(8));

    $firstMsg = $question !== '' ? $question : '等待接入...';
    $pdo->prepare('INSERT INTO v_sessions (tenant_id, agent_id, visitor_name, visitor_token, ip, from_url, last_msg, last_time, unread, status, source, quota_key, last_online_at, ua) VALUES (?,?,?,?,?,?,?,?,0,\'online\',?,?,?,?)')
        ->execute([$tid, $ctx['agent_id'], $name, $visitorToken, $ip, $fromUrl, $firstMsg, $now,
            $code !== '' ? 'app' : 'web', $quotaKey, $now, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
    $sid = (int)$pdo->lastInsertId();

    if ($phone !== '') {
        $pdo->prepare('INSERT INTO contacts (tenant_id, session_id, type, value, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([$tid, $sid, 'mobile', $phone, $now]);
    }
    if ($question !== '') {
        $pdo->prepare('INSERT INTO messages (tenant_id, session_id, from_type, text, msg_type, created_at) VALUES (?,?,\'visitor\',?,?,?)')
            ->execute([$tid, $sid, $question, 'text', $now]);
    }

    $sess = $pdo->prepare('SELECT * FROM v_sessions WHERE id = ?');
    $sess->execute([$sid]);
    json_out([
        'session_id' => $sid,
        'visitor_token' => $visitorToken,
        'reused' => false,
        'session' => visitor_session_out($sess->fetch()),
    ]);
}

function visitor_session_out(array $s): array
{
    return [
        'id' => (int)$s['id'], 'name' => $s['visitor_name'], 'ip' => $s['ip'],
        'from' => $s['from_url'], 'lastMsg' => $s['last_msg'], 'lastTime' => dt_to_iso($s['last_time']),
        'status' => $s['status'],
    ];
}

/* ---------- POST /api/visitor/welcome ---------- */
function handle_visitor_welcome(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)input('session_id', 0);
    $name = mb_substr((string)input('name', ''), 0, 30);
    if (!$sid) json_error('缺少 session_id');

    $pdo = db();
    $st = $pdo->prepare('SELECT tenant_id, ip FROM v_sessions WHERE id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) json_error('会话不存在', 400);
    $tid = (int)$s['tenant_id'];
    if (is_ip_blocked($tid, $s['ip'] ?: get_client_ip())) json_error('您的访问受限', 403);

    $msgs = db()->query('SELECT * FROM welcome_msgs WHERE tenant_id = ' . $tid . ' AND enabled = 1 ORDER BY sort ASC, id ASC LIMIT 5')->fetchAll();
    if (!$msgs) json_out(['sent' => false, 'reason' => '没有可用的欢迎语']);

    // 已发记录（once 条不重发）
    $sentSet = [];
    $st = $pdo->prepare('SELECT welcome_id FROM welcome_sent WHERE session_id = ?');
    $st->execute([$sid]);
    foreach ($st->fetchAll() as $r) $sentSet[(int)$r['welcome_id']] = true;

    $now = now_dt();
    $insM = $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, attachments, created_at) VALUES (?,?,'system',?,?,?,?,?)");
    $insSent = $pdo->prepare('INSERT IGNORE INTO welcome_sent (tenant_id, session_id, welcome_id) VALUES (?,?,?)');
    $ids = [];
    $lastText = '';
    foreach ($msgs as $m) {
        $wId = (int)$m['id'];
        $trig = ($m['trig'] ?? 'once') === 'every' ? 'every' : 'once';
        if ($trig === 'once' && isset($sentSet[$wId])) continue;
        $text = str_replace('{name}', $name ?: '访客', (string)$m['content']);
        $att = parse_attachments($m['attachments'] ?? '');
        $imgs = array_slice(array_values(array_filter($att, fn($a) => is_array($a) && ($a['type'] ?? '') === 'image')), 0, 3);
        $type = $text !== '' && $imgs ? 'mixed' : ($imgs ? 'image' : 'text');
        $insM->execute([$tid, $sid, '欢迎语', $text, $type, $imgs ? json_encode($imgs, JSON_UNESCAPED_UNICODE) : '', $now]);
        $ids[] = (int)$pdo->lastInsertId();
        $lastText = $text !== '' ? $text : '[图片]';
        if ($trig === 'once') $insSent->execute([$tid, $sid, $wId]);
    }
    if ($ids) {
        $pdo->prepare('UPDATE v_sessions SET last_msg = ?, last_time = ?, unread = unread + 1 WHERE id = ?')
            ->execute([$lastText, $now, $sid]);
    }
    json_out(['sent' => (bool)$ids, 'count' => count($ids), 'message_ids' => $ids]);
}

/* ---------- GET /api/visitor/messages ---------- */
function handle_visitor_messages(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $sid = (int)input('session_id', 0);
    $since = (int)input('since', 0);
    if (!$sid) json_error('缺少 session_id');
    $pdo = db();
    $sql = 'SELECT id, session_id, from_type AS `from`, sender_name, text, msg_type, attachments, created_at FROM messages WHERE session_id = ?' . ($since > 0 ? ' AND id > ?' : '') . ' ORDER BY id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($since > 0 ? [$sid, $since] : [$sid]);
    json_out(['messages' => array_map('to_msg', $st->fetchAll())]);
}

/* ---------- POST /api/visitor/upload ---------- */
function handle_visitor_upload(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)input('session_id', 0);
    $data = (string)input('data', '');
    $kind = (string)input('kind', '');
    $filename = (string)input('filename', '');
    if (!$sid || $data === '' || $kind === '') json_error('参数缺失');
    $pdo = db();
    $st = $pdo->prepare('SELECT tenant_id, ip FROM v_sessions WHERE id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) json_error('会话不存在', 400);
    if (is_ip_blocked((int)$s['tenant_id'], $s['ip'] ?: get_client_ip())) json_error('您的访问受限', 403);
    try { json_out(save_upload($data, $kind, $filename)); }
    catch (\Throwable $e) { json_error($e->getMessage()); }
}

/* ---------- GET /api/poll（访客版） ---------- */
function handle_visitor_poll(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $sid = (int)input('session', 0);
    $sinceMsg = (int)input('sinceMsg', 0);
    if (!$sid) json_out(['messages' => [], 'now' => time()]);
    $pdo = db();
    // 心跳：仅更新本人会话
    $pdo->prepare('UPDATE v_sessions SET last_online_at = ? WHERE id = ?')->execute([now_dt(), $sid]);
    $st = $pdo->prepare('SELECT id, session_id, from_type AS `from`, sender_name, text, msg_type, attachments, created_at FROM messages WHERE session_id = ? AND id > ? ORDER BY id ASC');
    $st->execute([$sid, $sinceMsg]);
    json_out(['messages' => array_map('to_msg', $st->fetchAll()), 'now' => time()]);
}
/* ---------- POST /api/visitor/send ---------- */
function handle_visitor_send(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)input('session_id', 0);
    $text = trim((string)input('text', ''));
    $attachArr = input('attachments', []);
    $attachArr = is_array($attachArr) ? $attachArr : [];
    if (!$sid || ($text === '' && !$attachArr)) json_error('参数缺失');

    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM v_sessions WHERE id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) json_error('会话不存在', 400);
    $tid = (int)$s['tenant_id'];
    $ip = $s['ip'] ?: get_client_ip();
    if (is_ip_blocked($tid, $ip)) json_error('您的访问受限', 403);
    if ($s['status'] === 'closed') json_error('会话已结束', 403);

    // 限频
    $cfg = config();
    $recent = fetch_count($pdo, "SELECT COUNT(*) FROM messages WHERE session_id = ? AND from_type = 'visitor' AND created_at > ?",
        [$sid, now_dt(time() - 60)]);
    if ($recent >= $cfg['rate_limit_per_min']) json_error('发送过快，请稍后再试', 429);

    $text = mb_substr($text, 0, 2000);
    // 访客过滤：消息命中黑名单关键词 → 自动拉黑并拒绝
    if ($text !== '' && ts_get($tid, 'auto_blacklist', '0') === '1') {
        $kws = array_filter(array_map('trim', explode(',', ts_get($tid, 'filter_keywords', ''))));
        foreach ($kws as $kw) {
            if ($kw !== '' && str_contains($text, $kw)) {
                try {
                    $pdo->prepare('INSERT IGNORE INTO ip_blacklist (tenant_id, ip, reason) VALUES (?,?,?)')
                        ->execute([$tid, $ip, '关键词命中自动拉黑：' . $kw]);
                } catch (\Throwable $e) {}
                json_error('消息包含违规内容，发送失败', 403);
            }
        }
    }
    $msgType = resolve_msg_type($text, $attachArr);
    $attachJson = $attachArr ? json_encode($attachArr, JSON_UNESCAPED_UNICODE) : '';
    $now = now_dt();
    $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, text, msg_type, attachments, created_at) VALUES (?,?,'visitor',?,?,?,?)")
        ->execute([$tid, $sid, $text, $msgType, $attachJson, $now]);
    $mid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE v_sessions SET last_msg = ?, last_time = ?, unread = unread + 1, last_online_at = ? WHERE id = ?')
        ->execute([msg_summary($text, $attachArr), $now, $now, $sid]);

    $msg = ['id' => $mid, 'from' => 'visitor', 'text' => $text, 'time' => dt_to_iso($now),
        'session_id' => $sid, 'msg_type' => $msgType, 'attachments' => $attachArr];

    // 关键词自动回复（以客服身份）
    $autoReply = null;
    if ($text !== '') {
        $st = $pdo->prepare('SELECT keyword, reply FROM auto_replies WHERE tenant_id = ? AND enabled = 1 ORDER BY id ASC');
        $st->execute([$tid]);
        foreach ($st->fetchAll() as $rule) {
            if ($rule['keyword'] !== '' && str_contains($text, $rule['keyword'])) {
                $replyText = mb_substr((string)$rule['reply'], 0, 2000);
                $rNow = now_dt();
                $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, created_at) VALUES (?,?,'agent',?,?,?,?)")
                    ->execute([$tid, $sid, '自动回复', $replyText, 'text', $rNow]);
                $autoReply = ['id' => (int)$pdo->lastInsertId(), 'from' => 'agent', 'sender_name' => '自动回复',
                    'text' => $replyText, 'time' => dt_to_iso($rNow), 'session_id' => $sid, 'msg_type' => 'text', 'attachments' => []];
                $pdo->prepare('UPDATE v_sessions SET last_msg = ?, last_time = ? WHERE id = ?')
                    ->execute([mb_substr($replyText, 0, 60), $rNow, $sid]);
                break;
            }
        }
    }
    json_out(['ok' => true, 'message' => $msg, 'auto_reply' => $autoReply, 'now' => time()]);
}
