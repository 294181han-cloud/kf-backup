<?php
/**
 * helpers.php - 工具函数（JWT / 上传 / 限频 / 通用）
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        // 1+2+n 分域部署：同一份代码/配置服务所有节点——按访问域名套用 hosts 覆盖段
        // （config.php 的 'hosts' 里按域名写 db / site_role / site_main_url / relay_secret 等）
        $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && isset($cfg['hosts'][$host]) && is_array($cfg['hosts'][$host])) {
            foreach ($cfg['hosts'][$host] as $k => $v) $cfg[$k] = $v;
        }
        unset($cfg['hosts']);
    }
    return $cfg;
}

/* ================= HTTP / JSON ================= */

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $code = 400): void
{
    json_out(['error' => $msg], $code);
}

/** v2.75 「账号状态」类错误（到期/停用/被踢）：带上机器可读的 code，
 *  前端据此**强制退出登录**并说明原因，而不是停在页面里一直报错。
 *  code: expired=已到期 / disabled=已停用 / kicked=被踢下线 */
function json_error_auth(string $msg, string $reason, int $status = 403): void
{
    json_out(['error' => $msg, 'code' => $reason], $status);
}

function body(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    $cached = is_array($j) ? $j : [];
    return $cached;
}

function input(string $key, $default = null)
{
    if (array_key_exists($key, body())) return body()[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}

function now_dt(?int $ts = null): string
{
    return date('Y-m-d H:i:s', $ts ?? time());
}

function dt_to_iso(?string $dt): ?string
{
    if (!$dt) return null;
    $ts = strtotime($dt);
    return $ts ? date('c', $ts) : $dt;
}

function fetch_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/* ================= 键值存储 ================= */

function kv_get(string $key, string $def = ''): string
{
    try {
        $stmt = db()->prepare('SELECT sval FROM kv_store WHERE skey = ?');
        $stmt->execute([$key]);
        $r = $stmt->fetch();
        return $r ? (string)$r['sval'] : $def;
    } catch (\Throwable $e) { return $def; }
}

function kv_set(string $key, string $val): void
{
    db()->prepare('INSERT INTO kv_store (skey, sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')
        ->execute([$key, $val]);
}

/** 租户级设置 */
function ts_get(int $tenantId, string $key, string $def = ''): string
{
    try {
        $stmt = db()->prepare('SELECT sval FROM tenant_settings WHERE tenant_id = ? AND skey = ?');
        $stmt->execute([$tenantId, $key]);
        $r = $stmt->fetch();
        return $r ? (string)$r['sval'] : $def;
    } catch (\Throwable $e) { return $def; }
}

function ts_set(int $tenantId, string $key, string $val): void
{
    db()->prepare('INSERT INTO tenant_settings (tenant_id, skey, sval) VALUES (?,?,?) 
        ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute([$tenantId, $key, $val]);
}

/* ================= JWT（HMAC-SHA256，密钥存 kv） ================= */

function jwt_secret(): string
{
    $s = kv_get('jwt_secret', '');
    if ($s === '') {
        $s = bin2hex(random_bytes(32));
        kv_set('jwt_secret', $s);
    }
    return $s;
}

function jwt_encode(array $payload): string
{
    $cfg = config();
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload['iat'] = time();
    $payload['exp'] = time() + $cfg['jwt_ttl'];
    $b64 = fn(array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
    $h = $b64($header);
    $p = $b64($payload);
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", jwt_secret(), true)), '+/', '-_'), '=');
    return "$h.$p.$sig";
}

function jwt_decode(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expect = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", jwt_secret(), true)), '+/', '-_'), '=');
    if (!hash_equals($expect, $s)) return null;
    $j = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    if (!is_array($j)) return null;
    if (isset($j['exp']) && time() > $j['exp']) return null;
    return $j;
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $h = $v; break; }
        }
    }
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) return $m[1];
    return null;
}

/** 验证 token 并实时校验账号状态。返回身份上下文：
 *  ['role'=>'admin','id'=>..,'name'=>..]
 *  ['role'=>'tenant','id'=>tenantId,'kami'=>..,'row'=>tenants行]
 *  ['role'=>'agent','id'=>agentId,'tenant_id'=>..,'row'=>agents行] */
function require_auth(): array
{
    $token = bearer_token();
    if (!$token) json_error('未登录', 401);
    $p = jwt_decode($token);
    if (!$p || empty($p['role'])) json_error('登录已失效，请重新登录', 401);
    $pdo = db();

    if ($p['role'] === 'admin') {
        $st = $pdo->prepare('SELECT * FROM admins WHERE id = ?');
        $st->execute([(int)$p['id']]);
        $u = $st->fetch();
        if (!$u) json_error('账号不存在', 401);
        return ['role' => 'admin', 'id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['nickname'], 'row' => $u];
    }

    if ($p['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
        $st->execute([(int)$p['id']]);
        $t = $st->fetch();
        if (!$t) json_error('账号不存在', 401);
        if ($t['status'] !== 'active') json_error_auth('账号已被停用，请联系平台管理员', 'disabled');
        if ($t['expires_at'] && strtotime($t['expires_at']) < time()) json_error_auth('账号已到期，请联系平台管理员续期', 'expired');
        if (!empty($p['dev']) && !device_touch(device_owner_key('tenant', (int)$t['id'], (int)$t['id']), (string)$p['dev'])) {
            json_error_auth('这台设备已被踢下线，请重新登录', 'kicked', 401);
        }
        return ['role' => 'tenant', 'id' => (int)$t['id'], 'kami' => $t['kami'], 'name' => $t['nickname'], 'row' => $t];
    }

    if ($p['role'] === 'agent') {
        $st = $pdo->prepare('SELECT a.*, t.kami AS tenant_kami, t.status AS tenant_status, t.expires_at AS tenant_expires FROM agents a JOIN tenants t ON t.id = a.tenant_id WHERE a.id = ?');
        $st->execute([(int)$p['id']]);
        $a = $st->fetch();
        if (!$a) json_error('账号不存在', 401);
        if ($a['status'] !== 'active') json_error_auth('客服账号已被停用', 'disabled');
        if ($a['tenant_status'] !== 'active') json_error_auth('所属账号已被停用', 'disabled');
        if ($a['tenant_expires'] && strtotime($a['tenant_expires']) < time()) json_error_auth('所属账号已到期，请联系客服续期', 'expired');
        if (!empty($p['dev']) && !device_touch(device_owner_key('agent', (int)$a['id'], (int)$a['tenant_id']), (string)$p['dev'])) {
            json_error_auth('这台设备已被踢下线，请重新登录', 'kicked', 401);
        }
        return ['role' => 'agent', 'id' => (int)$a['id'], 'tenant_id' => (int)$a['tenant_id'], 'name' => $a['nickname'], 'row' => $a];
    }

    json_error('身份无效', 401);
}

/** 限定角色（可多个），返回身份上下文 */
function require_role(string ...$roles): array
{
    $ctx = require_auth();
    if (!in_array($ctx['role'], $roles, true)) json_error('无权限执行此操作', 403);
    return $ctx;
}

/** 要求 tenant 身份（返回 tenant_id） */
function require_tenant(): array
{
    return require_role('tenant');
}

/** 要求 tenant 或 agent（返回 tenant_id + 可选 agent_id） */
function require_workspace(): array
{
    $ctx = require_role('tenant', 'agent');
    $ctx['tenant_id'] = (int)($ctx['tenant_id'] ?? $ctx['id']);
    $ctx['agent_id']  = $ctx['role'] === 'agent' ? $ctx['id'] : 0;
    return $ctx;
}

/* ================= v2.60.10 登录设备（防卡密共享） =================
   一个卡密默认只能有 3 台设备在线（浏览器/手机各算一台），满了别人就登不进来，
   但自己这边可以看设备列表、改备注、把不认识的那台踢掉。
   —— 做卡密生意最怕的就是一张卡被转卖/共享几十个人用。 */
function device_limit_for(array $t): int
{
    $n = (int)($t['max_devices'] ?? 3);
    if ($n <= 0) $n = 3;          // 默认 3 台
    return min(8, $n);            // 最高 8 台
}

function device_owner_key(string $role, int $id, int $tenantId): string
{
    return $role === 'agent' ? ('a:' . $id) : ('t:' . ($tenantId > 0 ? $tenantId : $id));
}

/** 取某个租户的登录设备上限（默认 3，最高 8） */
function tenant_device_limit(int $tenantId): int
{
    try {
        $st = db()->prepare('SELECT max_devices FROM tenants WHERE id = ?');
        $st->execute([$tenantId]);
        $row = $st->fetch() ?: [];
        return device_limit_for($row);
    } catch (\Throwable $e) { return 3; }
}

function device_norm_id(string $dev): string
{
    $dev = preg_replace('/[^A-Za-z0-9_-]/', '', $dev);
    return mb_substr((string)$dev, 0, 32);
}

/** 登录时登记设备：已登记过就刷新；没登记过且已满员 → 拒绝登录 */
function device_register(string $ownerKey, int $tenantId, string $devId, string $ip, string $ua, int $limit): array
{
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM login_devices WHERE owner_key = ? AND dev_id = ? LIMIT 1');
        $st->execute([$ownerKey, $devId]);
        $found = $st->fetch();
        if ($found) {
            $pdo->prepare('UPDATE login_devices SET last_seen = ?, ip = ?, ua = ? WHERE id = ?')
                ->execute([now_dt(), $ip, mb_substr($ua, 0, 200), (int)$found['id']]);
            return ['ok' => true, 'dev' => $found];
        }
        $n = fetch_count($pdo, 'SELECT COUNT(*) FROM login_devices WHERE owner_key = ?', [$ownerKey]);
        if ($n >= $limit) {
            return ['ok' => false, 'msg' => '登录设备已达上限（' . $limit . ' 台），请在「设置 → 安全与维护 → 登录设备」里把不用的设备踢掉，或联系客服提高上限'];
        }
        $pdo->prepare('INSERT INTO login_devices (owner_key, tenant_id, dev_id, note, ip, ua, created_at, last_seen) VALUES (?,?,?,\'\',?,?,?,?)')
            ->execute([$ownerKey, $tenantId, $devId, $ip, mb_substr($ua, 0, 200), now_dt(), now_dt()]);
        $id = (int)$pdo->lastInsertId();
        return ['ok' => true, 'is_new' => true, 'dev' => [
            'id' => $id, 'dev_id' => $devId, 'note' => '', 'ip' => $ip, 'ua' => mb_substr($ua, 0, 200),
            'owner_key' => $ownerKey, 'created_at' => now_dt(), 'last_seen' => now_dt(),
        ]];
    } catch (\Throwable $e) {
        return ['ok' => true, 'dev' => null];   // 老库没建表时别把登录堵死
    }
}

/* ===================== v2.80 登录安全：设备称呼 + 异地/新设备登录提醒 =====================
   ① 新设备第一次登录 → 前端弹「确认本次登录信息」，让用户给这台设备起个称呼（存进备注）；
   ② 同时把这次登录写成一条"新登录通知"，同账号的其他在线设备轮询到就会弹提醒
      （谁、在哪、什么设备、什么时候）—— 防盗用、防共享。 */

/** 设备外观标签（跟「登录设备」列表里那个「Windows 电脑 · Chrome」一致） */
function ua_label(string $ua): string
{
    $dev = '未知设备';
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) $dev = 'iPhone/iPad';
    elseif (preg_match('/Android/i', $ua)) $dev = '安卓手机';
    elseif (preg_match('/Windows/i', $ua)) $dev = 'Windows 电脑';
    elseif (preg_match('/Macintosh|Mac OS/i', $ua)) $dev = 'Mac';
    elseif (preg_match('/Linux/i', $ua)) $dev = 'Linux';
    if (preg_match('/MicroMessenger/i', $ua)) $dev .= ' · 微信';
    elseif (preg_match('/Edg\//i', $ua)) $dev .= ' · Edge';
    elseif (preg_match('/Chrome/i', $ua)) $dev .= ' · Chrome';
    elseif (preg_match('/Safari/i', $ua)) $dev .= ' · Safari';
    return $dev;
}

