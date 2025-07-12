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
        $apiEndpoint = $this->appendAuthKey($apiEndpoint);
        $requestData = $this->prepareRequestData($params);
        $response = $this->executeApiCall($apiEndpoint, $requestData);
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON decode error in GPTModelRequest: " . json_last_error_msg());
        }

        return $decoded;
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
    private function prepareRequestData(array $params): string
    {
        $mergedParams = array_merge($this->defaultParams, $params);
        unset($mergedParams["reasoning_effort"]); // Remove unsupported key for GPT-4o
        return json_encode($mergedParams) ?: '[]';
    }
}
