<?php
/**
 * helpers.php - 工具函数（JWT / 上传 / 限频 / 通用）
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function config(): array
{
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
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
        if ($t['status'] !== 'active') json_error('账号已被停用，请联系平台管理员', 403);
        if ($t['expires_at'] && strtotime($t['expires_at']) < time()) json_error('账号已到期，请联系平台管理员续期', 403);
        return ['role' => 'tenant', 'id' => (int)$t['id'], 'kami' => $t['kami'], 'name' => $t['nickname'], 'row' => $t];
    }

    if ($p['role'] === 'agent') {
        $st = $pdo->prepare('SELECT a.*, t.kami AS tenant_kami, t.status AS tenant_status, t.expires_at AS tenant_expires FROM agents a JOIN tenants t ON t.id = a.tenant_id WHERE a.id = ?');
        $st->execute([(int)$p['id']]);
        $a = $st->fetch();
        if (!$a) json_error('账号不存在', 401);
        if ($a['status'] !== 'active') json_error('客服账号已被停用', 403);
        if ($a['tenant_status'] !== 'active') json_error('所属账号已被停用', 403);
        if ($a['tenant_expires'] && strtotime($a['tenant_expires']) < time()) json_error('所属账号已到期，请联系客服续期', 403);
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
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($ip) $ip = explode(',', $ip)[0];
    $ip = trim($ip ?: ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '::1') $ip = '127.0.0.1';
    if (str_starts_with($ip, '::ffff:')) $ip = substr($ip, 7);
    return $ip ?: '127.0.0.1';
}

function is_ip_blocked(int $tenantId, string $ip): bool
{
    if (!$ip) return false;
    return (bool)fetch_count(db(), 'SELECT 1 FROM ip_blacklist WHERE tenant_id = ? AND ip = ? LIMIT 1', [$tenantId, $ip]);
}

/* ================= 附件 / 消息 ================= */

/* ================= 访客头像（动物 emoji） ================= */

/** 头像池：全部单码位动物 emoji，避免不同设备渲染差异 */
function visitor_avatar_pool(): array
{
    return ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🐔',
        '🐧','🐦','🐤','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞',
        '🐢','🐍','🦎','🐙','🦑','🦐','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🐊','🐅',
        '🐆','🦓','🦍','🐘','🦏','🦛','🐪','🐫','🦒','🦘','🐃','🐄','🐎','🐖','🐏','🐑',
        '🦙','🐐','🦌','🐕','🐈','🐓','🦃','🦚','🦜','🦢','🦩','🐇','🦝','🦦','🐁','🐀','🦔'];
}

/** 会话头像：优先用已分配的，其次按会话 id 稳定派生一个（老会话也有头像，且客服端/访客端一致） */
function visitor_avatar_resolve(int $sessionId, string $stored = ''): string
{
    $stored = trim($stored);
    if ($stored !== '') return $stored;
    $pool = visitor_avatar_pool();
    return $pool[abs($sessionId) % count($pool)];
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
    unset($r['created_at'], $r['recalled_at'], $r['read_at'], $r['tenant_id']);
    return $r;
}

function resolve_msg_type(string $text, array $attachments): string
{
    if (!$attachments) return 'text';
    if ($text === '') {
        $first = $attachments[0]['type'] ?? '';
        return $first === 'audio' ? 'audio' : 'image';
    }
    return 'mixed';
}

/** 消息摘要（会话列表用） */
function msg_summary(string $text, array $attachArr): string
{
    if ($text !== '') return mb_substr($text, 0, 60);
    $t = $attachArr[0]['type'] ?? '';
    return $t === 'audio' ? '[语音]' : '[图片]';
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

function save_upload(string $data, string $kind, string $filename = ''): array
{
    $cfg = config();
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

    $maxBytes = $kind === 'audio' ? $cfg['upload_max_audio'] : $cfg['upload_max_image'];
    if (strlen($buffer) > $maxBytes) {
        throw new \RuntimeException($kind === 'audio' ? '语音不能超过 10MB' : '图片不能超过 5MB');
    }

    $isImage = (bool)preg_match('#^image/(png|jpe?g|gif|webp)$#i', $mime);
    $isAudio = (bool)preg_match('#^(audio/|video/webm)#i', $mime);

    // 服务器禁用 shell_exec，语音不做转码，直接保存原始格式（前端选可播格式录制）
    if ($kind === 'image' && $isImage) {
        $ext = str_ireplace('jpeg', 'jpg', explode('/', $mime)[1]);
        $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $buffer) === false) throw new \RuntimeException('写入文件失败');
        return ['type' => 'image', 'url' => $cfg['upload_url'] . '/' . $name, 'size' => strlen($buffer)];
    }

    if ($kind === 'audio' && $isAudio) {
        $ext = audio_ext_of($mime, $filename);
        $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $buffer) === false) throw new \RuntimeException('写入文件失败');
        return ['type' => 'audio', 'url' => $cfg['upload_url'] . '/' . $name, 'size' => strlen($buffer)];
    }

    throw new \RuntimeException('文件格式不支持：' . ($mime ?: '未知'));
}

/* ================= IP 归属地（ip-api.com，带文件缓存） ================= */

function lookup_geo(string $ip): string
{
    if (!$ip || $ip === '127.0.0.1') return '';
    $cacheFile = sys_get_temp_dir() . '/kfxt_geo_zh_' . md5($ip) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $v = @file_get_contents($cacheFile);
        if ($v !== false && $v !== '') return $v;
    }
    $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,regionName,city,isp,countryCode&lang=zh-CN');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $d = $body ? json_decode($body, true) : null;
    if ($d && ($d['status'] ?? '') === 'success') {
        // 格式：国家|地区|城市|ISP|国家码（与示例 United States|New York|0|0|US 对齐，缺失项为 0）
        $parts = [$d['country'] ?? '0', $d['regionName'] ?? '0', $d['city'] ?: '0', $d['isp'] ?? '0', $d['countryCode'] ?? '0'];
        $out = implode('|', $parts);
        @file_put_contents($cacheFile, $out);
        return $out;
    }
    return '';
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
        // 海外：城市/地区即可（国家在悬停全文里）
        $out = $city !== '' ? $city : $prov;
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
