<?php
/**
 * routes/notify.php — 告警发送口（v2.92）
 *
 * 一条出口：发卡机器人（@xiaomikefubot）私聊。
 * 收件人 = 管理员名单 kv `tg_admin_ids` + 绑定了自己客服账号的客户（tg_bindings 表）。
 * 去重：同一件事 / 同一个对象，默认 1 小时只发一次（notify_once），避免每小时巡检重复推送。
 * 谁在用：bin/check_codes.php（客服码被判红、门牌被自动下架）。
 */
declare(strict_types=1);

/** 确保 tg_send 可用（复用发卡机器人的 token 与发送函数，不另建一套） */
function notify_tg_ready(): bool
{
    if (!function_exists('tg_send')) {
        $f = __DIR__ . '/telegram.php';
        if (is_file($f)) { try { require_once $f; } catch (\Throwable $e) { } }
    }
    return function_exists('tg_send');
}

/** 限流闸：同一个 key 在 $seconds 秒内只放行一次（放行 = true） */
function notify_once(string $key, int $seconds = 3600): bool
{
    $k = 'notify_at:' . md5($key);
    try {
        $last = (int)kv_get($k, '0');
        if ($last > 0 && (time() - $last) < $seconds) return false;
        kv_set($k, (string)time());
    } catch (\Throwable $e) {
    }
    return true;
}

/** 给一个 TG 号发消息；成功返回 true */
function tg_notify_chat(int $chatId, string $html): bool
{
    if ($chatId === 0 || !notify_tg_ready()) return false;
    try {
        $r = tg_send($chatId, $html);
        return (bool)($r && !empty($r['ok']));
    } catch (\Throwable $e) {
        return false;
    }
}

/** 管理员名单（kv tg_admin_ids，逗号分隔的数字 ID） */
function notify_admin_ids(): array
{
    try {
        return array_values(array_filter(array_map('intval', explode(',', (string)kv_get('tg_admin_ids', '')))));
    } catch (\Throwable $e) {
        return [];
    }
}

/** 给所有管理员发一条；返回成功条数 */
function tg_notify_admins(string $html): int
{
    $n = 0;
    foreach (notify_admin_ids() as $id) {
        if (tg_notify_chat((int)$id, $html)) $n++;
    }
    return $n;
}

/** 绑定了某个租户客服账号的客户（表由「客户自助绑定」功能维护；表不存在就返回空） */
function notify_bound_chats(int $tenantId): array
{
    try {
        $st = db()->prepare('SELECT tg_id FROM tg_bindings WHERE tenant_id = ? AND enabled = 1');
        $st->execute([$tenantId]);
        return array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (\Throwable $e) {
        return [];
    }
}

/** 租户名 + 卡密（告警里带上，方便一眼认人） */
function notify_tenant_label(int $tenantId): string
{
    try {
        $st = db()->prepare('SELECT nickname, kami FROM tenants WHERE id = ?');
        $st->execute([$tenantId]);
        $r = $st->fetch();
        if ($r) {
            $n = trim((string)$r['nickname']);
            return ($n !== '' ? $n : ('租户#' . $tenantId)) . '（卡密 ' . (string)$r['kami'] . '）';
        }
    } catch (\Throwable $e) {
    }
    return '租户#' . $tenantId;
}

/** 客服码被判红 → 通知管理员 + 绑定了该账号的客户；返回送达条数 */
function notify_code_red(int $tenantId, string $code, string $link, string $reason = '', string $doorHost = ''): int
{
    if (!notify_once('code_red:' . $code, 3600)) return 0;
    $html = "⚠️ <b>客服码被判红</b>\n"
        . '账号：' . htmlspecialchars(notify_tenant_label($tenantId)) . "\n"
        . '客服码：<code>' . htmlspecialchars($code) . "</code>\n"
        . ($doorHost !== '' ? '门牌：' . htmlspecialchars($doorHost) . "（已自动下架）\n" : '')
        . ($reason !== '' ? '检测：' . htmlspecialchars(mb_substr($reason, 0, 120)) . "\n" : '')
        . "处理：在后台「获客码」页点「更换码」\n"
        . '时间：' . now_dt();
    $n = tg_notify_admins($html);

    $cust = "⚠️ <b>你的客服码被微信判红了</b>\n"
        . '客服码：<code>' . htmlspecialchars($code) . "</code>\n"
        . "客户扫码会打不开，请尽快在后台「获客码」页点「更换码」。\n"
        . '时间：' . now_dt();
    foreach (notify_bound_chats($tenantId) as $chat) {
        if (tg_notify_chat((int)$chat, $cust)) $n++;
    }
    return $n;
}

/** 门牌被自动下架 → 通知管理员；返回送达条数 */
function notify_door_off(string $doorHost, string $code = ''): int
{
    if (!notify_once('door_off:' . $doorHost, 3600)) return 0;
    return tg_notify_admins(
        "🚪 <b>门牌被自动下架</b>\n"
        . '门牌：' . htmlspecialchars($doorHost) . "\n"
        . ($code !== '' ? '触发：客服码 <code>' . htmlspecialchars($code) . "</code> 被判红\n" : '')
        . "后续：新发的码不会再抽到它；要恢复用就去后台「门牌池」手动开。\n"
        . '时间：' . now_dt()
    );
}
