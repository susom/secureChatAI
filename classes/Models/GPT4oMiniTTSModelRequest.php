<?php

namespace Stanford\SecureChatAI;

class GPT4oMiniTTSModelRequest extends BaseModelRequest
{
    public function sendRequest(string $apiEndpoint, array $params): array
    {
        $startTime = microtime(true);

        $data = [
            'model'  => $params['model'] ?? 'gpt-4o-mini-tts',
            'input'  => $params['input'],
            'voice'  => $params['voice'] ?? 'alloy',
        ];
        if (isset($params['instructions'])) {
            $data['instructions'] = $params['instructions'];
        }
        $payload = json_encode($data);

        $headers = [
            "Ocp-Apim-Subscription-Key: {$this->apiKey}",
            "Content-Type: application/json",
        ];

        // Log outgoing payload
        if (method_exists($this->module, 'emDebug')) {
            $this->module->emDebug('TTS REQUEST', [
                'url' => $apiEndpoint,
                'payload' => $payload,
            ]);
        }

        $ch = curl_init($apiEndpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $raw_headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        $contentType = '';
        foreach (explode("\r\n", $raw_headers) as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, 13));
                break;
            }
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $elapsed = microtime(true) - $startTime;

        // Log raw response info
        if (method_exists($this->module, 'emDebug')) {
            $this->module->emDebug('TTS RESPONSE', [
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'elapsed_sec' => round($elapsed, 3),
                'body_length' => strlen($body),
                'curl_error' => $curlErr,
                // Only log a snippet of the body for debugging
                'body_snippet' => substr($body, 0, 200)
            ]);
        }

        if (strpos($contentType, 'audio') !== false && strlen($body) > 0) {
            return [
                'audio_base64' => base64_encode($body),
                'content_type' => $contentType,
                'success' => true
            ];
        } else {
            $error = json_decode($body, true);
            return [
                'success' => false,
                'error' => $error,
                'raw_body' => substr($body, 0, 500),
                'http_code' => $httpCode
            ];
        }
    }

}
