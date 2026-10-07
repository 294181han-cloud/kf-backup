<?php
/**
 * index.php - 唯一入口 / 路由分发
 *
 * 部署：站点根目录。Nginx 将非静态请求 rewrite 到本文件；
 * assets/ uploads/ 静态资源由 Nginx 直接命中（本文件提供 PHP 兜底）。
 */
declare(strict_types=1);

// 生产环境：任何 Warning/Notice 都不得混入 JSON 响应（避免前端解析失败）
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/helpers.php';

set_exception_handler(function ($e) {
    if (!headers_sent()) http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

/* ---------- 首次安装（建表 + 种子，幂等；用安装锁避免每次请求执行） ---------- */
function ensure_installed(): void
{
    $lock = __DIR__ . '/data/installed.lock';
    if (is_file($lock)) { ensure_upgrade(); return; }
    init_schema();
    init_seed();
    ensure_upgrade();
    if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0755, true);
    @file_put_contents($lock, date('Y-m-d H:i:s'));
}

/** 幂等升级（对已安装库做增量迁移；幂等建表执行很快，可每请求调用） */
function ensure_upgrade(): void
{
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_login_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            role VARCHAR(16) NOT NULL DEFAULT 'tenant',
            ident VARCHAR(64) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            ua VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tenant (tenant_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // v_sessions 增加访客 UA（用于访客资料：系统/浏览器）
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN ua VARCHAR(500) NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* 列已存在 */ }
        // messages 增加已读时间（已读回执）与撤回时间
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN read_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN recalled_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE messages ADD INDEX idx_msg_read (session_id, from_type, read_at)"); } catch (\Throwable $e) { /* 已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN visitor_avatar VARCHAR(255) NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("UPDATE v_sessions SET geo = '' WHERE geo LIKE '%|%'"); } catch (\Throwable $e) { /* 旧英文格式清空 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN geo VARCHAR(150) NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* 列已存在 */ }
        // v2.25 三级会话：已结束会话自动清理时间 + 归档时间；租户级会话总数上限
        try { $pdo->exec("ALTER TABLE tenants ADD COLUMN closed_keep_days INT UNSIGNED NOT NULL DEFAULT 3"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE tenants ADD COLUMN max_sessions INT UNSIGNED NOT NULL DEFAULT 0"); } catch (\Throwable $e) { /* 列已存在（0=不限，存量租户默认不限） */ }
        // v2.72 发卡机器人开出来的卡：记下买家的 Telegram ID（后台租户列表要显示 TG 用户名）
        try { $pdo->exec("ALTER TABLE tenants ADD COLUMN tg_id BIGINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE tenants ADD INDEX idx_tg (tg_id)"); } catch (\Throwable $e) { /* 已存在 */ }
        // v2.56 消息可编辑：记下编辑时间（前端据此显示「已编辑」并双向同步）
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN edited_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN closed_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN archived_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD INDEX idx_status_time (tenant_id, status, last_time)"); } catch (\Throwable $e) { /* 已存在 */ }
        // v2.43 消息引用回复 + 会话置顶
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN reply_to INT UNSIGNED NOT NULL DEFAULT 0"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN pinned TINYINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN pinned_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        // v2.60.9 访客「恢复码」：客户换浏览器/换设备后，用客服给的码把原对话接回来
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN resume_code VARCHAR(16) NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD UNIQUE KEY uk_resume_code (resume_code)"); } catch (\Throwable $e) { /* 索引已存在 */ }
        // v2.65 废码自动断开：会话记下发码来源，轮询时校验码/租户仍生效，失效即让前端断开
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN code VARCHAR(32) NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* 列已存在 */ }
        // v2.68 入口 ID：二维码里印的是它（不是客服码）——换码/换内容域名都不用重印二维码。
        try { $pdo->exec("ALTER TABLE service_codes ADD COLUMN entry_id VARCHAR(16) NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE service_codes ADD UNIQUE KEY uk_entry_id (entry_id)"); } catch (\Throwable $e) { /* 索引已存在 */ }
        // v2.68 内容落地主机：一张码固定一台（访客 localStorage 按域隔离，换域会丢会话）
        try { $pdo->exec("ALTER TABLE service_codes ADD COLUMN content_host VARCHAR(120) NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* 列已存在 */ }
        /* v2.70 租户专属落地域名（自动分配）：把一个租户固定在同一台域名上，
           某台域名红了只影响它自己，不再"一个域名红了全体陪葬"。
           存量租户按他现在正在用的域名回填（不打扰、不用重发二维码）。 */
        try { $pdo->exec("ALTER TABLE tenants ADD COLUMN content_domain VARCHAR(120) NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* 列已存在 */ }
        try {
            $unbound = $pdo->query("SELECT t.id, sc.link FROM tenants t
                JOIN service_codes sc ON sc.tenant_id = t.id
                WHERE t.content_domain = ''")->fetchAll();
            if ($unbound) {
                $upDom = $pdo->prepare('UPDATE tenants SET content_domain = ? WHERE id = ? AND content_domain = \'\'');
                foreach ($unbound as $ub) {
                    $dom = pool_domain_of((string)(parse_url((string)$ub['link'], PHP_URL_HOST) ?: ''));
                    if ($dom !== '') $upDom->execute([$dom, (int)$ub['id']]);
                }
            }
        } catch (\Throwable $e) { /* 迁移失败下轮再试 */ }
        try {
            $needIds = $pdo->query("SELECT id FROM service_codes WHERE entry_id IS NULL OR entry_id = ''")->fetchAll(PDO::FETCH_COLUMN);
            if ($needIds) {
                $upEntry = $pdo->prepare('UPDATE service_codes SET entry_id = ? WHERE id = ?');
                foreach ($needIds as $rid) $upEntry->execute([gen_entry_id(), (int)$rid]);
            }
        } catch (\Throwable $e) { /* 迁移失败下轮请求再试 */ }
        // v2.60.10 登录设备（防卡密共享）：默认 3 台
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_devices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_key VARCHAR(24) NOT NULL,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
            dev_id VARCHAR(32) NOT NULL,
            note VARCHAR(32) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            ua VARCHAR(200) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_owner_dev (owner_key, dev_id),
            KEY idx_owner_seen (owner_key, last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE tenants ADD COLUMN max_devices INT UNSIGNED NOT NULL DEFAULT 3"); } catch (\Throwable $e) { /* 列已存在 */ }
        // v2.60.11 会话回收站：删除会话改成软删除（30 天内可自己恢复，也能立刻撤销）
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN deleted_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD INDEX idx_deleted (tenant_id, deleted_at)"); } catch (\Throwable $e) { /* 索引已存在 */ }
        // v2.61 aaPanel 快速导入：domain_pool 增加类型（fixed=固定随机子域池主域）；kv_store sval 扩容
        //（64 个固定子域的逗号列表约 600 字符，VARCHAR(255) 放不下会静默截断）
        try { $pdo->exec("ALTER TABLE domain_pool ADD COLUMN dtype VARCHAR(16) NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE kv_store MODIFY sval TEXT NOT NULL"); } catch (\Throwable $e) { /* 已是 TEXT */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD INDEX idx_tenant_pinned (tenant_id, pinned, last_time)"); } catch (\Throwable $e) { /* 已存在 */ }
        // v2.33/v2.39 分域节点登记：同一台服务器可同时跑客服入口+访客站点，唯一键 = IP+角色
        $pdo->exec("CREATE TABLE IF NOT EXISTS nodes (
            ip VARCHAR(64) NOT NULL,
            role VARCHAR(16) NOT NULL DEFAULT '',
            app_version VARCHAR(32) NOT NULL DEFAULT '',
            origin VARCHAR(200) NOT NULL DEFAULT '',
            first_seen DATETIME NULL,
            last_seen DATETIME NULL,
            UNIQUE KEY uq_node (ip, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE nodes DROP PRIMARY KEY, ADD UNIQUE KEY uq_node (ip, role)"); } catch (\Throwable $e) { /* 已迁移 */ }
        // v2.64 微信红码提醒（每小时巡检脚本写入，工作台弹窗提示后标记 seen）
        $pdo->exec("CREATE TABLE IF NOT EXISTS code_alerts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            code VARCHAR(64) NOT NULL DEFAULT '',
            link VARCHAR(500) NOT NULL DEFAULT '',
            msg VARCHAR(500) NOT NULL DEFAULT '',
            seen TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tenant_seen (tenant_id, seen),
            KEY idx_code (tenant_id, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // v2.63 已红域名名单：客服码/巡检测到红 → 自动退役（子域从固定子域池摘除 / 主域停用池行）并记入此表
        $pdo->exec("CREATE TABLE IF NOT EXISTS red_domains (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            host VARCHAR(128) NOT NULL,
            main VARCHAR(128) NOT NULL DEFAULT '',
            kind VARCHAR(8) NOT NULL DEFAULT '',
            code VARCHAR(64) NOT NULL DEFAULT '',
            channel VARCHAR(8) NOT NULL DEFAULT 'wx',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_host_channel (host, channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // v2.61 逐条/批量「检测码」的结果缓存（同链接同渠道 5 分钟内不重复扣费）
        $pdo->exec("CREATE TABLE IF NOT EXISTS code_check_cache (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            link_hash CHAR(32) NOT NULL,
            channel VARCHAR(8) NOT NULL DEFAULT 'wx',
            link VARCHAR(500) NOT NULL DEFAULT '',
            state VARCHAR(16) NOT NULL DEFAULT '',
            tag VARCHAR(32) NOT NULL DEFAULT '',
            text VARCHAR(255) NOT NULL DEFAULT '',
            msg VARCHAR(500) NOT NULL DEFAULT '',
            at_ts INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_check (tenant_id, link_hash, channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // v2.41 语音转文字缓存（同一条语音只转一次，不重复扣费）
        $pdo->exec("CREATE TABLE IF NOT EXISTS msg_transcripts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            url VARCHAR(500) NOT NULL DEFAULT '',
            text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_msg_url (message_id, url),
            KEY idx_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // v2.35 TG 发卡表纳入每请求幂等升级（防首次并发初始化竞态漏建）
        $pdo->exec("CREATE TABLE IF NOT EXISTS tg_users (
            tg_id BIGINT NOT NULL PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT '',
            first_name VARCHAR(64) NOT NULL DEFAULT '',
            state VARCHAR(24) NOT NULL DEFAULT '',
            trial_kami VARCHAR(32) NOT NULL DEFAULT '',
            trial_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // v2.43 存量上传引用绝对化（一次性）：相对 /uploads/ 路径在分控节点不可见（枢纽模式）
        try {
            if (kv_get('upload_abs_migrated_v2', '') !== '1') {
                $mainBase = rtrim((string)config()['site_main_url'] ?? '', '/');
                if ($mainBase !== '') {
                    $rows = $pdo->query("SELECT id, attachments FROM messages WHERE attachments LIKE '%/uploads/%' LIMIT 5000")->fetchAll();
                    $fixed = 0;
                    foreach ($rows as $row) {
                        $arr = json_decode((string)$row['attachments'], true);
                        if (!is_array($arr)) continue;
                        $chg = false;
                        foreach ($arr as &$at) {
                            if (!empty($at['url']) && str_starts_with((string)$at['url'], '/uploads/')) {
                                $at['url'] = $mainBase . (string)$at['url']; $chg = true;
                            }
                        }
                        unset($at);
                        if ($chg) { $pdo->prepare('UPDATE messages SET attachments = ? WHERE id = ?')
                            ->execute([json_encode($arr, JSON_UNESCAPED_UNICODE), $row['id']]); $fixed++; }
                    }
                    foreach ([['tenant_settings', "UPDATE tenant_settings SET sval = CONCAT('{$mainBase}', sval) WHERE skey IN ('me_avatar','brand_avatar') AND sval LIKE '/uploads/%'"],
                              ['v_sessions', "UPDATE v_sessions SET visitor_avatar = CONCAT('{$mainBase}', visitor_avatar) WHERE visitor_avatar LIKE '/uploads/%'"]] as $pair) {
                        $pdo->exec($pair[1]);
                    }
                }
                kv_set('upload_abs_migrated_v2', '1');
            }
        } catch (\Throwable $e) { /* 忽略：失败不影响运行 */ }
        // v2.34 分节点网页安装：一次性授权码 + 平台级全局黑名单（边缘本地拦截用）
        $pdo->exec("CREATE TABLE IF NOT EXISTS node_auth_codes (
            code VARCHAR(32) NOT NULL PRIMARY KEY,
            role VARCHAR(16) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at DATETIME NULL,
            used_ip VARCHAR(64) NULL,
            KEY idx_used (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS global_blacklist (
            ip VARCHAR(64) NOT NULL PRIMARY KEY,
            note VARCHAR(200) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* 忽略 */ }
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';

/* ---------- v2.65.2 服务器断连拒绝：PHP 返回 444，nginx 对上游 444 直接断开连接不回任何字节，
   浏览器显示原生「无法访问此页面 · 似乎服务器关闭了连接」（ERR_EMPTY_RESPONSE）。
   拒绝入口统一走 /refused；PHP 内部拒绝也直接 http_response_code(444)。 ---------- */
/* v2.68：/refused 不再断连接 —— 老访客页/老链接的兜底入口统一给友好页，
   免得顾客看到浏览器原生「无法访问此页面」，或者节点上没实现的 404/500。 */
if ($uri === '/refused' || $uri === '/__refused__') {
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    echo gate_fail_page('链接已失效，请重新扫码进入');
    exit;
}
/** 真正该断开的地方（未知主机 / IP 直连扫描）仍走 444：TCP 层直接关闭 */
function refuse_connection(): void { http_response_code(444); exit; }
/** v2.67 总控访客页：内联加密版（页面源码无明文业务逻辑，外部 .js 请求消失） */
function serve_visitor_main(): void
{
    header('Cache-Control: no-store');
    echo visitor_page_packed((string)file_get_contents(__DIR__ . '/public/visitor.html'));
    exit;
}
/** v2.68 内容页伪装落地点（/web/data/YYYY/MM/DD/<uuid>.html）：真聊天页挂在这类像静态资源的路径下 */
function serve_visitor_fake_asset(): void
{
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    echo visitor_page_packed((string)file_get_contents(__DIR__ . '/public/visitor.html'));
    exit;
}
/** v2.68 内容页伪装路径判定（静态边缘节点的更新包里也烤了同名真实文件） */
function is_fake_asset_uri(string $uri): bool
{
    return (bool)preg_match('#^/web/data/\d{4}/\d{2}/\d{2}/[A-Za-z0-9\-]{16,48}\.html$#', $uri);
}

/* ---------- v2.66 主机门禁（仅总控）：只有主站域名与「正在使用」的域名（启用中的
   域名池域名/其子域、激活的专属链接子域）才放行；未知主机、IP 直连、已被红码退役
   停用的域名 → 一律 444 断开连接（配了 nginx 断连段即为 TCP 层直接关闭）。
   访客流量从此不可能再看到客服登录页。 ---------- */
$REQ_HOST = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$MAIN_HOST = strtolower(parse_url((string)site_main_url(), PHP_URL_HOST) ?: '');
if ($MAIN_HOST !== '' && $REQ_HOST !== '' && $REQ_HOST !== $MAIN_HOST && $REQ_HOST !== 'www.' . $MAIN_HOST
    && !pool_host_enabled($REQ_HOST)) {
    refuse_connection();
}

/* ---------- 静态边缘模式：/api/* 跨域直连总控（分控站点可为纯静态，无需 PHP） ---------- */
if (str_starts_with($uri, '/api/')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Edge-Secret, X-Edge-Role, X-Edge-Origin, X-Original-Host');
    header('Access-Control-Max-Age: 86400');
    header('Cache-Control: no-store');
    if ($method === 'OPTIONS') { http_response_code(204); exit; }
}

/* ---------- 主站：安装/升级（建表 + 种子 + 幂等迁移） ---------- */
try { ensure_installed(); } catch (\Throwable $e) { /* 初始化失败由后续请求重试 */ }
try {
    if (kv_get('tg_schema_v', '') !== '1') {
        tg_schema();
        tg_seed_pkgs();
        kv_set('tg_schema_v', '1');
        if (kv_get('tg_webhook_secret', '') === '') kv_set('tg_webhook_secret', bin2hex(random_bytes(16)));
    }
} catch (\Throwable $e) { /* 忽略：首次请求重试 */ }

/* ---------- 静态资源兜底（正常情况下 Nginx 已直接命中） ---------- */
function serve_static(string $uri): void
{
    // 站点根下：/assets/... /uploads/...
    foreach (['assets', 'uploads'] as $prefix) {
        if (str_starts_with($uri, "/$prefix/")) {
            $file = realpath(__DIR__ . $uri);
            $base = realpath(__DIR__ . "/$prefix");
            if ($file && str_starts_with($file, $base) && is_file($file)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $mime = [
                    'css' => 'text/css', 'js' => 'application/javascript',
                    'html' => 'text/html; charset=utf-8', 'png' => 'image/png',
                    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
                    'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'webm' => 'audio/webm',
                    'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'aac' => 'audio/aac',
                    'amr' => 'audio/amr', '3gp' => 'audio/3gpp',
                    'json' => 'application/json', 'ico' => 'image/x-icon',
                ][$ext] ?? 'application/octet-stream';
                header('Content-Type: ' . $mime);
                header('Cache-Control: public, max-age=604800');
                readfile($file);
                exit;
            }
        }
    }
    http_response_code(404);
    echo 'Not Found';
    exit;
}

if (preg_match('#^/(assets|uploads)/#', $uri)) {
    // 分域部署：非主站节点的 /uploads 由主站统一存储（新上传为绝对 URL；存量相对路径 302 过去）
    if (str_starts_with($uri, '/uploads/') && site_role() !== 'main') {
        $main = site_main_url();
        if ($main !== '') { header('Location: ' . $main . $uri, true, 302); exit; }
    }
    serve_static($uri);
}

/* ---------- 页面 ---------- */
function serve_html(string $file): void
{
    $path = __DIR__ . '/public/' . $file;
    if (!is_file($path)) { http_response_code(404); echo 'Not Found'; exit; }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Type: text/html; charset=utf-8');
    readfile($path);
    exit;
}

/* ---------- 站点角色门禁（1+2+n 分域部署，枢纽模式）----------
   边缘节点（agent/visitor）不连数据库：页面/静态/门禁本地处理，
   需要数据的 /api/* 由 nginx 反代到主站；未列路径一律 404/403。 */
if (site_role() !== 'main') {
    edge_maintenance();   // 边缘惰性自维护（每 5 分钟）：自更新 + 黑名单同步 + 节点登记，无需 cron
    // 访客/防红壳页（visitor 节点）与登录/工作台（agent 节点）放行；其余页面 404
    if (preg_match('#^/[0-9]{2}-[0-9]{2}/[A-Za-z0-9]{4,16}\.html$#', $uri)) {
        if (site_role() !== 'visitor') { http_response_code(404); echo 'Not Found'; exit; }
        // v2.68：只有「连入口参数都没有」的纯扫描流量才断连接；带 w/key 的一律出壳页，
        // 码真伪由内层 /api/gate 校验（失败先重试 → 换备用域名 → 友好提示，不再跳百度）
        if (gate_token_from_query() === '' || !gate_env_ok()) refuse_connection();
        header('Cache-Control: no-store');
        echo gate_loader_html();
        exit;   // v2.69.5 修：这里原来没有 exit，壳页后面被拼了一句 "Not Found" 且状态码是 404
    }
    /* v2.68 内容页伪装路径：静态节点上更新包已烤成真文件（nginx 直接命中，走不到这里）；
       PHP 型访客节点由这里兜底，保证 /api/gate 下发的地址一定能出访客页。 */
    if (is_fake_asset_uri($uri)) {
        if (site_role() !== 'visitor') { http_response_code(404); echo 'Not Found'; exit; }
        if (!isset($_GET['code'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo gate_fail_page('链接已失效，请重新扫码进入');
            exit;
        }
        serve_visitor_fake_asset();
    }
    foreach (['login.html', 'app.html', 'admin.html', 'visitor.html'] as $page) {
        if ($uri === "/$page") {
            if (!site_allow_page($page)) { http_response_code(404); echo 'Not Found'; exit; }
            // v2.65.3 服务端拒绝：访客页无 code → 服务器直接断开连接（444，浏览器原生错误页）
            if ($page === 'visitor.html' && !isset($_GET['code']) && !isset($_GET['t'])) {
                refuse_connection();
            }
            serve_html($page);
        }
    }
    // v2.65 catch-all：未知随机 .html 路径带 code → 出真实访客页（壳 loader 的随机 iframe 落点）
    if (preg_match('#^/[0-9a-z]{2,10}/[0-9a-z]{4,16}\.html$#i', $uri) && isset($_GET['code'])) {
        if (site_allow_page('visitor.html')) {
            header('Cache-Control: no-store');
            serve_html('visitor.html');
        }
        http_response_code(404); echo 'Not Found'; exit;
    }
    if ($uri === '/') {
        if (site_role() === 'visitor') { serve_html('visitor.html'); exit; }
        /* v2.66 访客节点之外的一切未知路径不再引导去客服登录页——直接断开（防访客流量看到客服入口） */
        refuse_connection();
    }
    // 健康检查任何节点都可用（含角色/版本，供巡检）
    if ($uri === '/health' || $uri === '/api/health') {
        json_out(['ok' => true, 'time' => time(), 'version' => '2.0', 'site_role' => site_role(), 'app_version' => app_version(), 'apcu' => mem_ok()]);
    }
    // 访客节点本地拦截全局黑名单（租户级黑名单由主站接口自行校验）
    if (site_role() === 'visitor' && str_starts_with($uri, '/api/') && edge_ip_blocked()) {
        json_error('您的访问受限', 403);
    }
    // /api/* 优先由 nginx 反代到主站（见 nginx-edge.conf）；未配置时由此处 PHP 兜底转发
    if (str_starts_with($uri, '/api/')) edge_relay_api($uri);
    http_response_code(404);
    echo 'Not Found';
    exit;
}


/* ---------- 站点角色门禁（1+2+n 分域部署）----------
   main（默认）= 全功能；agent = 客服登录入口（登录页+工作台+客服/租户接口）；
   visitor = 访客站点（访客页+访客接口）。非白名单路径一律 404/403，不暴露存在。 */
function site_allow_page(string $file): bool
{
    $r = site_role();
    if ($r === 'main') return true;
    if ($r === 'agent') return in_array($file, ['login.html', 'app.html'], true);
    return $file === 'visitor.html' || $file === 'gate.html';
}

function site_allow_api(string $uri): bool
{
    $r = site_role();
    if ($r === 'main') return true;
    if ($uri === '/api/health') return true;
    if ($r === 'agent') {
        if (str_starts_with($uri, '/api/auth/')) return true;
        if (str_starts_with($uri, '/api/sessions')) return true;
        if (str_starts_with($uri, '/api/tenant/')) return true;
        if (str_starts_with($uri, '/api/quick-msgs')) return true;
        if (str_starts_with($uri, '/api/msg/')) return true;
        return in_array($uri, ['/api/poll', '/api/agents', '/api/upload', '/api/stats/dashboard', '/api/export/sessions', '/api/broadcast/send', '/api/internal/apply'], true);
    }
    // visitor
    if (str_starts_with($uri, '/api/visitor/')) return true;
    return $uri === '/api/gate' || $uri === '/api/poll' || $uri === '/api/internal/apply';
}

/* ---------- 防红中转「空壳页」（v2.59 → v2.65 重做）：/MM-DD/xxxxxx.html?key=… ----------
   v2.65.3 直接在原网页拒绝：总控有库，无码/环境不像真人/码已废 → 一律 444 断开连接，
   不出 loader 不出任何内容；通过后才出 XOR 自解密 loader（内层 iframe 随机路径 catch-all）。 */
if (preg_match('#^/[0-9]{2}-[0-9]{2}/[A-Za-z0-9]{4,16}\.html$#', $uri)) {
    if (!site_allow_page('gate.html')) { http_response_code(404); echo 'Not Found'; exit; }
    /* v2.68：有入口参数（?w= 入口 ID / 老链接的 ?key= 客服码）就出壳页，码真伪交给
       壳页内层调 /api/gate 判定 —— 无效时壳页先重试、再换备用域名，最后给友好提示。 */
    if (gate_token_from_query() === '' || !gate_env_ok()) refuse_connection();
    header('Cache-Control: no-store');
    echo gate_loader_html();
    exit;
}

/* ---------- v2.68 内容页伪装落点：/web/data/YYYY/MM/DD/<uuid>.html ----------
   壳页从 /api/gate 拿到这个地址，装在 iframe 里当聊天页 —— 入口域名与内容域名解耦，
   二维码里那个域名永远不用换。（静态节点由更新包烤成真文件，nginx 直接命中，不走这里。） */
if (is_fake_asset_uri($uri)) {
    if (!isset($_GET['code']) || !gate_resolve_token(trim((string)$_GET['code']))) {
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        echo gate_fail_page('链接已失效，请重新扫码进入');
        exit;
    }
    serve_visitor_fake_asset();
}

if ($uri === '/' || $uri === '/index.php' || $uri === '/index.html') {
    // 访客入口根路径直达（v2.28）：Host 命中客服码子域或专属链接子域时直接出访客页（无需 ?code= 后缀）
    $rootHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    $isVisitorHost = false;
    if ($rootHost !== '') {
        try {
            $pdo0 = db();
            // 专属链接子域
            $st0 = $pdo0->prepare('SELECT dl.subdomain, dl.tenant_id FROM domain_links dl WHERE dl.active = 1');
            $st0->execute();
            foreach ($st0->fetchAll() as $dl0) {
                $sub0 = strtolower($dl0['subdomain']);
                if ($rootHost === $sub0 || str_starts_with($rootHost, $sub0 . '.')) { $isVisitorHost = true; break; }
            }
            // 客服码子域（service_codes.link 的 Host 部分）
            if (!$isVisitorHost) {
                $st1 = $pdo0->query('SELECT link FROM service_codes');
                foreach ($st1->fetchAll() as $sc0) {
                    $h0 = strtolower(parse_url($sc0['link'], PHP_URL_HOST) ?: '');
                    if ($h0 !== '' && $rootHost === $h0) { $isVisitorHost = true; break; }
                }
            }
        } catch (\Throwable $e) { /* 查询失败按默认跳转 */ }
    }
    if ($isVisitorHost || site_role() === 'visitor') { serve_visitor_main(); }
    /* v2.66 主站根路径：非访客域名的根访问不再引导去客服登录页（防访客流量看到客服入口），
       直接断开；客服登录请直接访问 /login.html */
    refuse_connection();
}
foreach (['login.html', 'app.html', 'admin.html', 'visitor.html'] as $page) {
    if ($uri === "/$page") {
        if (!site_allow_page($page)) { http_response_code(404); echo 'Not Found'; exit; }
        // v2.65.3 服务端拒绝：访客页无 code → 直接断开连接；带码的总控侧查码是否仍生效，无效断开
        if ($page === 'visitor.html') {
            $ck = trim((string)($_GET['code'] ?? ($_GET['key'] ?? '')));
            /* v2.68：链接失效不再断连接（顾客会以为网站挂了）——统一给一句人话 */
            if (!isset($_GET['code']) && !isset($_GET['t']) && $ck === '') {
                header('Cache-Control: no-store');
                header('Content-Type: text/html; charset=utf-8');
                echo gate_fail_page('请通过客服码或扫码进入');
                exit;
            }
            if ($ck !== '' && site_role() === 'main' && !gate_resolve_token($ck)) {
                header('Cache-Control: no-store');
                header('Content-Type: text/html; charset=utf-8');
                echo gate_fail_page('链接已失效，请重新扫码进入');
                exit;
            }
            serve_visitor_main();   // v2.67 内联加密版
        }
        serve_html($page);
    }
}
/* v2.65 catch-all：未知随机 .html 路径带 code → 出真实访客页（壳 loader 的随机 iframe 落点，
   真实页不再依赖固定文件名，扫描器探测任意路径都拿不到业务内容）；无 code 的未知路径维持 404。
   v2.65.3：码已废/格式坏 → 直接 444 断开，在原网页拒绝 */
if (preg_match('#^/[0-9a-z]{2,10}/[0-9a-z]{4,16}\.html$#i', $uri) && isset($_GET['code'])) {
    if (!gate_resolve_token(trim((string)$_GET['code']))) {
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        echo gate_fail_page('链接已失效，请重新扫码进入');
        exit;
    }
    if (site_allow_page('visitor.html')) {
        serve_visitor_main();   // v2.67 内联加密版
    }
    http_response_code(404); echo 'Not Found'; exit;
}
if ($uri === '/health' || $uri === '/api/health') {
    json_out(['ok' => true, 'time' => time(), 'version' => '2.0', 'site_role' => site_role(), 'app_version' => app_version(), 'apcu' => mem_ok()]);
}

/* ---------- API 路由 ---------- */
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/visitor.php';
require_once __DIR__ . '/routes/agent.php';
require_once __DIR__ . '/routes/tenant.php';
require_once __DIR__ . '/routes/admin.php';
require_once __DIR__ . '/routes/telegram.php';
require_once __DIR__ . '/routes/tgadmin.php';

/* 发卡机器人：建表 + 首次灌默认套餐（只跑一次，避免每请求都查） */
function dispatch_api(string $method, string $uri): void
{
    edge_touch_from_headers();   // 分控反代请求：带 X-Edge-Secret 的自动登记/刷新节点
    // ---- 认证 ----
    if ($uri === '/api/auth/login')    handle_auth_login($method);
    // ---- 上传中转（分节点 → 主站，仅 main 角色开放，共享密钥校验）----
    if ($uri === '/api/internal/upload') handle_internal_upload($method);
    // ---- 节点心跳（分控 cron → 主站登记）与远程更新指令（主控 → 分控）----
    if ($uri === '/api/internal/heartbeat') handle_internal_heartbeat($method);
    if ($uri === '/api/internal/update')    handle_internal_update($method);
    if ($uri === '/api/internal/apply')     handle_internal_apply($method);
    // ---- 分节点网页安装：授权码换凭证 + 边缘黑名单/归属同步 ----
    if ($uri === '/api/internal/node-register') handle_internal_node_register($method);
    if ($uri === '/api/internal/sync')          handle_internal_sync($method);
    // ---- 发卡机器人 webhook（Telegram 直接 POST，不走登录态，用 secret 校验）----
    if ($uri === '/api/tg/webhook')    handle_tg_webhook($method);
    if ($uri === '/api/auth/me')       handle_auth_me($method);
    if ($uri === '/api/auth/password') handle_auth_password($method);
    if ($uri === '/api/auth/profile')  handle_auth_profile($method);
    // v2.60.10 登录设备（防卡密共享）：看列表 / 改备注 / 踢掉
    if ($uri === '/api/login-devices')      handle_login_devices($method);
    if ($uri === '/api/login-devices/kick') handle_login_device_kick($method);
    if ($uri === '/api/login-devices/note') handle_login_device_note($method);

    // ---- 访客端（公开） ----
    if ($uri === '/api/visitor/config')      handle_visitor_config($method);
    if ($uri === '/api/visitor/pre-inquiry') handle_visitor_pre_inquiry($method);
    if ($uri === '/api/gate')                handle_gate_check($method);
    if ($uri === '/api/visitor/init')        handle_visitor_init($method);
    if ($uri === '/api/visitor/welcome')     handle_visitor_welcome($method);
    if ($uri === '/api/visitor/send')        handle_visitor_send($method);
    if ($uri === '/api/visitor/upload')      handle_visitor_upload($method);
    if ($uri === '/api/visitor/messages')    handle_visitor_messages($method);
    if ($uri === '/api/visitor/recall')      handle_visitor_recall($method);
    if ($uri === '/api/visitor/resume')      handle_visitor_resume($method);

    // ---- 工作台（tenant + agent） ----
    if ($uri === '/api/sessions')        handle_sessions($method);
    if ($uri === '/api/sessions/bulk')   handle_sessions_bulk($method);
    if ($uri === '/api/sessions/by-resume') handle_session_by_resume($method);
    if (preg_match('#^/api/sessions/(\d+)/merge$#', $uri, $m)) handle_session_merge($method, $m);
    if ($uri === '/api/agents')          handle_agents_list($method);
    if ($uri === '/api/upload')          handle_upload($method);
    if ($uri === '/api/msg/transcribe')  handle_msg_transcribe($method);
    if ($uri === '/api/stats/dashboard') handle_stats_dashboard($method);
    if ($uri === '/api/export/sessions') handle_export_sessions($method);
    if ($uri === '/api/broadcast/send')  handle_broadcast_send($method);

    if (preg_match('#^/api/sessions/(\d+)/messages$#', $uri, $m))  handle_session_messages($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/name$#', $uri, $m))      handle_session_rename($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/close$#', $uri, $m))     handle_session_close($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/archive$#', $uri, $m))   handle_session_archive($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/delete$#', $uri, $m))    handle_session_delete($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/restore$#', $uri, $m))   handle_session_restore($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/purge$#', $uri, $m))     handle_session_purge($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/read$#', $uri, $m))      handle_session_read($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/transfer$#', $uri, $m))  handle_session_transfer($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/block$#', $uri, $m))   handle_session_block($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/recall$#', $uri, $m))  handle_session_recall($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/messages/(\d+)/edit$#', $uri, $m)) handle_message_edit($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/pin$#', $uri, $m))     handle_session_pin($method, $m);
    if ($uri === '/api/quick-msgs/reorder')                       handle_quick_reorder($method);
    if (preg_match('#^/api/sessions/(\d+)/messages/(\d+)/delete$#', $uri, $m)) handle_message_delete($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/contacts(?:/(\w+))?$#', $uri, $m)) handle_session_contacts($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/profile$#', $uri, $m))   handle_session_profile($method, $m);

    if (preg_match('#^/api/(quick-msgs|welcome-messages|auto-reply|ip-blacklist|pre-inquiry)$#', $uri, $m)) {
        handle_crud($method, $m[1]);
    }
    if (preg_match('#^/api/(quick-msgs|welcome-messages|auto-reply|ip-blacklist|pre-inquiry)/(\d+)$#', $uri, $m)) {
        handle_crud_id($method, $m[1], (int)$m[2]);
    }

    // ---- 租户管理 ----
    if ($uri === '/api/tenant/agents')           handle_tenant_agents($method);
    if (preg_match('#^/api/tenant/agents/(\d+)$#', $uri, $m)) handle_tenant_agents_id($method, (int)$m[1]);
    if ($uri === '/api/tenant/service-code')     handle_tenant_service_code($method);
    if ($uri === '/api/tenant/link')             handle_tenant_link($method);
    if ($uri === '/api/tenant/link/renew')       handle_tenant_link_renew($method);
    if ($uri === '/api/tenant/settings')         handle_tenant_settings($method);
    if ($uri === '/api/tenant/links')            handle_tenant_links($method);
    if ($uri === '/api/tenant/link/history')     handle_tenant_link_history($method);
    if (preg_match('#^/api/tenant/link/disable/(\d+)$#', $uri, $m)) handle_tenant_link_disable($method, $m);
    if ($uri === '/api/tenant/login-logs')       handle_tenant_login_logs($method);
    if ($uri === '/api/tenant/cleanup')          handle_tenant_cleanup($method);
    if ($uri === '/api/tenant/visitors')         handle_tenant_visitors($method);
    if ($uri === '/api/tenant/code/check')       handle_tenant_code_check($method);
    if ($uri === '/api/tenant/code/alerts')      handle_tenant_code_alerts($method);

    // ---- 平台管理 ----
    if ($uri === '/api/admin/overview')          handle_admin_overview($method);
    if ($uri === '/api/admin/tenants')           handle_admin_tenants($method);
    if ($uri === '/api/admin/tenants/gen')       handle_admin_tenants_gen($method);
    if (preg_match('#^/api/admin/tenants/(\d+)$#', $uri, $m)) handle_admin_tenants_id($method, (int)$m[1]);
    if ($uri === '/api/admin/domain-pool')       handle_admin_domain_pool($method);
    if ($uri === '/api/admin/domain-pool/subs')  handle_admin_pool_subs($method);
    if (preg_match('#^/api/admin/domain-pool/(\d+)$#', $uri, $m)) handle_admin_domain_pool_id($method, (int)$m[1]);
    if ($uri === '/api/admin/aa-test')           handle_admin_aa_test($method);
    if ($uri === '/api/admin/aa-import')         handle_admin_aa_import($method);
    if ($uri === '/api/admin/red-domains')       handle_admin_red_domains($method);
    if (preg_match('#^/api/admin/red-domains/(\d+)$#', $uri, $m)) handle_admin_red_domain_restore($method, (int)$m[1]);
    if ($uri === '/api/admin/root-domain')       handle_admin_root_domain($method);
    if ($uri === '/api/admin/nodes')             handle_admin_nodes($method);
    if ($uri === '/api/admin/node-codes')        handle_admin_node_codes($method);
    if ($uri === '/api/admin/gblacklist')        handle_admin_gblacklist($method);
    if ($uri === '/api/admin/agent-entrances')   handle_admin_agent_entrances($method);
    if ($uri === '/api/admin/bootstrap-dl')      handle_admin_bootstrap_dl($method);
    if ($uri === '/api/admin/defaults')          handle_admin_defaults($method);
    if ($uri === '/api/admin/wxcheck')           handle_admin_wxcheck($method);
    // ---- 发卡机器人（平台管理员）----
    if ($uri === '/api/admin/tg')                handle_admin_tg($method);
    if ($uri === '/api/admin/tg/save')           handle_admin_tg_save($method);
    if ($uri === '/api/admin/tg/pkg')            handle_admin_tg_pkg($method);
    if ($uri === '/api/admin/tg/pkg-delete')     handle_admin_tg_pkg_delete($method);
    if ($uri === '/api/admin/tg/webhook')        handle_admin_tg_webhook($method);
    if ($uri === '/api/admin/tg/order')          handle_admin_tg_order($method);
    if ($uri === '/api/admin/tg/check')          handle_admin_tg_check($method);

    // ---- 轮询（客服版需登录；访客版公开） ----
    if ($uri === '/api/poll') {
        $role = (string)input('role', '');
        $sr = site_role();
        if ($sr === 'visitor') { handle_visitor_poll($method); }        // 访客节点只开放访客轮询
        elseif ($role === 'agent' || $sr === 'agent') { handle_agent_poll($method); }
        else { handle_visitor_poll($method); }
    }

    json_error('接口不存在', 400);
}

    // ---- 站点角色门禁：非白名单接口直接 403（不暴露存在） ----
    if (!site_allow_api($uri)) json_error('接口不存在', 403);

    dispatch_api($method, $uri);
