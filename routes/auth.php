<?php
/**
 * routes/auth.php - 登录 / 身份
 *
 * 账号格式：
 *   admin            → 平台管理员
 *   KAMI             → 租户（卡密）
 *   KAMI/username    → 租户内子客服
 */
declare(strict_types=1);

/** 登录成功后记录日志（租户/子客服） */
function log_tenant_login(int $tenantId, string $role, string $ident): void
{
    try {
        db()->prepare('INSERT INTO tenant_login_logs (tenant_id, role, ident, ip, ua) VALUES (?,?,?,?,?)')
            ->execute([$tenantId, $role, mb_substr($ident, 0, 64), get_client_ip(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
    } catch (\Throwable $e) { /* 日志失败不影响登录 */ }
}

function handle_auth_login(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $account  = trim((string)input('account', ''));
    $password = (string)input('password', '');
    /* v2.60.10 登录设备：前端在 localStorage 里存一个稳定的设备号随登录一起发过来。
       同一账号默认只能有 3 台设备在线（浏览器/手机各算一台），满了别人登不进来，
       但自己可以在「设置 → 安全与维护 → 登录设备」里踢掉不用的。 */
    $devId = device_norm_id((string)input('dev', ''));
    if ($devId === '') {
        $devId = 'd' . substr(hash('sha256', get_client_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12);
    }
    if ($account === '' || $password === '') json_error('请输入账号和密码');

    $ip = get_client_ip();
    login_fail_check($ip);
    $pdo = db();

    // ---- 平台管理员 ----
    $st = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
    $st->execute([$account]);
    $admin = $st->fetch();
    if ($admin) {
        if (password_verify($password, $admin['password_hash'])) {
            login_fail_clear($ip);
            json_out(['token' => jwt_encode(['role' => 'admin', 'id' => (int)$admin['id']]),
                'user' => ['role' => 'admin', 'id' => (int)$admin['id'], 'username' => $admin['username'], 'nickname' => $admin['nickname']],
                'redirect' => '/admin.html']);
        }
        login_fail_record($ip);
        json_error('密码错误', 401);
    }

    // ---- 拆分 卡密/子客服 ----
    $kami = $account;
    $agentUser = '';
    if (str_contains($account, '/')) {
        [$kami, $agentUser] = array_map('trim', explode('/', $account, 2));
    }
    if ($kami === '') json_error('账号格式不正确', 400);

    $st = $pdo->prepare('SELECT * FROM tenants WHERE kami = ?');
    $st->execute([$kami]);
    $t = $st->fetch();
    if (!$t) { login_fail_record($ip); json_error('卡密不存在', 401); }

    if ($agentUser !== '') {
        // 子客服登录
        $st = $pdo->prepare("SELECT * FROM agents WHERE tenant_id = ? AND username = ? AND status = 'active'");
        $st->execute([(int)$t['id'], $agentUser]);
        $a = $st->fetch();
        if (!$a) { login_fail_record($ip); json_error('客服账号不存在或已停用', 401); }
        if (!password_verify($password, $a['password_hash'])) { login_fail_record($ip); json_error('密码错误', 401); }
        if ($t['status'] !== 'active') json_error('所属卡密账户已被停用，请联系平台管理员', 403);
        if ($t['expires_at'] && strtotime($t['expires_at']) < time()) json_error('所属卡密账户已到期，请联系平台管理员续期', 403);
        login_fail_clear($ip);
        $pdo->prepare('UPDATE agents SET work_status = ? WHERE id = ?')->execute(['online', $a['id']]);
        log_tenant_login((int)$t['id'], 'agent', $a['username']);
        $chk = device_register(device_owner_key('agent', (int)$a['id'], (int)$t['id']), (int)$t['id'], $devId, $ip,
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), device_limit_for($t));
        if (empty($chk['ok'])) json_error((string)$chk['msg'], 403);
        $uaRaw = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!empty($chk['is_new'])) {
            login_notice_push(device_owner_key('agent', (int)$a['id'], (int)$t['id']), $devId, (string)$a['nickname'], $ip, $uaRaw);
        }
        json_out(['token' => jwt_encode(['role' => 'agent', 'id' => (int)$a['id'], 'tenant_id' => (int)$t['id'], 'dev' => $devId]),
            'user' => ['role' => 'agent', 'id' => (int)$a['id'], 'tenant_id' => (int)$t['id'],
                'nickname' => $a['nickname'], 'avatar' => $a['avatar'], 'work_status' => 'online'],
            'device' => ['is_new' => !empty($chk['is_new']), 'note' => (string)($chk['dev']['note'] ?? ''),
                'ip' => $ip, 'geo' => lookup_geo($ip), 'device' => ua_label($uaRaw)],
            'redirect' => '/app.html']);
    }

    // 租户登录
    if (!password_verify($password, $t['password_hash'])) { login_fail_record($ip); json_error('密码错误', 401); }
    if ($t['status'] !== 'active') json_error('卡密账户已被停用，请联系平台管理员', 403);
    if ($t['expires_at'] && strtotime($t['expires_at']) < time()) json_error('卡密账户已到期，请联系平台管理员续期', 403);
    login_fail_clear($ip);
    $pdo->prepare('UPDATE tenants SET last_login_at = ? WHERE id = ?')->execute([now_dt(), $t['id']]);
    log_tenant_login((int)$t['id'], 'tenant', $t['kami']);
    /* v2.86 新用户第一次登录就自动给一条客服码（不用自己去点「发码」） */
    tenant_ensure_service_code((int)$t['id']);
    $chk = device_register(device_owner_key('tenant', (int)$t['id'], (int)$t['id']), (int)$t['id'], $devId, $ip,
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), device_limit_for($t));
    if (empty($chk['ok'])) json_error((string)$chk['msg'], 403);
    /* v2.80 新设备登录 → 给同账号的其他设备写一条「新登录通知」 */
    $uaRaw = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!empty($chk['is_new'])) {
        login_notice_push(device_owner_key('tenant', (int)$t['id'], (int)$t['id']), $devId, (string)$t['nickname'], $ip, $uaRaw);
    }
    json_out(['token' => jwt_encode(['role' => 'tenant', 'id' => (int)$t['id'], 'dev' => $devId]),
        'user' => ['role' => 'tenant', 'id' => (int)$t['id'], 'kami' => $t['kami'],
            'nickname' => $t['nickname'], 'expires_at' => $t['expires_at']],
        'device' => ['is_new' => !empty($chk['is_new']), 'note' => (string)($chk['dev']['note'] ?? ''),
            'ip' => $ip, 'geo' => lookup_geo($ip), 'device' => ua_label($uaRaw)],
        'redirect' => '/app.html']);
}

