<?php

namespace Stanford\SecureChatAI;


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
    protected $module;

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

    public static function normalizeResponse(array $response): array {
        return $response; // Override in subclasses as needed
    }
}
