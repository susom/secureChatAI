# SecureChatAI Copilot Instructions

## Project Overview

**SecureChatAI** is a REDCap External Module (EM) that serves as a unified, policy-controlled gateway to Stanford-approved AI models. It acts as the foundational AI runtime layer for the REDCap AI ecosystem, enabling other modules (Chatbot EM, RAG EM, backend services) to access multiple LLM providers through a single, auditable interface.

**Key responsibility**: Model-agnostic service layer, not chatbot UI or RAG engine. Those live in separate EMs.

## Architecture Overview

### Core Components

- **`SecureChatAI.php`** - Main module class; handles model routing, parameter filtering, logging, and agent mode orchestration.
- **`classes/Models/`** - Model-specific request adapters:
  - `BaseModelRequest.php` - Common interface and parameter handling
  - `GPTModelRequest.php` - OpenAI GPT models
  - `GeminiModelRequest.php` - Google Gemini
  - `ClaudeModelRequest.php` - Anthropic Claude
  - `WhisperModelRequest.php` - Speech-to-text
  - `GPT4oMiniTTSModelRequest.php` - Text-to-speech
  - `GenericModelRequest.php` - Fallback for unspecialized models
  - `MetaModelRequest.php` - Meta Llama models
- **`classes/SecureChatLog.php`** - Atomic logging with session tracking and rehydration
- **`classes/ASEMLO.php`** - Agent simulation and orchestration (agentic workflows)
- **`emLoggerTrait.php`** - Debug logging utility
- **`pages/tests.php`** - Model smoke test UI (admin panel)
- **`pages/Visualization.php`** - Log visualization dashboard (admin panel)

### Request Flow

1. Caller invokes `$module->callAI($model, $params, $project_id)`
2. Module applies defaults and filters unsupported parameters per model
3. Selects appropriate model adapter via `ModelInterface`
4. Executes request via Guzzle HTTP client
5. Normalizes response to common format
6. Logs interaction atomically with session ID (if provided)
7. Returns normalized response

### Agent Mode (Agentic Workflows)

When `agent_mode = true` in request params:
- Auto-selects JSON schema-capable model if requested model doesn't support tool use
- Injects router system prompt and project-scoped tool catalog
- Enforces structured JSON response schema
- Executes tool loop with safety limits:
  - Max steps (default 8), max tool calls (default 15), timeout (default 120s)
  - Tool result capping (default 8000 chars)
  - Loop detection to prevent tool infinite loops
- Returns response with `tools_used` array if tools executed
- Graceful fallback to plain text if JSON schema fails

## Key Conventions

### Model Aliases and Configuration

- Models are configured entirely via **system settings** (never hardcoded)
- Each model has: `model-alias`, `model-id`, `api-url`, `api-token`, `api-key-var`, `api-input-var`
- Special model types detected by name patterns:
  - Whisper: name contains `whisper` → speech-to-text
  - TTS: name contains `tts` → text-to-speech
  - Embeddings: name contains `ada` or `embed` → embeddings
  - Otherwise: chat model

### Response Normalization

All models return a normalized response array:
```php
[
    'content' => 'Model response text',
    'role' => 'assistant',
    'model' => 'gpt-4o',
    'usage' => [
        'prompt_tokens' => N,
        'completion_tokens' => M,
        'total_tokens' => N+M
    ],
    'tools_used' => [ /* only in agent mode */ ]
]
```

### Parameter Filtering

Each model adapter filters incoming params to only valid fields for that model. E.g., `temperature` is invalid for embeddings; `language` is specific to Whisper.

### Logging & Session Tracking

- Each API call creates **one atomic log entry** (not conversation history)
- Logs store: `project_id`, `session_id`, `model`, `timestamp`, `user_message`, `assistant_response`, `usage`
- Session reconstruction via `SecureChatLog::rehydrateSession($module, $session_id, $project_id)`
- System prompts and RAG context are **excluded from logs** (synthesized into responses)
- Logs accessible via:
  - `$module->getSecureChatLogs($offset)` - All logs paginated
  - `$module->getSecureChatLogsBySession($session_id, $project_id)` - Session-specific logs
  - Visualization page: `/pages/Visualization.php` (admin panel, includes modal for session replay)

### Token Budget Calculation

Uses **Yethee\Tiktoken** library for token counting:
- Dynamic max tokens computed in `computeDynamicMaxTokens()` based on prompt length
- Agent mode allocates 4000 tokens (vs 800 for regular calls)
- Per-model token buffers prevent truncation

### Agent Tools Definition

Tools defined in system setting `agent_tool_registry` as JSON:
- Must have: `name`, `description`, `endpoint`, `parameters`
- Endpoint types: `module_api`, `redcap_api`, or `http`
- Validated at load time; malformed tools logged and excluded
- Project-scoped: cannot access other projects
- Parameters validated strictly before tool execution

## Testing

### Running Model Tests

Manual smoke tests available in admin panel (`pages/tests.php`):
1. Go to Control Center → SecureChatAI AI Model Smoke Tests
2. Tests each configured model (GPT, Gemini, Claude, Whisper, TTS, embeddings, etc.)
3. Detects model type automatically (chat, stt, tts, embedding)
4. Reports timing, token usage, and any errors

**Note**: Tests require `for_test.mp3` in module root for Whisper tests.

### Integration Testing

When modifying core request/response flow:
- Verify normalized response structure matches expected schema
- Test parameter filtering doesn't drop valid params or pass invalid ones
- Ensure session logging captures correct data
- For agent mode changes, test tool execution loop with mock tools

## Configuration

All configuration via **system settings**:

- **Model Registry**: `api-settings` (repeatable, per-model API endpoints, tokens, aliases)
- **Defaults**: `gpt-temperature`, `gpt-top-p`, `gpt-frequency-penalty`, `gpt-presence-penalty`, `gpt-max-tokens`, `reasoning-effort`
- **Whisper**: `whisper-language`, `whisper-temperature`, `whisper-top-p`, `whisper-n`, etc.
- **Agent Mode**: `enable_agent_mode`, `agent_max_steps`, `agent_max_tools_per_run`, `agent_timeout_seconds`, `agent_max_tool_result_chars`, `agent_router_system_prompt`, `agent_tool_registry`
- **DNS/Network**: `apim_dns_override_ip`, `guzzle-timeout`
- **Debug**: `enable-system-debug-logging`

## Dependencies

- **yethee/tiktoken** (^1.1) - For token counting
- **Composer** - PHP dependency manager (`composer.json`)

## API Endpoints

### REDCap EM API

External services can call via REDCap API:
```bash
curl -X POST "https://redcap.stanford.edu/api/" \
  -F "token=YOUR_API_TOKEN" \
  -F "content=externalModule" \
  -F "prefix=secure_chat_ai" \
  -F "action=callAI" \
  -F "model=gpt-4o" \
  -F "prompt=..." \
  -F "format=json"
```

**Supported actions**: `callAI`, `messages` (Claude Messages API compatibility)

## Important Notes

- **No test suite**: Tests are manual (smoke test UI) only. When making changes, manually verify via admin tests page.
- **VPN Required**: Module requires VPN connection to SOM/SHC for API access.
- **Project Scoping**: Agent tools are project-scoped; they cannot leak data between projects.
- **Backward Compatibility**: Non-agent calls are unaffected by agent mode changes.
- **Default Fallback**: If a requested model doesn't support required features (e.g., JSON schema for agents), module auto-selects compatible model (e.g., o3-mini).
