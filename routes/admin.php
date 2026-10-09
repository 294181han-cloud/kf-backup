<?php
/**
 * routes/admin.php - 平台管理员（租户管理 / 域名池 / 全局设置）
 */
declare(strict_types=1);

function require_admin_ctx(): array
{
    return require_role('admin');
}

/** v2.90.10 批量发码：给某个租户在域名池里**每台域名各发一条客服码**（发完都在他的「管理码」里）。
 *  用途：客户手里的码在某台域名上红了、或者想给他一堆备用码时，一次铺满，不用一台台换绑再发。
 *  参数：skip_red=1（默认）跳过红名单上的域名；force=1 才会给已经发过码的域名再来一条。 */
function handle_admin_tenant_mint_codes(string $method, int $id): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $pdo = db();
    $st = $pdo->prepare('SELECT id, kami FROM tenants WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) json_error('租户不存在', 404);

    $skipRed = (string)input('skip_red', '1') === '1';
    $force   = (string)input('force', '0') === '1';
    /* v2.90.12 replace_all=1：先把这个账号现有的客服码全部删掉，只留这次发出来的（用户要求） */
    $replaceAll = (string)input('replace_all', '0') === '1';

    $doms = [];
    foreach ($pdo->query('SELECT domain FROM domain_pool WHERE enabled = 1 ORDER BY id ASC') as $r) {
        $d = strtolower(trim((string)$r['domain']));
        if ($d !== '') $doms[] = $d;
    }
    if (!$doms) json_error('域名池是空的，先在「域名池」里加域名');

    // 这个租户已经在哪几台域名上发过码（不重复铺）
    $have = [];
    if (!$force) {
        $q = $pdo->prepare('SELECT link FROM service_codes WHERE tenant_id = ?');
        $q->execute([$id]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $lk) {
            $h = strtolower((string)(parse_url((string)$lk, PHP_URL_HOST) ?: ''));
            if ($h !== '') $have[pool_domain_of($h)] = true;
        }
    }

    $created = []; $skippedRed = []; $already = []; $failed = [];
    $removed = 0;
    if ($replaceAll) {
        try {
            $del = $pdo->prepare('DELETE FROM service_codes WHERE tenant_id = ?');
            $del->execute([$id]);
            $removed = $del->rowCount();
            $have = [];   // 旧码已经清空，下面按域名重新铺
        } catch (\Throwable $e) { json_error('清空旧码失败：' . $e->getMessage(), 500); }
    }
    foreach ($doms as $d) {
        if ($skipRed && domain_in_red_list($d)) { $skippedRed[] = $d; continue; }
        if (!$force && !empty($have[$d])) { $already[] = $d; continue; }
        $r = tenant_mint_service_code($id, '', $d);
        if (!empty($r['ok'])) $created[] = ['domain' => $d, 'code' => $r['code']['code'], 'link' => $r['code']['link']];
        else $failed[] = ['domain' => $d, 'msg' => (string)($r['msg'] ?? '失败')];
    }
    json_out([
        'ok' => true,
        'tenant' => ['id' => (int)$t['id'], 'kami' => (string)$t['kami']],
        'removed' => $removed,
        'created' => $created, 'created_count' => count($created),
        'skip_red' => $skippedRed, 'already' => $already, 'failed' => $failed,
    ]);
}

/** v2.90.14 给租户指定「专属主机」（形如 f1kov5q6.hi6o7e.shop）：绑了之后这个账号的码都发这一台上；
 *  这颗子域会从域名池的随机池里摘掉（别人不会随机到），传空 host 就是解绑并还回池子。 */
