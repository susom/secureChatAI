# SecureChatAI External Module (EM)

SecureChatAI is a **service-oriented REDCap External Module** that provides a unified, policy-controlled gateway to Stanford-approved AI models.

It acts as the **foundational AI runtime layer** for the REDCap AI ecosystem, enabling chatbots, RAG pipelines, background jobs, and agentic workflows to access multiple LLM providers through a single, auditable interface.

**Requires network access to Stanford AI endpoints** (via AIHub gateway or legacy APIM; VPN may be required depending on environment).

---

## What This Module Is (and Is Not)

### What It Is

- A **model-agnostic AI service layer**
- A **centralized policy and logging boundary**
- A **runtime for both single-shot and agentic LLM calls**
- A **secure bridge** between REDCap projects and Stanford AI endpoints

### What It Is Not

- Not a chatbot UI
- Not a RAG engine
- Not a workflow engine
- Not model-specific business logic

Those responsibilities live in **other EMs** (e.g., Chatbot EM, REDCap RAG EM).

---

## SecureChatAI in the REDCap AI Ecosystem

SecureChatAI is intentionally designed as a **shared dependency**:

- **Chatbot EM (Cappy)**  
  → Uses SecureChatAI for all LLM calls and optional agent routing

- **Agent Tool EMs** (`redcap_agent_record_tools`, `redcap_agent_rexi_tools`, etc.)  
  → Discovered and invoked by SecureChatAI's agent loop via EM-to-EM direct PHP calls

- **REDCap RAG EM**  
  → Uses SecureChatAI for embeddings and downstream generation

- **Backend services / cron jobs**  
  → Use SecureChatAI via the REDCap EM API endpoint

This separation ensures:
- One place to manage credentials
- One place to enforce policy
- One place to log and audit AI usage

---

## Core Features

- **Unified model interface**  
  Call GPT, Gemini, Claude, Llama, DeepSeek, Whisper, etc. via one method.

- **Model-aware parameter filtering**  
  Only valid parameters are sent to each model.

- **Normalized responses**  
  All models return a consistent structure.

- **Atomic logging with session tracking**  
  Each AI interaction logged individually with session IDs for conversation reconstruction. System prompts and RAG are excluded (synthesized into responses).

- **Optional agentic workflows**  
  Controlled, project-scoped tool invocation with 7-phase execution pipeline, pre/post hooks, sub-agents, and safety limits.

- **Conversation compaction**  
  Tiktoken-based token estimation with automatic summarization when conversations approach context limits.

- **Memory engine**  
  Persistent entity memory with rolling summary + changelog format for cross-session continuity.

- **REDCap EM API support**  
  Secure external access without exposing raw model keys.

---

## Supported Model Categories

### Chat / Completion Models

**Via AIHub — Azure AI Foundry:**
- `chat`, `gpt-4-1-nano`, `gpt-5-nano`, `grok-3-mini`, `llama-4-scout`, `o4-mini`

**Via AIHub — AWS Bedrock:**
- `claude-sonnet-3.5`, `claude-sonnet-3.7`, `claude-haiku-4.5`, `claude-opus-4`, `claude-sonnet-4`

**Via AIHub — Google Vertex AI:**
- `gemini-flash-lite`

**Via legacy APIM (or AIHub Azure AI Foundry):**
- `gpt-4o`, `gpt-4.1`, `o1`, `o3-mini`, `gpt-5`
- `llama3370b`, `llama-Maverick`, `deepseek`
- `claude` (legacy APIM proxy — use Bedrock aliases for AIHub)
- `gemini20flash`, `gemini25pro`

### Embeddings
- `ada-002`, `text-embedding-3-small`

### Audio / Speech
- `whisper`
- `gpt-4o-tts`, `tts`

---

## Architecture Overview

### Runtime Call Flow

1. **Caller (EM, UI, or API)** prepares messages and parameters.
2. **SecureChatAI**:
   - Applies defaults
   - Filters unsupported parameters
   - Selects the correct model adapter
