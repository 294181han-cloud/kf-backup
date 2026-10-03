<?php
/**
 * routes/tgadmin.php — 后台「发卡机器人」管理接口（平台管理员专用）
 *
 *   GET  /api/admin/tg              读配置 + 套餐 + 订单 + webhook 状态
 *   POST /api/admin/tg/save         保存配置
 *   POST /api/admin/tg/pkg          新增 / 修改套餐
 *   POST /api/admin/tg/pkg-delete   删除套餐
 *   POST /api/admin/tg/webhook      重新设置 Telegram webhook
 *   POST /api/admin/tg/order        订单操作（补发货 / 取消）
 *   POST /api/admin/tg/check        立刻跑一次核款
 */
declare(strict_types=1);

function handle_admin_tg(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    tg_schema();
    $pdo = db();
    $cfg = tg_cfg();

    $pkgs = $pdo->query('SELECT * FROM tg_pkgs ORDER BY sort ASC, price ASC, id ASC')->fetchAll();
    $orders = $pdo->query('SELECT * FROM tg_orders ORDER BY id DESC LIMIT 60')->fetchAll();
    $ord = array_map(function ($o) {
        return [
            'id' => (int)$o['id'], 'order_no' => $o['order_no'], 'tg_id' => (string)$o['tg_id'], 'tg_name' => $o['tg_name'],
            'kind' => $o['kind'], 'renew_kami' => $o['renew_kami'], 'pkg_name' => $o['pkg_name'],
            'amount' => (float)$o['amount'], 'status' => $o['status'], 'kami' => $o['kami'],
            'txid' => $o['txid'], 'created_at' => $o['created_at'], 'delivered_at' => $o['delivered_at'],
        ];
    }, $orders);

    $stat = [
        'today_count'  => fetch_count($pdo, "SELECT COUNT(*) FROM tg_orders WHERE DATE(created_at) = ?", [date('Y-m-d')]),
        'today_paid'   => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM tg_orders WHERE status='delivered' AND DATE(delivered_at)='" . date('Y-m-d') . "'")->fetchColumn(),
        'total_paid'   => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM tg_orders WHERE status='delivered'")->fetchColumn(),
        'total_done'   => fetch_count($pdo, "SELECT COUNT(*) FROM tg_orders WHERE status='delivered'"),
        'pending'      => fetch_count($pdo, "SELECT COUNT(*) FROM tg_orders WHERE status='pending'"),
        'users'        => fetch_count($pdo, 'SELECT COUNT(*) FROM tg_users'),
        'trials'       => fetch_count($pdo, "SELECT COUNT(*) FROM tg_users WHERE trial_kami <> ''"),
    ];

    json_out([
        'cfg' => [
            'enabled'  => $cfg['enabled'],
            'token'    => $cfg['token'],
            'pay_addr' => $cfg['pay_addr'],
            'support'  => $cfg['support'],
            'ttl'      => $cfg['ttl'],
            'admins'   => implode(',', $cfg['admins']),
            'tron_key' => $cfg['tron_key'],
            'dry'      => $cfg['dry'],
            'trial_on'    => $cfg['trial_on'],
            'trial_hours' => $cfg['trial_hours'],
            'webhook'  => TG_BASE_URL . '/api/tg/webhook?secret=' . $cfg['secret'],
        ],
        'bot'    => tg_api('getMe')['result'] ?? null,
        'hook'   => tg_api('getWebhookInfo')['result'] ?? null,
        'pkgs'   => $pkgs,
        'orders' => $ord,
        'stat'   => $stat,
    ]);
}

function handle_admin_tg_save(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $d = body();
    if (array_key_exists('token', $d))    kv_set('tg_token', trim((string)$d['token']));
    if (array_key_exists('pay_addr', $d)) kv_set('tg_pay_addr', trim((string)$d['pay_addr']));
    if (array_key_exists('support', $d))  kv_set('tg_support', trim((string)$d['support']));
    if (array_key_exists('tron_key', $d)) kv_set('tg_tron_key', trim((string)$d['tron_key']));
    if (array_key_exists('admins', $d))   kv_set('tg_admin_ids', trim((string)$d['admins']));
    if (array_key_exists('ttl', $d))      kv_set('tg_order_ttl', (string)max(5, (int)$d['ttl']));
    if (array_key_exists('enabled', $d))  kv_set('tg_enabled', ((int)$d['enabled'] === 1) ? '1' : '0');
    if (array_key_exists('dry', $d))      kv_set('tg_dry_run', ((int)$d['dry'] === 1) ? '1' : '0');
    if (array_key_exists('trial_on', $d))    kv_set('tg_trial_on', ((int)$d['trial_on'] === 1) ? '1' : '0');
    if (array_key_exists('trial_hours', $d)) kv_set('tg_trial_hours', (string)max(1, min(720, (int)$d['trial_hours'])));
    json_out(['ok' => true]);
}

function handle_admin_tg_pkg(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $d = body();
    $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 32);
    if ($name === '') json_error('套餐名称不能为空');
    $args = [
        $name,
        max(1, (int)($d['days'] ?? 30)),
        max(0, (int)($d['quota_web'] ?? 200)),
        max(0, (int)($d['quota_app'] ?? 200)),
        max(0, min(50, (int)($d['max_agents'] ?? 5))),
        max(0, (float)($d['price'] ?? 0)),
        mb_substr(trim((string)($d['badge'] ?? '')), 0, 16),
        (int)($d['sort'] ?? 0),
        ((int)($d['enabled'] ?? 1) === 1) ? 1 : 0,
    ];
    $id = (int)($d['id'] ?? 0);
    if ($id > 0) {
        $args[] = $id;
        db()->prepare('UPDATE tg_pkgs SET name=?, days=?, quota_web=?, quota_app=?, max_agents=?, price=?, badge=?, sort=?, enabled=? WHERE id=?')
            ->execute($args);
    } else {
        db()->prepare('INSERT INTO tg_pkgs (name, days, quota_web, quota_app, max_agents, price, badge, sort, enabled) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute($args);
        $id = (int)db()->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id]);
}

function handle_admin_tg_pkg_delete(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $id = (int)input('id', 0);
    if ($id <= 0) json_error('参数错误');
    db()->prepare('DELETE FROM tg_pkgs WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

function handle_admin_tg_webhook(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $r = tg_set_webhook();
    json_out($r);
}

/** 手动补发货（链上到账了但自动匹配没成功的兜底） */
function handle_admin_tg_order(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $d = body();
    $no = trim((string)($d['order_no'] ?? ''));
    $action = (string)($d['action'] ?? '');
    $o = tg_order_by_no($no);
    if (!$o) json_error('订单不存在');
    if ($action === 'cancel') {
        db()->prepare("UPDATE tg_orders SET status = 'canceled' WHERE id = ?")->execute([(int)$o['id']]);
        json_out(['ok' => true]);
    }
    if ($action === 'deliver') {
        if ($o['status'] === 'delivered') json_error('这单已经发过货了');
        tg_order_deliver($o, (string)$o['txid']);
        json_out(['ok' => true, 'kami' => db()->query('SELECT kami FROM tg_orders WHERE id=' . (int)$o['id'])->fetchColumn()]);
    }
    json_error('不支持的操作');
}

function handle_admin_tg_check(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    require_admin_ctx();
    $n = tg_run_checks();
    json_out(['ok' => true, 'delivered' => $n]);
}
