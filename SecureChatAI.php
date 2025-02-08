<?php

namespace Stanford\SecureChatAI;

require_once "emLoggerTrait.php";
require_once "classes/SecureChatLog.php";

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SecureChatAI extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private array $defaultParams;
    private $guzzleClient = null;
    private $guzzleTimeout = 5.0;
    private array $modelConfig = [];

    public function __construct()
    {
        parent::__construct();
    }

    private function initSecureChatAI()
    {
        // Set default LLM model parameters
        $this->defaultParams = [
            'temperature' => (float)$this->getSystemSetting('gpt-temperature') ?: 0.7,
            'top_p' => (float)$this->getSystemSetting('gpt-top-p') ?: 0.9,
            'frequency_penalty' => (float)$this->getSystemSetting('gpt-frequency-penalty') ?: 0.5,
            'presence_penalty' => (float)$this->getSystemSetting('gpt-presence-penalty') ?: 0,
            'max_tokens' => (int)$this->getSystemSetting('gpt-max-tokens') ?: 800,
            'reasoning_effort' => $this->getSystemSetting('reasoning-effort') ,
            'stop' => null,
            'model' => 'gpt-4o'
        ];

        // Initialize the model configurations from system settings
        $apiSettings = $this->framework->getSubSettings('api-settings');
        foreach ($apiSettings as $setting) {
            $modelAlias = $setting['model-alias'];
            $modelID = $setting['model-id'];
            $this->modelConfig[$modelAlias] = [
                'api_url' => $setting['api-url'],
                'api_token' => $setting['api-token'],
                'api_key_var' => $setting['api-key-var'],
                'required' => $setting['api-input-var'],
                'model_id' => $modelID
            ];

            if (isset($setting['default-model']) && $setting['default-model']) {
                $this->defaultParams['model'] = $modelAlias;
            }
        }

        // Set Guzzle info
        $timeout = $this->getSystemSetting('guzzle-timeout') ? (float)(strip_tags($this->getSystemSetting('guzzle-timeout'))) : $this->getGuzzleTimeout();
        $this->setGuzzleTimeout($timeout);
        $this->guzzleClient = $this->getGuzzleClient();
    }

    public function getSecureChatLogs($offset)
    {
        $offset = intval($offset);
        return SecureChatLog::getLogs($this, '52', $offset);
    }


    public function callAI($model, $params = [], $project_id = null)
    {
        $retries = 2; // Maximum number of retries
        $attempt = 0;

        while ($attempt <= $retries) {
            try {
                // Ensure the secure chat AI is initialized
                $this->initSecureChatAI();
                // $this->emDebug("Initialized SecureChatAI with model", $model);

                // Check if model is supported
                if (!isset($this->modelConfig[$model])) {
                    throw new Exception('Unsupported model: ' . $model);
                }

                $modelConfig = $this->modelConfig[$model];
                // $this->emDebug("Loaded model configuration", $modelConfig);

                $api_endpoint = $modelConfig['api_url'];
                $auth_key_name = $modelConfig['api_key_var'];
                $api_key = $modelConfig['api_token'];
                $model_id = $modelConfig['model_id'];
                $headers = [];

                // Ensure required parameters are provided
                foreach ($modelConfig['required'] as $param) {
                    if (empty($params[$param])) {
                        throw new Exception('Missing required parameter: ' . $param);
                    }
                }

                // Prepare request headers, URL, and payload based on model type
                switch ($model) {
                    // OLD WAY WHERE key APPENDED TO QueryString
                    case 'gpt-4o':
                    case 'ada-002':
                        $headers = ['Content-Type: application/json', 'Accept: application/json'];
                        $api_endpoint .= (strpos($api_endpoint, '?') === false ? '?' : '&') . "$auth_key_name=$api_key";
                        $merged_params = array_merge($this->defaultParams, $params);
                        unset($merged_params["reasoning_effort"]);
                        $postfields = json_encode($merged_params);
                        break;
                        
                    // SPECIAL CASE FOR WHISPER    
                    case 'whisper':
                        $this->prepareWhisperRequest($params, $api_endpoint, $headers, $api_key);
                        $postfields = $params;
                        break;

                    // "FINAL" PATTERN
                    case 'o1':
                    case 'o3-mini':
                    case 'llama3370b':
                        $this->prepareAIRequest($params, $headers, $api_key, $model, $model_id, $postfields);
                        break;

                    // same "FINAL" PATTERN BUT SLIGHTY DIFFERENT FOR CLAUDE    
                    case 'claude':
                        $this->prepareClaudeRequest($params, $headers, $api_key, $model_id, $postfields);
                        break;

                    // WILDLY DIFFERENT PATTERN FOR GEMINI
                    case "gemini15pro":
                        $this->prepareGeminiRequest($params, $headers, $api_key, $model, $postfields);
                        break;

                    default:
                        throw new Exception("Unsupported model configuration for: $model");
                }

                // Execute the API call
                $responseData = $this->executeApiCall($api_endpoint, $headers, $postfields ?? []);
                $this->emDebug("response data", $model, json_decode($postfields,1));
                $normalizedResponse = $this->normalizeResponse($responseData, $model);
                $this->emDebug("Normalized API Response", $normalizedResponse);

                // Log interaction only if project_id is available
                if ($project_id) {
                    $this->logInteraction($project_id, $params, $responseData);
                }

                return $normalizedResponse;
            } catch (Exception $e) {
                $attempt++;
                $this->emDebug("Attempt $attempt: Error", $e->getMessage());

                if ($attempt > $retries) {
                    $error = [
                        'error' => true,
                        'message' => "Error after $retries retries: " . $e->getMessage()
                    ];

                    // Log error interaction only if project_id is available
                    if ($project_id) {
                        $this->logErrorInteraction($project_id, $params, $error);
                    } else {
                        $this->emDebug("Skipping error logging due to missing project ID (pid).");
                    }

                    return $error;
                }
            }
        }
    }

    private function addWhisperParameters(&$multipartData, $params): void
    {
        $fields = [
            'initial_prompt',
            'prompt'
        ];

        foreach ($fields as $field) {
            if (!empty($params[$field])) {
                $multipartData[] = [
                    'name' => $field,
                    'contents' => $params[$field]
                ];
            }
        }

        $settings = [
            'whisper-language' => 'language',
            'whisper-temperature' => 'temperature',
            'whisper-top-p' => 'top_p',
            'whisper-n' => 'n',
            'whisper-logprobs' => 'logprobs',
            'whisper-max-alternate-transcriptions' => 'max_alternate_transcriptions',
            'whisper-compression-rate' => 'compression_rate',
            'whisper-sample-rate' => 'sample_rate',
            'whisper-condition-on-previous-text' => 'condition_on_previous_text'
        ];

        foreach ($settings as $settingKey => $fieldName) {
            $value = $this->getSystemSetting($settingKey);
            if ($value !== null) {
                $multipartData[] = [
                    'name' => $fieldName,
                    'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value
                ];
            }
        }
    }

    private function prepareWhisperRequest(&$params, &$api_endpoint, &$headers, $api_key)
    {
        // Set headers for multipart/form-data
        $headers = ['Content-Type: multipart/form-data', 'Accept: application/json'];

        // Append the API key to the endpoint
        $auth_key_name = $this->modelConfig['whisper']['api_key_var'] ?? 'api-key';
        $api_endpoint .= (strpos($api_endpoint, '?') === false ? '?' : '&') . "$auth_key_name=$api_key";

        if (!empty($params['fileBase64']) && !empty($params['fileName'])) {
            // Handle Base64-encoded input
            $decodedFile = base64_decode($params['fileBase64']);
            if ($decodedFile === false) {
                throw new Exception("Whisper: Failed to decode Base64 file data.");
            }

            // Save the decoded file to a temporary path
            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('whisper_', true) . '_' . $params['fileName'];
            if (file_put_contents($tempFilePath, $decodedFile) === false) {
                throw new Exception("Whisper: Failed to save decoded file to temporary path.");
            }

            // Replace Base64 data with a CURLFile object
            $params = [
                'file' => curl_file_create($tempFilePath, mime_content_type($tempFilePath), basename($tempFilePath)),
                'language' => $params['language'] ?? 'en',
                'temperature' => $params['temperature'] ?? '0.0',
                'format' => $params['format'] ?? 'json'
            ];
            // Cleanup: Ensure temp file removal later
            register_shutdown_function(function () use ($tempFilePath) {
                if (file_exists($tempFilePath)) {
                    unlink($tempFilePath);
                }
            });
        } elseif (!empty($params['file']) && file_exists($params['file'])) {
            // Handle file path input
            $params = [
                'file' => curl_file_create($params['file'], mime_content_type($params['file']), basename($params['file'])),
                'language' => $params['language'] ?? 'en',
                'temperature' => $params['temperature'] ?? '0.0',
                'format' => $params['format'] ?? 'json'
            ];
        } else {
            throw new Exception("Whisper: File not found or invalid input. Provide either a file path or Base64 data.");
        }
    }

    private function formatMessagesForClaude(array $messages): string
    {
        $formatted = [];
        foreach ($messages as $message) {
            $role = ucfirst($message['role']);
            $content = trim($message['content']);
            $formatted[] = "{$role}: {$content}";
        }
        return implode("\n\n", $formatted); // Separate messages with double newlines for clarity
    }

    private function prepareClaudeRequest(&$params, &$headers, $api_key, $model_id, &$postfields)
    {
        $auth_key_name = $this->modelConfig['claude']['api_key_var'];
        $headers = ['Content-Type: application/json', "$auth_key_name: $api_key"];

        // Format messages using existing helper
        $prompt_text = isset($params['messages']) 
            ? $this->formatMessagesForClaude($params['messages']) 
            : ($params['prompt_text'] ?? '');

        if (empty($prompt_text)) {
            throw new Exception('Claude API requires prompt_text in the request body.');
        }

        // only Claude-supported keys
        $parameters = [
            "temperature" => $this->defaultParams['temperature'],
            "top_p" => $this->defaultParams['top_p'],
            "max_tokens" => $this->defaultParams['max_tokens']
        ];

        $postfields = json_encode([
            "model_id" => $model_id,  // ✅ Keeps Claude’s expected `model_id`
            "prompt_text" => $prompt_text, // ✅ Keeps Claude’s expected `prompt_text`
            "parameters" => $parameters // ✅ Adds tuning options without breaking Claude
        ]);
    }

    private function prepareGeminiRequest(&$params, &$headers, $api_key, $model, &$postfields)
    {
        $auth_key_name = $this->modelConfig[$model]['api_key_var'] ;
        $headers = [
            'Content-Type: application/json',
            "$auth_key_name: $api_key"
        ];

        $messages = $params['messages'] ?? [];
        $geminiMessages = [];
        $systemContext = "";

        // Process messages to convert "system" role
        foreach ($messages as $msg) {
            if ($msg["role"] === "system") {
                // Collect system messages as context
                $systemContext .= trim($msg["content"]) . "\n\n";
            } else {
                // Convert normal messages
                $geminiMessages[] = [
                    "role" => $msg["role"],
                    "parts" => [["text" => trim($msg["content"])]]
                ];
            }
        }

        // If system context exists, inject it into the first user message
        if (!empty($systemContext)) {
            if (!empty($geminiMessages) && $geminiMessages[0]["role"] === "user") {
                // Prepend system context to first user message
                $geminiMessages[0]["parts"][0]["text"] = $systemContext . $geminiMessages[0]["parts"][0]["text"];
            } else {
                // Otherwise, create a new user message for system context
                array_unshift($geminiMessages, [
                    "role" => "user",
                    "parts" => [["text" => $systemContext]]
                ]);
            }
        }

        // Define generation config
        $generationConfig = [
            "temperature" => $this->defaultParams['temperature'],
            "topP" => $this->defaultParams['top_p'],
            "max_output_tokens" => $this->defaultParams['max_tokens'],
            "stop_sequences" => $this->defaultParams['stop'] ?? null, // Stop sequences if provided
            "frequency_penalty" => $this->defaultParams['frequency_penalty'],
            "presence_penalty" => $this->defaultParams['presence_penalty'],
        ];

        // Optional safety settings (can be adjusted)
        $safetySettings = [
            [
                "category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT",
                "threshold" => "BLOCK_LOW_AND_ABOVE"
            ]
        ];

        $postfields = json_encode([
            "contents" => $geminiMessages,
            "generation_config" => $generationConfig,
            "safety_settings" => $safetySettings
        ]);
    }

    private function prepareAIRequest(&$params,  &$headers, $api_key, $model, $model_id, &$postfields)
    {
        $auth_key_name = $this->modelConfig[$model]['api_key_var'];
        $headers = ['Content-Type: application/json', "$auth_key_name: $api_key"];

        $postfields = json_encode(
            in_array($model, ['o1', 'o3-mini'])
                ? [ // Specific params for `o1` and `o3-mini`
                    "model" => $model_id,
                    "messages" => $params['messages'] ?? [],
                    "max_completion_tokens" => $this->defaultParams['max_tokens'], 
                    "reasoning_effort" => $params['reasoning_effort'] ?? $this->defaultParams['reasoning_effort'] 
                ]
                : array_merge( // Standard merging for other models (llama only)
                    $this->defaultParams,
                    $params,
                    ["model" => $model_id, "messages" => $params['messages'] ?? []]
                )
        );
    }

    private function normalizeResponse($response, $model)
    {
        // $this->emDebug("API responseData for normalizeResponse", $model, $response);

        $normalized = [];

        if ($model === 'claude') {
            // Extract content from Claude response
            $normalized['content'] = $response['content'][0]['text'] ?? '';
            $normalized['role'] = $response['role'] ?? 'assistant';
            $normalized['model'] = $response['model'] ?? 'claude';
            $normalized['usage'] = [
                'prompt_tokens' => $response['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0)
            ];
        } elseif (in_array($model, ['o1', 'o3-mini', "gpt-4o", "llama3370b"])) {
            // Extract content from o1 and o3-mini responses
            $normalized['content'] = $response['choices'][0]['message']['content'] ?? '';
            $normalized['role'] = $response['choices'][0]['message']['role'] ?? 'assistant';
            $normalized['model'] = $response['model'] ?? $model;
            $normalized['usage'] = [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0
            ];
        } elseif ($model === 'gemini15pro') {
            // Collect all text parts across multiple candidates
            $contentParts = [];
            foreach ($response as $resp) {
                if (!empty($resp['candidates'][0]['content']['parts'])) {
                    foreach ($resp['candidates'][0]['content']['parts'] as $part) {
                        if (!empty($part['text'])) {
                            $contentParts[] = $part['text'];
                        }
                    }
                }
            }
        
            // Join all content pieces into one response
            $normalized['content'] = implode(" ", $contentParts);
        
            $normalized['role'] = "assistant";
            $normalized['model'] = $response[0]['modelVersion'] ?? $model;
            
            // Extract token usage from the last response chunk (assuming it's in the last index)
            $usage = end($response)['usageMetadata'] ?? [];
            $normalized['usage'] = [
                'prompt_tokens' => $usage['promptTokenCount'] ?? 0,
                'completion_tokens' => $usage['candidatesTokenCount'] ?? 0,
                'total_tokens' => $usage['totalTokenCount'] ?? 0
            ];
        } else {
            // If the model isn't specified, pass through as-is
            $normalized = $response;
        }

        return $normalized;
    }

    private function executeApiCall($api_endpoint, $headers, $postfields)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);

        // Handle multipart form-data or JSON
        if (is_array($postfields)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        }

        // Temporary manual DNS resolution
        // TODO: Remove this block once proper DNS resolution is restored
        curl_setopt($ch, CURLOPT_RESOLVE, [
            'apim.stanfordhealthcare.org:443:10.249.134.5',
            'som-redcap-whisper.openai.azure.com:443:10.153.192.4',
            'som-redcap.openai.azure.com:443:10.249.50.7'
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }

        if ($http_code < 200 || $http_code >= 300) {
            throw new Exception('HTTP error: ' . $http_code . ' - Response: ' . $response);
        }

        curl_close($ch);
        return json_decode($response, true);
    }


    private function logInteraction($project_id, $requestData, $responseData)
    {
        $payload = array_merge($requestData, $responseData);
        $payload['project_id'] = $project_id;
        $action = new SecureChatLog($this);

        $action->setValue('message', json_encode($payload));
        $action->setValue('record', 'SecureChatLog');
        $action->save();
    }

    private function logErrorInteraction($project_id, $requestData, $error)
    {
        $payload = array_merge($requestData, $error);
        $payload['project_id'] = $project_id;
        $action = new SecureChatLog($this);

        $action->setValue('message', json_encode($error));
        $action->setValue('record', 'SecureChatLogError');
        $action->save();
    }


    public function extractResponseText($response)
    {
        // Extract `content` from normalized response
        return $response['content'] ?? json_encode($response);
    }

    public function extractUsageTokens($response)
    {
        return $response['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    }

    public function extractMetaData($response)
    {
        return [
            'id' => $response['id'] ?? 'N/A',
            'object' => $response['object'] ?? 'N/A',
            'created' => $response['created'] ?? 'N/A',
            'model' => $response['model'] ?? 'N/A',
            'usage' => $response['usage'] ?? 'N/A'
        ];
    }


    public function getGuzzleClient()
    {
        if (!$this->guzzleClient) {
            $this->guzzleClient = new Client([
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => false
            ]);
        }
        return $this->guzzleClient;
    }

    public function setGuzzleTimeout(float $timeout)
    {
        $this->guzzleTimeout = $timeout;
    }

    public function getGuzzleTimeout(): float
    {
        return $this->guzzleTimeout;
    }
}
?>
