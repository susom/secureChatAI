<?php
/**
 * Token-policy routing E2E: drives the real module (callAI, agent loop, sub-agent, compaction, nested
 * calls, both REST actions) against the mock AI Hub, and reads the mock's request log to prove which
 * model set and which key served each call.
 *
 * Needs fixture.php setup and the mock running (see docs/token-policy.md, "Testing"):
 *   docker exec redcap_2023_1_web php /var/www/html/modules-local/secure_chat_ai_v9.9.9/tests/e2e/routing.php
 *
 * Exit code 0 = all passed.
 */

namespace Stanford\SecureChatAI;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('NOAUTH', true);
require_once '/var/www/html/redcap_connect.php';

const PID = 270;
const MOCK = 'http://127.0.0.1:8099';
const LOG = '/tmp/sca_mock_aihub.jsonl';
const STD = '/standard/';
const HIGH = '/high/';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  [ok]   " : "  [FAIL] ") . $name . (!$ok && $detail !== '' ? " — $detail" : '') . "\n";
}

function resetMock(): void
{
    $ch = curl_init(MOCK . '/__reset');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 5]);
    if (curl_exec($ch) === false) {
        fwrite(STDERR, "mock AI Hub not reachable at " . MOCK . " — start it first\n");
        exit(1);
    }
    curl_close($ch);
}

/** @return array<int, array> requests the mock saw since the last reset */
function seen(): array
{
    if (!is_file(LOG)) return [];
    return array_values(array_filter(array_map(fn($l) => json_decode($l, true), file(LOG, FILE_IGNORE_NEW_LINES))));
}

function paths(array $seen): string
{
    return implode(', ', array_map(fn($s) => $s['path'] . ' [' . $s['key'] . ']', $seen)) ?: '(none)';
}

function allOn(array $seen, string $prefix, string $key): bool
{
    if (!$seen) return false;
    foreach ($seen as $s) {
        if (!str_starts_with($s['path'], $prefix) || $s['key'] !== $key) return false;
    }
    return true;
}

function setMinimum(?string $policy): void
{
    global $m;
    if ($policy === null) {
        $m->removeProjectSetting('token-policy-default', PID);
    } else {
        $m->setProjectSetting('token-policy-default', $policy, PID);
    }
}

function chat(string $text): array
{
    return ['messages' => [['role' => 'user', 'content' => $text]]];
}

/** @var SecureChatAI $m */
$m = \ExternalModules\ExternalModules::getModuleInstance('secure_chat_ai');
$m->disableUserBasedSettingPermissions(); // CLI: no user
setMinimum(null);

echo "callAI — per-call policy, no project\n";

resetMock();
$r = $m->callAI('gpt-5-4', chat('hello'));
$s = seen();
check('R1 no policy -> standard gpt-5-4 on the standard key', count($s) === 1 && allOn($s, STD . 'gpt-5-4', 'e2e-std-key'), paths($s));
check('R1 reply came back', str_contains($r['content'] ?? '', 'path=/standard/gpt-5-4'), json_encode($r));

resetMock();
$r = $m->callAI('gpt-5-4', chat('hello') + ['token_policy' => 'high']);
$s = seen();
check('R2 high -> high gpt-5-4 on the high key (same alias)', count($s) === 1 && allOn($s, HIGH . 'gpt-5-4', 'e2e-high-key'), paths($s));

resetMock();
$r = $m->callAI('gpt-5-4', chat('hello') + ['token_policy' => ' HIGH ']);
check('R2b policy value is case/space-insensitive', allOn(seen(), HIGH . 'gpt-5-4', 'e2e-high-key'), paths(seen()));

resetMock();
$r = $m->callAI('gpt-4-1', chat('hello') + ['token_policy' => 'high']);
check('R3 high + standard-only model -> TOKEN_POLICY_ERROR', ($r['type'] ?? '') === 'TOKEN_POLICY_ERROR' && !empty($r['error']), json_encode($r));
check('R3 message names the policy and where the model exists', str_contains($r['message'] ?? '', "'high' token policy") && str_contains($r['message'] ?? '', 'standard'), $r['message'] ?? '');
check('R3 nothing sent upstream (no fallback)', seen() === [], paths(seen()));

