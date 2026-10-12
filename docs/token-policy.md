# AI Hub token policy (standard / high-threshold)

*Added 2026-10-12, branch `feature/token-policy`.*

SecureChatAI talks to two separate sets of AI Hub models, each behind its own subscription key:

| Policy | Models | Key (system setting) |
| --- | --- | --- |
| `standard` | the original AI Hub models | **AI Hub Token (standard)** `aihub-token-standard` |
| `high` | the high-threshold models (their own deployments, URLs and model IDs) | **AI Hub Token (high-threshold)** `aihub-token-high-threshold` |

**The policy is a data rule.** Data a caller sends under `high` must only ever reach a high-threshold model.
That rule drives every decision below:

- There is **no fallback** in either direction. A model that isn't configured under the requested policy is
  an error, not a quiet switch to the other set.
- A project's **Minimum Token Policy** is a floor. A call can raise it to `high`, never lower it.
- Every internal call path carries the policy: the agent loop, sub-agents, compaction summaries, and any
  nested `callAI()` a tool EM makes during a call.

## Configuration

### System settings (Control Center → External Modules → SecureChatAI → Configure)

- **AI Hub Token (standard)** / **AI Hub Token (high-threshold)** are `password` fields. The UI masks them;
  REDCap still stores them in plain text, like every module setting.
- Each **AI Model API Settings** row now has a **Token Policy** dropdown: `standard` (default) or
  `high-threshold`. Add each high-threshold model as its own row, set to `high-threshold`. A high row may reuse
  a standard alias (e.g. `gpt-5-4` in both sets); the registry is keyed by policy and then alias, so the two
  rows never overwrite each other.
- The row's **API Token** is now optional. Filled in, it overrides the system token for that row (this is
  how every existing row keeps working unchanged). Left empty, the row uses the system token for its policy.
- Saving is refused when a row would end up with no token: a `high` row with no row token while the
  high-threshold token is empty, or the same for `standard`.

### Project setting (project → External Modules → SecureChatAI → Configure)

- **Minimum Token Policy** `token-policy-default`, super users only. `high-threshold` sends every call made
  for this project to high-threshold models. Empty means `standard`.
