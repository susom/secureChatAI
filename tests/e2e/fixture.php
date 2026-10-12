<?php
/**
 * Token-policy E2E fixture. Swaps SecureChatAI's LOCAL system settings for a test configuration
 * that points every model at the mock AI Hub (mock_aihub.php), and puts them back afterwards.
 *
 *   docker exec redcap_2023_1_web php /var/www/html/modules-local/secure_chat_ai_v9.9.9/tests/e2e/fixture.php setup
 *   docker exec redcap_2023_1_web php /var/www/html/modules-local/secure_chat_ai_v9.9.9/tests/e2e/fixture.php teardown
 *
 * setup:
 *   1. Backs up every SecureChatAI system setting row (project_id IS NULL), including the real AI
 *      Hub tokens, to BACKUP inside the container (mode 0600). Refuses to run if a backup is already
 *      there, so a second setup can never overwrite the real settings with the test ones.
 *   2. Writes the test model rows and fake tokens (see ROWS).
 *   3. Creates a throwaway super user (USER) with a module-API token on PID, and enables the module
 *      on PID, so the REST actions can be called with a real REDCap API token.
 * teardown: restores the backup exactly, removes the user, the PID settings and the backup.
 *
 * Local only. Never run against production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$mode = (string)($argv[1] ?? '');

define('NOAUTH', true);
require_once '/var/www/html/redcap_connect.php';

const PREFIX = 'secure_chat_ai';
const PID = 270; // "Tweak UI" — local dev test project
const USER = 'e2e_sca_admin';
const PASS = 'E2eScaAdmin!2026';
const BACKUP = '/tmp/sca_e2e_settings_backup.json';
const TOKEN_FILE = '/tmp/sca_e2e_api_token';
const MOCK = 'http://127.0.0.1:8099';

const STD_KEY = 'e2e-std-key';
const HIGH_KEY = 'e2e-high-key';
const LEGACY_KEY = 'e2e-legacy-row-key';

// alias, policy, own row token
const ROWS = [
    ['gpt-4-1', 'standard', LEGACY_KEY], // legacy row: own token, standard
    ['gpt-5-4', 'standard', ''],         // standard system token
    ['gpt-5-4', 'high', ''],             // same alias, high-threshold set
    ['gpt-4-1-nano', 'high', ''],        // high only
];

function out(string $label, string $value = ''): void { printf("  [ok] %-40s %s\n", $label, $value); }
function fail(string $msg): void { fwrite(STDERR, "  [FAIL] $msg\n"); exit(1); }
function q(string $sql, array $params = []) {
    $r = db_query($sql, $params);
    if ($r === false) fail('query failed: ' . db_error());
    return $r;
}

$moduleId = (int)db_result(q("select external_module_id from redcap_external_modules where directory_prefix = ?", [PREFIX]), 0);
if (!$moduleId) fail('secure_chat_ai is not registered locally');

if ($mode === 'teardown') {
    echo "\n=== restoring SecureChatAI local settings ===\n";
    if (is_file(BACKUP)) {
        $rows = json_decode((string)file_get_contents(BACKUP), true);
        if (!is_array($rows) || !$rows) fail('backup unreadable: ' . BACKUP . ' (left in place)');
        q("delete from redcap_external_module_settings where external_module_id = ? and project_id is null", [$moduleId]);
        foreach ($rows as $r) {
            q("insert into redcap_external_module_settings (external_module_id, project_id, `key`, type, value) values (?, null, ?, ?, ?)",
                [$moduleId, $r['key'], $r['type'], $r['value']]);
        }
        unlink(BACKUP);
        out('system settings restored', count($rows) . ' rows');
    } else {
        out('no backup found', 'system settings untouched');
    }
    q("delete from redcap_external_module_settings where external_module_id = ? and project_id = ?", [$moduleId, PID]);
    out('PID ' . PID . ' module settings removed');
    q("delete from redcap_user_rights where project_id = ? and username = ?", [PID, USER]);
    q("delete from redcap_auth where username = ?", [USER]);
    q("delete from redcap_user_information where username = ?", [USER]);
    @unlink(TOKEN_FILE);
    out('removed user', USER);
    echo "\n*** DONE ***\n";
    exit(0);
}

if ($mode !== 'setup') fail("usage: php fixture.php setup|teardown");

echo "\n=== SecureChatAI token-policy fixture ===\n";

if (is_file(BACKUP)) fail('a backup already exists at ' . BACKUP . ' — run teardown first');
if ((int)db_result(q("select count(*) from redcap_external_module_settings where external_module_id = ? and project_id = ?", [$moduleId, PID]), 0) > 0) {
    fail('PID ' . PID . ' already has SecureChatAI settings; this fixture would delete them on teardown');
}

$backup = [];
$r = q("select `key`, type, value from redcap_external_module_settings where external_module_id = ? and project_id is null", [$moduleId]);
while ($row = db_fetch_assoc($r)) $backup[] = $row;
if (!$backup) fail('no system settings to back up');
file_put_contents(BACKUP, json_encode($backup));
chmod(BACKUP, 0600);
out('backed up system settings', count($backup) . ' rows -> ' . BACKUP);

// CLI has no user, so the framework's user-based permission check must be off for these writes.
$module = \ExternalModules\ExternalModules::getModuleInstance(PREFIX);
$module->disableUserBasedSettingPermissions();
$set = function (string $key, $value) use ($module) {
    $module->setSystemSetting($key, $value);
};
$set('aihub-token-standard', STD_KEY);
$set('aihub-token-high-threshold', HIGH_KEY);
$set('api-settings', array_fill(0, count(ROWS), true));
$set('model-alias', array_column(ROWS, 0));
$set('model-id', array_column(ROWS, 0));
$set('token-policy', array_column(ROWS, 1));
$set('api-token', array_column(ROWS, 2));
$set('api-url', array_map(fn($row) => MOCK . "/{$row[1]}/{$row[0]}", ROWS));
$set('api-key-var', array_fill(0, count(ROWS), 'api-key'));
$set('api-input-var', array_fill(0, count(ROWS), 'messages'));
$set('default-model', [true, false, false, false]);
$set('enable_agent_mode', true);
$module->removeSystemSetting('agent_tool_em_prefixes');
out('test model rows written', count(ROWS) . ' rows -> ' . MOCK);

// Throwaway super user with a module-API token on PID.
q("delete from redcap_user_rights where project_id = ? and username = ?", [PID, USER]);
q("delete from redcap_auth where username = ?", [USER]);
q("delete from redcap_user_information where username = ?", [USER]);
$salt = \Authentication::generatePasswordSalt();
$hash = \Authentication::hashPassword(PASS, $salt);
q("insert into redcap_auth (username, password, password_salt, legacy_hash, temp_pwd) values (?, ?, ?, 0, 0)", [USER, $hash, $salt]);
if (!\Authentication::verifyTableUsernamePassword(USER, PASS)) fail('seeded password does not verify');
// super_user alone no longer shows the Configure button: module config also needs the
// granular admin rights (access_external_module_install, access_system_config).
q("insert into redcap_user_information (username, user_email, user_firstname, user_lastname, user_creation, super_user, account_manager, allow_create_db,
       admin_rights, access_system_config, access_external_module_install)
   values (?, ?, 'E2E', 'SecureChatAI', now(), 1, 0, 0, 1, 1, 1)", [USER, USER . '@example.invalid']);
$apiToken = strtoupper(bin2hex(random_bytes(16)));
q("insert into redcap_user_rights (project_id, username, design, user_rights, api_token, api_modules) values (?, ?, 1, 0, ?, 1)",
    [PID, USER, $apiToken]);
file_put_contents(TOKEN_FILE, $apiToken);
chmod(TOKEN_FILE, 0600);
out('super user', USER . ' / ' . PASS);

// `enabled` is a reserved key the framework won't set from CLI; one row, removed on teardown.
q("insert into redcap_external_module_settings (external_module_id, project_id, `key`, type, value) values (?, ?, 'enabled', 'boolean', 'true')",
    [$moduleId, PID]);
out('module enabled on PID', (string)PID);

$base = rtrim((string)db_result(q("select value from redcap_config where field_name = 'redcap_base_url'"), 0), '/');
echo "\n  PID:        " . PID . "\n";
echo "  API token:  " . TOKEN_FILE . " (throwaway, removed on teardown)\n";
echo "  login:      $base/\n";
echo "\n*** DONE — run teardown when finished ***\n";
