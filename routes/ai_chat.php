<?php
/**
 * ai_chat.php — 「AI 聊天」（只对白名单租户开放）
 *
 * 流程照 Desktop\kf真人引导流程_实录.md 里真人客服的实录：
 *   欢迎语 → 问金额 → 微信操作说明 → 发户号(户号/地区/金额) → ⚠️风险预告 → 催单
 *   → 交不了费：先教地区写法，再不行换户号 → 补充值(450/100/2×50) → 要缴费详情截图核实 → 收尾
 *
 * 户号**不从任务大厅自动拿**：客户报金额 → 本模块往 TG 群里问「要个户号」，
 * 人工在群里回户号 → 机器人把户号发给客户；客户说充完了 → 群里问「到账没」→
 * 群里回「到了」→ 机器人回客户收尾。群里那条回复由 Telegram 机器人
 * POST /api/internal/ai-group 送回来（见 handle_internal_ai_group）。
 *
 * 死红线：出站文本里凡出现 8~17 位连续数字，必须命中「这一单的户号」白名单，
 *         否则拦下不发（模型自己编户号是踩过的真事故）。
 */

/** 白名单：哪些卡密能用 AI 聊天（另外可用 kv 键 ai_chat_kami_extra 追加以逗号分隔的卡密） */
function ai_chat_kami_allow(): array
{
    $list = ['CCWA8SGVJQ'];
    $extra = kv_get('ai_chat_kami_extra', '');
    if ($extra !== '') {
        foreach (explode(',', $extra) as $k) {
            $k = trim($k);
            if ($k !== '') $list[] = $k;
        }
    }
    return array_values(array_unique($list));
}

