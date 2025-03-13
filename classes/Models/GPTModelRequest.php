<?php

namespace Stanford\SecureChatAI;

class GPTModelRequest extends BaseModelRequest {

    /**
     * Sends an API request after merging default parameters and appending authentication details.
     *
     * @param string $apiEndpoint The API endpoint URL.
     * @param array $params The request parameters.
     * @return array The API response.
     * @throws \Exception If the request fails.
     */
    public function sendRequest(string $apiEndpoint, array $params): array
    {
        if($this->model === "gpt-4o" || $this->model === "ada-002") { //Old model types have different configuration parameters
            $requestData = $this->prepareOldRequestData($params);
            $apiEndpoint = $this->appendAuthKey($apiEndpoint);
        } else { // o1 , Mini
            $requestData = $this->prepareNewRequestData($params);
        }

        return $this->executeApiCall($apiEndpoint, $requestData);
    }

    /**
     * Appends the authentication key to the API endpoint.
     *
     * @param string $apiEndpoint The API endpoint URL.
     * @return string The updated API endpoint with authentication key.
     */
    private function appendAuthKey(string $apiEndpoint): string
    {
        $separator = str_contains($apiEndpoint, '?') ? '&' : '?';
        return "{$apiEndpoint}{$separator}{$this->auth_key_name}={$this->apiKey}";
    }

    /**
     * Merges default parameters, removes unnecessary fields, and encodes the request data.
     *
     * @param array $params The request parameters.
     * @return string JSON-encoded request data.
     */
    private function prepareOldRequestData(array $params): string
    {
        $mergedParams = array_merge($this->defaultParams, $params);

        // Manually removing default param, breaks regular gpt calls
        unset($mergedParams["reasoning_effort"]);
        return json_encode($mergedParams) ?: '[]';
    }

    /**
     * Sets new parameters based on new API spec o1 , o3-mini
     * @param array $params The request parameters.
     * @return string JSON-encoded request data.
     */
    private function prepareNewRequestData(array $params): string
    {
        // Grab required embedded strings
        $auth_key_name = $this->auth_key_name;
        $api_key = $this->apiKey;

        // Set headers explicit to llama / o1 / 03-mini
        $this->setHeaders(['Content-Type: application/json', "$auth_key_name: $api_key"]);

        //Set other required fields for request
        $params['model'] = $this->model;
        $params['messages'] = !empty($params['messages']) ?? [];
        $params['max_completion_tokens'] = $this->defaultParams['max_tokens'];
        $params['reasoning_effort'] = $params['reasoning_effort'] ?? $this->defaultParams['reasoning_effort'];
        return json_encode($params);
    }
}