resetMock();
$r = $m->callAI('gpt-4-1-nano', chat('hello') + ['token_policy' => 'standard']);
check('R4 standard + high-only model -> TOKEN_POLICY_ERROR, nothing sent', ($r['type'] ?? '') === 'TOKEN_POLICY_ERROR' && seen() === [], paths(seen()));

resetMock();
$r = $m->callAI('no-such-model', chat('hello') + ['token_policy' => 'high']);
check('R4b alias under no policy keeps the old "Unsupported model" error, nothing sent',
    ($r['type'] ?? '') !== 'TOKEN_POLICY_ERROR' && seen() === [], json_encode($r));

resetMock();
$r = $m->callAI('gpt-5-4', chat('hello') + ['token_policy' => 'ultra']);
check('R5 unknown policy -> TOKEN_POLICY_ERROR, nothing sent', ($r['type'] ?? '') === 'TOKEN_POLICY_ERROR' && seen() === [], json_encode($r));

resetMock();
$r = $m->callAI('gpt-4-1', chat('hello'));
check('R6 legacy row keeps its own token', allOn(seen(), STD . 'gpt-4-1', 'e2e-legacy-row-key'), paths(seen()));

echo "callAI — project minimum (PID " . PID . ")\n";
setMinimum('high');

resetMock();
$r = $m->callAI('gpt-5-4', chat('hello'), PID, 'e2e_sca_admin');
check('R7 minimum high, no policy -> high', allOn(seen(), HIGH . 'gpt-5-4', 'e2e-high-key'), paths(seen()));

resetMock();
$r = $m->callAI('gpt-5-4', chat('hello') + ['token_policy' => 'standard'], PID, 'e2e_sca_admin');
check('R8 minimum high, standard asked -> refused, nothing sent', ($r['type'] ?? '') === 'TOKEN_POLICY_ERROR' && seen() === [], json_encode($r));
check('R8 refusal says it is below the minimum', str_contains($r['message'] ?? '', 'minimum'), $r['message'] ?? '');

resetMock();
$r = $m->callAI('gpt-4-1', chat('hello'), PID, 'e2e_sca_admin');
check('R9 minimum high, standard-only model -> refused, nothing sent', ($r['type'] ?? '') === 'TOKEN_POLICY_ERROR' && seen() === [], paths(seen()));

setMinimum('standard');
resetMock();
$r = $m->callAI('gpt-5-4', chat('hello') + ['token_policy' => 'high'], PID, 'e2e_sca_admin');
check('R10 minimum standard, high asked -> high (raise allowed)', allOn(seen(), HIGH . 'gpt-5-4', 'e2e-high-key'), paths(seen()));
setMinimum(null);

echo "nested calls inherit the enclosing policy\n";
$prop = new \ReflectionProperty($m, 'activeTokenPolicy');
$prop->setAccessible(true);
$prop->setValue($m, 'high'); // as if a tool EM called back during a high call
resetMock();
$nested = $m->callAI('gpt-5-4', chat('nested'));
$nestedSeen = seen();
resetMock();
$nestedDown = $m->callAI('gpt-5-4', chat('nested') + ['token_policy' => 'standard']);
$nestedDownSeen = seen();
$prop->setValue($m, null);
check('R11 nested call with no policy -> high', allOn($nestedSeen, HIGH . 'gpt-5-4', 'e2e-high-key'), paths($nestedSeen));
check('R12 nested call asking standard -> refused', ($nestedDown['type'] ?? '') === 'TOKEN_POLICY_ERROR' && $nestedDownSeen === [], paths($nestedDownSeen));
check('R13 enclosing policy restored after the call', $prop->getValue($m) === null);

