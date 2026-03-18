<?php
namespace Stanford\SecureChatAI;

class ClaudeModelRequest extends BaseModelRequest
{
    public function sendRequest(string $apiEndpoint, array $params): array
    {
        if (empty($params['messages'])) {
            throw new \Exception('Claude API requires messages in the request body.');
        }

        // Extract system messages into top-level system param (Anthropic Messages API format)
        $system = '';
        $messages = [];
        foreach ($params['messages'] as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $system .= (empty($system) ? '' : "\n\n") . trim($msg['content']);
            } else {
                // Anthropic expects content as string or array of content blocks
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
        }

        $payload = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'messages' => $messages,
            'max_tokens' => (int)($params['max_tokens'] ?? $this->defaultParams['max_tokens'] ?? 4096),
        ];

        if (!empty($system)) {
            $payload['system'] = $system;
        }

        // Optional parameters
        if (isset($params['temperature']) || isset($this->defaultParams['temperature'])) {
            $payload['temperature'] = (float)($params['temperature'] ?? $this->defaultParams['temperature']);
        }
        if (isset($params['top_p']) || isset($this->defaultParams['top_p'])) {
            $payload['top_p'] = (float)($params['top_p'] ?? $this->defaultParams['top_p']);
        }

        $postfields = json_encode($payload);

        $headers = [
            "Content-Type: application/json",
            "{$this->auth_key_name}: {$this->apiKey}"
        ];

        $this->module->emDebug("Sending ClaudeModelRequest (Bedrock)", [
            'endpoint' => $apiEndpoint,
            'model_id' => $this->modelId,
            'message_count' => count($messages),
            'has_system' => !empty($system)
        ]);

        $rawResponse = $this->executeAPICall($apiEndpoint, $postfields, $headers);
        return json_decode($rawResponse, true);
    }
}
