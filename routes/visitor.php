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
    // 枢纽模式：分控反代来的请求带 X-Original-Host（访客真实访问的域名），
    // 仅当 X-Edge-Secret 与总控 relay_secret 匹配（可信边缘）时才采信，防伪造
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host);
    $edgeSecret = (string)(config()['relay_secret'] ?? '');
    $edgeGiven = (string)($_SERVER['HTTP_X_EDGE_SECRET'] ?? '');
    if ($edgeSecret !== '' && $edgeGiven !== '' && hash_equals($edgeSecret, $edgeGiven)) {
        $orig = strtolower((string)($_SERVER['HTTP_X_ORIGINAL_HOST'] ?? ''));
        $orig = preg_replace('/:\d+$/', '', $orig);
        if ($orig !== '') $host = $orig;
    }
    if ($host !== '') {
        $st = $pdo->prepare('SELECT dl.subdomain, tn.* FROM domain_links dl JOIN tenants tn ON tn.id = dl.tenant_id WHERE dl.active = 1');
        $st->execute();
        foreach ($st->fetchAll() as $dl) {
            $sub = strtolower($dl['subdomain']);
            if ($host === $sub || str_starts_with($host, $sub . '.')) {
                return ['tenant' => $dl, 'agent_id' => 0];
            }
        }
        // 客服码根路径子域（v2.28 链接格式）：service_codes.link 的 Host 精确匹配
        // 列名防冲突：tenants 历史遗留 link 列会覆盖 sc.link（tn.* 通配的坑），显式别名
        $st = $pdo->prepare('SELECT sc.link AS sc_link, sc.code, sc.agent_id, tn.* FROM service_codes sc JOIN tenants tn ON tn.id = sc.tenant_id');
        $st->execute();
        foreach ($st->fetchAll() as $sc) {
            $linkRaw = $sc['sc_link'] ?? '';
            if (!is_string($linkRaw) || $linkRaw === '') continue;
            $linkHost = strtolower((string)(parse_url($linkRaw, PHP_URL_HOST) ?: ''));
            if ($linkHost !== '' && $host === $linkHost) {
                unset($sc['sc_link']);
                return ['tenant' => $sc, 'agent_id' => (int)$sc['agent_id']];
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
    return fetch_count(db(), 'SELECT COUNT(*) FROM v_sessions WHERE tenant_id = ? AND deleted_at IS NULL AND quota_key = ? AND last_time >= ?',
        [$tenantId, $key, now_dt(strtotime('-6 days'))]);
}

/** 租户品牌配置 */
function tenant_brand(array $tenant): array
{
    $tid = (int)$tenant['id'];
    return [
        // v2.78 品牌名称默认固定「在线客服」（不再跟着卡密的名称走——卡密名 ≠ 品牌名）
        'company_name'  => ts_get($tid, 'brand_company', '在线客服'),
        'welcome_title' => ts_get($tid, 'brand_title', '您好，欢迎咨询！'),
        'welcome_sub'   => ts_get($tid, 'brand_sub', '请简单描述您的问题，客服会尽快回复'),
        'avatar'        => ts_get($tid, 'brand_avatar', '💬'),
        'theme_color'   => ts_get($tid, 'brand_color', '#1677ff'),
        'pre_inquiry_on' => ts_get($tid, 'pre_inquiry_on', '0') === '1',
        'show_position' => ts_get($tid, 'brand_position', 'right-bottom'),
        // v2.78 公告默认开 + 默认文案
        'notice_on'     => ts_get($tid, 'notice_on', '1') === '1',
        // v2.77.1 默认值必须和后台（routes/tenant.php）一致 —— 之前这里还是 whatsapp，
        // 于是"没单独设过模板"的租户：后台显示「默认客服」，访客端却渲染成 WhatsApp。
        'template'      => ts_get($tid, 'visitor_template', 'default'),
        'notice_text'   => ts_get($tid, 'notice_text', '不要关闭此网页，若退出请先复制浏览器打开'),
        // 验证策略
        'robot'          => ts_get($tid, 'robot', 'no'),
        'robot_frequency' => ts_get($tid, 'robot_frequency', 'first'),
        'robot_type_is_code' => in_array(ts_get($tid, 'robot', 'no'), ['invite', 'password'], true),
        // 弹窗公告 / 滚动通知
        'publicity_on'    => ts_get($tid, 'publicity_on', '0') === '1',
        'publicity_title' => ts_get($tid, 'publicity_title', ''),
        'publicity_content' => ts_get($tid, 'publicity_content', ''),
        'inform_on'       => ts_get($tid, 'inform_on', '0') === '1',
        'inform_text'     => ts_get($tid, 'inform_text', ''),
        // 导航深度配置
        'tpl_nav_on'      => ts_get($tid, 'tpl_nav_on', '1') === '1',
        'tpl_nav_title'   => ts_get($tid, 'tpl_nav_title', ''),
        'vip_badge'       => ts_get($tid, 'vip_badge', ''),
        'footer_text'     => ts_get($tid, 'footer_text', ''),
        // 链接模式
        'link_mode'       => ts_get($tid, 'link_mode', 'no'),
        // 声音设置（v2.27，访客端：接收/发送分开开关与选音）
        'snd_recv_on'    => ts_get($tid, 'visitor_snd_recv_on', '1') === '1',
        'snd_recv_which' => ts_get($tid, 'visitor_snd_recv_which', 'dindon'),
        'snd_send_on'    => ts_get($tid, 'visitor_snd_send_on', '1') === '1',
        'snd_send_which' => ts_get($tid, 'visitor_snd_send_which', 'water'),
        // 访客头像/名称类型
        'user_head_type'  => ts_get($tid, 'user_head_type', 'default'),
        'user_head'       => ts_get($tid, 'user_head', ''),
        'user_name_type'  => ts_get($tid, 'user_name_type', 'default'),
        // 码设置显示开关
        'show_nickname'   => ts_get($tid, 'code_nickname_on', '1') === '1',
        'show_support'    => ts_get($tid, 'code_support_on', '1') === '1',
    ];
}

/* ---------- GET /api/visitor/config ---------- */
function handle_visitor_config(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = resolve_visitor_context();
    if (!$ctx) {
        // v2.65 策略：无标识/废码访问一律拒绝连接（不展示引导文案，不泄露任何状态差异）
        $hadId = input('code', '') !== '' || input('t', '') !== '';
        json_out(['available' => false,
            'unavailable_reason' => '拒绝连接',
            'neutral' => !$hadId]);
        return;
    }
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

    // ===== 访客过滤（对标：非微信/非移动/非国内） =====
    $uaStr = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (ts_get($tid, 'filtration_wx', '0') === '1' && !str_contains($uaStr, 'MicroMessenger')) {
        json_error('请使用微信访问本页面', 403);
    }
    if (ts_get($tid, 'filtration_pc', '0') === '1' && !preg_match('/Mobile|Android|iPhone|iPad/i', $uaStr)) {
        json_error('请使用手机访问本页面', 403);
    }
    if (ts_get($tid, 'filtration_area', '0') === '1') {
        $geo = lookup_geo($ip);
        if ($geo !== '') {
            $parts = explode('|', $geo);
            $cc = trim($parts[4] ?? '');
            if ($cc !== '' && strtoupper($cc) !== 'CN') {
                json_error('当前地区暂不提供服务', 403);
            }
        }
    }

    $pdo = db();
    $now = now_dt();
    $visitorToken = trim((string)input('visitor_token', ''));

    // ① 复用该租户下最近未关闭会话；开启「访客去重」时 24 小时内的已关闭会话也复用（重开，不占新额度）
    if ($visitorToken !== '') {
        $dedup = ts_get($tid, 'dedup_on', '0') === '1';
        if ($dedup) {
            $st = $pdo->prepare("SELECT * FROM v_sessions WHERE tenant_id = ? AND deleted_at IS NULL AND visitor_token = ? AND (status != 'closed' OR last_time >= ?) ORDER BY last_time DESC LIMIT 1");
            $st->execute([$tid, $visitorToken, now_dt(time() - 86400)]);
        } else {
            $st = $pdo->prepare("SELECT * FROM v_sessions WHERE tenant_id = ? AND deleted_at IS NULL AND visitor_token = ? AND status != 'closed' ORDER BY last_time DESC LIMIT 1");
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
                'visitor_avatar' => visitor_avatar_resolve($sid, (string)($old['visitor_avatar'] ?? '')),
                'session' => visitor_session_out($old),
            ]);
        }
    }

    // ②a 验证策略（仅新建会话时校验；first=有历史会话的复用已在前面的复用分支处理）
    $robot = ts_get($tid, 'robot', 'no');
    if ($robot !== 'no') {
        $freq = ts_get($tid, 'robot_frequency', 'first');
        $needVerify = true;
        if ($freq === 'first' && $visitorToken !== '') {
            // 有 token 且 24h 内有历史会话 → 视为老访客，跳过
            $hist = fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions WHERE tenant_id = ? AND deleted_at IS NULL AND visitor_token = ? AND last_time >= ?',
                [$tid, $visitorToken, now_dt(time() - 86400)]);
            if ($hist > 0) $needVerify = false;
        }
        if ($needVerify) {
            if ($robot === 'invite' || $robot === 'password') {
                $code = ts_get($tid, 'robot_code', '');
                $vv = trim((string)input('verify_value', ''));
                if ($code === '' || $vv !== $code) json_error($robot === 'invite' ? '邀请码错误' : '密码错误', 403);
            } else {
                // click/slide/code 前端完成交互后标记
                if (!input('verify_passed')) json_error('请先完成验证', 403);
            }
        }
    }

    // ②b 新建会话：IP 频率限制（每分钟新建会话超过 N 个自动拉黑）
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
    // 会话总数额度（v2.25）：0=不限；到顶后新访客被拒（老访客恢复会话不受影响）
    $maxSessions = (int)($t['max_sessions'] ?? 0);
    if ($maxSessions > 0) {
        $total = fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions WHERE tenant_id = ? AND deleted_at IS NULL', [$tid]);
        if ($total >= $maxSessions) json_error('当前咨询人数较多，请稍后再试', 403);
    }

    // 访客名：自定义 or 自动编号
    $rawName = mb_substr(trim((string)input('name', '')), 0, 30);
    $isAuto = $rawName === '' || (bool)preg_match('/^\d{1,2}-\d{1,2} \d{4}$/', $rawName);
    $name = $isAuto ? gen_visitor_name($tid) : $rawName;
    $phone = mb_substr(trim((string)input('phone', '')), 0, 50);
    $question = mb_substr(trim((string)input('question', '')), 0, 500);
    $fromUrl = mb_substr((string)input('from_url', '直接访问'), 0, 255) ?: '直接访问';
    if ($visitorToken === '') $visitorToken = 'vt_' . bin2hex(random_bytes(8));

    // 访客头像/昵称策略
    $headType = ts_get($tid, 'user_head_type', 'default');
    $visitorAvatar = '';
    if ($headType === 'custom') {
        $visitorAvatar = ts_get($tid, 'user_head', '');
    }
    // 默认 / 随机：分配 emoji 头像（动物优先，不够用车/船，再不够用其它），并尽量避开"当天其他访客已用过的"
    if (trim($visitorAvatar) === '') $visitorAvatar = pick_visitor_avatar($pdo, $tid);
    // 访客名：① 随机昵称 → 随机词 + 序号；② 默认（自动） → 跟头像对应（🐼→熊猫、🐘→大象、🌳→大树）
    if (ts_get($tid, 'user_name_type', 'default') === 'random') {
        $pool2 = ['小太阳','软糖','布丁','星星','月月','豆豆','团子','奶盖','咕咕','泡泡','奶茶','西柚','柚子','软软','阿玛','小鹿','桃桃','雪碧','布偶','汽水'];
        $name = $pool2[random_int(0, count($pool2) - 1)] . random_int(10, 99);
    } elseif ($isAuto) {
        $nick = visitor_avatar_name($visitorAvatar);
        if ($nick !== '') $name = $nick;
    }

    $firstMsg = $question !== '' ? $question : '等待接入...';
    // v2.60.9 建会话时就生成「恢复码」，客服随时能复制给客户（换设备后接回原对话）
    $resumeCode = resume_code_new();
    $pdo->prepare('INSERT INTO v_sessions (tenant_id, agent_id, visitor_name, visitor_token, ip, from_url, last_msg, last_time, unread, status, source, quota_key, last_online_at, ua, visitor_avatar, resume_code, code) VALUES (?,?,?,?,?,?,?,?,0,\'online\',?,?,?,?,?,?,?)')
        ->execute([$tid, $ctx['agent_id'], $name, $visitorToken, $ip, $fromUrl, $firstMsg, $now,
            $code !== '' ? 'app' : 'web', $quotaKey, $now, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), $visitorAvatar, $resumeCode, $code]);
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
    $sessRow = $sess->fetch();
    json_out([
        'session_id' => $sid,
        'visitor_token' => $visitorToken,
        'visitor_avatar' => $visitorAvatar,
        'visitor_name' => $name,
        'reused' => false,
        'session' => visitor_session_out($sessRow),
    ]);
}