echo "agent loop and sub-agent\n";
resetMock();
$r = $m->callAI('gpt-5-4', chat('E2E_SPAWN please') + ['agent_mode' => true, 'token_policy' => 'high']);
$s = seen();
check('R14 agent loop + spawned sub-agent: every step on high', count($s) >= 3 && allOn($s, HIGH . 'gpt-5-4', 'e2e-high-key'), paths($s));
check('R14 agent finished', str_contains(json_encode($r), 'Agent done'), json_encode($r));

resetMock();
$r = $m->callAI('gpt-4-1-nano', chat('E2E_SPAWN please') + ['agent_mode' => true, 'token_policy' => 'high']);
$s = seen();
check('R15 agent fallback picks a schema model from the high set only', $s !== [] && allOn($s, HIGH, 'e2e-high-key'), paths($s));

echo "compaction\n";
$long = [];
for ($i = 0; $i < 12; $i++) {
    $long[] = ['role' => $i % 2 ? 'assistant' : 'user', 'content' => str_repeat("turn $i ", 40)];
}
resetMock();
$c = $m->runCompaction($long, 'gpt-5-4', 2, 'gpt-4-1-nano', 50, 'high');
check('R16 compaction under high summarizes on the high set', $c['compacted'] && allOn(seen(), HIGH . 'gpt-4-1-nano', 'e2e-high-key'), paths(seen()));

resetMock();
$c = $m->runCompaction($long, 'gpt-5-4', 2, 'gpt-4-1', 50, 'high');
check('R17 compaction under high with a standard-only summarizer: skipped, nothing sent', !$c['compacted'] && seen() === [], paths(seen()));

resetMock();
$r = $m->callAI('gpt-5-4', ['messages' => $long, 'compact_if_needed' => true, 'compact_keep_recent' => 2, 'compact_model' => 'gpt-4-1', 'token_policy' => 'high']);
$s = seen();
check('R18 in-call compaction never reaches the standard summarizer', $s !== [] && allOn($s, HIGH, 'e2e-high-key'), paths($s));

echo "upstream hygiene\n";
resetMock();
$m->callAI('gpt-5-4', chat('hello') + ['token_policy' => 'high']);
$m->callAI('gpt-5-4', chat('E2E_SPAWN') + ['agent_mode' => true, 'token_policy' => 'high']);
$s = seen();
check('R19 token_policy never sent to the provider', $s !== [] && !array_filter($s, fn($x) => $x['raw_has_token_policy']), json_encode(array_column($s, 'body_keys')));

echo "logging\n";
$logRows = function (string $record, int $limit) {
    $r = db_query(
        "select message from redcap_external_modules_log
          where external_module_id = (select external_module_id from redcap_external_modules where directory_prefix = 'secure_chat_ai')
            and project_id = ? and record = ? order by log_id desc limit $limit", [PID, $record]
    );
    $rows = [];
    while ($row = db_fetch_assoc($r)) $rows[] = (string)$row['message'];
    return $rows;
};
$ok = $logRows('SecureChatLog', 5);
$err = $logRows('SecureChatLogError', 3);
$decoded = array_map(fn($x) => json_decode($x, true) ?: [], $ok);
check('R20 project log rows record token_policy', $decoded && !array_filter($decoded, fn($d) => !in_array($d['token_policy'] ?? null, ['standard', 'high'], true)), json_encode(array_column($decoded, 'token_policy')));
$errDecoded = array_map(fn($x) => json_decode($x, true) ?: [], $err);
check('R21 refused calls log TOKEN_POLICY_ERROR with the model alias', $errDecoded && ($errDecoded[0]['error_type'] ?? '') === 'TOKEN_POLICY_ERROR' && ($errDecoded[0]['model'] ?? 'unknown') !== 'unknown', json_encode($errDecoded[0] ?? []));
// The mock echoes the key it received in its reply text, which is logged as assistant_response;
// everything else in the row must be key-free.
$withoutReplies = array_map(function ($d) { unset($d['assistant_response']); return json_encode($d); }, array_merge($decoded, $errDecoded));
$all = implode("\n", $withoutReplies);
check('R22 log rows never hold a key', $all !== '' && !preg_match('/e2e-(std|high|legacy-row)-key/', $all), $all);

