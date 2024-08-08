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
    private string $api_key;

    private array $defaultParams;
    private $guzzleClient = null;
    private $guzzleTimeout = 5.0;
    private $modelConfig = [
        'gpt-4o' => [
            'endpoint' => 'getApiAiUrl',
            'required' => ['messages']
        ],
        'ada-002' => [
            'endpoint' => 'getApiEmbeddingsUrl',
            'required' => ['input']
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
        $this->setApiKey($this->getSystemSetting('secure-chat-api-token'));

        //Set default model parameters
        $this->setDefaultParams([
            'model' => $this->getSystemSetting('gpt-model') ?: 'gpt-4o',
            'temperature' => (float)$this->getSystemSetting('gpt-temperature') ?: 0.7,
            'top_p' => (float)$this->getSystemSetting('gpt-top-p') ?: 0.9,
            'frequency_penalty' => (float)$this->getSystemSetting('gpt-frequency-penalty') ?: 0.5,
            'presence_penalty' => (float)$this->getSystemSetting('gpt-presence-penalty') ?: 0,
            'max_tokens' => (int)$this->getSystemSetting('gpt-max-tokens') ?: 800,
            'stop' => null  // Assuming stop is not configurable and kept at default
        ]);

        //Set guzzle timeout
        $timeout = $this->getProjectSetting('guzzle-timeout') ? (float)(strip_tags($this->getProjectSetting('guzzle-timeout'))) : $this->getGuzzleTimeout();
        $this->setGuzzleTimeout($timeout);

        $this->guzzleClient = $this->getGuzzleClient();
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getSecureChatLogs(){
        return SecureChatLog::getLogs($this, '52');
    }

    /**
     * Call the AI API with the provided messages and parameters.
     *
     * @param string $model The model to be used for the API call.
     * @param array $params Additional parameters to customize the API call.
     * @param int|null $project_id The project ID for tracking purposes.
     * @return array The response from the AI API or an error message.
     */
    public function callAI($model, $params = [], $project_id = null)
    {
        $retries = 2;  // Maximum number of retries
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

                // Ensure required parameters are provided
                foreach ($modelConfig['required'] as $param) {
                    if (empty($params[$param])) {
                        throw new Exception('Missing required parameter: ' . $param);
                    }
                }

                $data = array_merge($this->getDefaultParams(), $params);
                $response = $this->getGuzzleClient()->request('POST', $api_endpoint . '&subscription-key=' . $this->api_key, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ],
                    'json' => $data,
                    'timeout' => $this->getGuzzleTimeout()
                ]);

                $responseData = json_decode($response->getBody(), true);

                // Log interaction (placeholder)
                $this->logInteraction($project_id, $data, $responseData);

                return $responseData;

            } catch (GuzzleException $e) {
                $attempt++;
                $this->emError("Attempt $attempt: Guzzle error: " . $e->getMessage());

                if ($attempt > $retries) {
                    return [
                        'error' => true,
                        'message' => "Guzzle error after $retries retries: " . $e->getMessage()
                    ];
                }
//                sleep(2);  // Wait for 2 seconds before retrying
            } catch (\Exception $e) {
                $this->emError("Error: in SecureChat: " . $e->getMessage());
                return [
                    'error' => true,
                    'message' => "Error in SecureChat: " . $e->getMessage()
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

//        if($project_id && $requestData["model"] == "gpt-4o" && $log_project_id = $this->getSystemSetting('interaction-log-project-id')){
//            $usage = $this->extractUsageTokens($responseData);
//
//            //TODO NEED TO GET A SUMMARY OF THE INTERACTION EH?
//            $last_query = array_pop($requestData["messages"]);
//            $summary = "Q: ". $last_query["content"] . "\n\n" . "A: " . $this->extractResponseText($responseData);
//
//            // Prepare data for REDCap
//            $data = [
//                'record_id' => $responseData["id"],
//                'project_id' => $project_id,
//                'interaction_ts' => date('Y-m-d H:i:s'),
//                'model' => $responseData["model"],
//                'input_tokens' => $usage['prompt_tokens'],
//                'completion_tokens' => $usage['completion_tokens'],
//                'interaction_summary' => $summary
//            ];
//
//            // Use REDCap's saveData function to save the interaction
//            $response = \REDCap::saveData($log_project_id, 'json', json_encode([$data]));
//
//            if (!empty($response['errors'])) {
//                $this->emError("Error logging interaction to REDCap: " . json_encode($response['errors']));
//            }
//        }
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
     * @param string $api_embeddings_url
     */
    public function setApiEmbeddingsUrl(string $api_embeddings_url): void
    {
        $this->api_embeddings_url = $api_embeddings_url;
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
                'connect_timeout' => 5,
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
}
?>
