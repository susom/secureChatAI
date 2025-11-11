# SecureChatAI External Module (EM)

SecureChatAI is a REDCap External Module that provides a unified interface to Stanford’s Secure AI endpoints—including OpenAI (GPT-4o, GPT-4.1, Ada), Gemini, Claude, Llama, and more.
**Requires VPN connection to SOM/SHC.**

---

## Features

* **Unified interface:** Call any supported model (`gpt-4o`, `gpt-4.1`, `gemini20flash`, `claude`, `llama-Maverick`, etc) via a single function.
* **Auto-param filtering:** Only sends parameters the chosen model supports; avoids 400 errors from unexpected params.
* **Robust error logging:** All interactions, errors, and usage stats are logged to emLogger.
* **Easy to extend:** Add new models with minimal code changes.

---

## Basic Usage

```php
// Get module instance (from another EM)
$em = \ExternalModules\ExternalModules::getModuleInstance("secure_chat_ai");

// Prepare parameters for your model
$messages = [
    ["role" => "user", "content" => "Say hi from SecureChatAI!"]
];
$params = [
    'messages' => $messages,
    'temperature' => 0.7,   // Optional; see below
    'max_tokens' => 512     // Optional; see below
];
$model = "gpt-4o"; // or "o1", "gemini20flash", etc.
$project_id = 108; // Optional

// Call the model
$response = $em->callAI($model, $params, $project_id);
```

**Result:**
Normalized associative array with content, model, and token usage.

---

## Model Support & Example Calls

* **Chat Models** (`gpt-4o`, `gpt-4.1`, `o1`, `o3-mini`, `llama3370b`, `llama-Maverick`, `gemini20flash`, `claude`)

  * Pass `messages` as array of ChatML objects (`role` + `content`).
  * Only include parameters supported by the target model.
* **Embeddings** (`ada-002`)

  * Pass `input` as a string.
* **Transcription** (`whisper`)

  * Pass `file` (path to audio file), `language`, etc.

**Example:**

```php
// GPT-4o
$em->callAI("gpt-4o", ['messages' => $messages]);

// Ada-002 Embedding
$em->callAI("ada-002", ['input' => 'The quick brown fox']);

// Whisper
$em->callAI("whisper", [
    'file' => '/path/to/audio.wav',
    'language' => 'en'
]);
```

---

## Response Format

All chat model responses are normalized:

```php
[
    'content' => 'AI response text',
    'role' => 'assistant',
    'model' => 'gpt-4o',
    'usage' => [
        'prompt_tokens' => 16,
        'completion_tokens' => 20,
        'total_tokens' => 36
    ]
]
```

Embeddings:

```php
[
    0.0015534189,
    -0.016994879,
    // ...
]
```

---

## Model Parameters

* **temperature, top\_p, frequency\_penalty, presence\_penalty, max\_tokens**

  * Most chat models accept these. Defaults are configurable in the EM settings.
  * Only supported parameters are sent to each model.
* **reasoning\_effort**

  * Only for `o1`, `o3-mini`.
* **json\_schema**

  * Only for models supporting function calling/schema output (`gpt-4.1`, `o1`, etc).

See **EM configuration** for full list and defaults.

---

## Logging

* All API calls and responses (including errors) are logged to emLogs.
* Logs include project ID, tokens used, and response payload.

---

## Adding New Models

1. Add API config in the EM settings (alias, model ID, endpoint, etc).
2. If model requires special param filtering, add to `filterDefaultParamsForModel()`.
3. If response structure differs, update `normalizeResponse()`.

---

## Requirements

* REDCap 14+ (recommended)
* VPN connection to Stanford SOM/SHC
* Proper API credentials for each model endpoint

---

## Module API Endpoint

SecureChatAI now exposes a **REDCap External Module API endpoint** for external services (like RAG pipelines) to securely call Stanford-approved AI models.

This allows backend scripts, CRON jobs, or cloud services (e.g., Cloud Run, App Engine) to submit prompts through SecureChatAI using a REDCap API token — without needing direct model keys or VPN routing.

### Example: cURL Request

```bash
curl -X POST "https://redcap.stanford.edu/api/" \
  -F "token=YOUR_API_TOKEN" \
  -F "content=externalModule" \
  -F "prefix=secure_chat_ai" \
  -F "action=callAI" \
  -F "prompt=Summarize this text about RAG pipelines" \
  -F "model=deepseek" \
  -F "format=json" \
  -F "returnFormat=json"
```

### Example Response

```json
{
  "status": "success",
  "model": "deepseek",
  "content": "RAG pipelines combine retrieval and generation...",
  "usage": {
    "prompt_tokens": 42,
    "completion_tokens": 178,
    "total_tokens": 220
  }
}
```

### API Notes

- **Requires**: A valid REDCap API token from a project where SecureChatAI EM is enabled.  
  → Typically, this is a *dummy “service project”* created specifically for SecureChatAI.
- **Access Control**: All requests must include a valid token unless explicitly marked `no-auth` in config.
- **Supported Action**:  
  `callAI` — invokes any SecureChatAI-supported model (DeepSeek, GPT-4o, Gemini, Claude, etc.)
- **Format Support**: JSON (recommended), XML, CSV.
- **Recommended Use Case**: Backend integrations like RAG pipelines, cron-based summarization, or ingestion workers.


---

## FAQ / Troubleshooting

* **Getting 400 errors?**
  Only supported params are sent for each model. If you see this, check your input or EM config.
* **Want to use a new model?**
  Add it in settings and test with the built-in Unit Test page.

---

**For more details, see the code and the `tests.php` unit test UI.**
