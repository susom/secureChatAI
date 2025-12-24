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

When explicitly enabled:

1. Caller sets `agent_mode = true`
2. SecureChatAI injects:
   - A router system prompt
   - A project-scoped tool catalog
3. The model may:
   - Ask for clarification
   - Call a registered tool
   - Produce a final answer
4. Tool calls are:
   - Strictly validated
   - Project-scoped
   - Step-limited
5. Tool results are injected back as **system context**
6. The loop exits with a final response or error

Agent mode is:
- Opt-in
- Globally toggleable
- Disabled by default

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
    ]
]
```

Embeddings return a numeric vector array.

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

- Model registry (API endpoints, tokens, aliases)
- Default model selection
- Parameter defaults
- Agent mode controls
- Tool registry
- Logging and debug flags

No code changes are required to add or modify models.

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
