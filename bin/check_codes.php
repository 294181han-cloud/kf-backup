<?php
/**
 * 客服码「红码」巡检（v2.64）
 *
 * 干什么：把所有客服码逐个丢给第三方接口查一遍微信有没有拦截（俗称「红了」）。
 *        查出来是红的 → 往 code_alerts 写一条未读提醒；工作台轮询到就会弹窗告诉租户。
 *        某条码恢复正常 → 把它未读的提醒删掉，这样以后再次变红还能重新提醒。
 *
 * 怎么跑：crontab 每小时一次（见 DEPLOY.md）：
 *        0 * * * * /usr/bin/php /www/wwwroot/kf.ylcz.cc/bin/check_codes.php >> /www/server/cron/kfxt-code-check.log 2>&1
 *
 * 注意：**每一次检测都是真实的付费接口调用**。码越多、跑得越勤，扣得越多。
 *      想省钱就把频率调低，或者临时把 kv_store 的 wxcheck_on 设成 0（脚本会直接跳过）。
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
require __DIR__ . '/../helpers.php';

$pdo = db();
$cfg = wxcheck_cfg();
if (!$cfg['on']) {
    echo date('Y-m-d H:i:s'), " 检测未开通（wxcheck_on=0），跳过\n";
    exit;
}

$codes = $pdo->query('SELECT id, tenant_id, code, link FROM service_codes ORDER BY id ASC')->fetchAll();
if (!$codes) {
    echo date('Y-m-d H:i:s'), " 没有客服码，跳过\n";
    exit;
}

$ok = 0; $red = 0; $other = 0; $newAlerts = 0;
foreach ($codes as $c) {
    $res = wxcheck_run((string)$c['link'], 'wx');
    // 留档（code_check_cache 只当"上次检测记录"，不参与结果）
    try {
        $pdo->prepare('INSERT INTO code_check_cache (tenant_id, link_hash, channel, link, state, tag, text, msg, at_ts)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE state=VALUES(state), tag=VALUES(tag), text=VALUES(text), msg=VALUES(msg),
              link=VALUES(link), at_ts=VALUES(at_ts)')
            ->execute([(int)$c['tenant_id'], md5((string)$c['link']), 'wx', mb_substr((string)$c['link'], 0, 500),
                $res['state'], $res['tag'], $res['text'], mb_substr((string)$res['msg'], 0, 500), time()]);
    } catch (\Throwable $e) {}

    if ($res['state'] === 'red') {
        $red++;
        /* v2.63 码红了 → 自动退役：固定子域从子域池摘除 / 主域停用池行，并记入红名单 */
        try { code_red_retire((string)$c['link'], 'wx'); } catch (\Throwable $e) {}
        $st = $pdo->prepare('SELECT id FROM code_alerts WHERE tenant_id = ? AND code = ? AND seen = 0 LIMIT 1');
        $st->execute([(int)$c['tenant_id'], (string)$c['code']]);
        if (!$st->fetchColumn()) {
            $pdo->prepare('INSERT INTO code_alerts (tenant_id, code, link, msg) VALUES (?,?,?,?)')
                ->execute([(int)$c['tenant_id'], (string)$c['code'], (string)$c['link'], mb_substr((string)$res['msg'], 0, 500)]);
            $newAlerts++;
        }
    } elseif ($res['state'] === 'ok') {
        $ok++;
        // 恢复正常了：清掉未读提醒，下次再红还能重新提醒
        $pdo->prepare('DELETE FROM code_alerts WHERE tenant_id = ? AND code = ? AND seen = 0')
            ->execute([(int)$c['tenant_id'], (string)$c['code']]);
    } else {
        $other++;   // off / err：不动提醒，避免误清
    }
    usleep(300000);   // 每条间隔 0.3s，别把接口打爆
}

/* v2.60.17 域名体检：域名池里某个域名被微信封了 → 自动下掉（enabled=0），
   免得「换码」时又抽到一个已经红了的域名（现场踩过：moupz.cn 整域被封，换码还可能抽到它）。
   恢复了**不会**自动开回来，要人工在「域名池」里确认后再启用。 */
$pool = $pdo->query('SELECT id, domain, enabled FROM domain_pool ORDER BY id')->fetchAll();
$disabled = 0; $poolRed = 0;
foreach ($pool as $d) {
    $r = wxcheck_single_run('https://' . $d['domain'] . '/', 'wx');
    if (($r['state'] ?? '') === 'red') {
        $poolRed++;
        /* v2.63 主域红也记入红名单（子域/主域明细统一在此表），并自动停用池行 */
        try { code_red_retire('https://' . $d['domain'] . '/', 'wx'); } catch (\Throwable $e) {}
        if ((int)$d['enabled'] === 1) {
            $pdo->prepare('UPDATE domain_pool SET enabled = 0 WHERE id = ?')->execute([(int)$d['id']]);
            $disabled++;
            echo date('Y-m-d H:i:s'), " 域名 ", $d['domain'], " 已被微信拦截（", mb_substr((string)($r['msg'] ?? ''), 0, 30), "）→ 已从域名池下掉\n";
        }
    }
    usleep(300000);
}

echo date('Y-m-d H:i:s'), " 巡检完成：共 ", count($codes), " 个码 → 正常 {$ok}、红 {$red}、异常/未开通 {$other}；新增提醒 {$newAlerts} 条",
    "；域名池 ", count($pool), " 个域名 → 红 {$poolRed}、本次下掉 {$disabled} 个\n";
