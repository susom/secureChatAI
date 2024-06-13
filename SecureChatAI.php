<?php
namespace Stanford\SecureChatAI;

require_once "emLoggerTrait.php";
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SecureChatAI extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    private $api_ai_url;
    private $api_embeddings_url;
    private $api_key;
    private $defaultParams;
    private $guzzleClient;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
        // Trying second commit
	}

    public function initSecureChatAI() {
        $this->api_ai_url = $this->getSystemSetting('secure-chat-api-url');
        $this->api_embeddings_url = $this->getSystemSetting('secure-chat-embeddings-api-url');
        $this->api_key = $this->getSystemSetting('secure-chat-api-token');

        $this->defaultParams = [
            'temperature' => 0.7,
            'top_p' => 0.95,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
            'max_tokens' => 800,
            'stop' => null
        ];

        $this->guzzleClient = new Client([
            'timeout' => 30,
            'connect_timeout' => 5,
            'verify' => false
        ]);
    }

    /**
     * Call the AI API with the provided messages and parameters.
     *
     * @param array $messages The messages to send to the AI.
     * @param array $params Additional parameters to customize the API call.
     * @return mixed The response from the AI API or an error message.
     */
    public function callAI($model, $messages, $input = '', $params = []) {
        // Ensure the secure chat AI is initialized
        if(!$this->guzzleClient) {
            $this->initSecureChatAI();
        }

        $data = array_merge($this->defaultParams, $params);
        if ($model == "gpt-4o") {
            $data['messages'] = $messages;
        } elseif($model == "ada-002"){
            $data['input'] = $input;
        }

        $api_endpoint = $this->api_ai_url;
        if($model == "ada-002"){
            $api_endpoint = $this->api_embeddings_url;
        }

        try {
            $response = $this->guzzleClient->request('POST', $api_endpoint . '&api-key=' . $this->api_key, [
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
            $this->emError("General error: " . $e->getMessage());
            return [
                'error' => true,
                'message' => "General error: " . $e->getMessage()
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
}
