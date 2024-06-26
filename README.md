# SecureChat
SecureChat is an External Module (EM) designed as a service to access Stanford's Instance of OpenAI Models, Secure Chat AI.

**Requirement:** Connection to SHC's VPN. You can connect through the VPN via [Stanford Health Care's portal](https://vpn.stanfordhealthcare.org/) using the F5 Client.

## Usage from other Project EM
```php
$moduleDirectoryPrefix = "SecureChatAI"; // Module prefix of your target system-level module
$messages = [...]; // The data you want to pass to callAI
$params = [...];
$model = "gpt-4o" // or "ada-002" for embeddings
$em = \ExternalModules\ExternalModules::getModuleInstance($moduleDirectoryPrefix);
$result = $em->callAI($model, $messages, $params);
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

$response = $this->callAI($model, ['messages' => $messages]);
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

## Parameters Glosary

### Temperature
- **Description**: Controls the randomness of the model's output.
- **Range**: 0.0 to 1.0
- **Effect**: Lower values (e.g., 0.2) make the output more deterministic and focused, while higher values (e.g., 0.8) make it more random and creative.

### top_p
- **Description**: Implements nucleus sampling, which selects tokens with the highest cumulative probability.
- **Range**: 0.0 to 1.0
- **Effect**: Lower values (e.g., 0.5) will narrow the token selection to fewer, more likely tokens, while higher values (e.g., 0.9) will broaden the token selection.

### frequency_penalty
- **Description**: Adjusts the likelihood of a token being used based on its frequency so far.
- **Range**: -2.0 to 2.0
- **Effect**: Positive values (e.g., 1.0) reduce the model's tendency to repeat the same lines, while negative values (e.g., -1.0) encourage repetition.

### presence_penalty
- **Description**: Adjusts the likelihood of a token being used based on its presence so far.
- **Range**: -2.0 to 2.0
- **Effect**: Positive values (e.g., 1.0) reduce the model's tendency to generates new topics, while negative values (e.g., -1.0) encourage the introduction of new topics.

### max_tokens
- **Description**: Sets the maximum number of tokens to generate.
- **Range**: 1 to 2048 (depending on the model and the context length)
- **Effect**: Determines the length of the response. Higher values allow for longer responses, while lower values limit the length.

### stop
- **Description**: A string or array of strings that specify where the model should stop generating further tokens.
- **Range**: Any string or array of strings
- **Effect**: If any of the specified stop sequences are encountered, the model will stop generating further tokens. If `null`, the model continues until it reaches the token limit or a default stopping condition.


