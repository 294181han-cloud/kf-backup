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
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN closed_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN archived_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD INDEX idx_status_time (tenant_id, status, last_time)"); } catch (\Throwable $e) { /* 已存在 */ }
        // v2.43 消息引用回复 + 会话置顶
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN reply_to INT UNSIGNED NOT NULL DEFAULT 0"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN pinned TINYINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD COLUMN pinned_at DATETIME NULL"); } catch (\Throwable $e) { /* 列已存在 */ }
        try { $pdo->exec("ALTER TABLE v_sessions ADD INDEX idx_tenant_pinned (tenant_id, pinned, last_time)"); } catch (\Throwable $e) { /* 已存在 */ }
        // v2.33 分域节点登记（主站记录各分控节点：IP 为名，心跳带角色/版本）
        $pdo->exec("CREATE TABLE IF NOT EXISTS nodes (
            ip VARCHAR(64) NOT NULL PRIMARY KEY,
            role VARCHAR(16) NOT NULL DEFAULT '',
            app_version VARCHAR(32) NOT NULL DEFAULT '',
            origin VARCHAR(200) NOT NULL DEFAULT '',
            first_seen DATETIME NULL,
            last_seen DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
    // 访客/防红壳页（visitor 节点）与登录/工作台（agent 节点）放行；其余页面 404
    if (preg_match('#^/[0-9]{2}-[0-9]{2}/[A-Za-z0-9]{4,16}\.html$#', $uri)) {
        if (site_role() !== 'visitor') { http_response_code(404); echo 'Not Found'; exit; }
        serve_html('gate.html');
    }
    foreach (['login.html', 'app.html', 'admin.html', 'visitor.html'] as $page) {
        if ($uri === "/$page") {
            if (!site_allow_page($page)) { http_response_code(404); echo 'Not Found'; exit; }
            serve_html($page);
        }
    }
    if ($uri === '/') {
        if (site_role() === 'visitor') { serve_html('visitor.html'); exit; }
        header('Location: /login.html');
        exit;
    }
    // 健康检查任何节点都可用（含角色/版本，供巡检）
    if ($uri === '/health' || $uri === '/api/health') {
        json_out(['ok' => true, 'time' => time(), 'version' => '2.0', 'site_role' => site_role(), 'app_version' => app_version(), 'apcu' => mem_ok()]);
    }
    // 访客节点本地拦截全局黑名单（租户级黑名单由主站接口自行校验）
    if (site_role() === 'visitor' && str_starts_with($uri, '/api/') && edge_ip_blocked()) {
        json_error('您的访问受限', 403);
    }
    // 其余 /api/* 交给 nginx 反代到主站（见 node/README.md）；PHP 兜底 404
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

/* ---------- 防红中转「空壳页」（v2.59）：/MM-DD/xxxxxx.html?key=… ----------
   看起来就是一个放在日期目录里的普通静态页；文件里没有任何业务内容，
   真实入口由页内脚本拿 key 调 /api/gate 换出来（见 public/gate.html）。 */
if (preg_match('#^/[0-9]{2}-[0-9]{2}/[A-Za-z0-9]{4,16}\.html$#', $uri)) {
    if (!site_allow_page('gate.html')) { http_response_code(404); echo 'Not Found'; exit; }
    serve_html('gate.html');
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
    if ($isVisitorHost || site_role() === 'visitor') { serve_html('visitor.html'); exit; }
    header('Location: /login.html');
    exit;
}
foreach (['login.html', 'app.html', 'admin.html', 'visitor.html'] as $page) {
    if ($uri === "/$page") {
        if (!site_allow_page($page)) { http_response_code(404); echo 'Not Found'; exit; }
        serve_html($page);
    }
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

    // ---- 工作台（tenant + agent） ----
    if ($uri === '/api/sessions')        handle_sessions($method);
    if ($uri === '/api/sessions/bulk')   handle_sessions_bulk($method);
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
    if (preg_match('#^/api/sessions/(\d+)/read$#', $uri, $m))      handle_session_read($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/transfer$#', $uri, $m))  handle_session_transfer($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/block$#', $uri, $m))   handle_session_block($method, $m);
    if (preg_match('#^/api/sessions/(\d+)/recall$#', $uri, $m))  handle_session_recall($method, $m);
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
    if (preg_match('#^/api/admin/domain-pool/(\d+)$#', $uri, $m)) handle_admin_domain_pool_id($method, (int)$m[1]);
    if ($uri === '/api/admin/root-domain')       handle_admin_root_domain($method);
    if ($uri === '/api/admin/nodes')             handle_admin_nodes($method);
    if ($uri === '/api/admin/node-codes')        handle_admin_node_codes($method);
    if ($uri === '/api/admin/gblacklist')        handle_admin_gblacklist($method);
    if ($uri === '/api/admin/agent-entrances')   handle_admin_agent_entrances($method);
    if ($uri === '/api/admin/defaults')          handle_admin_defaults($method);
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
