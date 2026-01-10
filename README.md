# SecureChatAI External Module (EM)

SecureChatAI is a **service-oriented REDCap External Module** that provides a unified, policy-controlled gateway to Stanford-approved AI models.

It acts as the **foundational AI runtime layer** for the REDCap AI ecosystem, enabling chatbots, RAG pipelines, background jobs, and agentic workflows to access multiple LLM providers through a single, auditable interface.

**Requires VPN connection to SOM / SHC.**

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

- **Centralized logging**  
  Requests, responses, errors, and token usage are logged.

- **Optional agentic workflows**  
  Controlled, project-scoped tool invocation with strict limits.

- **REDCap EM API support**  
  Secure external access without exposing raw model keys.

---

## Supported Model Categories

### Chat / Completion Models
- `gpt-4o`
- `gpt-4.1`
- `o1`, `o3-mini`
- `claude`
- `gemini20flash`, `gemini25pro`
- `llama3370b`, `llama-Maverick`
- `deepseek`

### Embeddings
- `ada-002`

### Audio / Speech
- `whisper`
- `gpt-4o-tts`

---

## Architecture Overview

### Runtime Call Flow

1. **Caller (EM, UI, or API)** prepares messages and parameters.
2. **SecureChatAI**:
   - Applies defaults
   - Filters unsupported parameters
   - Selects the correct model adapter
3. **Model request is executed** via Stanford-approved endpoint.
4. **Response is normalized** into a common format.
5. **Usage and metadata are logged** for audit and monitoring.
6. **Normalized response is returned** to the caller.

---

### Agentic Workflow (Optional)

#### Tiktoken-based Dynamic Max Tokens
Starting with the 2026-01-12 release, SecureChatAI now calculates the prompt token count using the Yethee\Tiktoken library to automatically scale max tokens for each model. This ensures:
- Agentic flows have enough headroom to avoid truncation.
- Non-agent calls also benefit from higher overall token limits.
- We minimize wasted tokens by only allocating as many as needed.

Configuration details:
- See computeDynamicMaxTokens() in SecureChatAI.php for per-model token buffers.
- The default fallback is up to 16k tokens or more, depending on the model.
- Agent mode also bumps maximum tokens to ensure multi-step tool usage remains stable.


When explicitly enabled:

1. Caller sets `agent_mode = true`
2. SecureChatAI:
   - Forces a JSON schema-capable model (gpt-4.1, o1, o3-mini, llama3370b)
   - Auto-switches to `o1` if the requested model doesn't support structured output
   - Injects a router system prompt
   - Injects a project-scoped tool catalog
   - Enforces strict JSON schema for agent responses
3. The model must respond with:
   - `{"tool_call": {"name": "...", "arguments": {...}}}` for tool execution
   - `{"final_answer": "..."}` for all other responses (including clarifications)
4. Tool calls are:
   - Strictly validated against registered tools
   - Project-scoped (cannot access other projects)
   - Step-limited (default: 8 iterations max)
   - Tool-count limited (default: 15 total tool calls max)
   - Time-limited (default: 120 second timeout)
5. Tool results are injected back as **user context** (standard practice)
6. The loop exits with a final response or error

Agent mode is:
- Opt-in (requires `enable_agent_mode` system setting)
- Globally toggleable
- Disabled by default
- Fully backward compatible (non-agent calls unchanged)

#### Agent Mode Hardening (2026-01-08)

Recent improvements ensure production-grade reliability:

**Response Handling:**
- **JSON Schema Enforcement**: Models must return structured JSON (fallback to plain text if needed)
- **Control Character Stripping**: Removes formatting characters that break JSON parsing
- **HTML Entity Decoding**: Handles REDCap-encoded responses automatically
- **Emergency Backstop Regex**: Extracts answers even from truncated/malformed JSON
- **Model Auto-Selection**: Non-schema models auto-switch to compatible models
- **Graceful Degradation**: Plain text responses work even when JSON schema fails

**Safety Limits:**
- **Tool Result Size Capping**: Large tool results truncated intelligently (default: 8000 chars)
- **Tool Definition Validation**: Malformed tool configs rejected at load time with error logging
- **Tool Loop Detection**: Prevents infinite loops (max 3 calls to same tool+args in last 5 steps)
- **Increased Token Budget**: Agent mode uses 4000 tokens (vs 800) to prevent mid-response truncation

**Observability:**
- **Tool Usage Metadata**: Responses include which tools were used (for UI indicators)
- **Enhanced Logging**: Step-by-step debug traces for agent execution
- **Friendly Error Messages**: All agent errors return polite user-facing text

This means agent mode can be enabled in production without breaking existing chat functionality or leaking JSON/errors to users.

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
        ['name' => 'records.getUserIdByFullName', 'arguments' => ['full_name' => 'John Doe'], 'step' => 1],
        ['name' => 'records.getClinicalData', 'arguments' => ['record_id' => '12345'], 'step' => 2]
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

## Agent Tool Integration

Tools are defined via **system settings** and are:

- Project-scoped
- Explicitly registered
- Argument-validated
- Executed via:
  - Module API calls, or
  - REDCap API calls

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
- **Default model selection**
- **Parameter defaults** (temperature, top_p, max_tokens, etc.)
- **Agent mode controls**:
  - `enable_agent_mode` - Global toggle for agentic workflows
  - `agent_max_steps` - Max reasoning iterations per request (default: 8)
  - `agent_max_tools_per_run` - Max total tool calls before termination (default: 15)
  - `agent_timeout_seconds` - Max wall-clock execution time (default: 120)
  - `agent_max_tool_result_chars` - Max chars per tool result before truncation (default: 8000)
  - `agent_router_system_prompt` - Defines agent routing behavior
  - `agent_tool_registry` - JSON-defined, project-scoped tool catalog
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
