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

require_once __DIR__ . '/vendor/autoload.php';

use Google\Exception;
use GuzzleHttp\Client;
use Yethee\Tiktoken\EncoderProvider;

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
            'max_tokens' => (int)$this->getSystemSetting('gpt-max-tokens') ?: 16384,
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
        // Embedding models don't use chat defaultParams
        if ($model === 'ada-002') {
            return array_merge([
                'model' => $this->modelConfig[$model]['model_id'] ?? 'text-embedding-ada-002'
            ], $params);
        }

        $merged = array_merge($this->defaultParams, $params);

        // Only o1/o3-mini/gpt-5 get reasoning params
        if (!in_array($model, ['o1', 'o3-mini', 'gpt-5'])) {
            unset($merged['reasoning']);
            unset($merged['reasoning_effort']);
        }

        // Only models supporting json_schema
        $schemaModels = ['gpt-4.1', 'o1', 'o3-mini', 'gpt-5', 'llama3370b'];
        if (!in_array($model, $schemaModels)) {
            unset($merged['json_schema']);
        }

        // Only o1/o3-mini/gpt-5 have strict param set (use max_completion_tokens)
        if (in_array($model, ['o1', 'o3-mini', 'gpt-5'])) {
            $strict = [
                'model' => $model,
                'messages' => $merged['messages'] ?? [],
                'max_completion_tokens' => $merged['max_completion_tokens'] ?? ($merged['max_tokens'] ?? 32000),
            ];
            if (isset($merged['reasoning_effort'])) {
                $strict['reasoning_effort'] = $merged['reasoning_effort'];
            }

            // Preserve json_schema for o1/o3-mini/gpt-5
            if (isset($merged['json_schema'])) {
                $strict['json_schema'] = $merged['json_schema'];
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

                $response = null;

                if ($agent_mode_requested && $agent_mode_enabled) {
                    $response = $this->runAgentLoop(
                        model: $model,
                        params: $params,
                        project_id: $project_id
                    );
                } else {
                    if($agent_mode_requested && !$agent_mode_enabled){
                        $this->emDebug("Agent mode requested but not enabled in system settings. Proceeding with normal LLM call.");
                        unset($params['agent_mode']);
                    }

                    // Normal single-call path
                    $response = $this->callLLMOnce($model, $params, $project_id);
                }

                // ✅ ALWAYS sanitize output before returning to UI
                return $this->sanitizeOutputForUI($response);

            } catch (\Exception $e) {
                $attempt++;
                $this->emDebug("Attempt $attempt: Error", $e->getMessage());

                if ($attempt > $retries) {
                    $error = [
                        'error' => true,
                        'type' => 'NETWORK_ERROR',
                        'message' => "Error after $retries retries: " . $e->getMessage()
                    ];
                    if ($project_id) {
                        $this->logErrorInteraction($project_id, $params, $error);
                    } else {
                        $this->emDebug("Skipping error logging due to missing project ID (pid).");
                    }
                    // ✅ Sanitize errors too
                    return $this->sanitizeOutputForUI($error);
                }
            }
        }

        // ✅ Sanitize fallback error
        return $this->sanitizeOutputForUI([
            'error' => true,
            'type' => 'UNKNOWN_ERROR',
            'message' => 'Unknown error'
        ]);
    }

    private function loadToolsForProject(?int $pid): array
    {
        if (empty($pid)) return [];

        $json = $this->getSystemSetting('agent_tool_registry');
        if (empty($json)) return [];

        $registry = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($registry)) {
            $this->emError("Invalid agent_tool_registry JSON: " . json_last_error_msg());
            return [];
        }

        $tools = $registry[(string)$pid] ?? $registry[$pid] ?? [];
        if (!is_array($tools)) return [];

        // Validate each tool definition
        $validatedTools = [];
        foreach ($tools as $tool) {
            $validation = $this->validateToolDefinition($tool);
            if ($validation['valid']) {
                $validatedTools[] = $tool;
            } else {
                $this->emError("Invalid tool definition skipped", [
                    'tool_name' => $tool['name'] ?? 'unnamed',
                    'errors' => $validation['errors']
                ]);
            }
        }

        return $validatedTools;
    }

    /**
     * Validate tool definition structure
     * Returns ['valid' => bool, 'errors' => array]
     */
    private function validateToolDefinition(array $tool): array
    {
        $errors = [];

        // Required fields
        if (empty($tool['name'])) {
            $errors[] = "Missing 'name' field";
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.]*$/', $tool['name'])) {
            $errors[] = "Invalid 'name' format (must start with letter, alphanumeric + _ . only)";
        }

        if (empty($tool['description'])) {
            $errors[] = "Missing 'description' field";
        }

        if (empty($tool['endpoint'])) {
            $errors[] = "Missing 'endpoint' field";
        } elseif (!in_array($tool['endpoint'], ['module_api', 'redcap_api', 'http'])) {
            $errors[] = "Invalid 'endpoint' value (must be: module_api, redcap_api, or http)";
        }

        // Validate parameters structure
        if (isset($tool['parameters'])) {
            if (!is_array($tool['parameters'])) {
                $errors[] = "'parameters' must be an object";
            } elseif (!isset($tool['parameters']['type']) || $tool['parameters']['type'] !== 'object') {
                $errors[] = "'parameters.type' must be 'object'";
            }
        }

        // Validate endpoint-specific requirements
        if ($tool['endpoint'] === 'redcap_api') {
            if (empty($tool['redcap']['prefix'])) {
                $errors[] = "Missing 'redcap.prefix' for redcap_api endpoint";
            }
            if (empty($tool['redcap']['action'])) {
                $errors[] = "Missing 'redcap.action' for redcap_api endpoint";
            }
        } elseif ($tool['endpoint'] === 'module_api') {
            if (empty($tool['module']['action'])) {
                $errors[] = "Missing 'module.action' for module_api endpoint";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
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

        // Force schema-capable model for agent mode
        $schemaModels = ['gpt-4.1', 'o1', 'o3-mini', 'gpt-5', 'llama3370b'];
        if (!in_array($model, $schemaModels)) {
            $this->emDebug("Agent mode requires schema-capable model, switching from {$model} to o1");
            $model = 'o3-mini'; // Default fallback for agent mode
        }

        $this->emDebug("AGENT MODE ENABLED", [
            'pid' => $project_id,
            'model' => $model,
            'tool_count' => count($tools),
            'tool_names' => array_column($tools, 'name')
        ]);

        // Initialize safety limits
        $max_steps = (int) ($this->getSystemSetting('agent_max_steps') ?? 8);
        $max_tools = (int) ($this->getSystemSetting('agent_max_tools_per_run') ?? 15);
        $timeout = (int) ($this->getSystemSetting('agent_timeout_seconds') ?? 120);
        $max_tool_result_chars = (int) ($this->getSystemSetting('agent_max_tool_result_chars') ?? 8000);
        $start_time = time();

        // CRITICAL: Agent mode needs higher token limit to avoid truncation mid-JSON
        // o1 models use max_completion_tokens, others use max_tokens
        if (in_array($model, ['o1', 'o3-mini'])) {
            $params['max_completion_tokens'] = 32000; // Enough for full responses
        } else {
            $params['max_tokens'] = 4000;
        }

        $step = 0;
        $tools_called = 0;
        $tool_call_history = []; // Track tool calls to detect loops
        $tools_used = []; // Track tools used for UI display

        // Inject router system prompt + tool catalog
        $messages = $this->injectAgentSystemContext($messages, $tools);
        $params['messages'] = $messages;

        // ⚠️ IMPORTANT: disable native OpenAI tools for agent mode
        unset($params['tools'], $params['tool_choice']);

        // Prevent recursion
        unset($params['agent_mode']);

        // Add JSON schema for agent responses
        // Note: strict=false because tool arguments are dynamic (incompatible with strict mode)
        $params['json_schema'] = [
            'name' => 'agent_response',
            'strict' => false,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'tool_call' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'arguments' => ['type' => 'object']
                        ],
                        'required' => ['name', 'arguments'],
                        'additionalProperties' => false
                    ],
                    'final_answer' => ['type' => 'string'],
                    'thinking' => ['type' => 'string']
                ],
                'additionalProperties' => false
            ]
        ];

        while ($step < $max_steps) {
            $step++;

            // Step-by-step logging
            $this->emDebug("AGENT STEP {$step}/{$max_steps}", [
                'messages_count' => count($messages),
                'last_message_preview' => substr(end($messages)['content'] ?? '', 0, 100)
            ]);

            $response = $this->callLLMOnce($model, $params, $project_id);
            $this->emDebug("AGENT RAW RESPONSE", $response);

            $content = trim($response['content'] ?? '');

            // Decode HTML entities (REDCap may encode response)
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');

            // Clean ALL control characters (including literal newlines/tabs) that break JSON parsing
            // This collapses pretty-printed JSON into single line
            // Escaped sequences like \n are preserved since they're not actual control chars
            $cleanContent = preg_replace('/[\x00-\x1F]/', '', $content);

            $decoded = json_decode($cleanContent, true);

            // Fallback strategy for non-JSON responses
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->emDebug("JSON parse failed, trying regex extraction", [
                    'error' => json_last_error_msg(),
                    'content_preview' => substr($content, 0, 200)
                ]);

                // Try to extract tool_call JSON pattern from text
                if (preg_match('/\{[\s\S]*"tool_call"[\s\S]*\}/', $content, $matches)) {
                    $decoded = json_decode($matches[0], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $this->emDebug("Regex extraction succeeded");
                    }
                }

                // If still no valid JSON, treat as plain text final answer
                if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                    $this->emDebug("Treating plain text response as final answer");
                    return [
                        'role' => 'assistant',
                        'content' => $content
                    ];
                }
            }

            if (isset($decoded['tool_call'])) {
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

                // Return errors immediately (but not MISSING_PARAMETERS - let agent handle that)
                if ($execution['error'] ?? false) {
                    return $execution;
                }

                // Cap tool result size to prevent token overflow
                $execution['result'] = $this->capToolResultSize(
                    $execution['result'],
                    $max_tool_result_chars
                );

                // Track tool usage for UI display
                $tools_used[] = [
                    'name' => $tool_name,
                    'arguments' => $arguments,
                    'step' => $step
                ];

                // Detect tool ping-pong loops (same tool+args called repeatedly)
                $callSignature = $tool_name . ':' . json_encode($arguments);
                $tool_call_history[] = $callSignature;

                // Check for loops: same signature appearing 3+ times in last 5 calls
                if (count($tool_call_history) >= 5) {
                    $recentCalls = array_slice($tool_call_history, -5);
                    $signatureCounts = array_count_values($recentCalls);
                    if (max($signatureCounts) >= 3) {
                        return $this->agentError(
                            "TOOL_LOOP_DETECTED",
                            "Agent is repeatedly calling the same tool. Breaking loop to prevent infinite execution."
                        );
                    }
                }

                // Increment tool counter and check limit
                $tools_called++;
                if ($tools_called >= $max_tools) {
                    return $this->agentError(
                        "MAX_TOOLS_EXCEEDED",
                        "Agent called {$tools_called} tools (limit: {$max_tools})"
                    );
                }

                // Check timeout
                if (time() - $start_time > $timeout) {
                    return $this->agentError(
                        "TIMEOUT",
                        "Agent exceeded {$timeout} second time limit"
                    );
                }

                // ✅ Inject tool result as USER context (standard practice)
                $messages[] = [
                    'role' => 'user',
                    'content' =>
                        "TOOL RESULT [{$tool_name}]:\n" .
                        json_encode($execution['result'], JSON_PRETTY_PRINT)
                ];

                $params['messages'] = $messages;
                continue;
            } elseif (isset($decoded['final_answer'])) {
                return [
                    'role' => 'assistant',
                    'content' => $decoded['final_answer'],
                    'tools_used' => $tools_used // Include tool metadata for UI
                ];
            } else {
                // No tool_call or final_answer - treat entire response as final answer
                $this->emDebug("No tool_call or final_answer field, using raw content");
                return [
                    'role' => 'assistant',
                    'content' => $content,
                    'tools_used' => $tools_used
                ];
            }
        }

        return $this->agentError(
            "AGENT_MAX_STEPS_EXCEEDED",
            "Agent exceeded maximum allowed steps ({$max_steps})"
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

        [$paramName, $dynamicMax, $promptTokens] = $this->computeDynamicMaxTokens($model, $fullPrompt ?? $filteredParams['messages'][0]['content'] ?? '');
        $filteredParams[$paramName] = (int)$dynamicMax;
        $this->emDebug("Dynamic tokens: prompt={$promptTokens}, max={$dynamicMax} for {$model}");

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
            case 'gpt-5':
            case 'llama3370b':
            case 'llama-Maverick':
                $filteredParams = $this->filterDefaultParamsForModel($model, $params);
                $generic = new GenericModelRequest($this, $modelConfig, [], $model);
                $responseData = $generic->sendRequest($api_endpoint, $filteredParams);
                $this->emDebug("RAW GenericModelRequest API RESPONSE", $responseData);
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
            // Don't return error - let the agent ask the user naturally
            return [
                'error'   => false,
                'type'    => 'MISSING_PARAMETERS',
                'missing' => array_values($missing),
                'result' => [
                    'status' => 'incomplete',
                    'message' => "Missing required parameters: " . implode(", ", $missing),
                    'missing_fields' => array_values($missing)
                ]
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

    /**
     * Cap tool result size to prevent token overflow
     * Intelligently truncates while preserving structure and adding metadata
     */
    private function capToolResultSize($result, int $maxChars)
    {
        $jsonResult = json_encode($result, JSON_UNESCAPED_UNICODE);
        $originalSize = strlen($jsonResult);

        // If under limit, return as-is
        if ($originalSize <= $maxChars) {
            return $result;
        }

        $truncated = $result;
        $wasTruncated = false;

        // Handle arrays - truncate items
        if (is_array($result) && array_is_list($result)) {
            $itemCount = count($result);
            $kept = [];
            $currentSize = 2; // [] brackets

            foreach ($result as $item) {
                $itemJson = json_encode($item, JSON_UNESCAPED_UNICODE);
                $itemSize = strlen($itemJson) + 1; // +1 for comma

                if ($currentSize + $itemSize > $maxChars - 200) { // Reserve 200 chars for metadata
                    $wasTruncated = true;
                    break;
                }

                $kept[] = $item;
                $currentSize += $itemSize;
            }

            $truncated = $kept;
            if ($wasTruncated) {
                $truncated[] = [
                    '_truncated' => true,
                    '_original_count' => $itemCount,
                    '_returned_count' => count($kept),
                    '_message' => "Result truncated to fit token budget. Showing " . count($kept) . " of $itemCount items."
                ];
            }
        }
        // Handle objects - recursively cap nested values
        elseif (is_array($result)) {
            $currentSize = 2; // {} brackets
            $truncated = [];

            foreach ($result as $key => $value) {
                $valueJson = json_encode($value, JSON_UNESCAPED_UNICODE);
                $itemSize = strlen($key) + strlen($valueJson) + 4; // key + value + quotes/colon

                if ($currentSize + $itemSize > $maxChars - 200) {
                    $wasTruncated = true;
                    break;
                }

                $truncated[$key] = $value;
                $currentSize += $itemSize;
            }

            if ($wasTruncated) {
                $truncated['_truncated'] = true;
                $truncated['_message'] = "Result truncated to fit token budget.";
            }
        }
        // Handle strings - simple truncation
        elseif (is_string($result)) {
            $truncated = substr($result, 0, $maxChars - 100);
            $truncated .= "\n\n[... truncated " . ($originalSize - strlen($truncated)) . " characters to fit token budget]";
        }

        return $truncated;
    }

    /**
     * Final cleanup: ensure response is always clean, user-ready text
     * - Extract text from stringified JSON (agent schema or accidental)
     * - Convert error objects to polite messages
     * - Never return raw JSON strings or error arrays to the UI
     */
    private function sanitizeOutputForUI(array $response): array
    {
        // Embedding responses (ada-002) should pass through unchanged
        // They have 'data' field with embedding vectors, not 'content'
        if (isset($response['data']) && !isset($response['content'])) {
            $this->emDebug("Passing through embedding response in sanitizeOutputForUI");
            return $response;
        }

        // TTS responses should pass through unchanged (have audio_base64)
        if (isset($response['audio_base64'])) {
            $this->emDebug("Passing through TTS response in sanitizeOutputForUI");
            return $response;
        }

        // STT/Whisper responses should pass through unchanged (have 'text' from transcription)
        // Raw whisper responses don't have 'role' field
        if (isset($response['text']) && !isset($response['role'])) {
            $this->emDebug("Passing through STT response in sanitizeOutputForUI");
            return $response;
        }

        // Handle error responses with friendly messages
        if (!empty($response['error'])) {
            $type = $response['type'] ?? 'UNKNOWN_ERROR';
            $msg = $response['message'] ?? 'An error occurred';

            $friendlyMessages = [
                'TIMEOUT' => "I apologize, but that request took longer than expected. Please try again in a moment.",
                'MAX_TOOLS_EXCEEDED' => "I apologize, but I needed to use too many tools for that request. Could you try simplifying your question?",
                'AGENT_MAX_STEPS_EXCEEDED' => "I apologize, but I couldn't complete that task efficiently. Could you try breaking it into smaller requests?",
                'TOOL_LOOP_DETECTED' => "I apologize, but I got stuck in a loop trying to complete that request. Could you try rephrasing your question?",
                'UNKNOWN_TOOL' => "I apologize, but I don't have access to that capability right now.",
                'MISCONFIGURED_AGENT_TOOLS' => "I apologize, but I'm experiencing technical difficulties. Please contact your administrator.",
                'REDCAP_API_ERROR' => "I apologize, but I'm having trouble accessing that data right now. Please try again in a moment.",
                'NETWORK_ERROR' => "I apologize, but I'm experiencing network difficulties. Please wait a moment and try again.",
            ];

            $politeMessage = $friendlyMessages[$type] ??
                "I apologize, but I'm experiencing technical difficulties. Please wait a moment and try again.";

            return [
                'role' => 'assistant',
                'content' => $politeMessage
            ];
        }

        $content = trim($response['content'] ?? '');

        // Decode HTML entities (in case of double-encoding)
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');

        // Try to detect and parse JSON responses
        if (!empty($content) && ($content[0] === '{' || str_starts_with($content, '```json'))) {
            // Strip markdown code fences if present
            $content = preg_replace('/^```json\s*|\s*```$/s', '', $content);
            $content = trim($content);

            // Clean control characters that break JSON parsing (same as agent loop)
            $cleanContent = preg_replace('/[\x00-\x1F]/', '', $content);

            $decoded = json_decode($cleanContent, true);
            $parseError = json_last_error();

            if ($parseError === JSON_ERROR_NONE && is_array($decoded)) {
                if (!empty($response['preserve_structure'])) {
                    // Keep the JSON string as-is for structured output
                    $response['content'] = $content;
                    return $response;
                }

                // Extract from agent schema format
                if (isset($decoded['final_answer'])) {
                    $content = $decoded['final_answer'];
                } elseif (isset($decoded['tool_call'])) {
                    // If we're seeing a tool_call in final output, something went wrong
                    $content = "I apologize, but I wasn't able to complete that request properly. Could you try rephrasing?";
                } elseif (isset($decoded['content'])) {
                    // Some models nest content in JSON
                    $content = $decoded['content'];
                } elseif (isset($decoded['message'])) {
                    $content = $decoded['message'];
                }
                // Otherwise leave the JSON string as-is (might be intentional structured output)
            }
        }

        // EMERGENCY BACKSTOP: If content STILL looks like our JSON schema, strip it one more time
        // This handles edge cases where json_decode failed or we didn't extract properly
        // Works even with truncated JSON (missing closing brace/quote)
        if (!empty($content) && preg_match('/\{\s*"final_answer"\s*:\s*"(.*)$/s', $content, $match)) {
            $extracted = $match[1];
            // Remove trailing garbage (incomplete JSON structure)
            $extracted = preg_replace('/["}\s]*$/', '', $extracted);
            // Unescape JSON string escapes
            $content = str_replace(['\n', '\r', '\t', '\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $extracted);
        }

        $sanitized = [
            'role' => $response['role'] ?? 'assistant',
            'content' => $content,
            'model' => $response['model'] ?? null,
            'usage' => $response['usage'] ?? null
        ];

        // Preserve tool metadata if present (for UI display)
        if (!empty($response['tools_used'])) {
            $sanitized['tools_used'] = $response['tools_used'];
        }

        return $sanitized;
    }

    private function estimateTokens(string $text, string $model): int {
        $provider = new EncoderProvider();  // Caches encoders automatically
        $encoder = $provider->getForModel($model);  // Maps 'o1', 'gpt-4.1' → cl100k_base
        return count($encoder->encode($text));  // Returns token count
    }

    private function computeDynamicMaxTokens(string $model, string $prompt): array {
        $modelSpecs = [
            'o1' => [
                'context' => 200000,
                'output_max' => 100000,
                'param' => 'max_completion_tokens',
                'buffer' => 25000
            ],
            'gpt-4.1' => [
                'context' => 1000000,
                'output_max' => 128000,
                'param' => 'max_tokens',
                'buffer' => 2000
            ],
            'o3-mini' => [
                'context' => 200000,
                'output_max' => 100000,
                'param' => 'max_completion_tokens',
                'buffer' => 25000
            ],
            'gpt-5' => [
                'context' => 400000,
                'output_max' => 128000,
                'param' => 'max_tokens',
                'buffer' => 2000
            ]
        ];
        $spec = $modelSpecs[$model] ?? null;
        if($model == "gemini20flash") return ['max_tokens', 8192, 0];
        if (!$spec) return ['max_tokens', 16384, 0];

        $promptTokens = $this->estimateTokens($prompt, $model);
        $available = $spec['context'] - $promptTokens - $spec['buffer'];
        $final = min($available, $spec['output_max']);
        $final = max(1024, $final);  // Min fallback

        // Log equivalent (adapt to your logger)
        error_log("\n\nfinal_max_tokens = $final\n\n");

        return [$spec['param'], $final, $promptTokens];
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

        $this->emDebug("normalizeResponse called", [
            'model' => $model,
            'has_data' => isset($response['data']),
            'has_choices' => isset($response['choices'])
        ]);

        // Embedding models return their own format - pass through unchanged
        if ($model === 'ada-002') {
            $this->emDebug("Passing through ada-002 response unchanged");
            return $response;
        }

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
            'o1', 'o3-mini', 'gpt-4o', 'gpt-5', 'llama3370b', 'gpt-4.1', 'llama-Maverick', 'deepseek'
        ])) {
            $normalized['content'] = $response['choices'][0]['message']['content'] ?? '';
            $decoded = json_decode($normalized['content'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $normalized['structured_output'] = $decoded;
                $normalized['preserve_structure'] = true;
            }
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
        // Return structured output as-is if available (already JSON)
        if (isset($response['structured_output'])) {
            return $response['structured_output'];
        }
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
                $max_tokens = isset($payload['max_tokens']) ? (int)$payload['max_tokens'] : 8000;
                $json_schema = $payload['json_schema'] ?? null;

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

                if ($json_schema !== null) {
                    $decoded = json_decode($json_schema, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $params['json_schema'] = $decoded;
                    }
                }

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

            case "messages":
                // Claude Messages API compatibility endpoint
                // Accepts: {model, messages, max_tokens, temperature, system, top_p, stop}
                // Returns: Claude Messages API format response

                $model = $payload['model'] ?? 'o3-mini';
                $messages = $payload['messages'] ?? null;

                // Handle messages as JSON string (from form-encoded POST)
                if (is_string($messages)) {
                    $decoded = json_decode($messages, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $messages = $decoded;
                    }
                }

                $max_tokens = isset($payload['max_tokens']) ? (int)$payload['max_tokens'] : 4096;
                $temperature = isset($payload['temperature']) ? (float)$payload['temperature'] : null;
                $top_p = isset($payload['top_p']) ? (float)$payload['top_p'] : null;
                $stop = $payload['stop'] ?? null;
                $system = $payload['system'] ?? null;

                if (!$messages || !is_array($messages)) {
                    return [
                        "status"  => 400,
                        "body"    => json_encode([
                            "type" => "error",
                            "error" => [
                                "type" => "invalid_request_error",
                                "message" => "Missing or invalid 'messages' field"
                            ]
                        ]),
                        "headers" => ["Content-Type" => "application/json"]
                    ];
                }

                // Map Claude model names to SecureChatAI aliases
                $modelMap = [
                    'claude-3-7-sonnet-20250219' => 'claude',
                    'claude-3-5-sonnet-20241022' => 'claude',
                    'claude-sonnet-4' => 'claude',
                    'gpt-4.1' => 'gpt-4.1',
                    'o1' => 'o1',
                    'o3-mini' => 'o3-mini',
                    'gpt-5' => 'gpt-5',
                    'llama3370b' => 'llama3370b',
                    'deepseek' => 'deepseek'
                ];
                $mappedModel = $modelMap[$model] ?? $model;

                // Prepend system message if provided
                if ($system) {
                    array_unshift($messages, [
                        'role' => 'system',
                        'content' => $system
                    ]);
                }

                // Build params for callAI()
                $params = ['messages' => $messages];

                if ($temperature !== null) $params['temperature'] = $temperature;
                if ($top_p !== null) $params['top_p'] = $top_p;
                if ($stop !== null) $params['stop'] = $stop;

                // Handle max_tokens vs max_completion_tokens
                if (in_array($mappedModel, ['o1', 'o3-mini', 'gpt-5'])) {
                    $params['max_completion_tokens'] = $max_tokens;
                } else {
                    $params['max_tokens'] = $max_tokens;
                }

                // Call the AI (no project_id for external API calls)
                $result = $this->callAI($mappedModel, $params, null);

                // Check for errors
                if (isset($result['error']) && $result['error']) {
                    return [
                        "status"  => 500,
                        "body"    => json_encode([
                            "type" => "error",
                            "error" => [
                                "type" => $result['type'] ?? "api_error",
                                "message" => $result['message'] ?? "Internal error"
                            ]
                        ]),
                        "headers" => ["Content-Type" => "application/json"]
                    ];
                }

                // Transform to Claude Messages API response format
                $content = $this->extractResponseText($result);
                $usage = $this->extractUsageTokens($result);

                // Determine stop_reason
                $stop_reason = "end_turn";
                if (isset($result['usage']['completion_tokens']) &&
                    isset($params['max_tokens']) &&
                    $result['usage']['completion_tokens'] >= $params['max_tokens']) {
                    $stop_reason = "max_tokens";
                }

                $claudeResponse = [
                    "id" => "msg_" . uniqid(),
                    "type" => "message",
                    "role" => "assistant",
                    "content" => [
                        [
                            "type" => "text",
                            "text" => is_string($content) ? $content : json_encode($content)
                        ]
                    ],
                    "model" => $model,  // Echo back the requested model name
                    "stop_reason" => $stop_reason,
                    "stop_sequence" => null,
                    "usage" => [
                        "input_tokens" => $usage['prompt_tokens'] ?? 0,
                        "output_tokens" => $usage['completion_tokens'] ?? 0
                    ]
                ];

                return [
                    "status"  => 200,
                    "body"    => json_encode($claudeResponse),
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
