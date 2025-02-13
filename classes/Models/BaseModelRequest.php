<?php

namespace Stanford\SecureChatAI;


use Exception;

/**
 * Supported Models : gpt-4o , ada-002
 */

abstract class BaseModelRequest implements ModelInterface {
    protected string $apiEndpoint;
    protected string $apiKey;
    protected string $modelId;
    protected array $headers = ['Content-Type: application/json', 'Accept: application/json'];
    protected array $defaultParams = [];
    protected string $auth_key_name;
    protected \Stanford\SecureChatAI\SecureChatAI $module;

    public function __construct($module, array $modelConfig, array $defaultParams) {
        $this->module = $module;
        $this->apiEndpoint = $modelConfig['api_url'];
        $this->apiKey = $modelConfig['api_token'];
        $this->modelId = $modelConfig['model_id'];
        $this->auth_key_name = $modelConfig['api_key_var'];
        $this->defaultParams = $defaultParams;

    }

    public function validateParams(array $params): void {
        try{
            foreach ($this->requiredParams() as $param) {
                if (empty($params[$param])) {
                    throw new Exception("Missing required parameter: $param");
                }
            }
        } catch (\Exception $e) {
            $this->module->emError("$e");
        }
    }

    public function setHeaders($headers): void
    {
        $this->headers = $headers;
    }


    protected function requiredParams(): array {
        return [];
    }

//    public function sendRequest(string $apiEndpoint, array $params): array {
//        // Common API execution logic (e.g., using cURL)
//        $apiEndpoint .= (!str_contains($apiEndpoint, '?') ? '?' : '&') . "$this->auth_key_name=$this->apiKey";
//        $mergedParams = array_merge($this->defaultParams, $params);
//        unset($mergedParams["reasoning_effort"]);
//        $data = json_encode($mergedParams) ?? [];
//
//        $responseData = $this->module->executeApiCall($apiEndpoint, $this->headers, $data);
//
//        return $responseData; // Replace with actual API call logic
//    }

    /**
     * @throws Exception
     */
    public function executeAPICall(string $apiEndpoint, string $params): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

        // TODO: Remove this block once proper DNS resolution is restored
        curl_setopt($ch, CURLOPT_RESOLVE, [
            'apim.stanfordhealthcare.org:443:10.249.134.5',
            'som-redcap-whisper.openai.azure.com:443:10.153.192.4',
            'som-redcap.openai.azure.com:443:10.249.50.7'
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new \Exception('cURL error: ' . curl_error($ch));
        }

        if ($http_code < 200 || $http_code >= 300) {
            throw new Exception('HTTP error: ' . $http_code . ' - Response: ' . $response);
        }

        curl_close($ch);

        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }
        return $decodedResponse;
    }
    public static function normalizeResponse(array $response): array {
        return $response; // Override in subclasses as needed
    }
}
