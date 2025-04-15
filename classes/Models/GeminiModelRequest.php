<?php

namespace Stanford\SecureChatAI;

class GeminiModelRequest extends BaseModelRequest {
    private $em;

    public function __construct($em, array $modelConfig, array $defaultParams) {
        parent::__construct($em, $modelConfig, $defaultParams); // pass $em as $module
        $this->em = $em;
    }
    

    public function sendRequest(string $apiEndpoint, array $params): array {
        $payload = $this->prepareRequestData($params);
        $headers = [
            "Content-Type: application/json",
            "{$this->auth_key_name}: {$this->apiKey}" 
        ];

        $rawResponse = $this->executeApiCall($apiEndpoint, $payload, $headers);

        $decoded = json_decode($rawResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Gemini JSON decode error: " . json_last_error_msg());
        }

        return $decoded;
    }

    private function appendAuthKey(string $apiEndpoint): string {
        return $apiEndpoint;
    }

    private function prepareRequestData(array $params): string {
        $messages = $params['messages'] ?? [];

        $geminiMessages = [];
        $systemContext = "";

        foreach ($messages as $msg) {
            if ($msg["role"] === "system") {
                $systemContext .= trim($msg["content"]) . "\n\n";
            } else {
                $geminiMessages[] = [
                    "role" => $msg["role"],
                    "parts" => [["text" => trim((string) $msg["content"])]],
                ];
            }
        }

        if (!empty($systemContext)) {
            if (!empty($geminiMessages) && $geminiMessages[0]["role"] === "user") {
                $geminiMessages[0]["parts"][0]["text"] = $systemContext . $geminiMessages[0]["parts"][0]["text"];
            } else {
                array_unshift($geminiMessages, [
                    "role" => "user",
                    "parts" => [["text" => $systemContext]]
                ]);
            }
        }

        $payload = [
            "contents" => $geminiMessages,
            "generation_config" => [
                "temperature" => $params['temperature'] ?? $this->defaultParams['temperature'],
                "topP" => $params['top_p'] ?? $this->defaultParams['top_p'],
                "topK" => 40,
                "maxOutputTokens" => $params['max_tokens'] ?? $this->defaultParams['max_tokens'],
                "frequencyPenalty" => $params['frequency_penalty'] ?? $this->defaultParams['frequency_penalty'],
                "presencePenalty" => $params['presence_penalty'] ?? $this->defaultParams['presence_penalty'],
            ],
            "safety_settings" => [[
                "category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT",
                "threshold" => "BLOCK_LOW_AND_ABOVE"
            ]]
        ];

        return json_encode($payload);
    }
}