/** 写一条「新登录通知」（同账号的其他设备下次轮询就会看到） */
function login_notice_push(string $ownerKey, string $devId, string $note, string $ip, string $ua): void
{
    try {
        $payload = [
            'id' => bin2hex(random_bytes(6)),
            'ts' => time(),
            'dev' => device_norm_id($devId),
            'note' => mb_substr($note, 0, 32),
            'ip' => $ip,
            'geo' => lookup_geo($ip),
            'geo_text' => ($g0 = lookup_geo($ip)) !== '' ? (geo_region($g0) ?: geo_city($g0)) : '',
            'device' => ua_label($ua),
            'at' => date('Y-m-d H:i:s'),
        ];
        kv_set('login_notice:' . $ownerKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) { /* 通知写失败不影响登录 */ }
}

function login_notice_get(string $ownerKey): ?array
{
    try {
        $raw = kv_get('login_notice:' . $ownerKey, '');
        if ($raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    } catch (\Throwable $e) { return null; }
}

/** 每次带 token 的请求都核对设备还在不在（被踢掉 → token 立刻失效）；last_seen 最多 2 分钟写一次 */
function device_touch(string $ownerKey, string $devId): bool
{
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT id, last_seen FROM login_devices WHERE owner_key = ? AND dev_id = ? LIMIT 1');
        $st->execute([$ownerKey, $devId]);
        $d = $st->fetch();
        if (!$d) return false;
        if (strtotime((string)$d['last_seen']) < time() - 120) {
            $pdo->prepare('UPDATE login_devices SET last_seen = ? WHERE id = ?')->execute([now_dt(), (int)$d['id']]);
        }
        return true;
    } catch (\Throwable $e) {
        return true;   // 表不存在（老库）时放行
    }
}

/* ================= 登录防爆破 ================= */

function login_fail_record(string $ip): void
{
    db()->prepare('INSERT INTO login_fails (ident, created_at) VALUES (?,?)')->execute([$ip, now_dt()]);
    // 顺手清理 1 天前的记录
    if (random_int(1, 20) === 1) {
        db()->prepare('DELETE FROM login_fails WHERE created_at < ?')->execute([now_dt(time() - 86400)]);
    }
}

function login_fail_check(string $ip): void
{
    $cfg = config();
    $n = fetch_count(db(), 'SELECT COUNT(*) FROM login_fails WHERE ident = ? AND created_at > ?',
        [$ip, now_dt(time() - $cfg['login_lock_secs'])]);
    if ($n >= $cfg['login_max_fails']) {
        json_error('尝试次数过多，请 15 分钟后再试', 429);
    }
}

function login_fail_clear(string $ip): void
{
    db()->prepare('DELETE FROM login_fails WHERE ident = ?')->execute([$ip]);
}

/* ================= 访客 IP / 黑名单 ================= */

function get_client_ip(): string
{
    $norm = function (string $ip): string {
        if ($ip === '::1') return '127.0.0.1';
        if (str_starts_with($ip, '::ffff:')) return substr($ip, 7);
        return $ip;
    };
    $remote = $norm($_SERVER['REMOTE_ADDR'] ?? '');
    // 可信代理：本机 nginx（127.0.0.1）+ 已登记分控节点（总控角色）
    $trusted = site_role() === 'main' ? array_merge(['127.0.0.1', '::1'], trusted_edge_ips()) : [];
    if (in_array($remote, $trusted, true)) {
        // 从右往左跳过可信代理，第一个非可信 IP 即真实来源（客户端伪造的 XFF 头会被自动忽略）
        $xff = array_map('trim', array_reverse(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        foreach ($xff as $cand) {
            $cand = $norm($cand);
            if ($cand === '') continue;
            if (in_array($cand, $trusted, true)) continue;
            if (filter_var($cand, FILTER_VALIDATE_IP)) return $cand;
        }
    }
    return $remote ?: '127.0.0.1';
}

function is_ip_blocked(int $tenantId, string $ip): bool
{
    if (!$ip) return false;
    return (bool)fetch_count(db(), 'SELECT 1 FROM ip_blacklist WHERE tenant_id = ? AND ip = ? LIMIT 1', [$tenantId, $ip]);
}

/* ================= 附件 / 消息 ================= */

/* ================= 访客头像（动物 emoji） ================= */

/**
 * 头像池分层：① 动物 → ② 车/船/交通 → ③ 其它醒目 emoji。
 * 分配时按层优先取"当天没用过的"，所以先保证动物不重复，不够再往下一层拿。
 * 全部为单码位（或标准 emoji 呈现）字符，避免各设备渲染成文字。
 */
function visitor_avatar_tiers(): array
{
    return [
        // ① 动物
        [
            '🐶' => '小狗', '🐱' => '小猫', '🐭' => '老鼠', '🐹' => '仓鼠', '🐰' => '兔子', '🦊' => '狐狸',
            '🐻' => '小熊', '🐼' => '熊猫', '🐨' => '考拉', '🐯' => '老虎', '🦁' => '狮子', '🐮' => '奶牛',
            '🐷' => '小猪', '🐽' => '猪鼻', '🐸' => '青蛙', '🐵' => '猴子', '🙈' => '捂眼猴', '🙉' => '捂耳猴',
            '🙊' => '捂嘴猴', '🐒' => '猴子', '🐔' => '公鸡', '🐧' => '企鹅', '🐦' => '小鸟', '🐤' => '小鸡',
            '🐣' => '破壳鸡', '🐥' => '小鸡', '🦆' => '鸭子', '🦢' => '天鹅', '🦅' => '老鹰', '🦉' => '猫头鹰',
            '🦃' => '火鸡', '🦚' => '孔雀', '🦜' => '鹦鹉', '🦇' => '蝙蝠', '🐺' => '狼', '🐗' => '野猪',
            '🐴' => '骏马', '🦄' => '独角兽', '🐝' => '蜜蜂', '🐛' => '毛毛虫', '🦋' => '蝴蝶', '🐌' => '蜗牛',
            '🐞' => '瓢虫', '🐜' => '蚂蚁', '🦗' => '蟋蟀', '🦂' => '蝎子', '🐢' => '乌龟', '🐍' => '蛇',
            '🦎' => '蜥蜴', '🦖' => '霸王龙', '🦕' => '恐龙', '🐙' => '章鱼', '🦑' => '鱿鱼', '🦐' => '虾',
            '🦞' => '龙虾', '🦀' => '螃蟹', '🐡' => '河豚', '🐠' => '热带鱼', '🐟' => '小鱼', '🐬' => '海豚',
            '🐳' => '鲸鱼', '🐋' => '鲸鱼', '🦈' => '鲨鱼', '🐊' => '鳄鱼', '🐅' => '老虎', '🐆' => '豹子',
            '🦓' => '斑马', '🦍' => '大猩猩', '🦧' => '红毛猩猩', '🐘' => '大象', '🦛' => '河马', '🦏' => '犀牛',
            '🐪' => '骆驼', '🐫' => '骆驼', '🦒' => '长颈鹿', '🦘' => '袋鼠', '🐃' => '水牛', '🐂' => '公牛',
            '🐄' => '奶牛', '🐎' => '骏马', '🐖' => '小猪', '🐏' => '绵羊', '🐑' => '绵羊', '🦙' => '羊驼',
            '🐐' => '山羊', '🦌' => '小鹿', '🐕' => '小狗', '🐩' => '贵宾犬', '🐈' => '猫咪', '🐓' => '公鸡',
            '🐇' => '兔子', '🦝' => '浣熊', '🦨' => '臭鼬', '🦡' => '獾', '🦦' => '水獭', '🦥' => '树懒',
            '🦭' => '海豹', '🐁' => '老鼠', '🐀' => '大鼠', '🦔' => '刺猬', '🐾' => '爪印', '🐉' => '神龙',
            '🐲' => '龙头', '🦩' => '火烈鸟', '🦤' => '渡渡鸟', '🪱' => '蚯蚓', '🦟' => '蚊子',
        ],
        // ② 车 / 船 / 交通
        [
            '🚗' => '轿车', '🚕' => '出租车', '🚙' => '越野车', '🚌' => '公交车', '🚎' => '无轨电车', '🏎' => '赛车',
            '🚑' => '救护车', '🚒' => '消防车', '🚐' => '面包车', '🛻' => '皮卡', '🚚' => '货车', '🚛' => '大货车',
            '🚜' => '拖拉机', '🛵' => '踏板车', '🏍' => '摩托', '🛺' => '三轮车', '🚲' => '自行车', '🛴' => '滑板车',
            '🚍' => '巴士', '🚘' => '轿车', '🚖' => '出租车', '🚡' => '缆车', '🚠' => '吊厢', '🚟' => '空中缆车',
            '🚃' => '电车', '🚋' => '有轨电车', '🚞' => '山区铁路', '🚝' => '单轨车', '🚄' => '高铁', '🚅' => '高铁',
            '🚈' => '轻轨', '🚂' => '火车', '🚆' => '火车', '🚇' => '地铁', '🚊' => '有轨电车', '🚉' => '车站',
            '🛫' => '起飞', '🛬' => '降落', '🛩' => '小飞机', '🚀' => '火箭', '🛸' => '飞碟', '🚁' => '直升机',
            '🛶' => '独木舟', '⛵' => '帆船', '🚤' => '快艇', '🛥' => '游艇', '🛳' => '邮轮', '⛴' => '渡轮',
            '🚢' => '轮船', '⚓' => '船锚', '🚏' => '站牌', '⛽' => '加油站', '🚧' => '施工', '🚦' => '红绿灯',
            '🚥' => '信号灯', '🛑' => '停车牌', '🧭' => '指南针', '🛰' => '卫星', '🎠' => '旋转木马', '🎡' => '摩天轮',
            '🎢' => '过山车', '🎪' => '马戏团', '🗼' => '铁塔', '🗽' => '自由女神', '🏰' => '城堡',
        ],
        // ③ 其它醒目 emoji
        [
            '🍎' => '苹果', '🍊' => '橘子', '🍋' => '柠檬', '🍌' => '香蕉', '🍉' => '西瓜', '🍇' => '葡萄',
            '🍓' => '草莓', '🫐' => '蓝莓', '🍈' => '甜瓜', '🍒' => '樱桃', '🍑' => '桃子', '🥭' => '芒果',
            '🍍' => '菠萝', '🥥' => '椰子', '🥝' => '猕猴桃', '🍅' => '番茄', '🥑' => '牛油果', '🍆' => '茄子',
            '🥔' => '土豆', '🥕' => '胡萝卜', '🌽' => '玉米', '🌶' => '辣椒', '🥒' => '黄瓜', '🥬' => '青菜',
            '🥦' => '西兰花', '🍄' => '蘑菇', '🥜' => '花生', '🍞' => '面包', '🥐' => '可颂', '🥖' => '法棍',
            '🥨' => '椒盐卷', '🧀' => '奶酪', '🥚' => '鸡蛋', '🍳' => '煎蛋', '🧇' => '华夫饼', '🥞' => '松饼',
            '🧈' => '黄油', '🍗' => '鸡腿', '🍖' => '肉', '🌭' => '热狗', '🍔' => '汉堡', '🍟' => '薯条',
            '🍕' => '披萨', '🥪' => '三明治', '🥙' => '卷饼', '🧆' => '丸子', '🌮' => '墨西哥卷', '🌯' => '烤肉卷',
            '🥗' => '沙拉', '🥘' => '海鲜饭', '🍝' => '意面', '🍜' => '拉面', '🍲' => '火锅', '🍛' => '咖喱',
            '🍣' => '寿司', '🍱' => '便当', '🥟' => '饺子', '🍤' => '炸虾', '🍙' => '饭团', '🍚' => '米饭',
            '🍘' => '仙贝', '🍥' => '鱼糕', '🥠' => '幸运饼', '🍢' => '关东煮', '🍡' => '团子', '🍧' => '刨冰',
            '🍨' => '冰淇淋', '🍦' => '甜筒', '🥧' => '派', '🧁' => '纸杯蛋糕', '🍰' => '蛋糕', '🎂' => '生日蛋糕',
            '🍮' => '布丁', '🍭' => '棒棒糖', '🍬' => '糖果', '🍫' => '巧克力', '🍿' => '爆米花', '🍩' => '甜甜圈',
            '🍪' => '曲奇', '☕' => '咖啡', '🍵' => '茶', '🧃' => '果汁', '🥤' => '饮料', '🍶' => '清酒',
            '🍺' => '啤酒', '🍻' => '干杯', '🥂' => '香槟', '🍷' => '红酒', '🥃' => '威士忌', '🍸' => '鸡尾酒',
            '🍹' => '果酒', '🧉' => '马黛茶', '🧊' => '冰块', '🌈' => '彩虹', '🌸' => '樱花', '🌺' => '小花',
            '🌻' => '向日葵', '🌼' => '小雏菊', '🌷' => '郁金香', '🌹' => '玫瑰', '🌵' => '仙人掌', '🌴' => '棕榈树',
            '🌳' => '大树', '🌲' => '松树', '🍀' => '四叶草', '🍁' => '枫叶', '🍂' => '落叶', '🍃' => '叶子',
            '🌾' => '稻穗', '🌱' => '小苗', '🌿' => '香草', '☘' => '幸运草', '⛄' => '雪人', '⭐' => '星星',
            '🌟' => '闪星', '💫' => '流星', '🔥' => '火', '✨' => '闪光', '⚡' => '闪电', '⚽' => '足球',
            '🏀' => '篮球', '🏈' => '橄榄球', '⚾' => '棒球', '🎾' => '网球', '🏐' => '排球', '🏉' => '橄榄球',
            '🎱' => '台球', '🏓' => '乒乓球', '🏸' => '羽毛球', '🥊' => '拳套', '🥋' => '武术', '⛳' => '高尔夫',
            '🛹' => '滑板', '🎿' => '滑雪', '🏂' => '单板', '🏄' => '冲浪', '🏊' => '游泳', '🚴' => '骑行',
            '🏇' => '骑马', '🤿' => '潜水', '🎯' => '靶心', '🎲' => '骰子', '🎮' => '手柄', '🎰' => '老虎机',
            '🎸' => '吉他', '🎹' => '钢琴', '🎺' => '小号', '🎻' => '小提琴', '🥁' => '鼓', '🎤' => '麦克风',
            '🎧' => '耳机', '🎬' => '电影', '🎨' => '调色板', '🎭' => '面具', '🧡' => '橙心', '💛' => '黄心',
            '💚' => '绿心', '💙' => '蓝心', '💜' => '紫心', '🖤' => '黑心', '🤍' => '白心', '🤎' => '棕心',
            '💎' => '钻石', '👑' => '皇冠', '🎁' => '礼物', '🎈' => '气球', '🎉' => '庆祝', '🎊' => '烟花',
            '🏆' => '奖杯', '🥇' => '金牌', '🚩' => '红旗', '🎀' => '蝴蝶结', '🔮' => '水晶球', '🎩' => '礼帽',
            '🧢' => '棒球帽', '🎓' => '学士帽', '🪙' => '比特币', '💰' => '钱袋', '💵' => '钞票', '💳' => '信用卡',
            '🔑' => '钥匙', '🧲' => '磁铁',
        ],
    ];
}

/** 全部头像（分层合并；老会话按 id 派生时用这个） */
function visitor_avatar_pool(): array
{
    return array_keys(visitor_avatar_map());
}

/** emoji => 中文名 的合并映射 */
function visitor_avatar_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (visitor_avatar_tiers() as $tier) {
            foreach ($tier as $emoji => $nick) $map[$emoji] = $nick;
        }
    }
    return $map;
}

/** 头像 emoji 对应的默认昵称（如 🐼 → 熊猫）；没有登记的返回空串 */
function visitor_avatar_name(string $emoji): string
{
    $m = visitor_avatar_map();
    return $m[$emoji] ?? '';
}

/** 会话头像：优先用已分配的，其次按会话 id 稳定派生一个（老会话也有头像，且客服端/访客端一致） */
function visitor_avatar_resolve(int $sessionId, string $stored = ''): string
{
    $stored = trim($stored);
    if ($stored !== '') return $stored;
    $pool = visitor_avatar_pool();
    return $pool[abs(crc32('kf' . $sessionId)) % count($pool)];
}

function parse_attachments($v): array
{
    if (!$v) return [];
    $a = json_decode((string)$v, true);
    return is_array($a) ? $a : [];
}

function to_msg(array $r): array
{
    $r['attachments'] = parse_attachments($r['attachments'] ?? null);
    $r['time'] = dt_to_iso($r['created_at'] ?? null);
    if (!empty($r['recalled_at'])) $r['recalled'] = true;
    if (!empty($r['edited_at'])) $r['edited'] = true;   // v2.56 编辑过的消息（前端显示「已编辑」）
    unset($r['created_at'], $r['recalled_at'], $r['read_at'], $r['tenant_id']);
    return $r;
}

function resolve_msg_type(string $text, array $attachments): string
{
    if (!$attachments) return 'text';
    if ($text === '') {
        $first = $attachments[0]['type'] ?? '';
        // v2.43：除文字外，语音/图片/视频/文件/位置 各自成一种消息类型
        return in_array($first, ['audio', 'image', 'video', 'file', 'location'], true) ? $first : 'image';
    }
    return 'mixed';
}

/** 消息摘要（会话列表用） */
function msg_summary(string $text, array $attachArr): string
{
    if ($text !== '') return mb_substr($text, 0, 60);
    $t = $attachArr[0]['type'] ?? '';
    return [
        'audio' => '[语音]', 'image' => '[图片]', 'video' => '[视频]',
        'file' => '[文件]', 'location' => '[位置]',
    ][$t] ?? '[图片]';
}

/* ================= 上传（base64 → 磁盘） ================= */

function audio_ext_of(string $mime, string $filename): string
{
    $map = [
        'mp4' => 'm4a', 'm4a' => 'm4a', 'aac' => 'aac', 'amr' => 'amr',
        'x-caf' => 'caf', '3gpp' => '3gp', '3gp' => '3gp', 'mp3' => 'mp3',
        'mpeg' => 'mp3', 'webm' => 'webm', 'ogg' => 'ogg', 'oga' => 'ogg',
        'opus' => 'ogg', 'wav' => 'wav', 'x-m4a' => 'm4a',
    ];
    if (preg_match('#^audio/([a-z0-9.+\-]+)#i', $mime, $m)) {
        $sub = strtolower($m[1]);
        if (isset($map[$sub])) return $map[$sub];
    }
    if (preg_match('#^video/webm#i', $mime)) return 'webm';
    $extHint = (strpos($filename, '.') !== false) ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';
    return in_array($extHint, ['mp3','m4a','wav','amr','webm','ogg','aac','3gp','caf'], true) ? $extHint : 'webm';
}

/* ================= 站点角色（1+2+n 分域部署） ================= */

/** 当前节点角色：main（全功能主站，默认）/ agent（客服登录入口）/ visitor（访客站点） */
function site_role(): string
{
    static $role = null;
    if ($role === null) {
        $r = (string)(config()['site_role'] ?? 'main');
        $role = in_array($r, ['main', 'agent', 'visitor'], true) ? $r : 'main';
    }
    return $role;
}

function site_main_url(): string
{
    return rtrim((string)(config()['site_main_url'] ?? ''), '/');
}

/** 非 main 节点：把上传内容中转到主站 /api/internal/upload，返回含绝对 URL 的结果 */
function relay_upload_to_main(string $data, string $kind, string $filename): array
{
    $cfg = config();
    $main = rtrim((string)($cfg['site_main_url'] ?? ''), '/');
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($main === '' || $secret === '') throw new \RuntimeException('上传中转未配置（site_main_url / relay_secret）');
    $body = json_encode(['secret' => $secret, 'data' => $data, 'kind' => $kind, 'filename' => $filename], JSON_UNESCAPED_UNICODE);
    $resp = '';
    $code = 0;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($main . '/api/internal/upload');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 30, 'ignore_errors' => true]]);
        $resp = @file_get_contents($main . '/api/internal/upload', false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m2)) $code = (int)$m2[1];
        }
        if ($resp === false) $err = '网络请求失败';
    }
    if ($code !== 200 || !is_string($resp) || $resp === '') throw new \RuntimeException('上传中转失败(' . $code . ') ' . $err);
    $j = json_decode($resp, true);
    if (!is_array($j) || empty($j['url'])) throw new \RuntimeException((string)($j['error'] ?? '上传中转响应异常'));
    return $j;
}

/** 主站专用：接收分节点的上传中转（共享 relay_secret 校验；仅 main 角色开放） */
function handle_internal_upload(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $cfg = config();
    if (($cfg['site_role'] ?? 'main') !== 'main') json_error('接口不存在', 403);
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($secret === '') json_error('未启用上传中转', 403);
    $given = (string)input('secret', '');
    if ($given === '' || !hash_equals($secret, $given)) json_error('无权调用', 403);
    $r = save_upload((string)input('data', ''), (string)input('kind', 'image'), (string)input('filename', ''));
    $main = rtrim((string)($cfg['site_main_url'] ?? ''), '/');
    if ($main !== '' && isset($r['url']) && str_starts_with($r['url'], '/uploads/')) $r['url'] = $main . $r['url'];
    json_out($r);
}

/** 客服登录入口列表（卡密交付/帮助文案里按 入口1、入口2… 展示；kv 可配，缺省主站登录页） */
function agent_entrances(): array
{
    $list = [];
    try {
        $raw = kv_get('agent_entrances', '');
        if ($raw !== '') {
            $arr = json_decode($raw, true);
            if (is_array($arr)) $list = array_values(array_filter(array_map('trim', $arr), fn($u) => is_string($u) && $u !== ''));
        }
    } catch (\Throwable $e) {}
    if (!$list) $list = [site_main_url() . '/login.html'];
    return $list;
}

/** 主控侧：给分控节点的更新包（version 不同才发全量；config.php 永不下发） */
function handle_internal_update(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $cfg = config();
    if (($cfg['site_role'] ?? 'main') !== 'main') json_error('接口不存在', 403);
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($secret === '') json_error('未启用更新分发', 403);
    $given = (string)input('secret', '');
    if ($given === '' || !hash_equals($secret, $given)) json_error('无权调用', 403);
    $nodeVer = (string)input('app_version', '');
    $role = in_array(($r = (string)input('role', '')), ['agent', 'visitor'], true) ? $r : '';
    /* v2.49 静态包必须节点显式申请（static=1，纯静态 bash 节点用）；
       PHP 边缘站（update-node.php / 惰性维护）不发 static → 拿全量包，PHP 才能跟着升级。
       壳页指纹 codes_rev：发版后新增的客服码壳页也能即时下发，不用等下一次发版。 */
    $static = (string)input('static', '') === '1';
    $bundle = node_bundle_build($role, $static);
    $nodeRev = trim((string)input('codes_rev', ''));
    // 版本和壳页指纹都一致才算最新——发版后新码也能 ≤5 分钟自动到节点
    if ($nodeVer === (string)$bundle['version'] && $nodeRev === (string)$bundle['codes_rev']) {
        json_out(['ok' => true, 'up_to_date' => true, 'version' => $bundle['version']]);
    }
    // bash 节点（cron-node.sh）：要 tar.gz 包（curl+tar 即可应用，无需 PHP）
    if ((string)input('format', '') === 'tgz') {
        $tmp = tempnam(sys_get_temp_dir(), 'upk');
        $phar = new PharData($tmp . '.tar');
        foreach ($bundle['files'] as $path => $b64) {
            $tmpF = $tmp . '.f';
            file_put_contents($tmpF, base64_decode((string)$b64));
            $phar->addFromString($path, (string)file_get_contents($tmpF));
            @unlink($tmpF);
        }
        $phar->compress(Phar::GZ);
        $tgz = $tmp . '.tar.gz';
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="update-' . $bundle['version'] . '.tar.gz"');
        echo (string)file_get_contents($tgz);
        @unlink($tgz); @unlink($tmp . '.tar');
        exit;
    }
    json_out(['ok' => true, 'version' => $bundle['version']] + $bundle);
}

/** 统一版本号（version.php；主站与分控节点对版本用） */
function app_version(): string
{
    static $v = null;
    if ($v === null) {
        try { $v = (string)(require __DIR__ . '/version.php'); } catch (\Throwable $e) { $v = ''; }
    }
    return $v;
}

/** 分控节点心跳（cron 每次拉码后调用）：登记 IP/角色/版本，主控「节点管理」页展示 */
function handle_internal_heartbeat(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $cfg = config();
    if (($cfg['site_role'] ?? 'main') !== 'main') json_error('接口不存在', 403);
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($secret === '') json_error('未启用节点登记', 403);
    $given = (string)input('secret', '');
    if ($given === '' || !hash_equals($secret, $given)) json_error('无权调用', 403);
    $ip = (string)input('ip', '');
    if ($ip === '') $ip = get_client_ip();
    if (!preg_match('/^[0-9a-fA-F.:]{3,45}$/', $ip)) json_error('IP 格式异常');
    $role = in_array(($r = (string)input('role', '')), ['agent', 'visitor'], true) ? $r : '';
    $ver = mb_substr(trim((string)input('app_version', '')), 0, 32);
    $origin = mb_substr(trim((string)input('origin', '')), 0, 200);
    $pdo = db();
    /* v2.51 空 app_version 不覆盖登记值：纯静态节点没有 version.php（cron 心跳带空版本），
       不能把节点管理页已知的真实版本抹掉，否则页面无从判断新旧 */
    /* v2.60.4 同理：cron 心跳不带 origin（脚本里没有请求上下文），空值也不能把已登记的
       来源地址抹掉 —— 节点管理页「来源」那一列会突然变「—」。 */
    $pdo->prepare('INSERT INTO nodes (ip, role, app_version, origin, first_seen, last_seen)
                   VALUES (?,?,?,?,NOW(),NOW())
                   ON DUPLICATE KEY UPDATE last_seen = NOW()'
                   . ($origin !== '' ? ', origin = VALUES(origin)' : '')
                   . ($ver !== '' ? ', app_version = VALUES(app_version)' : ''))
        ->execute([$ip, $role, $ver, $origin]);
    json_out(['ok' => true, 'main_version' => app_version()]);
}

/** 更新包白名单（分控节点需要的代码路径；config.php/数据/上传/安装器永不下发） */
function node_bundle_prefixes(): array
{
    return ['assets/', 'public/', 'routes/', 'vendor/', 'node/', 'index.php', 'helpers.php', 'db.php', 'version.php'];
}

/** 主控侧：按白名单打包当前代码（version + files{path:base64}）
 *  $static=true：纯静态节点包——无 PHP、根路径页副本、客服码壳页落成真实文件（含 codes_rev 指纹） */
