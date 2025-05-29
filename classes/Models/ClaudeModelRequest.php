<?php
namespace Stanford\SecureChatAI;

class ClaudeModelRequest extends BaseModelRequest
{
    public function sendRequest(string $apiEndpoint, array $params): array
    {
        // Format messages to Claude's expected prompt_text
        $prompt_text = isset($params['messages'])
            ? $this->formatMessagesForClaude($params['messages'])
            : ($params['prompt_text'] ?? '');

        if (empty($prompt_text)) {
            throw new \Exception('Claude API requires prompt_text in the request body.');
        }

        // Claude's tuning parameters must be nested under 'parameters'
        $parameters = [
            "temperature" => $params['temperature'] ?? $this->defaultParams['temperature'],
            "top_p"       => $params['top_p'] ?? $this->defaultParams['top_p'],
            "max_tokens"  => $params['max_tokens'] ?? $this->defaultParams['max_tokens']
        ];

        $payload = [
            "model_id"    => $this->modelId,
            "prompt_text" => $prompt_text,
            "parameters"  => $parameters
        ];

        $postfields = json_encode($payload);

        // Use API key in header (e.g. Ocp-Apim-Subscription-Key)
        $headers = [
            "Content-Type: application/json",
            "{$this->auth_key_name}: {$this->apiKey}"
        ];

        $this->module->emDebug("Sending ClaudeModelRequest", [
            'endpoint'   => $apiEndpoint,
            'headers'    => $headers,
            'postfields' => $payload
        ]);

        $rawResponse = $this->executeAPICall($apiEndpoint, $postfields, $headers);
        return json_decode($rawResponse, true);
    }

    private function formatMessagesForClaude(array $messages): string
    {
        $formatted = [];
        foreach ($messages as $message) {
            $role = ucfirst($message['role']);
            $content = trim($message['content']);
            $formatted[] = "{$role}: {$content}";
        }
        return implode("\n\n", $formatted);
    }
}
