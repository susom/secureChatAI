<?php

namespace Stanford\SecureChatAI;

class WhisperModelRequest extends BaseModelRequest
{
    /**
     * Sends an API request after merging default parameters and appending authentication details.
     *
     * @param string $apiEndpoint The API endpoint URL.
     * @param array $params The request parameters.
     * @return array The API response.
     * @throws \Exception If the request fails.
     */
    public function sendRequest(string $apiEndpoint, array $params): array {
        $apiEndpoint = $this->appendAuthKey($apiEndpoint);
        $requestData = $this->prepareRequestData($params);

        // Set required headers for Whisper requests
        $this->setHeaders(['Content-Type: multipart/form-data', 'Accept: application/json']);

        $rawResponse = $this->executeApiCall($apiEndpoint, $requestData);

        $format = strtolower($params['response_format'] ?? 'json');
        if (in_array($format, ['srt', 'vtt', 'text'])) {
            return ['text' => $rawResponse, 'format' => $format];
        }

        $decoded = json_decode($rawResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON decode error in WhisperModelRequest: " . json_last_error_msg());
        }

        return $decoded;
    }
    

    private function appendAuthKey(string $apiEndpoint): string
    {
        $separator = str_contains($apiEndpoint, '?') ? '&' : '?';
        return "{$apiEndpoint}{$separator}{$this->auth_key_name}={$this->apiKey}";
    }

    private function prepareRequestData(array $params): array
    {
        $file = null;

        if (!empty($params['fileBase64']) && !empty($params['fileName'])) {
            $decodedFile = base64_decode($params['fileBase64']);
            if ($decodedFile === false) {
                throw new \Exception("Whisper: Failed to decode Base64 file data.");
            }
            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('whisper_', true) . '_' . $params['fileName'];
            if (file_put_contents($tempFilePath, $decodedFile) === false) {
                throw new \Exception("Whisper: Failed to save decoded file to temporary path.");
            }
            register_shutdown_function(fn() => file_exists($tempFilePath) && unlink($tempFilePath));
            $file = $tempFilePath;
        } elseif (!empty($params['file']) && file_exists($params['file'])) {
            $file = $params['file'];
        } else {
            throw new \Exception("Whisper: File not found or invalid input. Provide either a file path or Base64 data.");
        }

        $curlFile = curl_file_create($file, mime_content_type($file), basename($file));
        return [
            'file' => $curlFile,
            'language' => $params['language'] ?? 'en',
            'temperature' => $params['temperature'] ?? '0.0',
            'response_format' => $params['response_format'] ?? 'json'
        ];
    }
}
