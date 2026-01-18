<?php
namespace Stanford\SecureChatAI;

class GenericModelRequest extends BaseModelRequest
{
    public function __construct($module, array $modelConfig, array $defaultParams, string $model) {
        parent::__construct($module, $modelConfig, $defaultParams, $model);
    }
    
    public function sendRequest(string $apiEndpoint, array $params): array
    {
        $mergedParams = array_merge($this->defaultParams, $params);
        $mergedParams['model'] = $this->modelId;

        // Handle json_schema wrapping with proper OpenAI format
        if (isset($mergedParams['json_schema'])) {
            $mergedParams['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'agent_response',
                    'strict' => true,
                    'schema' => $mergedParams['json_schema']
                ]
            ];
            unset($mergedParams['json_schema']);
        }


        $postfields = json_encode($mergedParams);

        // Dynamically decide if the key should be in header or query string
        $keyHeaderName = $this->auth_key_name;
        $headers = ["Content-Type: application/json", "Accept: application/json"];

        if (str_starts_with(strtolower($keyHeaderName), 'ocp-') || str_contains($keyHeaderName, 'Subscription')) {
            // Azure-style: send as header
            $headers[] = "$keyHeaderName: {$this->apiKey}";
        } else {
            // Legacy OpenAI style: send as query string
            $separator = str_contains($apiEndpoint, '?') ? '&' : '?';
            $apiEndpoint .= "{$separator}{$keyHeaderName}={$this->apiKey}";
        }

        $rawResponse = $this->executeAPICall($apiEndpoint, $postfields, $headers);
        return json_decode($rawResponse, true);
    }

}
