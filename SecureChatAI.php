<?php

namespace Stanford\SecureChatAI;

require_once "emLoggerTrait.php";
require_once "classes/SecureChatLog.php";
require_once "classes/Models/ModelInterface.php";
require_once "classes/Models/BaseModelRequest.php";
require_once "classes/Models/GPTModelRequest.php";
require_once "classes/Models/WhisperModelRequest.php";
require_once "classes/Models/GeminiModelRequest.php";
require_once "classes/Models/ClaudeModelRequest.php";
require_once "classes/Models/GenericModelRequest.php";
require_once "classes/Models/GPT4oMiniTTSModelRequest.php";

use Google\Exception;
use GuzzleHttp\Client;

class SecureChatAI extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private array $defaultParams;
    private $guzzleClient = null;
    private $guzzleTimeout = 5.0;
    private array $modelConfig = [];

    public function __construct()
    {
        parent::__construct();
    }

    private function initSecureChatAI()
    {
        // Set default LLM model parameters
        $this->defaultParams = [
            'temperature' => (float)$this->getSystemSetting('gpt-temperature') ?: 0.7,
            'top_p' => (float)$this->getSystemSetting('gpt-top-p') ?: 0.9,
            'frequency_penalty' => (float)$this->getSystemSetting('gpt-frequency-penalty') ?: 0.5,
            'presence_penalty' => (float)$this->getSystemSetting('gpt-presence-penalty') ?: 0,
            'max_tokens' => (int)$this->getSystemSetting('gpt-max-tokens') ?: 800,
            'reasoning_effort' => $this->getSystemSetting('reasoning-effort'),
            'stop' => null,
            'model' => 'gpt-4o'
        ];

        // Initialize the model configurations from system settings
        $apiSettings = $this->framework->getSubSettings('api-settings');
        foreach ($apiSettings as $setting) {
            $modelAlias = $setting['model-alias'];
            $modelID = $setting['model-id'];
            $this->modelConfig[$modelAlias] = [
                'api_url' => $setting['api-url'],
                'api_token' => $setting['api-token'],
                'api_key_var' => $setting['api-key-var'],
                'required' => $setting['api-input-var'],
                'model_id' => $modelID
            ];
            if (isset($setting['default-model']) && $setting['default-model']) {
                $this->defaultParams['model'] = $modelAlias;
            }
        }

        // Set Guzzle info
        $timeout = $this->getSystemSetting('guzzle-timeout') ? (float)(strip_tags($this->getSystemSetting('guzzle-timeout'))) : $this->getGuzzleTimeout();
        $this->setGuzzleTimeout($timeout);
        $this->guzzleClient = $this->getGuzzleClient();
    }

    public function getSecureChatLogs($offset)
    {
        $offset = intval($offset);
        return SecureChatLog::getAllLogs($this, $offset);
    }

    private function filterDefaultParamsForModel($model, $params)
    {
        $merged = array_merge($this->defaultParams, $params);

        // Only o1/o3-mini get reasoning params
        if (!in_array($model, ['o1', 'o3-mini'])) {
            unset($merged['reasoning']);
            unset($merged['reasoning_effort']);
        }

        // Only models supporting json_schema
        $schemaModels = ['gpt-4.1', 'o1', 'o3-mini', 'llama3370b'];
        if (!in_array($model, $schemaModels)) {
            unset($merged['json_schema']);
        }

        // Only o1/o3-mini have strict param set
        if (in_array($model, ['o1', 'o3-mini'])) {
            $strict = [
                'model' => $model,
                'messages' => $merged['messages'] ?? [],
                'max_completion_tokens' => $merged['max_completion_tokens'] ?? ($merged['max_tokens'] ?? 800),
            ];
            if (isset($merged['reasoning_effort'])) {
                $strict['reasoning_effort'] = $merged['reasoning_effort'];
            }
            return $strict;
        }

        // Remove max_tokens for all non-o1/o3-mini
        unset($merged['max_tokens']);

        return $merged;
    }




    public function callAI($model, $params = [], $project_id = null)
    {
        $retries = 2;
        $attempt = 0;

        while ($attempt <= $retries) {
            try {
                // --- Agent Mode Gate ---
                $agent_mode_requested = !empty($params['agent_mode']);
                $agent_mode_enabled = (bool) $this->getSystemSetting('enable_agent_mode');

                if ($agent_mode_requested && $agent_mode_enabled) {
                    return $this->runAgentLoop(
                        model: $model,
                        params: $params,
                        project_id: $project_id
                    );
                }

                // Normal single-call path
                return $this->callLLMOnce($model, $params, $project_id);

            } catch (\Exception $e) {
                $attempt++;
                $this->emDebug("Attempt $attempt: Error", $e->getMessage());

                if ($attempt > $retries) {
                    $error = [
                        'error' => true,
                        'message' => "Error after $retries retries: " . $e->getMessage()
                    ];
                    if ($project_id) {
                        $this->logErrorInteraction($project_id, $params, $error);
                    } else {
                        $this->emDebug("Skipping error logging due to missing project ID (pid).");
                    }
                    return $error;
                }
            }
        }

        return ['error' => true, 'message' => 'Unknown error'];
    }

    private function loadToolsForProject(?int $pid): array
    {
        if (empty($pid)) return [];

        $json = $this->getSystemSetting('agent_tool_registry');
        if (empty($json)) return [];

        $registry = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($registry)) {
            $this->emError("Invalid agent_tool_registry JSON");
            return [];
        }

        $tools = $registry[(string)$pid] ?? $registry[$pid] ?? [];
        return is_array($tools) ? $tools : [];
    }

    private function getAgentRouterPrompt(): string
    {
        $prompt = (string) ($this->getSystemSetting('agent_router_system_prompt') ?? '');
        if (!empty(trim($prompt))) return $prompt;

        // Safe default if not configured yet
        return implode("\n", [
            "You are the REDCap Agent Router.",
            "Your job:",
            "- Interpret user intent.",
            "- Decide if a tool is required.",
            "- Choose the correct tool(s) and provide a tool_call in strict JSON.",
            "- Ask for missing required parameters instead of guessing.",
            "- Never invent tool names or arguments.",
            "",
            "Output one of:",
            "1) A tool_call JSON object",
            "2) A clarification question",
            "3) A final natural-language answer"
        ]);
    }

    private function buildToolCatalogText(array $tools): string
    {
        if (empty($tools)) {
            return "TOOLS AVAILABLE: (none)";
        }

        $lines = ["TOOLS AVAILABLE:"];
        foreach ($tools as $t) {
            $name = $t['name'] ?? '(missing name)';
            $desc = $t['description'] ?? '';
            $required = $t['parameters']['required'] ?? [];
            $reqStr = !empty($required) ? implode(", ", $required) : "(none)";
            $lines[] = "- {$name}: {$desc} | required: {$reqStr}";
        }
        return implode("\n", $lines);
    }

    private function injectAgentSystemContext(array $messages, array $tools): array
    {
        $routerPrompt = $this->getAgentRouterPrompt();

        // ALWAYS include tool catalog in agent mode
        $routerPrompt .= "\n\n" . $this->buildToolCatalogText($tools);

        array_unshift($messages, [
            'role' => 'system',
            'content' => $routerPrompt
        ]);

        return $messages;
    }

    private function runAgentLoop(string $model, array $params, ?int $project_id): array
    {
        $messages = $params['messages'] ?? [];
        $tools = $this->loadToolsForProject($project_id);

        $this->emDebug("AGENT MODE ENABLED", [
            'pid' => $project_id,
            'tool_count' => count($tools),
            'tool_names' => array_column($tools, 'name')
        ]);

        $max_steps = (int) ($this->getSystemSetting('agent_max_steps') ?? 8);
        $step = 0;

        // Inject router system prompt + tool catalog
        $messages = $this->injectAgentSystemContext($messages, $tools);
        $params['messages'] = $messages;

        // ⚠️ IMPORTANT: disable native OpenAI tools for agent mode
        unset($params['tools'], $params['tool_choice']);

        // Prevent recursion
        unset($params['agent_mode']);

        while ($step < $max_steps) {
            $step++;

            $this->emDebug("AGENT PROMPT SNAPSHOT", [
                'system_prompt' => $params['messages'][0]['content'],
                'user_message' => end($params['messages'])['content'] ?? null
            ]);

            $response = $this->callLLMOnce($model, $params, $project_id);
            $this->emDebug("AGENT RAW RESPONSE", $response);

            $content = trim($response['content'] ?? '');
            $decoded = null;

            // Attempt to extract JSON tool_call even if surrounded by text
            if (preg_match('/\{[\s\S]*"tool_call"[\s\S]*\}/', $content, $matches)) {
                $decoded = json_decode($matches[0], true);
            }

            if (is_array($decoded) && isset($decoded['tool_call'])) {
                $tool_call = $decoded['tool_call'];
                $tool_name = $tool_call['name'] ?? null;
                $arguments = $tool_call['arguments'] ?? [];

                if (!$tool_name) {
                    return $this->agentError("INVALID_TOOL_CALL", "Tool name missing");
                }

                $execution = $this->executeToolCall(
                    tool_name: $tool_name,
                    arguments: $arguments,
                    tools: $tools,
                    project_id: $project_id
                );

                // Ask user for missing params and STOP
                if (($execution['type'] ?? '') === 'MISSING_PARAMETERS') {
                    return [
                        'role' => 'assistant',
                        'content' => $this->askForMoreInfoText(
                            $tool_name,
                            $execution['missing'] ?? []
                        )
                    ];
                }

                if ($execution['error'] ?? false) {
                    return $execution;
                }

                // ✅ Inject tool result as SYSTEM context (NOT role=tool)
                $messages[] = [
                    'role' => 'system',
                    'content' =>
                        "TOOL RESULT [{$tool_name}]:\n" .
                        json_encode($execution['result'], JSON_PRETTY_PRINT)
                ];

                $params['messages'] = $messages;
                continue;
            }

            // No tool call → final answer
            return $response;
        }

        return $this->agentError(
            "AGENT_MAX_STEPS_EXCEEDED",
            "Agent exceeded maximum allowed steps"
        );
    }

    private function callLLMOnce(string $model, array $params, ?int $project_id): array
    {
        $this->initSecureChatAI();

        if (!isset($this->modelConfig[$model])) {
            throw new Exception('Unsupported model: ' . $model);
        }

        $modelConfig = $this->modelConfig[$model];
        $api_endpoint = $modelConfig['api_url'];

        foreach ($modelConfig['required'] as $param) {
            if (empty($params[$param])) {
                throw new Exception('Missing required parameter: ' . $param);
            }
        }

        $filteredParams = $this->filterDefaultParamsForModel($model, $params);

        switch ($model) {
            case 'gpt-4o':
            case 'ada-002':
                $gpt = new GPTModelRequest($this, $modelConfig, $this->defaultParams, $model);
                $responseData = $gpt->sendRequest($api_endpoint, $filteredParams);
                break;
            case 'deepseek':
                $generic = new GenericModelRequest($this, $modelConfig, $this->defaultParams, $model);
                $responseData = $generic->sendRequest($api_endpoint, $filteredParams);
                break;
            case 'whisper':
                $whisper = new WhisperModelRequest($this, $modelConfig, $this->defaultParams, $model);
                $whisper->setHeaders(['Content-Type: multipart/form-data','Accept: application/json']);
                $whisper->setAuthKeyName($modelConfig['whisper']['api_key_var'] ?? 'api-key');
                $responseData = $whisper->sendRequest($api_endpoint, $params);
                break;
            case 'gpt-4.1':
            case 'o1':
            case 'o3-mini':
            case 'llama3370b':
            case 'llama-Maverick':
                $filteredParams = $this->filterDefaultParamsForModel($model, $params);
                $generic = new GenericModelRequest($this, $modelConfig, [], $model);
                $responseData = $generic->sendRequest($api_endpoint, $filteredParams);
                break;
            case 'claude':
                $claude = new ClaudeModelRequest($this, $modelConfig, $this->defaultParams, $model);
                $responseData = $claude->sendRequest($api_endpoint, $filteredParams);
                break;
            case 'gemini20flash':
            case 'gemini25pro':
                $gemini = new GeminiModelRequest($this, $modelConfig, $this->defaultParams, $model);
                $responseData = $gemini->sendRequest($api_endpoint, $filteredParams);
                break;
            case 'gpt-4o-tts':
            case 'tts':
                $tts = new GPT4oMiniTTSModelRequest($this, $modelConfig, $this->defaultParams, $model);
                $responseData = $tts->sendRequest($api_endpoint, $params);
                break;
            default:
                throw new Exception("Unsupported model configuration for: $model");
        }

        $normalizedResponse = $this->normalizeResponse($responseData, $model);

        if ($project_id) {
            $this->logInteraction($project_id, $params, $responseData);
        }

        return $normalizedResponse;
    }

    private function executeToolCall(
        string $tool_name,
        array $arguments,
        array $tools,
        ?int $project_id
    ): array {
        $tool = null;

        foreach ($tools as $t) {
            if (($t['name'] ?? null) === $tool_name) {
                $tool = $t;
                break;
            }
        }

        if (!$tool) {
            return $this->agentError("UNKNOWN_TOOL", "Tool '{$tool_name}' not registered");
        }

        // ---- Required args check ----
        $required = $tool['parameters']['required'] ?? [];
        $missing  = array_diff($required, array_keys($arguments));

        if (!empty($missing)) {
            return [
                'error'   => true,
                'type'    => 'MISSING_PARAMETERS',
                'missing' => array_values($missing)
            ];
        }

        // ---- Same-project EM call ----
        if (($tool['endpoint'] ?? '') === 'module_api') {
            return [
                'error'  => false,
                'result' => $this->redcap_module_api(
                    $tool['module']['action'],
                    $arguments
                )
            ];
        }

        // ---- Cross-project REDCap API call ----
        if (($tool['endpoint'] ?? '') === 'redcap_api') {
            try {
                $apiUrl   = rtrim($this->getSystemSetting('agent_tools_redcap_api_url'), '/') . '/';
                $apiToken = $this->getSystemSetting('agent_tools_project_api_key');

                if (empty($apiUrl) || empty($apiToken)) {
                    return $this->agentError(
                        "MISCONFIGURED_AGENT_TOOLS",
                        "Missing agent_tools_redcap_api_url or agent_tools_project_api_key"
                    );
                }

                $payload = array_merge([
                    'token'        => $apiToken,
                    'content'      => 'externalModule',
                    'format'       => 'json',
                    'returnFormat' => 'json',
                    'prefix'       => $tool['redcap']['prefix'],
                    'action'       => $tool['redcap']['action'],
                ], $arguments);

                $client = new \GuzzleHttp\Client([
                    'timeout' => 10
                ]);

                $response = $client->post($apiUrl, [
                    'form_params' => $payload
                ]);

                $body = json_decode((string) $response->getBody(), true);

                return [
                    'error'  => false,
                    'result' => $body
                ];

            } catch (\Throwable $e) {
                return $this->agentError(
                    "REDCAP_API_ERROR",
                    $e->getMessage()
                );
            }
        }

        return $this->agentError(
            "UNSUPPORTED_TOOL_ENDPOINT",
            "Endpoint '{$tool['endpoint']}' not supported"
        );
    }

    private function askForMoreInfoText(string $tool_name, array $missing_fields): string
    {
        return "I need: " . implode(", ", $missing_fields) . " to use {$tool_name}.";
    }

    private function agentError(string $type, string $message): array
    {
        return [
            'error' => true,
            'type' => $type,
            'message' => $message
        ];
    }

