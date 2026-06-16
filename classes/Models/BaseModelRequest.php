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
    protected string $model;

    public function __construct($module, array $modelConfig, array $defaultParams, string $model) {
        $this->module = $module;
        $this->apiEndpoint = trim($modelConfig['api_url']);
        $this->apiKey = trim($modelConfig['api_token']);
        $this->modelId = trim($modelConfig['model_id']);
        $this->auth_key_name = trim($modelConfig['api_key_var']);
        $this->defaultParams = $defaultParams;
        $this->model = $model;
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

    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }


    protected function requiredParams(): array {
        return [];
    }

    /**
     * @throws Exception
     */
    public function executeAPICall(string $apiEndpoint, string|array $params, array $headers = null): string {
        // Sanitize URL: strip any non-printable/invisible characters
        $apiEndpoint = preg_replace('/[^\x20-\x7E]/', '', $apiEndpoint);
        $apiEndpoint = trim($apiEndpoint);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?? $this->headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 500);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        // Apply DNS override if configured (for site-to-site VPN routing)
        $dnsOverrideIp = $this->module->getSystemSetting('apim_dns_override_ip');
        if (!empty($dnsOverrideIp)) {
            $host = parse_url($apiEndpoint, PHP_URL_HOST);
            $port = parse_url($apiEndpoint, PHP_URL_PORT) ?: 443;
            if ($host) {
                curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$dnsOverrideIp}"]);
            }
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
            curl_close($ch);
            throw new \Exception('cURL error: ' . $curlError);
        }

        if ($http_code < 200 || $http_code >= 300) {
            // PHI-safe: do not embed the raw response body in the exception message — it can
            // echo prompt/response content (PHI) into upstream debug logs (see callAI catch).
            $responseLength = strlen((string) $response);
            curl_close($ch);
            throw new Exception('HTTP error: ' . $http_code
                . ' (response body omitted; length=' . $responseLength . ' bytes)');
        }

        curl_close($ch);

        return $response;
    }
    public static function normalizeResponse(array $response): array {
        return $response; // Override in subclasses as needed
    }

    public function getAuthKeyName(): string
    {
        return $this->auth_key_name;
    }

    public function setAuthKeyName(string $auth_key_name): void
    {
        $this->auth_key_name = $auth_key_name;
    }
}
