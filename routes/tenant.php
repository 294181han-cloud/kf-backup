<?php
/**
 * routes/tenant.php - 租户专属管理（子客服 / 客服码 / 专属链接 / 品牌设置）
 * 仅 tenant 角色可访问
 */
declare(strict_types=1);

/* ---------- 子客服管理 ---------- */

function handle_tenant_agents(string $method): void
{
    $ctx = require_tenant();
    $tid = $ctx['id'];
    $pdo = db();

    if ($method === 'GET') {
        $st = $pdo->prepare('SELECT id, username, nickname, avatar, work_status, auto_accept, sound_enabled, signature, status, created_at FROM agents WHERE tenant_id = ? ORDER BY id');
        $st->execute([$tid]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['created_at'] = dt_to_iso($r['created_at']); }
        unset($r);
        json_out($rows);
    }

    if ($method === 'POST') {
        $data = body();
        $username = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($data['username'] ?? ''));
        $nickname = mb_substr(trim((string)($data['nickname'] ?? '客服')), 0, 64);
        if (mb_strlen($username) < 2) json_error('客服用户名至少 2 位（字母/数字/下划线）');
        if (mb_strlen($username) > 32) json_error('客服用户名过长');

        $password = (string)($data['password'] ?? '');
        if ($password === '') $password = gen_password();
        elseif (strlen($password) < 6) json_error('密码至少 6 位');

        // 先查重名（提示更明确），再查数量上限
        $dup = $pdo->prepare('SELECT 1 FROM agents WHERE tenant_id = ? AND username = ?');
        $dup->execute([$tid, $username]);
        if ($dup->fetch()) json_error('该用户名已存在');
        $t = $ctx['row'];
        $cnt = fetch_count($pdo, 'SELECT COUNT(*) FROM agents WHERE tenant_id = ?', [$tid]);
        if ($cnt >= (int)$t['max_agents']) json_error('子客服数量已达上限（' . $t['max_agents'] . ' 个）');

        $pdo->prepare('INSERT INTO agents (tenant_id, username, password_hash, nickname, avatar) VALUES (?,?,?,?,?)')
            ->execute([$tid, $username, password_hash($password, PASSWORD_DEFAULT), $nickname, (string)($data['avatar'] ?? '👨‍💼')]);
        $id = (int)$pdo->lastInsertId();
        json_out(['ok' => true, 'agent' => ['id' => $id, 'username' => $username, 'nickname' => $nickname],
            'plain' => ['username' => $username, 'password' => $password],
            'login_account' => $ctx['kami'] . '/' . $username], 201);
    }

    json_error('方法不允许', 405);
}