function node_bundle_build(string $role = '', bool $static = false): array
{
    $base = __DIR__;
    $files = [];
    $staticMode = $static;   // v2.49：由节点显式申请，不再按角色隐式开启（防 PHP 边缘站拿到无 PHP 包被清成砖）
    $mainBase = rtrim((string)(config()['site_main_url'] ?? ''), '/');
    $dirIter = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($dirIter, function ($cur) {
        if ($cur->isDir()) return !in_array($cur->getFilename(), ['.git', 'data', 'uploads'], true);
        return true;
    });
    $iter = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
    $mainBase = rtrim((string)config()['site_main_url'] ?? '', '/');
    foreach ($iter as $f) {
        $path = str_replace(chr(92), '/', substr($f->getPathname(), strlen($base) + 1));
        $keep = false;
        foreach (node_bundle_prefixes() as $pre) {
            if ($pre === 'node/' && str_starts_with($path, 'node/')) { $keep = str_ends_with($path, 'cron-node.sh') || str_ends_with($path, 'update-node.php'); continue; }
            if (str_starts_with($path, $pre)) { $keep = true; break; }
        }
        if (!$keep) continue;
        if (str_contains($path, '/.') || str_ends_with($path, '.log')) continue;
        // 静态边缘模式：PHP 文件不下发（节点为纯静态站点）
        // v2.60.3 例外：version.php 要下发。纯静态节点的 version.php 是历史遗留文件，
        //   不下发就会永远停在旧版本号 → 每 5 分钟都判定「版本不同」重新下载整包（约 1MB）
        //   并且重解压一次（解压被打断就会解出半新半旧的站点）。下发后节点版本号能自然对齐。
        if ($staticMode && str_ends_with($path, '.php') && $path !== 'version.php') continue;
        $files[$path] = base64_encode((string)file_get_contents($f->getPathname()));
    }
    /* 根路径页面副本（注入总控地址）+ 节点配置 JS：**所有**节点包都下发，不再区分静态/普通包。
       v2.60.5 修：边缘节点 nginx 是 `try_files $uri $uri/ /index.php`，
       只要站点根目录存在 visitor.html，nginx 就直接当静态文件发出去（请求根本到不了 PHP）。
       以前只有「静态包」带根副本，普通包只带 public/*.html —— 于是 PHP 型节点的根副本
       永远停在建站那天（现场：某节点 visitor.js 停在 2.14.2、app.css 停在 3.31.0，
       而它自报的版本号却是新的，光看「节点管理」根本发现不了）。 */
    $inject = '<script src="/edge-config.js"></script>';
    foreach (['login', 'app', 'visitor', 'gate'] as $pg) {
        /* v2.65 壳页不再用文件：烤制 XOR 自解密 loader（静态包跨域直连总控，PHP 包同源）；
         * v2.67 访客页烤制为内联加密版（页面源码无明文业务逻辑） */
        if ($pg === 'gate') {
            $files['gate.html'] = base64_encode(gate_loader_html($staticMode ? $mainBase : ''));
            continue;
        }
        if ($pg === 'visitor') {
            $files['visitor.html'] = base64_encode(visitor_page_packed((string)file_get_contents($base . '/public/visitor.html')));
            continue;
        }
        $src = $base . '/public/' . $pg . '.html';
        if (!is_file($src)) continue;
        $html = (string)file_get_contents($src);
        if (str_contains($html, '<head')) $html = preg_replace('/<head[^>]*>/i', '<head>' . $inject, $html, 1);
        else $html = $inject . $html;
        $files[$pg . '.html'] = base64_encode($html);
    }
    $files['edge-config.js'] = base64_encode(
        'window.KF_API_BASE=' . json_encode($mainBase) . ';window.KF_EDGE_ROLE=' . json_encode($role) . ';');
    if ($staticMode) {
        // index.html 只在静态包下发：PHP 节点要靠 index.php 兜底路由，不能被 index.html 抢走
        $files['index.html'] = base64_encode($role === 'agent'
            ? '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><script src="/edge-config.js"></script><script>location.replace("/login.html");</script></head><body></body></html>'
            : '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><script src="/edge-config.js"></script><script>location.replace("/visitor.html");</script></head><body></body></html>');
        // 总控已落地的防红壳页静态文件（发码时写入 MM-DD/ 目录）
        foreach (glob($base . '/[0-9][0-9]-[0-9][0-9]/*.html') ?: [] as $gf) {
            $rel = str_replace(chr(92), '/', substr($gf, strlen($base) + 1));
            $files[$rel] = base64_encode((string)file_get_contents($gf));
        }
    }
    /* v2.50→v2.65 客服码防红壳页随所有更新包下发为真实静态文件：节点 nginx 若无动态回退，
       /MM-DD/xxx.html 只有文件存在才可达——放全量包里，节点用现有自动拉码通道即可自愈，
       新码 ≤5 分钟自动到（codes_rev 指纹），零手工零发版。壳页统一烤制为 XOR 自解密 loader
       （静态包跨域直连总控；PHP 包同源经 /api/ 反代）。 */
    $inject = '<script src="/edge-config.js"></script>';
    $shellPaths = [];
    $gateHtml = gate_loader_html($staticMode ? $mainBase : '');
    try {
        foreach (db()->query('SELECT link FROM service_codes')->fetchAll() as $scRow) {
            $lp = (string)(parse_url((string)$scRow['link'], PHP_URL_PATH) ?: '');
            if (preg_match('#^/[0-9]{2}-[0-9]{2}/[A-Za-z0-9]{4,16}\.html$#', $lp)) {
                $lp = ltrim($lp, '/');
                $files[$lp] = base64_encode($gateHtml);
                $shellPaths[] = $lp;
            }
        }
    } catch (\Throwable $e) { /* 壳页打包失败不阻塞更新包 */ }
    $codesRev = $shellPaths ? md5(implode("\n", array_unique($shellPaths))) : '';
    if ($staticMode && $codesRev !== '') $files['data/node-codes-rev.txt'] = base64_encode($codesRev);
    /* v2.68 内容页伪装路径：每个落地域名固定一批 /web/data/YYYY/MM/DD/<uuid>.html（存 kv_store），
       更新包里烤成真实静态文件 —— 静态节点（无 PHP）也能直接命中，壳页拿 /api/gate 下发的
       地址就能进聊天页；总控侧 gate_content_url() 只从同一份清单里挑，两边永远一致。 */
    try {
        $visitorPacked = visitor_page_packed((string)file_get_contents($base . '/public/visitor.html'));
        foreach (db()->query('SELECT domain FROM domain_pool WHERE enabled = 1') as $dpRow) {
            $dom = strtolower(trim((string)$dpRow['domain']));
            if ($dom === '') continue;
            foreach (gate_content_paths($dom) as $cp) {
                $rel = ltrim($cp, '/');
                if (!isset($files[$rel])) $files[$rel] = base64_encode($visitorPacked);
            }
        }
    } catch (\Throwable $e) { /* 无库/打包失败不阻塞更新包 */ }
    /* v2.67 直读 assets/js/visitor.js 的 URL 也只拿得到加密版（可读源码不再出现在任何节点） */
    $visitorSrc = @file_get_contents($base . '/assets/js/visitor.js');
    if ($visitorSrc !== false && $visitorSrc !== '') $files['assets/js/visitor.js'] = base64_encode(pack_js($visitorSrc));
    ksort($files);
    return ['version' => app_version(), 'files' => $files, 'static' => $staticMode, 'codes_rev' => $codesRev];
}

/** 分控侧：应用更新包（受保护路径永不覆盖；按包清理已移除文件） */
function apply_node_bundle(array $bundle): array
{
    $base = __DIR__;
    $protected = fn(string $p): bool => $p === 'config.php' || str_starts_with($p, 'data/') || str_starts_with($p, 'uploads/');
    $files = (array)($bundle['files'] ?? []);
    if (!$files) throw new RuntimeException('更新包为空');
    $manifestFile = $base . '/data/node-manifest.json';
    /* v2.48 修 bug：清单文件里存的是「路径数组」（json_encode(array_keys(...))），
       直接 (array)json_decode 得到的键是 0/1/2… 不是路径，下面 array_keys($applied) 就会把 int 当路径传给
       $protected(string $p) → PHP 8 严格类型下 TypeError → **节点从第二次更新起永远失败**（表现：卡在旧版本、
       手动「推送更新」报 500）。这里统一归一化成 路径=>1 的映射，兼容旧的数组格式。 */
    $raw = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : [];
    $applied = [];
    if (is_array($raw)) {
        foreach ($raw as $k => $v) {
            $path = is_string($k) ? $k : (is_string($v) ? $v : '');
            if ($path !== '') $applied[$path] = 1;
        }
    }
    if (!is_dir($base . '/data')) @mkdir($base . '/data', 0755, true);
    /* v2.49 静态包不含 PHP：绝不能触发清理，否则会把 PHP 边缘站之前应用的 .php 全删成砖 */
    $isStaticBundle = !empty($bundle['static']);
    $changed = 0;
    foreach ($files as $path => $b64) {
        if ($protected($path)) continue;
        $full = $base . '/' . $path;
        if (str_contains($path, '/')) {
            $d = dirname($full);
            if (!is_dir($d)) @mkdir($d, 0755, true);
        }
        $content = base64_decode((string)$b64, true);
        if ($content === false) throw new RuntimeException('解码失败: ' . $path);
        // 原子写入：先写临时文件再 rename，避免覆盖正在执行的脚本导致 500
        $tmp = $full . '.nodeup';
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $full)) throw new RuntimeException('写入失败: ' . $path);
        $applied[$path] = 1;
        $changed++;
    }
    // 清理主站已删除、而本节点还在的文件（仅全量包；静态包只增不删）
    if (!$isStaticBundle) {
        foreach (array_keys($applied) as $old) {
            if ($protected($old)) continue;
            if (str_starts_with($old, '_')) continue;   // 元数据键（_codes_rev 等）不是文件
            if (!isset($files[$old]) && is_file($base . '/' . $old)) { @unlink($base . '/' . $old); unset($applied[$old]); }
        }
    }
    if ($isStaticBundle) $applied['_codes_rev'] = (string)($bundle['codes_rev'] ?? '');
    @file_put_contents($manifestFile, json_encode($applied, JSON_UNESCAPED_UNICODE));
    // 壳页指纹落盘：节点侧（PHP 惰性维护 / update-node.php）下次拉码时回传，码变了即时发包
    if (($bundle['codes_rev'] ?? '') !== '') {
        @file_put_contents($base . '/data/node-codes-rev.txt', (string)$bundle['codes_rev']);
    }
    return ['ok' => true, 'changed' => $changed, 'app_version' => (string)($bundle['version'] ?? app_version())];
}

/** 分控节点侧：接收主控推送的更新包（共享密钥校验，受保护路径不覆盖） */
function handle_internal_apply(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $cfg = config();
    /* v2.51 总控绝不把远程推送应用到自身：节点 nginx 把 /api/* 反代回总控时，
       「推送更新」会打到总控这里——若不拦截，总控静默自应用并回报成功，节点什么都没收到，
       节点管理页从此显示假版本。拦下后推送方拿到明确报错。 */
    if (($cfg['site_role'] ?? 'main') === 'main') json_error('目标不是节点（/api 被反代回总控），推送无法直达该节点', 400);
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($secret === '') json_error('未启用远程更新', 403);
    $given = (string)input('secret', '');
    if ($given === '' || !hash_equals($secret, $given)) json_error('无权调用', 403);
    $bundle = (array)input('bundle', []);
    json_out(apply_node_bundle($bundle));
}

/* ================= 枢纽模式：内存态 / 白名单 / 黑名单同步（v2.34） ================= */

/** 边缘节点惰性维护（每 5 分钟一次，随流量触发，无需 cron）：
 *  1) 向总控检查版本并应用更新包（self-update）  2) 黑名单/归属同步落到 data/blcache.json
 *  3) 向总控登记节点（IP+角色，边缘自动入节点管理列表） */
function edge_maintenance(): void
{
    $lockFile = __DIR__ . '/data/edge-maint.lock';
    $now = time();
    if (is_file($lockFile) && ($now - (int)file_get_contents($lockFile)) < 290) return; // 5 分钟节流
    @file_put_contents($lockFile, (string)$now);
    $cfg = config();
    $main = rtrim((string)($cfg['site_main_url'] ?? ''), '/');
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($main === '' || $secret === '') return;
    $post = function (string $path, array $body): array {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if (function_exists('curl_init')) {
            $ch = curl_init($path);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload]);
            $resp = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json
", 'content' => $payload, 'timeout' => 60, 'ignore_errors' => true]]);
            $resp = @file_get_contents($path, false, $ctx);
        }
        $j = json_decode((string)$resp, true);
        return is_array($j) ? $j : [];
    };
    // 1) 更新检查（总控版本不同或壳页指纹变化 → 返回更新包并应用）
    $justApplied = '';   // v2.60.4：本轮刚应用的版本，心跳要用它，别用缓存的旧值
    try {
        $rev = @file_get_contents(__DIR__ . '/data/node-codes-rev.txt');
        $j = $post($main . '/api/internal/update', ['secret' => $secret, 'app_version' => app_version(),
            'role' => ($cfg['site_role'] ?? ''), 'codes_rev' => trim((string)$rev)]);
        if (!empty($j['files'])) {
            apply_node_bundle($j);
            $justApplied = (string)($j['version'] ?? '');
        }
    } catch (\Throwable $e) { /* 静默：下轮重试 */ }
    // 2) 黑名单同步
    try {
        $j = $post($main . '/api/internal/sync', ['secret' => $secret]);
        if (isset($j['bl_version'])) @file_put_contents(__DIR__ . '/data/blcache.json', json_encode($j, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) { /* 静默 */ }
    // 3) 节点登记（IP+角色，幂等）
    try {
        $origin = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        /* v2.60.4 修：app_version() 在同一个 PHP 请求里是 static 缓存，上面已经取过一次旧版本，
           更新后这里再读还是旧版本号 —— 节点刚更新完却上报「落后」，节点管理页要等下一轮才变绿。
           刚应用过就直接用更新包里的版本号。 */
        $post($main . '/api/internal/heartbeat', ['secret' => $secret, 'role' => ($cfg['site_role'] ?? ''),
            'app_version' => $justApplied !== '' ? $justApplied : app_version(), 'origin' => $origin, 'ip' => '']);
    } catch (\Throwable $e) { /* 静默 */ }
}

/** 边缘 API 转发兜底：把 /api/* 请求原样转发到总控并回传响应
 *  （nginx 已配 /api/ 反代时请求到不了这里；未配置时由此兜底，边缘零 nginx 配置也能用） */
function edge_relay_api(string $uri): void
{
    $cfg = config();
    $main = rtrim((string)($cfg['site_main_url'] ?? ''), '/');
    if ($main === '') { http_response_code(404); echo 'Not Found'; exit; }
    $qs = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            // 剥离 Host/XFF/X-Edge-* 与逐跳头（Accept-Encoding/Connection 等），避免压缩/连接态错乱
            if (in_array($name, ['Host', 'X-Forwarded-For', 'X-Edge-Secret', 'X-Edge-Role', 'X-Edge-Origin',
                'Accept-Encoding', 'Connection', 'Keep-Alive', 'Transfer-Encoding', 'TE', 'Upgrade', 'Content-Length'], true)) continue;
            $headers[] = $name . ': ' . $v;
        }
    }
    $headers[] = 'X-Forwarded-For: ' . get_client_ip();
    $payload = $method !== 'GET' && $method !== 'HEAD' ? file_get_contents('php://input') : null;
    $ch = curl_init($main . $uri . $qs);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json');
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false && $err !== '') { http_response_code(502); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => '节点转发失败: ' . $err]); exit; }
    http_response_code($code);
    header('Content-Type: ' . $ctype);
    echo (string)$resp;
    exit;
}

/** 边缘本地黑名单拦截：读 data/blcache.json（总控同步），全局 + 当前租户 双层判定 */
function edge_ip_blocked(): bool
{
    $file = __DIR__ . '/data/blcache.json';
    if (!is_file($file)) return false;
    $j = json_decode((string)file_get_contents($file), true);
    if (!is_array($j)) return false;
    $ip = get_client_ip();
    if ($ip === '' ) return false;
    foreach ((array)($j['global'] ?? []) as $g) {
        if ((string)($g['ip'] ?? '') === $ip) return true;
    }
    // 当前租户：?t=卡密 或 ?key=客服码 反查 tenant_id
    $tid = 0;
    $qs = $_GET;
    if (!empty($qs['t']) && isset($j['kamiById'])) {
        foreach ((array)$j['kamiById'] as $tidK => $kami) { if ((string)$kami === (string)$qs['t']) { $tid = (int)$tidK; break; } }
    } elseif (!empty($qs['key']) && isset($j['codeTid'])) {
        $tid = (int)($j['codeTid'][(string)$qs['key']] ?? 0);
    }
    if (!$tid) return false;
    return in_array($ip, (array)($j['tenants'][$tid] ?? []), true);
}

/** 主控侧：识别带 X-Edge-Secret 的分控反代请求——自动登记/刷新节点（IP+角色） */
function edge_touch_from_headers(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $cfg = config();
    if (($cfg['site_role'] ?? 'main') !== 'main') return;
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($secret === '') return;
    $given = (string)($_SERVER['HTTP_X_EDGE_SECRET'] ?? '');
    if ($given === '' || !hash_equals($secret, $given)) return;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) return;
    $role = in_array(($r = (string)($_SERVER['HTTP_X_EDGE_ROLE'] ?? '')), ['agent', 'visitor'], true) ? $r : '';
    $origin = mb_substr(trim((string)($_SERVER['HTTP_X_EDGE_ORIGIN'] ?? '')), 0, 200);
    try {
        db()->prepare('INSERT INTO nodes (ip, role, app_version, origin, first_seen, last_seen)
                       VALUES (?,?,?,?,NOW(),NOW())
                       ON DUPLICATE KEY UPDATE app_version = VALUES(app_version),
                                               origin = VALUES(origin), last_seen = NOW()')
            ->execute([$remote, $role, app_version(), $origin]);
    } catch (\Throwable $e) { /* 登记失败不影响请求 */ }
}

/** 总控内存能力探测（APCu 可用时才启用内存态：在线表 / 消息缓存）；不可用则自动退回数据库路径 */
function mem_ok(): bool
{
    static $ok = null;
    if ($ok === null) $ok = function_exists('apcu_enabled') && @apcu_enabled();
    return $ok;
}
function mem_get(string $k) { $v = null; if (mem_ok()) { $hit = false; $v = apcu_fetch($k, $hit); if (!$hit) $v = null; } return $v; }
function mem_set(string $k, $v, int $ttl = 0): bool { return mem_ok() ? apcu_store($k, $v, $ttl) : false; }
function mem_del(string $k): void { if (mem_ok()) apcu_delete($k); }

/** 在线状态：只记在线的（key => {node, since}）；下线/超时即删，不占内存 */
function presence_touch(string $key): void
{
    if (!mem_ok() || $key === '') return;
    $p = mem_get('presence') ?: [];
    $p[$key] = ['node' => $_SERVER['REMOTE_ADDR'] ?? '', 'since' => time()];
    if (count($p) > 5000) { // 硬上限保护
        $now = time();
        $p = array_filter($p, fn($e) => ($now - (int)($e['since'] ?? 0)) <= 75);
    }
    mem_set('presence', $p, 600);
}
function presence_off(string $key): void { $p = mem_get('presence') ?: []; if (isset($p[$key])) { unset($p[$key]); mem_set('presence', $p, 600); } }
function presence_prune(int $maxAgeSecs = 75): void
{
    if (!mem_ok()) return;
    $p = mem_get('presence') ?: [];
    $now = time();
    $p2 = array_filter($p, fn($e) => ($now - (int)($e['since'] ?? 0)) <= $maxAgeSecs);
    if (count($p2) !== count($p)) mem_set('presence', $p2, 600);
}
function presence_all(): array { return mem_ok() ? (array)(mem_get('presence') ?: []) : []; }

/** 主控信任的分控边缘 IP 白名单（节点登记表；边缘转发的访客真实 IP 只信任这些来源） */
function trusted_edge_ips(): array
{
    static $ips = null;
    if ($ips === null) {
        $ips = [];
        try {
            $rows = db()->query('SELECT ip FROM nodes')->fetchAll(PDO::FETCH_COLUMN);
            $ips = array_map('strval', $rows);
        } catch (\Throwable $e) { $ips = []; }
    }
    return $ips;
}

