<?php
/**
 * routes/telegram.php — 发卡机器人（@xiaomikefubot「小米云客服续费」）
 *
 * 干什么：在 Telegram 上自助卖「客服系统卡密」（kfxt 的租户账号）。
 *   /start → 选套餐 → 生成订单（USDT-TRC20 收款，金额带唯一小数尾数）→
 *   用户转账后点「我已支付」→ 每分钟的 cron 拉一次链上转账记录核款 →
 *   自动开卡（新开 / 续费）并把卡密发回给用户。
 *
 * 依赖：helpers.php（db / kv / gen_kami / gen_password / now_dt）
 * cron：bin/tg_orders.php（每分钟，核款 + 过期）
 *
 * 配置全部走 kv_store（后台「发卡机器人」里改）：
 *   tg_enabled / tg_token / tg_webhook_secret / tg_pay_addr / tg_admin_ids /
 *   tg_support / tg_order_ttl / tg_dry_run
 */
declare(strict_types=1);

const TG_USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';   // USDT-TRC20 合约
const TG_BASE_URL      = 'https://kf.ylcz.cc';

/* ==================== 配置 ==================== */

function tg_cfg(): array
{
    return [
        'enabled'  => kv_get('tg_enabled', '1') === '1',
        'token'    => kv_get('tg_token', ''),
        'secret'   => kv_get('tg_webhook_secret', ''),
        'pay_addr' => kv_get('tg_pay_addr', 'TYqHjF35ovKPRL5vA9CSbVs5mGEkBvsYBB'),
        'admins'   => array_values(array_filter(array_map('intval', explode(',', kv_get('tg_admin_ids', ''))))),
        'support'  => kv_get('tg_support', ''),
        'ttl'      => max(5, (int)kv_get('tg_order_ttl', '30')),
        'dry'      => kv_get('tg_dry_run', '0') === '1',
        // TronGrid API Key（有它才不会被限流；后台可换）
        'tron_key' => kv_get('tg_tron_key', 'b1c9add7-9922-4fb0-9e06-ead79da550ee'),
        // 测试卡：每人限领一张，有效期按小时配
        'trial_on'    => kv_get('tg_trial_on', '1') === '1',
        'trial_hours' => max(1, (int)kv_get('tg_trial_hours', '24')),
    ];
}

/* ==================== Telegram API ==================== */

/** 底层 HTTP（JSON）。返回解码后的数组；失败返回 null
 *  $tronKey 为真时带上 TronGrid 的 API Key 头 */
function tg_curl(string $url, ?array $payload = null, int $timeout = 15, bool $tronKey = false): ?array
{
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    if ($payload !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        $opt[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($tronKey) {
        $k = tg_cfg()['tron_key'];
        if ($k !== '') $opt[CURLOPT_HTTPHEADER] = ['TRON-PRO-API-KEY: ' . $k];
    }
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        tg_log('HTTP失败 ' . $url . ' :: ' . $err);
        return null;
    }
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : null;
}

function tg_log(string $s): void
{
    $f = __DIR__ . '/../data/tg.log';
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $s . "\n", FILE_APPEND);
}