function handle_tenant_agents_id(string $method, int $id): void
{
    $ctx = require_tenant();
    $tid = $ctx['id'];
    $pdo = db();

    $st = $pdo->prepare('SELECT * FROM agents WHERE id = ? AND tenant_id = ?');
    $st->execute([$id, $tid]);
    $a = $st->fetch();
    if (!$a) json_error('客服不存在', 400);

    if ($method === 'PUT') {
        $data = body();
        $set = []; $vals = [];
        foreach (['nickname', 'avatar', 'signature'] as $f) {
            if (array_key_exists($f, $data)) { $set[] = "$f = ?"; $vals[] = mb_substr((string)$data[$f], 0, 255); }
        }
        foreach (['work_status', 'status'] as $f) {
            if (array_key_exists($f, $data)) {
                $allow = $f === 'status' ? ['active', 'disabled'] : ['online', 'busy', 'away', 'offline'];
                $v = in_array($data[$f], $allow, true) ? $data[$f] : $allow[0];
                $set[] = "$f = ?"; $vals[] = $v;
            }
        }
        foreach (['auto_accept', 'sound_enabled'] as $f) {
            if (array_key_exists($f, $data)) { $set[] = "$f = ?"; $vals[] = $data[$f] ? 1 : 0; }
        }
        if (array_key_exists('password', $data) && (string)$data['password'] !== '') {
            if (strlen((string)$data['password']) < 6) json_error('密码至少 6 位');
            $set[] = 'password_hash = ?';
            $vals[] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
        }
        if ($set) {
            $vals[] = $id;
            $pdo->prepare('UPDATE agents SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        }
        $st = $pdo->prepare('SELECT id, username, nickname, avatar, work_status, status, auto_accept, sound_enabled, signature FROM agents WHERE id = ?');
        $st->execute([$id]);
        json_out(['ok' => true, 'agent' => $st->fetch()]);
    }

    if ($method === 'DELETE') {
        // 该客服的会话退回待接入池
        $pdo->prepare('UPDATE v_sessions SET agent_id = 0 WHERE tenant_id = ? AND agent_id = ?')->execute([$tid, $id]);
        $pdo->prepare('DELETE FROM agents WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
        json_out(['ok' => true]);
    }

    json_error('方法不允许', 405);
}

/* ---------- 客服码 ---------- */

function handle_tenant_service_code(string $method): void
{
    $ctx = require_tenant();
    $tid = $ctx['id'];
    $pdo = db();

    if ($method === 'GET') {
        $st = $pdo->prepare('SELECT id, code, link, created_at FROM service_codes WHERE tenant_id = ? ORDER BY id DESC');
        $st->execute([$tid]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['created_at'] = dt_to_iso($r['created_at']); }
        unset($r);
        json_out($rows);
    }

    if ($method === 'POST') {
        $rows = $pdo->query('SELECT domain, wildcard FROM domain_pool WHERE enabled = 1 ORDER BY id ASC')->fetchAll();
        if (!$rows) json_error('域名池为空，请联系平台管理员添加可用域名', 400);
        $pick = $rows[array_rand($rows)];
        $code = gen_code_token();
        $scheme = 'https';
        if ($pick['wildcard']) {
            // 泛解析域名：随机子域即入口标识，根路径直达（无 /visitor.html?code= 后缀）
            $sub = substr(preg_replace('/[^a-z0-9]/', 'x', strtolower(base64_encode(random_bytes(6)))), 0, 8);
            $url = "$scheme://$sub.{$pick['domain']}/";
        } else {
            $url = "$scheme://{$pick['domain']}/visitor.html?code=$code";
        }
        $pdo->prepare('INSERT INTO service_codes (tenant_id, code, link) VALUES (?,?,?)')->execute([$tid, $code, $url]);
        json_out(['ok' => true, 'code' => ['id' => (int)$pdo->lastInsertId(), 'code' => $code, 'link' => $url, 'created_at' => dt_to_iso(now_dt())]], 201);
    }

    if ($method === 'DELETE') {
        $code = trim((string)input('code', ''));
        if ($code === '') json_error('缺少 code');
        $pdo->prepare('DELETE FROM service_codes WHERE code = ? AND tenant_id = ?')->execute([$code, $tid]);
        json_out(['ok' => true]);
    }

    json_error('方法不允许', 405);
}

/* ---------- 专属链接 ---------- */

function handle_tenant_link(string $method): void
{
    $ctx = require_tenant();
    $tid = $ctx['id'];
    $pdo = db();
    $root = kv_get('root_domain', '');

    if ($method === 'GET') {
        $st = $pdo->prepare('SELECT subdomain, link, created_at FROM domain_links WHERE tenant_id = ? AND active = 1 ORDER BY id DESC LIMIT 1');
        $st->execute([$tid]);
        $cur = $st->fetch();
        json_out(['root_domain' => $root, 'link' => $cur['link'] ?? '', 'subdomain' => $cur['subdomain'] ?? '',
            'created_at' => $cur ? dt_to_iso($cur['created_at']) : null]);
    }

    if ($method === 'POST') {
        if ($root === '') json_error('平台尚未设置主域名，请联系管理员', 400);
        $st = $pdo->prepare('SELECT id, subdomain, link FROM domain_links WHERE tenant_id = ? AND active = 1 LIMIT 1');
        $st->execute([$tid]);
        if ($cur = $st->fetch()) json_out(['ok' => true, 'link' => $cur['link'], 'subdomain' => $cur['subdomain'], 'reused' => true]);
        $sub = gen_subdomain();
        $link = "https://$sub.$root/visitor.html";
        $pdo->prepare('INSERT INTO domain_links (tenant_id, subdomain, link, active) VALUES (?,?,?,1)')->execute([$tid, $sub, $link]);
        json_out(['ok' => true, 'link' => $link, 'subdomain' => $sub], 201);
    }

    json_error('方法不允许', 405);
}

function handle_tenant_link_renew(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_tenant();
    $tid = $ctx['id'];
    $pdo = db();
    $root = kv_get('root_domain', '');
    if ($root === '') json_error('平台尚未设置主域名，请联系管理员', 400);
    $pdo->prepare('UPDATE domain_links SET active = 0 WHERE tenant_id = ? AND active = 1')->execute([$tid]);
    $sub = gen_subdomain();
    $link = "https://$sub.$root/visitor.html";
    $pdo->prepare('INSERT INTO domain_links (tenant_id, subdomain, link, active) VALUES (?,?,?,1)')->execute([$tid, $sub, $link]);
    json_out(['ok' => true, 'link' => $link, 'subdomain' => $sub, 'renewed' => true]);
}

/* ---------- 品牌与访客端设置（含公告 / 过滤 / 验证策略） ---------- */

function handle_tenant_settings(string $method): void
{
    $ctx = require_tenant();
    $tid = $ctx['id'];

    if ($method === 'GET') {
        $t = $ctx['row'];
        json_out([
            'brand_company'  => ts_get($tid, 'brand_company', $t['nickname'] ?: '在线客服'),
            'brand_title'    => ts_get($tid, 'brand_title', '您好，欢迎咨询！'),
            'brand_sub'      => ts_get($tid, 'brand_sub', '请简单描述您的问题，客服会尽快回复'),
            'brand_avatar'   => ts_get($tid, 'brand_avatar', '💬'),
            'brand_color'    => ts_get($tid, 'brand_color', '#1677ff'),
            'brand_position' => ts_get($tid, 'brand_position', 'right-bottom'),
            'pre_inquiry_on' => ts_get($tid, 'pre_inquiry_on', '0') === '1',
            'visitor_template' => ts_get($tid, 'visitor_template', 'whatsapp'),
            // 验证策略
            'robot'          => ts_get($tid, 'robot', 'no'),
            'robot_frequency' => ts_get($tid, 'robot_frequency', 'first'),
            'robot_code'     => ts_get($tid, 'robot_code', ''),
            // 会话管理（v2.25）：已结束保留天数（0=永久）、会话总数额度（0=不限）
            'closed_keep_days' => (int)($t['closed_keep_days'] ?? 3),
            'max_sessions'     => (int)($t['max_sessions'] ?? 0),
            // 链接模式
            'link_mode'      => ts_get($tid, 'link_mode', 'no'),
            // 头像/昵称
            'user_head_type' => ts_get($tid, 'user_head_type', 'default'),
            'user_head'      => ts_get($tid, 'user_head', ''),
            'user_name_type' => ts_get($tid, 'user_name_type', 'default'),
            // 访客过滤
            'filtration_wx'   => ts_get($tid, 'filtration_wx', '0') === '1',
            'filtration_pc'   => ts_get($tid, 'filtration_pc', '0') === '1',
            'filtration_area' => ts_get($tid, 'filtration_area', '0') === '1',
            // 弹窗公告 / 滚动通知
            'publicity_on'    => ts_get($tid, 'publicity_on', '0') === '1',
            'publicity_title' => ts_get($tid, 'publicity_title', ''),
            'publicity_content' => ts_get($tid, 'publicity_content', ''),
            'inform_on'       => ts_get($tid, 'inform_on', '0') === '1',
            'inform_text'     => ts_get($tid, 'inform_text', ''),
            // 导航深度配置
            'tpl_nav_on'      => ts_get($tid, 'tpl_nav_on', '1') === '1',
            'tpl_nav_title'   => ts_get($tid, 'tpl_nav_title', ''),
            'vip_badge'       => ts_get($tid, 'vip_badge', ''),
            // 码设置（获客码页）
            'me_avatar'       => ts_get($tid, 'me_avatar', ''),
            'code_logo_type'  => ts_get($tid, 'code_logo_type', 'wechat'),
            'code_nickname_on' => ts_get($tid, 'code_nickname_on', '1') === '1',
            'code_support_on' => ts_get($tid, 'code_support_on', '1') === '1',
            'footer_text'     => ts_get($tid, 'footer_text', ''),
            // 「请求访客上传图片」话术（客服在工作台点该按钮时发给访客的文案）
            'ask_image_text'  => ts_get($tid, 'ask_image_text', '方便的话，请上传一下相关图片，帮您更快定位问题～'),
            // 公告（访客端顶部横幅）
            'notice_on'      => ts_get($tid, 'notice_on', '0') === '1',
            'notice_text'    => ts_get($tid, 'notice_text', ''),
            // 访客过滤：关键词自动拉黑
            'filter_keywords' => ts_get($tid, 'filter_keywords', ''),
            'auto_blacklist' => ts_get($tid, 'auto_blacklist', '0') === '1',
            // 验证策略：访客去重 / IP 频率限制
            'dedup_on'       => ts_get($tid, 'dedup_on', '0') === '1',
            'ip_limit_on'    => ts_get($tid, 'ip_limit_on', '0') === '1',
            'ip_limit_count' => (int)ts_get($tid, 'ip_limit_count', '5'),
            // 聊天头像：是否显示客服设置的个人头像（关闭/未设置时用品牌头像）
            'agent_avatar_on' => ts_get($tid, 'agent_avatar_on', '1') === '1',
            // 声音设置（v2.27，租户级；客服端/访客端分开，发送/接收分开）
            'agent_snd_recv_on'   => ts_get($tid, 'agent_snd_recv_on', '1') === '1',
            'agent_snd_recv_which' => ts_get($tid, 'agent_snd_recv_which', 'dindon'),
            'agent_snd_send_on'   => ts_get($tid, 'agent_snd_send_on', '1') === '1',
            'agent_snd_send_which' => ts_get($tid, 'agent_snd_send_which', 'water'),
            'visitor_snd_recv_on'   => ts_get($tid, 'visitor_snd_recv_on', '1') === '1',
            'visitor_snd_recv_which' => ts_get($tid, 'visitor_snd_recv_which', 'dindon'),
            'visitor_snd_send_on'   => ts_get($tid, 'visitor_snd_send_on', '1') === '1',
            'visitor_snd_send_which' => ts_get($tid, 'visitor_snd_send_which', 'water'),
            // 客服码卡片样式 + 上次检测结果（v2.30）
            'code_dot'         => ts_get($tid, 'code_dot', 'rounded'),
            'code_eye'         => ts_get($tid, 'code_eye', 'rounded'),
            'code_brand'       => ts_get($tid, 'code_brand', '#2E6BE6'),
            'code_logo'        => ts_get($tid, 'code_logo', 'none'),
            'code_logo_size'   => ts_get($tid, 'code_logo_size', 'std'),
            'code_top_text'    => ts_get($tid, 'code_top_text', '扫码咨询'),
            'code_bottom_text' => ts_get($tid, 'code_bottom_text', '官方客服'),
            'code_color_dots'  => ts_get($tid, 'code_color_dots', '0') === '1',
            'code_custom_logo' => ts_get($tid, 'code_custom_logo', ''),
            'code_check_state' => ts_get($tid, 'code_check_state', 'idle'),
            'code_check_text'  => ts_get($tid, 'code_check_text', '未检测'),
            'code_check_tag'   => ts_get($tid, 'code_check_tag', '未检测'),
            'code_check_at'    => ts_get($tid, 'code_check_at', ''),
            'code_check_channel' => ts_get($tid, 'code_check_channel', ''),
        ]);
    }

    if ($method === 'PUT') {
        $data = body();
        $labels = ['brand_company' => '品牌名称', 'brand_title' => '欢迎标题', 'brand_sub' => '欢迎副标题',
            'brand_avatar' => '头像', 'brand_color' => '主题色', 'brand_position' => '窗口位置',
            'notice_text' => '公告内容', 'filter_keywords' => '黑名单关键词', 'visitor_template' => '访客端模板',
            'robot' => '验证策略', 'robot_frequency' => '验证频率', 'robot_code' => '邀请码/密码',
            'link_mode' => '链接模式', 'user_head_type' => '访客头像类型', 'user_head' => '自定义访客头像',
            'user_name_type' => '访客名称类型', 'publicity_title' => '弹窗公告标题', 'publicity_content' => '弹窗公告内容',
            'inform_text' => '滚动通知文案', 'tpl_nav_title' => '导航栏标题', 'vip_badge' => 'VIP标识', 'footer_text' => '底部标识',
            'ask_image_text' => '请求上传图片话术',
            // 声音选择（v2.27）：water 水滴 / pling / weixin 微信 / dindon / click 点击
            'agent_snd_recv_which' => '客服端接收提示音', 'agent_snd_send_which' => '客服端发送提示音',
            'visitor_snd_recv_which' => '访客端接收提示音', 'visitor_snd_send_which' => '访客端发送提示音'];
        foreach ($labels as $k => $label) {
            if (array_key_exists($k, $data)) {
                $v = mb_substr(trim((string)$data[$k]), 0, 500);
                if ($k === 'brand_color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $v = '#1677ff';
                if ($k === 'brand_position' && !in_array($v, ['right-bottom', 'left-bottom', 'right-center', 'left-center'], true)) $v = 'right-bottom';
                if ($k === 'notice_text') $v = mb_substr($v, 0, 200);
                if ($k === 'brand_avatar' || $k === 'brand_color') $v = mb_substr($v, 0, 255);
                if ($v === '' && in_array($k, ['brand_company', 'brand_title'], true)) json_error("$label 不能为空");
                ts_set($tid, $k, $v);
            }
        }
        foreach (['pre_inquiry_on', 'notice_on', 'auto_blacklist', 'dedup_on', 'ip_limit_on', 'filtration_wx', 'filtration_pc', 'filtration_area', 'publicity_on', 'inform_on', 'tpl_nav_on', 'agent_snd_recv_on', 'agent_snd_send_on', 'visitor_snd_recv_on', 'visitor_snd_send_on', 'agent_avatar_on'] as $k) {
            if (array_key_exists($k, $data)) ts_set($tid, $k, $data[$k] ? '1' : '0');
        }
        if (array_key_exists('ip_limit_count', $data)) {
            ts_set($tid, 'ip_limit_count', (string)max(1, min(100, (int)$data['ip_limit_count'])));
        }
        // 枚举键校验
        $enums = [
            'robot' => ['no', 'click', 'slide', 'code', 'invite', 'password'],
            'robot_frequency' => ['first', 'every'],
            'link_mode' => ['no', 'copy', 'jump'],
            'user_head_type' => ['default', 'random', 'custom'],
            'user_name_type' => ['default', 'random'],
            'code_logo_type' => ['avatar', 'wechat', 'alipay', 'mix'],
            'agent_snd_recv_which' => ['water', 'pling', 'weixin', 'dindon', 'click'],
            'agent_snd_send_which' => ['water', 'pling', 'weixin', 'dindon', 'click'],
            'visitor_snd_recv_which' => ['water', 'pling', 'weixin', 'dindon', 'click'],
            'visitor_snd_send_which' => ['water', 'pling', 'weixin', 'dindon', 'click'],
        ];
        foreach ($enums as $k => $allow) {
            if (array_key_exists($k, $data)) {
                $v = in_array($data[$k], $allow, true) ? $data[$k] : $allow[0];
                ts_set($tid, $k, $v);
            }
        }
        // 码设置专用键
        if (array_key_exists('me_avatar', $data)) ts_set($tid, 'me_avatar', mb_substr(trim((string)$data['me_avatar']), 0, 500));
        foreach (['code_nickname_on', 'code_support_on'] as $bk) {
            if (array_key_exists($bk, $data)) ts_set($tid, $bk, $data[$bk] ? '1' : '0');
        }
        // 客服码卡片样式（v2.30）：码点/码眼/主色/上下文案/中心 Logo 与大小
        foreach (['code_dot', 'code_eye', 'code_brand', 'code_logo', 'code_logo_size', 'code_top_text', 'code_bottom_text'] as $k) {
            if (array_key_exists($k, $data)) ts_set($tid, $k, mb_substr(trim((string)$data[$k]), 0, 64));
        }
        if (array_key_exists('code_custom_logo', $data)) ts_set($tid, 'code_custom_logo', mb_substr(trim((string)$data['code_custom_logo']), 0, 500));
        if (array_key_exists('code_color_dots', $data)) ts_set($tid, 'code_color_dots', $data['code_color_dots'] ? '1' : '0');
        // 会话管理（v2.25）：写租户级字段（非 ts 键值）
        $tidInt = (int)$tid;
        foreach (['closed_keep_days', 'max_sessions'] as $col) {
            if (array_key_exists($col, $data)) {
                $v = max(0, min(3650, (int)$data[$col]));
                db()->prepare("UPDATE tenants SET $col = ? WHERE id = ?")->execute([$v, $tidInt]);
            }
        }
        json_out(['ok' => true]);
    }

    json_error('方法不允许', 405);
}

/* ---------- 登录记录（原「登录设备」做真：真实登录历史） ---------- */
function handle_tenant_login_logs(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_tenant();
    $st = db()->prepare('SELECT id, role, ident, ip, ua, created_at FROM tenant_login_logs WHERE tenant_id = ? ORDER BY id DESC LIMIT 20');
    $st->execute([$ctx['id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['ua_short'] = mb_substr($r['ua'], 0, 80);
        $r['device'] = parse_ua($r['ua'])['device'];
        $r['created_at'] = dt_to_iso($r['created_at']);
        unset($r['ua']);
    }
    unset($r);
    json_out($rows);
}

/* ---------- 数据清理（原「清理数据」做真：真实删除） ---------- */
function handle_tenant_cleanup(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_tenant();
    $tid = $ctx['id'];
    $scope = (string)input('scope', 'sessions');   // sessions|blacklist
    $days = (int)input('days', -1);                // -1=全部；>0=N天前
    $pdo = db();

    if ($scope === 'blacklist') {
        $st = $pdo->prepare('DELETE FROM ip_blacklist WHERE tenant_id = ?');
        $st->execute([$tid]);
        json_out(['ok' => true, 'deleted' => $st->rowCount(), 'scope' => 'blacklist']);
    }

    // 会话清理：删 N 天前（或全部）的会话及其消息/联系人/欢迎语记录
    if ($days < 0) {
        $cond = ''; $params = [$tid];
    } else {
        $cond = ' AND last_time < ?'; $params = [$tid, now_dt(time() - $days * 86400)];
    }
    $st = $pdo->prepare("SELECT id FROM v_sessions WHERE tenant_id = ?$cond");
    $st->execute($params);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) json_out(['ok' => true, 'deleted' => 0, 'scope' => 'sessions']);
    $idList = implode(',', array_map('intval', $ids));
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM messages WHERE tenant_id = $tid AND session_id IN ($idList)");
        $pdo->exec("DELETE FROM contacts WHERE tenant_id = $tid AND session_id IN ($idList)");
        $pdo->exec("DELETE FROM welcome_sent WHERE tenant_id = $tid AND session_id IN ($idList)");
        $n = $pdo->exec("DELETE FROM v_sessions WHERE tenant_id = $tid AND id IN ($idList)");
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        json_error('清理失败：' . $e->getMessage(), 500);
    }
    json_out(['ok' => true, 'deleted' => (int)$n, 'scope' => 'sessions', 'label' => $days < 0 ? '全部' : "{$days}天前"]);
}

/* ---------- 访客资源（原「访客管理」做真：按访客聚合） ---------- */
function handle_tenant_visitors(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_tenant();
    $tid = $ctx['id'];
    $pdo = db();

    $rows = $pdo->prepare("SELECT s.visitor_token AS tok,
        SUBSTRING_INDEX(GROUP_CONCAT(s.visitor_name ORDER BY s.last_time DESC SEPARATOR '|||'), '|||', 1) AS name,
        SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(s.ip,'') ORDER BY s.last_time DESC SEPARATOR '|||'), '|||', 1) AS ip,
        SUBSTRING_INDEX(GROUP_CONCAT(s.from_url ORDER BY s.last_time DESC SEPARATOR '|||'), '|||', 1) AS from_url,
        COUNT(*) AS session_count,
        MAX(s.last_time) AS last_active,
        MIN(s.created_at) AS first_seen,
        SUM(s.unread) AS unread_total
        FROM v_sessions s
        WHERE s.tenant_id = ? AND s.visitor_token != ''
        GROUP BY s.visitor_token
        ORDER BY last_active DESC
        LIMIT 500");
    $rows->execute([$tid]);
    $out = $rows->fetchAll();

    // 批量补最近会话的联系方式（手机/微信）与消息数
    $qMobile = $pdo->prepare("SELECT value FROM contacts WHERE session_id = ? AND type='mobile' LIMIT 1");
    $qWechat = $pdo->prepare("SELECT value FROM contacts WHERE session_id = ? AND type='wechat' LIMIT 1");
    $qMsgCnt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE session_id = ?');
    $qLastSid = $pdo->prepare('SELECT id FROM v_sessions WHERE tenant_id = ? AND visitor_token = ? ORDER BY last_time DESC LIMIT 1');
    foreach ($out as &$v) {
        $qLastSid->execute([$tid, $v['tok']]);
        $lastSid = (int)$qLastSid->fetchColumn();
        $v['last_session_id'] = $lastSid;
        $v['mobile'] = ''; $v['wechat'] = ''; $v['msg_count'] = 0;
        if ($lastSid) {
            $qMobile->execute([$lastSid]); $v['mobile'] = (string)$qMobile->fetchColumn();
            $qWechat->execute([$lastSid]); $v['wechat'] = (string)$qWechat->fetchColumn();
            $qMsgCnt->execute([$lastSid]); $v['msg_count'] = (int)$qMsgCnt->fetchColumn();
        }
        $v['session_count'] = (int)$v['session_count'];
        $v['unread_total'] = (int)$v['unread_total'];
        $v['last_active'] = dt_to_iso($v['last_active']);
        $v['first_seen'] = dt_to_iso($v['first_seen']);
    }
    unset($v);
    json_out($out);
}

/* ---------- 链接历史（管理码：含已作废的历史记录） ---------- */
function handle_tenant_link_history(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_tenant();
    $st = db()->prepare('SELECT id, subdomain, link, active, created_at FROM domain_links WHERE tenant_id = ? ORDER BY id DESC LIMIT 50');
    $st->execute([$ctx['id']]);
    $links = $st->fetchAll();
    $st = $db2 = db()->prepare('SELECT id, code, link, created_at FROM service_codes WHERE tenant_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$ctx['id']]);
    $codes = $st->fetchAll();
    foreach ($links as &$r) { $r['active'] = (bool)$r['active']; $r['created_at'] = dt_to_iso($r['created_at']); }
    unset($r);
    foreach ($codes as &$r) { $r['created_at'] = dt_to_iso($r['created_at']); }
    unset($r);
    json_out(['links' => $links, 'codes' => $codes]);
}

/* ---------- 作废链接（专属链接历史） ---------- */
function handle_tenant_link_disable(string $method, array $m): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_tenant();
    db()->prepare('UPDATE domain_links SET active = 0 WHERE id = ? AND tenant_id = ?')->execute([(int)$m[1], $ctx['id']]);
    json_out(['ok' => true]);
}

/* ---------- 入口链接汇总 ---------- */

function handle_tenant_links(string $method): void
{
    if ($method !== 'GET') { json_error('方法不允许', 405); return; }
    $ctx = require_tenant();
    $tid = $ctx['id'];
    $pdo = db();

    $root = kv_get('root_domain', '');
    $st = $pdo->prepare('SELECT link, subdomain, created_at FROM domain_links WHERE tenant_id = ? AND active = 1 ORDER BY id DESC LIMIT 1');
    $st->execute([$tid]);
    $dl = $st->fetch();

    $st = $pdo->prepare('SELECT code, link, created_at FROM service_codes WHERE tenant_id = ? ORDER BY id DESC');
    $st->execute([$tid]);
    $codes = $st->fetchAll();

    // 卡密直链：本站直接挂参数
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $direct = $host ? "$scheme://$host/visitor.html?t=" . $ctx['kami'] : '';

    json_out([
        'root_domain' => $root,
        'domain_link' => $dl ?: null,
        'direct_link' => $direct,
        'codes' => $codes,
    ]);
}

/* ---------- 客服码状态检测（第三方「域名拦截检测」接口，按次计费） ---------- */

/** 检测接口配置：kv_store 可覆盖（wxcheck_user / wxcheck_key / wxcheck_url / wxcheck_ttl） */
function wxcheck_cfg(): array
{
    return [
        // 平台级开关：wxcheck_on=0 时只回报「未开通」，不显示绿色的「正常接待」（接口不可信时避免假绿）
        'on'   => kv_get('wxcheck_on', '0') === '1',
        'user' => kv_get('wxcheck_user', ''),
        'key'  => kv_get('wxcheck_key', ''),
        'url'  => rtrim(kv_get('wxcheck_url', 'https://api.uouin.com/app'), '/'),
        'ttl'  => max(60, (int)kv_get('wxcheck_ttl', '300')),
    ];
}

/** 当前生效的客服码链接：优先域名池子域，其次服务码 */
function tenant_current_link(int $tid): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT link FROM domain_links WHERE tenant_id = ? AND active = 1 ORDER BY id DESC LIMIT 1');
    $st->execute([$tid]);
    $link = (string)($st->fetchColumn() ?: '');
    if ($link === '') {
        $st = $pdo->prepare('SELECT link FROM service_codes WHERE tenant_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$tid]);
        $link = (string)($st->fetchColumn() ?: '');
    }
    return $link;
}

/**
 * 检测当前客服码在微信/QQ 是否被拦截（俗称「红」）。
 * 结果落 tenant_settings（code_check_*），同链接同渠道 ttl 秒内直接复用，避免按次扣费。
 */
function handle_tenant_code_check(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_tenant();
    $tid = (int)$ctx['id'];
    $data = body();
    $force = !empty($data['force']);
    $channel = (string)($data['channel'] ?? 'wx');
    if (!in_array($channel, ['wx', 'qq'], true)) $channel = 'wx';
    $chName = $channel === 'qq' ? 'QQ' : '微信';

    $link = tenant_current_link($tid);
    if ($link === '') {
        json_out(['state' => 'none', 'text' => '尚未生成客服码', 'tag' => '未生成', 'at' => '', 'msg' => '', 'channel' => $channel]);
        return;
    }

    $cfg = wxcheck_cfg();
    $lastLink = ts_get($tid, 'code_check_link', '');
    $lastCh   = ts_get($tid, 'code_check_channel', '');
    $lastTs   = (int)ts_get($tid, 'code_check_at_ts', '0');
    if (!$cfg['on']) {
        ts_set($tid, 'code_check_state', 'off');
        ts_set($tid, 'code_check_text', $chName . '检测未开通');
        ts_set($tid, 'code_check_tag', '未开通');
        ts_set($tid, 'code_check_msg', '平台未开通拦截检测，请联系管理员');
        ts_set($tid, 'code_check_at', now_dt());
        ts_set($tid, 'code_check_at_ts', (string)time());
        ts_set($tid, 'code_check_link', $link);
        ts_set($tid, 'code_check_channel', $channel);
        json_out(['state' => 'off', 'text' => $chName . '检测未开通', 'tag' => '未开通', 'at' => now_dt(),
            'msg' => '平台未开通拦截检测，请联系管理员', 'channel' => $channel]);
        return;
    }
    if (!$force && $lastLink === $link && $lastCh === $channel && (time() - $lastTs) < $cfg['ttl']) {
        json_out(['state' => ts_get($tid, 'code_check_state', 'idle'), 'text' => ts_get($tid, 'code_check_text', '未检测'),
                  'tag'   => ts_get($tid, 'code_check_tag', '未检测'),   'at'   => ts_get($tid, 'code_check_at', ''),
                  'msg'   => ts_get($tid, 'code_check_msg', ''), 'channel' => $channel, 'cached' => true]);
        return;
    }

    $state = 'err'; $text = '检测失败'; $tag = '异常'; $msg = '';
    if ($cfg['user'] === '' || $cfg['key'] === '') {
        $state = 'off'; $text = $chName . '检测未开通'; $tag = '未开通'; $msg = '平台未配置检测接口账号';
    } else {
        $api = $cfg['url'] . '/' . $channel . '?username=' . urlencode($cfg['user'])
             . '&key=' . urlencode($cfg['key']) . '&url=' . urlencode($link);
        $resp = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($api);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'kfxt/2.30']);
            $resp = (string)curl_exec($ch);
            curl_close($ch);
        } else {
            $resp = (string)@file_get_contents($api);
        }
        $j = json_decode($resp, true);
        if (!is_array($j) || !isset($j['code'])) {
            $state = 'err'; $text = '检测失败'; $tag = '异常'; $msg = '检测接口无响应';
        } else {
            $c = (int)$j['code'];
            $m = trim((string)($j['msg'] ?? ''));
            if ($c === 1001)     { $state = 'ok';  $text = $chName . '可正常打开'; $tag = '正常'; $msg = ($m !== '' ? $m : ('该链接在' . $chName . '内未被拦截')); }
            elseif ($c === 1002) { $state = 'red'; $text = '已被' . $chName . '拦截'; $tag = '红'; $msg = ($m !== '' ? $m : ('该链接在' . $chName . '内已被拦截，建议换码/换域名')); }
            elseif ($c === -206) { $state = 'off'; $text = $chName . '检测未开通'; $tag = '未开通'; $msg = $m; }
            else                 { $state = 'err'; $text = '检测异常';   $tag = '异常';   $msg = ($c . ' ' . $m); }
        }
    }
    $at = now_dt();
    ts_set($tid, 'code_check_state', $state);
    ts_set($tid, 'code_check_text', $text);
    ts_set($tid, 'code_check_tag', $tag);
    ts_set($tid, 'code_check_msg', mb_substr($msg, 0, 300));
    ts_set($tid, 'code_check_at', $at);
    ts_set($tid, 'code_check_at_ts', (string)time());
    ts_set($tid, 'code_check_link', $link);
    ts_set($tid, 'code_check_channel', $channel);
    json_out(['state' => $state, 'text' => $text, 'tag' => $tag, 'at' => $at, 'msg' => $msg, 'channel' => $channel]);
}