/** 节点授权码：校验并一次性消费（used_at/used_ip 落库），登记节点并返回节点凭证包 */
function handle_internal_node_register(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $cfg = config();
    if (($cfg['site_role'] ?? 'main') !== 'main') json_error('接口不存在', 403);
    $code = strtoupper(trim((string)input('auth_code', '')));
    if (!preg_match('/^[A-Z0-9]{8,32}$/', $code)) json_error('授权码格式不正确');
    $pdo = db();
    $st = $pdo->prepare('SELECT role, used_ip FROM node_auth_codes WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    if (!$row) json_error('授权码不存在');
    if (!empty($row['used_ip'])) json_error('授权码已被使用');
    $nodeIp = get_client_ip();
    $role = in_array(($rr = (string)input('role', $row['role'])), ['agent', 'visitor'], true) ? $rr : 'agent';
    $origin = mb_substr(trim((string)input('origin', '')), 0, 200);
    $pdo->prepare('UPDATE node_auth_codes SET used_at = NOW(), used_ip = ? WHERE code = ?')->execute([$nodeIp, $code]);
    $pdo->prepare('INSERT INTO nodes (ip, role, app_version, origin, first_seen, last_seen)
                   VALUES (?,?,?,?,NOW(),NOW())
                   ON DUPLICATE KEY UPDATE app_version = VALUES(app_version),
                                           origin = VALUES(origin), last_seen = NOW()')
        ->execute([$nodeIp, $role, '', $origin]);   // 节点真实版本未知（装完首次同步才知道），记空使总控按落后处理
    json_out([
        'ok'          => true,
        'role'        => $role,
        'main_url'    => site_main_url(),
        'relay_secret'=> (string)($cfg['relay_secret'] ?? ''),
        'app_version' => app_version(),
    ]);
}

/** 边缘同步：全局黑名单 + 租户黑名单 + 码/卡密归属（供边缘本地拦截） */
function handle_internal_sync(string $method): void
{
    if ($method !== 'GET' && $method !== 'POST') { json_error('方法不允许', 405); return; }
    $cfg = config();
    if (($cfg['site_role'] ?? 'main') !== 'main') json_error('接口不存在', 403);
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($secret === '') json_error('未启用同步', 403);
    $given = (string)input('secret', '');
    if ($given === '' || !hash_equals($secret, $given)) json_error('无权调用', 403);
    $pdo = db();
    $global = $pdo->query('SELECT ip, note FROM global_blacklist')->fetchAll();
    $rows = $pdo->query('SELECT tenant_id, ip FROM ip_blacklist')->fetchAll();
    $tenants = [];
    foreach ($rows as $r2) $tenants[(int)$r2['tenant_id']][] = $r2['ip'];
    $kamiMap = $pdo->query('SELECT id, kami FROM tenants')->fetchAll();
    $kamiById = [];
    foreach ($kamiMap as $k) $kamiById[(int)$k['id']] = (string)$k['kami'];
    $codeMap = $pdo->query("SELECT code, tenant_id FROM service_codes")->fetchAll();
    $codeTid = [];
    foreach ($codeMap as $c) $codeTid[(string)$c['code']] = (int)$c['tenant_id'];
    json_out([
        'bl_version' => (int)kv_get('bl_version', '1'),
        'global'     => $global,
        'tenants'    => $tenants,   // {tenant_id: [ip,...]}
        'kamiById'   => $kamiById,  // {tenant_id: 卡密}（边缘用 ?t=卡密 反查）
        'codeTid'    => $codeTid,   // {客服码: tenant_id}（边缘用 ?key= 反查）
    ]);
}

/** 黑名单版本号 +1（任何黑名单写操作后调用，边缘据此感知变化） */
function bl_touch(): void { kv_set('bl_version', (string)time()); }

function save_upload(string $data, string $kind, string $filename = ''): array
{
    $cfg = config();
    // 1+2+n：非主站节点把文件中转到主站，返回绝对 URL（保证任意节点可见）
    if (($cfg['site_role'] ?? 'main') !== 'main') return relay_upload_to_main($data, $kind, $filename);
    // 枢纽模式：总控配置了 site_main_url 时，上传 URL 直接绝对化——
    // 消息附件存在库里，任何节点/任何域名的页面都能直接引用（跨节点可见）
    $urlBase = $cfg['upload_url'];
    if (!empty($cfg['site_main_url'])) $urlBase = rtrim((string)$cfg['site_main_url'], '/') . $cfg['upload_url'];
    $dir = $cfg['upload_dir'];
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $payload = $data;
    $mime = '';
    if (preg_match('#^data:([a-zA-Z0-9.+\-/_]+);base64,(.+)$#s', $data, $m)) {
        $mime = strtolower($m[1]);
        $payload = $m[2];
    }
    $buffer = base64_decode($payload, true);
    if ($buffer === false || $buffer === '') throw new \RuntimeException('文件内容为空');

    // v2.43：新增视频 / 通用文件上传（默认 20MB，可在 config.php 覆盖）
    $limitImage = (int)($cfg['upload_max_image'] ?? 5 * 1024 * 1024);
    $limitAudio = (int)($cfg['upload_max_audio'] ?? 10 * 1024 * 1024);
    $limitMedia = (int)($cfg['upload_max_media'] ?? 20 * 1024 * 1024);
    $maxBytes = $kind === 'audio' ? $limitAudio : (in_array($kind, ['video', 'file'], true) ? $limitMedia : $limitImage);
    if (strlen($buffer) > $maxBytes) {
        $mb = (int)round($maxBytes / 1024 / 1024);
        $label = $kind === 'audio' ? '语音' : ($kind === 'video' ? '视频' : ($kind === 'file' ? '文件' : '图片'));
        throw new \RuntimeException($label . '不能超过 ' . $mb . 'MB');
    }

    $isImage = (bool)preg_match('#^image/(png|jpe?g|gif|webp)$#i', $mime);
    $isAudio = (bool)preg_match('#^(audio/|video/webm)#i', $mime);
    $isVideo = (bool)preg_match('#^video/#i', $mime);

    // 服务器禁用 shell_exec，语音不做转码，直接保存原始格式（前端选可播格式录制）
    if ($kind === 'image' && $isImage) {
        $ext = str_ireplace('jpeg', 'jpg', explode('/', $mime)[1]);
        $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $buffer) === false) throw new \RuntimeException('写入文件失败');
        return ['type' => 'image', 'url' => $urlBase . '/' . $name, 'size' => strlen($buffer)];
    }

    if ($kind === 'audio' && $isAudio) {
        $ext = audio_ext_of($mime, $filename);
        $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $buffer) === false) throw new \RuntimeException('写入文件失败');
        return ['type' => 'audio', 'url' => $urlBase . '/' . $name, 'size' => strlen($buffer)];
    }

    // 视频：只放行常见可播格式，前端用 <video> 直接播
    if ($kind === 'video' && ($isVideo || preg_match('#^application/octet-stream$#i', $mime))) {
        $ext = media_ext_of($mime, $filename, ['mp4', 'webm', 'ogg', 'mov', 'm4v'], 'mp4');
        $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $buffer) === false) throw new \RuntimeException('写入文件失败');
        return ['type' => 'video', 'url' => $urlBase . '/' . $name, 'size' => strlen($buffer),
            'name' => $filename !== '' ? $filename : ('视频.' . $ext)];
    }

    // 通用文件：保留原扩展名（白名单外的扩展名降级为 .bin，避免落可执行脚本）
    if ($kind === 'file') {
        $orig = $filename !== '' ? $filename : 'file.bin';
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!preg_match('#^[a-z0-9]{1,8}$#', $ext)) $ext = 'bin';
        $safeExt = [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', '7z',
            'mp3', 'm4a', 'wav', 'mp4', 'mov', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'apk', 'bin',
        ];
        if (!in_array($ext, $safeExt, true)) $ext = 'bin';
        $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $buffer) === false) throw new \RuntimeException('写入文件失败');
        return ['type' => 'file', 'url' => $urlBase . '/' . $name, 'size' => strlen($buffer), 'name' => $orig];
    }

    throw new \RuntimeException('文件格式不支持：' . ($mime ?: '未知'));
}

/** 视频/文件的扩展名推断：优先取原文件名，其次 mime 子类型，最后给默认值 */
function media_ext_of(string $mime, string $filename, array $allow, string $fallback): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, $allow, true)) return $ext;
    if (preg_match('#^[a-z]+/([a-z0-9.+\-]+)#i', $mime, $m)) {
        $sub = strtolower($m[1]);
        if (in_array($sub, $allow, true)) return $sub;
        if ($sub === 'quicktime') return 'mov';
        if ($sub === 'x-matroska') return 'mp4';
    }
    return $fallback;
}

/* ================= IP 归属地（国内库优先 + ip-api 回退，带文件缓存） =================
   统一输出格式：国家|地区|城市|运营商|国家码（缺失项用 0 占位） */

/** 简单 HTTP GET（3 秒超时，失败返回空串） */
function geo_http_get(string $url, int $timeout = 3): string
{
    if (!function_exists('curl_init')) return (string)@file_get_contents($url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; kfxt/2.36)',
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return $body;
}

/** 运营商名归一：把 "联通ADSL / 移通 / 电信3G" 等收敛成 电信/联通/移动/铁通/广电 */
function geo_isp_norm(string $raw): string
{
    foreach (['电信' => '电信', '联通' => '联通', '网通' => '联通', '移通' => '移动', '移动' => '移动',
              '铁通' => '铁通', '广电' => '广电', '教育网' => '教育网', '鹏博士' => '鹏博士'] as $k => $v) {
        if (mb_strpos($raw, $k) !== false) return $v;
    }
    return '';
}

/** 国内库（太平洋电脑网 whois）：城市可到区县，带运营商；海外/失败返回空 */
function geo_lookup_cn(string $ip): string
{
    $body = geo_http_get('https://whois.pconline.com.cn/ipJson.jsp?ip=' . urlencode($ip) . '&json=true', 3);
    if ($body === '') return '';
    if (function_exists('mb_convert_encoding')) $body = mb_convert_encoding($body, 'UTF-8', 'GBK,GB2312,UTF-8');
    $d = json_decode($body, true);
    /* v2.89.4 以前 err=nocity（移动/城域网 IP 拿不到市）就整条丢掉，连省都浪费了；
       现在只要拿到省就返回，市留空 —— 交给多源投票去补。海外（err=noprovince，pro 为空）仍算无数据。 */
    if (!is_array($d) || ($d['pro'] ?? '') === '') return '';
    $pro  = geo_clean((string)$d['pro']);
    $city = geo_clean((string)$d['city']);
    $reg  = geo_clean((string)$d['region']);       // 区县（如 普宁市）
    $addr = trim((string)($d['addr'] ?? ''));
    $isp  = geo_isp_norm($addr);
    if ($city === '' && $reg !== '') $city = $reg;  // 只有区县时用区县
    return implode('|', ['中国', $pro !== '' ? $pro : '0', $city !== '' ? $city : '0',
        $isp !== '' ? $isp : '0', 'CN']);
}

/** v2.89.4 国内第二源：纯真 IP 库（ip.zxinc.org，免费）。省市分辨率稳，海外偶尔会误判成国内。 */
function geo_lookup_zxinc(string $ip): string
{
    $body = geo_http_get('https://ip.zxinc.org/api.php?type=json&ip=' . urlencode($ip), 3);
    if ($body === '') return '';
    $d = json_decode($body, true);
    if (!is_array($d) || (int)($d['code'] ?? -1) !== 0 || !is_array($d['data'] ?? null)) return '';
    $loc = (string)($d['data']['location'] ?? '');
    if ($loc === '') return '';
    $loc = str_replace(['–', '—'], '-', $loc);
    $seg = array_values(array_filter(array_map('trim', explode('-', $loc)), fn($v) => $v !== ''));
    if (!$seg) return '';
    /* 纯真的 location 形如「中国-河南-洛阳 联通」「中国-山西 移动」：末段末尾常常缀着运营商，
       先把它拆出来，否则会被当成省份/城市名（省份投票会因此投错）。 */
    $ispRaw = '';
    $lastIdx = count($seg) - 1;
    if ($lastIdx > 0 && preg_match('/^(.*?)\s+(\S+)$/u', (string)$seg[$lastIdx], $m)
        && preg_match('/电信|联通|移动|铁通|广电|教育网|鹏博士|BGP|IDC|数据中心/u', (string)$m[2])) {
        $ispRaw = (string)$m[2];
        $seg[$lastIdx] = trim((string)$m[1]);
    }
    $countryRaw = geo_clean((string)$seg[0]);
    $isCn = $countryRaw === '' || mb_strpos($countryRaw, '中国') !== false;
    $prov = geo_clean((string)($seg[1] ?? ''));
    $city = geo_clean((string)($seg[2] ?? ''));
    $extra = (string)($seg[3] ?? '');
    if ($ispRaw === '' && $extra !== '') {
        $sp = preg_split('/\s+/u', $extra);
        $ispRaw = (string)end($sp);
    }
    if (!preg_match('/电信|联通|移动|铁通|广电|教育网|鹏博士/u', $ispRaw)) {
        $ispRaw = (string)($d['data']['local'] ?? '');
    }
    $isp = geo_isp_norm($ispRaw);
    if (!$isCn && $prov === '' && $city === '') return '';
    return implode('|', [$isCn ? '中国' : ($countryRaw !== '' ? $countryRaw : '0'),
        $prov !== '' ? $prov : '0', $city !== '' ? $city : '0', $isp !== '' ? $isp : '0', $isCn ? 'CN' : '0']);
}

/** v2.89.4 多个国内源合并：省份取多数票（平票优先太平洋），城市只在「同省且不冲突」时才给。
 *  $a=太平洋 $b=纯真 $c=ip-api（可为 null）。返回统一的 国家|省|市|运营商|国家码 */
function geo_merge_cn(?array $a, ?array $b, ?array $c): string
{
    $k = function (?array $r, int $i): string {
        if (!$r) return '';
        $v = (string)($r[$i] ?? '');
        return ($v === '' || $v === '0') ? '' : geo_name_key($v);
    };
    $pa = $k($a, 1); $pb = $k($b, 1); $pc = $k($c, 1);
    $cnt = [];
    foreach ([$pa, $pb, $pc] as $p) { if ($p !== '') $cnt[$p] = ($cnt[$p] ?? 0) + 1; }
    if (!$cnt) return '';
    arsort($cnt);
    $keys = array_keys($cnt);
    $prov = $keys[0];
    if (count($keys) > 1 && $cnt[$keys[0]] === $cnt[$keys[1]] && $pa !== '') $prov = $pa;   // 平票：太平洋优先

    $cities = [];
    foreach ([[$a, $pa], [$b, $pb], [$c, $pc]] as $pair) {
        [$r, $pk] = $pair;
        if (!$r || $pk === '' || $pk !== $prov) continue;
        $ck = $k($r, 2);
        if ($ck !== '') $cities[$ck] = (string)$r[2];
    }
    $city = count($cities) === 1 ? (string)array_values($cities)[0] : '0';   // 同省不同市 → 宁可不给市

    $isp = '0';
    $provShow = '';
    foreach ([[$a, $pa], [$b, $pb], [$c, $pc]] as $pair) {
        [$r, $pk] = $pair;
        if (!$r || $pk !== $prov) continue;
        if ($provShow === '') $provShow = (string)$r[1];
        if ($isp === '0' && (string)($r[3] ?? '0') !== '0') $isp = (string)$r[3];
    }
    if ($provShow === '') $provShow = $prov;
    return implode('|', ['中国', $provShow, $city, $isp, 'CN']);
}

/** 国外库回退（ip-api.com 免费接口） */
function geo_lookup_world(string $ip): string
{
    $body = geo_http_get('http://ip-api.com/json/' . urlencode($ip)
        . '?fields=status,country,regionName,city,isp,countryCode&lang=zh-CN', 3);
    $d = $body ? json_decode($body, true) : null;
    if (!$d || ($d['status'] ?? '') !== 'success') return '';
    $parts = [$d['country'] ?? '0', $d['regionName'] ?? '0', $d['city'] ?: '0', $d['isp'] ?? '0', $d['countryCode'] ?? '0'];
    return implode('|', $parts);
}

/** 国内备用源：淘宝/阿里 IP 库（省市运营商，字段规范） */
function geo_lookup_taobao(string $ip): string
{
    $body = geo_http_get('https://ip.taobao.com/outGetIpInfo?ip=' . urlencode($ip) . '&accessKey=alibaba-inc', 3);
    $d = $body ? json_decode($body, true) : null;
    if (!is_array($d) || (int)($d['code'] ?? -1) !== 0 || !is_array($d['data'] ?? null)) return '';
    $x = $d['data'];
    $country = geo_clean((string)($x['country'] ?? ''));
    $prov = geo_clean((string)($x['region'] ?? ''));
    $city = geo_clean((string)($x['city'] ?? ''));
    $isp = geo_isp_norm((string)($x['isp'] ?? ''));
    if ($prov === '' && $city === '') return '';   // 没有省市信息（海外/未知）交给其它源
    $isCn = $country === '' || mb_strpos($country, '中国') !== false;
    return implode('|', [$isCn ? '中国' : ($country ?: '0'),
        $prov !== '' ? $prov : '0', $city !== '' ? $city : '0',
        $isp !== '' ? $isp : '0', $isCn ? 'CN' : '0']);
}

/** 省市名归一，用于两库比对（去掉 省/市/区/县/自治州 等后缀） */
function geo_name_key(string $v): string
{
    $v = trim($v);
    if ($v === '' || $v === '0') return '';
    $v = preg_replace('/(省|市|自治区|特别行政区|回族|维吾尔|壮族|自治州|地区|盟|区|县)+$/u', '', $v);
    return trim((string)$v);
}

/** 去掉各种占位符（淘宝会返回 XX；也有库返回 未知/无/- 等） */
function geo_clean(string $v): string
{
    $v = trim($v);
    if ($v === '' || $v === '0') return '';
    if (preg_match('/^(xx|XX|未知|无|--|-|null|NULL|N\/A)$/u', $v)) return '';
    return $v;
}

/** 可选：ipip.net 付费接口（在平台设置里填 token 即启用，城域网/手机 IP 更准）
 *  https://ipapi.ipip.net/find?addr=IP   Header: Token: xxx  → ["中国","山东","潍坊","移动"] */
function geo_lookup_ipip(string $ip): string
{
    $token = kv_get('geo_ipip_token', '');
    if ($token === '') return '';
    $ch = curl_init('https://ipapi.ipip.net/find?addr=' . urlencode($ip));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => ['Token: ' . $token],
        CURLOPT_USERAGENT => 'kfxt/2.37',
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    $d = $body ? json_decode($body, true) : null;
    if (!is_array($d) || count($d) < 3) return '';
    if (isset($d['ret']) && $d['ret'] !== 'ok') return '';
    $country = geo_clean((string)($d[0] ?? ''));
    $prov = geo_clean((string)($d[1] ?? ''));
    $city = geo_clean((string)($d[2] ?? ''));
    $isp  = geo_isp_norm(geo_clean((string)($d[3] ?? '')));
    if ($prov === '' && $city === '') return '';
    $isCn = $country === '' || mb_strpos($country, '中国') !== false;
    return implode('|', [$isCn ? '中国' : $country, $prov !== '' ? $prov : '0',
        $city !== '' ? $city : '0', $isp !== '' ? $isp : '0', $isCn ? 'CN' : '0']);
}

