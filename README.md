# SecureChat
SecureChat is an External Module (EM) designed to access Stanford's instance of OpenAI models, Secure Chat AI.

**Requirement:** Connection to SOM or SHC's VPN.

## Logging
Every interaction with the API is logged to `emLogs`, tracking input/output tokens and project ID.

## Usage from Other Project EMs
```php
$moduleDirectoryPrefix = "secure_chat_ai"; // Module prefix of your target system-level module
$messages = [...]; // Data you want to pass to callAI
$params = [...];
$model = "gpt-4o"; // or "ada-002" for embeddings
$em = \ExternalModules\ExternalModules::getModuleInstance($moduleDirectoryPrefix);
$result = $em->callAI($model, $params, $project_id);
```

Usage of different models might require alternate parameters. See the example calls below for information:

## Example call (GPT-4o)
```php
$model = "gpt-4o";
$messages = [
    [
        'role' => 'system',
        'content' => 'What is 1+1'
    ]
];
$params = {
    'temperature' => 0.7
    'top_p' => 0.9
    'frequency_penalty' => 0.5
    'presence_penalty' => 0
    'max_tokens' => 4096,
    'messages' => $messages
}
$project_id = 108;

$response = $this->callAI($model, $params, $project_id);
```
## Example call (ADA-002)
```php
$model = "ada-002";
$input = "What is 2+2?"

$response = $this->callAI($model, ['input' => $input]);
```

## Example call (Whisper - Transcription)
```php
$model = "whisper";
$inputFile = "/path/to/audio/file.wav"; // Path to the audio file
$params = [
    'input' => $inputFile,
    'language' => 'en',
    'temperature' => 0.0,
    'format' => 'json',
    'initial_prompt' => 'Delineate between speakers',
    'prompt' => 'Make any corrections to misheard speech if possible to deduce'
];
$project_id = 108;

$response = $this->callAI($model, $params, $project_id);
```


## AI Endpoint (ChatML)
Expected input:
```json
[
    {"role":"system", "content":"context text"},
    {"role":"user", "content":"early user query"},
    {"role":"assistant", "content":"previous ai response"},
    {"role":"user", "content":"newest user query"}
]
```
Expected output :
```json
[
    {
        "role": "assistant",
        "content": "reponse content from AI",
        "id": "abcxyz123",
        "model": "gpt-4o-2024-05-13",
        "usage": {
            "completion_tokens": 125,
            "prompt_tokens": 1315,
            "total_tokens": 1440
        }
    }
]
```


## Embeddings Endpoint (RAG workflow)
```json
"RAW TEXT INPUT"
```
Expected output :
```json
[
    0.0015534189,
    -0.016994879,
    -0.0012200507,
    0.0027190577,
    ...,
    ...,
    etc
]
```

## gpt-4o Model Parameters with Default Values (*Configurable in EM Settings)

### Temperature*
- **Description**: Controls the randomness of the model's output.
- **Range**: 0.0 to 1.0
- **Effect**: Lower values (e.g., 0.2) make the output more deterministic, while higher values (e.g., 0.8) increase randomness.

### top_p*
- **Description**: Implements nucleus sampling, selecting tokens with the highest cumulative probability.
- **Range**: 0.0 to 1.0
- **Effect**: Lower values (e.g., 0.5) narrow token selection, while higher values (e.g., 0.9) broaden it.

### frequency_penalty*
- **Description**: Adjusts token usage likelihood based on its frequency so far.
- **Range**: -2.0 to 2.0
- **Effect**: Positive values reduce repetition, while negative values encourage it.

### presence_penalty*
- **Description**: Adjusts token usage likelihood based on its presence so far.
- **Range**: -2.0 to 2.0
- **Effect**: Positive values reduce new topic generation, while negative values encourage it.

### max_tokens*
- **Description**: Sets the maximum number of tokens to generate.
- **Range**: 1 to 2048 (depending on model and context length)
- **Effect**: Determines response length. Higher values allow for longer responses.

### stop
- **Description**: A string or array of strings specifying where the model should stop generating further tokens.
- **Range**: Any string or array of strings
- **Effect**: If any specified stop sequences are encountered, the model stops generating further tokens.

## Whisper Model Parameters with Default Values (*Configurable in EM Settings)

### Language
- **Key**: `language`
- **Type**: `string`
- **Example**: `"en"` (for English)
- **Default**: None (Must be specified if needed)

### Temperature
- **Key**: `temperature`
- **Type**: `float`
- **Range**: `0.0` to `1.0`
- **Default**: `0.0`
- **Description**: Controls the randomness of the transcription. Lower values make the output more deterministic.

### Max Tokens
- **Key**: `max_tokens`
- **Type**: `int`
- **Default**: None (No maximum unless specified)
- **Description**: Specifies the maximum number of tokens to generate in the transcription.

### Format
- **Key**: `format`
- **Type**: `string`
- **Example**: `"json"`, `"text"`, `"srt"`
- **Default**: `"json"`
- **Description**: Specifies the format of the transcription output.

### Temperature Increment On Fallback
- **Key**: `temperature_increment_on_fallback`
- **Type**: `float`
- **Range**: `0.0` to `1.0`
- **Default**: Not specified (Rarely used)

### Compression Ratio Threshold
- **Key**: `compression_ratio_threshold`
- **Type**: `float`
- **Example**: `2.4`
- **Default**: None (Optional)

### Log Prob Threshold
- **Key**: `log_prob_threshold`
- **Type**: `float`
- **Example**: `-1.0`
- **Default**: None (Optional)

### No Speech Threshold
- **Key**: `no_speech_threshold`
- **Type**: `float`
- **Range**: `0.0` to `1.0`
- **Default**: `0.6`
- **Description**: If the probability of silence/noise is higher than this threshold, the segment is ignored.

### Condition On Previous Text
- **Key**: `condition_on_previous_text`
- **Type**: `boolean`
- **Default**: `true`
- **Description**: Whether the model should condition on the previous output when generating the next segment of transcription.
