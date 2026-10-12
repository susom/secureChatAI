<?php
/**
 * Stand-in for AI Hub, for the token-policy E2E. Runs ONLY under PHP's built-in server, inside the
 * web container, so it is never reachable through Apache:
 *
 *   docker exec -d redcap_2023_1_web php -S 127.0.0.1:8099 \
 *       /var/www/html/modules-local/secure_chat_ai_v9.9.9/tests/e2e/mock_aihub.php
 *
 * The fixture (fixture.php) points every model row at http://127.0.0.1:8099/<policy>/<alias>, with
 * fake keys. For each request the mock appends one line to /tmp/sca_mock_aihub.jsonl: the path, the
 * key it was sent and the top-level body keys (never the message text), so a test can prove which
 * model set and which key served a call, and that `token_policy` never went upstream.
 *
 * Chat replies (OpenAI shape) echo the alias, path and key so the smoke-test page shows them.
 * Agent-mode calls (response_format json_schema) get an agent JSON reply: a spawnAgent tool call when
 * the conversation contains E2E_SPAWN and no child has answered yet; a projects.getMetadata call on
 * PID 282 as the first step of an E2E_CROSS conversation; otherwise a final answer.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

const LOG = '/tmp/sca_mock_aihub.jsonl';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$key = $_SERVER['HTTP_API_KEY'] ?? '';

if ($path === '/__reset') {
    @unlink(LOG);
    header('Content-Type: application/json');
    echo '{"reset":true}';
    exit;
}

$messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
$allText = '';
foreach ($messages as $m) {
    $allText .= "\n" . (is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? ''));
}

file_put_contents(LOG, json_encode([
    'path'      => $path,
    'key'       => $key,
    'body_keys' => is_array($body) ? array_keys($body) : [],
    'model'     => $body['model'] ?? null,
    'raw_has_token_policy' => str_contains($raw, 'token_policy'),
    // The TokenPolicyPreHook denial text, as fed back to the model after a refused tool call.
    'has_policy_denial'    => str_contains($allText, 'requires the \'high\' AI token policy'),
    'messages'             => count($messages),
]) . "\n", FILE_APPEND);

$parts = explode('/', trim($path, '/'));
$policy = $parts[0] ?? '?';
$alias = $parts[1] ?? '?';
$tag = "path=/{$policy}/{$alias} key={$key}";

if (isset($body['response_format']['json_schema'])) {
    if (str_contains($allText, 'E2E_CROSS') && count($messages) <= 2) {
        $content = json_encode(['tool_call' => ['name' => 'projects.getMetadata', 'arguments' => ['pid' => 282]]]);
    } elseif (str_contains($allText, 'E2E_SPAWN') && !str_contains($allText, 'E2E_CHILD_DONE')) {
        $content = json_encode(['tool_call' => ['name' => 'spawnAgent', 'arguments' => ['prompt' => 'E2E_CHILD task']]]);
    } elseif (str_contains($allText, 'E2E_CHILD task') && !str_contains($allText, 'E2E_SPAWN')) {
        $content = json_encode(['final_answer' => "E2E_CHILD_DONE {$tag}"]);
    } else {
        $content = json_encode(['final_answer' => "Agent done {$tag}"]);
    }
} elseif (str_contains($allText, 'Concisely summarize')) {
    $content = "Summary {$tag}";
} else {
    $content = "Test successful for {$alias} | {$tag}";
}

header('Content-Type: application/json');
echo json_encode([
    'id'      => 'mock-' . uniqid(),
    'object'  => 'chat.completion',
    'created' => time(),
    'model'   => $body['model'] ?? $alias,
    'choices' => [[
        'index'         => 0,
        'message'       => ['role' => 'assistant', 'content' => $content],
        'finish_reason' => 'stop',
    ]],
    'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 7, 'total_tokens' => 12],
]);
