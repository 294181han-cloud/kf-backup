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
    $pdo->prepare('INSERT INTO nodes (ip, role, app_version, origin, first_seen, last_seen)
                   VALUES (?,?,?,?,NOW(),NOW())
                   ON DUPLICATE KEY UPDATE role = VALUES(role), app_version = VALUES(app_version),
                                           origin = VALUES(origin), last_seen = NOW()')
        ->execute([$ip, $role, $ver, $origin]);
    json_out(['ok' => true, 'main_version' => app_version()]);
}

/** 分控节点侧：接收主控推送的更新指令（git pull；PHP 未开放 shell_exec 时提示走 cron） */
function handle_internal_update(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $cfg = config();
    $secret = (string)($cfg['relay_secret'] ?? '');
    if ($secret === '') json_error('未启用远程更新', 403);
    $given = (string)input('secret', '');
    if ($given === '' || !hash_equals($secret, $given)) json_error('无权调用', 403);
    if (!function_exists('shell_exec')) {
        json_out(['ok' => false, 'need_cron' => true, 'error' => '该节点 PHP 未开放 shell_exec，请依赖 cron 自动更新（每 5 分钟）']);
    }
    @set_time_limit(120);
    $out = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git pull --autostash -q 2>&1');
    json_out(['ok' => true, 'app_version' => app_version(), 'output' => (string)$out]);
}

/* ================= 枢纽模式：内存态 / 白名单 / 黑名单同步（v2.34） ================= */

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
                   ON DUPLICATE KEY UPDATE role = VALUES(role), origin = VALUES(origin), last_seen = NOW()')
        ->execute([$nodeIp, $role, app_version(), $origin]);
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
        return ['type' => 'image', 'url' => $cfg['upload_url'] . '/' . $name, 'size' => strlen($buffer)];
    }

    if ($kind === 'audio' && $isAudio) {
        $ext = audio_ext_of($mime, $filename);
        $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $buffer) === false) throw new \RuntimeException('写入文件失败');
        return ['type' => 'audio', 'url' => $cfg['upload_url'] . '/' . $name, 'size' => strlen($buffer)];
    }

    // 视频：只放行常见可播格式，前端用 <video> 直接播
    if ($kind === 'video' && ($isVideo || preg_match('#^application/octet-stream$#i', $mime))) {
        $ext = media_ext_of($mime, $filename, ['mp4', 'webm', 'ogg', 'mov', 'm4v'], 'mp4');
        $name = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents("$dir/$name", $buffer) === false) throw new \RuntimeException('写入文件失败');
        return ['type' => 'video', 'url' => $cfg['upload_url'] . '/' . $name, 'size' => strlen($buffer),
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
        return ['type' => 'file', 'url' => $cfg['upload_url'] . '/' . $name, 'size' => strlen($buffer), 'name' => $orig];
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
    if (!is_array($d) || ($d['err'] ?? '') !== '' || ($d['pro'] ?? '') === '') return '';
    $pro  = geo_clean((string)$d['pro']);
    $city = geo_clean((string)$d['city']);
    $reg  = geo_clean((string)$d['region']);       // 区县（如 普宁市）
    $addr = trim((string)($d['addr'] ?? ''));
    $isp  = geo_isp_norm($addr);
    if ($city === '' && $reg !== '') $city = $reg;  // 只有区县时用区县
    return implode('|', ['中国', $pro !== '' ? $pro : '0', $city !== '' ? $city : '0',
        $isp !== '' ? $isp : '0', 'CN']);
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
    $cacheFile = sys_get_temp_dir() . '/kfxt_geo_v2_' . md5($ip) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $v = @file_get_contents($cacheFile);
        if ($v !== false && $v !== '') return $v;
    }
    // 国内 IP 走国内库（城市/区县/运营商更准），海外或失败再走 ip-api
    // ① 有 ipip token 就优先用（城域网/手机 IP 最准）
    $out = geo_lookup_ipip($ip);
    if ($out !== '') { @file_put_contents($cacheFile, $out); return $out; }
    $cn1 = geo_lookup_cn($ip);        // 太平洋（可到区县）
    $cn2 = geo_lookup_taobao($ip);    // 淘宝/阿里
    $out = '';
    if ($cn1 !== '' || $cn2 !== '') {
        $a = $cn1 !== '' ? explode('|', $cn1) : null;
        $b = $cn2 !== '' ? explode('|', $cn2) : null;
        if ($a && $b) {
            $ca = geo_name_key($a[2]); $cb = geo_name_key($b[2]);
            $pa = geo_name_key($a[1]); $pb = geo_name_key($b[1]);
            $prov = $a[1] !== '0' ? $a[1] : $b[1];
            $isp  = $a[3] !== '0' ? $a[3] : $b[3];
            if ($ca !== '' && $cb !== '' && $ca === $cb) {
                // 两个库城市一致 → 可信，用城市
                $out = implode('|', ['中国', $prov, $a[2] !== '0' ? $a[2] : $b[2], $isp, 'CN']);
            } elseif ($pa !== '' && $pa === $pb) {
                // 省级一致但市级不一致（多为移动/城域网 IP）→ 只显示到省，不给错城市
                $out = implode('|', ['中国', $prov, '0', $isp, 'CN']);
            } else {
                $out = $a[4] === 'CN' ? $cn1 : $cn2;   // 连省都不一致 → 取国内库原值
            }
        } else {
            $out = $cn1 !== '' ? $cn1 : $cn2;
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
        'on'   => kv_get('wxcheck_on', '0') === '1',
        'user' => kv_get('wxcheck_user', ''),
        'key'  => kv_get('wxcheck_key', ''),
        'url'  => rtrim(kv_get('wxcheck_url', 'https://api.uouin.com/app'), '/'),
        'ttl'  => max(60, (int)kv_get('wxcheck_ttl', '300')),
    ];
}

/* ================= 微信/QQ 拦截检测（v2.64 抽成公共函数，供 API 与定时巡检共用） =================
   返回 ['state' => ok|red|off|err, 'tag' => 中文, 'text' => 一句话, 'msg' => 详情]
   state=off 表示平台没开通/没配账号；err 表示接口异常。
   注意：**每次调用都会真实请求第三方接口（按次计费）**，调用方自己控制频率。 */
function wxcheck_run(string $link, string $channel = 'wx'): array
{
    $chName = $channel === 'qq' ? 'QQ' : '微信';
    $cfg = wxcheck_cfg();
    if (!$cfg['on']) {
        return ['state' => 'off', 'tag' => '未开通', 'text' => $chName . '检测未开通',
            'msg' => '平台未开通拦截检测，请联系管理员'];
    }
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
