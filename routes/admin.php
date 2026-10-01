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
    return [
        'id' => (int)$t['id'], 'kami' => $t['kami'], 'nickname' => $t['nickname'], 'remark' => $t['remark'],
        'status' => $t['status'], 'expires_at' => $t['expires_at'],
        'quota_web' => (int)$t['quota_web'], 'quota_app' => (int)$t['quota_app'], 'max_agents' => (int)$t['max_agents'],
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
        $rows = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM v_sessions s WHERE s.tenant_id = t.id) sessions_cnt,
            (SELECT COUNT(*) FROM agents a WHERE a.tenant_id = t.id) agents_cnt
            FROM tenants t ORDER BY t.id DESC')->fetchAll();
        $out = array_map(function ($t) {
            $o = tenant_out($t, (int)$t['sessions_cnt']);
            $o['agents_cnt'] = (int)$t['agents_cnt'];
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
        $expires = null;
        if (!empty($data['expires_at'])) {
            $ts = strtotime((string)$data['expires_at']);
            if (!$ts) json_error('有效期格式不正确');
            $expires = date('Y-m-d H:i:s', $ts);
        }

        $pdo->prepare('INSERT INTO tenants (kami, password_hash, nickname, remark, expires_at, quota_web, quota_app, max_agents) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$kami, password_hash($password, PASSWORD_DEFAULT), $nickname, $remark, $expires, $quotaWeb, $quotaApp, $maxAgents]);
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
        foreach (['quota_web', 'quota_app', 'max_agents'] as $f) {
            if (array_key_exists($f, $data)) { $set[] = "$f = ?"; $vals[] = max(0, (int)$data[$f]); }
        }
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
                      'service_codes', 'domain_links', 'agents'] as $tbl) {
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
        json_out($pdo->query('SELECT id, domain, wildcard, enabled, created_at FROM domain_pool ORDER BY id ASC')->fetchAll());
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
