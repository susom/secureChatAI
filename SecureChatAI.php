<?php

namespace Stanford\SecureChatAI;

require_once "emLoggerTrait.php";
require_once "classes/SecureChatLog.php";
require_once "classes/Models/ModelInterface.php";
require_once "classes/Models/BaseModelRequest.php";
require_once "classes/Models/GPTModelRequest.php";
require_once "classes/Models/WhisperModelRequest.php";
require_once "classes/Models/GeminiModelRequest.php";
require_once "classes/Models/ClaudeModelRequest.php";
require_once "classes/Models/GenericModelRequest.php";
require_once "classes/Models/GPT4oMiniTTSModelRequest.php";

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
            'reasoning_effort' => $this->getSystemSetting('reasoning-effort'),
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
        return SecureChatLog::getAllLogs($this, $offset);
    }

    private function filterDefaultParamsForModel($model, $params)
    {
        // Merge first so $params values override defaults
        $merged = array_merge($this->defaultParams, $params);

        // Only o1/o3-mini get reasoning_effort
        if (!in_array($model, ['o1', 'o3-mini'])) {
            unset($merged['reasoning_effort']);
        }

        // Only models supporting json_schema
        $schemaModels = ['gpt-4.1', 'o1', 'o3-mini', 'llama3370b'];
        if (!in_array($model, $schemaModels)) {
            unset($merged['json_schema']);
        }

        // Only o1/o3-mini have strict param set
        if (in_array($model, ['o1', 'o3-mini'])) {
            $strict = [
                'model' => $model,
                'messages' => $merged['messages'] ?? [],
                'max_completion_tokens' => $merged['max_completion_tokens'] ?? ($merged['max_tokens'] ?? 800),
            ];
            if (isset($merged['reasoning_effort'])) {
                $strict['reasoning_effort'] = $merged['reasoning_effort'];
            }
            return $strict;
        }

        // Remove max_tokens for all non-o1/o3-mini
        unset($merged['max_tokens']);

        return $merged;
    }



    public function callAI($model, $params = [], $project_id = null)
    {
        $retries = 2;
        $attempt = 0;

        while ($attempt <= $retries) {
            try {
                $this->initSecureChatAI();

                if (!isset($this->modelConfig[$model])) {
                    throw new Exception('Unsupported model: ' . $model);
                }

                $modelConfig = $this->modelConfig[$model];
                $api_endpoint = $modelConfig['api_url'];
                $headers = [];

                // Ensure required parameters are provided
                foreach ($modelConfig['required'] as $param) {
                    if (empty($params[$param])) {
                        throw new Exception('Missing required parameter: ' . $param);
                    }
                }

                switch ($model) {
                    case 'gpt-4o':
                    case 'ada-002':
                        $gpt = new GPTModelRequest($this, $modelConfig, $this->defaultParams, $model);
                        $responseData = $gpt->sendRequest($api_endpoint, $params);
                        break;
                    case 'whisper':
                        $whisper = new WhisperModelRequest($this, $modelConfig, $this->defaultParams, $model);
                        $whisper->setHeaders(['Content-Type: multipart/form-data','Accept: application/json']);
                        $whisper->setAuthKeyName($modelConfig['whisper']['api_key_var'] ?? 'api-key');
                        $responseData = $whisper->sendRequest($api_endpoint, $params);
                        break;
                    case 'gpt-4.1':
                    case 'o1':
                    case 'o3-mini':
                    case 'llama3370b':
                    case 'llama-Maverick':
                        $filteredParams = $this->filterDefaultParamsForModel($model, $params);
                        $generic = new GenericModelRequest($this, $modelConfig, [], $model); // just leave defaultParams empty or as truly static defaults
                        $responseData = $generic->sendRequest($api_endpoint, $filteredParams);
                        break;
                    case 'claude':
                        $claude = new ClaudeModelRequest($this, $modelConfig, $this->defaultParams, $model);
                        $responseData = $claude->sendRequest($api_endpoint, $params);
                        break;
                    case 'gemini20flash':
                    case 'gemini25pro':
                        $gemini = new GeminiModelRequest($this, $modelConfig, $this->defaultParams, $model);
                        $responseData = $gemini->sendRequest($api_endpoint, $params);
                        break;
                    case 'gpt-4o-tts':
                    case 'tts':
                        $tts = new GPT4oMiniTTSModelRequest($this, $modelConfig, $this->defaultParams, $model);
                        $responseData = $tts->sendRequest($api_endpoint, $params);
                        break;
                    default:
                        throw new Exception("Unsupported model configuration for: $model");
                }
                

                $normalizedResponse = $this->normalizeResponse($responseData, $model);

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

    private function normalizeResponse($response, $model)
    {
        $normalized = [];

        if ($model === 'claude') {
            $normalized['content'] = $response['content'][0]['text'] ?? '';
            $normalized['role'] = $response['role'] ?? 'assistant';
            $normalized['model'] = $response['model'] ?? 'claude';
            $normalized['usage'] = [
                'prompt_tokens' => $response['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0)
            ];
        } elseif (in_array($model, [
            'o1', 'o3-mini', 'gpt-4o', 'llama3370b', 'gpt-4.1', 'llama-Maverick'
        ])) {
            $normalized['content'] = $response['choices'][0]['message']['content'] ?? '';
            $normalized['role'] = $response['choices'][0]['message']['role'] ?? 'assistant';
            $normalized['model'] = $response['model'] ?? $model;
            $normalized['usage'] = [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0
            ];
        } elseif (in_array($model, [
             'gemini20flash',  'gemini25pro'
        ])) {
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
        } elseif ($model === 'gpt-4o-tts' || $model === 'tts') {
            // Example: Your TTSModelRequest returns audio_base64 and content_type fields.
            $normalized['audio_base64'] = $response['audio_base64'] ?? '';
            $normalized['content_type'] = $response['content_type'] ?? 'audio/mpeg';
            $normalized['model'] = $model;
            // Add any other relevant info
        } else {
            $normalized = $response;
        }

        $this->emDebug("normalized responseData", $normalized);
        return $normalized;
    }

    private function logInteraction($project_id, $requestData, $responseData)
    {
        $payload = array_merge($requestData, $responseData ?? []);
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
