<?php
/**
 * 发卡机器人核款（每分钟跑一次）
 *
 * 干什么：
 *   1. 拉一次收款地址（USDT-TRC20）最近的转入记录，写进 tg_chain_cache；
 *   2. 拿还没支付的订单去比对：金额吻合（误差 < 0.005）+ 时间在订单之后 + 那笔转账没被别人用过 → 自动开卡发货；
 *   3. 超时没付的订单标记 expired 并通知用户。
 *
 * crontab：
 *   * * * * * /usr/bin/php /www/wwwroot/kf.ylcz.cc/bin/tg_orders.php >> /www/server/cron/kfxt-tg.log 2>&1
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require __DIR__ . '/../helpers.php';
require __DIR__ . '/../routes/telegram.php';

$cfg = tg_cfg();
if (!$cfg['enabled']) {
    echo date('Y-m-d H:i:s'), " 发卡机器人未启用（tg_enabled=0），跳过\n";
    exit;
}

try {
    $n = tg_run_checks();
    echo date('Y-m-d H:i:s'), " 核款完成，本轮到账发货 {$n} 单\n";
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s'), " 出错：", $e->getMessage(), "\n";
    exit(1);
}