/* ---------- v2.60.10 登录设备管理 ----------
   租户看自己的设备；子客服只看自己那台（不想把别人的设备暴露给子客服）。 */
function login_device_owner(): array
{
    $ctx = require_workspace();
    $key = device_owner_key($ctx['role'], (int)$ctx['id'], (int)$ctx['tenant_id']);
    return [$ctx, $key];
}

function device_row_out(array $r): array
{
    $ua = (string)($r['ua'] ?? '');
    $dev = ua_label($ua);   // v2.80 抽到 helpers.php，登录通知里共用同一套标签
    return [
        'id' => (int)$r['id'],
        'dev_id' => substr((string)$r['dev_id'], 0, 8),
        'note' => (string)$r['note'],
        'device' => $dev,
        'ip' => (string)$r['ip'],
        'created_at' => dt_to_iso((string)$r['created_at']),
        'last_seen' => dt_to_iso((string)$r['last_seen']),
    ];
}

function handle_login_devices(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    [$ctx, $key] = login_device_owner();
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM login_devices WHERE owner_key = ? ORDER BY last_seen DESC LIMIT 50');
    $st->execute([$key]);
    /* v2.86 标出「这台就是我正在用的设备」——列表里一眼认出自己，不会手滑把自己踢下线 */
    $tok = bearer_token();
    $dec = $tok ? jwt_decode($tok) : null;
    $myDev = device_norm_id((string)(is_array($dec) ? ($dec['dev'] ?? '') : ''));
    $list = array_map(function ($r) use ($myDev) {
        $o = device_row_out($r);
        $o['current'] = ($myDev !== '' && device_norm_id((string)$r['dev_id']) === $myDev);
        return $o;
    }, $st->fetchAll());
    $limit = tenant_device_limit((int)$ctx['tenant_id']);
    json_out(['devices' => $list, 'limit' => $limit, 'count' => count($list)]);
}

