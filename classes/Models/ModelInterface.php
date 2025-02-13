<?php

namespace Stanford\SecureChatAI;

interface ModelInterface
{
    public function validateParams(array $params): void;
    public function setHeaders(array $headers): void;
    public static function normalizeResponse(array $response): array;
    public function sendRequest(string $apiEndpoint, array $params);
}