/** 调 Telegram Bot API */
function tg_api(string $method, array $params = []): ?array
{
    $cfg = tg_cfg();
    if ($cfg['dry']) {
        // 演练模式：不外发，只把请求写日志（用于本地跑通全流程）
        @file_put_contents(__DIR__ . '/../data/tg_dry.log',
            date('Y-m-d H:i:s') . ' ' . $method . ' ' . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return ['ok' => true, 'result' => ['message_id' => random_int(1000, 9999)]];
    }
    if ($cfg['token'] === '') { tg_log('没配置 token，跳过 ' . $method); return null; }
    $r = tg_curl('https://api.telegram.org/bot' . $cfg['token'] . '/' . $method, $params);
    if (is_array($r) && empty($r['ok'])) tg_log($method . ' 出错：' . json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r;
}

/* ---- 键盘小工具 ---- */
function tg_btn(string $text, string $data): array      { return ['text' => $text, 'callback_data' => $data]; }
function tg_url(string $text, string $url): array       { return ['text' => $text, 'url' => $url]; }

/** 「点一下复制」按钮（Bot API 7.0+，老客户端不支持时会退化成普通按钮） */
function tg_copy(string $text, string $val): array      { return ['text' => $text, 'copy_text' => ['text' => $val]]; }

function tg_kb(array $rows): array { return ['inline_keyboard' => $rows]; }

/** 发消息（HTML）；$kb 为二维按钮数组 */
function tg_send(int $chat, string $html, array $kb = []): ?array
{
    $p = ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML',
          'disable_web_page_preview' => true];
    if ($kb) $p['reply_markup'] = tg_kb($kb);
    return tg_api('sendMessage', $p);
}

function tg_edit(int $chat, int $mid, string $html, array $kb = []): ?array
{
    $p = ['chat_id' => $chat, 'message_id' => $mid, 'text' => $html, 'parse_mode' => 'HTML',
          'disable_web_page_preview' => true];
    if ($kb) $p['reply_markup'] = tg_kb($kb);
    else     $p['reply_markup'] = ['inline_keyboard' => []];
    return tg_api('editMessageText', $p);
}

function tg_cb_answer(string $id, string $text = '', bool $alert = false): void
{
    if ($id === '') return;
    tg_api('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert]);
}

function tg_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ==================== 建表 ==================== */

function tg_schema(): void
{
    $pdo = db();
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
    // 老库补列（幂等）
    try { $pdo->exec("ALTER TABLE tg_users ADD COLUMN trial_kami VARCHAR(32) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tg_users ADD COLUMN trial_at DATETIME NULL"); } catch (\Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_pkgs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(32) NOT NULL,
        days INT UNSIGNED NOT NULL DEFAULT 30,
        quota_web INT UNSIGNED NOT NULL DEFAULT 200,
        quota_app INT UNSIGNED NOT NULL DEFAULT 200,
        max_agents INT UNSIGNED NOT NULL DEFAULT 5,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        badge VARCHAR(16) NOT NULL DEFAULT '',
        sort INT NOT NULL DEFAULT 0,
        enabled TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(32) NOT NULL,
        tg_id BIGINT NOT NULL,
        tg_name VARCHAR(64) NOT NULL DEFAULT '',
        kind VARCHAR(8) NOT NULL DEFAULT 'new',
        renew_kami VARCHAR(32) NOT NULL DEFAULT '',
        pkg_id INT UNSIGNED NOT NULL DEFAULT 0,
        pkg_name VARCHAR(32) NOT NULL DEFAULT '',
        days INT UNSIGNED NOT NULL DEFAULT 0,
        quota_web INT UNSIGNED NOT NULL DEFAULT 0,
        quota_app INT UNSIGNED NOT NULL DEFAULT 0,
        max_agents INT UNSIGNED NOT NULL DEFAULT 0,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        pay_addr VARCHAR(64) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        txid VARCHAR(80) NOT NULL DEFAULT '',
        kami VARCHAR(32) NOT NULL DEFAULT '',
        password VARCHAR(32) NOT NULL DEFAULT '',
        tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
        chat_id BIGINT NOT NULL DEFAULT 0,
        msg_id BIGINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expire_at DATETIME NULL,
        paid_at DATETIME NULL,
        delivered_at DATETIME NULL,
        UNIQUE KEY uniq_no (order_no),
        KEY idx_status (status, expire_at),
        KEY idx_kami (kami)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_chain_cache (
        txid VARCHAR(80) NOT NULL PRIMARY KEY,
        amount DECIMAL(16,6) NOT NULL DEFAULT 0,
        from_addr VARCHAR(64) NOT NULL DEFAULT '',
        at_ts BIGINT NOT NULL DEFAULT 0,
        used TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_used (used, at_ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ==================== 套餐 ==================== */

function tg_pkgs(bool $onlyOn = true): array
{
    $sql = 'SELECT * FROM tg_pkgs' . ($onlyOn ? ' WHERE enabled = 1' : '') . ' ORDER BY sort ASC, price ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

function tg_pkg(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM tg_pkgs WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** 首次运行时灌一套默认套餐（价格随便定的，后台可改） */
function tg_seed_pkgs(): void
{
    if (fetch_count(db(), 'SELECT COUNT(*) FROM tg_pkgs') > 0) return;
    $rows = [
        ['体验卡', 1,   50,   50,  1,  1.00, '试用', 10],
        ['月卡',   30,  200,  200, 5,  9.90, '',     20],
        ['季卡',   90,  600,  600, 10, 25.00, '热门', 30],
        ['年卡',   365, 3000, 3000, 20, 88.00, '最划算', 40],
    ];
    $ins = db()->prepare('INSERT INTO tg_pkgs (name, days, quota_web, quota_app, max_agents, price, badge, sort, enabled) VALUES (?,?,?,?,?,?,?,?,1)');
    foreach ($rows as $r) $ins->execute($r);
}

/* ==================== 订单 ==================== */

/** 给金额挑一个没被占用的唯一尾数，用来区分同一价格的多个订单 */
function tg_unique_amount(float $base): float
{
    $st = db()->prepare("SELECT amount FROM tg_orders WHERE status = 'pending' AND amount >= ? AND amount < ?");
    $lo = floor($base);
    $st->execute([$lo, $lo + 1]);
    $taken = array_map(fn($v) => round((float)$v, 2), $st->fetchAll(PDO::FETCH_COLUMN));
    $baseR = round($base, 2);
    if (!in_array($baseR, $taken, true)) return $baseR;
    for ($i = 1; $i <= 99; $i++) {
        $v = round($lo + $i / 100, 2);
        if ($v <= 0) continue;
        if (!in_array($v, $taken, true)) return $v;
    }
    return $baseR;
}

function tg_order_no(): string
{
    return 'KM' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
}

function tg_order_by_no(string $no): ?array
{
    $st = db()->prepare('SELECT * FROM tg_orders WHERE order_no = ?');
    $st->execute([$no]);
    $r = $st->fetch();
    return $r ?: null;
}

/* ==================== 链上收款 ==================== */

/** 拉最新一批转入本地址的 USDT-TRC20 转账，写进 tg_chain_cache（幂等） */
function tg_chain_sync(): int
{
    $addr = tg_cfg()['pay_addr'];
    if ($addr === '') return 0;
    $list = [];

    // 主：TronGrid
    $u = 'https://api.trongrid.io/v1/accounts/' . $addr . '/transactions/trc20?limit=100&only_to=true&contract_address=' . TG_USDT_CONTRACT;
    $r = tg_curl($u, null, 20, true);
    if (is_array($r) && !empty($r['data'])) {
        foreach ($r['data'] as $t) {
            if (($t['to'] ?? '') !== $addr) continue;
            $list[] = [
                'txid'   => (string)($t['transaction_id'] ?? ''),
                'amount' => (float)($t['value'] ?? 0) / 1e6,
                'from'   => (string)($t['from'] ?? ''),
                'ts'     => (int)round(((int)($t['block_timestamp'] ?? 0)) / 1000),
            ];
        }
    }
    // 备：Tronscan（TronGrid 挂了/限流时兜底）
    if (!$list) {
        $u2 = 'https://apilist.tronscanapi.com/api/token_trc20/transfers?limit=50&start=0&toAddress=' . $addr
            . '&contract_address=' . TG_USDT_CONTRACT;
        $r2 = tg_curl($u2, null, 20);
        foreach (($r2['token_transfers'] ?? []) as $t) {
            if (strcasecmp((string)($t['to_address'] ?? ''), $addr) !== 0) continue;
            $list[] = [
                'txid'   => (string)($t['transaction_id'] ?? ''),
                'amount' => (float)($t['quant'] ?? 0) / 1e6,
                'from'   => (string)($t['from_address'] ?? ''),
                'ts'     => (int)round(((int)($t['block_ts'] ?? 0)) / 1000),
            ];
        }
    }

    $n = 0;
    $ins = db()->prepare('INSERT INTO tg_chain_cache (txid, amount, from_addr, at_ts) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), at_ts = VALUES(at_ts)');
    foreach ($list as $t) {
        if ($t['txid'] === '' || $t['amount'] <= 0) continue;
        $ins->execute([$t['txid'], $t['amount'], $t['from'], $t['ts']]);
        $n++;
    }
    return $n;
}

/* ==================== 核款 + 发货 ==================== */

/** 开卡：新开一个租户，或给已有卡密续期。返回 [kami, password, tenant_id] */
function tg_deliver_tenant(array $o): array
{
    $pdo = db();
    $days = max(1, (int)$o['days']);
    $tgId = (int)($o['tg_id'] ?? 0);

    if ($o['kind'] === 'renew' && $o['renew_kami'] !== '') {
        $st = $pdo->prepare('SELECT * FROM tenants WHERE kami = ?');
        $st->execute([$o['renew_kami']]);
        $t = $st->fetch();
        if ($t) {
            // 从「现在」和「原到期日」里取更晚的那个往后加，过期卡也能续回来
            $base = ($t['expires_at'] && strtotime((string)$t['expires_at']) > time())
                ? strtotime((string)$t['expires_at']) : time();
            $newExp = date('Y-m-d H:i:s', $base + $days * 86400);
            // tg_id 为空的老卡顺带补上，后台列表才知道这张卡是谁的
            $pdo->prepare('UPDATE tenants SET expires_at = ?, status = ?, quota_web = quota_web + ?, quota_app = quota_app + ?,
                tg_id = IF(tg_id = 0, ?, tg_id) WHERE id = ?')
                ->execute([$newExp, 'active', (int)$o['quota_web'], (int)$o['quota_app'], $tgId, (int)$t['id']]);
            return [(string)$t['kami'], '', (int)$t['id']];
        }
        // 卡密不存在 → 当成新开处理（下面继续）
    }

    $kami = gen_kami();
    $password = gen_password();
    $expires = date('Y-m-d H:i:s', time() + $days * 86400);
    $pdo->prepare('INSERT INTO tenants (kami, password_hash, nickname, remark, expires_at, quota_web, quota_app, max_agents, closed_keep_days, max_sessions, tg_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$kami, password_hash($password, PASSWORD_DEFAULT),
            '客服', '发卡机器人 ' . $o['order_no'], $expires,
            (int)$o['quota_web'], (int)$o['quota_app'], (int)$o['max_agents'], 3, 1000, $tgId]);
    return [$kami, $password, (int)$pdo->lastInsertId()];
}

/** 发货：开卡 + 给用户发卡密卡片 */
function tg_order_deliver(array $o, string $txid = ''): bool
{
    $pdo = db();
    [$kami, $password, $tid] = tg_deliver_tenant($o);
    $pdo->prepare("UPDATE tg_orders SET status = 'delivered', txid = ?, kami = ?, password = ?, tenant_id = ?, paid_at = ?, delivered_at = ? WHERE id = ?")
        ->execute([$txid, $kami, $password, $tid, now_dt(), now_dt(), (int)$o['id']]);

    $st = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
    $st->execute([$tid]);
    $t = $st->fetch() ?: [];
    $exp = (string)($t['expires_at'] ?? '');
    $isRenew = ($o['kind'] === 'renew' && $o['renew_kami'] !== '' && $o['renew_kami'] === $kami);

    $html = ($isRenew ? "♻️ <b>续费成功，已到账</b>\n" : "✅ <b>支付成功，卡密已发货</b>\n")
        . "━━━━━━━━━━━━━━━\n"
        . "🎫 卡密：<code>" . tg_h($kami) . "</code>\n";
    if (!$isRenew && $password !== '') $html .= "🔑 密码：<code>" . tg_h($password) . "</code>\n";
    $html .= "📅 到期：" . tg_h($exp) . "（+" . (int)$o['days'] . " 天）\n"
        . "👥 子客服：" . (int)$o['max_agents'] . " 个　💬 额度：网页 " . (int)$o['quota_web'] . " / 客服码 " . (int)$o['quota_app'] . "\n"
        . "━━━━━━━━━━━━━━━\n"
        . "🧾 订单：" . tg_h($o['order_no']);
    if ($txid !== '') $html .= "\n🔗 TXID：<code>" . tg_h(substr($txid, 0, 24)) . "…</code>";

    tg_send((int)$o['chat_id'], $html, [
        [tg_url('🔗 立即登录', TG_BASE_URL . '/login.html')],
        [tg_btn('📖 使用教程', 'help'), tg_btn('🛒 再买一张', 'buy')],
    ]);
    // 原订单消息改成「已完成」，免得用户再点「我已支付」
    if ((int)$o['msg_id'] > 0) {
        tg_edit((int)$o['chat_id'], (int)$o['msg_id'],
            "✅ <b>订单已完成</b>\n━━━━━━━━━━━━━━━\n🧾 " . tg_h($o['order_no']) . "\n📦 " . tg_h($o['pkg_name'])
            . "\n💰 " . number_format((float)$o['amount'], 2) . " USDT\n🎫 卡密 <code>" . tg_h($kami) . "</code>（已单独发给你）",
            [[tg_btn('🛒 再买一张', 'buy'), tg_btn('📦 我的卡密', 'mine')]]);
    }
    tg_log('发货 ' . $o['order_no'] . ' → ' . $kami);
    return true;
}

/**
 * 跑一轮：同步链上 + 核对待支付订单 + 处理过期
 * @return int 本轮处理（发货）的订单数
 */
function tg_run_checks(): int
{
    tg_schema();
    $pdo = db();
    $synced = tg_chain_sync();

    // 待支付订单：没过期的才匹配
    $st = $pdo->query("SELECT * FROM tg_orders WHERE status = 'pending' ORDER BY id ASC LIMIT 200");
    $pending = $st->fetchAll();
    $done = 0;

    foreach ($pending as $o) {
        $exp = $o['expire_at'] ? strtotime((string)$o['expire_at']) : 0;
        if ($exp && $exp < time()) {
            $pdo->prepare("UPDATE tg_orders SET status = 'expired' WHERE id = ?")->execute([(int)$o['id']]);
            if ((int)$o['chat_id'] > 0) {
                tg_send((int)$o['chat_id'],
                    "⌛️ <b>订单已超时关闭</b>\n━━━━━━━━━━━━━━━\n🧾 " . tg_h($o['order_no'])
                    . "\n💰 " . number_format((float)$o['amount'], 2) . " USDT\n\n如果钱已经转了，请把 TXID 发给客服人工补单。",
                    [[tg_btn('🛒 重新下单', 'buy'), tg_support_btn()]]);
            }
            continue;
        }
        // 找一个「金额吻合 + 时间在订单之后」且没被别的订单用过的转账
        $from = date('Y-m-d H:i:s', strtotime((string)$o['created_at']) - 300);
        $st2 = $pdo->prepare("SELECT * FROM tg_chain_cache WHERE used = 0 AND at_ts >= ? AND ABS(amount - ?) < 0.005 ORDER BY at_ts ASC LIMIT 1");
        $st2->execute([strtotime($from), (float)$o['amount']]);
        $tx = $st2->fetch();
        if (!$tx) continue;
        $pdo->prepare('UPDATE tg_chain_cache SET used = 1 WHERE txid = ?')->execute([$tx['txid']]);
        try {
            tg_order_deliver($o, (string)$tx['txid']);
            $done++;
        } catch (\Throwable $e) {
            tg_log('发货失败 ' . $o['order_no'] . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE tg_orders SET status = 'failed' WHERE id = ?")->execute([(int)$o['id']]);
            tg_send((int)$o['chat_id'], "⚠️ 已收到款项，但自动开卡失败（" . tg_h($e->getMessage()) . "），请联系客服人工处理。");
        }
    }
    tg_log("本轮同步 $synced 条转账，发货 $done 单，待处理 " . count($pending) . " 单");
    return $done;
}

/* ==================== 用户 ==================== */

function tg_touch_user(array $from): array
{
    $id = (int)($from['id'] ?? 0);
    if ($id === 0) return ['tg_id' => 0, 'state' => ''];
    db()->prepare('INSERT INTO tg_users (tg_id, username, first_name, last_seen) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_seen = VALUES(last_seen)')
        ->execute([$id, (string)($from['username'] ?? ''), (string)($from['first_name'] ?? ''), now_dt()]);
    $st = db()->prepare('SELECT * FROM tg_users WHERE tg_id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: ['tg_id' => $id, 'state' => ''];
}

function tg_set_state(int $id, string $state): void
{
    db()->prepare('UPDATE tg_users SET state = ? WHERE tg_id = ?')->execute([$state, $id]);
}

function tg_is_admin(int $id): bool
{
    if ($id === 0) return false;
    return in_array($id, tg_cfg()['admins'], true);
}

/* ==================== 界面文案 ==================== */

function tg_main_menu(int $chat, array $u = [], int $mid = 0): void
{
    $name = tg_h((string)($u['first_name'] ?? ''));
    $html = "🎫 <b>小米云客服 · 发卡中心</b>\n"
        . "━━━━━━━━━━━━━━━\n"
        . ($name !== '' ? "👋 你好，{$name}！\n\n" : "👋 你好！\n\n")
        . "这里可以自助购买客服系统卡密：\n"
        . "🛒 选套餐 → 💰 转 USDT → 🤖 自动发货\n"
        . "♻️ 已有卡密可以直接续费\n"
        . "📦 买过的卡密随时能查\n\n"
        . "请选择 👇";
    $kb = [
        [tg_btn('🛒 购买卡密', 'buy'), tg_btn('♻️ 续费已有卡密', 'renew')],
        [tg_btn('🎁 免费领测试卡', 'trial')],
        [tg_btn('📦 我的卡密', 'mine'), tg_btn('📖 使用教程', 'help')],
        [tg_support_btn()],
    ];
    if (tg_is_admin((int)($u['id'] ?? 0))) array_unshift($kb, [tg_btn('🛠 管理后台', 'admin')]);
    if ($mid > 0) tg_edit($chat, $mid, $html, $kb); else tg_send($chat, $html, $kb);
}

function tg_buy_menu(int $chat, string $title = '选择套餐', int $mid = 0): void
{
    $pkgs = tg_pkgs();
    $html = "🛒 <b>{$title}</b>\n━━━━━━━━━━━━━━━\n";
    $rows = [];
    foreach ($pkgs as $p) {
        $badge = trim((string)$p['badge']);
        $html .= "\n" . ($badge !== '' ? "🔥 <b>" : "▪️ <b>") . tg_h((string)$p['name']) . "</b>"
            . ($badge !== '' ? " <i>{$badge}</i>" : '')
            . "\n　　💰 <b>" . number_format((float)$p['price'], 2) . "</b> USDT　📅 " . (int)$p['days'] . " 天"
            . "\n　　👥 子客服 " . (int)$p['max_agents'] . " 个　💬 网页 " . (int)$p['quota_web'] . " / 客服码 " . (int)$p['quota_app']
            . "\n";
        $rows[] = [tg_btn('「' . $p['name'] . '」' . number_format((float)$p['price'], 2) . ' USDT', 'buy:' . (int)$p['id'])];
    }
    if (!$pkgs) $html .= "\n暂时没有可售套餐，请联系客服。";
    else $html .= "\n👇 点下面的按钮选一个";
    $rows[] = [tg_btn('⬅️ 返回主菜单', 'menu')];
    if ($mid > 0) tg_edit($chat, $mid, $html, $rows); else tg_send($chat, $html, $rows);
}

/* ==================== 测试卡（每人限领一张） ==================== */

/** 领测试卡：没领过就发一张，领过就把原来那张再报一遍 */
function tg_trial(int $chat, array $u, array $me, int $mid = 0): void
{
    $cfg = tg_cfg();
    $tgId = (int)($me['tg_id'] ?? 0);
    $kbBack = [[tg_btn('🛒 购买正式卡', 'buy'), tg_btn('⬅️ 主菜单', 'menu')]];

    if (!$cfg['trial_on']) {
        $html = "🎁 <b>测试卡</b>\n━━━━━━━━━━━━━━━\n暂时不开放自助领取，需要试用请联系客服。";
        if ($mid > 0) tg_edit($chat, $mid, $html, [[tg_support_btn(), tg_btn('⬅️ 主菜单', 'menu')]]);
        else tg_send($chat, $html, [[tg_support_btn()]]);
        return;
    }

    // 已经领过：把那张卡再报一次，顺便看他还有没有时间
    $old = trim((string)($me['trial_kami'] ?? ''));
    if ($old !== '') {
        $st = db()->prepare('SELECT expires_at FROM tenants WHERE kami = ?');
        $st->execute([$old]);
        $t = $st->fetch();
        $exp = $t && $t['expires_at'] ? strtotime((string)$t['expires_at']) : 0;
        $leftTxt = $exp && $exp > time()
            ? '还剩 ' . (int)ceil(($exp - time()) / 3600) . ' 小时（到 ' . date('m-d H:i', $exp) . '）'
            : '已经过期了';
        $html = "🎁 <b>测试卡</b>\n━━━━━━━━━━━━━━━\n"
            . "你已经领过测试卡啦，每人只能领一张～\n\n"
            . "🎫 卡密：<code>" . tg_h($old) . "</code>\n"
            . "⏳ 状态：" . $leftTxt . "\n\n"
            . "要是用得顺手，直接买一张正式卡就能长期用 👇";
        if ($mid > 0) tg_edit($chat, $mid, $html, $kbBack);
        else tg_send($chat, $html, $kbBack);
        return;
    }

    // 发一张新的
    $hours = $cfg['trial_hours'];
    $kami = gen_kami();
    $password = gen_password();
    $expires = date('Y-m-d H:i:s', time() + $hours * 3600);
    $nick = mb_substr('测试卡', 0, 64);
    db()->prepare('INSERT INTO tenants (kami, password_hash, nickname, remark, expires_at, quota_web, quota_app, max_agents, closed_keep_days, max_sessions, tg_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$kami, password_hash($password, PASSWORD_DEFAULT), $nick,
            '机器人测试卡 @' . (string)($u['username'] ?? $tgId), $expires, 50, 50, 1, 3, 200, $tgId]);
    $tid = (int)db()->lastInsertId();
    db()->prepare('UPDATE tg_users SET trial_kami = ?, trial_at = ? WHERE tg_id = ?')->execute([$kami, now_dt(), $tgId]);

    $html = "🎁 <b>测试卡已发放</b>\n"
        . "━━━━━━━━━━━━━━━\n"
        . "🎫 卡密：<code>" . tg_h($kami) . "</code>\n"
        . "🔑 密码：<code>" . tg_h($password) . "</code>\n"
        . "⏳ 有效期：{$hours} 小时（到 " . date('m-d H:i', strtotime($expires)) . "）\n"
        . "👥 子客服：1 个　💬 额度：网页 50 / 客服码 50\n"
        . "━━━━━━━━━━━━━━━\n"
        . "⚠️ 每个 Telegram 账号只能领一次，到期后无法登录\n"
        . "💡 觉得好用可以直接买正式卡，长期有效、额度更大";
    $kb = [[tg_url('🔗 立即登录', TG_BASE_URL . '/login.html')],
           [tg_btn('🛒 购买正式卡', 'buy'), tg_btn('📖 使用教程', 'help')]];
    if ($mid > 0) tg_edit($chat, $mid, $html, $kb);
    else tg_send($chat, $html, $kb);
    tg_log('发放测试卡 ' . $kami . ' → tg ' . $tgId . '（租户 ' . $tid . '，' . $hours . ' 小时）');
}

function tg_help_text(): string
{
    return "📖 <b>使用教程</b>\n"
        . "━━━━━━━━━━━━━━━\n"
        . "1️⃣ 拿到卡密和密码后，打开 " . TG_BASE_URL . "/login.html\n"
        . "2️⃣ 用「卡密 + 密码」登录客服工作台\n"
        . "3️⃣ 首次登录建议在「个人设置 → 安全维护」里改掉密码\n"
        . "4️⃣ 进「客服码」生成你的咨询二维码 / 链接，贴到微信、抖音、网站都行\n"
        . "5️⃣ 有访客咨询时「工作台」会响铃提醒，聊天界面和微信一样好用\n\n"
        . "💡 一个卡密可以再开子客服账号，访客额度用完了找我续费就行。";
}

/** 「联系客服」的正文 + 按钮（support 支持 @username 或完整 t.me 链接） */
function tg_support_view(): array
{
    $sp = trim(tg_cfg()['support']);
    $html = "💬 <b>联系客服</b>\n━━━━━━━━━━━━━━━\n";
    $kb = [];
    if ($sp !== '') {
        if (preg_match('#^https?://#i', $sp)) {
            $url = $sp;
            $label = $sp;
            $html .= "👉 " . tg_h($sp) . "\n";
        } else {
            $handle = ltrim($sp, '@');
            $url = 'https://t.me/' . $handle;
            $label = '@' . $handle;
            $html .= "👉 <b>" . tg_h($label) . "</b>\n";
        }
        $html .= "\n点下面的按钮直接找我，也可以直接在这里发消息。";
        $kb[] = [tg_url('💬 打开会话 ' . $label, $url)];
    } else {
        $html .= "直接在这里发消息就行，客服看到会尽快回你。";
    }
    $kb[] = [tg_btn('🛒 购买卡密', 'buy'), tg_btn('🎁 领测试卡', 'trial')];
    $kb[] = [tg_btn('⬅️ 返回主菜单', 'menu')];
    return [$html, $kb];
}

/** 客服按钮：配了 @用户名 / t.me 链接就是「点一下直接跳过去」的直链按钮，
 *  没配就退回成点了显示联系方式的普通按钮 */
function tg_support_btn(string $text = '💬 联系客服'): array
{
    $sp = trim(tg_cfg()['support']);
    if ($sp === '') return tg_btn($text, 'support');
    if (preg_match('#^https?://#i', $sp)) return tg_url($text, $sp);
    $handle = ltrim($sp, '@');
    return tg_url($text . ' ' . '@' . $handle, 'https://t.me/' . $handle);
}

function tg_mine(int $chat, int $tgId, int $mid = 0): void
{
    $st = db()->prepare("SELECT * FROM tg_orders WHERE tg_id = ? AND status = 'delivered' ORDER BY id DESC LIMIT 10");
    $st->execute([$tgId]);
    $rows = $st->fetchAll();
    if (!$rows) {
        $html = "📦 <b>我的卡密</b>\n━━━━━━━━━━━━━━━\n你还没有买过卡密哦～\n点下面的按钮去挑一个吧 👇";
        $kb = [[tg_btn('🛒 购买卡密', 'buy')], [tg_btn('⬅️ 返回主菜单', 'menu')]];
    } else {
        $html = "📦 <b>我的卡密</b>\n━━━━━━━━━━━━━━━\n";
        foreach ($rows as $o) {
            $t = null;
            if ($o['kami'] !== '') {
                $s2 = db()->prepare('SELECT expires_at, status FROM tenants WHERE kami = ?');
                $s2->execute([$o['kami']]);
                $t = $s2->fetch() ?: null;
            }
            $expStr = $t && $t['expires_at'] ? date('Y-m-d', strtotime((string)$t['expires_at'])) : '—';
            $left = $t && $t['expires_at'] ? ceil((strtotime((string)$t['expires_at']) - time()) / 86400) : 0;
            $html .= "\n🎫 <code>" . tg_h((string)$o['kami']) . "</code>\n"
                . "　　📅 " . $expStr . ($left > 0 ? "（剩 {$left} 天）" : "（已到期）") . "\n"
                . "　　📦 " . tg_h((string)$o['pkg_name']) . "　🧾 " . tg_h((string)$o['order_no']) . "\n";
        }
        $html .= "\n🔗 登录地址：" . TG_BASE_URL . "/login.html";
        $kb = [[tg_url('🔗 打开登录页', TG_BASE_URL . '/login.html')],
               [tg_btn('♻️ 续费', 'renew'), tg_btn('🛒 再买一张', 'buy')],
               [tg_btn('⬅️ 返回主菜单', 'menu')]];
    }
    if ($mid > 0) tg_edit($chat, $mid, $html, $kb); else tg_send($chat, $html, $kb);
}

/* ==================== 下单 ==================== */

function tg_order_card(array $o): string
{
    $addr = (string)$o['pay_addr'];
    $amt  = number_format((float)$o['amount'], 2);
    $left = max(0, (int)ceil((strtotime((string)$o['expire_at']) - time()) / 60));
    return "🧾 <b>订单已创建</b>\n"
        . "━━━━━━━━━━━━━━━\n"
        . "📦 套餐　" . tg_h((string)$o['pkg_name']) . "（" . (int)$o['days'] . " 天）"
        . ($o['kind'] === 'renew' && $o['renew_kami'] !== '' ? "\n♻️ 续费卡密　<code>" . tg_h((string)$o['renew_kami']) . "</code>" : '')
        . "\n💰 应付　<b>{$amt} USDT</b>\n"
        . "🌐 网络　TRC-20（波场）\n"
        . "⏳ 剩余　约 {$left} 分钟\n"
        . "━━━━━━━━━━━━━━━\n"
        . "📮 <b>收款地址</b>\n<code>{$addr}</code>\n\n"
        . "⚠️ 请<b>只转 {$amt} 这个精确金额</b>，多一分少一分都没法自动发货（这个尾数是你这张订单专属的）\n"
        . "✅ 转完点下面的「我已支付」，系统会自动核链发货，一般 1 分钟内到账";
}

function tg_order_kb(array $o): array
{
    return [
        [tg_btn('✅ 我已支付', 'paid:' . $o['order_no'])],
        [tg_copy('📋 复制收款地址', (string)$o['pay_addr']), tg_btn('❌ 取消订单', 'cancel:' . $o['order_no'])],
    ];
}

/** 建单（同一个人已有待支付订单时，直接把那张单再推一遍，避免撞单） */
function tg_create_order(int $chat, array $u, int $pkgId, string $kind = 'new', string $renewKami = ''): void
{
    $tgId = (int)($u['id'] ?? 0);
    $pkgs = tg_pkgs();
    $pkg = null;
    foreach ($pkgs as $p) if ((int)$p['id'] === $pkgId) $pkg = $p;
    if (!$pkg) { tg_send($chat, '这个套餐已经下架了，请重新选一个。', [[tg_btn('🛒 选择套餐', 'buy')]]); return; }

    $st = db()->prepare("SELECT * FROM tg_orders WHERE tg_id = ? AND status = 'pending' AND kind = ? AND renew_kami = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$tgId, $kind, $renewKami]);
    $old = $st->fetch();
    if ($old && strtotime((string)$old['expire_at']) > time()) {
        $sent = tg_send($chat, "ℹ️ 你还有一张没付完的订单，先把这张付了吧 👇\n\n" . tg_order_card($old), tg_order_kb($old));
        if ($sent && isset($sent['result']['message_id'])) {
            db()->prepare('UPDATE tg_orders SET chat_id = ?, msg_id = ? WHERE id = ?')
                ->execute([$chat, (int)$sent['result']['message_id'], (int)$old['id']]);
        }
        return;
    }

    $cfg = tg_cfg();
    $amount = tg_unique_amount((float)$pkg['price']);
    $no = tg_order_no();
    db()->prepare("INSERT INTO tg_orders (order_no, tg_id, tg_name, kind, renew_kami, pkg_id, pkg_name, days,
            quota_web, quota_app, max_agents, amount, pay_addr, status, chat_id, created_at, expire_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending', ?, ?, ?)")
        ->execute([$no, $tgId, mb_substr((string)($u['first_name'] ?? ''), 0, 64), $kind, $renewKami,
            (int)$pkg['id'], (string)$pkg['name'], (int)$pkg['days'],
            (int)$pkg['quota_web'], (int)$pkg['quota_app'], (int)$pkg['max_agents'],
            $amount, (string)$cfg['pay_addr'], $chat, now_dt(), now_dt(time() + $cfg['ttl'] * 60)]);
    $id = (int)db()->lastInsertId();
    $order = db()->query('SELECT * FROM tg_orders WHERE id = ' . $id)->fetch();
    $sent = tg_send($chat, tg_order_card($order), tg_order_kb($order));
    if ($sent && isset($sent['result']['message_id'])) {
        db()->prepare('UPDATE tg_orders SET msg_id = ? WHERE id = ?')->execute([(int)$sent['result']['message_id'], $id]);
    }
}

/* ==================== 限频地跑一次核款 ==================== */

function tg_maybe_run(int $minGapSec = 20): void
{
    $last = (int)kv_get('tg_last_run', '0');
    if (time() - $last < $minGapSec) return;
    kv_set('tg_last_run', (string)time());
    try { tg_run_checks(); } catch (\Throwable $e) { tg_log('核款异常：' . $e->getMessage()); }
}

/* ==================== 更新分发 ==================== */

function tg_on_update(array $u): void
{
    tg_schema();

    /* ---- 按钮回调 ---- */
    if (isset($u['callback_query'])) {
        $cq   = $u['callback_query'];
        $chat = (int)($cq['message']['chat']['id'] ?? 0);
        $mid  = (int)($cq['message']['message_id'] ?? 0);
        $from = is_array($cq['from'] ?? null) ? $cq['from'] : [];
        $data = (string)($cq['data'] ?? '');
        $me   = tg_touch_user($from);
        $tgId = (int)$me['tg_id'];

        if ($data === 'menu')  { tg_cb_answer((string)$cq['id']); tg_main_menu($chat, $from, $mid); return; }
        if ($data === 'buy')   { tg_cb_answer((string)$cq['id']); tg_buy_menu($chat, '选择套餐', $mid); return; }
        if ($data === 'mine')  { tg_cb_answer((string)$cq['id']); tg_mine($chat, $tgId, $mid); return; }
        if ($data === 'trial') { tg_cb_answer((string)$cq['id']); tg_trial($chat, $from, $me, $mid); return; }
        if ($data === 'help')  { tg_cb_answer((string)$cq['id']); tg_edit($chat, $mid, tg_help_text(), [[tg_url('🔗 打开登录页', TG_BASE_URL . '/login.html')], [tg_btn('⬅️ 返回主菜单', 'menu')]]); return; }
        if ($data === 'support') {
            tg_cb_answer((string)$cq['id']);
            [$html, $kb] = tg_support_view();
            tg_edit($chat, $mid, $html, $kb);
            return;
        }
        if ($data === 'admin' && tg_is_admin($tgId)) {
            tg_cb_answer((string)$cq['id']);
            $pdo = db();
            $today = date('Y-m-d');
            $paid = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM tg_orders WHERE status='delivered' AND DATE(delivered_at)='$today'")->fetchColumn();
            $cnt  = (int)$pdo->query("SELECT COUNT(*) FROM tg_orders WHERE DATE(created_at)='$today'")->fetchColumn();
            $done = (int)$pdo->query("SELECT COUNT(*) FROM tg_orders WHERE status='delivered'")->fetchColumn();
            $pend = (int)$pdo->query("SELECT COUNT(*) FROM tg_orders WHERE status='pending'")->fetchColumn();
            $all  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM tg_orders WHERE status='delivered'")->fetchColumn();
            tg_edit($chat, $mid, "🛠 <b>发卡数据</b>\n━━━━━━━━━━━━━━━\n"
                . "📅 今日下单　{$cnt} 单\n💰 今日收款　<b>" . number_format($paid, 2) . "</b> USDT\n"
                . "✅ 累计发货　{$done} 单\n💵 累计收款　<b>" . number_format($all, 2) . "</b> USDT\n"
                . "⏳ 待支付　{$pend} 单", [[tg_btn('⬅️ 返回主菜单', 'menu')]]);
            return;
        }
        if ($data === 'renew') {
            tg_cb_answer((string)$cq['id']);
            tg_set_state($tgId, 'renew');
            tg_edit($chat, $mid, "♻️ <b>续费</b>\n━━━━━━━━━━━━━━━\n把你现有的<b>卡密</b>发给我（就是登录用的那串，例如 <code>ABCD123456</code>），我来给你选续费套餐。",
                [[tg_btn('⬅️ 返回主菜单', 'menu')]]);
            return;
        }
        if (strpos($data, 'buy:') === 0) {
            tg_cb_answer((string)$cq['id']);
            tg_create_order($chat, $from, (int)substr($data, 4));
            return;
        }
        if (strpos($data, 'rbuy:') === 0) {
            $kami = '';
            if (strpos((string)$me['state'], 'renew:') === 0) $kami = substr((string)$me['state'], 6);
            tg_set_state($tgId, '');
            tg_cb_answer((string)$cq['id']);
            if ($kami === '') { tg_send($chat, '请先把卡密发给我。', [[tg_btn('♻️ 续费', 'renew')]]); return; }
            tg_create_order($chat, $from, (int)substr($data, 5), 'renew', $kami);
            return;
        }
        if (strpos($data, 'paid:') === 0) {
            $no = substr($data, 5);
            $o = tg_order_by_no($no);
            if (!$o) { tg_cb_answer((string)$cq['id'], '订单不存在', true); return; }
            if ($o['status'] === 'delivered') { tg_cb_answer((string)$cq['id'], '这单已经发货啦，翻上面的消息看卡密 👆', true); return; }
            if ($o['status'] !== 'pending') { tg_cb_answer((string)$cq['id'], '这单已经关闭了，请重新下单', true); return; }
            tg_cb_answer((string)$cq['id'], '收到，正在核对链上转账…');
            tg_send($chat, "🔍 <b>正在核对链上转账</b>\n━━━━━━━━━━━━━━━\n订单 " . tg_h($no)
                . "\n金额 " . number_format((float)$o['amount'], 2) . " USDT\n\n"
                . "系统每分钟自动查一次，到账后立刻把卡密发你，稍等一下下～\n"
                . "（超过 10 分钟还没到账，把转账的 TXID 发给客服）");
            tg_maybe_run(10);
            $o2 = tg_order_by_no($no);
            if ($o2 && $o2['status'] === 'delivered') tg_cb_answer('', '');
            return;
        }
        if (strpos($data, 'cancel:') === 0) {
            $no = substr($data, 5);
            $o = tg_order_by_no($no);
            if ($o && $o['status'] === 'pending') {
                db()->prepare("UPDATE tg_orders SET status = 'canceled' WHERE id = ?")->execute([(int)$o['id']]);
                tg_cb_answer((string)$cq['id'], '订单已取消');
                tg_edit($chat, $mid, "❌ <b>订单已取消</b>\n━━━━━━━━━━━━━━━\n🧾 " . tg_h($no)
                    . "\n\n如果刚刚已经转过钱了，请把 TXID 发给客服人工处理。",
                    [[tg_btn('🛒 重新下单', 'buy'), tg_btn('⬅️ 主菜单', 'menu')]]);
            } else {
                tg_cb_answer((string)$cq['id'], '这单不能取消了', true);
            }
            return;
        }
        tg_cb_answer((string)$cq['id']);
        return;
    }

    /* ---- 普通消息 ---- */
    $msg = $u['message'] ?? $u['edited_message'] ?? null;
    if (!is_array($msg)) return;
    $chat = (int)($msg['chat']['id'] ?? 0);
    $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
    $text = trim((string)($msg['text'] ?? ''));
    if ($chat === 0) return;
    $me = tg_touch_user($from);
    $tgId = (int)$me['tg_id'];

    // 命令
    $cmd = strtolower((string)(explode(' ', $text)[0] ?? ''));
    if ($cmd === '/start' || $cmd === '/menu' || $cmd === '') { tg_set_state($tgId, ''); tg_main_menu($chat, $from); return; }
    if ($cmd === '/id') { tg_send($chat, "🆔 你的 Telegram ID：<code>{$tgId}</code>\n把这个填进后台「发卡机器人 → 管理员 ID」就能用 /admin 看数据。"); return; }
    if ($cmd === '/buy')  { tg_buy_menu($chat); return; }
    if ($cmd === '/trial' || $cmd === '/test') { tg_trial($chat, $from, $me); return; }
    if ($cmd === '/my' || $cmd === '/mine') { tg_mine($chat, $tgId); return; }
    if ($cmd === '/help') { tg_send($chat, tg_help_text(), [[tg_url('🔗 打开登录页', TG_BASE_URL . '/login.html')], [tg_btn('⬅️ 返回主菜单', 'menu')]]); return; }
    if ($cmd === '/support' || $cmd === '/kf') { [$h, $k] = tg_support_view(); tg_send($chat, $h, $k); return; }
    if ($cmd === '/admin' && tg_is_admin($tgId)) {
        $pdo = db();
        $today = date('Y-m-d');
        $paid = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM tg_orders WHERE status='delivered' AND DATE(delivered_at)='$today'")->fetchColumn();
        $cnt  = (int)$pdo->query("SELECT COUNT(*) FROM tg_orders WHERE DATE(created_at)='$today'")->fetchColumn();
        $all  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM tg_orders WHERE status='delivered'")->fetchColumn();
        $pend = (int)$pdo->query("SELECT COUNT(*) FROM tg_orders WHERE status='pending'")->fetchColumn();
        tg_send($chat, "🛠 <b>发卡数据</b>\n━━━━━━━━━━━━━━━\n📅 今日下单 {$cnt} 单\n💰 今日收款 <b>"
            . number_format($paid, 2) . "</b> USDT\n💵 累计收款 <b>" . number_format($all, 2) . "</b> USDT\n⏳ 待支付 {$pend} 单");
        return;
    }

    // 续费：等用户发卡密
    if ((string)$me['state'] === 'renew') {
        $kami = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $text));
        $st = db()->prepare('SELECT * FROM tenants WHERE kami = ?');
        $st->execute([$kami]);
        $t = $st->fetch();
        if (!$t) {
            tg_send($chat, "❓ 没找到卡密 <code>" . tg_h($kami) . "</code>\n\n"
                . "请检查一下（大小写不区分，登录页能登进去的那串就是）。也可以点下面按钮重来。",
                [[tg_btn('♻️ 重新输入', 'renew'), tg_btn('⬅️ 主菜单', 'menu')]]);
            return;
        }
        tg_set_state($tgId, 'renew:' . $kami);
        $exp = $t['expires_at'] ? date('Y-m-d', strtotime((string)$t['expires_at'])) : '未设置';
        $left = $t['expires_at'] ? (int)ceil((strtotime((string)$t['expires_at']) - time()) / 86400) : 0;
        tg_buy_menu($chat, '选择续费套餐（' . $kami . ' 目前 ' . $exp . ($left > 0 ? '，剩 ' . $left . ' 天' : '，已过期') . '）');
        return;
    }

    // 其它文字：当成找客服
    tg_send($chat, "💬 已收到你的消息，客服看到会尽快回复。\n\n需要自助买卡/续费的话，点下面按钮 👇",
        [[tg_btn('🛒 购买卡密', 'buy'), tg_btn('♻️ 续费', 'renew')], [tg_btn('⬅️ 返回主菜单', 'menu')]]);
}

/* ==================== Webhook 入口 ==================== */

function handle_tg_webhook(string $method): void
{
    if ($method !== 'POST') { http_response_code(405); echo 'method'; exit; }
    $cfg = tg_cfg();
    $secret = (string)input('secret', '');
    // 双保险：query 里的 secret + Telegram 的 X-Telegram-Bot-Api-Secret-Token 头
    $hdr = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    $okQ = ($secret !== '' && hash_equals($cfg['secret'], $secret));
    $okH = ($hdr !== '' && hash_equals($cfg['secret'], $hdr));
    if ($cfg['secret'] === '' || (!$okQ && !$okH)) {
        http_response_code(403); echo 'forbidden'; exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $u = json_decode($raw, true);
    if (!is_array($u)) { echo 'ok'; exit; }
    try { tg_on_update($u); } catch (\Throwable $e) { tg_log('处理更新异常：' . $e->getMessage()); }
    // 永远回 200，免得 Telegram 反复重推同一条
    echo 'ok';
    exit;
}

/** 设置 webhook（后台按钮 / CLI 都走这里） */
function tg_set_webhook(): array
{
    $cfg = tg_cfg();
    if ($cfg['secret'] === '') {
        $cfg['secret'] = bin2hex(random_bytes(16));
        kv_set('tg_webhook_secret', $cfg['secret']);
    }
    $url = TG_BASE_URL . '/api/tg/webhook?secret=' . $cfg['secret'];
    $r = tg_api('setWebhook', ['url' => $url, 'allowed_updates' => ['message', 'callback_query'],
        'max_connections' => 40, 'drop_pending_updates' => true, 'secret_token' => $cfg['secret']]);
    tg_api('setMyCommands', ['commands' => [
        ['command' => 'start', 'description' => '打开菜单'],
        ['command' => 'buy',   'description' => '购买卡密'],
        ['command' => 'trial', 'description' => '免费领测试卡'],
        ['command' => 'my',    'description' => '我的卡密'],
        ['command' => 'help',  'description' => '使用教程'],
        ['command' => 'id',    'description' => '查看我的 Telegram ID'],
        ['command' => 'support', 'description' => '联系客服'],
    ]]);
    return ['ok' => (bool)($r['ok'] ?? false), 'url' => $url, 'resp' => $r];
}
