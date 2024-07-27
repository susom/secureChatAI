# SecureChat
SecureChat is an External Module (EM) designed as a service to access Stanford's Instance of OpenAI Models, Secure Chat AI.

**Requirement:** Connection to SOM or SHC's VPN.

## Logging

Each interaction with the API can be logged to a configured REDCap project (or Entity Table TBD) by project_id
An example of a project for Logging: [SecureChatAIEmUsageLog_2024-07-27.REDCap.xml](SecureChatAIEmUsageLog_2024-07-27.REDCap.xml)

## Usage from other Project EM
```php
$moduleDirectoryPrefix = "SecureChatAI"; // Module prefix of your target system-level module
$messages = [...]; // The data you want to pass to callAI
$params = [...];
$model = "gpt-4o" // or "ada-002" for embeddings
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

## Parameters Glosary (*Configurable in EM Settings)

### Temperature*
- **Description**: Controls the randomness of the model's output.
- **Range**: 0.0 to 1.0
- **Effect**: Lower values (e.g., 0.2) make the output more deterministic and focused, while higher values (e.g., 0.8) make it more random and creative.

### top_p*
- **Description**: Implements nucleus sampling, which selects tokens with the highest cumulative probability.
- **Range**: 0.0 to 1.0
- **Effect**: Lower values (e.g., 0.5) will narrow the token selection to fewer, more likely tokens, while higher values (e.g., 0.9) will broaden the token selection.

### frequency_penalty*
- **Description**: Adjusts the likelihood of a token being used based on its frequency so far.
- **Range**: -2.0 to 2.0
- **Effect**: Positive values (e.g., 1.0) reduce the model's tendency to repeat the same lines, while negative values (e.g., -1.0) encourage repetition.

### presence_penalty*
- **Description**: Adjusts the likelihood of a token being used based on its presence so far.
- **Range**: -2.0 to 2.0
- **Effect**: Positive values (e.g., 1.0) reduce the model's tendency to generates new topics, while negative values (e.g., -1.0) encourage the introduction of new topics.

### max_tokens*
- **Description**: Sets the maximum number of tokens to generate.
- **Range**: 1 to 2048 (depending on the model and the context length)
- **Effect**: Determines the length of the response. Higher values allow for longer responses, while lower values limit the length.

### stop
- **Description**: A string or array of strings that specify where the model should stop generating further tokens.
- **Range**: Any string or array of strings
- **Effect**: If any of the specified stop sequences are encountered, the model will stop generating further tokens. If `null`, the model continues until it reaches the token limit or a default stopping condition.