/** v2.80 登录安全：返回 ①我这次登录的设备信息（新设备要不要让用户起个称呼）
 *  ②最新的一条「新登录通知」（同账号别的设备登录时写的，前端弹提醒用）。 */
function handle_login_notice(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    [$ctx, $key] = login_device_owner();
    $pdo = db();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $tok = bearer_token();
    $dec = $tok ? jwt_decode($tok) : null;
    $myDev = device_norm_id((string)(is_array($dec) ? ($dec['dev'] ?? '') : ''));
    $me = null;
    if ($myDev !== '') {
        $st = $pdo->prepare('SELECT * FROM login_devices WHERE owner_key = ? AND dev_id = ? LIMIT 1');
        $st->execute([$key, $myDev]);
        $row = $st->fetch();
        if ($row) {
            $isNew = (time() - strtotime((string)$row['created_at'])) < 120;   // 本次刚登记的设备
            $me = [
                'id' => (int)$row['id'],
                'dev_id' => $myDev,
                'note' => (string)$row['note'],
                'is_new' => $isNew,
                'ip' => (string)$row['ip'] ?: get_client_ip(),
                'geo' => lookup_geo((string)$row['ip'] ?: get_client_ip()),
                'device' => ua_label($ua),
                'at' => dt_to_iso((string)$row['created_at']),
            ];
        }
    }
    /* v2.81.1 count：该账号登记了几台设备 —— 只有一台时不弹"确认登录信息"（起名字没意义） */
    $count = 0;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM login_devices WHERE owner_key = ?');
        $st->execute([$key]);
        $count = (int)$st->fetchColumn();
    } catch (\Throwable $e) { }
    json_out(['notice' => login_notice_get($key), 'me' => $me, 'devices' => $count]);
}

