<?php
/**
 * routes/ai.php — 客服端 AI 助手接口（v2.90.75）
 *
 * 定位：AI 只给**人工客服**出建议（建议回复 / 会话摘要 / 待办），不直接对客户说话。
 *   红线和上下文拼装都在 helpers.php 的 ai_* 函数里（客户的钱走官方渠道、不承诺到账、
 *   不产出「折扣/慢充」类话术、涉风险一律建议转人工）。
 *
 *   GET  /api/tenant/ai      读设置（Key 只回掩码）+ 知识库条数 + 今日用量
 *   PUT  /api/tenant/ai      保存设置（ai_key 留空=不改，传 '-'=清空）
 *   POST /api/tenant/ai      测试连通性（真调一次模型）
 *   POST /api/ai/suggest     针对某会话生成建议（{session_id}）
 */
declare(strict_types=1);

function handle_ai_settings(string $method): void
{
    $ctx = require_workspace();
    $tid = (int)$ctx['tenant_id'];

    if ($method === 'GET') {
        $s = ai_settings($tid);
        $kb = 0;
        try { $kb = fetch_count(db(), 'SELECT COUNT(*) FROM ai_kb WHERE tenant_id = ? AND enabled = 1', [$tid]); } catch (\Throwable $e) {}
        $today = ['calls' => 0, 'errors' => 0, 'tokens_in' => 0, 'tokens_out' => 0];
        try {
            $st = db()->prepare('SELECT COUNT(*) AS c, SUM(ok = 0) AS e, SUM(ptok) AS pi, SUM(ctok) AS co
                FROM ai_logs WHERE tenant_id = ? AND created_at >= ?');
            $st->execute([$tid, date('Y-m-d 00:00:00')]);
            $r = $st->fetch() ?: [];
            $today = [
                'calls' => (int)($r['c'] ?? 0), 'errors' => (int)($r['e'] ?? 0),
                'tokens_in' => (int)($r['pi'] ?? 0), 'tokens_out' => (int)($r['co'] ?? 0),
            ];
        } catch (\Throwable $e) { /* 表还没建好时忽略 */ }
        json_out([
            'ok' => true, 'settings' => $s,
            'key_masked' => ai_mask_key(ai_key_raw($tid)),
            'has_key' => ai_key_raw($tid) !== '',
            'kb_count' => $kb, 'today' => $today,
        ]);
    }

    if ($method === 'PUT') {
        if ((string)($ctx['role'] ?? '') !== 'tenant') json_error('只有账户主人能改 AI 设置', 403);
        ai_save_settings($tid, body());
        json_out([
            'ok' => true, 'settings' => ai_settings($tid),
            'key_masked' => ai_mask_key(ai_key_raw($tid)),
            'has_key' => ai_key_raw($tid) !== '',
        ]);
    }

    /* 测试连通性：真调一次模型，让用户知道 key/地址/模型对不对 */
    if ($method === 'POST') {
        $r = ai_chat($tid, [
            ['role' => 'system', 'content' => '你是连通性测试助手，只回一句简短的中文问候，不要多余内容。'],
            ['role' => 'user', 'content' => '你好'],
        ], 25, ['kind' => 'test']);
        if (empty($r['ok'])) json_out(['ok' => false, 'error' => (string)($r['error'] ?? '调用失败')]);
        json_out(['ok' => true, 'text' => (string)$r['text'], 'model' => (string)$r['model'], 'ms' => (int)$r['ms']]);
    }

    json_error('方法不允许', 405);
}

function handle_ai_suggest(string $method): void
{
    if ($method !== 'POST') { json_error('方法不允许', 405); return; }
    $ctx = require_workspace();
    $tid = (int)$ctx['tenant_id'];
    $sid = (int)input('session_id', 0);
    if ($sid <= 0) json_error('缺少会话 id', 400);

    $pdo = db();
    /* 跨租户一律 404（项目安全基线） */
    $st = $pdo->prepare('SELECT id, visitor_name, ip, geo, source, status FROM v_sessions
        WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL');
    $st->execute([$sid, $tid]);
    $sess = $st->fetch();
    if (!$sess) json_error('会话不存在', 404);

    $mq = $pdo->prepare("SELECT from_type, text FROM messages
        WHERE session_id = ? AND tenant_id = ? AND text IS NOT NULL AND text <> '' ORDER BY id DESC LIMIT 30");
    $mq->execute([$sid, $tid]);
    $msgs = array_reverse($mq->fetchAll());

    $s = ai_settings($tid);
    $kb = ai_kb_lines($tid, 60);
    $sys = ai_system_prompt();
    $extra = trim((string)$s['extra']);
    if ($extra !== '') $sys .= "\n\n【本账户的补充要求】\n" . mb_substr($extra, 0, 1500);

    $r = ai_chat($tid, [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => ai_context_text($sess, $msgs, $kb, ts_get($tid, 'brand_company', ''))],
    ], 40, ['kind' => 'suggest', 'session_id' => $sid, 'agent_id' => (int)($ctx['agent_id'] ?? 0)]);

    if (empty($r['ok'])) json_out(['ok' => false, 'error' => (string)($r['error'] ?? '生成失败')]);

    $parsed = ai_parse_suggest((string)$r['text'], (string)$s['block']);
    json_out(array_merge([
        'ok' => true,
        'model' => (string)$r['model'],
        'ms' => (int)$r['ms'],
        'tokens' => $r['tokens'] ?? null,
        'kb_used' => count($kb),
    ], $parsed));
}
