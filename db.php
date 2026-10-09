<?php
/**
 * db.php - PDO 单例 + 建表 + 种子数据
 * 调用：db()->prepare(...)
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    /* v2.48.3 修 bug：这里原来直接 require config.php，**绕过了按域名的 hosts 覆盖**，
       导致「基础段指向别的库」的节点（如旧站 kf.ylcz.cc 基础段是新库、靠 hosts 覆盖回本地 kfxt）
       所有数据库操作全部 Access denied。统一走 config()，分域部署才真正生效。 */
    $cfg = function_exists('config') ? config() : require __DIR__ . '/config.php';
    $d = $cfg['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'], $d['name'], $d['charset']);
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // 统一 MySQL 会话时区为 +08:00（与 PHP PRC 对齐），保证 CURRENT_TIMESTAMP 与 PHP now_dt() 一致
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, SESSION time_zone = '+08:00'",
    ]);
    return $pdo;
}

/** 幂等建表（全新安装时调用一次） */
function init_schema(): void
{
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(32) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        nickname VARCHAR(64) NOT NULL DEFAULT '管理员',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kami VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        nickname VARCHAR(64) NOT NULL DEFAULT '客服',
        remark VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        expires_at DATETIME NULL,
        quota_web INT UNSIGNED NOT NULL DEFAULT 200,
        quota_app INT UNSIGNED NOT NULL DEFAULT 200,
        closed_keep_days INT UNSIGNED NOT NULL DEFAULT 3,
        max_sessions INT UNSIGNED NOT NULL DEFAULT 0,
        max_agents INT UNSIGNED NOT NULL DEFAULT 5,
        content_domain VARCHAR(120) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agents (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        username VARCHAR(32) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        nickname VARCHAR(64) NOT NULL DEFAULT '客服',
        avatar VARCHAR(255) NOT NULL DEFAULT '👨‍💼',
        work_status VARCHAR(16) NOT NULL DEFAULT 'online',
        auto_accept TINYINT NOT NULL DEFAULT 1,
        sound_enabled TINYINT NOT NULL DEFAULT 1,
        signature VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tenant_user (tenant_id, username),
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS v_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        agent_id INT UNSIGNED NOT NULL DEFAULT 0,
        visitor_name VARCHAR(64) NOT NULL,
        visitor_token VARCHAR(64) NOT NULL DEFAULT '',
        ip VARCHAR(64) NOT NULL DEFAULT '',
        from_url VARCHAR(255) NOT NULL DEFAULT '直接访问',
        last_msg TEXT NULL,
        last_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        unread INT NOT NULL DEFAULT 0,
        status VARCHAR(16) NOT NULL DEFAULT 'online',
        source VARCHAR(16) NOT NULL DEFAULT 'direct',
        quota_key VARCHAR(16) NOT NULL DEFAULT '',
        last_online_at DATETIME NULL,
        closed_at DATETIME NULL,
        archived_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tenant_time (tenant_id, last_time),
        KEY idx_status_time (tenant_id, status, last_time),
        KEY idx_tenant_agent (tenant_id, agent_id),
        KEY idx_token (visitor_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        session_id INT UNSIGNED NOT NULL,
        from_type VARCHAR(16) NOT NULL,
        sender_id INT UNSIGNED NOT NULL DEFAULT 0,
        sender_name VARCHAR(64) NOT NULL DEFAULT '',
        text TEXT NOT NULL,
        msg_type VARCHAR(16) NOT NULL DEFAULT 'text',
        attachments TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id, id),
        KEY idx_tenant_time (tenant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        session_id INT UNSIGNED NOT NULL,
        type VARCHAR(16) NOT NULL,
        value VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_session_type (session_id, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS quick_msgs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        title VARCHAR(128) NOT NULL,
        content TEXT NOT NULL,
        group_name VARCHAR(32) NOT NULL DEFAULT '通用',
        sort INT NOT NULL DEFAULT 0,
        attachments TEXT NULL,
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS welcome_msgs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        delay INT NOT NULL DEFAULT 0,
        enabled TINYINT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        attachments TEXT NULL,
        trig VARCHAR(8) NOT NULL DEFAULT 'once',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS welcome_sent (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        session_id INT UNSIGNED NOT NULL,
        welcome_id INT UNSIGNED NOT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sess_wel (session_id, welcome_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS auto_replies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        keyword VARCHAR(128) NOT NULL,
        reply TEXT NOT NULL,
        enabled TINYINT NOT NULL DEFAULT 1,
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcasts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        title VARCHAR(128) NOT NULL DEFAULT '',
        content TEXT NOT NULL,
        count INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ip_blacklist (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        ip VARCHAR(64) NOT NULL,
        reason VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tenant_ip (tenant_id, ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_inquiries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        label VARCHAR(64) NOT NULL,
        type VARCHAR(16) NOT NULL DEFAULT 'text',
        required TINYINT NOT NULL DEFAULT 0,
        placeholder VARCHAR(128) NOT NULL DEFAULT '',
        sort INT NOT NULL DEFAULT 0,
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_settings (
        tenant_id INT UNSIGNED NOT NULL,
        skey VARCHAR(64) NOT NULL,
        sval TEXT NOT NULL,
        PRIMARY KEY (tenant_id, skey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        agent_id INT UNSIGNED NOT NULL DEFAULT 0,
        code VARCHAR(24) NOT NULL UNIQUE,
        entry_id VARCHAR(16) NULL,
        content_host VARCHAR(120) NOT NULL DEFAULT '',
        link VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tenant (tenant_id),
        UNIQUE KEY uk_entry_id (entry_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS domain_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        subdomain VARCHAR(64) NOT NULL UNIQUE,
        link VARCHAR(255) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS domain_pool (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain VARCHAR(128) NOT NULL UNIQUE,
        wildcard TINYINT NOT NULL DEFAULT 0,
        dtype VARCHAR(16) NOT NULL DEFAULT '',
        enabled TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS kv_store (
        skey VARCHAR(64) PRIMARY KEY,
        sval VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_fails (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ident VARCHAR(128) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ident_time (ident, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // v2.90.2 会话合并别名（老会话 id → 合并后的目标 id；老窗口不用刷新也能接着聊）
    $pdo->exec("CREATE TABLE IF NOT EXISTS session_merge_alias (
        old_id INT UNSIGNED NOT NULL PRIMARY KEY,
        new_id INT UNSIGNED NOT NULL,
        token VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_new (new_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** 首启种子：管理员、JWT 密钥、全局默认值 */
function init_seed(): void
{
    $pdo = db();

    // 平台管理员（仅当表为空时创建，默认 admin/123456）
    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($cnt === 0) {
        $pdo->prepare('INSERT INTO admins (username, password_hash, nickname) VALUES (?,?,?)')
            ->execute(['admin', password_hash('123456', PASSWORD_DEFAULT), '平台管理员']);
    }

    // JWT 密钥（一次性生成）
    $st = $pdo->prepare('SELECT sval FROM kv_store WHERE skey = ?');
    $st->execute(['jwt_secret']);
    if (!$st->fetch()) {
        $pdo->prepare('INSERT INTO kv_store (skey, sval) VALUES (?,?)')
            ->execute(['jwt_secret', bin2hex(random_bytes(32))]);
    }

    // 全局默认值
    $defaults = [
        'root_domain'       => '',
        'default_quota_web' => '200',
        'default_quota_app' => '200',
        'default_max_agents' => '5',
        'installed_at'      => date('Y-m-d H:i:s'),
    ];
    foreach ($defaults as $k => $v) {
        $pdo->prepare('INSERT IGNORE INTO kv_store (skey, sval) VALUES (?,?)')->execute([$k, $v]);
    }
}
