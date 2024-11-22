<?php

namespace Stanford\SecureChatAI;

require_once "emLoggerTrait.php";
require_once "classes/SecureChatLog.php";

use Google\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SecureChatAI extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private string $api_ai_url;
    private string $api_embeddings_url;
    private ?string $api_whisper_url;
    private string $api_key;
    private string $api_embeddings_key;
    private ?string $api_whisper_key;

    private array $defaultParams;

    private $guzzleClient = null;
    private $guzzleTimeout = 5.0;
    private $modelConfig = [
        'gpt-4o' => [
            'endpoint' => 'getApiAiUrl',
            'required' => ['messages'],
            'auth_key_name' => 'subscription-key'
        ],
        'ada-002' => [
            'endpoint' => 'getApiEmbeddingsUrl',
            'required' => ['input'],
            'auth_key_name' => 'api-key'
        ],
        'whisper' => [
            'endpoint' => 'getApiWhisperUrl',
            'required' => ['input'],
            'auth_key_name' => 'api-key'
        ]
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function initSecureChatAI()
    {
        //Set default API variables from system settings
        $this->setApiAiUrl($this->getSystemSetting('secure-chat-api-url'));
        $this->setApiEmbeddingsUrl($this->getSystemSetting('secure-chat-embeddings-api-url'));
        $this->setApiWhisperUrl($this->getSystemSetting('secure-chat-whisper-api-url'));
        $this->setApiKey($this->getSystemSetting('secure-chat-api-token'));
        $this->setApiEmbeddingsKey($this->getSystemSetting('secure-chat-embeddings-api-token'));
        $this->setApiWhisperKey($this->getSystemSetting('secure-chat-whisper-api-token'));

        //Set default LLM model parameters
        $this->setDefaultParams([
            'model' => $this->getSystemSetting('gpt-model') ?: 'gpt-4o',
            'temperature' => (float)$this->getSystemSetting('gpt-temperature') ?: 0.7,
            'top_p' => (float)$this->getSystemSetting('gpt-top-p') ?: 0.9,
            'frequency_penalty' => (float)$this->getSystemSetting('gpt-frequency-penalty') ?: 0.5,
            'presence_penalty' => (float)$this->getSystemSetting('gpt-presence-penalty') ?: 0,
            'max_tokens' => (int)$this->getSystemSetting('gpt-max-tokens') ?: 800,
            'stop' => null  // Assuming stop is not configurable and kept at default
        ]);

        //Set guzzle info
        $timeout = $this->getSystemSetting('guzzle-timeout') ? (float)(strip_tags($this->getSystemSetting('guzzle-timeout'))) : $this->getGuzzleTimeout();
        $this->setGuzzleTimeout($timeout);
        $this->guzzleClient = $this->getGuzzleClient();
    }

    /**
     * @return array
     * @params $offset array offset
     * @throws \Exception
     */
    public function getSecureChatLogs($offset){
        $offset = intval($offset);
        return SecureChatLog::getLogs($this, '52', $offset);
    }

    /**
     * Call the AI API with the provided messages and parameters.
     *
     * @param string $model The model to be used for the API call.
     * @param array $params Additional parameters to customize the API call.
     * @param int|null $project_id The project ID for tracking purposes (optional).
     * @return array The response from the AI API or an error message.
     */
    public function callAI($model, $params = [], $project_id = null)
    {
        $retries = 2; // Maximum number of retries
        $attempt = 0;

        while ($attempt <= $retries) {
            try {
                // Ensure the secure chat AI is initialized
                $this->initSecureChatAI();
                $config = $this->getModelConfig();
                // Check if model is supported
                if (!isset($config[$model])) {
                    throw new Exception('Unsupported model: ' . $model);
                }

                $modelConfig = $config[$model];
                $api_endpoint = $this->{$modelConfig['endpoint']}();
                $auth_key_name = $modelConfig["auth_key_name"];
                $headers = ['Content-Type: application/json', 'Accept: application/json'];

                // Ensure required parameters are provided
                foreach ($modelConfig['required'] as $param) {
                    if (empty($params[$param])) {
                        throw new Exception('Missing required parameter: ' . $param);
                    }
                }

                // Prepare API key and URL
                if ($model === "gpt-4o") {
                    $data = array_merge($this->getDefaultParams(), $params);
                    $api_key = $this->getApiKey();
                    $api_endpoint .= "&$auth_key_name=$api_key";
                } else if ($model === "ada-002") {
                    $data = $params;
                    $api_key = $this->getApiEmbeddingsKey();
                    $headers[] = "$auth_key_name: $api_key";
                } else if ($model === "whisper") {
                    $api_key = $this->getApiWhisperKey();
                    $data = $params;
                    $api_endpoint .= "&$auth_key_name=$api_key";
                } else {
                    throw new Exception('Unsupported model: ' . $model);
                }

                $api_url = $api_endpoint ;

                // Set up cURL request
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
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

                // Parse the response
                $responseData = json_decode($response, true);
//                $this->emDebug("gpt response", $responseData);

                // Log interaction only if project_id is available
                if (!empty($project_id)) {
                    $this->logInteraction($project_id, $params, $responseData);
                } else {
                    $this->emDebug("Skipping logging due to missing project ID (pid).");
                }

                return $responseData;
            } catch (\Exception $e) {
                $attempt++;
                $this->emDebug("Attempt $attempt: Error", $e->getMessage());

                if ($attempt > $retries) {
                    $error = [
                        'error' => true,
                        'message' => "Error after $retries retries: " . $e->getMessage()
                    ];

                    // Log error interaction only if project_id is available
                    if (!empty($project_id)) {
                        $this->logErrorInteraction($project_id, $params, $error);
                    } else {
                        $this->emDebug("Skipping error logging due to missing project ID (pid).");
                    }

                    return $error;
                }
            }
        }
    }


    /*
     * Adds whisper parameters to multipart data array
     */
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

    /**
     * Log the interaction with the AI API.
     *
     * @param int|null $project_id The project ID for tracking purposes.
     * @param array $requestData The request data sent to the API.
     * @param array $responseData The response data received from the API.
     */
    private function logInteraction($project_id, $requestData, $responseData)
    {
        // Save every data point in log table
        $payload = array_merge($requestData, $responseData);
        $payload['project_id'] = $project_id;
        $action = new SecureChatLog($this);

        // Message capacity is currently ~16mb or 16 million characters
        $action->setValue('message', json_encode($payload));
        $action->setValue('record', 'SecureChatLog');
        $action->save();
    }

    private function logErrorInteraction($project_id, $requestData, $error){
        $payload = array_merge($requestData, $error);
        $payload['project_id'] = $project_id;
        $action = new SecureChatLog($this);

        // Message capacity is currently ~16mb or 16 million characters
        $action->setValue('message', json_encode($error));
        $action->setValue('record', 'SecureChatLogError');
        $action->save();
    }

    /**
     * Extract the main response text from the API response.
     *
     * @param array $response The API response.
     * @return string The extracted response text.
     */
    public function extractResponseText($response)
    {
        return $response['choices'][0]['message']['content'] ?? json_encode($response);
    }

    /**
     * Extract the usage tokens from the API response.
     *
     * @param array $response The API response.
     * @return array The extracted usage data.
     */
    public function extractUsageTokens($response)
    {
        return $response['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    }

    /**
     * Extract metadata from the API response.
     *
     * @param array $response The API response.
     * @return array The extracted metadata.
     */
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

    /**
     * @return string
     */
    public function getApiAiUrl()
    {
        return $this->api_ai_url;
    }

    /**
     * @param string $url
     */
    public function setApiAiUrl(string $url): void
    {
        $this->api_ai_url = $url;
    }

    /**
     * @return string
     */
    public function getApiEmbeddingsUrl()
    {
        return $this->api_embeddings_url;
    }

    /**
     * @param ?string $api_embeddings_url
     */
    public function setApiEmbeddingsUrl(?string $api_embeddings_url): void
    {
        $this->api_embeddings_url = $api_embeddings_url;
    }

    public function getApiWhisperUrl()
    {
        return $this->api_whisper_url;
    }

    /**
     * @param ?string $api_whisper_url
     */
    public function setApiWhisperUrl(?string $api_whisper_url): void
    {
        $this->api_whisper_url = $api_whisper_url;
    }

    /**
     * @return string
     */
    public function getApiKey()
    {
        return $this->api_key;
    }

    /**
     * @param string $api_key
     */
    public function setApiKey(string $api_key): void
    {
        $this->api_key = $api_key;
    }

    /**
     * @return string
     */
    public function getApiEmbeddingsKey()
    {
        return $this->api_embeddings_key;
    }

    /**
     * @param string $api_key
     */
    public function setApiEmbeddingsKey(string $api_key): void
    {
        $this->api_embeddings_key = $api_key;
    }

    /**
     * @return string
     */
    public function getApiWhisperKey()
    {
        return $this->api_whisper_key;
    }

    /**
     * @param ?string $api_key
     */
    public function setApiWhisperKey(?string $api_key): void
    {
        $this->api_whisper_key = $api_key;
    }


    /**
     * @return array
     */
    public function getDefaultParams()
    {
        return $this->defaultParams;
    }

    /**
     * @param array $defaultParams
     */
    public function setDefaultParams(array $defaultParams): void
    {
        $this->defaultParams = $defaultParams;
    }

    /**
     * @return Client
     */
    public function getGuzzleClient()
    {
        if (!$this->guzzleClient) {
            $this->setGuzzleClient(new Client([
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => false
            ]));
        }
        return $this->guzzleClient;
    }

    /**
     * @param Client $guzzleClient
     */
    public function setGuzzleClient(Client $guzzleClient): void
    {
        $this->guzzleClient = $guzzleClient;
    }

    /**
     * @param array $config
     * @return void
     */
    public function setModelConfig(array $config): void
    {
        $this->modelConfig = $config;
    }

    /**
     * @return array
     */
    public function getModelConfig(): array
    {
        return $this->modelConfig;
    }

    public function setGuzzleTimeout($float)
    {
        $this->guzzleTimeout = $float;
    }

    public function getGuzzleTimeout(): float
    {
        return $this->guzzleTimeout;
    }

    public function getSecureChatLogObject()
    {
        return new SecureChatLog($this);
    }
}
?>