// OPTIONAL FOR LATER , MODEL SPECIFIC STUFF
private function modelSupportsNativeTools(string $model): bool
{
    // Intentionally disabled for initial agent rollout
    return false;

    /*
    $raw = (string) ($this->getSystemSetting('agent_tools_native_models') ?? '');
    $allow = array_filter(array_map('trim', explode(',', $raw)));
    if (empty($allow)) return false;

    return in_array($model, $allow, true);
    */
}

private function toOpenAIToolsShape(array $tools): array
{
    // Intentionally disabled for initial agent rollout
    return [];

    /*
    $out = [];
    foreach ($tools as $t) {
        if (empty($t['name'])) continue;
        $out[] = [
            'type' => 'function',
            'function' => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'parameters' => $t['parameters'] ?? [
                    'type' => 'object',
                    'properties' => []
                ]
            ]
        ];
    }
    return $out;
    */
}
// OPTIONAL FOR LATER , MODEL SPECIFIC STUFF




    private function normalizeResponse($response, $model)
    {
        $normalized = [];

        if ($model === 'claude') {
            $normalized['content'] = $response['content'][0]['text'] ?? '';
            $normalized['role'] = $response['role'] ?? 'assistant';
            $normalized['model'] = $response['model'] ?? 'claude';
            $normalized['usage'] = [
                'prompt_tokens' => $response['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0)
            ];
        } elseif (in_array($model, [
            'o1', 'o3-mini', 'gpt-4o', 'llama3370b', 'gpt-4.1', 'llama-Maverick', 'deepseek'
        ])) {
            $normalized['content'] = $response['choices'][0]['message']['content'] ?? '';
            $normalized['role'] = $response['choices'][0]['message']['role'] ?? 'assistant';
            $normalized['model'] = $response['model'] ?? $model;
            $normalized['usage'] = [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0
            ];
        } elseif (in_array($model, [
             'gemini20flash',  'gemini25pro'
        ])) {
            $contentParts = [];
            foreach ($response as $chunk) {
                $parts = $chunk['candidates'][0]['content']['parts'] ?? [];
                foreach ($parts as $part) {
                    if (!empty($part['text'])) {
                        $contentParts[] = $part['text'];
                    }
                }
            }
            $normalized['content'] = implode(" ", $contentParts);
            $normalized['role'] = $response[0]['candidates'][0]['content']['role'] ?? "model";
            $normalized['model'] = $response[0]['modelVersion'] ?? $model;

            $usage = end($response)['usageMetadata'] ?? [];
            $normalized['usage'] = [
                'prompt_tokens' => $usage['promptTokenCount'] ?? 0,
                'completion_tokens' => $usage['candidatesTokenCount'] ?? 0,
                'total_tokens' => $usage['totalTokenCount'] ?? 0
            ];
        } elseif ($model === 'gpt-4o-tts' || $model === 'tts') {
            // Example: Your TTSModelRequest returns audio_base64 and content_type fields.
            $normalized['audio_base64'] = $response['audio_base64'] ?? '';
            $normalized['content_type'] = $response['content_type'] ?? 'audio/mpeg';
            $normalized['model'] = $model;
            // Add any other relevant info
        } else {
            $normalized = $response;
        }

        // $this->emDebug("normalized responseData", $normalized);
        return $normalized;
    }

    private function logInteraction($project_id, $requestData, $responseData)
    {
        $payload = array_merge($requestData, $responseData ?? []);
        $payload['project_id'] = $project_id;
        $action = new SecureChatLog($this);

        $action->setValue('message', json_encode($payload));
        $action->setValue('record', 'SecureChatLog');
        $action->save();
    }

    private function logErrorInteraction($project_id, $requestData, $error)
    {
        $payload = array_merge($requestData, $error);
        $payload['project_id'] = $project_id;
        $action = new SecureChatLog($this);

        $action->setValue('message', json_encode($error));
        $action->setValue('record', 'SecureChatLogError');
        $action->save();
    }

    public function extractResponseText($response)
    {
        return $response['content'] ?? json_encode($response);
    }

    public function extractUsageTokens($response)
    {
        return $response['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    }

    public function extractMetaData($response)
    {
        return [
            'id' => $response['id'] ?? 'N/A',
            'object' => $response['object'] ?? 'N/A',
            'created' => $response['created'] ?? 'N/A',
            'model' => $response['model'] ?? 'N/A',
            'usage' => $response['usage'] ?? 'N/A'
        ];
    }

    public function getGuzzleClient()
    {
        if (!$this->guzzleClient) {
            $this->guzzleClient = new Client([
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => false
            ]);
        }
        return $this->guzzleClient;
    }

    public function setGuzzleTimeout(float $timeout)
    {
        $this->guzzleTimeout = $timeout;
    }

    public function getGuzzleTimeout(): float
    {
        return $this->guzzleTimeout;
    }

    /**
     * This is the primary ajax handler for JSMO calls
     * @param $action
     * @param $payload
     * @param $project_id
     * @return array|array[]|bool
     * @throws Exception
     */

    public function redcap_module_api($action = null, $payload = [])
    {
        if (empty($action) && isset($_POST['action'])) {
            $action = $_POST['action'];
        }

        // Normalize payload from JSON or POST
        if (empty($payload)) {
            $raw = file_get_contents("php://input");
            $decoded = $raw ? json_decode($raw, true) : [];
            $payload = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $_POST;
        }

        switch ($action) {
            case "callAI":
                $prompt = $payload['prompt'] ?? null;
                $model = $payload['model'] ?? 'deepseek';
                $temperature = isset($payload['temperature']) ? (float)$payload['temperature'] : 0.2;
                $max_tokens = isset($payload['max_tokens']) ? (int)$payload['max_tokens'] : 800;

                if (!$prompt) {
                    return [
                        "status"  => 400,
                        "body"    => json_encode(["error" => "Missing prompt"]),
                        "headers" => ["Content-Type" => "application/json"]
                    ];
                }

                $params = [
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => $temperature,
                    'max_tokens' => $max_tokens
                ];

                $result = $this->callAI($model, $params);

                $response = [
                    'status' => 'success',
                    'model' => $model,
                    'content' => $this->extractResponseText($result),
                    'usage' => $this->extractUsageTokens($result)
                ];

                return [
                    "status"  => 200,
                    "body"    => json_encode($response),
                    "headers" => ["Content-Type" => "application/json"]
                ];

            default:
                return [
                    "status"  => 400,
                    "body"    => json_encode(["error" => "Action $action not defined"]),
                    "headers" => ["Content-Type" => "application/json"]
                ];
        }
    }
}
?>
