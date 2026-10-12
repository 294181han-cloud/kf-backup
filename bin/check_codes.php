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

$ok = 0; $red = 0; $other = 0; $newAlerts = 0; $redCodes = [];   // v2.91.27 红码明细（用于顺藤下架门牌/落地域名）
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
        $redCodes[] = ['code' => (string)$c['code'], 'link' => (string)$c['link'], 'tenant_id' => (int)$c['tenant_id']];
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


/* v2.91.27 按用户要求：巡检不再单独逐个查域名池/门牌池（省接口调用），
   而是**以「租户在用的客服码」为依据**：某条码被判红 → 顺着它找到对应的【门牌】和【落地域名】，
   两个都自动下架退役，避免租户之后点「换码」又换到红的门牌/域名。
   恢复后不会自动开回来（要人工在后台确认）。 */
$doorOff = 0; $alerts = 0;   // v2.91.28 只统计门牌下架

/* 预建映射（避免在循环里反复查库）：
   $entryMap[客服码] = entry_id ；$codeRows[客服码] = 该码整行 ；$doorToCodes[门牌] = 用到它的码列表 */
$entryMap = []; $codeRows = []; $doorToCodes = [];
try {
    foreach ($pdo->query('SELECT tenant_id, code, entry_id, link FROM service_codes WHERE enabled = 1') as $c0) {
        $c0code = (string)$c0['code'];
        $entryMap[$c0code] = (string)$c0['entry_id'];
        $codeRows[$c0code] = $c0;
        $d0 = strtolower((string)(parse_url(gate_code_link((string)$c0['entry_id'], (string)$c0['link']), PHP_URL_HOST) ?: ''));
        if ($d0 !== '') $doorToCodes[$d0][] = $c0code;
    }
} catch (\Throwable $e) { }

foreach ($redCodes as $rc) {
    $rcCode = (string)$rc['code'];
    $rcLink = (string)$rc['link'];
    $landDomain = strtolower((string)pool_domain_of((string)parse_url($rcLink, PHP_URL_HOST)));

    /* v2.91.28 按用户要求：红码【只退役门牌，不动落地域名】。
       实测依据（用户给的这条红码）：门牌 unrivaled-moxie-03037b 查红 = red（被封），
       而它指向的落地 h652ww.shop 查红 = ok（完全正常）。
       一个落地域名上通常挂着好几个租户的多条码，因一条红码就下架整个落地域名会连累别人。
       落地域名真要停用，由管理员在后台「域名池」页手动处理。 */

    /* ② 对应的门牌：停用 + 记红名单（租户换码就不会再抽到它） */
    try {
        $entryId = (string)($entryMap[$rcCode] ?? '');
        $doorLink = gate_code_link($entryId, $rcLink);
        $doorHost = strtolower((string)parse_url($doorLink, PHP_URL_HOST));
        if ($doorHost !== '') {
            $st2 = $pdo->prepare('SELECT id, enabled FROM door_pool WHERE domain = ? LIMIT 1');
            $st2->execute([$doorHost]);
            $row2 = $st2->fetch(PDO::FETCH_ASSOC);
            if ($row2) {
                $pdo->prepare("INSERT IGNORE INTO red_domains (host, main, kind, code, channel) VALUES (?,?,?,?, 'wx')")
                    ->execute([$doorHost, $doorHost, 'main', $rcCode]);
                $pdo->prepare('UPDATE door_pool SET fail_count = fail_count + 1, red_at = ? WHERE id = ?')
                    ->execute([now_dt(), (int)$row2['id']]);
                if ((int)$row2['enabled'] === 1) {
                    $pdo->prepare('UPDATE door_pool SET enabled = 0 WHERE id = ?')->execute([(int)$row2['id']]);
                    $doorOff++;
                    echo date('Y-m-d H:i:s'), " 门牌 ", $doorHost, " 因客服码 ", $rcCode, " 被判红 → 已下架\n";
                }
                /* 同一张门牌下的其他在用码：也提醒一下（它们的码实际已经打不开了） */
                foreach ($doorToCodes[$doorHost] ?? [] as $ocCode) {
                    if ($ocCode === $rcCode) continue;
                    $oc = $codeRows[$ocCode] ?? null;
                    if (!$oc) continue;
                    try {
                        $chk = $pdo->prepare('SELECT id FROM code_alerts WHERE tenant_id = ? AND code = ? AND seen = 0 LIMIT 1');
                        $chk->execute([(int)$oc['tenant_id'], $ocCode]);
                        if (!$chk->fetchColumn()) {
                            $pdo->prepare('INSERT INTO code_alerts (tenant_id, code, link, msg) VALUES (?,?,?,?)')
                                ->execute([(int)$oc['tenant_id'], $ocCode, (string)$oc['link'],
                                    '这条码所在的门牌已被微信拦截，客户扫不开 —— 请到「客服码」页点「更换码」换一条']);
                            $alerts++;
                        }
                    } catch (\Throwable $e) { }
                }
            }
        }
    } catch (\Throwable $e) { }
}

/* v2.91.27b 汇总行必须放在「顺藤下架」之后，否则统计恒为 0 */
echo date('Y-m-d H:i:s'), " 巡检完成：共 ", count($codes), " 个码 → 正常 {$ok}、红 {$red}、异常/未开通 {$other}；新增提醒 {$newAlerts} 条",
    "；因红码下架：门牌 {$doorOff} 个；顺带提醒 {$alerts} 条\n";
