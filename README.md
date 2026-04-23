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
  Controlled, project-scoped tool invocation with strict limits.

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
- Endpoint-specific fields:
  - For `redcap_api`: `redcap.prefix` and `redcap.action`
  - For `module_api`: `module.action`

Malformed tools are rejected with error logging and will not be available to agents.

---

## External API Access (REDCap EM API)

SecureChatAI exposes a **REDCap External Module API endpoint** for backend services.

### Supported Action

- `callAI`

### Example cURL

```bash
curl -X POST "https://redcap.stanford.edu/api/" \
  -F "token=YOUR_API_TOKEN" \
  -F "content=externalModule" \
  -F "prefix=secure_chat_ai" \
  -F "action=callAI" \
  -F "prompt=Summarize this RAG pipeline" \
  -F "model=deepseek" \
  -F "format=json"
```

---

## Intended Use Cases

- RAG ingestion pipelines
- Scheduled summarization jobs
- Backend AI services running outside REDCap
- Cloud Run / App Engine workers

---

## Configuration Overview

Configured entirely via **System Settings**:

- **Model registry** (API endpoints, tokens, aliases)
  - Each model entry specifies: alias, model ID, endpoint URL, API token, auth header name, and input variable name
  - **AIHub models** use `api-key` as the auth header name and the AIHub subscription primary key as the API token
  - **Legacy APIM models** use `subscription-key` or `Ocp-Apim-Subscription-Key` as the auth header name
  - Endpoint URLs include the full path (model ID / deployment ID baked into the URL for AIHub)
- **Default model selection**
- **Parameter defaults** (temperature, top_p, max_tokens, etc.)
- **Agent mode controls**:
  - `enable_agent_mode` - Global toggle for agentic workflows
  - `agent_max_steps` - Max reasoning iterations per request (default: 8)
  - `agent_max_tools_per_run` - Max total tool calls before termination (default: 15)
  - `agent_timeout_seconds` - Max wall-clock execution time (default: 120)
  - `agent_max_tool_result_chars` - Max chars per tool result before truncation (default: 8000)
  - `agent_router_system_prompt` - Defines agent routing behavior
  - `agent_tool_em_prefixes` - Comma-separated EM prefixes that provide agent tools
- **Logging and debug flags**

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
