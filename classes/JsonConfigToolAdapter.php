<?php

namespace Stanford\SecureChatAI;

/**
 * Adapter that wraps a JSON-configured tool (from agent_tool_registry)
 * to implement ToolInterface.
 *
 * This bridges the existing JSON registry into the typed pipeline
 * without requiring every tool EM to implement ToolInterface.
 */
class JsonConfigToolAdapter implements ToolInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return $this->config['name'] ?? 'unknown';
    }

    public function inputSchema(): array
    {
        return $this->config['parameters'] ?? ['type' => 'object', 'properties' => []];
    }

    public function description(): string
    {
        return $this->config['description'] ?? '';
    }

    public function validateInput(array $input, ToolContext $context): ?string
    {
        $required = $this->config['parameters']['required'] ?? [];
        $missing = array_diff($required, array_keys($input));

        if (!empty($missing)) {
            return "Missing required parameters: " . implode(", ", $missing);
        }

        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isDestructive(): bool
    {
        return false;
    }

    /**
     * Execute via the appropriate endpoint (module_api or redcap_api).
     *
     * The SecureChatAI instance is passed via ToolContext metadata
     * so we don't need a hard dependency on it here.
     */
    public function call(array $input, ToolContext $context): array
    {
        $endpoint = $this->config['endpoint'] ?? '';

        if ($endpoint === 'module_api') {
            return $this->callModuleApi($input, $context);
        }

        if ($endpoint === 'redcap_api') {
            return $this->callRedcapApi($input, $context);
        }

        throw new \RuntimeException("Unsupported tool endpoint: {$endpoint}");
    }

    private function callModuleApi(array $input, ToolContext $context): array
    {
        $module = $context->get('secure_chat_ai_instance');
        if (!$module) {
            throw new \RuntimeException("SecureChatAI instance not available in ToolContext");
        }

        return $module->redcap_module_api(
            $this->config['module']['action'],
            $input
        );
    }

    private function callRedcapApi(array $input, ToolContext $context): array
    {
        $module = $context->get('secure_chat_ai_instance');
        if (!$module) {
            throw new \RuntimeException("SecureChatAI instance not available in ToolContext");
        }

        $apiUrl   = rtrim($module->getSystemSetting('agent_tools_redcap_api_url'), '/') . '/';
        $apiToken = $module->getSystemSetting('agent_tools_project_api_key');

        if (empty($apiUrl) || empty($apiToken)) {
            throw new \RuntimeException(
                "Missing agent_tools_redcap_api_url or agent_tools_project_api_key"
            );
        }

        $payload = array_merge([
            'token'        => $apiToken,
            'content'      => 'externalModule',
            'format'       => 'json',
            'returnFormat' => 'json',
            'prefix'       => $this->config['redcap']['prefix'],
            'action'       => $this->config['redcap']['action'],
        ], $input);

        $guzzle = $context->get('guzzle_client');
        if (!$guzzle) {
            throw new \RuntimeException("Guzzle client not available in ToolContext");
        }

        $response = $guzzle->post($apiUrl, [
            'form_params' => $payload,
            'timeout'     => 10,
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
