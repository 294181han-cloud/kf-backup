<?php
/**
 * config.php - 全局配置（模板）
 *
 * 部署：复制本文件为 config.php 并填入真实凭据（config.php 已被 .gitignore 排除，不会提交）。
 * 也可用环境变量覆盖：DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS。
 */
declare(strict_types=1);

return [
    // ---------- 数据库 ----------
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int)(getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'YOUR_DB_NAME',
        'user'    => getenv('DB_USER') ?: 'YOUR_DB_USER',
        'pass'    => getenv('DB_PASS') ?: 'YOUR_DB_PASSWORD',
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
];
