# SecureChat
SecureChat is an External Module (EM) designed as a service to access Stanford's Instance of OpenAI Models, Secure Chat AI.

**Requirement:** Connection to SHC's VPN. You can connect through the VPN via [Stanford Health Care's portal](https://vpn.stanfordhealthcare.org/) using the F5 Client.

## Usage from other Project EM
```php
$moduleDirectoryPrefix = "SecureChatAI"; // Module prefix of your target system-level module
$method = "callAI";
$messages = [...]; // The data you want to pass to callAI
$params = [...];

$result = \ExternalModules\ExternalModules::call($moduleDirectoryPrefix, $method, [$messages, $params]);
```

## Context Management (ChatML)
```json
[
    {"role":"system", "content":"context text"},
    {"role":"user", "content":"early user query"},
    {"role":"assistant", "content":"previous ai response"},
    {"role":"user", "content":"newest user query"}
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