echo "REST API (content=externalModule)\n";
$apiToken = trim((string)@file_get_contents('/tmp/sca_e2e_api_token'));
function api(string $token, array $fields): array
{
    $ch = curl_init('http://127.0.0.1/api/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Host: redcap.local'],
        CURLOPT_POSTFIELDS => http_build_query($fields + [
            'token' => $token, 'content' => 'externalModule', 'prefix' => 'secure_chat_ai', 'format' => 'json', 'returnFormat' => 'json',
        ]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$body, true), (string)$body];
}

if ($apiToken === '') {
    check('REST: API token from fixture', false, 'run fixture.php setup');
} else {
    setMinimum(null);
    resetMock();
    [$code, $j] = api($apiToken, ['action' => 'callAI', 'model' => 'gpt-5-4', 'prompt' => 'hi', 'token_policy' => 'high']);
    check('A1 callAI token_policy=high -> 200, high set', $code === 200 && ($j['token_policy'] ?? '') === 'high' && allOn(seen(), HIGH . 'gpt-5-4', 'e2e-high-key'), "$code " . json_encode($j) . ' ' . paths(seen()));

    resetMock();
    [$code, $j] = api($apiToken, ['action' => 'callAI', 'model' => 'gpt-5-4', 'prompt' => 'hi']);
    check('A2 callAI no policy -> standard', $code === 200 && ($j['token_policy'] ?? '') === 'standard' && allOn(seen(), STD . 'gpt-5-4', 'e2e-std-key'), "$code " . paths(seen()));

    resetMock();
    [$code, $j] = api($apiToken, ['action' => 'callAI', 'model' => 'gpt-4-1', 'prompt' => 'hi', 'token_policy' => 'high']);
    check('A3 callAI high + standard-only model -> 400 TOKEN_POLICY_ERROR, nothing sent', $code === 400 && ($j['type'] ?? '') === 'TOKEN_POLICY_ERROR' && seen() === [], "$code " . json_encode($j));

    setMinimum('high');
    resetMock();
    [$code, $j] = api($apiToken, ['action' => 'callAI', 'model' => 'gpt-5-4', 'prompt' => 'hi', 'token_policy' => 'standard']);
    check("A4 callAI below the token's project minimum -> 400, nothing sent", $code === 400 && ($j['type'] ?? '') === 'TOKEN_POLICY_ERROR' && seen() === [], "$code " . json_encode($j));

    resetMock();
    [$code, $j] = api($apiToken, ['action' => 'callAI', 'model' => 'gpt-5-4', 'prompt' => 'hi']);
    check("A5 callAI no policy on a high-minimum project -> high", $code === 200 && ($j['token_policy'] ?? '') === 'high' && allOn(seen(), HIGH . 'gpt-5-4', 'e2e-high-key'), "$code " . paths(seen()));

    resetMock();
    [$code, $j] = api($apiToken, ['action' => 'messages', 'model' => 'gpt-5-4', 'messages' => json_encode([['role' => 'user', 'content' => 'hi']]), 'token_policy' => 'standard']);
    check('A6 messages below minimum -> 400 Claude-format token_policy_error', $code === 400 && ($j['error']['type'] ?? '') === 'token_policy_error' && seen() === [], "$code " . json_encode($j));

    resetMock();
    [$code, $j] = api($apiToken, ['action' => 'messages', 'model' => 'gpt-5-4', 'messages' => json_encode([['role' => 'user', 'content' => 'hi']])]);
    check('A7 messages no policy on high-minimum project -> 200, high set', $code === 200 && ($j['type'] ?? '') === 'message' && allOn(seen(), HIGH . 'gpt-5-4', 'e2e-high-key'), "$code " . paths(seen()));
    setMinimum(null);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
