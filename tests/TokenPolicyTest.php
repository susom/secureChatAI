<?php
/**
 * Unit tests for classes/TokenPolicy.php. Dependency-free (no PHPUnit, no REDCap):
 *
 *   docker exec redcap_2023_1_web php /var/www/html/modules-local/secure_chat_ai_v9.9.9/tests/TokenPolicyTest.php
 *
 * Exit code 0 = all passed.
 */

namespace Stanford\SecureChatAI;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../classes/TokenPolicy.php';

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [ok]   $name\n";
    } else {
        $fail++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function throwsPolicy(callable $fn): bool
{
    try {
        $fn();
    } catch (TokenPolicyException $e) {
        return true;
    }
    return false;
}

echo "normalize()\n";
check('null means not specified', TokenPolicy::normalize(null) === null);
check('empty string means not specified', TokenPolicy::normalize('') === null);
check('whitespace means not specified', TokenPolicy::normalize('  ') === null);
check("'standard' accepted", TokenPolicy::normalize('standard') === 'standard');
check("'HIGH ' is trimmed and lower-cased", TokenPolicy::normalize(' HIGH ') === 'high');
check('unknown value throws', throwsPolicy(fn() => TokenPolicy::normalize('ultra')));
check("'high-threshold' (the label, not the value) throws", throwsPolicy(fn() => TokenPolicy::normalize('high-threshold')));
check('non-string throws', throwsPolicy(fn() => TokenPolicy::normalize(['high'])));
check('boolean throws', throwsPolicy(fn() => TokenPolicy::normalize(true)));

echo "resolve() — the project default is a minimum\n";
check('nothing asked, standard minimum -> standard', TokenPolicy::resolve(null, 'standard') === 'standard');
check('nothing asked, high minimum -> high', TokenPolicy::resolve(null, 'high') === 'high');
check('high asked, standard minimum -> high (raise allowed)', TokenPolicy::resolve('high', 'standard') === 'high');
check('high asked, high minimum -> high', TokenPolicy::resolve('high', 'high') === 'high');
check('standard asked, standard minimum -> standard', TokenPolicy::resolve('standard', 'standard') === 'standard');
check('standard asked, high minimum -> refused (no downgrade)', throwsPolicy(fn() => TokenPolicy::resolve('standard', 'high')));

echo "stricter()\n";
check('standard/high -> high', TokenPolicy::stricter('standard', 'high') === 'high');
check('high/standard -> high', TokenPolicy::stricter('high', 'standard') === 'high');
check('standard/standard -> standard', TokenPolicy::stricter('standard', 'standard') === 'standard');

echo "buildRegistry()\n";
$rows = [
    // legacy row: no policy, own token
    ['model-alias' => 'gpt-4-1', 'model-id' => 'gpt-4-1', 'api-url' => 'https://std/gpt-4-1', 'api-token' => 'row-key',
     'api-key-var' => 'api-key', 'api-input-var' => ['messages'], 'default-model' => true],
    // standard row on the system token
    ['model-alias' => 'gpt-5-4', 'model-id' => 'gpt-5-4', 'api-url' => 'https://std/gpt-5-4', 'api-token' => '',
     'api-key-var' => 'api-key', 'api-input-var' => ['messages'], 'token-policy' => 'standard'],
    // high row reusing the same alias
    ['model-alias' => 'gpt-5-4', 'model-id' => 'gpt-5-4-ht', 'api-url' => 'https://high/gpt-5-4', 'api-token' => null,
     'api-key-var' => 'api-key', 'api-input-var' => ['messages'], 'token-policy' => 'high'],
    // high row with its own override token
    ['model-alias' => 'claude-ht', 'model-id' => 'c', 'api-url' => 'https://high/claude', 'api-token' => ' own-high ',
     'api-key-var' => 'api-key', 'api-input-var' => ['messages'], 'token-policy' => 'high'],
    // bad policy -> dropped
    ['model-alias' => 'weird', 'api-url' => 'x', 'token-policy' => 'ultra'],
    // empty alias -> dropped
    ['model-alias' => '  ', 'api-url' => 'x'],
];
$reg = TokenPolicy::buildRegistry($rows, ' std-key ', 'high-key');
check('both policies present', isset($reg['standard'], $reg['high']));
check('legacy row lands in standard', isset($reg['standard']['gpt-4-1']));
check('legacy row keeps its own token', ($reg['standard']['gpt-4-1']['api_token'] ?? null) === 'row-key');
check('legacy row is the default', ($reg['standard']['gpt-4-1']['is_default'] ?? null) === true);
check('standard row uses trimmed standard system token', ($reg['standard']['gpt-5-4']['api_token'] ?? null) === 'std-key');
check('same alias under high does not overwrite standard', ($reg['standard']['gpt-5-4']['api_url'] ?? null) === 'https://std/gpt-5-4');
check('high row uses high system token', ($reg['high']['gpt-5-4']['api_token'] ?? null) === 'high-key');
check('high row keeps its own model id', ($reg['high']['gpt-5-4']['model_id'] ?? null) === 'gpt-5-4-ht');
check('high row override token (trimmed) wins', ($reg['high']['claude-ht']['api_token'] ?? null) === 'own-high');
check('rows carry their policy', ($reg['high']['claude-ht']['token_policy'] ?? null) === 'high');
check('unknown-policy row dropped (fail closed)', !isset($reg['standard']['weird']) && !isset($reg['high']['weird']));
check('empty alias dropped', count($reg['standard']) === 2 && count($reg['high']) === 2);
check('high token never appears on a standard row', !in_array('high-key', array_column($reg['standard'], 'api_token'), true));
check('standard token never appears on a high row', !in_array('std-key', array_column($reg['high'], 'api_token'), true));

echo "validateSettings()\n";
$base = [
    'aihub-token-standard'       => 'std',
    'aihub-token-high-threshold' => 'high',
    'model-alias'  => ['a', 'b'],
    'token-policy' => ['standard', 'high'],
    'api-token'    => [null, null],
];
check('valid system save passes', TokenPolicy::validateSettings($base) === null);
$noHigh = $base;
$noHigh['aihub-token-high-threshold'] = null;
$msg = TokenPolicy::validateSettings($noHigh);
check('high row without high token is refused', is_string($msg) && str_contains($msg, "Model 'b'"), (string)$msg);
check('message names the setting to fill', is_string($msg) && str_contains($msg, 'AI Hub Token (high-threshold)'));
$noHighButOverride = $noHigh;
$noHighButOverride['api-token'] = [null, 'own'];
check('high row with its own override token passes', TokenPolicy::validateSettings($noHighButOverride) === null);
$noStd = $base;
$noStd['aihub-token-standard'] = '';
check('standard row without any token is refused', is_string(TokenPolicy::validateSettings($noStd)));
$legacy = ['model-alias' => ['a'], 'api-token' => ['row'], 'aihub-token-standard' => null, 'aihub-token-high-threshold' => null];
check('legacy save (rows with own tokens, no policy) passes', TokenPolicy::validateSettings($legacy) === null);
$single = ['model-alias' => 'a', 'token-policy' => 'high', 'api-token' => null, 'aihub-token-high-threshold' => 'h'];
check('scalar (single-row) values handled', TokenPolicy::validateSettings($single) === null);
$badRow = $base;
$badRow['token-policy'] = ['standard', 'ultra'];
check('unknown row policy is refused', is_string(TokenPolicy::validateSettings($badRow)));
check('project save with valid minimum passes', TokenPolicy::validateSettings(['token-policy-default' => 'high']) === null);
check('project save with empty minimum passes', TokenPolicy::validateSettings(['token-policy-default' => null]) === null);
check('project save with bad minimum is refused', is_string(TokenPolicy::validateSettings(['token-policy-default' => 'x'])));
check('unrelated project save passes', TokenPolicy::validateSettings(['enable-project-usage' => true]) === null);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