function lookup_geo(string $ip): string
{
    if (!$ip || $ip === '127.0.0.1') return '';
    /* v2.89.4 缓存代号升 v3：算法换了两源交叉 + 第三票，老缓存里那些不准的结果直接作废重查 */
    $cacheFile = sys_get_temp_dir() . '/kfxt_geo_v3_' . md5($ip) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $v = @file_get_contents($cacheFile);
        if ($v !== false && $v !== '') return $v;
    }
    /* v2.89.4 归属地重做（原来是 太平洋 + 淘宝，淘宝那个公开接口已经废了 → 等于单源，所以经常不准）：
       ① 有 ipip token 还是优先（最准）；
       ② 国内：太平洋（能到区县）+ 纯真 两个国内源交叉，省份取多数票；
          城市只在两个源同省且不冲突时才给（移动/城域网 IP 各库城市差别大，宁可不给也不报错城市）；
       ③ 两个国内源省份打架 / 都没有 → 拿 ip-api 投第三票；ip-api 说在海外就以它为准（纯真偶尔把海外判成国内）。 */
    $out = geo_lookup_ipip($ip);
    if ($out !== '') { @file_put_contents($cacheFile, $out); return $out; }
    $cn1 = geo_lookup_cn($ip);          // 太平洋（省/市/区县/运营商）
    $cn2 = geo_lookup_zxinc($ip);       // 纯真
    $a = $cn1 !== '' ? explode('|', $cn1) : null;
    $b = $cn2 !== '' ? explode('|', $cn2) : null;
    $out = '';
    if ($a || $b) {
        $pa = $a ? geo_name_key((string)$a[1]) : '';
        $pb = $b ? geo_name_key((string)$b[1]) : '';
        if ($pa !== '' && $pb !== '' && $pa === $pb) {
            $out = geo_merge_cn($a, $b, null);                    // 两个国内源省份一致 → 直接交叉
        } else {
            $w = geo_lookup_world($ip);                           // 打架 / 缺省：请 ip-api 投票
            $c = $w !== '' ? explode('|', $w) : null;
            $cCn = $c && (string)($c[4] ?? '') === 'CN';
            $cnAgree = ($pa !== '' && $pb !== '' && $pa === $pb);
            if ($c && !$cCn && !$cnAgree) {
                $out = $w;                                        // 两个国内源自己都不一致、ip-api 说海外 → 信 ip-api
            } else {
                /* 只有一个国内源有数据（另一个源没查到/连不上）且 ip-api 同省 → 保留这个源的市，
                   别因为第二源缺数据把本来有的城市也丢了 */
                $one = null;
                if ($pa !== '' && $pb === '') $one = $a;
                elseif ($pb !== '' && $pa === '') $one = $b;
                if ($one && $cCn && geo_name_key((string)$one[1]) === geo_name_key((string)$c[1])) {
                    $out = implode('|', ['中国', $one[1], $one[2],
                        (string)($one[3] ?? '0') !== '0' ? $one[3] : $c[3], 'CN']);
                } else {
                    $out = geo_merge_cn($a, $b, $c);
                }
            }
        }
    }
    if ($out === '') $out = geo_lookup_world($ip);
    if ($out !== '') @file_put_contents($cacheFile, $out);
    geo_cache_gc();
    return $out;
}

/** 检测接口配置：kv_store 可覆盖（wxcheck_user / wxcheck_key / wxcheck_url / wxcheck_ttl） */
function wxcheck_cfg(): array
{
    return [
        // 平台级开关：wxcheck_on=0 时只回报「未开通」，不显示绿色的「正常接待」（接口不可信时避免假绿）
        // v2.47 起默认开启（用米人免费接口，不需要账号；要关就写 kv wxcheck_on=0）
        'on'   => kv_get('wxcheck_on', '1') !== '0',
        // v2.47 检测通道：mir6 = 米人免费接口（只要 url，不用账号）／uouin = 原来的付费接口（要账号密钥）
        'provider' => kv_get('wxcheck_provider', 'mir6') === 'uouin' ? 'uouin' : 'mir6',
        'user' => kv_get('wxcheck_user', ''),
        'key'  => kv_get('wxcheck_key', ''),
        'url'  => rtrim(kv_get('wxcheck_url', 'https://api.uouin.com/app'), '/'),
        'mir6' => rtrim(kv_get('wxcheck_mir6_url', 'https://api.mir6.com/api/wxurl'), '/'),
        'ttl'  => max(60, (int)kv_get('wxcheck_ttl', '300')),
    ];
}

/* ================= 微信/QQ 拦截检测（v2.64 抽成公共函数，供 API 与定时巡检共用） =================
   返回 ['state' => ok|red|off|err, 'tag' => 中文, 'text' => 一句话, 'msg' => 详情]
   state=off 表示平台没开通/没配账号；err 表示接口异常。
   注意：**每次调用都会真实请求第三方接口（按次计费）**，调用方自己控制频率。 */
/* v2.60.15 双源交叉检测：一次同时查「付费接口（麒麟/腾讯urlsec）」和「米人免费接口」，
   任意一源报红就算红（宁误报不漏报——实测米人把一条真被微信拦的链接报成了"安全网站"）。
   付费通道没配/没充值（-206）时自动只用米人，行为跟以前一样。 */
function wxcheck_run(string $link, string $channel = 'wx'): array
{
    $chName = $channel === 'qq' ? 'QQ' : '微信';
    $cfg = wxcheck_cfg();
    if (!$cfg['on']) {
        return ['state' => 'off', 'tag' => '未开通', 'text' => $chName . '检测未开通',
            'msg' => '平台未开通拦截检测，请联系管理员'];
    }
    $hasPaid = ($cfg['user'] !== '' && $cfg['key'] !== '');
    $order = $hasPaid ? ['uouin', 'mir6'] : ['mir6'];
    $res = [];
    foreach ($order as $src) {
        if ($src === 'mir6' && $channel !== 'wx') continue;   // 米人只做微信
        $r = wxcheck_single_run($link, $channel, $src);
        $r['source'] = $src;
        $res[] = $r;
        if (($r['state'] ?? '') === 'red') break;             // 已经确定红了，不用再烧第二次调用
    }
    if (!$res) {
        return ['state' => 'off', 'tag' => '未开通', 'text' => $chName . '检测未开通',
            'msg' => $chName . '检测通道未配置'];
    }
    foreach ($res as $r) if (($r['state'] ?? '') === 'red') return $r;      // 任一红 → 红
    foreach ($res as $r) {
        if (($r['state'] ?? '') === 'ok') {
            $bad = '';
            foreach ($res as $x) if (($x['state'] ?? '') === 'err') $bad = (string)($x['msg'] ?? '');
            if ($bad !== '') $r['msg'] = (string)($r['msg'] ?? '') . '（另一检测源异常：' . $bad . '）';
            return $r;
        }
    }
    return $res[0];   // 全是异常/未开通
}

/** 单源检测（$forceProvider 用来指定走哪条通道；不传就按配置） */
function wxcheck_single_run(string $link, string $channel = 'wx', ?string $forceProvider = null): array
{
    $chName = $channel === 'qq' ? 'QQ' : '微信';
    $cfg = wxcheck_cfg();
    $provider = $forceProvider ?? $cfg['provider'];
    // ---- 米人免费接口（v2.47）：GET /api/wxurl?url=… → type=安全网站/危险网站/未知状态 ----
    if ($provider === 'mir6') {
        if ($channel !== 'wx') {
            return ['state' => 'off', 'tag' => '不支持', 'text' => $chName . '检测不支持',
                'msg' => '米人接口只提供微信拦截检测，QQ 检测请改用付费通道'];
        }
        $api = $cfg['mir6'] . '?url=' . urlencode($link);
        $resp = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($api);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'kfxt/2.47']);
            $resp = (string)curl_exec($ch);
            curl_close($ch);
        } else {
            $resp = (string)@file_get_contents($api);
        }
        $j = json_decode($resp, true);
        if (!is_array($j) || (int)($j['code'] ?? 0) !== 200) {
            return ['state' => 'err', 'tag' => '异常', 'text' => '检测失败',
                'msg' => '检测接口无响应' . (is_array($j) ? ('（' . (string)($j['msg'] ?? '') . '）') : '')];
        }
        $type = trim((string)($j['type'] ?? ''));
        if ($type === '安全网站') {
            return ['state' => 'ok', 'tag' => '正常', 'text' => $chName . '可正常打开',
                'msg' => '该链接在' . $chName . '内未被拦截（米人检测）'];
        }
        if ($type === '危险网站') {
            return ['state' => 'red', 'tag' => '红', 'text' => '已被' . $chName . '拦截',
                'msg' => '该链接在' . $chName . '内已被拦截，建议换码/换域名（米人检测）'];
        }
        return ['state' => 'err', 'tag' => '未知', 'text' => '检测结果未知',
            'msg' => '接口返回：' . ($type !== '' ? $type : '未知状态') . '，建议人工复核'];
    }
    // ---- 原有付费通道 ----
    if ($cfg['user'] === '' || $cfg['key'] === '') {
        return ['state' => 'off', 'tag' => '未开通', 'text' => $chName . '检测未开通',
            'msg' => '平台未配置检测接口账号'];
    }
    $api = $cfg['url'] . '/' . $channel . '?username=' . urlencode($cfg['user'])
         . '&key=' . urlencode($cfg['key']) . '&url=' . urlencode($link);
    $resp = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($api);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'kfxt/2.30']);
        $resp = (string)curl_exec($ch);
        curl_close($ch);
    } else {
        $resp = (string)@file_get_contents($api);
    }
    $j = json_decode($resp, true);
    if (!is_array($j) || !isset($j['code'])) {
        return ['state' => 'err', 'tag' => '异常', 'text' => '检测失败', 'msg' => '检测接口无响应'];
    }
    $c = (int)$j['code'];
    $m = trim((string)($j['msg'] ?? ''));
    if ($c === 1001)     return ['state' => 'ok',  'tag' => '正常', 'text' => $chName . '可正常打开',
        'msg' => ($m !== '' ? $m : ('该链接在' . $chName . '内未被拦截'))];
    if ($c === 1002)     return ['state' => 'red', 'tag' => '红', 'text' => '已被' . $chName . '拦截',
        'msg' => ($m !== '' ? $m : ('该链接在' . $chName . '内已被拦截，建议换码/换域名'))];
    if ($c === -206)     return ['state' => 'off', 'tag' => '未开通', 'text' => $chName . '检测未开通', 'msg' => $m];
    return ['state' => 'err', 'tag' => '异常', 'text' => '检测异常', 'msg' => ($c . ' ' . $m)];
}

/**
 * 地区缓存文件的自清理（v2.63）
 * 这些文件本身 24 小时就过期不用了（见上面的 filemtime 判断），
 * 这里只是防止长期堆积（每个不同 IP 一个文件）。
 * 刻意做成"按概率触发"：大约每 50 次地区查询顺手扫一次，平时完全不做事。
 * 只删 **超过 2 天** 且文件名符合我们前缀的临时文件，碰不到别的东西。
 */
function geo_cache_gc(): void
{
    try {
        if (random_int(1, 50) !== 1) return;
        $dir = sys_get_temp_dir();
        $files = @glob($dir . '/kfxt_geo_v2_*.json');
        if (!$files) return;
        $now = time();
        foreach ($files as $f) {
            if ($now - (int)@filemtime($f) > 172800) @unlink($f);
        }
    } catch (\Throwable $e) { /* 清理失败无所谓 */ }
}

/* ================= UA / 语言 ================= */

