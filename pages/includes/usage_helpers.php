<?php

namespace Stanford\SecureChatAI;

function createTable($action, $index) {
    $id = $action['id'] ?? 'N/A';
    $timestamp = $action['timestamp'] ?? 'N/A';
    $completionTokens = $action['usage']['completion_tokens'] ?? 'N/A';
    $promptTokens = $action['usage']['prompt_tokens'] ?? 'N/A';
    $totalTokens = $action['usage']['total_tokens'] ?? 'N/A';
    $model = $action['model'] ?? 'N/A';
    $project_id = $action['project_id'] ?? 'N/A';
    $record = $action['record'] ?? 'N/A';
    $session_id = $action['session_id'] ?? 'N/A';

    // Support both old format (choices/messages) and new atomic format (user_message/assistant_response)
    // Extract tools_used for agent mode display
    $toolsUsed = [];
    if (!empty($action['choices'][0]['message']['tools_used'])) {
        $toolsUsed = $action['choices'][0]['message']['tools_used'];
    } elseif (!empty($action['tools_used'])) {
        $toolsUsed = $action['tools_used'];
    }

    if($record === "SecureChatLogError"){
        $responseDump = "N/A - Error";
        $queryDump = htmlspecialchars(strip_tags($action['message'] ?? ''), ENT_QUOTES, 'UTF-8');
    } else {
        // Try new atomic format first, fall back to old format
        if (!empty($action['assistant_response'])) {
            // New atomic format
            $rawResponse = $action['assistant_response'];
            $queryDump = htmlspecialchars($action['user_message'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        } else {
            // Old format
            $rawResponse = $action['choices'][0]['message']['content'] ?? 'N/A';
            $queryDump = htmlspecialchars(print_r($action['messages'] ?? [], true), ENT_QUOTES, 'UTF-8');
        }
        $responseDump = htmlspecialchars($rawResponse, ENT_QUOTES, 'UTF-8');
    }


    // Unique IDs for each accordion based on row and column index
    $tokensId = "collapse-tokens-{$index}";
    $metaId = "collapse-meta-{$index}";
    $queryId = "collapse-query-{$index}";
    $responseId = "collapse-response-{$index}";
    $toolsId = "collapse-tools-{$index}";

    // Build tools display
    $toolsDisplay = '';
    if (!empty($toolsUsed)) {
        $toolsDisplay = "<div class='agent-steps'>";
        foreach ($toolsUsed as $idx => $tool) {
            $toolName = htmlspecialchars($tool['name'] ?? 'unknown', ENT_QUOTES, 'UTF-8');
            $toolArgs = htmlspecialchars(json_encode($tool['arguments'] ?? [], JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
            $stepNum = ($tool['step'] ?? $idx + 1);
            $toolsDisplay .= "<div class='tool-step'><strong>Step {$stepNum}:</strong> {$toolName}<br><small>{$toolArgs}</small></div>";
        }
        $toolsDisplay .= "</div>";
    }

    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    $safeProjectId = htmlspecialchars($project_id, ENT_QUOTES, 'UTF-8');
    $safeRecord = htmlspecialchars($record, ENT_QUOTES, 'UTF-8');
    $safeTimestamp = htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8');
    $safeModel = htmlspecialchars($model, ENT_QUOTES, 'UTF-8');
    $safeSessionId = htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8');
    $safeCompletionTokens = htmlspecialchars($completionTokens, ENT_QUOTES, 'UTF-8');
    $safePromptTokens = htmlspecialchars($promptTokens, ENT_QUOTES, 'UTF-8');
    $safeTotalTokens = htmlspecialchars($totalTokens, ENT_QUOTES, 'UTF-8');
    $safeTemperature = htmlspecialchars($action['temperature'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
    $safeTopP = htmlspecialchars($action['top_p'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
    $safeFreqPenalty = htmlspecialchars($action['frequency_penalty'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
    $safePresPenalty = htmlspecialchars($action['presence_penalty'] ?? 'N/A', ENT_QUOTES, 'UTF-8');

    return "<tr>
                <td class='id-column'>{$safeId}</td>
                <td class='project-id-column'>{$safeProjectId}</td>
                <td>{$safeRecord}</td>
                <td>{$safeTimestamp}</td>
                <td>{$safeModel}</td>
                <td class='session-id-column'>{$safeSessionId}</td>
                <td>
                    <div class='accordion' id='accordionTokens-{$index}'>
                        <div class='accordion-item'>
                            <h2 class='accordion-header' id='heading-tokens-{$index}'>
                                <button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#{$tokensId}' aria-expanded='false' aria-controls='{$tokensId}'>
                                    Total: {$safeTotalTokens}
                                </button>
                            </h2>
                            <div id='{$tokensId}' class='accordion-collapse collapse' aria-labelledby='heading-tokens-{$index}' data-bs-parent='#accordionTokens-{$index}'>
                                <div class='accordion-body'>
                                    <div>Prompt Tokens: {$safePromptTokens}</div>
                                    <div>Completion Tokens: {$safeCompletionTokens}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class='accordion' id='accordionMeta-{$index}'>
                        <div class='accordion-item'>
                            <h2 class='accordion-header' id='heading-meta-{$index}'>
                                <div class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#{$metaId}' aria-expanded='false' aria-controls='{$metaId}'>
                                    Temp: {$safeTemperature}
                                </div>
                            </h2>
                            <div id='{$metaId}' class='accordion-collapse collapse' aria-labelledby='heading-meta-{$index}' data-bs-parent='#accordionMeta-{$index}'>
                                <div class='accordion-body'>
                                    <div><strong>Top P:</strong> {$safeTopP}</div>
                                    <div><strong>Frequency Penalty:</strong> {$safeFreqPenalty}</div>
                                    <div><strong>Presence Penalty:</strong> {$safePresPenalty}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>
                <td class='query-column'>
                    <div class='accordion' id='accordionQuery-{$index}'>
                        <div class='accordion-item'>
                            <h2 class='accordion-header' id='heading-query-{$index}'>
                                <button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#{$queryId}' aria-expanded='false' aria-controls='{$queryId}'>
                                    Expand ...
                                </button>
                            </h2>
                            <div id='{$queryId}' class='accordion-collapse collapse' aria-labelledby='heading-query-{$index}' data-bs-parent='#accordionQuery-{$index}'>
                                <div class='accordion-body'>
                                    <pre class='scrollable-content'>$queryDump</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>
                <td class='response-column'>
                    <div class='accordion' id='accordionResponse-{$index}'>
                        <div class='accordion-item'>
                            <h2 class='accordion-header' id='heading-response-{$index}'>
                                <button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#{$responseId}' aria-expanded='false' aria-controls='{$responseId}'>
                                    Expand ...
                                </button>
                            </h2>
                            <div id='{$responseId}' class='accordion-collapse collapse' aria-labelledby='heading-response-{$index}' data-bs-parent='#accordionResponse-{$index}'>
                                <div class='accordion-body'>
                                    <pre class='scrollable-content'>{$responseDump}</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>
                <td class='tools-column'>
                    " . (!empty($toolsUsed) ? "
                    <div class='accordion' id='accordionTools-{$index}'>
                        <div class='accordion-item'>
                            <h2 class='accordion-header' id='heading-tools-{$index}'>
                                <button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#{$toolsId}' aria-expanded='false' aria-controls='{$toolsId}'>
                                    " . count($toolsUsed) . " tool(s)
                                </button>
                            </h2>
                            <div id='{$toolsId}' class='accordion-collapse collapse' aria-labelledby='heading-tools-{$index}' data-bs-parent='#accordionTools-{$index}'>
                                <div class='accordion-body'>
                                    {$toolsDisplay}
                                </div>
                            </div>
                        </div>
                    </div>
                    " : "<span class='text-muted'>—</span>") . "
                </td>
            </tr>";
}
