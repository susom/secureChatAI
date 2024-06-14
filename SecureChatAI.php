<?php
namespace Stanford\SecureChatAI;

require_once "emLoggerTrait.php";

use Google\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SecureChatAI extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    private string $api_ai_url;
    private string $api_embeddings_url;
    private string $api_key;
    private array $defaultParams;
    private $guzzleClient = null;
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

    public function __construct() {
		parent::__construct();
	}

    public function initSecureChatAI() {
        $this->setApiAiUrl($this->getSystemSetting('secure-chat-api-url'));
        $this->setApiEmbeddingsUrl($this->getSystemSetting('secure-chat-embeddings-api-url'));
        $this->setApiKey($this->getSystemSetting('secure-chat-api-token'));
        $this->setDefaultParams([
            'temperature' => 0.7,
            'top_p' => 0.95,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
            'max_tokens' => 800,
            'stop' => null
        ]);
        $this->guzzleClient = $this->getGuzzleClient();
    }

    /**
     * Call the AI API with the provided messages and parameters.
     *
     * @param array $messages The messages to send to the AI.
     * @param array $params Additional parameters to customize the API call.
     * @return mixed The response from the AI API or an error message.
     */
    public function callAI($model, $params = []) {
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

            $response = $this->getGuzzleClient()->request('POST', $api_endpoint . '&api-key=' . $this->api_key, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $data
            ]);

            return json_decode($response->getBody(), true);

        } catch (GuzzleException $e) {
            $this->emError("Guzzle error: " . $e->getMessage());
            return [
                'error' => true,
                'message' => "Guzzle error: " . $e->getMessage()
            ];
        } catch (\Exception $e) {
            $this->emError("Error: in SecureChat: " . $e->getMessage());
            return [
                'error' => true,
                'message' => "Error in SecureChat: " . $e->getMessage()
            ];
        }
    }

    /**
     * Extract the main response text from the API response.
     *
     * @param array $response The API response.
     * @return string The extracted response text.
     */
    public function extractResponseText($response)
    {
        return $response['choices'][0]['message']['content'] ?? 'No content available';
    }

    /**
     * Extract the usage tokens from the API response.
     *
     * @param array $response The API response.
     * @return mixed The extracted usage data or a default message.
     */
    public function extractUsageTokens($response)
    {
        return $response['usage'] ?? 'No usage data available';
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
}