function parse_ua(?string $ua): array
{
    if (!$ua) return ['device' => '未知', 'os' => '未知', 'browser' => '未知', 'brand' => '', 'channel' => '', 'os_short' => '', 'dev_tag' => '未知'];
    $device = '桌面'; $os = '未知'; $browser = '未知'; $brand = ''; $channel = ''; $osShort = '';
    // ----- 系统与设备 -----
    if (str_contains($ua, 'iPhone')) {
        $device = '苹果 iPhone'; $os = '苹果 iOS'; $osShort = 'iOS';
        if (preg_match('/iPhone OS (\d+)[._](\d+)/', $ua, $m)) $osShort = 'iOS ' . $m[1] . ($m[2] !== '0' ? '.' . $m[2] : '');
    } elseif (str_contains($ua, 'iPad')) {
        $device = '苹果 iPad'; $os = '苹果 iPadOS'; $osShort = 'iPadOS';
        if (preg_match('/CPU OS (\d+)[._](\d+)/', $ua, $m)) $osShort = 'iPadOS ' . $m[1];
    } elseif (str_contains($ua, 'Android')) {
        $device = str_contains($ua, 'Mobile') ? 'Android 手机' : 'Android 平板';
        $osShort = '安卓';
        if (preg_match('/Android (\d+)(?:\.(\d+))?/', $ua, $m)) $osShort = '安卓' . $m[1] . (isset($m[2]) && $m[2] !== '0' ? '.' . $m[2] : '');
        $os = 'Android';
        // 安卓 UA 的 Build 型号前缀推断品牌（如 "HUAWEI P30 Build/..."）
        if (preg_match('/;\s*([A-Za-z][A-Za-z0-9 _-]{1,24}?)\s+(?:Build\/|\))/u', $ua, $bm)) {
            $model = trim($bm[1]);
            if (!preg_match('/^(WAP|Browser|Windows)/i', $model)) {
                $brandMap = ['HUAWEI' => '华为', 'HONOR' => '荣耀', 'Xiaomi' => '小米', 'Redmi' => '红米',
                    'MI ' => '小米', 'OPPO' => 'OPPO', 'OnePlus' => '一加', 'vivo' => 'vivo', 'iQOO' => 'iQOO',
                    'samsung' => '三星', 'SM-' => '三星', 'Pixel' => '谷歌', 'meizu' => '魅族', 'MEIZU' => '魅族'];
                foreach ($brandMap as $k => $v) {
                    if (stripos($model, $k) === 0) { $brand = $v; break; }
                }
                if ($brand === '' && preg_match('/^[A-Z]{2,}\d/', $model)) $brand = $model;
            }
        }
    } elseif (str_contains($ua, 'Windows NT 10')) {
        $os = 'Windows 10/11'; $osShort = 'Windows 10/11';
        if (str_contains($ua, 'Windows Phone')) { $device = 'Windows 手机'; $osShort = 'Win10 Mobile'; }
    } elseif (str_contains($ua, 'Windows NT 6.3')) { $os = 'Windows 8.1'; $osShort = 'Windows 8.1'; }
    elseif (str_contains($ua, 'Windows NT 6.1')) { $os = 'Windows 7'; $osShort = 'Windows 7'; }
    elseif (str_contains($ua, 'Windows NT')) { $os = 'Windows'; $osShort = 'Windows'; }
    elseif (str_contains($ua, 'Mac OS X')) {
        $os = 'macOS'; $osShort = 'macOS';
        if (preg_match('/Mac OS X (\d+)[._](\d+)/', $ua, $m)) $osShort = 'macOS ' . $m[1] . '.' . $m[2];
    }
    elseif (str_contains($ua, 'Linux')) { $os = 'Linux'; $osShort = 'Linux'; }
    // ----- 渠道（内置 WebView 特征明确，先于普通浏览器判断）-----
    if (str_contains($ua, 'MicroMessenger')) { $browser = '微信内置浏览器'; $channel = '微信'; }
    elseif (str_contains($ua, 'AlipayClient')) { $browser = '支付宝内置浏览器'; $channel = '支付宝'; }
    elseif (str_contains($ua, 'QQ/') && !str_contains($ua, 'QQBrowser')) { $browser = 'QQ 内置浏览器'; $channel = 'QQ'; }
    elseif (str_contains($ua, 'DingTalk')) { $browser = '钉钉内置浏览器'; $channel = '钉钉'; }
    elseif (str_contains($ua, 'ByteDance') || str_contains($ua, 'aweme')) { $browser = '抖音内置浏览器'; $channel = '抖音'; }
    elseif (str_contains($ua, 'Edg/')) $browser = 'Edge 浏览器';
    elseif (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) $browser = 'Opera 浏览器';
    elseif (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Chromium')) $browser = 'Chrome 浏览器';
    elseif (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/')) $browser = 'Safari 浏览器';
    elseif (str_contains($ua, 'Firefox/')) $browser = 'Firefox 浏览器';
    if ($channel === '') $channel = '浏览器';
    // 设备标签：安卓=品牌+系统版本；其余=系统短名（iOS 17 / Windows 10/11 / macOS 14.5…）
    if ($brand !== '') $devTag = $brand . ' ' . $osShort;
    elseif ($osShort !== '') $devTag = $osShort;
    else $devTag = $device;
    return ['device' => $device, 'os' => $os, 'browser' => $browser,
        'brand' => $brand, 'channel' => $channel, 'os_short' => $osShort, 'dev_tag' => $devTag];
}

function parse_language(?string $al): string
{
    if (!$al) return '未知';
    $first = trim(explode(',', $al)[0]);
    if ($first === '') return '未知';
    $map = ['zh-CN' => '简体中文', 'zh' => '中文', 'zh-TW' => '繁体中文', 'zh-HK' => '繁體中文',
        'en' => '英语', 'en-US' => '英语（美国）', 'ja' => '日语', 'ko' => '韩语'];
    if (isset($map[$first])) return $map[$first];
    return $map[explode('-', $first)[0]] ?? $first;
}

/* ================= 会话访客标签（设备 / 渠道 / 地区） ================= */

/** geo 串取城市（格式 国家|地区|城市|ISP|国家码）；取不到城市时退回地区，再退国家；紧凑化后缀 */
function geo_city(?string $geo): string
{
    if (!$geo) return '';
    $p = array_map('trim', explode('|', $geo));
    foreach ([2, 1, 0] as $i) {           // 城市 → 地区 → 国家
        $v = $p[$i] ?? '';
        if ($v !== '' && $v !== '0') {
            if (mb_strlen($v) > 2) {
                if (mb_substr($v, -1) === '市') $v = mb_substr($v, 0, -1);            // 深圳市 → 深圳（北京市 → 北京）
                elseif (mb_substr($v, -3) === '地区') $v = mb_substr($v, 0, -3);
                elseif ($i === 1 && mb_substr($v, -1) === '省') $v = mb_substr($v, 0, -1);  // 省级 fallback：浙江省 → 浙江
            }
            return $v;
        }
    }
    return '';
}

/** geo 串取地区标签（v2.29）：省市 + 运营商。如「广东 广州 电信」「香港」；海外不含运营商 */
function geo_region(?string $geo): string
{
    if (!$geo) return '';
    $p = array_map('trim', explode('|', $geo));
    $prov = ($p[1] ?? '');
    $city = ($p[2] ?? '');
    $isp = ($p[3] ?? '');
    $cc = strtoupper($p[4] ?? '');
    $tight = function (string $v): string {
        if ($v === '' || $v === '0') return '';
        if (mb_strlen($v) > 2) {
            if (mb_substr($v, -1) === '市') $v = mb_substr($v, 0, -1);
            elseif (mb_substr($v, -3) === '地区') $v = mb_substr($v, 0, -3);
            elseif (mb_substr($v, -1) === '省') $v = mb_substr($v, 0, -1);
        }
        return $v;
    };
    $prov = $tight($prov); $city = $tight($city);
    $country = $tight((string)($p[0] ?? ''));   // 海外：城市名常等于国家名（新加坡/中央新加坡）
    // 运营商精简：ISP 字符串里通常含公司名（如 "中国电信"/"China Telecom"）
    $ispShort = '';
    if ($isp !== '' && $isp !== '0') {
        if (str_contains($isp, '电信') || stripos($isp, 'Telecom') !== false) $ispShort = '电信';
        elseif (str_contains($isp, '联通') || stripos($isp, 'Unicom') !== false) $ispShort = '联通';
        elseif (str_contains($isp, '移动') || stripos($isp, 'Mobile') !== false) $ispShort = '移动';
        elseif (str_contains($isp, '铁通')) $ispShort = '铁通';
        elseif (str_contains($isp, '广电')) $ispShort = '广电';
        elseif (stripos($isp, 'Cable') !== false) $ispShort = '广电';
    }
    $out = '';
    if ($cc === 'CN') {
        // 国内：省市去重（北京市|北京市 → 北京）
        if ($prov !== '' && $city !== '' && $prov !== $city) $out = $prov . ' ' . $city;
        else $out = $city !== '' ? $city : $prov;
        if ($ispShort !== '') $out .= ' ' . $ispShort;
    } else {
        // 海外：地区 + 城市（国家在悬停全文里）；像「台湾|台北市」显示成「台湾 台北」更好认
        if ($city !== '' && $country !== '' && $city === $country) {
            $out = $city;                                  // 「Central Singapore 新加坡」→ 新加坡
        } elseif ($prov !== '' && $city !== '' && $prov !== $city) {
            // 两个库互相包含时只留长的那半，别出现「Central Singapore 新加坡」这种啰嗦写法
            if (mb_stripos($prov, $city) !== false || mb_stripos($city, $prov) !== false) {
                $out = mb_strlen($prov) >= mb_strlen($city) ? $prov : $city;
            } else {
                $out = $prov . ' ' . $city;
            }
        } else {
            $out = $city !== '' ? $city : $prov;
        }
    }
    return $out !== '' ? $out : geo_city($geo);
}

/* ================= 随机串生成 ================= */

function gen_kami(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $s = '';
        for ($i = 0; $i < 10; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        $dup = fetch_count(db(), 'SELECT 1 FROM tenants WHERE kami = ? LIMIT 1', [$s]);
    } while ($dup);
    return $s;
}

function gen_password(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s;
}

function gen_code_token(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    do {
        $s = '';
        for ($i = 0; $i < 10; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        $dup = fetch_count(db(), 'SELECT 1 FROM service_codes WHERE code = ? LIMIT 1', [$s]);
    } while ($dup);
    return $s;
}

function gen_subdomain(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    do {
        $s = '';
        for ($i = 0; $i < 8; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        $dup = fetch_count(db(), 'SELECT 1 FROM domain_links WHERE subdomain = ? LIMIT 1', [$s]);
    } while ($dup);
    return $s;
}

/**
 * 防红中转页的文件名（v2.59）：8 位小写字母数字。
 * 配合 /MM-DD/ 目录使用，让客服码看起来是"某天生成的一个普通静态页"。
 */
function gen_gate_name(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s;
}

/* ===================== v2.60.9 访客「恢复码」 =====================
   场景：客户换手机 / 换浏览器 / 清了缓存后重新扫码，浏览器里原来的 visitor_token 没了，
   会被当成新访客、看不到之前的聊天记录。给他一个 8 位恢复码，就能把那段对话接回来。
   （客服端在「访客信息」里能看到并复制这个码，然后发给客户。） */
function resume_code_new(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';   // 去掉容易看错的 i l o 0 1
    for ($try = 0; $try < 8; $try++) {
        $s = '';
        for ($i = 0; $i < 8; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        try {
            $st = db()->prepare('SELECT 1 FROM v_sessions WHERE resume_code = ? LIMIT 1');
            $st->execute([$s]);
            if (!$st->fetch()) return $s;
        } catch (\Throwable $e) { return $s; }   // 老库没这列时也别报错
    }
    return 'r' . substr(bin2hex(random_bytes(5)), 0, 7);
}

/** 取会话的恢复码（没有就生成并存下来），老会话第一次查看时自动补上 */
function resume_code_for(int $sid): string
{
    try {
        $st = db()->prepare('SELECT resume_code FROM v_sessions WHERE id = ?');
        $st->execute([$sid]);
        $cur = trim((string)($st->fetchColumn() ?: ''));
        if ($cur !== '') return $cur;
        $code = resume_code_new();
        db()->prepare('UPDATE v_sessions SET resume_code = ? WHERE id = ?')->execute([$code, $sid]);
        return $code;
    } catch (\Throwable $e) { return ''; }
}

/* ===================== v2.61 aaPanel（宝塔国际版）快速导入固定随机子域 ===================== */

/** aaPanel API 调用：request_token = md5(时间戳 + md5(API密钥))，表单 POST，返回 JSON 数组。
 *  面板常用自签证书，跳过校验；凭证只在调用方（管理员快速导入）请求内使用，不落库。 */
function aa_call(string $panel, string $key, string $path, array $params = [], int $timeout = 60): array
{
    $t = (string)time();
    $params['request_time'] = $t;
    $params['request_token'] = md5($t . md5($key));
    $ch = curl_init(rtrim($panel, '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $resp === '') {
        throw new RuntimeException('面板无响应（HTTP ' . $http . ($err !== '' ? '：' . $err : '') . '）——确认面板地址可达、面板「设置→API」已开启、总控 IP 在 API 白名单里');
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) {
        throw new RuntimeException('面板返回非 JSON（HTTP ' . $http . '）：' . mb_substr((string)$resp, 0, 300));
    }
    return $j;
}

/** 从 aaPanel PHP 版本列表响应里宽容地抽出版本号（不同面板版本返回结构不一） */
function aa_php_versions(array $j): array
{
    $out = [];
    $walk = function ($v) use (&$walk, &$out) {
        if (!is_array($v)) return;
        if (isset($v['version'])) { $out[] = (string)$v['version']; return; }
        foreach ($v as $vv) $walk($vv);
    };
    $walk($j);
    return array_values(array_unique($out));
}

/** 生成随机子域：小写字母+数字，去掉易混淆的 0/1/o/l；$taken 为已占用集合 */
function gen_subdomains(int $count, int $len, array $taken = []): array
{
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = [];
    $guard = 0;
    while (count($out) < $count && $guard++ < $count * 60) {
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        if (isset($taken[$s])) continue;
        $taken[$s] = 1;
        $out[] = $s;
    }
    return $out;
}

/** v2.63 客服码/域名红了 → 自动退役并记入红名单（子域级明细区分）：
 *  - 链接 host 是某主域的固定子域（host != 主域）→ 从子域池（pool_subs）摘除，发码不再挑中它；
 *    固定随机的子域全部退役则整行停用（否则会退化成没有证书的随机子域）；
 *  - host 是主域本身（单域名/泛解析裸域）→ 停用池行；
 *  - 两种情况都写 red_domains（管理台红名单可查看/恢复）。域名不在池里只记名单不动行。 */
function code_red_retire(string $link, string $channel = 'wx'): array
{
    $out = ['kind' => '', 'host' => '', 'main' => '', 'disabled_row' => false, 'retired_sub' => false];
    $host = strtolower((string)(parse_url($link, PHP_URL_HOST) ?: ''));
    if ($host === '') return $out;
    $out['host'] = $host;
    $pdo = db();
    // 归属：host 本身是池行 → 主域；否则找 host 所属的池行主域（子域）
    $main = '';
    $row = null;
    $st = $pdo->prepare('SELECT id, domain, dtype, enabled FROM domain_pool WHERE domain = ? LIMIT 1');
    $st->execute([$host]);
    if ($r = $st->fetch()) { $main = $host; $row = $r; }
    else {
        foreach ($pdo->query('SELECT id, domain, dtype, enabled FROM domain_pool') as $r) {
            if (str_ends_with($host, '.' . strtolower((string)$r['domain']))) {
                $main = strtolower((string)$r['domain']); $row = $r; break;
            }
        }
    }
    $out['main'] = $main !== '' ? $main : $host;
    parse_str((string)(parse_url($link, PHP_URL_QUERY) ?: ''), $q);
    $code = mb_substr(trim((string)($q['key'] ?? '')), 0, 64);
    try {
        $kind = ($row && $host !== $main) ? 'sub' : 'main';
        $pdo->prepare('INSERT IGNORE INTO red_domains (host, main, kind, code, channel) VALUES (?,?,?,?,?)')
            ->execute([$host, $out['main'], $kind, $code, $channel]);
        $out['kind'] = $kind;
    } catch (\Throwable $e) { /* 名单写失败不影响退役 */ }
    if (!$row) return $out;   // 域名不在池里（可能已删）：只记名单
    if ($out['kind'] === 'sub') {
        $key = 'pool_subs:' . $main;
        $list = array_values(array_diff(array_filter(array_map('trim', explode(',', kv_get($key, '')))), [$host]));
        kv_set($key, implode(',', $list));
        $out['retired_sub'] = true;
        if (!$list && (string)$row['dtype'] === 'fixed') {
            $pdo->prepare('UPDATE domain_pool SET enabled = 0 WHERE id = ?')->execute([(int)$row['id']]);
            $out['disabled_row'] = true;
        }
    } else {
        $pdo->prepare('UPDATE domain_pool SET enabled = 0 WHERE id = ?')->execute([(int)$row['id']]);
        $out['disabled_row'] = true;
    }
    return $out;
}

/* ===================== v2.65 防红壳 · 自解密 Loader ===================== */

/** 浏览器证明（防脚本直接刷 /api/gate）：JS 与 PHP 各实现一份 djb2，必须完全一致 */
function gate_proof(string $key): string
{
    $s = $key . '|' . date('Y') . '|kfxt';
    $h = 5381;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $h = (($h << 5) + $h + ord($s[$i])) & 0xFFFFFFFF;
    }
    return dechex($h);
}

/** v2.65.3 从查询串取客服码（兼容 ?key= / ?k= / ?d=base64(key=…)） */
function gate_key_from_query(): string
{
    $k = trim((string)($_GET['key'] ?? ($_GET['k'] ?? '')));
    if ($k === '') {
        try {
            $d = (string)($_GET['d'] ?? '');
            if ($d !== '') {
                $s = base64_decode($d, true) ?: '';
                if (preg_match('/key=([A-Za-z0-9]+)/', $s, $m)) $k = $m[1];
                elseif (preg_match('/^[A-Za-z0-9]{4,64}$/', $s)) $k = $s;
            }
        } catch (\Throwable $e) { /* 解不出来当无码 */ }
    }
    return $k;
}

/** v2.65.3 码有效性（总控侧，需数据库）：存在 + 租户 active + 未到期 */
function gate_key_valid(string $key): bool
{
    if (!preg_match('/^[A-Za-z0-9]{4,64}$/', $key)) return false;
    $st = db()->prepare('SELECT 1 FROM service_codes sc JOIN tenants t ON t.id = sc.tenant_id
        WHERE sc.code = ? AND t.status = \'active\' AND (t.expires_at IS NULL OR t.expires_at >= NOW()) LIMIT 1');
    $st->execute([$key]);
    return (bool)$st->fetchColumn();
}

/* ===================== v2.68 入口 ID（二维码永不过期） =====================
   老做法：二维码里印的就是客服码，而且域名+路径一起写死 —— 换码 / 换域名都要重印二维码。
   新做法：二维码里印的是「入口 ID」（跟客服码、跟落地域名都解耦）：
     https://<入口域名>/MM-DD/<随机>.html?w=<入口ID>
   总控在 /api/gate 里把入口 ID 解析成「当前客服码 + 当前内容域名」再下发，
   于是换客服码、换内容域名，顾客手里那张二维码都不用重印。
   老的 ?key=<客服码> 链接继续原样可用（解析顺序：先按入口 ID 找，找不到再当客服码）。 */

/** 入口 ID 字符集去掉易混的 l/0/1 */
function gen_entry_id(int $len = 12): string
{
    $c = 'abcdefghijkmnpqrstuvwxyz23456789';
    $n = strlen($c) - 1;
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $c[random_int(0, $n)];
    return $s;
}

/** 从查询串取「入口令牌」：优先 v2.68 的 ?w=，兼容老的 ?key=/?k=/?d=（base64） */
function gate_token_from_query(): string
{
    $t = trim((string)($_GET['w'] ?? ''));
    if ($t !== '' && preg_match('/^[A-Za-z0-9]{6,32}$/', $t)) return $t;
    /* v2.89 新码把入口 ID 放在随机参数名后面（二维码里没有固定特征 ?w=），
       固定名都认不出来时，退回到「任意一个看起来像入口 ID 的参数」。老链接照旧走前面那条。 */
    $skip = ['w' => 1, 'key' => 1, 'k' => 1, 'd' => 1, 't' => 1, 'r' => 1, 'code' => 1, 'pf' => 1, 'tk' => 1, '_t' => 1];
    foreach ($_GET as $k => $v) {
        if (is_array($v)) continue;
        $k = (string)$k;
        if (isset($skip[strtolower($k)]) || ($k !== '' && $k[0] === '_')) continue;
        $v = trim((string)$v);
        if (preg_match('/^[A-Za-z0-9]{6,32}$/', $v)) return $v;
    }
    return gate_key_from_query();
}

/** v2.89 随机参数名：新发的码用 ?<随机名>=<入口ID>，链接里不再有固定的 ?w= 特征。
 *  同一个码仍可换参数名重发（入口 ID 才是身份），老二维码的 ?w= 一直有效。 */
function gate_rand_param(): string
{
    $bad = ['w' => 1, 'k' => 1, 'key' => 1, 'd' => 1, 't' => 1, 'r' => 1, 'code' => 1, 'pf' => 1, 'tk' => 1, 'u' => 1, 'id' => 1];
    $ab = 'abcdefghijklmnopqrstuvwxyz0123456789';
    for ($i = 0; $i < 30; $i++) {
        $len = random_int(5, 7);
        $n = '';
        for ($j = 0; $j < $len; $j++) $n .= $ab[random_int(0, strlen($ab) - 1)];
        if (!isset($bad[$n]) && !ctype_digit($n)) return $n;
    }
    return 'q' . bin2hex(random_bytes(3));
}

/** 入口令牌 → 真实客服码（先按入口 ID 找，再退回当客服码解析）。
 *  返回 ['code'=>…, 'tenant_id'=>…]；无效 / 租户停用 / 已到期 → null。
 *  库不可用（边缘节点无库）→ null，由调用方决定怎么兜。 */
function gate_resolve_token(string $token): ?array
{
    if ($token === '' || !preg_match('/^[A-Za-z0-9]{4,64}$/', $token)) return null;
    try {
        $pdo = db();
        $row = null;
        $st = $pdo->prepare('SELECT sc.code, sc.tenant_id, t.status, t.expires_at
            FROM service_codes sc JOIN tenants t ON t.id = sc.tenant_id
            WHERE sc.entry_id = ? AND sc.enabled = 1 LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch();
        if (!$row) {
            $st = $pdo->prepare('SELECT sc.code, sc.tenant_id, t.status, t.expires_at
                FROM service_codes sc JOIN tenants t ON t.id = sc.tenant_id
                WHERE sc.code = ? AND sc.enabled = 1 LIMIT 1');
            $st->execute([$token]);
            $row = $st->fetch();
        }
    } catch (\Throwable $e) {
        return null;
    }
    if (!$row) return null;
    if (($row['status'] ?? '') !== 'active') return null;
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) return null;
    return ['code' => (string)$row['code'], 'tenant_id' => (int)$row['tenant_id']];
}

/** 从启用的域名池挑落地主机（子域优先）。
 *  $stable=true 时每个域名固定取子域池第一个（给壳页的「备用域名」用，保证同一个包内稳定）。 */
function gate_pool_hosts(int $limit = 8, bool $stable = false): array
{
    try {
        $rows = db()->query('SELECT domain, wildcard FROM domain_pool WHERE enabled = 1 ORDER BY id ASC')->fetchAll();
    } catch (\Throwable $e) {
        return [];   // 边缘节点无库
    }
    $out = [];
    foreach ($rows as $r) {
        $d = trim((string)($r['domain'] ?? ''));
        if ($d === '') continue;
        $subs = array_values(array_filter(array_map('trim', explode(',', kv_get('pool_subs:' . $d, '')))));
        if ($subs) {
            $h = ($stable ? $subs[0] : $subs[array_rand($subs)]) . '.' . $d;
        } elseif ((int)($r['wildcard'] ?? 0) === 1) {
            $h = substr(preg_replace('/[^a-z0-9]/', 'x', strtolower(base64_encode(random_bytes(6)))), 0, 8) . '.' . $d;
        } else {
            $h = $d;
        }
        $out[] = $h;
        if (count($out) >= $limit) break;
    }
    return array_values(array_unique($out));
}

/** 壳页里的「备用域名」：当前域名挂了（被红/被墙）时，同一个入口 ID 换到这些域名上继续进。
 *  取启用地域名池每域一个固定子域；边缘节点无库 → 空数组（壳页只做重试+提示）。 */
function gate_alt_hosts(int $limit = 6): array
{
    $self = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $list = gate_pool_hosts($limit + 1, true);
    $out = [];
    foreach ($list as $h) {
        if ($h !== '' && strtolower($h) !== $self) $out[] = $h;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** 内容落地点：伪装成「数据目录里的静态页」——真聊天页挂在这里，
 *  入口二维码里那个域名就永远不用换（换内容域名只在总控下发时决定）。
 *  固定成每域名一小批（见 gate_content_paths），静态节点才能在更新包里烤出真文件。 */
function gate_content_paths(string $domain, int $count = 6): array
{
    $domain = strtolower(trim($domain));
    if ($domain === '') return [];
    $key = 'content_paths:' . $domain;
    $raw = kv_get($key, '');
    $list = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
    if (count($list) >= $count) return $list;
    while (count($list) < $count) {
        $p = '/web/data/' . date('Y/m/d') . '/' . bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2))
            . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(6)) . '.html';
        if (in_array($p, $list, true)) continue;
        $list[] = $p;
    }
    try { kv_set($key, implode(',', $list)); } catch (\Throwable $e) { /* 存不下就本次生效 */ }
    return $list;
}
/* ===================== v2.70 租户专属落地域名（全自动分配） =====================
   以前：所有租户发码都从域名池里随机挑一台 → 一台域名红了，落在它上面的所有租户一起死。
   现在：每个租户固定绑一台专属域名，系统自动挑（不用人工分配）：
     · 发码时如果这个租户还没绑，就选「当前绑定租户最少」的启用域名给他；
     · 绑定的域名被停用（红了）→ 下次发码自动换一台没红的；
     · 全池都满了他也不会发不出码（自动把单台承载上限放宽）。
   存量租户在升级时按"他当前正在用的域名"回填，不打扰、不用重发二维码。 */

/** 这台域名目前绑了几个租户（只算启用中的租户） */
function domain_tenant_count(string $domain): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM tenants WHERE content_domain = ? AND status = 'active'");
        $st->execute([$domain]);
        return (int)$st->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/** 从域名池挑一台给租户用：优先"绑定人数最少"的（同数量随机）；都被占满就把每台上限 +1 再挑 */
function domain_pick_for_tenant(): string
{
    try {
        $rows = db()->query('SELECT domain FROM domain_pool WHERE enabled = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) { return ''; }
    $rows = array_values(array_filter(array_map(fn($d) => strtolower(trim((string)$d)), $rows)));
    /* 保险（很重要）：只要这台域名进过红名单（哪怕只是它的某个子域被拦），就整台不再分配。
       微信封的是域名——"这个子域红了、换个兄弟子域还能用"是错觉，实测主域一起红。 */
    $rows = array_values(array_filter($rows, fn($d) => !domain_in_red_list($d)));
    if (!$rows) return '';
    $cap = max(1, (int)kv_get('domain_tenant_cap', '1'));   // 一台域名默认只带 1 个租户
    for ($round = 0; $round < 6; $round++) {
        $free = [];
        foreach ($rows as $d) { if (domain_tenant_count($d) < $cap) $free[] = $d; }
        if ($free) return $free[array_rand($free)];
        $cap++;                                             // 域名不够就放宽，别让租户发不出码
    }
    return $rows[array_rand($rows)];
}

/** 这台域名（或其任意子域）是否进过红名单 */
function domain_in_red_list(string $domain): bool
{
    $domain = strtolower(trim($domain));
    if ($domain === '') return false;
    try {
        $st = db()->prepare('SELECT 1 FROM red_domains WHERE main = ? OR host = ? LIMIT 1');
        $st->execute([$domain, $domain]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) { return false; }   // 表不存在/查询失败：不当红处理
}

/** 落地主机是否还能用：域名池里启用的、且主域没进过红名单 */
function pool_host_usable(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '' || !pool_host_enabled($host)) return false;
    return !domain_in_red_list(pool_domain_of($host));
}

/** v2.90.14 这个租户的「专属主机」（可选，形如 f1kov5q6.hi6o7e.shop）：
 *  后台可以给某个账号指定一台主机，绑了之后他的码都发在这一台上（别人不再随机到他这台）。 */
function tenant_host_for(int $tenantId): string
{
    try {
        $st = db()->prepare('SELECT content_host FROM tenants WHERE id = ?');
        $st->execute([$tenantId]);
        $h = strtolower(trim((string)($st->fetchColumn() ?: '')));
    } catch (\Throwable $e) {
        return '';
    }
    if ($h === '' || !pool_host_enabled($h)) return '';
    return $h;
}

/** 这个租户的专属域名：已绑且在启用中就直接用；否则自动分配一台并落库 */
function tenant_domain_for(int $tenantId): string
{
    $cur = '';
    try {
        $st = db()->prepare('SELECT content_domain FROM tenants WHERE id = ?');
        $st->execute([$tenantId]);
        $cur = strtolower(trim((string)($st->fetchColumn() ?: '')));
    } catch (\Throwable $e) { }
    if ($cur !== '') {
        try {
            $st = db()->prepare('SELECT enabled FROM domain_pool WHERE domain = ? LIMIT 1');
            $st->execute([$cur]);
            $en = $st->fetchColumn();
            if ($en !== false && (int)$en === 1) return $cur;   // 还活着，继续用
        } catch (\Throwable $e) { return $cur; }
    }
    $pick = domain_pick_for_tenant();
    if ($pick !== '') {
        try { db()->prepare('UPDATE tenants SET content_domain = ? WHERE id = ?')->execute([$pick, $tenantId]); }
        catch (\Throwable $e) { }
    }
    return $pick;
}

/** 在指定域名下挑一个落地主机（固定子域池随机 / 泛解析随机 8 位 / 裸域） */
function pool_host_in(string $domain): string
{
    $domain = strtolower(trim($domain));
    if ($domain === '') return '';
    $subs = array_values(array_filter(array_map('trim', explode(',', kv_get('pool_subs:' . $domain, '')))));
    if ($subs) return $subs[array_rand($subs)] . '.' . $domain;
    try {
        $st = db()->prepare('SELECT wildcard FROM domain_pool WHERE domain = ? LIMIT 1');
        $st->execute([$domain]);
        $w = $st->fetchColumn();
    } catch (\Throwable $e) { $w = false; }
    if ((int)$w === 1) {
        $sub = substr(preg_replace('/[^a-z0-9]/', 'x', strtolower(base64_encode(random_bytes(6)))), 0, 8);
        return $sub . '.' . $domain;
    }
    return $domain;
}

/* ===================== v2.86 客服码：发码 / 自动发码 =====================
   「新码 / 更换码」和「新用户第一次登录自动发一条」共用这一份发码逻辑，
   免得两处各写一遍、以后改漏一处。 */

/** v2.90.24 「门牌池」包装：真实落地链接 → https://门牌/?xx=<base64url>
 *  为什么要池子：只用一个门牌，被举报多了必红（实测 unrivaled-moxie-03037b 红了 → 全站码一起打不开）。
 *  后台配了 door_domain 就固定用它；没配就从池子随机挑一个，且**跳过与落地域同域的门牌**
 *  （同域会跟落地一起红，白套）。已经包过的不再包一层。
 *  管理码列表、查看码、复制链接、二维码统一走这一份逻辑。 */
function gate_door_wrap(string $url): string
{
    if ($url === '') return '';
    if (str_contains($url, '?xx=')) return $url;
    $b64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($url));

    $dom = strtolower(trim((string)kv_get('door_domain', '')));
    if ($dom !== '') {                      // 后台配了固定门牌 → 照旧只用它
        $clean = preg_replace('#^https?://#', '', $dom);
        $clean = rtrim(explode('/', (string)$clean)[0], '/');
        return $clean === '' ? $url : "https://$clean/?xx=$b64";
    }

    $lh = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    $cand = [];
    foreach (door_own_hosts() as $d) {
        if ($lh !== '' && $d === $lh) continue;          // 同域跳过
        $cand[] = "https://$d/k9f3m2.html?xx=";          // 自家门牌页
    }
    foreach (door_ext_hosts() as $d) {
        if ($lh !== '' && $d === $lh) continue;
        $cand[] = "https://$d/?xx=";                     // 大厂托管站（根目录 index.html）
    }
    if (!$cand) return $url;
    return $cand[array_rand($cand)] . $b64;
}

/** 门牌池·自家绿域名（门牌页 /k9f3m2.html 两份 docroot 各放一份，池域名都能命中） */
function door_own_hosts(): array
{
    return ['hujm2g.shop', 'j73t1ia.shop', 'hi6o7e.shop', 'h652ww.shop', 'f5ma20.shop', 'piv27x4.shop'];
}

/** 门牌池·大厂免费托管（netlify 整段已放行；新建站把域名填这里就行，留空=只用自家域名） */
function door_ext_hosts(): array
{
    return [
        'luminous-cactus-91a97c.netlify.app',
    ];
}

/** 发一条新的客服码（新域名 + 新随机路径）。失败返回 ['ok'=>false,'msg'=>...]
 *  $domain 非空 = 指定用域名池里的哪一台（v2.90.10 批量发码「每台域名各一条」用）。 */
function tenant_mint_service_code(int $tid, string $want = '', string $domain = ''): array
{
    $pdo = db();
    $entryId = gen_entry_id();
    $domain = strtolower(trim($domain));
    $want = trim($want);
    if ($want !== '') {
        if (!preg_match('/^[A-Za-z0-9]{4,32}$/', $want)) return ['ok' => false, 'msg' => '客服码只能是 4~32 位字母或数字'];
        $dup = $pdo->prepare('SELECT 1 FROM service_codes WHERE code = ? LIMIT 1');
        $dup->execute([$want]);
        if ($dup->fetch()) return ['ok' => false, 'msg' => '这个客服码已经被用了，换一个'];
        $code = $want;
    } else {
        $code = gen_code_token();
    }
    /* v2.59 防红：入口是「日期目录 + 随机文件名.html」，页面本身没有内容（壳页）。
       v2.68：query 里用 ?w=入口ID，不暴露客服码。
       v2.62.1 域名：配了固定子域池就从池里随机挑；泛解析行随机 8 位子域；单域名行用裸域。 */
    $scheme = 'https';
    /* v2.89 入口 ID 放进随机参数名里（老链接的 ?w= 仍然有效，见 gate_token_from_query） */
    $gatePath = date('m-d') . '/' . gen_gate_name() . '.html?' . gate_rand_param() . '=' . $entryId;
    $boundHost = '';   // v2.90.14 专属主机（见下）
    $entryDomain = strtolower(trim(kv_get('entry_domain', '')));
    if ($entryDomain !== '') {
        /* 入口域名（专门印二维码那个）：只用来出壳页，内容域名红了也不连累二维码 */
        $host = preg_replace('#^https?://#', '', $entryDomain);
        $host = rtrim(explode('/', $host)[0], '/');
        $url = "$scheme://$host/$gatePath";
    } else {
        /* v2.70 专属落地域名（自动分配）：这个租户固定用一台域名，红了只影响它自己；
           指定 $domain 时按指定的那台发（批量铺码用）。 */
        $bound = $domain !== '' ? $domain : tenant_domain_for($tid);
        if ($domain !== '') {
            $ok = false;
            try {
                $chk = $pdo->prepare('SELECT enabled FROM domain_pool WHERE domain = ? LIMIT 1');
                $chk->execute([$domain]);
                $ok = (int)($chk->fetchColumn() ?: 0) === 1;
            } catch (\Throwable $e) { $ok = false; }
            if (!$ok) return ['ok' => false, 'msg' => '域名 ' . $domain . ' 不在域名池里或已停用'];
        }
        if ($bound === '') return ['ok' => false, 'msg' => '域名池为空，请联系平台管理员添加可用域名'];
        /* v2.90.14：这个账号绑了「专属主机」就直接用它（批量铺码指定域名时以指定域名为准） */
        $boundHost = $domain === '' ? tenant_host_for($tid) : '';
        $host = $boundHost !== '' ? $boundHost : pool_host_in($bound);
        if ($host === '') return ['ok' => false, 'msg' => '域名池为空，请联系平台管理员添加可用域名'];
        $url = "$scheme://$host/$gatePath";
    }
    $pdo->prepare('INSERT INTO service_codes (tenant_id, code, entry_id, link, enabled) VALUES (?,?,?,?,1)')
        ->execute([$tid, $code, $entryId, $url]);
    $newId = (int)$pdo->lastInsertId();

    /* v2.90.11/23 「大厂门牌」包装：统一走 gate_door_wrap()（管理码、查看码、二维码都用同一份逻辑） */
    $wrappedUrl = gate_door_wrap($url);

    /* v2.68 内容落地主机：新码先落一台（gate_content_url 会复用，保证访客会话不丢） */
    $ch = $boundHost !== '' ? $boundHost : pool_host_in($domain !== '' ? $domain : tenant_domain_for($tid));
    if ($ch !== '') $pdo->prepare('UPDATE service_codes SET content_host = ? WHERE id = ?')->execute([$ch, $newId]);
    return ['ok' => true, 'code' => [
        'id' => $newId, 'code' => $code, 'entry_id' => $entryId, 'link' => $wrappedUrl,
        'enabled' => true, 'created_at' => dt_to_iso(now_dt()),
    ]];
}

/** 一条能用的客服码都没有时，自动发一条 —— 新用户第一次登录就有码，不用自己去点「生成」。
    已有码（哪怕只是历史遗留的一条）就什么都不做，绝不重复发。 */
function tenant_ensure_service_code(int $tid): void
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM service_codes WHERE tenant_id = ? AND enabled = 1');
        $st->execute([$tid]);
        if ((int)$st->fetchColumn() > 0) return;
        tenant_mint_service_code($tid);
    } catch (\Throwable $e) { /* 发码失败不影响登录 */ }
}

/** 从落地主机反查它属于哪个域名池域名（f5ma20.shop / xxxxx.f5ma20.shop → f5ma20.shop） */
function pool_domain_of(string $host): string
{
    $h = strtolower(trim($host));
    if ($h === '') return '';
    try {
        foreach (db()->query('SELECT domain FROM domain_pool') as $r) {
            $d = strtolower((string)$r['domain']);
            if ($d !== '' && ($h === $d || str_ends_with($h, '.' . $d))) return $d;
        }
    } catch (\Throwable $e) { /* 无库（边缘）走下面的兜底 */ }
    $p = explode('.', $h);
    return count($p) > 2 ? implode('.', array_slice($p, -2)) : $h;
}

/** 这张码固定用哪台「内容落地主机」（落库保存，同一张码永远同一台）：访客的
 *  localStorage（visitor_token）是按域隔离的，每次换域名会让老访客变成新访客、
 *  聊天记录接不上；所以只在「原主机已停用」时才换，并顺手落库。 */
function gate_content_host_for(string $code, string $fallback = ''): string
{
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT id, tenant_id, content_host FROM service_codes WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch();
        if ($row) {
            $cur = strtolower(trim((string)$row['content_host']));
            if ($cur !== '' && pool_host_usable($cur)) return $cur;
            /* 原主机已停用（红了）：换到「这个租户的专属域名」下的主机（没有就自动分配） */
            $dom = tenant_domain_for((int)$row['tenant_id']);
            $next = $dom !== '' ? pool_host_in($dom) : $fallback;
            if ($next !== '' && $next !== $cur) {
                $pdo->prepare('UPDATE service_codes SET content_host = ? WHERE id = ?')
                    ->execute([$next, (int)$row['id']]);
            }
            return $next !== '' ? $next : $fallback;
        }
    } catch (\Throwable $e) { /* 无库/写失败：本次仍用 fallback */ }
    return $fallback;
}

/** 真正下发给壳页的聊天页地址：固定内容主机 + 伪装静态路径 + 客服码 */
function gate_content_url(string $code, string $host = ''): string
{
    $host = gate_content_host_for($code, $host !== '' ? $host : (gate_pool_hosts(1)[0] ?? ''));
    $domain = pool_domain_of($host);
    $paths = gate_content_paths($domain !== '' ? $domain : $host);
    if (!$paths) $paths = ['/web/data/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.html'];
    $path = $paths[array_rand($paths)];
    // 不在这里带 code：壳页会自己拼 ?code=（避免出现两个 code 参数）
    return ($host !== '' ? 'https://' . $host : '') . $path;
}

/** 码无效/租户停用时的友好页（v2.68：不再跳百度、不再 444 断开） */
function gate_fail_page(string $msg = '链接已失效，请重新扫码进入'): string
{
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">'
        . '<meta name="referrer" content="no-referrer"><title>在线客服</title>'
        . '<style>html,body{height:100%;margin:0;background:#fff;color:#6b7280;'
        . 'font:15px/1.8 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}'
        . 'body{display:flex;align-items:center;justify-content:center;text-align:center;padding:0 28px}'
        . 'a{display:inline-block;margin-top:18px;padding:9px 22px;border-radius:999px;background:#07c160;'
        . 'color:#fff;text-decoration:none;font-size:14px}</style></head><body><div>'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
        . '<br><a href="javascript:location.reload()">重新加载</a></div></body></html>';
}

/** 数字载荷切片（防红壳/加密脚本共用）：切成固定宽度的模板字符串数组，
 *  拼接后仍是一串合法的「逗号分隔数字」，但静态抓取拿不到一整串连续数字。 */
function js_num_chunks(array $nums, int $width = 420): string
{
    $s = implode(',', $nums);
    $parts = [];
    $len = strlen($s);
    $start = 0;
    while ($start < $len) {
        $end = min($start + $width, $len);
        if ($end < $len) {
            $cut = strrpos(substr($s, $start, $end - $start), ',');   // 尽量切在逗号后
            if ($cut !== false && $cut > 0) $end = $start + $cut + 1;
        }
        $parts[] = substr($s, $start, $end - $start);
        $start = $end;
    }
    $out = [];
    foreach ($parts as $p) $out[] = '`' . $p . '`';
    return '[' . implode(',', $out) . '].join(\'\')';
}

/** v2.65.3 壳路由/访客页共用：环境像真人才放行（UA 缺失或非浏览器一律拒绝） */
function gate_env_ok(): bool
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua !== '' && str_contains($ua, 'Mozilla');
}

/* ===================== v2.71 反爬 / 反采集（三层） =====================
   目的：让「微信的自动检测」（爬虫预检、内容审核、安全扫描）尽量拿不到东西，
   同时不误伤真人访客。三层判断，命中任一层就给一张纯白纸（200，不跳转、不断连接）：
     ① UA 特征：爬虫/扫描器/自动化框架的关键字
     ② IP 段：后台可配 `gate_bot_ips`（逗号分隔 CIDR），例如腾讯/微信爬虫段
     ③ 一次性票据：/api/gate 发的票据，内容页凭票进入（防扒到链接后重复访问）
   注意：这些都只挡「自动检测」，挡不住真人举报——举报只能靠多域名摊。 */

/** 白纸页：比 444 断连接更"正常"（断连本身算异常特征），爬虫/SDK 拿到的就是一张空页 */
function gate_blank_page(): void
{
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="referrer" content="no-referrer"><title>id-' . random_int(1000, 9999) . '</title>'
        . '</head><body></body></html>';
    exit;
}

/** 客户端 IP 是否落在某个 CIDR 列表里（IPv4；列表形如 "1.2.3.0/24,5.6.7.8"） */
function ip_in_cidrs(string $ip, string $list): bool
{
    if ($ip === '' || $list === '') return false;
    $ipn = ip2long($ip);
    if ($ipn === false) return false;
    foreach (array_map('trim', explode(',', $list)) as $cidr) {
        if ($cidr === '') continue;
        if (!str_contains($cidr, '/')) { if ($ip === $cidr) return true; continue; }
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $netn = ip2long($net);
        $bits = (int)$bits;
        if ($netn === false || $bits < 0 || $bits > 32) continue;
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        if (($ipn & $mask) === ($netn & $mask)) return true;
    }
    return false;
}

/** 爬虫/扫描器判断：UA 关键字 + 可配置 IP 段（`gate_bot_ips`） */
function gate_is_bot(): bool
{
    $ua = strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($ua === '') return true;
    static $bad = ['bot', 'spider', 'crawler', 'slurp', 'curl/', 'wget', 'python', 'java/', 'go-http',
        'okhttp', 'headless', 'phantom', 'selenium', 'puppeteer', 'playwright', 'scrapy', 'requests/',
        'httpclient', 'libwww', 'httrack', 'winhttp', 'aria2', 'axios/', 'node-fetch', 'postman',
        'insomnia', 'zgrab', 'masscan', 'nmap', 'nuclei', 'sqlmap', 'dirbuster', 'gobuster',
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'bytespider', 'baiduspider', 'sogou',
        '360spider', 'yisouspider', 'applebot', 'petalbot', 'semrush', 'ahrefs', 'mj12', 'dotbot',
        'censys', 'shodan', 'expanse', 'internetmeasurement'];
    foreach ($bad as $b) { if (str_contains($ua, $b)) return true; }
    if (!str_contains($ua, 'mozilla')) return true;
    $crawlerIps = kv_get('gate_bot_ips', '');
    if ($crawlerIps !== '' && ip_in_cidrs(get_client_ip(), $crawlerIps)) return true;
    return false;
}

/** v2.71 一次性票据：/api/gate 发，内容页凭票进入（5 分钟内、最多 5 次，防扒链接复用） */
function gate_token_issue(string $code): string
{
    $tk = bin2hex(random_bytes(8));
    try { kv_set('gtok:' . $tk, $code . '|' . get_client_ip() . '|' . time() . '|0'); }
    catch (\Throwable $e) { }
    return $tk;
}

function gate_token_check(string $tk, string $code): bool
{
    if ($tk === '' || !preg_match('/^[a-f0-9]{16}$/', $tk)) return false;
    try {
        $raw = kv_get('gtok:' . $tk, '');
        if ($raw === '') return false;
        $p = explode('|', $raw);
        if (count($p) < 4 || (string)$p[0] !== $code) return false;
        if (time() - (int)$p[2] > 300) return false;
        $used = (int)$p[3];
        if ($used >= 5) return false;
        kv_set('gtok:' . $tk, $p[0] . '|' . $p[1] . '|' . $p[2] . '|' . ($used + 1));
        return true;
    } catch (\Throwable $e) { return false; }
}

/** v2.66 主机门禁：host 是否为「正在使用」的域名——启用中的域名池域名（含其子域）
 *  或激活中的专属链接子域。未知/已停用（红码退役）域名 → false → 服务器直接断开。
 *  库不可用时放行（fail-open，安装期/瞬断不能把主站打死），后续路径仍有各自的校验。 */
function pool_host_enabled(string $host): bool
{
    try {
        $pdo = db();
        $host = strtolower(trim($host));
        if ($host === '') return false;
        foreach ($pdo->query('SELECT domain, enabled FROM domain_pool') as $r) {
            $d = strtolower((string)$r['domain']);
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return (int)$r['enabled'] === 1;
            }
        }
        foreach ($pdo->query('SELECT subdomain FROM domain_links WHERE active = 1') as $r) {
            $s = strtolower((string)$r['subdomain']);
            if ($s !== '' && ($host === $s || str_starts_with($host, $s . '.'))) return true;
        }
        return false;
    } catch (\Throwable $e) {
        return true;   // fail-open
    }
}

/* ===================== v2.67 前端加密下发 ===================== */

/** 把 JS 源码打成 XOR(年份) 自解密 + djb2 完整性校验（防篡改）的脚本：
 *  静态抓包/直读文件只能看到数字阵列，看不到任何接口与业务逻辑；
 *  跨年自动失效重写，解密自带上一年回退。真实浏览器自动解密执行，无感。
 *  校验和按 UTF-8 字节计算（PHP 与 JS 两侧一致）；总失败时兜底加载外部脚本保可用。 */
function pack_js(string $src): string
{
    $year = (string)(int)date('Y');
    $n = strlen($src);
    $sum = 5381;
    for ($i = 0; $i < $n; $i++) $sum = (($sum << 5) + $sum + ord($src[$i])) & 0xFFFFFFFF;
    $nums = [];
    for ($i = 0; $i < $n; $i++) $nums[] = ord($src[$i]) ^ ord($year[$i % 4]) ^ (($i * 31 + 17) & 255);
    $fallback = 'var s2=document.createElement("script");s2.src="/assets/js/visitor.js?v=' . time() . '";'
        . 'document.body.appendChild(s2);';
    return '(function(){'
        . 'var _p=[' . implode(',', $nums) . '];var _s="' . dechex($sum) . '";'
        . 'var mk=function(y){var k=String(y);var b=new Uint8Array(_p.length);'
        . 'for(var i=0;i<_p.length;i++)b[i]=_p[i]^k.charCodeAt(i%4)^((i*31+17)&255);return b};'
        . 'var djb=function(b){var h=5381;for(var i=0;i<b.length;i++){h=((h<<5)+h+b[i])>>>0}return h.toString(16)};'
        . 'var y=new Date().getFullYear();var b=mk(y);'
        . 'if(djb(b)!==_s)b=mk(y-1);'
        . 'if(djb(b)!==_s){' . $fallback . 'return;}'
        . 'try{(0,eval)(new TextDecoder().decode(b))}catch(e){}'
        . '})();';
}

/** visitor.html 加密内联：把外部 visitor.js 引用替换为内联打包脚本（连 .js 请求都不发出，
 *  页面源码里没有任何明文业务逻辑）。仓库里的 assets/js/visitor.js 保持可读，协作者照常开发。
 *  正则放宽（任意带 visitor.js 的 script 标签都命中），协作者改版本号/加属性都不影响保护。 */
function visitor_page_packed(string $html): string
{
    $src = @file_get_contents(__DIR__ . '/assets/js/visitor.js');
    if ($src === false || $src === '') return $html;
    $packed = '<script>' . pack_js($src) . '</' . 'script>';
    return preg_replace('#<script src="[^"]*visitor\.js[^"]*"></script>#', $packed, $html, 1, $cnt);
}

/** v2.89.12 「门牌」白名单：允许哪些域名把防红壳页嵌进自己的 iframe。
 *  壳页靠 document.referrer 的 hostname 判定父页是谁（浏览器写的，伪造不了）。
 *  默认 = 域名池里所有启用的域名（它们本来就是我们的门牌/落地域），可按需加外部托管域。
 *  返回空数组 = 谁都不许嵌（等价于老行为）。 */
function gate_frame_allow_hosts(): array
{
    $hosts = [];
    try {
        foreach (db()->query('SELECT domain FROM domain_pool WHERE enabled = 1') as $r) {
            $d = strtolower(trim((string)$r['domain']));
            if ($d !== '') $hosts[] = $d;
        }
    } catch (\Throwable $e) { /* 库不可用就只留下面的固定项 */ }
    /* 大厂免费托管门牌：这些**整段**都放行，建了站直接能当门牌用，不用再改代码。
       2026-10-10 实测微信状态：netlify / vercel / pages.dev / workers.dev / github.io / gitlab.io /
       glitch.me / surge.sh / render / web.app / firebaseapp / fly.dev / herokuapp / wixsite /
       notion.site / wordpress / statically / jsdelivr / gitee.io / 腾讯云COS / 阿里云OSS / 七牛 / 百度BOS
       —— 全部 🟢 绿；**deno.dev、blogspot.com 已红，别用**。
       注：整段放行 = 别人也能拿这些域当门牌嵌壳页；但壳页要带有效入口 ID 才有内容，风险可控。 */
    foreach ([
        'netlify.app', 'vercel.app', 'pages.dev', 'workers.dev', 'github.io', 'gitlab.io',
        'glitch.me', 'surge.sh', 'onrender.com', 'web.app', 'firebaseapp.com', 'fly.dev',
        'herokuapp.com', 'wixsite.com', 'notion.site', 'wordpress.com', 'statically.io',
        'jsdelivr.net', 'gitee.io', 'myqcloud.com', 'aliyuncs.com', 'clouddn.com', 'bcebos.com',
    ] as $extra) $hosts[] = $extra;
    return array_values(array_unique($hosts));
}

/** 防红壳 Loader（v2.68 重做）
 *  外层：零资源引用的 XOR(年份) 载荷 —— 数字切成固定宽度分片（静态抓取拿不到连续数字串），
 *        另加两条大厂 CDN 的 jquery 引用（域名信誉 / 干扰静态扫描，不依赖它们执行）。
 *  内层（解密后才存在）：环境检查 → 拿「入口 ID」调 /api/gate 换「当前客服码 + 内容地址」
 *        → 全屏 iframe 装载聊天页；地址栏始终停在二维码里那个入口路径上。
 *  失败处理：同一域名重试 3 次 → 自动换到备用域名（同一入口 ID 通用）→ 都不行才给
 *        「链接已失效，请重新扫码进入」。不再跳百度、不再断连接。
 *  $apiBase：静态边缘烤制时传总控地址（跨域直连），动态出壳传空串（同源/反代）。 */
function gate_loader_html(string $apiBase = ''): string
{
    $year = (int)date('Y');
    $alts = gate_alt_hosts(6);
    $frameAllow = gate_frame_allow_hosts();   // v2.89.12 「门牌」白名单：只有这些域名能把壳页嵌进 iframe
    $brands = [
        'https://www.tencent.com/js/jquery.min.js',
        'https://www.sinaimg.cn/jslib/modules2/gallery/jquery/1.8.2/jquery.js',
    ];
    $brandTags = '';
    foreach ($brands as $bu) $brandTags .= '<script src="' . $bu . '" async></script>';

    // 内层（解密后才会出现在浏览器里）
    $inner = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">'
        . '<meta name="referrer" content="no-referrer"><title>加载中</title>'
        . '<style>html,body{height:100%;margin:0;background:#fff;color:#9aa3af;'
        . 'font:14px/1.7 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}'
        . '.ld{position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:0 28px}'
        . '.ld .rl{margin-top:14px;color:#07c160;text-decoration:underline;cursor:pointer}'
        . 'iframe{position:fixed;inset:0;width:100%;height:100%;border:0;margin:0;padding:0}</style></head>'
        . '<body><div class="ld" id="ld">正在进入…</div><script>(function(){'
        . 'var q=new URLSearchParams(location.search);'
        . 'var t=q.get("w")||q.get("key")||q.get("k")||"";'
        /* v2.89 随机参数名：固定名认不出来时，取任意一个「看起来像入口 ID」的参数值 */
        . 'if(!t){var SK={w:1,key:1,k:1,d:1,t:1,r:1,code:1,pf:1,tk:1};var it=q.entries();var c;'
        . 'while(!(c=it.next()).done){var kk=c.value[0],vv=c.value[1];'
        . 'if(SK[kk.toLowerCase()]||kk.charAt(0)==="_")continue;'
        . 'if(/^[A-Za-z0-9]{6,32}$/.test(vv)){t=vv;break;}}}'
        . 'try{var d=q.get("d");if(!t&&d){var s=atob(d);var m=s.match(/(?:^|[?&])(?:w|key)=([A-Za-z0-9]+)/);t=m?m[1]:s;}}catch(e){}'
        . 'var ALT=' . json_encode(array_values($alts), JSON_UNESCAPED_SLASHES) . ';'
        . 'var FA=' . json_encode(array_values($frameAllow), JSON_UNESCAPED_SLASHES) . ';'
        . 'var AB=' . json_encode($apiBase) . ';'
        . 'var box=document.getElementById("ld");var done=false;'
        /* v2.89 进入成功判定 + 内容主机连不上时的自愈：
           访客页挂载后会 postMessage 报到（kf=ready）；7 秒没报到就认为这台内容主机不行 ——
           上报服务端（复核后会把这台退役换一台），自己再要一次地址重试一次。 */
        . 'var okIn=false;var rdy=null;var retried=false;var CD="";'
        . 'function hideLoad(){okIn=true;if(rdy){clearTimeout(rdy);rdy=null;}if(box)box.style.display="none";}'
        . 'window.addEventListener("message",function(e){var dd=e.data;if(dd&&dd.kf==="ready"){hideLoad();}});'
        . 'function reportErr(reason,host){try{'
        . 'if(navigator.sendBeacon&&window.FormData){var fd=new FormData();fd.append("code",CD);fd.append("host",host||"");fd.append("r",reason);navigator.sendBeacon(AB+"/api/gate/err",fd);return;}'
        . 'var x=new XMLHttpRequest();x.open("POST",AB+"/api/gate/err",true);'
        . 'x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");'
        . 'x.send("code="+encodeURIComponent(CD)+"&host="+encodeURIComponent(host||"")+"&r="+encodeURIComponent(reason));}catch(e){}}'
        /* 已经试过的域名（写在链接参数里跨域名传递，避免 A→B→A 来回死循环） */
        . 'var tried=(q.get("_t")||"").split(",").filter(Boolean);'
        . 'if(tried.indexOf(location.hostname)<0)tried.push(location.hostname);'
        . 'function msg(s){if(okIn)return;done=true;if(box){box.style.display="";box.innerHTML="";}var p=document.createElement("div");'
        . 'p.textContent=(s||"链接已失效，请重新扫码进入");var a=document.createElement("div");'
        . 'a.className="rl";a.textContent="点击重新加载";a.onclick=function(){location.reload()};'
        . 'if(box){box.appendChild(p);box.appendChild(a);}}'
        /* 只在「连服务器都连不上」时换域名（域名被拦/被墙）；码本身失效换域名也救不回来 */
        . 'function nextHost(){if(okIn)return;if(tried.length>ALT.length+2){msg();return;}'
        . 'for(var i=0;i<ALT.length;i++){var h=ALT[i];'
        . 'if(!h||tried.indexOf(h)>=0)continue;'
        . 'var sep=location.search?location.search+"&":"?";'
        . 'location.replace("https://"+h+location.pathname+sep+"_t="+encodeURIComponent(tried.concat(h).join(",")));'
        . 'return;}'
        . 'msg();}'
        . 'function mount(r){var u=(r.url||"/visitor.html");if(u.indexOf("http")!==0)u=AB+u;'
        . 'CD=r.code||CD;'
        . 'var full=u+(u.indexOf("?")<0?"?":"&")+"code="+encodeURIComponent(r.code||"")+(r.tk?"&t="+encodeURIComponent(r.tk):"");'
        . 'var chost="";try{chost=(new URL(full)).host}catch(e){}'
        . 'var f=document.createElement("iframe");'
        . 'f.src=full;'
        . 'f.setAttribute("referrerpolicy","no-referrer");'
        . 'f.setAttribute("allow","microphone; autoplay; clipboard-write; fullscreen");'
        . 'document.body.appendChild(f);'
        . 'if(rdy)clearTimeout(rdy);'
        /* v2.89.2 4.5 秒还在转就先说一句「网络较慢，正在重试」，别让人对着「正在进入…」发呆 */
        . 'setTimeout(function(){if(okIn||done)return;if(box)box.textContent="网络较慢，正在重试…";},4500);'
        . 'rdy=setTimeout(function(){if(okIn)return;reportErr("frame-timeout",chost);'
        . 'if(!retried){retried=true;try{f.parentNode.removeChild(f)}catch(e){}ask(0);return;}'
        . 'msg("网络较慢，没能进入聊天页，请点击重试");},7000);}'
        . 'var pf=function(k){var h=5381,s=k+"|' . $year . '|kfxt";for(var i=0;i<s.length;i++){h=((h<<5)+h+s.charCodeAt(i))>>>0}return h.toString(16)};'
        . 'function ask(n){var x=new XMLHttpRequest();x.open("POST",AB+"/api/gate",true);'
        . 'x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.timeout=6000;'
        . 'x.onreadystatechange=function(){if(x.readyState!==4)return;var r=null;'
        . 'if(x.status===200){'
        . '  try{r=JSON.parse(x.responseText)}catch(e){}'
        . '  if(r&&r.ok&&r.code){mount(r);return;}'
        /* 服务器明确回话了（码废了/租户停用/到期）→ 直接给一句人话，换域名没有意义 */
        . '  msg(r&&r.msg);return;}'
        /* 连不上/报错（域名被拦、超时、断连）→ 同域名重试 2 次，再换备用域名 */
        . 'if(n<2){setTimeout(function(){ask(n+1)},300);return;}'
        . 'nextHost();};'
        . 'x.send("w="+encodeURIComponent(t)+"&key="+encodeURIComponent(t)+"&pf="+encodeURIComponent(pf(t)));}'
        . 'if(!t){msg();return;}'
        . 'if(navigator.webdriver||!/Mozilla/.test(navigator.userAgent||"")){msg();return;}'
        /* v2.89.12 「门牌」模式：允许被自己的门牌页 iframe 装载（地址栏停在门牌域名上）。
           老逻辑是「只要在 iframe 里就罢工」→ 门牌整条路走不通。
           现在改成认人：父页 referrer 命中白名单才放行，别人盗嵌照旧吃「在线客服」白屏。 */
        . 'if(window.top!==window.self){'
        . 'var _rh="";try{_rh=(new URL(document.referrer||"")).hostname.toLowerCase()}catch(e){}'
        . 'var _ok=false;for(var _i=0;_i<FA.length;_i++){var _h=FA[_i];'
        . 'if(_h&&(_rh===_h||_rh.slice(-(_h.length+1))==="."+_h)){_ok=true;break}}'
        . 'if(!_ok){document.title="在线客服";return;}}'
        . 'ask(0);'
        . '})();</' . 'script></body></html>';

    // XOR(年份) 打包：外层只有分片的数字（跨年自动失效重写，解密自带上一年回退）
    $keyStr = (string)$year;
    $n = strlen($inner);
    $nums = [];
    for ($i = 0; $i < $n; $i++) {
        $nums[] = ord($inner[$i]) ^ ord($keyStr[$i % 4]) ^ (($i * 31 + 17) & 255);
    }
    $outer = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="referrer" content="no-referrer"><meta name="robots" content="noindex">'
        . '<title>id-' . random_int(1000, 9999) . '</title>'
        . $brandTags . '</head>'
        . '<body><script>(function(){'
        . 'var _p=' . js_num_chunks($nums) . '.split(",").map(Number);'
        . 'var _y=String(new Date().getFullYear());'
        . 'var dec=function(y){var k=String(y);var b=new Uint8Array(_p.length);'
        . 'for(var i=0;i<_p.length;i++)b[i]=_p[i]^k.charCodeAt(i%4)^((i*31+17)&255);'
        . 'return new TextDecoder().decode(b)};'
        . 'var t=dec(_y);if(t.indexOf("<")!==0){try{t=dec(_y-1)}catch(e){}}'
        . 'try{document.open();document.write(t);document.close()}catch(e){}'
        . '})();</' . 'script></body></html>';
    return $outer;
}

/** 访客默认名：月-日 编号 */
function gen_visitor_name(int $tenantId): string
{
    $today = date('n-j');
    $n = fetch_count(db(), 'SELECT COUNT(*) FROM v_sessions WHERE tenant_id = ? AND date(last_time) = ?',
        [$tenantId, date('Y-m-d')]) + 1;
    return $today . ' ' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** CSV 输出 */
function csv_escape($v): string
{
    $s = (string)($v ?? '');
    if (preg_match('/[",\n\r]/', $s)) return '"' . str_replace('"', '""', $s) . '"';
    return $s;
}

function send_csv(string $filename, array $header, array $rows): void
{
    $lines = [implode(',', array_map('csv_escape', $header))];
    foreach ($rows as $r) $lines[] = implode(',', array_map('csv_escape', $r));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF" . implode("\n", $lines);
    exit;
}

/* ---------- v2.90.2 会话合并别名 ----------
   客服把 A 会话并进 B 会话后，A 的行会被删掉；如果客户手上那个旧窗口还开着，
   它继续用 A 的 id 轮询/发消息就会落空（看到的是一段空记录、发出去的消息也丢了）。
   这里记一条 A → B 的别名，访客端所有带会话 id 的接口都先过一遍别名，
   客户那个旧窗口不用刷新就能看到合并后的完整记录。 */

/** 顺着合并别名找到当前真正在用的会话 id（最多追 5 跳，防环） */
function session_alias_follow(int $sid): int
{
    if ($sid <= 0) return $sid;
    $pdo = db();
    for ($i = 0; $i < 5; $i++) {
        $st = $pdo->prepare('SELECT new_id FROM session_merge_alias WHERE old_id = ?');
        $st->execute([$sid]);
        $next = (int)($st->fetchColumn() ?: 0);
        if ($next <= 0 || $next === $sid) return $sid;
        $sid = $next;
    }
    return $sid;
}

/** 合并时老会话留下的 visitor_token（老窗口仍然算有效，不然后续请求会被判「会话已失效」） */
function session_alias_token(int $sid): string
{
    if ($sid <= 0) return '';
    try {
        $st = db()->prepare('SELECT token FROM session_merge_alias WHERE old_id = ?');
        $st->execute([$sid]);
        return (string)($st->fetchColumn() ?: '');
    } catch (\Throwable $e) {
        return '';
    }
}

/** 访客端接口统一入口：拿原始会话 id，返回 [实际用的会话 id, 可接受的老 token] */
function visitor_sid_resolve(int $sidRaw): array
{
    $sid = session_alias_follow($sidRaw);
    return [$sid, ($sid === $sidRaw ? '' : session_alias_token($sidRaw))];
}

/** v2.90.3 归属地渐进补查：把列表里还没解析出地区的会话补上（写回 v_sessions.geo）。
 *  一次最多补 $max 条，避免拖慢轮询/列表；每条 IP 的解析结果有 24h 缓存，补过一次就不再花钱。
 *  补完顺手把这一轮的 city / region 也重算，前端不用等下一轮才显示。 */
function geo_backfill(PDO $pdo, array &$sessions, int $max = 3): void
{
    if (!$sessions) return;
    try {
        $need = [];
        foreach ($sessions as $row) {
            if (count($need) >= max(1, $max)) break;
            if (empty($row['geo']) && !empty($row['ip'])) $need[(int)$row['id']] = (string)$row['ip'];
        }
        foreach ($need as $sidG => $ipG) {
            $g = lookup_geo($ipG);
            if ($g === '') continue;
            try { $pdo->prepare('UPDATE v_sessions SET geo = ? WHERE id = ?')->execute([$g, $sidG]); }
            catch (\Throwable $e) { continue; }
            foreach ($sessions as &$rowUpd) {
                if ((int)$rowUpd['id'] === $sidG) {
                    $rowUpd['geo'] = $g;
                    $rowUpd['city'] = geo_city($g);
                    $rowUpd['region'] = geo_region($g);
                    break;
                }
            }
            unset($rowUpd);
        }
    } catch (\Throwable $e) { /* 补查失败不影响主流程 */ }
}