/** 这个租户有没有资格（按卡密判定：别的卡看不到这个开关、也不会触发 AI） */
function ai_chat_entitled(int $tenantId): bool
{
    try {
        $st = db()->prepare('SELECT kami FROM tenants WHERE id = ?');
        $st->execute([$tenantId]);
        $kami = (string)$st->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
    return $kami !== '' && in_array($kami, ai_chat_kami_allow(), true);
}

/** 用户可以在设置页开关（默认关） */
function ai_chat_on(int $tenantId): bool
{
    return ts_get($tenantId, 'ai_chat_on', '0') === '1' && ai_chat_entitled($tenantId);
}

function ai_chat_cfg(int $tenantId): array
{
    return [
        'base'    => rtrim(ts_get($tenantId, 'ai_base_url', 'https://api.b.ai'), '/'),
        'key'     => ts_get($tenantId, 'ai_key', ''),
        'model'   => ts_get($tenantId, 'ai_model', 'deepseek-v4.1-flash'),
        'temp'    => (float)ts_get($tenantId, 'ai_temp', '1.0'),
        'tg_token' => ts_get($tenantId, 'ai_tg_token', ''),
        'tg_chat' => ts_get($tenantId, 'ai_tg_chat', ''),
        'secret'  => ts_get($tenantId, 'ai_bridge_secret', ''),
        'cap'     => (float)ts_get($tenantId, 'ai_max_per_pay', '450'),
        'retry'   => (float)ts_get($tenantId, 'ai_retry_pay', '100'),
        'floor'   => (float)ts_get($tenantId, 'ai_min_pay', '50'),
        'sign'    => ts_get($tenantId, 'ai_sign', ''),
    ];
}

function ai_chat_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS ai_chat_state (
            session_id INT UNSIGNED NOT NULL PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            stage VARCHAR(16) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NULL,
            account VARCHAR(32) NOT NULL DEFAULT '',
            region VARCHAR(64) NOT NULL DEFAULT '',
            pay VARCHAR(16) NOT NULL DEFAULT '',
            ask_at DATETIME NULL,
            ask_kind VARCHAR(16) NOT NULL DEFAULT '',
            region_taught TINYINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_stage (tenant_id, stage, ask_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
    }
}

function ai_chat_state(int $sessionId): array
{
    ai_chat_ensure_table();
    try {
        $st = db()->prepare('SELECT * FROM ai_chat_state WHERE session_id = ?');
        $st->execute([$sessionId]);
        $r = $st->fetch();
        if ($r) return $r;
    } catch (\Throwable $e) {
    }
    return ['session_id' => $sessionId, 'stage' => '', 'amount' => null, 'account' => '',
        'region' => '', 'pay' => '', 'ask_at' => null, 'ask_kind' => '', 'region_taught' => 0];
}

function ai_chat_state_set(int $tenantId, int $sessionId, array $patch): void
{
    ai_chat_ensure_table();
    $cur = ai_chat_state($sessionId);
    $row = array_merge($cur, $patch);
    try {
        db()->prepare('INSERT INTO ai_chat_state (session_id, tenant_id, stage, amount, account, region, pay, ask_at, ask_kind, region_taught)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE stage = VALUES(stage), amount = VALUES(amount), account = VALUES(account),
              region = VALUES(region), pay = VALUES(pay), ask_at = VALUES(ask_at), ask_kind = VALUES(ask_kind),
              region_taught = VALUES(region_taught)')
            ->execute([$sessionId, $tenantId, (string)$row['stage'], $row['amount'], (string)$row['account'],
                (string)$row['region'], (string)$row['pay'], $row['ask_at'], (string)$row['ask_kind'], (int)$row['region_taught']]);
    } catch (\Throwable $e) {
    }
}

/** 数字提取：8~17 位连续数字（允许中间有空格/横杠，先归一再数） */
function ai_chat_digits(string $text): array
{
    $flat = preg_replace('/[\s\-—–_]+/u', '', $text);
    preg_match_all('/\d{8,17}/', (string)$flat, $m);
    return $m[0] ?? [];
}

/** 金额识别（宽松）：2~5 位整数，排除户号那种长数字串 */
function ai_chat_amount(string $text): ?float
{
    if (preg_match('/(?:充|交|缴|冲|转|要|报|是|金额|面额|来|弄)?\s*(\d{2,5})(?:\s*(?:元|块|块钱))?/', $text, $m)) {
        $v = (float)$m[1];
        if ($v >= 10 && $v <= 200000) {
            // 整句有明显长数字串（户号）时不认金额
            if (ai_chat_digits($text)) return null;
            return $v;
        }
    }
    return null;
}

/** 「充完了」类信号 */
function ai_chat_done(string $text): bool
{
    foreach (['充完', '充好', '交了', '缴了', '付了', '转过去', '过去了', '弄好了', '搞定', '已完成', '好了', 'ok', 'OK', 'OK了', '到了'] as $k) {
        if (mb_stripos($text, $k) !== false) return true;
    }
    return false;
}

/** 「充不了」类信号 */
function ai_chat_stuck(string $text): bool
{
    foreach (['交不了', '缴不了', '充不了', '转不了', '转不过', '付不了', '不行', '失败', '限额', '了解详情', '不能支付', '交不上', '缴不上'] as $k) {
        if (mb_stripos($text, $k) !== false) return true;
    }
    return false;
}

/** 出站号码白名单校验：8~17 位数字必须命中允许的号码 */
function ai_chat_guard(string $text, array $allowed): ?string
{
    $allowed = array_values(array_filter(array_map('strval', $allowed), fn($v) => $v !== ''));
    foreach (ai_chat_digits($text) as $n) {
        if (!in_array($n, $allowed, true)) return null;
    }
    return $text;
}

/** 以客服身份插一条消息给访客（返回给前端的结构） */
function ai_chat_insert_agent(int $tenantId, int $sessionId, string $name, string $text): array
{
    $pdo = db();
    $now = now_dt();
    $pdo->prepare("INSERT INTO messages (tenant_id, session_id, from_type, sender_name, text, msg_type, created_at)
        VALUES (?,?,'agent',?,?,?,'text',?)")->execute([$tenantId, $sessionId, $name, $text, $now]);
    $mid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE v_sessions SET last_msg = ?, last_time = ?, unread = 0 WHERE id = ?')
        ->execute([mb_substr($text, 0, 60), $now, $sessionId]);
    return ['id' => $mid, 'from' => 'agent', 'sender_name' => $name, 'text' => $text,
        'time' => dt_to_iso($now), 'session_id' => $sessionId, 'msg_type' => 'text', 'attachments' => [], 'ai' => true];
}

/** 往群里发一条（复用同一个 Telegram 机器人） */
function ai_chat_tg_send(int $tenantId, string $text): bool
{
    $cfg = ai_chat_cfg($tenantId);
    if ($cfg['tg_token'] === '' || $cfg['tg_chat'] === '') return false;
    $url = 'https://api.telegram.org/bot' . $cfg['tg_token'] . '/sendMessage';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => $cfg['tg_chat'], 'text' => mb_substr($text, 0, 3500),
            'disable_web_page_preview' => 'true'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/** 最近对话（给模型当上下文） */
function ai_chat_history(int $sessionId, int $limit = 16): array
{
    try {
        $st = db()->prepare("SELECT from_type, sender_name, text, msg_type FROM messages
            WHERE session_id = ? AND recalled_at IS NULL ORDER BY id DESC LIMIT ?");
        $st->bindValue(1, $sessionId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = array_reverse($st->fetchAll());
    } catch (\Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $who = $r['from_type'] === 'visitor' ? '客户' : ($r['from_type'] === 'agent' ? '客服' : '系统');
        $t = trim((string)$r['text']);
        if ($t === '') $t = ($r['msg_type'] === 'image' ? '[图片]' : ($r['msg_type'] === 'audio' ? '[语音]' : '[附件]'));
        $out[] = $who . '：' . $t;
    }
    return $out;
}

/** 系统提示词：照真人实录写 */
function ai_chat_prompt(int $tenantId, array $state, array $history, string $visitorText): string
{
    $cfg = ai_chat_cfg($tenantId);
    $sign = $cfg['sign'] !== '' ? $cfg['sign'] : '客服';
    $cap = rtrim(rtrim(number_format($cfg['cap'], 2, '.', ''), '0'), '.');
    $retry = rtrim(rtrim(number_format($cfg['retry'], 2, '.', ''), '0'), '.');
    $floor = rtrim(rtrim(number_format($cfg['floor'], 2, '.', ''), '0'), '.');

    $lines = [];
    $lines[] = "你是「{$sign}」，做话费/电费代充值这行的客服，正在手机上和客户一对一聊天。";
    $lines[] = "说话口语化、自然、短句连发，像真人打字。称呼用「亲」「亲爱的」「亲亲」，多说「稍等」「辛苦您」。";
    $lines[] = "【这一单的固定流程】照下面走，别跳步：";
    $lines[] = "1. 先问清楚要办多少金额、资金在微信还是支付宝；";
    $lines[] = "2. 客户报了金额后，先说这段（可稍微口语化，但别改数字）：";
    $lines[] = "   「打开微信，按照下图教程选择我们提供的地区和缴费单位（默认国家电网）填入户号以及金额进行充值，付款前请截图给我核实户号以及金额，否则多充或者缴错将不计入结算账」";
    $lines[] = "3. 紧接着把【我方给的户号】按三段式发给他（户号 / 地区 / 金额），一个户号一条消息；";
    $lines[] = "4. 发完号紧跟一句：「⚠️如出现不能支付 马上回来联系我！」";
    $lines[] = "5. 客户没动静，过一会儿问一句「在操作了吗 亲爱的」；";
    $lines[] = "6. 客户说交不了费/转不过去：**先教他改地区写法**（例如「这里改成江苏省」「江苏省苏州市呢 亲爱的」）；";
    $lines[] = "   教过一次还不行，才说「给您换户号」「稍等」，然后等群里给新户号；";
    $lines[] = "7. 客户说充好了：让他「辛苦您这边按以下步骤提供一下缴费详情截图方便我这边进行核实一下」，然后等群里确认；";
    $lines[] = "8. 群里确认到账后收尾：「已结算完毕 请勿私下充值❗️」";
    $lines[] = "【单笔限额】单笔最多 {$cap}。要充的比 {$cap} 多就让他分几笔、**同一个户号连着缴**；"
        . "{$cap} 那笔充不了就改成一笔 {$retry}；{$retry} 也不行就让他充 2 次 {$floor}。";
    $lines[] = "【绝对不许】自己编户号、编地区、编到账。户号只能一字不改地照抄【我方给的户号】里的数字；"
        . "还没拿到号就说「稍等，我这边给你安排户号」，不许拿别的数字糊弄。";
    $lines[] = "【这一单现在的状态】";
    $lines[] = "- 客户要办的金额：" . ($state['amount'] ? rtrim(rtrim(number_format((float)$state['amount'], 2, '.', ''), '0'), '.') : '还没说');
    $lines[] = "- 已经发给他的户号：" . ($state['account'] !== '' ? $state['account'] . '（地区 ' . $state['region'] . '，让他充 ' . $state['pay'] . '）' : '还没发（还在等群里给号）');
    if ($state['account'] !== '') {
        $lines[] = "【我方给的户号】户号 {$state['account']}  地区 {$state['region']}  充值 {$state['pay']}（只能原样使用）";
    } else {
        $lines[] = "【我方给的户号】（还没有，别自己编，回一句稍等即可）";
    }
    if ($history) {
        $lines[] = "【最近聊天】";
        foreach ($history as $h) $lines[] = $h;
    }
    $lines[] = "【客户刚说】{$visitorText}";
    $lines[] = "现在直接用客服口吻回复客户（可以拆成 1~3 条短消息，用换行分开）。只输出要说的话，不要解释、不要加引号、不要提你是 AI。";
    return implode("\n", $lines);
}

/** 调模型（OpenAI 兼容） */
function ai_chat_llm(int $tenantId, string $system, array $history, string $userText): ?string
{
    $cfg = ai_chat_cfg($tenantId);
    if ($cfg['key'] === '') return null;
    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($history as $h) {
        // 历史行「客户：xxx」/「客服：xxx」
        if (mb_strpos($h, '客户：') === 0) $messages[] = ['role' => 'user', 'content' => mb_substr($h, 3)];
        elseif (mb_strpos($h, '客服：') === 0) $messages[] = ['role' => 'assistant', 'content' => mb_substr($h, 3)];
    }
    $messages[] = ['role' => 'user', 'content' => $userText];
    $payload = json_encode([
        'model' => $cfg['model'],
        'messages' => $messages,
        'temperature' => min(1.5, max(0.0, $cfg['temp'])),
        'max_tokens' => 500,
        'stream' => false,
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($cfg['base'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        ai_chat_log($tenantId, "模型调用失败 HTTP {$code} " . mb_substr($err !== '' ? $err : $body, 0, 200));
        return null;
    }
    $j = json_decode($body, true);
    $text = '';
    if (is_array($j)) $text = (string)($j['choices'][0]['message']['content'] ?? '');
    $text = trim($text);
    return $text !== '' ? $text : null;
}

function ai_chat_log(int $tenantId, string $msg): void
{
    try {
        $dir = rtrim((string)(config()['data_dir'] ?? __DIR__ . '/../data'), '/');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/ai_chat.log', '[' . now_dt() . "] t{$tenantId} {$msg}\n", FILE_APPEND);
    } catch (\Throwable $e) {
    }
}

/** 按客户消息决定「要不要往群里要东西」，并返回要发的群消息 */
function ai_chat_group_ask(int $tenantId, int $sessionId, array $state, string $visitorText, ?float $amount): ?array
{
    $age = $state['ask_at'] ? (time() - strtotime((string)$state['ask_at'])) : 999999;
    if ($amount !== null && $state['account'] === '') {
        if ($state['ask_kind'] === 'number' && $age < 600) return null;   // 10 分钟内不重复问号
        $txt = '【AI客服】客户报金' . rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.')
            . ' 要个户号（回：户号 地区 金额，或直接发户号） #S' . $sessionId;
        return ['kind' => 'number', 'text' => $txt];
    }
    if ($state['account'] !== '' && $state['ask_kind'] !== 'verify' && ai_chat_done($visitorText)) {
        $txt = '【AI客服】客户说充完了 户号 ' . $state['account'] . ' 到账没？（回「到了」或「没到」） #S' . $sessionId;
        return ['kind' => 'verify', 'text' => $txt];
    }
    if ($state['account'] !== '' && ai_chat_stuck($visitorText)) {
        if ((int)$state['region_taught'] === 1) {
            if ($state['ask_kind'] === 'renew' && $age < 600) return null;
            $txt = '【AI客服】户号 ' . $state['account'] . ' 客户缴不了（地区写法也教过了），给换个户号 #S' . $sessionId;
            return ['kind' => 'renew', 'text' => $txt];
        }
    }
    return null;
}

/**
 * 主入口：访客发来一条消息时调用（visitor.php）。
 * 返回 null = 不处理（没开/没资格/失败）；返回数组 = 要发给访客的客服消息（复用 auto_reply 字段）。
 */
function ai_chat_try_reply(int $tenantId, int $sessionId, string $text, array $session = []): ?array
{
    if (!ai_chat_on($tenantId)) return null;
    if ($text === '') return null;
    $cfg = ai_chat_cfg($tenantId);
    $state = ai_chat_state($sessionId);
    $amount = ai_chat_amount($text);
    if ($amount !== null && $state['account'] === '') {
        $state['amount'] = $amount;
        $state['stage'] = 'need_number';
    }

    // 要不要往群里要东西
    $ask = ai_chat_group_ask($tenantId, $sessionId, $state, $text, $amount);
    $askSent = false;
    if ($ask) {
        $askSent = ai_chat_tg_send($tenantId, $ask['text']);
        ai_chat_log($tenantId, 's' . $sessionId . ' 问群(' . $ask['kind'] . ') ' . ($askSent ? '已发' : '发送失败'));
    }

    $patch = ['amount' => $state['amount'], 'stage' => $state['stage']];
    if ($ask) { $patch['ask_kind'] = $ask['kind']; $patch['ask_at'] = now_dt(); }
    if ($state['account'] !== '' && $ask && $ask['kind'] !== 'verify' && ai_chat_stuck($text)) $patch['region_taught'] = 1;
    if ($state['account'] !== '' && ai_chat_stuck($text) && (int)$state['region_taught'] === 0) $patch['region_taught'] = 1;
    ai_chat_state_set($tenantId, $sessionId, $patch);

    // 模型回复
    $history = ai_chat_history($sessionId, 14);
    if ($history) array_pop($history);   // 最后一条就是客户刚说的这句
    $system = ai_chat_prompt($tenantId, $state, $history, $text);
    $reply = ai_chat_llm($tenantId, $system, $history, $text);
    if ($reply === null) {
        // 模型挂了也要有人味：按当前状态给一句兜底
        $reply = $state['account'] === '' ? '稍等 亲爱的' : '好的 我这边看一下 稍等';
        ai_chat_log($tenantId, 's' . $sessionId . ' 模型没回，用兜底话术');
    }
    // 死红线：号码白名单
    $allowed = $state['account'] !== '' ? [$state['account']] : [];
    $guarded = ai_chat_guard($reply, $allowed);
    if ($guarded === null) {
        ai_chat_log($tenantId, 's' . $sessionId . ' 回复里出现非白名单号码，已拦下：' . mb_substr($reply, 0, 80));
        $reply = $state['account'] === '' ? '稍等 亲爱的' : '好的 我这边看一下 稍等';
        if (ai_chat_guard($reply, $allowed) === null) return null;
    } else {
        $reply = $guarded;
    }
    $reply = mb_substr($reply, 0, 900);
    $name = $cfg['sign'] !== '' ? $cfg['sign'] : (string)($session['agent_name'] ?? '客服');
    return ai_chat_insert_agent($tenantId, $sessionId, $name, $reply);
}

/** 从群里那条回复里解析：户号 / 地区 / 金额 */
function ai_chat_parse_pick(string $text): array
{
    $nums = ai_chat_digits($text);
    $account = $nums ? (string)$nums[0] : '';
    $region = '';
    if (preg_match('/(?:地区|地址|城市|省份)[：:\s]*([^\s，,。；;]{2,20})/u', $text, $m)) $region = trim($m[1]);
    if ($region === '' && preg_match('/([\x{4e00}-\x{9fa5}]{2,8}(?:省|市|区|县|盟|州|旗))/u', $text, $m)) $region = $m[1];
    $pay = '';
    if (preg_match('/(?:充值|充|金额|缴|付)[：:\s]*(\d{2,6})(?:\.\d{1,2})?/u', $text, $m)) $pay = $m[1];
    if ($pay === '' && $nums && count($nums) > 1) $pay = (string)$nums[1];
    return ['account' => $account, 'region' => $region, 'pay' => $pay];
}

/**
 * POST /api/internal/ai-group
 * 群里那条回复由 Telegram 机器人转过来（body: secret / chat_id / text / from_name）
 * 按 secret 找到是哪个租户，再把内容配给等待中的会话。
 */
function handle_internal_ai_group(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $secret = trim((string)input('secret', ''));
    $text = trim((string)input('text', ''));
    $chatId = trim((string)input('chat_id', ''));
    if ($secret === '' || $text === '') json_error('参数缺失');

    $pdo = db();
    $st = $pdo->prepare("SELECT tenant_id, sval FROM tenant_settings WHERE skey = 'ai_bridge_secret' AND sval = ? LIMIT 1");
    $st->execute([$secret]);
    $row = $st->fetch();
    if (!$row) json_error('无权调用', 403);
    $tid = (int)$row['tenant_id'];
    if (!ai_chat_on($tid)) json_error('未开启', 403);

    $cfg = ai_chat_cfg($tid);
    if ($cfg['tg_chat'] !== '' && $chatId !== '' && $chatId !== $cfg['tg_chat']) json_error('群不匹配', 403);

    ai_chat_ensure_table();
    // 目标会话：优先按 #S<id> 标记；没有标记就挑「最近在等答案」的那个
    $sid = 0;
    if (preg_match('/#S(\d+)/', $text, $m)) $sid = (int)$m[1];
    $text = trim(preg_replace('/#S\d+/', '', $text));
    if ($sid > 0) {
        $chk = $pdo->prepare('SELECT * FROM ai_chat_state WHERE session_id = ? AND tenant_id = ?');
        $chk->execute([$sid, $tid]);
        $state = $chk->fetch();
        if (!$state) $sid = 0;
    }
    if ($sid === 0) {
        $q = $pdo->prepare("SELECT * FROM ai_chat_state WHERE tenant_id = ? AND stage IN ('need_number','verifying')
            AND ask_at IS NOT NULL ORDER BY ask_at ASC LIMIT 1");
        $q->execute([$tid]);
        $state = $q->fetch();
        if ($state) $sid = (int)$state['session_id'];
    }
    if (!$state || $sid === 0) {
        ai_chat_log($tid, '群里来消息但没找到等待中的会话：' . mb_substr($text, 0, 80));
        json_out(['ok' => true, 'matched' => false]);
        return;
    }

    // 判定：这一单在等号 还是 在等核实
    $waitVerify = (string)$state['ask_kind'] === 'verify';
    if (!$waitVerify) {
        $pick = ai_chat_parse_pick($text);
        if ($pick['account'] === '') {
            // 群里回的不是户号（可能是"到了/没到"或别的话）：只有等核实才处理
            json_out(['ok' => true, 'matched' => false, 'why' => '没解析到户号']);
            return;
        }
        $allowedOld = (string)$state['account'];
        ai_chat_state_set($tid, $sid, ['account' => $pick['account'], 'region' => $pick['region'],
            'pay' => $pick['pay'] !== '' ? $pick['pay'] : ((string)$state['amount'] !== '' ? (string)(float)$state['amount'] : ''),
            'stage' => 'sent_number', 'ask_kind' => '', 'region_taught' => 0]);
        $line = '户号：' . $pick['account'];
        if ($pick['region'] !== '') $line .= '  地区：' . $pick['region'];
        if ($pick['pay'] !== '') $line .= ' 充值' . $pick['pay'];
        // 群里给的原话也带上（可能还有别的交代），但号码白名单只允许这个新号
        $extra = $text;
        foreach (ai_chat_digits($extra) as $n) {
            if ($n !== $pick['account'] && $n !== $allowedOld) $extra = str_replace($n, '', $extra);
        }
        $msg = $line;
        $cleanExtra = trim(preg_replace('/\s+/u', ' ', $extra));
        if ($cleanExtra !== '' && mb_strpos($cleanExtra, $pick['account']) === false && mb_strlen($cleanExtra) <= 200) {
            $msg .= "\n" . $cleanExtra;
        }
        ai_chat_insert_agent($tid, $sid, $cfg['sign'] !== '' ? $cfg['sign'] : '客服', mb_substr($msg, 0, 600));
        ai_chat_tg_send($tid, '已发给客户：' . $line . '（会话 #S' . $sid . '）');
        ai_chat_log($tid, "s{$sid} 群里给了户号 {$pick['account']}，已发给客户");
        json_out(['ok' => true, 'matched' => true, 'session_id' => $sid, 'sent' => $line]);
        return;
    }

    // 等核实：把群里的话当成核实结果
    $neg = false;
    foreach (['没到', '未到', '没收到', '没有', '不到', '不对', '等一下', '再查'] as $k) {
        if (mb_stripos($text, $k) !== false) { $neg = true; break; }
    }
    if ($neg) {
        ai_chat_state_set($tid, $sid, ['ask_kind' => '', 'stage' => 'sent_number']);
        ai_chat_insert_agent($tid, $sid, $cfg['sign'] !== '' ? $cfg['sign'] : '客服', '稍等 我这边再核实一下');
        ai_chat_log($tid, "s{$sid} 群里说到账未确认：{$text}");
        json_out(['ok' => true, 'matched' => true, 'session_id' => $sid, 'verified' => false]);
        return;
    }
    ai_chat_state_set($tid, $sid, ['stage' => 'done', 'ask_kind' => '']);
    ai_chat_insert_agent($tid, $sid, $cfg['sign'] !== '' ? $cfg['sign'] : '客服',
        "已核实到账 ✅ 已结算完毕 请勿私下充值❗️");
    ai_chat_log($tid, "s{$sid} 群里确认到账：{$text}");
    json_out(['ok' => true, 'matched' => true, 'session_id' => $sid, 'verified' => true]);
}
