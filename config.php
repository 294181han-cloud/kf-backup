<?php
/**
 * config.php - 全局配置（多租户客服系统）
 *
 * 生产环境请用环境变量覆盖数据库凭据，或直接修改此文件默认值。
 */
declare(strict_types=1);

return [
    // ---------- 数据库 ----------
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int)(getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'kfxt',
        'user'    => getenv('DB_USER') ?: 'kfxt',
        'pass'    => getenv('DB_PASS') ?: 'ZCG6YHx5jEZ4E5Kk',
        'charset' => 'utf8mb4',
    ],

    // ---------- 鉴权 ----------
    // JWT 密钥首次初始化时自动生成并写入 kv_store，无需手工配置
    'jwt_ttl' => 60 * 60 * 24 * 7,          // 7 天（秒）

    // ---------- 上传 ----------
    'upload_dir'      => __DIR__ . '/uploads',
    'upload_url'      => '/uploads',
    'upload_max_image' => 5 * 1024 * 1024,   // 图片 5MB
    'upload_max_audio' => 10 * 1024 * 1024,  // 语音 10MB

    // ---------- 限频 ----------
    'rate_limit_per_min' => 30,   // 访客 1 分钟最多消息数
    'login_max_fails'    => 10,   // 同 IP 15 分钟内登录失败上限
    'login_lock_secs'    => 900,  // 登录锁定时长（秒）

    // ---------- 其他 ----------
    'session_online_secs' => 25,  // 访客最后心跳在 N 秒内视为在线
    'data_dir'            => __DIR__ . '/data',

    // ---------- 站点角色（1+2+n 分域部署；单机部署保持 main 即可） ----------
    // main    = 全功能主站：数据库在本机、admin.html 管理台、登录页、访客页全部可用（默认）
    // agent   = 客服登录入口节点：只放行 login.html / app.html 与客服-租户相关接口
    // visitor = 访客站点节点：只放行 visitor.html / gate.html 与访客接口
    // 非主站节点需要：DB 指向主站 MySQL（db.host），上传经中转落到主站（site_main_url + relay_secret）
    'site_role'     => getenv('SITE_ROLE') ?: 'main',
    'site_main_url' => getenv('SITE_MAIN_URL') ?: 'https://kf.ylcz.cc',   // 主站对外地址，如 https://kf.ylcz.cc（非 main 节点必填）
    'relay_secret'  => getenv('RELAY_SECRET') ?: 'f242a61a4986f89ba3257c670100b5ac948d18c471b6faf5',    // 上传中转共享密钥（非空时主站开放 /api/internal/upload）
];