function handle_admin_tenant_host(string $method, int $id): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $pdo = db();
    $st = $pdo->prepare('SELECT id, kami, content_host FROM tenants WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) json_error('租户不存在', 404);

    $host = strtolower(trim((string)input('host', '')));
    $host = preg_replace('#^https?://#', '', $host);
    $host = rtrim(explode('/', (string)$host)[0], '/');
    $old = strtolower(trim((string)($t['content_host'] ?? '')));

    // 解绑：把子域还回池子
    if ($host === '') {
        if ($old !== '') {
            $od = pool_domain_of($old);
            if ($od !== '') {
                $key = 'pool_subs:' . $od;
                $subs = array_values(array_filter(array_map('trim', explode(',', kv_get($key, '')))));
                $short = (str_ends_with($old, '.' . $od)) ? substr($old, 0, -strlen('.' . $od)) : '';
                if ($short !== '' && !in_array($short, $subs, true)) $subs[] = $short;
                kv_set($key, implode(',', $subs));
            }
        }
        $pdo->prepare('UPDATE tenants SET content_host = ? WHERE id = ?')->execute(['', $id]);
        json_out(['ok' => true, 'host' => '', 'msg' => '已解绑，子域还回域名池']);
    }

    if (!preg_match('/^[a-z0-9][a-z0-9.\-]{3,118}$/', $host)) json_error('主机名格式不对');
    $dom = pool_domain_of($host);
    $chk = $pdo->prepare('SELECT enabled FROM domain_pool WHERE domain = ? LIMIT 1');
    $chk->execute([$dom]);
    $en = $chk->fetchColumn();
    if ($en === false) json_error('这台主机所属的域名不在域名池里：' . $dom);
    if ((int)$en !== 1) json_error('这台主机所属的域名已停用：' . $dom);

    // 别人已经绑了同一台？
    $other = $pdo->prepare('SELECT kami FROM tenants WHERE content_host = ? AND id <> ? LIMIT 1');
    $other->execute([$host, $id]);
    $oc = (string)($other->fetchColumn() ?: '');
    if ($oc !== '') json_error('这台主机已经绑给别的账号（' . $oc . '）了');

    $pdo->prepare('UPDATE tenants SET content_host = ? WHERE id = ?')->execute([$host, $id]);
    /* 从随机池摘掉这颗子域 → 别的账号不会随机到（真「专属」）；同时把这个租户的专属域名也指到这台所属域名 */
    if ($host !== $dom) {
        $key = 'pool_subs:' . $dom;
        $subs = array_values(array_filter(array_map('trim', explode(',', kv_get($key, '')))));
        $short = substr($host, 0, -strlen('.' . $dom));
        if (in_array($short, $subs, true)) {
            $subs = array_values(array_diff($subs, [$short]));
            kv_set($key, implode(',', $subs));
        }
    } else {
        // 裸域绑走 = 这台域名的固定子域也不能再随机给别人 → 清空子域池
        kv_set('pool_subs:' . $dom, '');
    }
    try { $pdo->prepare('UPDATE tenants SET content_domain = ? WHERE id = ?')->execute([$dom, $id]); } catch (\Throwable $e) { }
    json_out(['ok' => true, 'host' => $host, 'domain' => $dom,
        'msg' => '已绑定：这个账号以后的码都发在 ' . $host . ' 上']);
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
        'content_domain' => (string)($t['content_domain'] ?? ''),   // v2.70 专属落地域名（自动分配）
        'content_host' => (string)($t['content_host'] ?? ''),       // v2.90.14 专属主机（可选，绑了码都发这台上）
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
        /* v2.90.14 每个租户的码实际落在哪些域名/主机上（跑过「批量发码」之后一眼能看出来） */
        $domOf = []; $hostOf = [];
        try {
            foreach ($pdo->query('SELECT tenant_id, link FROM service_codes') as $r2) {
                $h = strtolower((string)(parse_url((string)$r2['link'], PHP_URL_HOST) ?: ''));
                if ($h === '') continue;
                $tid2 = (int)$r2['tenant_id'];
                $hostOf[$tid2][$h] = true;
                $d2 = pool_domain_of($h);
                if ($d2 !== '') $domOf[$tid2][$d2] = true;
            }
        } catch (\Throwable $e) { /* 查不到就不显示 */ }
        $out = array_map(function ($t) use ($domOf, $hostOf) {
            $o = tenant_out($t, (int)$t['sessions_cnt']);
            $o['agents_cnt'] = (int)$t['agents_cnt'];
            $o['tg_id'] = (int)($t['tg_id'] ?? 0);
            $o['tg_user'] = tenant_tg_user($t);
            $tid3 = (int)$t['id'];
            $o['code_domains'] = array_keys($domOf[$tid3] ?? []);
            $o['code_hosts'] = array_keys($hostOf[$tid3] ?? []);
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
        /* v2.62.1 伪随机并入固定随机：两者存储逻辑相同（kv_store pool_subs:<域名>），
           有固定子域池的行统一标 dtype=fixed 并带子域明细，显示与发码实际行为一致 */
        $rows = $pdo->query('SELECT id, domain, wildcard, dtype, enabled, created_at FROM domain_pool ORDER BY id ASC')->fetchAll();
        $subsMap = [];
        foreach ($pdo->query("SELECT skey, sval FROM kv_store WHERE skey LIKE 'pool_subs:%'") as $kv) {
            $dom = strtolower(substr((string)$kv['skey'], strlen('pool_subs:')));
            if ($dom === '') continue;
            $list = array_values(array_filter(array_map('trim', explode(',', (string)$kv['sval']))));
            $subsMap[$dom] = $list;
        }
        foreach ($rows as &$r) {
            $list = $subsMap[strtolower((string)$r['domain'])] ?? [];
            $r['subs'] = $list;
            $r['subs_count'] = count($list);
            if ((string)$r['dtype'] === '' && $list) $r['dtype'] = 'fixed';
        }
        unset($r);
        json_out($rows);
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

/** v2.73 人工标记「微信里打不开」：检测接口（麒麟/urlsec）测不出来的拦截，由人工实扫判定。
 *  一键做完四件事：①停用池行 ②写红名单（发码/自动分配都会跳过）③该域名上的租户写红码提醒
 *  ④清掉这些租户的专属域名与内容主机（下次发码自动换到别的域名）。
 *  与自动退役的区别：这是"人工判红"，因为微信客户端侧的拦截 API 查不到。 */
function handle_admin_domain_pool_block(string $method, int $id): void
{
    require_admin_ctx();
    if ($method !== 'POST') json_error('方法不允许', 405);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM domain_pool WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) json_error('域名不存在', 404);
    $dom = strtolower((string)$row['domain']);
    $pdo->prepare('UPDATE domain_pool SET enabled = 0 WHERE id = ?')->execute([$id]);
    try {
        $pdo->prepare("INSERT IGNORE INTO red_domains (host, main, kind, code, channel) VALUES (?,?,?,?, 'wx')")
            ->execute([$dom, $dom, 'main', '']);
    } catch (\Throwable $e) { /* 名单写失败不影响停用 */ }
    $n = 0;
    try {
        $st2 = $pdo->prepare('SELECT tenant_id, code, link FROM service_codes WHERE link LIKE ?');
        $st2->execute(['%' . $dom . '%']);
        foreach ($st2->fetchAll() as $sc) {
            $pdo->prepare('INSERT INTO code_alerts (tenant_id, code, link, msg) VALUES (?,?,?,?)')
                ->execute([(int)$sc['tenant_id'], (string)$sc['code'], (string)$sc['link'],
                    '域名 ' . $dom . ' 在微信里已打不开（人工标记），请到「客服码」页点「换新链接」重新发给客户']);
            $n++;
        }
        $pdo->prepare('UPDATE tenants SET content_domain = \'\' WHERE content_domain = ?')->execute([$dom]);
        $pdo->prepare('UPDATE service_codes SET content_host = \'\' WHERE content_host LIKE ?')->execute(['%.' . $dom]);
    } catch (\Throwable $e) { /* 提醒写失败不影响停用 */ }
    json_out(['ok' => true, 'domain' => $dom, 'affected' => $n]);
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

/* ---------- 子域明细 · 状态表（v2.64）----------
   主域下每个子域的实时状态，优先级：红码 > 使用中 > 已作废 > 待分配。
   固定随机：待分配 = 固定子域池里还没被码占用的；
   泛解析：只显示出现过的（现役码占用 / 检测留档的历史码 / 红名单），每次随机新造的子域不预列。
   数据源：service_codes（现役）、code_check_cache（检测留档，码已不在即视为已作废）、
   red_domains（红）、pool_subs（固定池）。 */
function handle_admin_pool_subs(string $method): void
{
    require_admin_ctx();
    if ($method !== 'GET') json_error('方法不允许', 405);
    $main = strtolower(trim((string)input('domain', '')));
    if ($main === '' || !str_contains($main, '.')) json_error('缺少 domain');
    $pdo = db();
    $likeSub = '%.' . $main . '/%';
    $likeBare = '%//' . $main . '/%';

    $rows = [];
    $liveCodes = [];
    foreach ($pdo->query('SELECT code, link FROM service_codes WHERE link LIKE ' . $pdo->quote($likeSub) . ' OR link LIKE ' . $pdo->quote($likeBare)) as $r) {
        $h = strtolower((string)(parse_url((string)$r['link'], PHP_URL_HOST) ?: ''));
        if ($h === '') continue;
        $rows[$h] = ['host' => $h, 'status' => '使用中', 'code' => (string)$r['code'], 'checked_at' => ''];
        $liveCodes[] = (string)$r['code'];
    }
    foreach ($pdo->query('SELECT link, state, at_ts FROM code_check_cache WHERE link LIKE ' . $pdo->quote($likeSub) . ' OR link LIKE ' . $pdo->quote($likeBare)) as $r) {
        $h = strtolower((string)(parse_url((string)$r['link'], PHP_URL_HOST) ?: ''));
        if ($h === '' || isset($rows[$h])) continue;
        parse_str((string)(parse_url((string)$r['link'], PHP_URL_QUERY) ?: ''), $q);
        $code = (string)($q['key'] ?? '');
        $isLive = $code !== '' && in_array($code, $liveCodes, true);
        $rows[$h] = ['host' => $h,
            'status' => $isLive ? '使用中' : ((string)$r['state'] === 'red' ? '红码' : '已作废'),
            'code' => $code,
            'checked_at' => (int)$r['at_ts'] ? date('Y-m-d H:i', (int)$r['at_ts']) : ''];
    }
    $st = $pdo->prepare('SELECT host, code, created_at FROM red_domains WHERE main = ? OR host = ?');
    $st->execute([$main, $main]);
    foreach ($st->fetchAll() as $r) {
        $h = strtolower((string)$r['host']);
        $rows[$h] = ['host' => $h, 'status' => '红码', 'code' => (string)$r['code'],
            'checked_at' => dt_to_iso((string)$r['created_at'])];
    }
    foreach (array_filter(array_map('trim', explode(',', kv_get('pool_subs:' . $main, '')))) as $s) {
        $h = strtolower($s) . '.' . $main;
        if (!isset($rows[$h])) $rows[$h] = ['host' => $h, 'status' => '待分配', 'code' => '', 'checked_at' => ''];
    }
    $rank = ['红码' => 0, '使用中' => 1, '已作废' => 2, '待分配' => 3];
    $out = array_values($rows);
    usort($out, fn($a, $b) => ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9) ?: strcmp($a['host'], $b['host']));
    json_out($out);
}

/* ---------- 已红域名名单（v2.63）：检测到红自动退役，这里查看/恢复 ---------- */

function handle_admin_red_domains(string $method): void
{
    require_admin_ctx();
    if ($method !== 'GET') json_error('方法不允许', 405);
    json_out(db()->query('SELECT id, host, main, kind, code, channel, created_at FROM red_domains ORDER BY id DESC LIMIT 300')->fetchAll());
}

/** 恢复：主域红 → 重新启用池行；子域红 → 追加回固定子域池；然后从名单移除 */
function handle_admin_red_domain_restore(string $method, int $id): void
{
    require_admin_ctx();
    if ($method !== 'DELETE') json_error('方法不允许', 405);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM red_domains WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) json_error('记录不存在', 404);
    if ($r['kind'] === 'sub' && $r['main'] !== '') {
        $key = 'pool_subs:' . $r['main'];
        $list = array_values(array_filter(array_map('trim', explode(',', kv_get($key, '')))));
        if (!in_array((string)$r['host'], $list, true)) $list[] = (string)$r['host'];
        kv_set($key, implode(',', $list));
        $pdo->prepare("UPDATE domain_pool SET enabled = 1 WHERE domain = ? AND dtype = 'fixed'")->execute([$r['main']]);
    } elseif ($r['main'] !== '') {
        $pdo->prepare('UPDATE domain_pool SET enabled = 1 WHERE domain = ?')->execute([$r['main']]);
    }
    $pdo->prepare('DELETE FROM red_domains WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
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
