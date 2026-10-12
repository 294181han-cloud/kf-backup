<?php
/**
 * config.local.example.php —— 本地开发配置模板（v2.90.98）
 *
 * 用法：
 *   1. 复制本文件为 config.php（config.php 已在 .gitignore 里，不会被提交）
 *   2. 把 'pass' 改成你本机 MySQL 的密码
 *   3. 数据库名建议单独建一个（如 kfxt_local），别连任何线上库
 *
 * 关键点：site_role 保持 main；relay_secret 与 hosts 一律留空 ——
 *   这两项留空 = 本地不连任何线上节点，防止误操作影响生产。
 */
declare(strict_types=1);

return [
    // ---------- 数据库（本地）----------
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'kfxt_local',
        'user'    => 'root',
        'pass'    => '',              // ← 本机 MySQL 密码（Laragon 默认 root 无密码就留空）
        'charset' => 'utf8mb4',
    ],

    // ---------- 鉴权 ----------
    // JWT 密钥首次初始化时自动生成并写入 kv_store，无需手工配置
    'jwt_ttl' => 60 * 60 * 24 * 7,

    // ---------- 上传 ----------
    'upload_dir'       => __DIR__ . '/uploads',
    'upload_url'       => '/uploads',
    'upload_max_image' => 8 * 1024 * 1024,
    'upload_max_audio' => 10 * 1024 * 1024,

    // ---------- 限频 ----------
    'rate_limit_per_min' => 30,
    'login_max_fails'    => 10,
    'login_lock_secs'    => 900,

    // ---------- 其他 ----------
    'session_online_secs' => 25,
    'data_dir'            => __DIR__ . '/data',

    // ---------- 站点角色 ----------
    'site_role'     => 'main',
    'site_main_url' => 'http://localhost',   // 本地自测用，随便填本机地址
    'relay_secret'  => '',                   // 本地留空：关闭节点中转/远程推送，防误连线上

    // ---------- 分域 hosts 覆盖 ----------
    'hosts' => [],                           // 本地留空
];
