<?php
namespace Stanford\SecureChatAI;

class GenericModelRequest extends BaseModelRequest
{
    public function __construct($module, array $modelConfig, array $defaultParams, string $model) {
        parent::__construct($module, $modelConfig, $defaultParams, $model);
    }
    
    /**
     * Recursively convert empty PHP arrays to stdClass objects
     * so they encode as {} instead of [] in JSON.
     * Preserves non-empty arrays as arrays.
     */
    private function fixEmptyArrays($data, $key = null) {
        if (is_array($data)) {
            // Special case: "required" field must stay as array even if empty
            if ($key === 'required' || $key === 'enum') {
                return $data;  // Keep as array
            }
            
            // Empty array in "properties" context → empty object
            if (empty($data) && $key === 'properties') {
                return new \stdClass();
            }
            
            // Non-empty array → recurse
            foreach ($data as $k => $value) {
                $data[$k] = $this->fixEmptyArrays($value, $k);
            }
        }
        return $data;
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

        // Fix empty arrays before encoding to prevent [] instead of {}
        $mergedParams = $this->fixEmptyArrays($mergedParams);

        // $this->module->emDebug("SENDING TO API (after fixEmptyArrays)", $mergedParams);
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