function visitor_session_out(array $s): array
{
    return [
        'id' => (int)$s['id'], 'name' => $s['visitor_name'], 'ip' => $s['ip'],
        'from' => $s['from_url'], 'lastMsg' => $s['last_msg'], 'lastTime' => dt_to_iso($s['last_time']),
        'status' => $s['status'],
        'avatar' => visitor_avatar_resolve((int)$s['id'], (string)($s['visitor_avatar'] ?? '')),
    ];
}

/* ---------- 访客头像：动物 emoji 池（visitor_avatar_pool 在 helpers.php）+ 同天尽量不重复分配 ---------- */

/**
 * 给新访客分配头像：优先挑「今天还没被其他访客用过」的动物 emoji；
 * 池子用完了就挑当天用得最少的那个（保证仍然尽量分散）。
 */
function pick_visitor_avatar(PDO $pdo, int $tenantId): string
{
    $tiers = visitor_avatar_tiers();
    $used = [];
    try {
        $st = $pdo->prepare('SELECT visitor_avatar, COUNT(*) AS c FROM v_sessions
            WHERE tenant_id = ? AND visitor_avatar <> ? AND created_at >= CURDATE() GROUP BY visitor_avatar');
        $st->execute([$tenantId, '']);
        foreach ($st->fetchAll() as $r) $used[(string)$r['visitor_avatar']] = (int)$r['c'];
    } catch (\Throwable $e) { /* 统计失败不影响分配 */ }

    // 按层优先：先用没被用过的动物，动物用完了再拿车船，最后才用其它 emoji
    foreach ($tiers as $tier) {
        $free = [];
        foreach (array_keys($tier) as $e) { if (!isset($used[$e])) $free[] = $e; }
        if ($free) return $free[random_int(0, count($free) - 1)];
    }

    // 三层都用完了：挑当天用得最少的（尽量不用同一个）
    $best = ''; $min = PHP_INT_MAX;
    foreach ($tiers as $tier) {
        foreach (array_keys($tier) as $e) {
            $c = $used[$e] ?? 0;
            if ($c < $min) { $min = $c; $best = $e; }
        }
    }
    return $best;
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
    // v2.73 欢迎语算「我们发出去的」：from_type 用 agent，工作台里显示在右侧（以前是 system 会跑到访客那边）
    $insM = $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, attachments, created_at) VALUES (?,?,'agent',?,?,?,?,?)");
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
    $sql = 'SELECT id, session_id, from_type AS `from`, sender_name, text, msg_type, attachments, reply_to, recalled_at, edited_at, read_at, created_at FROM messages WHERE session_id = ?' . ($since > 0 ? ' AND id > ?' : '') . ' ORDER BY id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($since > 0 ? [$sid, $since] : [$sid]);
    // 访客自己的消息带上「客服是否已读」回执（read_at 由客服端打开会话时写入）
    $rows = $st->fetchAll();
    $msgs = array_map(function ($r) {
        $m = to_msg($r);
        if (($m['from'] ?? '') === 'visitor') $m['read'] = !empty($r['read_at']);
        return $m;
    }, $rows);
    json_out(['messages' => $msgs]);
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
    /* v2.67 会话绑定：轮询（含轻量探针）必须携带与会话匹配的 visitor_token，防枚举偷聊天记录 */
    $vt = trim((string)input('visitor_token', ''));
    $stTok = db()->prepare('SELECT visitor_token FROM v_sessions WHERE id = ?');
    $stTok->execute([$sid]);
    $sessTok = (string)($stTok->fetchColumn() ?: '');
    if ($sessTok !== '' && $vt !== $sessTok) json_error('会话已失效', 403);
    // 轻量探针（访客闲置/暂停期专用）：只答"有无新消息"，一条索引查询；有新消息前端立即恢复全量轮询
    if (input('light')) {
        if (!$sid) json_out(['new' => false]);
        presence_touch('v:' . $sid);
        $st = db()->prepare('SELECT COUNT(*) FROM messages WHERE session_id = ? AND id > ?');
        $st->execute([$sid, $sinceMsg]);
        json_out(['new' => ((int)$st->fetchColumn()) > 0]);
    }
    if (!$sid) json_out(['messages' => [], 'now' => time()]);
    $pdo = db();
    presence_touch('v:' . $sid);   // 在线状态随请求刷新；停活由总控清理删除，内存只留在线的
    // 心跳：仅更新本人会话
    $pdo->prepare('UPDATE v_sessions SET last_online_at = ? WHERE id = ?')->execute([now_dt(), $sid]);
    // 已读回执：访客在线即视为已读客服消息
    $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE session_id = ? AND from_type = 'agent' AND read_at IS NULL")->execute([$sid]);
    // 撤回同步：最近 10 分钟内被撤回的消息 id（前端据此把本地消息标记为已撤回）
    $rc = $pdo->prepare('SELECT id FROM messages WHERE session_id = ? AND recalled_at IS NOT NULL AND recalled_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $rc->execute([$sid]);
    $recalledIds = array_map('intval', $rc->fetchAll(PDO::FETCH_COLUMN));
    // v2.56 编辑同步：最近 10 分钟被编辑过的消息（id + 新文字）
    $ed = $pdo->prepare('SELECT id, text FROM messages WHERE session_id = ? AND edited_at IS NOT NULL AND edited_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $ed->execute([$sid]);
    $editedMsgs = array_map(fn($r) => ['id' => (int)$r['id'], 'text' => (string)$r['text']], $ed->fetchAll());
    // 接待客服头像：仅认图片头像（http// 或 /uploads 开头）；表情占位视为未设置，继续回退；
    // 租户开关 agent_avatar_on 关闭时直接使用品牌头像
    $agentAvatar = '';
    $revoked = false;
    $ss = $pdo->prepare('SELECT tenant_id, agent_id, status, code FROM v_sessions WHERE id = ?');
    $ss->execute([$sid]);
    $sessStatus = 'online';
    if ($sess = $ss->fetch()) {
        $sessStatus = (string)($sess['status'] ?? 'online');
        $tid2 = (int)$sess['tenant_id'];
        /* v2.65 废码自动断开：会话挂着的客服码被换掉/删除、或租户停用/到期 → 告知前端断开。
           每次全量轮询都查（两条索引小查询），轻量探针不查。 */
        $sessCode = trim((string)($sess['code'] ?? ''));
        if ($sessCode !== '') {
            $ok1 = (bool)db()->query('SELECT 1 FROM service_codes WHERE code = ' . db()->quote($sessCode) . ' LIMIT 1')->fetchColumn();
            $t1 = $pdo->prepare('SELECT status, expires_at FROM tenants WHERE id = ?');
            $t1->execute([$tid2]);
            $tRow = $t1->fetch();
            $ok2 = $tRow && ($tRow['status'] ?? '') === 'active'
                && (empty($tRow['expires_at']) || strtotime((string)$tRow['expires_at']) >= time());
            if (!$ok1 || !$ok2) $revoked = true;
        }
        $isImgUrl = function ($v) {
            return is_string($v) && (strpos($v, 'http://') === 0 || strpos($v, 'https://') === 0 || strpos($v, '/uploads') === 0);
        };
        if (ts_get($tid2, 'agent_avatar_on', '1') === '1') {
            if (!empty($sess['agent_id'])) {
                $a = $pdo->prepare('SELECT avatar FROM agents WHERE id = ? AND tenant_id = ?');
                $a->execute([(int)$sess['agent_id'], $tid2]);
                $av = trim((string)($a->fetchColumn() ?: ''));
                if ($isImgUrl($av)) $agentAvatar = $av;
            }
            if ($agentAvatar === '') {
                $av = trim((string)ts_get($tid2, 'me_avatar', ''));
                if ($isImgUrl($av)) $agentAvatar = $av;
            }
        }
        if ($agentAvatar === '') $agentAvatar = (string)ts_get($tid2, 'brand_avatar', '💬');
    }
    $st = $pdo->prepare('SELECT id, session_id, from_type AS `from`, sender_name, text, msg_type, attachments, reply_to, recalled_at, edited_at, read_at, created_at FROM messages WHERE session_id = ? AND id > ? ORDER BY id ASC');
    $st->execute([$sid, $sinceMsg]);
    $rows2 = $st->fetchAll();
    $msgs2 = array_map(function ($r) {
        $m = to_msg($r);
        if (($m['from'] ?? '') === 'visitor') $m['read'] = !empty($r['read_at']);
        return $m;
    }, $rows2);
    json_out(['messages' => $msgs2, 'recalledIds' => $recalledIds, 'editedMsgs' => $editedMsgs,
        'agent_avatar' => $agentAvatar, 'session_status' => $sessStatus, 'revoked' => $revoked, 'now' => time()]);
}
/* ---------- POST /api/visitor/send ---------- */
/* ---------- 防红中转（v2.59）：空壳页拿 key 换真实入口 ----------
   请求里只有客服码，不落任何业务内容；码无效/租户停用/已到期都给一句人话提示。 */
function handle_gate_check(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    /* v2.68：token 可能是「入口 ID」（二维码里印的 ?w=）也可能是老链接里的客服码（?key=），
       两者都收；解析顺序见 gate_resolve_token（先入口 ID，再退回客服码）。 */
    $key = trim((string)input('w', ''));
    if ($key === '') $key = trim((string)input('key', ''));
    if ($key === '' || !preg_match('/^[A-Za-z0-9]{4,64}$/', $key)) {
        json_out(['ok' => false, 'msg' => '链接已失效，请重新扫码进入']);
        return;
    }
    /* v2.65 浏览器证明 + 环境校验：pf 由壳页内层在真实浏览器里算出（djb2(key|年|kfxt)），
       不执行 JS 的脚本刷接口拿不到；UA 不像浏览器的一律拒绝。失败统一话术，不泄露校验细节。
       v2.68：失败话术从「拒绝连接」改成「链接已失效，请重新扫码进入」——壳页会先重试、
       再自动换备用域名，都不行才把这句话显示给访客（不再跳百度 / 不再断连接）。 */
    $pf = trim((string)input('pf', ''));
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($pf === '' || !hash_equals(gate_proof($key), $pf)
        || $ua === '' || !str_contains($ua, 'Mozilla')) {
        json_out(['ok' => false, 'msg' => '链接已失效，请重新扫码进入']);
        return;
    }
    $hit = gate_resolve_token($key);
    if (!$hit) { json_out(['ok' => false, 'msg' => '链接已失效，请重新扫码进入']); return; }
    /* v2.68：真实聊天页地址由总控下发 —— 内容域名（域名池里挑）+ 伪装静态路径。
       二维码里那个入口域名跟内容域名解耦：域名红了、换码了，顾客手里那张二维码都还能用。 */
    /* v2.71 内容页凭票进入：下发票据给壳页，内容页 5 分钟内凭票访问（防扒链接重复用） */
    json_out(['ok' => true, 'code' => $hit['code'], 'url' => gate_content_url($hit['code']),
        'tk' => gate_token_issue($hit['code'])]);
}

/* ---------- v2.60.9 访客「恢复原对话」 ----------
   换手机 / 换浏览器 / 清了缓存后，浏览器里的 visitor_token 没了，访客会被当成新访客。
   输入客服给的 8 位恢复码 → 找到那段未结束的会话 → 把它的 visitor_token 还给前端
   （前端存进 localStorage），之后访客看到的就还是原来那段对话。
   安全：只对未结束的会话生效；同一 IP 10 分钟最多尝试 20 次。 */
function handle_visitor_resume(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $key = trim((string)input('code', ''));
    $rc = strtolower(trim((string)input('resume', '')));
    if ($rc === '') { json_out(['ok' => false, 'msg' => '请输入恢复码']); return; }
    if (!preg_match('/^[a-z0-9]{4,16}$/', $rc)) { json_out(['ok' => false, 'msg' => '恢复码格式不对']); return; }

    // 限频：同 IP 10 分钟内最多 20 次，防爆破
    $lk = 'resume_try:' . md5(get_client_ip());
    $raw = kv_get($lk, '');
    $nowTs = time(); $cnt = 0; $start = $nowTs;
    if ($raw !== '') {
        $parts = explode(':', $raw);
        if (count($parts) === 2 && $nowTs - (int)$parts[1] < 600) { $cnt = (int)$parts[0]; $start = (int)$parts[1]; }
    }
    $cnt++;
    kv_set($lk, $cnt . ':' . $start);
    if ($cnt > 20) { json_out(['ok' => false, 'msg' => '尝试次数太多了，请稍后再试']); return; }

    $pdo = db();
    $tid = 0;
    if ($key !== '') {
        $st = $pdo->prepare('SELECT tenant_id FROM service_codes WHERE code = ? LIMIT 1');
        $st->execute([$key]);
        $tid = (int)($st->fetchColumn() ?: 0);
    }
    if ($tid > 0) {
        $st = $pdo->prepare("SELECT * FROM v_sessions WHERE tenant_id = ? AND deleted_at IS NULL AND resume_code = ? AND status <> 'closed' ORDER BY last_time DESC LIMIT 1");
        $st->execute([$tid, $rc]);
    } else {
        $st = $pdo->prepare("SELECT * FROM v_sessions WHERE deleted_at IS NULL AND resume_code = ? AND status <> 'closed' ORDER BY last_time DESC LIMIT 1");
        $st->execute([$rc]);
    }
    $row = $st->fetch();
    if (!$row) { json_out(['ok' => false, 'msg' => '恢复码不对，或者这段对话已经结束了']); return; }

    // 接回来的会话回到在线列表（原来是归档状态的话），并把心跳刷新
    if (($row['status'] ?? '') === 'archived') {
        $pdo->prepare("UPDATE v_sessions SET status = 'online', last_online_at = ? WHERE id = ?")->execute([now_dt(), (int)$row['id']]);
    } else {
        $pdo->prepare('UPDATE v_sessions SET last_online_at = ? WHERE id = ?')->execute([now_dt(), (int)$row['id']]);
    }
    json_out([
        'ok' => true,
        'session_id' => (int)$row['id'],
        'visitor_token' => (string)$row['visitor_token'],
        'session' => visitor_session_out($row),
    ]);
}

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
    /* v2.67 会话绑定：发送必须携带与会话匹配的 visitor_token（防冒用会话发消息） */
    if ((string)($s['visitor_token'] ?? '') !== '' && trim((string)input('visitor_token', '')) !== (string)$s['visitor_token']) {
        json_error('会话已失效', 403);
    }
    $tid = (int)$s['tenant_id'];
    $ip = $s['ip'] ?: get_client_ip();
    if (is_ip_blocked($tid, $ip)) json_error('您的访问受限', 403);
    if ($s['status'] === 'closed') json_error('会话已结束', 403);
    // 归档会话有新消息 → 自动取消归档（活跃对话回到在线列表，带未读提示）
    if ($s['status'] === 'archived') {
        $pdo->prepare("UPDATE v_sessions SET status = 'online', archived_at = NULL WHERE id = ?")->execute([$sid]);
        $s['status'] = 'online';
    }

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
    // v2.43 引用回复
    $replyTo = (int)input('reply_to', 0);
    if ($replyTo > 0) {
        $chk = $pdo->prepare('SELECT id FROM messages WHERE id = ? AND session_id = ?');
        $chk->execute([$replyTo, $sid]);
        if (!$chk->fetchColumn()) $replyTo = 0;
    }
    $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, text, msg_type, attachments, reply_to, created_at) VALUES (?,?,'visitor',?,?,?,?,?)")
        ->execute([$tid, $sid, $text, $msgType, $attachJson, $replyTo, $now]);
    $mid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE v_sessions SET last_msg = ?, last_time = ?, unread = unread + 1, last_online_at = ? WHERE id = ?')
        ->execute([msg_summary($text, $attachArr), $now, $now, $sid]);

    $msg = ['id' => $mid, 'from' => 'visitor', 'text' => $text, 'time' => dt_to_iso($now),
        'session_id' => $sid, 'msg_type' => $msgType, 'attachments' => $attachArr, 'reply_to' => $replyTo];

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

/* ---------- POST /api/visitor/recall（访客撤回自己 2 分钟内的消息） ---------- */
function handle_visitor_recall(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $sid = (int)input('session_id', 0);
    $msgId = (int)input('msg_id', 0);
    if (!$sid || !$msgId) json_error('参数缺失');
    $st = db()->prepare("SELECT * FROM messages WHERE id = ? AND session_id = ? AND from_type = 'visitor'");
    $st->execute([$msgId, $sid]);
    $msg = $st->fetch();
    if (!$msg) json_error('消息不存在', 400);
    if ($msg['recalled_at']) json_out(['ok' => true]);
    if (time() - strtotime($msg['created_at']) > 120) json_error('超过 2 分钟，无法撤回', 403);
    // 访客撤回：仅自己端隐藏，客服端保留内容并标记 recalled（需求）
    db()->prepare('UPDATE messages SET recalled_at = ? WHERE id = ?')->execute([now_dt(), $msgId]);
    json_out(['ok' => true, 'msg_id' => $msgId]);
}