- The project has to have SecureChatAI enabled for the setting to be editable. Calls made without a project
  ID (e.g. RAG's embedding call) only get the per-call value.

## Choosing the policy from a calling EM

```php
$sca = \ExternalModules\ExternalModules::getModuleInstance('secure_chat_ai');

$response = $sca->callAI('gpt-5-4', [
    'messages'     => [['role' => 'user', 'content' => $prompt]],
    'token_policy' => 'high',          // optional: 'standard' | 'high'
], $project_id, $username);

if (($response['type'] ?? null) === 'TOKEN_POLICY_ERROR') {
    // Refused before anything was sent. $response['message'] says why.
}
```

How a call's policy is resolved:

```
minimum  = stricter(project Minimum Token Policy, policy of an enclosing callAI(), 'standard')
policy   = token_policy if given, else minimum
refused  if token_policy is below minimum, unknown, or the alias isn't configured under policy
```

| Project minimum | `token_policy` passed | Result |
| --- | --- | --- |
| none / standard | (none) | standard |
| none / standard | `high` | high |
| high | (none) | high |
| high | `high` | high |
| high | `standard` | **refused** |
| any | `ultra` | **refused** |

`token_policy` is case- and whitespace-insensitive and is stripped before the request goes to AI Hub.
Existing callers (Cappy, MICA, RAG, Rexi) pass nothing, so they run exactly as before on any project without a
minimum.

### Refusal response

`callAI()` doesn't throw on a refusal. Like every other error it returns a normalized assistant message,
except that this one keeps its error flag, type and reason so the caller can detect it:

```php
[
    'role'    => 'assistant',
    'content' => 'This request was not sent: it is not allowed under the AI token policy. Please contact your administrator.',
    'error'   => true,
    'type'    => 'TOKEN_POLICY_ERROR',
    'message' => "Model 'gpt-4-1' is not configured under the 'high' token policy (it exists only under: standard).",
]
```

Refusals aren't retried. When there's a project they're logged as `SecureChatLogError` with
`error_type: TOKEN_POLICY_ERROR`.

An alias that exists under **no** policy (a typo) still fails the way it always has, as "Unsupported model"
(retried, then a generic error), not as a token-policy refusal.

### Other entry points

- `getAvailableModels(string $tokenPolicy = 'standard')` returns the aliases configured under one policy.
- `runCompaction(..., ?string $tokenPolicy = null, ?int $projectId = null)` resolves the policy the same
  way and summarizes on that policy's models only.
- **Agent mode:** if the requested model can't do JSON schemas, the fallback model is chosen from the
  same policy's set only.
- **Compaction inside a call:** if the summarize model doesn't exist under the call's policy, compaction is
  skipped and the conversation continues uncompacted. The history is never sent to the other set.

## REST API (`content=externalModule&prefix=secure_chat_ai`)

Both `callAI` and `messages` accept an optional `token_policy` field. The minimum comes from the **API
token's project**, so REST callers can't go below it either.

```bash
curl -X POST "https://redcap.stanford.edu/api/" \
  -d "token=YOUR_API_TOKEN" -d "content=externalModule" -d "prefix=secure_chat_ai" \
  -d "action=callAI" -d "model=gpt-5-4" -d "prompt=Summarize this" \
  -d "token_policy=high"
```

`callAI` success now includes the policy that served it:

```json
{"status": "success", "model": "gpt-5-4", "token_policy": "high", "content": "...", "usage": {...}}
```

A refusal is HTTP **400**:

```json
// callAI
{"error": "token_policy 'standard' is below this context's minimum 'high'. ...", "type": "TOKEN_POLICY_ERROR"}
// messages (Claude format)
{"error": {"type": "token_policy_error", "message": "..."}, "type": "error"}
```

`"error"` is deliberately the first key: REDCap's `RestUtility::sendResponse()` re-wraps any non-200 JSON
body that doesn't start with `{"error"` into `{"error": "<the body as a string>"}`.

## Logging

`SecureChatLog`, `SecureChatLogError` and `CappyChatTurn` rows now carry `token_policy` (`standard` /
`high`). The key itself is never logged. The dev-only request log (`logFullRequest`) masks whichever key the
call used.

## Production rollout (redcap.stanford.edu)

Order matters: add the high-threshold models before any project's minimum is raised, or that project's
calls will be refused.

1. Deploy the new SecureChatAI version through the usual EM deployment.
2. **Control Center → External Modules → SecureChatAI → Configure.**
3. In **AI Hub Token (standard)**, paste the current AI Hub key (the one every existing row uses today).
4. In **AI Hub Token (high-threshold)**, paste the new high-threshold key.
5. Leave every existing model row as it is: its **Token Policy** shows `standard` and it keeps its own **API Token**.
   (Optional, later: empty each row's API Token so it uses the system token, which makes rotating the key a
   single edit.)
6. For each high-threshold model, click **+** on the AI Model API Settings group to add a row:
   - **Model Alias** and **Model ID**: as AI Hub names them for the high-threshold deployment
   - **API Endpoint**: the high-threshold deployment's URL
   - **Token Policy**: `high-threshold`
   - **API Token**: leave empty
   - **API Key Variable Name**: `api-key`; **Model input Variable Name**: `messages`
7. Click **Save**. If it refuses, the message names the row and the token that's missing.
8. **Control Center → SecureChatAI AI Model Smoke Tests**, click **High-threshold**: every high row should
   show ✅. Then click **Standard** to confirm the existing models are unaffected.
9. For each project whose data must stay on high-threshold models: open the project → **External Modules** →
   SecureChatAI **Configure** → **Minimum Token Policy** = `high-threshold` → **Save**. (SecureChatAI has to be
   enabled on that project.)
10. Tell the owners of the calling EMs that `token_policy: 'high'` is available, and that `TOKEN_POLICY_ERROR`
    means a call was refused without being sent.

**Rollback:** set each project's Minimum Token Policy back to empty and remove the high-threshold rows. The
standard rows never changed, so existing traffic isn't affected.

## Testing

| What | Command | Result 2026-10-12 |
| --- | --- | --- |
| Unit: `TokenPolicy` (normalize, resolve/minimum, registry, save validation) | `docker exec redcap_2023_1_web php /var/www/html/modules-local/secure_chat_ai_v9.9.9/tests/TokenPolicyTest.php` | 44/44, exit 0 |
| E2E routing against a mock AI Hub: callAI, project minimum, nested calls, agent loop + sub-agent, compaction, upstream hygiene, logging, both REST actions | `tests/e2e/routing.php` (below) | 36/36, exit 0 |
| E2E UI (Playwright, desktop 1400×950 + iPhone 13): settings dialog, refused/valid save, project minimum, smoke-test page and its filter | `tests/e2e/token-policy.js` (below) | 40/40, exit 0 |

The E2E tests swap the **local** SecureChatAI system settings for a test configuration whose four model rows
point at `tests/e2e/mock_aihub.php` with fake keys. The mock runs only under PHP's built-in server inside the
web container, records which key and path each request used, and never logs message text.
`fixture.php setup` backs up the real settings first and refuses to run if a backup already exists;
`teardown` restores them exactly. It also creates a throwaway admin (`e2e_sca_admin`) with a module-API token
on PID 270 and enables the module there; teardown removes both.

```bash
W=/var/www/html/modules-local/secure_chat_ai_v9.9.9
docker exec redcap_2023_1_web php $W/tests/e2e/fixture.php setup
docker exec -d redcap_2023_1_web php -S 127.0.0.1:8099 $W/tests/e2e/mock_aihub.php
docker exec redcap_2023_1_web php $W/tests/e2e/routing.php
NODE_PATH=../proj_mica_v9.9.9/node_modules node tests/e2e/token-policy.js   # Playwright from MICA's install
docker exec redcap_2023_1_web php $W/tests/e2e/fixture.php teardown
docker exec redcap_2023_1_web pkill -f "php -S 127.0.0.1:8099"
```

Playwright isn't installed in this EM; the UI test borrows `proj_mica_v9.9.9/node_modules`. The UI test
writes screenshots to `tests/e2e/shots/` (git-ignored).

## Smoke-test page changes

`pages/tests.php` runs each model under its own policy, shows a Policy badge, and has an **All / Standard /
High-threshold** filter (`?policy=`). It was also made phone-safe. Before, its table made the page 598px wide on
a 390px phone; now the tables scroll inside their own box, Timing and Tokens are hidden below tablet width,
the policy badge moves under the model name, and long output wraps.
