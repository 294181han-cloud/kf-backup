<?php
/**
 * routes/admin.php - 平台管理员（租户管理 / 域名池 / 全局设置）
 */
declare(strict_types=1);

function require_admin_ctx(): array
{
    return require_role('admin');
}

/* ---------- 平台概览 ---------- */
function handle_admin_overview(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $pdo = db();
    $today = date('Y-m-d');

    $tenantsTotal = fetch_count($pdo, 'SELECT COUNT(*) FROM tenants');
    $tenantsActive = fetch_count($pdo, "SELECT COUNT(*) FROM tenants WHERE status = 'active' AND (expires_at IS NULL OR expires_at > NOW())");
    $tenantsExpired = fetch_count($pdo, 'SELECT COUNT(*) FROM tenants WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
    $agentsTotal = fetch_count($pdo, 'SELECT COUNT(*) FROM agents');
    $sessionsTotal = fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions');
    $sessionsToday = fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions WHERE date(last_time) = ?', [$today]);
    $messagesToday = fetch_count($pdo, 'SELECT COUNT(*) FROM messages WHERE date(created_at) = ?', [$today]);

    // 近 7 天全会话趋势
    $st = $pdo->prepare('SELECT date(created_at) day, COUNT(*) c FROM v_sessions WHERE date(created_at) >= ? GROUP BY day');
    $st->execute([date('Y-m-d', strtotime('-6 days'))]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[$r['day']] = (int)$r['c'];
    $trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trend[] = ['date' => substr($d, 5), 'count' => $map[$d] ?? 0];
    }

    // 租户活跃排行（近 7 天会话数）
    $ranking = $pdo->query('SELECT t.id, t.kami, t.nickname, COUNT(s.id) c
        FROM tenants t LEFT JOIN v_sessions s ON s.tenant_id = t.id AND s.last_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY t.id ORDER BY c DESC, t.id ASC LIMIT 10')->fetchAll();

    json_out([
        'tenants_total' => $tenantsTotal, 'tenants_active' => $tenantsActive, 'tenants_expired' => $tenantsExpired,
        'agents_total' => $agentsTotal, 'sessions_total' => $sessionsTotal,
        'sessions_today' => $sessionsToday, 'messages_today' => $messagesToday,
        'trend7' => $trend, 'ranking' => $ranking,
        'root_domain' => kv_get('root_domain', ''),
    ]);
}

/* ---------- 租户管理 ---------- */

function tenant_out(array $t, ?int $sessions = null): array
{
    return tenant_fields($t, $sessions);
}

/** 这张卡是谁的（发卡机器人开的）：优先 @用户名，没有用户名就用 Telegram ID；
 *  更早的卡没记 tg_id，就从备注里把当年写进去的 @用户名 抠出来（老数据也能显示） */
function tenant_tg_user(array $t): string
{
    $u = trim((string)($t['tg_username'] ?? ''));
    if ($u !== '') return '@' . ltrim($u, '@');
    $id = (int)($t['tg_id'] ?? 0);
    if ($id > 0) return (string)$id;
    if (preg_match('/@([A-Za-z0-9_]{3,32})/', (string)($t['remark'] ?? ''), $m)) return '@' . $m[1];
    return '';
}

function tenant_fields(array $t, ?int $sessions = null): array
{
    return [
        'id' => (int)$t['id'], 'kami' => $t['kami'], 'nickname' => $t['nickname'], 'remark' => $t['remark'],
        'status' => $t['status'], 'expires_at' => $t['expires_at'],
        'quota_web' => (int)$t['quota_web'], 'quota_app' => (int)$t['quota_app'], 'max_agents' => (int)$t['max_agents'],
        'max_devices' => (int)($t['max_devices'] ?? 3),   // v2.60.10 登录设备上限（默认 3，最高 8）
        'closed_keep_days' => (int)($t['closed_keep_days'] ?? 3), 'max_sessions' => (int)($t['max_sessions'] ?? 0),
        'created_at' => $t['created_at'], 'last_login_at' => $t['last_login_at'],
        'expired' => $t['expires_at'] && strtotime($t['expires_at']) < time(),
        'sessions' => $sessions,
    ];
}

function handle_admin_tenants(string $method): void
{
    require_admin_ctx();
    $pdo = db();

    if ($method === 'GET') {
        // v2.72 带上买家的 Telegram 用户名（发卡机器人开出来的卡），没有用户名就用 ID
        $rows = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = t.id) sessions_cnt,
            (SELECT COUNT(*) FROM agents a WHERE a.tenant_id = t.id) agents_cnt,
            u.username AS tg_username, u.first_name AS tg_name
            FROM tenants t LEFT JOIN tg_users u ON u.tg_id = t.tg_id
            ORDER BY t.id DESC')->fetchAll();
        $out = array_map(function ($t) {
            $o = tenant_out($t, (int)$t['sessions_cnt']);
            $o['agents_cnt'] = (int)$t['agents_cnt'];
            $o['tg_id'] = (int)($t['tg_id'] ?? 0);
            $o['tg_user'] = tenant_tg_user($t);
            return $o;
        }, $rows);
        json_out($out);
    }

    if ($method === 'POST') {
        $data = body();
        $nickname = mb_substr(trim((string)($data['nickname'] ?? '客服')), 0, 64);
        $remark = mb_substr(trim((string)($data['remark'] ?? '')), 0, 255);

        $kami = trim((string)($data['kami'] ?? ''));
        if ($kami === '') $kami = gen_kami();
        else {
            if (!preg_match('/^[A-Za-z0-9]{4,32}$/', $kami)) json_error('卡密格式：4-32 位字母或数字');
            $dup = $pdo->prepare('SELECT 1 FROM tenants WHERE kami = ?');
            $dup->execute([$kami]);
            if ($dup->fetch()) json_error('卡密已存在，请更换');
        }

        $password = (string)($data['password'] ?? '');
        if ($password === '') $password = gen_password();
        elseif (strlen($password) < 6) json_error('密码至少 6 位');

        $quotaWeb = max(0, (int)($data['quota_web'] ?? kv_get('default_quota_web', '200')));
        $quotaApp = max(0, (int)($data['quota_app'] ?? kv_get('default_quota_app', '200')));
        $maxAgents = max(0, min(50, (int)($data['max_agents'] ?? kv_get('default_max_agents', '5'))));
        $closedKeepDays = max(0, min(3650, (int)($data['closed_keep_days'] ?? 3)));   // 已结束保留天数（0=永久）
        $maxSessions = max(0, (int)($data['max_sessions'] ?? 1000));                  // 会话总数额度（0=不限）
        $expires = null;
        if (!empty($data['expires_at'])) {
            $ts = strtotime((string)$data['expires_at']);
            if (!$ts) json_error('有效期格式不正确');
            $expires = date('Y-m-d H:i:s', $ts);
        }

        $pdo->prepare('INSERT INTO tenants (kami, password_hash, nickname, remark, expires_at, quota_web, quota_app, max_agents, closed_keep_days, max_sessions) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$kami, password_hash($password, PASSWORD_DEFAULT), $nickname, $remark, $expires, $quotaWeb, $quotaApp, $maxAgents, $closedKeepDays, $maxSessions]);
        $id = (int)$pdo->lastInsertId();

        json_out(['ok' => true,
            'tenant' => ['id' => $id, 'kami' => $kami, 'nickname' => $nickname],
            'plain' => ['kami' => $kami, 'password' => $password]], 201);
    }

    json_error('方法不允许', 405);
}

function handle_admin_tenants_gen(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    json_out(['kami' => gen_kami(), 'password' => gen_password()]);
}

function handle_admin_tenants_id(string $method, int $id): void
{
    require_admin_ctx();
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) json_error('租户不存在', 400);

    if ($method === 'GET') {
        $sessions = fetch_count($pdo, 'SELECT COUNT(*) FROM v_sessions WHERE tenant_id = ?', [$id]);
        json_out(tenant_out($t, $sessions));
    }

    if ($method === 'PUT') {
        $data = body();
        $set = []; $vals = [];
        foreach (['nickname', 'remark'] as $f) {
            if (array_key_exists($f, $data)) { $set[] = "$f = ?"; $vals[] = mb_substr((string)$data[$f], 0, 255); }
        }
        if (array_key_exists('status', $data)) {
            $v = in_array($data['status'], ['active', 'banned'], true) ? $data['status'] : 'active';
            $set[] = 'status = ?'; $vals[] = $v;
        }
        foreach (['quota_web', 'quota_app', 'max_agents', 'closed_keep_days', 'max_sessions'] as $f) {
            if (array_key_exists($f, $data)) { $set[] = "$f = ?"; $vals[] = max(0, min(3650, (int)$data[$f])); }
        }
        // v2.60.10 登录设备上限：默认 3，最高 8
        if (array_key_exists('max_devices', $data)) { $set[] = 'max_devices = ?'; $vals[] = max(1, min(8, (int)$data['max_devices'])); }
        if (array_key_exists('expires_at', $data)) {
            $ev = trim((string)$data['expires_at']);
            if ($ev === '') { $set[] = 'expires_at = ?'; $vals[] = null; }
            else {
                $ts = strtotime($ev);
                if (!$ts) json_error('有效期格式不正确');
                $set[] = 'expires_at = ?'; $vals[] = date('Y-m-d H:i:s', $ts);
            }
        }
        if (array_key_exists('password', $data) && (string)$data['password'] !== '') {
            if (strlen((string)$data['password']) < 6) json_error('密码至少 6 位');
            $set[] = 'password_hash = ?';
            $vals[] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
        }
        if ($set) {
            $vals[] = $id;
            $pdo->prepare('UPDATE tenants SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        }
        $st->execute([$id]);
        $t = $st->fetch();
        json_out(['ok' => true, 'tenant' => tenant_out($t)]);
    }

    if ($method === 'DELETE') {
        // 删除租户及全部关联数据
        $pdo->beginTransaction();
        try {
            foreach (['v_sessions', 'messages', 'contacts', 'quick_msgs', 'welcome_msgs', 'welcome_sent',
                      'auto_replies', 'broadcasts', 'ip_blacklist', 'pre_inquiries', 'tenant_settings',
                      'service_codes', 'domain_links', 'agents', 'login_devices'] as $tbl) {
                $pdo->prepare("DELETE FROM $tbl WHERE tenant_id = ?")->execute([$id]);
            }
            $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            json_error('删除失败：' . $e->getMessage(), 500);
        }
        json_out(['ok' => true]);
    }

    json_error('方法不允许', 405);
}

/* ---------- 域名池 ---------- */

function handle_admin_domain_pool(string $method): void
{
    require_admin_ctx();
    $pdo = db();

    if ($method === 'GET') {
        json_out($pdo->query('SELECT id, domain, wildcard, dtype, enabled, created_at FROM domain_pool ORDER BY id ASC')->fetchAll());
    }

    if ($method === 'POST') {
        $data = body();
        $raw = trim((string)($data['domain'] ?? ''));
        if ($raw === '') json_error('请输入域名');
        $wildcard = 0;
        if (str_starts_with($raw, '*.')) { $wildcard = 1; $raw = mb_substr($raw, 2); }
        $domain = strtolower(trim($raw, " \t\n\r\0\x0B./"));
        if ($domain === '' || str_contains($domain, ' ') || str_contains($domain, 'http') || str_contains($domain, '/')) {
            json_error('域名格式不正确，例如：aaa.com 或 *.bbb.com');
        }
        $enabled = (isset($data['enabled']) && (int)$data['enabled'] === 0) ? 0 : 1;
        try {
            $pdo->prepare('INSERT INTO domain_pool (domain, wildcard, enabled) VALUES (?,?,?)')->execute([$domain, $wildcard, $enabled]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) json_error('该域名已在池中');
            throw $e;
        }
        json_out(['ok' => true, 'domain' => ['id' => (int)$pdo->lastInsertId(), 'domain' => $domain, 'wildcard' => $wildcard, 'enabled' => $enabled]], 201);
    }

    json_error('方法不允许', 405);
}

function handle_admin_domain_pool_id(string $method, int $id): void
{
    require_admin_ctx();
    $pdo = db();
    $params = [];

    if ($method === 'DELETE') {
        $pdo->prepare('DELETE FROM domain_pool WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
    }
    if ($method === 'PUT') {
        $data = body();
        $set = [];
        if (array_key_exists('enabled', $data)) { $set[] = 'enabled = ?'; $params[] = $data['enabled'] ? 1 : 0; }
        if (array_key_exists('wildcard', $data)) { $set[] = 'wildcard = ?'; $params[] = $data['wildcard'] ? 1 : 0; }
        if ($set) { $params[] = $id; $pdo->prepare('UPDATE domain_pool SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params); }
        json_out(['ok' => true]);
    }
    json_error('方法不允许', 405);
}

/* ---------- aaPanel 快速导入（v2.61）----------
   用途：泛解析证书麻烦（真通配要 DNS 验证），改为「一批固定随机子域 + 主域」共用一张
   Let's Encrypt SAN 证书（一张最多 100 个域名，避开 LE 每周 50 张限额）。
   流程：连接测试 → 一个 PHP 站点（主域 + 子域别名，无数据库，幂等）→ SAN 证书（HTTP 验证）→
   子域列表写 kv_store pool_subs:<主域>（对接 v2.60.6 固定子域池：发码从列表随机挑，
   主域名本身永远不会出现在客服码链接里），主域行入池登记（wildcard=1, dtype=fixed）。
   面板凭证只在本次请求内使用，不落库；需先把总控 IP 加进面板 API 白名单。 */

/** 快速导入 · 连接测试：验证面板地址/密钥，顺带返回可用 PHP 版本供表单选择 */
function handle_admin_aa_test(string $method): void
{
    if ($method !== 'POST') json_error('方法不允许', 405);
    require_admin_ctx();
    $d = body();
    $panel = trim((string)($d['panel'] ?? ''));
    $key = trim((string)($d['api_key'] ?? ''));
    if (!preg_match('#^https?://#i', $panel)) json_error('面板地址要以 http(s):// 开头，例如 https://1.2.3.4:8888');
    if ($key === '') json_error('请填写面板 API 密钥');
    try {
        $j = aa_call($panel, $key, '/site?action=GetPHPVersion', [], 25);
    } catch (\Throwable $e) {
        json_error('连接失败：' . $e->getMessage());
    }
    json_out(['ok' => true, 'php' => aa_php_versions($j)]);
}

/** 快速导入 · 一键执行：建站 + 签证书 + 写域名池/子域池 */
function handle_admin_aa_import(string $method): void
{
    if ($method !== 'POST') json_error('方法不允许', 405);
    require_admin_ctx();
    $d = body();
    $panel = trim((string)($d['panel'] ?? ''));
    $key = trim((string)($d['api_key'] ?? ''));
    if (!preg_match('#^https?://#i', $panel)) json_error('面板地址要以 http(s):// 开头');
    if ($key === '') json_error('请填写面板 API 密钥');
    $main = strtolower(trim((string)($d['main_domain'] ?? ''), " \t\n\r\0\x0B./"));
    if ($main === '' || str_contains($main, '*') || str_contains($main, ' ') || str_contains($main, '/') || !str_contains($main, '.')) {
        json_error('主域名格式不正确，例如：moupz.cn（不带 *. 和协议）');
    }
    $count = min(100, max(1, (int)($d['sub_count'] ?? 64)));
    $subLen = min(12, max(4, (int)($d['sub_len'] ?? 8)));
    $phpv = preg_match('/^\d{1,2}$/', (string)($d['php_version'] ?? '84')) ? (string)$d['php_version'] : '84';

    $pdo = db();
    // ① 生成随机子域：避开现有池内域名和已登记的子域池
    $taken = [];
    foreach ($pdo->query('SELECT domain FROM domain_pool') as $r) $taken[(string)$r['domain']] = 1;
    foreach (explode(',', kv_get('pool_subs:' . $main, '')) as $s) {
        if ($s !== '') $taken[$s . '.' . $main] = 1;
    }
    $want = [];
    foreach ($taken as $dm => $_) {
        if (str_ends_with($dm, '.' . $main)) $want[] = substr($dm, 0, -(strlen($main) + 1));
    }
    $subs = gen_subdomains($count, $subLen, $want);
    if (count($subs) < $count) json_error('无法生成足够的随机子域（现有占用太多），请换主域名或缩短子域长度');
    $fqdns = array_map(fn($s) => $s . '.' . $main, $subs);

    // ② 一个站点：主域为主域名，子域全部做别名，同一网站目录（幂等：已存在则继续）
    $sitePath = '/www/wwwroot/' . $main;
    try {
        $add = aa_call($panel, $key, '/site?action=AddSite', [
            'webname' => json_encode(['domain' => $main, 'domainlist' => $fqdns, 'count' => 0]),
            'path' => $sitePath,
            'type_id' => 0,
            'type' => 'PHP',
            'php_version' => $phpv,
            'port' => 80,
            'ps' => 'kfxt-quick-import',
            'ftp' => 'false',
            'sql' => 'false',
        ], 90);
    } catch (\Throwable $e) {
        json_error('建站失败：' . $e->getMessage());
    }
    $siteMsg = (string)($add['msg'] ?? '');
    $siteExisted = empty($add['status']) && $siteMsg !== '';
    if (empty($add['status']) && !str_contains($siteMsg, '已存在') && !str_contains($siteMsg, 'exist')) {
        json_error('建站失败：' . ($siteMsg !== '' ? $siteMsg : json_encode($add, JSON_UNESCAPED_UNICODE)));
    }

    // ③ 一张 Let's Encrypt SAN 证书覆盖 主域+全部子域（HTTP 验证，DNS 提前解析好即一次通过）。
    //    失败不回滚站点和子域池——去面板确认解析后点「重新导入」即可补签（建站是幂等的）。
    $certOk = false;
    $certMsg = '';
    try {
        $cert = aa_call($panel, $key, '/acme?action=create_certificate', [
            'domains' => json_encode(array_merge([$main], $fqdns)),
            'auth_type' => 'http',
            'auto_wildcard' => 0,
        ], 180);
        $certOk = !empty($cert['status']);
        if (!$certOk) $certMsg = (string)($cert['msg'] ?? json_encode($cert, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        $certMsg = $e->getMessage();
    }

    // ④ 子域池（v2.60.6 发码机制）+ 主域行入池登记（dtype=fixed，发码只从子域挑，主域不发链接）
    kv_set('pool_subs:' . $main, implode(',', $subs));
    $pdo->prepare('INSERT INTO domain_pool (domain, wildcard, dtype, enabled) VALUES (?,1,?,1)
                   ON DUPLICATE KEY UPDATE wildcard = 1, dtype = VALUES(dtype), enabled = 1')
        ->execute([$main, 'fixed']);

    json_out(['ok' => true, 'main' => $main, 'subs' => $subs, 'site_path' => $sitePath,
        'site_existed' => $siteExisted, 'cert_ok' => $certOk, 'cert_msg' => $certMsg]);
}

/* ---------- 主域名 / 全局默认 ---------- */

function handle_admin_root_domain(string $method): void
{
    require_admin_ctx();
    if ($method === 'GET') {
        json_out(['root_domain' => kv_get('root_domain', '')]);
    }
    if ($method === 'POST') {
        $d = strtolower(trim((string)input('root_domain', ''), " \t\n\r\0\x0B./"));
        if ($d === '') {
            kv_set('root_domain', ''); // 允许清空
            json_out(['ok' => true, 'root_domain' => '']);
        }
        if (str_contains($d, ' ') || str_contains($d, 'http') || str_contains($d, '/')) {
            json_error('主域名格式不正确，例如：abc.com');
        }
        kv_set('root_domain', $d);
        json_out(['ok' => true, 'root_domain' => $d]);
    }
    json_error('方法不允许', 405);
}

function handle_admin_defaults(string $method): void
{
    require_admin_ctx();
    if ($method === 'GET') {
        json_out([
            'default_quota_web' => (int)kv_get('default_quota_web', '200'),
            'default_quota_app' => (int)kv_get('default_quota_app', '200'),
            'default_max_agents' => (int)kv_get('default_max_agents', '5'),
        ]);
    }
    if ($method === 'POST') {
        kv_set('default_quota_web', (string)max(0, (int)input('default_quota_web', 200)));
        kv_set('default_quota_app', (string)max(0, (int)input('default_quota_app', 200)));
        kv_set('default_max_agents', (string)max(0, min(50, (int)input('default_max_agents', 5))));
        json_out(['ok' => true]);
    }
    json_error('方法不允许', 405);
}

/* ================= 微信拦截检测（v2.47：米人免费接口 / 原付费接口可切换） ================= */
function handle_admin_wxcheck(string $method): void
{
    require_admin_ctx();
    $cfg = wxcheck_cfg();

    if ($method === 'GET') {
        json_out([
            'on' => $cfg['on'], 'provider' => $cfg['provider'],
            'user' => $cfg['user'], 'key_set' => $cfg['key'] !== '',
            'mir6' => $cfg['mir6'], 'uouin' => $cfg['url'],
        ]);
    }
    if ($method === 'POST') {
        // 测试：给一个链接直接返回检测结果（不落库、不动租户状态）
        if ((string)input('test', '') !== '') {
            $link = trim((string)input('link', ''));
            if ($link === '' || !preg_match('#^https?://#i', $link)) json_error('请填完整链接，要带 http:// 或 https://');
            json_out(['ok' => true, 'result' => wxcheck_run($link, (string)input('channel', 'wx'))]);
        }
        if (input('on') !== null) kv_set('wxcheck_on', ((int)input('on') === 1) ? '1' : '0');
        if (input('provider') !== null) {
            $p = (string)input('provider');
            kv_set('wxcheck_provider', $p === 'uouin' ? 'uouin' : 'mir6');
        }
        if (input('user') !== null) kv_set('wxcheck_user', trim((string)input('user')));
        if (input('key') !== null && (string)input('key') !== '') kv_set('wxcheck_key', trim((string)input('key')));
        if (input('mir6') !== null && trim((string)input('mir6')) !== '') kv_set('wxcheck_mir6_url', trim((string)input('mir6')));
        if (input('uouin') !== null && trim((string)input('uouin')) !== '') kv_set('wxcheck_url', rtrim(trim((string)input('uouin')), '/'));
        json_out(['ok' => true]);
    }
    json_error('方法不允许', 405);
}

/* ================= 节点管理（1+2+n 分域部署，v2.33） ================= */

/** 主站向指定分控节点发起 HTTP 调用（curl 优先，stream 兜底） */
function admin_node_http(string $origin, string $path, array $body): array
{
    $cfg = config();
    $url = rtrim($origin, '/') . $path;
    $body['secret'] = (string)($cfg['relay_secret'] ?? '');
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    $resp = '';
    $code = 0;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 60, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m2)) $code = (int)$m2[1];
        }
        if ($resp === false) $err = '网络请求失败';
    }
    $j = json_decode((string)$resp, true);
    if ($code !== 200 || !is_array($j)) {
        return ['ok' => false, 'error' => '节点无响应(' . $code . ') ' . $err];
    }
    return $j;
}

function handle_admin_nodes(string $method): void
{
    require_admin_ctx();
    $pdo = db();

    if ($method === 'GET') {
        $rows = $pdo->query('SELECT ip, role, app_version, origin, first_seen, last_seen FROM nodes ORDER BY last_seen DESC, role ASC')->fetchAll();
        foreach ($rows as &$r) {
            $r['first_seen'] = dt_to_iso($r['first_seen']);
            $r['last_seen'] = dt_to_iso($r['last_seen']);
            $r['stale'] = $r['app_version'] !== '' && $r['app_version'] !== app_version();
        }
        unset($r);
        json_out(['main_version' => app_version(), 'nodes' => $rows]);
    }

    // 推送更新：主控打包代码 → 节点 /api/internal/apply 应用（无需 git/shell）
    if ($method === 'POST' && (input('action', '') === 'push')) {
        $ip = trim((string)input('ip', ''));
        $role = trim((string)input('role', ''));
        $st = $pdo->prepare('SELECT ip, role, origin, app_version FROM nodes WHERE ip = ? AND role = ?');
        $st->execute([$ip, $role]);
        $node = $st->fetch();
        if (!$node || (string)$node['origin'] === '') json_error('节点不存在或未上报来源地址', 400);
        $bundle = node_bundle_build();
        if ((string)$node['app_version'] === (string)$bundle['version']) {
            json_out(['ok' => true, 'up_to_date' => true, 'ip' => $ip, 'role' => $role, 'version' => $bundle['version']]);
        }
        $r = admin_node_http((string)$node['origin'], '/api/internal/apply', ['bundle' => $bundle]);
        if (!empty($r['ok']) && isset($r['app_version'])) {
            $pdo->prepare('UPDATE nodes SET app_version = ? WHERE ip = ? AND role = ?')->execute([(string)$r['app_version'], $ip, $role]);
        }
        json_out($r + ['ip' => $ip, 'role' => $role]);
    }

    // 忽略（删除）节点登记
    if ($method === 'POST' && (input('action', '') === 'forget')) {
        $ip = trim((string)input('ip', ''));
        $role = trim((string)input('role', ''));
        $pdo->prepare('DELETE FROM nodes WHERE ip = ? AND role = ?')->execute([$ip, $role]);
        json_out(['ok' => true]);
    }

    json_error('方法不允许', 405);
}

/* ---- 节点授权码（一次性，分节点网页安装器用，v2.34） ---- */
function handle_admin_node_codes(string $method): void
{
    require_admin_ctx();
    $pdo = db();
    if ($method === 'GET') {
        $rows = $pdo->query('SELECT code, role, created_at, used_at, used_ip FROM node_auth_codes ORDER BY created_at DESC LIMIT 50')->fetchAll();
        foreach ($rows as &$r) {
            $r['created_at'] = dt_to_iso($r['created_at']);
            $r['used_at'] = $r['used_at'] ? dt_to_iso($r['used_at']) : null;
        }
        unset($r);
        json_out(['codes' => $rows]);
    }
    if ($method === 'POST') {
        $action = (string)input('action', 'gen');
        if ($action === 'gen') {
            $role = in_array((string)input('role', ''), ['agent', 'visitor'], true) ? (string)input('role', '') : '';
            $code = 'N' . strtoupper(bin2hex(random_bytes(8)));
            $pdo->prepare('INSERT INTO node_auth_codes (code, role) VALUES (?,?)')->execute([$code, $role]);
            json_out(['ok' => true, 'code' => $code, 'role' => $role], 201);
        }
        if ($action === 'revoke') {
            $code = strtoupper(trim((string)input('code', '')));
            $pdo->prepare('DELETE FROM node_auth_codes WHERE code = ? AND used_ip IS NULL')->execute([$code]);
            json_out(['ok' => true]);
        }
    }
    json_error('方法不允许', 405);
}

/* ---- 全局黑名单（平台级，边缘节点本地拦截，v2.34） ---- */
function handle_admin_gblacklist(string $method): void
{
    require_admin_ctx();
    $pdo = db();
    if ($method === 'GET') {
        json_out([
            'ips' => $pdo->query('SELECT ip, note, created_at FROM global_blacklist ORDER BY created_at DESC LIMIT 500')->fetchAll(),
            'version' => (int)kv_get('bl_version', '1'),
        ]);
    }
    if ($method === 'POST') {
        $ip = trim((string)input('ip', ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) json_error('IP 格式不正确');
        $note = mb_substr(trim((string)input('note', '')), 0, 200);
        $pdo->prepare('INSERT IGNORE INTO global_blacklist (ip, note) VALUES (?,?)')->execute([$ip, $note]);
        bl_touch();
        json_out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        $ip = trim((string)input('ip', ''));
        $pdo->prepare('DELETE FROM global_blacklist WHERE ip = ?')->execute([$ip]);
        bl_touch();
        json_out(['ok' => true]);
    }
    json_error('方法不允许', 405);
}

/* ---- 分控节点安装器下载（bootstrap.php 单文件，v2.38） ---- */
function handle_admin_bootstrap_dl(string $method): void
{
    require_admin_ctx();
    $path = __DIR__ . '/bootstrap.php';
    if (!is_file($path)) json_error('安装器文件不存在', 404);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="bootstrap.php"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ---- 客服登录入口（卡密交付文案按 入口1、入口2… 展示，v2.35） ---- */
function handle_admin_agent_entrances(string $method): void
{
    require_admin_ctx();
    if ($method === 'GET') {
        json_out(['urls' => agent_entrances()]);
    }
    if ($method === 'PUT') {
        $urls = input('urls', []);
        if (!is_array($urls)) json_error('参数格式不正确');
        $clean = [];
        foreach ($urls as $u) {
            $u = trim((string)$u);
            if ($u !== '' && preg_match('#^https?://[a-z0-9.\-]+(:\d+)?#i', $u)) $clean[] = $u;
        }
        if (count($clean) > 5) $clean = array_slice($clean, 0, 5);
        kv_set('agent_entrances', json_encode($clean, JSON_UNESCAPED_UNICODE));
        json_out(['ok' => true, 'urls' => $clean]);
    }
    json_error('方法不允许', 405);
}
