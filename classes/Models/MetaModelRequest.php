<?php

namespace Stanford\SecureChatAI;

class MetaModelRequest extends BaseModelRequest {

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
        $requestData = $this->prepareNewRequestData($params);

        // Grab required embedded strings
        $auth_key_name = $this->auth_key_name;
        $api_key = $this->apiKey;

        // Set headers explicit to llama / o1 / 03-mini
        $this->setHeaders(['Content-Type: application/json', "$auth_key_name: $api_key"]);
        return $this->executeApiCall($apiEndpoint, $requestData);
    }

    /**
     * Standard merge of parameters for llama
     * @param array $params
     * @return string
     */
    private function prepareNewRequestData(array $params): string
    {
        $mergedParams = array_merge($this->defaultParams, $params);
        $mergedParams["model"] = $this->modelId;
        $mergedParams["messages"] = $params["messages"] ?? [];
        return json_encode($mergedParams);
    }
}