3. **Model request is executed** via Stanford AIHub gateway (AWS Bedrock, Google Vertex AI, or Azure AI Foundry) or legacy APIM endpoint.
4. **Response is normalized** into a common format.
5. **Usage and metadata are logged** for audit and monitoring.
6. **Normalized response is returned** to the caller.

---

### Agentic Workflow (Optional)

When a caller sets `agent_mode = true`, SecureChatAI becomes an agent orchestrator:

1. **Tool Discovery:** Loads tool definitions from all EMs matching the configured **Agent Tool EM Prefixes** (system or project level). Each EM's `agent-tool-definitions` from config.json are read and presented to the LLM as available tools.

2. **Agent Loop:**
   - Injects a router system prompt and project-scoped tool catalog
   - Forces a JSON schema-capable model (auto-switches if the requested model doesn't support structured output)
   - The LLM responds with either:
     - `{"tool_call": {"name": "...", "arguments": {...}}}` → execute a tool
     - `{"final_answer": "..."}` → return the response to the user
   - Tool calls are executed via **EM-to-EM direct PHP** (`getModuleInstance()->redcap_module_api()`) — no HTTP, no API tokens
   - Tool results are injected back as context and the loop continues
   - Exits with a final response, or when safety limits are hit

3. **Safety Limits:**
   - Step-limited (default: 8 iterations max)
   - Tool-count limited (default: 15 total tool calls max)
   - Time-limited (default: 120 second timeout)
   - Tool loop detection (max 3 calls to same tool+args in last 5 steps)
   - Tool result size capping (default: 8000 chars)
   - Tool definition validation at load time

4. **Resilience:**
   - JSON schema enforcement with fallback to plain text
   - Control character stripping and HTML entity decoding for REDCap responses
   - Emergency backstop regex for truncated/malformed JSON
   - Graceful degradation — plain text responses work even when JSON schema fails
   - All agent errors return polite user-facing text (never leaks JSON/stack traces)

5. **Observability:**
   - `tools_used` array in response metadata (for UI indicators)
   - Step-by-step debug traces via emLogger
   - Dynamic max token calculation (Tiktoken-based) to prevent mid-response truncation

Agent mode is:
- **Opt-in** (requires `enable_agent_mode` system setting)
- Globally toggleable
- Disabled by default
- Fully backward compatible (non-agent calls unchanged)

### 7-Phase Tool Execution Pipeline

Every tool call goes through a structured pipeline (`ToolPipeline`), not a raw function call:

| Phase | What Happens |
|-------|-------------|
| 1. **Lookup** | Find tool config in the registry by name |
| 2. **Parse** | Validate required parameters exist |
| 3. **Validate** | Tool-specific validation (types, ranges) |
| 4. **PreHooks** | Run registered `PreToolUseHook` list (system + project) |
| 5. **Permits** | If any hook returned "deny", abort before execution |
| 6. **Execute** | Call the tool via EM-to-EM (`redcap_module_api`) |
| 7. **PostHooks** | Run registered `PostToolUseHook` list (logging, transforms) |

Errors at any phase return a `ToolResult::fail()` — the pipeline never throws.

Pre/post hooks are configurable at both system and project level, and merge automatically. This lets you add audit logging, permission checks, or result transforms without modifying tool EMs.

### Sub-Agents

The agent can spawn independent sub-agents via the built-in `spawnAgent` tool:

- Sub-agent runs a fresh agent loop with its own context
- Scoped to a subset of tools (or all tools)
- Reduced safety limits to prevent runaway token usage
- Configurable depth limit (`agent_max_subagent_depth`, default: 1)
- Useful for decomposing complex tasks (e.g., "check 3 projects" → spawn one sub-agent per project)

### Conversation Compaction

Long conversations can be compacted server-side via `runCompaction()`:

- Tiktoken-based token estimation against the model's context window
- When conversation exceeds 80% of context, older messages are summarized into a single message
- Keeps the N most recent messages intact (default: 6)
- Returns before/after stats (message count, token count, reduction %)
- Caller EMs can call this proactively or let SecureChatAI handle it automatically

### Memory Engine

`MemoryEngine` provides persistent entity memory across conversations:

- Maintains a "living memory document" (rolling summary + changelog)
- Significance gate — skips trivial deltas (greetings, single-word responses)
- LLM-powered merge: new conversation context is merged into the existing summary
- Extracted as a reusable class for any EM that needs cross-session memory

### Public Helper Methods

| Method | Description |
|--------|-------------|
| `callAI($model, $params, $pid)` | Primary entry point for all model calls |
| `getToolCatalogForProject($pid)` | Returns all discovered tool definitions for a project |
| `getAvailableModels()` | Returns list of configured/available models |
| `getModelContextWindow($model)` | Returns context window size in tokens |
| `runCompaction($messages, $model)` | Server-side conversation compaction |

---

## Basic Usage (Internal EM Calls)

```php
$em = \ExternalModules\ExternalModules::getModuleInstance("secure_chat_ai");

$params = [
    'messages' => [
        ['role' => 'user', 'content' => 'Hello from SecureChatAI']
    ],
    'temperature' => 0.7,
    'max_tokens' => 512
];

$response = $em->callAI("gpt-4o", $params, $project_id);

```

## Response Format (Normalized)

```php
[
    'content' => 'Model response text',
    'role' => 'assistant',
    'model' => 'gpt-4o',
    'usage' => [
        'prompt_tokens' => 42,
        'completion_tokens' => 128,
        'total_tokens' => 170
    ],
    'tools_used' => [ // Only present in agent mode responses
        ['name' => 'projects.search', 'arguments' => ['query' => 'intake'], 'step' => 1],
        ['name' => 'records.get', 'arguments' => ['pid' => 42, 'record_id' => '1001'], 'step' => 2]
    ]
]
```

**Notes:**
- Embeddings return a numeric vector array.
- `tools_used` array is only present when agent mode executed tools successfully.

---

## Public Methods

### `callAI(string $model, array $params, ?int $project_id = null)`

Primary entry point for all model calls.

- Handles retries
- Applies model-specific parameter filtering
- Routes to agent mode if requested

---

### `extractResponseText(array $response)`

Returns plain text from a normalized response.

---

### `extractUsageTokens(array $response)`

Returns token usage metadata.

---

### `extractMetaData(array $response)`

Returns model-level metadata (ID, model name, usage).

---

### `getSecureChatLogs(int $offset)`

Fetches logged interactions for admin inspection.

---

### `getSecureChatLogsBySession(string $session_id, ?int $project_id)`

Fetches all logs for a specific session ID. Useful for retrieving conversation history.

---

### Logging & Session Management

All AI interactions are logged atomically (per turn) with the following structure:

```json
{
  "project_id": 123,
  "session_id": "abc123",
  "model": "gpt-4o",
  "timestamp": "2026-02-12 10:30:00",
  "user_message": "What's the weather?",
  "assistant_response": "It's sunny!",
  "usage": {
    "prompt_tokens": 150,
    "completion_tokens": 10,
    "total_tokens": 160
  }
}
```

**Key features:**
- **Atomic logging**: Each API call creates one log entry (not cumulative conversation history)
- **Session tracking**: Pass `session_id` in request params to group related turns
- **EAV parameters**: `session_id` and `model` are stored as separate rows in `redcap_external_modules_log_parameters` for JOIN-based querying (not actual columns on the log table, which is core REDCap schema)
- **No bloat**: System prompts and RAG context are NOT logged (synthesized into responses)
- **Token tracking**: Usage stats included for cost monitoring

**To track sessions**, pass `session_id` in your request:
```php
$params = [
    'messages' => [...],
    'session_id' => 'unique-session-id'  // Enables session reconstruction
];
$module->callAI($model, $params, $project_id);
```

**To rehydrate a conversation** from logs:
```php
$session = SecureChatLog::rehydrateSession($module, 'abc123', $project_id);
// Returns: ['session_id' => '...', 'messages' => [...], 'metadata' => [...], 'stats' => [...]]
```

**Session viewer**: The Visualization page (admin logs table) supports clicking any Session ID to open a modal that reconstructs the full conversation in chronological chat format with metadata (duration, tokens, models used). Works for both new logs (fast EAV parameter lookup) and legacy logs (JSON blob fallback).

---

## Agent Tool Integration

Tools are auto-discovered from enabled EMs whose prefix is listed in `agent_tool_em_prefixes` (system setting) or `project_agent_tool_em_prefixes` (project setting). SecureChatAI reads each EM's `agent-tool-definitions` from config.json and invokes them via direct PHP calls (EM-to-EM, no HTTP).

Tools are:

- Project-scoped (each project declares which tool EM prefixes it can access)
- Auto-discovered from config.json
- Argument-validated at load time
- Executed via direct EM-to-EM PHP calls (`module_api`) or optionally via REDCap API (`redcap_api`)

SecureChatAI does **not** allow arbitrary or ad-hoc tool execution.

### Tool Definition Requirements

All tool definitions are validated at load time. Required fields:

- `name` - Tool identifier (alphanumeric + `_` `.` only, must start with letter)
- `description` - Clear description of tool purpose
- `endpoint` - Must be `module_api`, `redcap_api`, or `http`
- `parameters` - Must be an object with `type: "object"`
- Endpoint-specific routing fields:
  - For `module_api`: `module.action` (the EM action string to call)
  - For `redcap_api`: `redcap.prefix` and `redcap.action`

**Important:** Tool EM authors do **not** write `endpoint`, `module.action`, or `redcap.prefix` fields. When tools are auto-discovered from a tool EM's `agent-tool-definitions`, SecureChatAI fills these in automatically:

```php
// From discoverEmToolDefinitions() — auto-filled for every discovered tool:
'endpoint'    => 'module_api',
'module'      => ['prefix' => $prefix, 'action' => $def['api-action']],
```

Tool EM authors only need: `name`, `description`, `parameters`, and `api-action` (which maps to the EM's `api-actions` key). See [REDCapAgentToolTemplate](https://github.com/susom/REDCapAgentToolTemplate) for a working example.

Malformed tools are rejected with error logging and will not be available to agents.

---

## External API Access (REDCap EM API)

SecureChatAI exposes a **REDCap External Module API endpoint** for backend services and other EMs.

### Supported Actions

| Action | Description |
|--------|-------------|
| `callAI` | Simple prompt → response. Wraps a prompt into messages and calls the LLM (no agent mode). |
| `messages` | Claude Messages API-compatible endpoint. Accepts `{model, messages, max_tokens, temperature, system, top_p, stop}`. Returns Claude-format response. |
| `getSession` | Rehydrate a conversation session. Accepts `{session_id, project_id}`. Returns full session with messages and metadata. |

### Example: `callAI` via cURL

```bash
curl -X POST "https://redcap.stanford.edu/api/" \
  -d "token=YOUR_API_TOKEN" \
  -d "content=externalModule" \
  -d "prefix=secure_chat_ai" \
  -d "action=callAI" \
  -d "prompt=Summarize this RAG pipeline" \
  -d "model=deepseek"
```

**Note:** The `callAI` action is a simple prompt-in/response-out endpoint — it does **not** support `agent_mode`. For agentic workflows, use SecureChatAI's `callAI()` PHP method directly from another EM with `agent_mode = true` in the params.

---

## Intended Use Cases

- **Agentic workflows** — LLM-driven tool use within REDCap (records, reports, escalation)
- **Chatbot backends** — Cappy and other conversational UIs
- **RAG pipelines** — Embedding generation and downstream summarization
- **Standalone task agents** — Backend scripts and cron jobs calling `callAI()` with `agent_mode`
- **External services** — Backend AI services accessing SecureChatAI via the REDCap EM API endpoint

---

## Configuration Overview

All settings are configured via REDCap's External Modules system settings page.

### System Settings

**Model Registry** (repeating sub-settings under `api-settings`):
- `model-alias` — Internal alias used in `callAI()` (e.g., `gpt-4o`, `claude`, `deepseek`)
- `model-id` — Provider's model ID or deployment name
- `api-url` — Full endpoint URL (model/deployment ID baked into the URL for AIHub)
- `api-token` — API key or subscription key
- `api-key-var` — Auth header name (`api-key` for AIHub, `Ocp-Apim-Subscription-Key` for legacy APIM)
- `api-input-var` — Input variable name for the request body
- `default-model` — Checkbox to set this entry as the default model

**Parameter Defaults:**
- `gpt-temperature`, `gpt-top-p`, `gpt-frequency-penalty`, `gpt-presence-penalty`, `gpt-max-tokens`
- `reasoning-effort` — For reasoning models (o1, o3-mini) — `low`, `medium`, `high`

**Agent Mode Controls:**
- `enable_agent_mode` — Global toggle for agentic workflows (disabled by default)
- `agent_router_system_prompt` — System prompt that defines agent routing behavior
- `agent_tool_em_prefixes` — Comma-separated EM prefixes that provide agent tools
- `agent_max_steps` — Max reasoning iterations per request (default: 8)
- `agent_max_tools_per_run` — Max total tool calls before termination (default: 15)
- `agent_timeout_seconds` — Max wall-clock execution time (default: 120)
- `agent_max_subagent_depth` — How many levels deep sub-agents can spawn (default: 1)
- `agent_max_clarifications` — Max clarification requests before forcing an answer
- `agent_max_tool_result_chars` — Max chars per tool result before truncation (default: 8000; not yet in config.json UI — set via EM settings table)
- `pre_tool_use_hooks` — Comma-separated hook class names run before every tool call (system-wide)
- `post_tool_use_hooks` — Comma-separated hook class names run after every tool call (system-wide)
- `agent_tools_redcap_api_url` — REDCap API URL for `redcap_api` endpoint tools (legacy)
- `agent_tools_project_api_key` — API token for `redcap_api` endpoint tools (legacy)

**Infrastructure:**
- `apim_dns_override_ip` — Override DNS resolution for APIM endpoints (useful in restricted networks)
- `enable-system-debug-logging` — Toggle verbose emLogger debug output

**Whisper (Audio) Settings:**
- `whisper-language`, `whisper-temperature`, `whisper-top-p`, `whisper-n`
- `whisper-logprobs`, `whisper-max-alternate-transcriptions`
- `whisper-compression-rate`, `whisper-sample-rate`, `whisper-condition-on-previous-text`

### Project Settings

- `project_agent_tool_em_prefixes` — Project-level override for which tool EM prefixes are available (takes priority over system setting)
- `project_pre_tool_use_hooks` / `project_post_tool_use_hooks` — Project-level hook overrides (merge with system hooks)
- `enable-project-usage` — Enable/disable SecureChatAI for this project
- `project-api-key` — Project-specific API key for external access
- `project-monthly-token-limit` / `project-monthly-cost-limit` — Usage caps per project

No code changes are required to add or modify models or tools.

---

## Security Notes

- Requires REDCap authentication or API token
- Project-scoped access enforced
- All interactions are logged
- No PHI is introduced unless present in input
- Agent execution is constrained and auditable

---

## Summary

SecureChatAI is the **foundation layer** for AI inside REDCap:

- One gateway
- Many models
- Consistent behavior
- Controlled agentic expansion

Other EMs build **on top of it**, not alongside it.