function handle_login_device_kick(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    [$ctx, $key] = login_device_owner();
    $id = (int)input('id', 0);
    if ($id <= 0) json_error('缺少设备 id');
    // 只允许踢自己名下的设备；别把自己正在用的这台踢了
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM login_devices WHERE id = ? AND owner_key = ? LIMIT 1');
    $st->execute([$id, $key]);
    $row = $st->fetch();
    if (!$row) json_error('设备不存在', 404);
    $tok = bearer_token();
    $dec = $tok ? jwt_decode($tok) : null;
    $cur = device_norm_id((string)(is_array($dec) ? ($dec['dev'] ?? '') : ''));
    if ($cur !== '' && $cur === (string)$row['dev_id']) json_error('这是你当前正在使用的设备，不能踢', 400);
    $pdo->prepare('DELETE FROM login_devices WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

function handle_login_device_note(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    [$ctx, $key] = login_device_owner();
    $id = (int)input('id', 0);
    $note = mb_substr(trim((string)input('note', '')), 0, 32);
    if ($id <= 0) json_error('缺少设备 id');
    $pdo = db();
    $st = $pdo->prepare('UPDATE login_devices SET note = ? WHERE id = ? AND owner_key = ?');
    $st->execute([$note, $id, $key]);
    json_out(['ok' => true]);
}

/** 当前身份详情（工作台/管理台通用） */
function handle_auth_me(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_auth();
    $pdo = db();

    if ($ctx['role'] === 'admin') {
        $u = $ctx['row'];
        $defaultPwd = password_verify('123456', $u['password_hash']);
        json_out(['role' => 'admin', 'id' => (int)$u['id'], 'username' => $u['username'],
            'nickname' => $u['nickname'], 'default_password' => $defaultPwd]);
    }

    if ($ctx['role'] === 'tenant') {
        $t = $ctx['row'];
        $t['avatar'] = ts_get((int)$t['id'], 'me_avatar', '');  // 租户自定义头像（图片 URL）
        $usedWeb = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions WHERE tenant_id=? AND deleted_at IS NULL AND quota_key='web' AND last_time >= ?",
            [$t['id'], now_dt(strtotime('-6 days'))]);
        $usedApp = fetch_count($pdo, "SELECT COUNT(*) FROM v_sessions WHERE tenant_id=? AND deleted_at IS NULL AND quota_key='app' AND last_time >= ?",
            [$t['id'], now_dt(strtotime('-6 days'))]);
        json_out([
            'role' => 'tenant', 'id' => (int)$t['id'], 'kami' => $t['kami'], 'nickname' => $t['nickname'], 'avatar' => $t['avatar'],
            'remark' => $t['remark'], 'expires_at' => $t['expires_at'],
            'quota_web' => (int)$t['quota_web'], 'quota_app' => (int)$t['quota_app'],
            'max_agents' => (int)$t['max_agents'],
            'used_web' => $usedWeb, 'used_app' => $usedApp,
            'created_at' => $t['created_at'],
        ]);
    }

    // agent
    $a = $ctx['row'];
    json_out(['role' => 'agent', 'id' => (int)$a['id'], 'tenant_id' => (int)$a['tenant_id'],
        'username' => $a['username'], 'nickname' => $a['nickname'], 'avatar' => $a['avatar'],
        'work_status' => $a['work_status'], 'sound_enabled' => (bool)$a['sound_enabled'],
        'auto_accept' => (bool)$a['auto_accept'], 'signature' => $a['signature']]);
}

/** 修改自己密码 */
function handle_auth_password(string $method): void
{
    if ($method !== 'PUT') { json_error('方法不允许', 405); return; }
    $ctx = require_auth();
    $old = (string)input('old_password', '');
    $new = (string)input('new_password', '');
    if ($old === '' || $new === '') json_error('请输入旧密码和新密码');
    if (strlen($new) < 6) json_error('新密码至少 6 位');

    $pdo = db();
    $table = ['admin' => 'admins', 'tenant' => 'tenants', 'agent' => 'agents'][$ctx['role']];
    $st = $pdo->prepare("SELECT password_hash FROM $table WHERE id = ?");
    $st->execute([$ctx['id']]);
    $r = $st->fetch();
    if (!$r || !password_verify($old, $r['password_hash'])) json_error('旧密码错误');
    $pdo->prepare("UPDATE $table SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($new, PASSWORD_DEFAULT), $ctx['id']]);
    json_out(['ok' => true]);
}

/** 修改个人资料（昵称/头像/状态等） */
function handle_auth_profile(string $method): void
{
    if ($method !== 'PUT') { json_error('方法不允许', 405); return; }
    $ctx = require_auth();
    $pdo = db();

    if ($ctx['role'] === 'admin') {
        $nickname = mb_substr(trim((string)input('nickname', '')), 0, 64);
        if ($nickname !== '') $pdo->prepare('UPDATE admins SET nickname = ? WHERE id = ?')->execute([$nickname, $ctx['id']]);
        json_out(['ok' => true]);
    }

    if ($ctx['role'] === 'tenant') {
        $nickname = trim((string)input('nickname', ''));
        if ($nickname !== '') $pdo->prepare('UPDATE tenants SET nickname = ? WHERE id = ?')->execute([mb_substr($nickname, 0, 64), $ctx['id']]);
        json_out(['ok' => true]);
    }

    // agent：昵称、头像、签名、工作状态、声音、自动接手
    $set = []; $vals = [];
    foreach (['nickname', 'avatar', 'signature', 'work_status'] as $f) {
        $v = input($f, null);
        if ($v !== null) { $set[] = "$f = ?"; $vals[] = mb_substr((string)$v, 0, 255); }
    }
    foreach (['sound_enabled', 'auto_accept'] as $f) {
        if (input($f, null) !== null) { $set[] = "$f = ?"; $vals[] = input($f) ? 1 : 0; }
    }
    if (isset($vals[3]) && $vals[3] === 'work_status = ?') { /* 占位防御 */ }
    // work_status 白名单校验
    if (in_array('work_status = ?', $set, true)) {
        $idx = array_search('work_status = ?', $set, true);
        $vals[$idx] = in_array($vals[$idx], ['online', 'busy', 'away', 'offline'], true) ? $vals[$idx] : 'online';
    }
    if ($set) {
        $vals[] = $ctx['id'];
        $pdo->prepare('UPDATE agents SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }
    $st = $pdo->prepare('SELECT id, username, nickname, avatar, work_status, sound_enabled, auto_accept, signature FROM agents WHERE id = ?');
    $st->execute([$ctx['id']]);
    json_out(['ok' => true, 'user' => $st->fetch()]);
}
