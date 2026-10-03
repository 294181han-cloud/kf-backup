<?php
/**
 * config.node.example.php — 分控节点配置模板（客服入口 / 访客站点通用）
 *
 * 用法：node/node-setup.sh 会把本模板复制为站点根目录的 config.php 并替换占位符。
 * 也可以手工复制后把下面 <尖括号> 里的值改成节点自己的。
 */
declare(strict_types=1);

return [
    // ---------- 数据库（连主站的 MySQL） ----------
    'db' => [
        'host'    => '<主站IP或内网地址>',
        'port'    => 3306,
        'name'    => 'kfxt',
        'user'    => 'kfxt',
        'pass'    => '<主站授权给该节点的密码>',
        'charset' => 'utf8mb4',
    ],

    // ---------- 鉴权（密钥存主站 kv_store，同库共享，无需配置） ----------
    'jwt_ttl' => 60 * 60 * 24 * 7,

    // ---------- 上传（本地不落盘，自动中转到主站） ----------
    'upload_dir'      => __DIR__ . '/uploads',
    'upload_url'      => '/uploads',
    'upload_max_image' => 5 * 1024 * 1024,
    'upload_max_audio' => 10 * 1024 * 1024,

    // ---------- 限频 ----------
    'rate_limit_per_min' => 30,
    'login_max_fails'    => 10,
    'login_lock_secs'    => 900,

    // ---------- 其他 ----------
    'session_online_secs' => 25,
    'data_dir'            => __DIR__ . '/data',

    // ---------- 站点角色（本节点身份，按用途二选一） ----------
    'site_role'     => '<agent 或 visitor>',
    'site_main_url' => 'https://<主站域名>',           // 如 https://kf.ylcz.cc
    'relay_secret'  => '<主站 config.php 里的 relay_secret 值>',
];
