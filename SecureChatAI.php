<?php
namespace Stanford\SecureChatAI;

require_once "emLoggerTrait.php";
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SecureChatAI extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    private $api_url;
    private $api_key;
    private $defaultParams;
    private $guzzleClient;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}

    public function initSecureChatAI() {
        $this->api_url = $this->getSystemSetting('secure-chat-api-url');
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

    public function callAI($messages, $params = []) {
        // Ensure the secure chat AI is initialized
        if(!$this->guzzleClient) {
            $this->initSecureChatAI();
        }

        $data = array_merge($this->defaultParams, $params);
        $data['messages'] = $messages;

        try {
            $response = $this->guzzleClient->request('POST', $this->api_url . '&api-key=' . $this->api_key, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $data
            ]);

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            return "Guzzle error: " . $e->getMessage();
        }
    }

    public function extractResponseText($response)
    {
        return $response['choices'][0]['message']['content'] ?? 'No content available';
    }

    public function extractUsageTokens($response)
    {
        return $response['usage'] ?? 'No usage data available';
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
}
