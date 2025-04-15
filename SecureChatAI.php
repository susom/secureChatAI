<?php

namespace Stanford\SecureChatAI;

require_once "emLoggerTrait.php";
require_once "classes/SecureChatLog.php";
require_once "classes/Models/ModelInterface.php"; // ✅ Ensure interface is loaded first
require_once "classes/Models/BaseModelRequest.php";
require_once "classes/Models/GPTModelRequest.php";
require_once "classes/Models/WhisperModelRequest.php";
require_once "classes/Models/GeminiModelRequest.php";

use Google\Exception;
use GuzzleHttp\Client;

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
                        $gpt = new GPTModelRequest($this, $modelConfig, $this->defaultParams);
                        $responseData = $gpt->sendRequest($api_endpoint, $params);
                        break;

                    // SPECIAL CASE FOR WHISPER
                    case 'whisper':
                        $whisper = new WhisperModelRequest($this, $modelConfig, $this->defaultParams);
                        $whisper->setHeaders(['Content-Type: multipart/form-data','Accept: application/json']);
                        //Whisper model structure operates independently, set Auth key manually
                        $whisper->setAuthKeyName($modelConfig['whisper']['api_key_var'] ?? 'api-key');
                        $responseData = $whisper->sendRequest($api_endpoint, $params);
                        break;

                    // "FINAL" PATTERN
                    case 'o1':
                    case 'o3-mini':
                    case 'llama3370b': //Meta
                        $this->prepareAIRequest($params, $headers, $api_key, $model, $model_id, $postfields);
                        break;

                    // same "FINAL" PATTERN BUT SLIGHTY DIFFERENT FOR CLAUDE
                    case 'claude':
                        $this->prepareClaudeRequest($params, $headers, $api_key, $model_id, $postfields);
                        break;

                    // WILDLY DIFFERENT PATTERN FOR GEMINI
                    case "gemini20flash":
                        $gemini = new GeminiModelRequest($this, $modelConfig, $this->defaultParams);
                        $responseData = $gemini->sendRequest($api_endpoint, $params);
                        break;

                    default:
                        throw new Exception("Unsupported model configuration for: $model");
                }

                // Execute the API call
//                $responseData = $this->executeApiCall($api_endpoint, $headers, $postfields ?? []);
                $normalizedResponse = $this->normalizeResponse($responseData, $model);

                // Log interaction only if project_id is available
                if ($project_id) {
                    $this->logInteraction($project_id, $params, $responseData);
                }

                return $normalizedResponse;
            } catch (\Exception $e) {
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
        } elseif ($model === 'gemini20flash') {
            $contentParts = [];

            foreach ($response as $chunk) {
                $parts = $chunk['candidates'][0]['content']['parts'] ?? [];
                foreach ($parts as $part) {
                    if (!empty($part['text'])) {
                        $contentParts[] = $part['text'];
                    }
                }
            }

            $normalized['content'] = implode(" ", $contentParts);
            $normalized['role'] = $response[0]['candidates'][0]['content']['role'] ?? "model";
            $normalized['model'] = $response[0]['modelVersion'] ?? $model;

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

        $this->emDebug("normalized responseData", $normalized);
        return $normalized;
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
