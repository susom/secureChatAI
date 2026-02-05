<?php

namespace Stanford\SecureChatAI;
/** @var \Stanford\SecureChatAI\SecureChatAI $module */

function createTable($action, $index) {
    $id = $action['id'] ?? 'N/A';
    $timestamp = $action['timestamp'] ?? 'N/A';
    $completionTokens = $action['usage']['completion_tokens'] ?? 'N/A';
    $promptTokens = $action['usage']['prompt_tokens'] ?? 'N/A';
    $totalTokens = $action['usage']['total_tokens'] ?? 'N/A';
    $model = $action['model'] ?? 'N/A';
    $project_id = $action['project_id'] ?? 'N/A';
    $record = $action['record'] ?? 'N/A';

    // Extract tools_used for agent mode display
    $toolsUsed = [];
    if (!empty($action['choices'][0]['message']['tools_used'])) {
        $toolsUsed = $action['choices'][0]['message']['tools_used'];
    }

    if($record === "SecureChatLogError"){
        $responseDump = "N/A - Error";
        $queryDump = htmlspecialchars(strip_tags($action['message'] ?? ''), ENT_QUOTES, 'UTF-8');
    } else {
        $rawResponse = $action['choices'][0]['message']['content'] ?? 'N/A';
        $responseDump = htmlspecialchars($rawResponse, ENT_QUOTES, 'UTF-8');
        $queryDump = htmlspecialchars(print_r($action['messages'] ?? [], true), ENT_QUOTES, 'UTF-8');
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

// Check if demo mode (fake production data)
$isDemoMode = isset($_GET['demo']) && $_GET['demo'] === 'true';

// Check if JSON format requested (for AJAX updates)
$isJsonRequest = isset($_GET['format']) && $_GET['format'] === 'json';

// Get limit from query param or default to 500
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
$limit = min(max($limit, 10), 1000); // Clamp between 10 and 1000

// Get date range filters
$dateStart = isset($_GET['dateStart']) ? $_GET['dateStart'] : null;
$dateEnd = isset($_GET['dateEnd']) ? $_GET['dateEnd'] : null;

$offset = 0;
$a = $module->getSecureChatLogs($offset);

// Collect unique values for filters
$uniqueModels = [];
$uniqueProjects = [];
$uniqueTypes = [];

$allLogs = []; // Store all logs for analytics

// First pass: collect all logs (don't create rows yet)
foreach ($a as $index => $v) {
    $action = $v->getLog();

    // Apply date filtering if specified
    if ($dateStart && $dateEnd && !empty($action['timestamp'])) {
        $logDate = date('Y-m-d', strtotime($action['timestamp']));
        if ($logDate < $dateStart || $logDate > $dateEnd) {
            continue; // Skip this log
        }
    }

    // Collect all filtered logs
    $allLogs[] = $action;

    // Collect unique values
    if (!empty($action['model'])) $uniqueModels[$action['model']] = true;
    if (!empty($action['project_id'])) $uniqueProjects[$action['project_id']] = true;
    if (!empty($action['record'])) $uniqueTypes[$action['record']] = true;
}

// Logs are already sorted by database (order by log_id desc)
// Just create table rows from first 500
$rows = '';
$tableIndex = 0;
foreach ($allLogs as $index => $action) {
    if ($tableIndex < $limit) {
        $rows .= createTable($action, $tableIndex);
        $tableIndex++;
    } else {
        break; // Stop after limit reached
    }
}

// Convert to sorted arrays for dropdowns
$uniqueModels = array_keys($uniqueModels);
$uniqueProjects = array_keys($uniqueProjects);
$uniqueTypes = array_keys($uniqueTypes);
sort($uniqueModels);
sort($uniqueProjects);
sort($uniqueTypes);

// DEMO MODE: Generate fake production data
if ($isDemoMode) {
    $allLogs = [];
    $fakeModels = ['gpt-4o', 'gpt-4o-mini', 'claude-3.5-sonnet', 'claude-3-haiku', 'gemini-1.5-pro', 'gemini-1.5-flash', 'o1', 'o3-mini', 'gpt-4.1', 'llama-3.3-70b'];
    $fakeProjects = [123, 456, 789, 234, 567];
    $fakeTypes = ['chat_completion', 'agent_call', 'embedding', 'chat_completion'];
    $fakeTools = [
        ['name' => 'searchRAG', 'arguments' => ['query' => 'patient data'], 'step' => 1],
        ['name' => 'getUserData', 'arguments' => ['record_id' => '12345'], 'step' => 2],
        ['name' => 'formatResponse', 'arguments' => ['format' => 'json'], 'step' => 3]
    ];

    // Generate 5000 fake logs over last 30 days with proper distribution
    $baseTime = time();
    for ($i = 0; $i < 5000; $i++) {
        // Distribute evenly over 30 days, then add randomness
        $secondsAgo = ($i / 5000) * (30 * 24 * 60 * 60); // Spread evenly
        $randomOffset = rand(-3600, 3600); // Add up to 1 hour randomness
        $timestamp = date('Y-m-d H:i:s', $baseTime - $secondsAgo + $randomOffset);

        $model = $fakeModels[array_rand($fakeModels)];
        $isAgent = rand(0, 100) < 25; // 25% agent mode
        $promptTokens = rand(100, 3000);
        $completionTokens = rand(50, 2000);

        $log = [
            'id' => 1000000 + $i,
            'timestamp' => $timestamp,
            'model' => $model,
            'project_id' => $fakeProjects[array_rand($fakeProjects)],
            'record' => $fakeTypes[array_rand($fakeTypes)],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens
            ],
            'temperature' => 0.7,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'messages' => [
                ['role' => 'user', 'content' => 'Sample query about patient data'],
                ['role' => 'assistant', 'content' => 'Sample response with clinical information']
            ],
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Sample AI response with detailed analysis and recommendations.',
                        'tools_used' => $isAgent ? $fakeTools : []
                    ]
                ]
            ]
        ];

        $allLogs[] = $log;
    }

    // Recollect unique values
    $uniqueModels = array_unique($fakeModels);
    $uniqueProjects = $fakeProjects;
    $uniqueTypes = array_unique($fakeTypes);

    // Sort demo logs by ID descending (needed for fake data since it's not from DB)
    usort($allLogs, function($a, $b) {
        return intval($b['id']) - intval($a['id']); // DESC order
    });

    // Create table rows from sorted demo data
    $rows = '';
    $tableIndex = 0;
    foreach ($allLogs as $index => $action) {
        if ($tableIndex < $limit) {
            $rows .= createTable($action, $tableIndex);
            $tableIndex++;
        } else {
            break;
        }
    }
}

// Pass data to JavaScript for analytics
$logsJson = json_encode($allLogs);

// If JSON requested, return data and exit
if ($isJsonRequest) {
    header('Content-Type: application/json');
    echo json_encode([
        'logs' => $allLogs,
        'limit' => $limit,
        'total' => count($allLogs),
        'dateStart' => $dateStart,
        'dateEnd' => $dateEnd,
        'timestamp' => time()
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Chat AI Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/2.1.3/css/dataTables.dataTables.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.0.0/css/buttons.dataTables.css" />
    <script src="https://cdn.datatables.net/2.1.3/js/dataTables.js"></script>
    <script src="https://cdn.datatables.net/buttons/3.0.0/js/dataTables.buttons.js"></script>
    <script src="https://cdn.datatables.net/buttons/3.0.0/js/buttons.html5.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

    <!-- D3.js for fancy visualizations -->
    <script src="https://d3js.org/d3.v7.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <style>
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0;
        }
        html, body {
            font-size: 0.9rem;
        }
        table.dataTable tbody tr {
            height: 24px;
        }
        .accordion-header {
            padding: 0.2rem 0.5rem;
        }
        .accordion-body {
            padding: 0.2rem 0.5rem;
            max-height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        .accordion-button {
            padding: 0.2rem 0.5rem;
            font-size: 0.8rem;
        }
        .table td, .table th {
            word-wrap: break-word;
        }
        .query-column, .response-column, .tools-column {
            width: 300px;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .id-column, .project-id-column {
            width: auto;
        }
        .accordion-collapse {
            width: 100%;
            overflow: hidden;
        }
        .table td {
            word-wrap: break-word;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tool-step {
            margin-bottom: 0.5rem;
            padding: 0.3rem;
            background: #f8f9fa;
            border-left: 3px solid #007bff;
            font-size: 0.85rem;
        }
        .agent-steps {
            font-size: 0.85rem;
        }
        .filters-panel {
            background: #f8f9fa;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.5rem;
        }
        .controls-panel {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .dataTables_length {
            float: left;
        }
        .dataTables_filter {
            float: right;
            text-align: right;
        }
        .dataTables_wrapper .row {
            margin-bottom: 1rem;
        }
        .auto-refresh-indicator {
            color: #28a745;
            font-weight: bold;
        }

        /* Analytics Dashboard Styles */
        .nav-tabs {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 2rem;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s;
        }
        .nav-tabs .nav-link:hover {
            color: #007bff;
            border-bottom: 2px solid #007bff;
        }
        .nav-tabs .nav-link.active {
            color: #007bff;
            background: transparent;
            border-bottom: 2px solid #007bff;
        }
        .analytics-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 1rem;
            padding: 1.5rem;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            animation: cardPulse 3s ease-in-out infinite;
        }
        .analytics-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.5s;
        }
        .analytics-card:hover::before {
            opacity: 1;
            animation: shimmer 2s infinite;
        }
        .analytics-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 24px rgba(0,0,0,0.3);
        }
        .analytics-card.green {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .analytics-card.orange {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .analytics-card.blue {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        @keyframes cardPulse {
            0%, 100% { box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            50% { box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
        }
        @keyframes shimmer {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        .analytics-card h3 {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        .analytics-card .big-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }
        .analytics-card .trend {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        .chart-container {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            position: relative;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .chart-container:hover {
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        /* Make main chart row containers match height */
        .row .col-md-8 .chart-container,
        .row .col-md-4 .chart-container {
            min-height: 480px;
        }
        .chart-actions {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.5rem;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .chart-container:hover .chart-actions {
            opacity: 1;
        }
        .chart-btn {
            background: rgba(0,0,0,0.05);
            border: none;
            border-radius: 0.5rem;
            padding: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1.2rem;
        }
        .chart-btn:hover {
            background: rgba(0,0,0,0.1);
            transform: scale(1.1);
        }
        .trend-arrow {
            display: inline-block;
            margin-left: 0.5rem;
            font-size: 1.2em;
        }
        .trend-up { color: #38ef7d; }
        .trend-down { color: #f5576c; }
        .projection-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255,255,255,0.2);
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 500;
            backdrop-filter: blur(10px);
        }
        .loading-shimmer {
            animation: shimmerLoading 2s infinite;
        }
        @keyframes shimmerLoading {
            0% { opacity: 0.5; }
            50% { opacity: 1; }
            100% { opacity: 0.5; }
        }
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #333;
        }
        .chart-subtitle {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 1.5rem;
        }
        #tokensChart, #modelChart, #costChart, #heatmapChart, #flowChart {
            width: 100%;
        }
        .sparkline {
            height: 40px;
            margin-top: 0.5rem;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            margin-right: 1.5rem;
            font-size: 0.85rem;
        }
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 3px;
            margin-right: 0.5rem;
        }
        .time-range-toggle {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .time-range-toggle .btn {
            font-size: 0.85rem;
            padding: 0.25rem 0.75rem;
        }
        .tooltip-d3 {
            position: absolute;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.85rem;
            pointer-events: none;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .sankey-node {
            cursor: pointer;
        }
        .sankey-link {
            fill: none;
            stroke: #ccc;
            stroke-opacity: 0.5;
        }
        .sankey-link:hover {
            stroke-opacity: 0.8;
        }

        /* Live Indicator */
        .live-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(40, 167, 69, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            border: 2px solid #28a745;
        }
        .live-dot {
            width: 10px;
            height: 10px;
            background: #28a745;
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
            box-shadow: 0 0 10px #28a745;
        }
        .live-text {
            font-weight: 700;
            color: #28a745;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.7; }
        }

        /* Dark Mode */
        body.dark-mode {
            background: #1a1a2e;
            color: #eee;
        }
        body.dark-mode .chart-container {
            background: #16213e;
            color: #eee;
        }
        body.dark-mode .chart-title {
            color: #eee;
        }
        body.dark-mode .chart-subtitle {
            color: #aaa;
        }
        body.dark-mode .alert-info {
            background: #16213e;
            color: #eee;
            border-color: #0f3460;
        }
        body.dark-mode .nav-tabs .nav-link {
            color: #aaa;
        }
        body.dark-mode .nav-tabs .nav-link.active {
            color: #4facfe;
            background: #16213e;
        }
        body.dark-mode .table {
            color: #eee;
            background: #16213e;
        }
        body.dark-mode .table-striped tbody tr:nth-of-type(odd) {
            background: rgba(255,255,255,0.02);
        }
        body.dark-mode .form-select,
        body.dark-mode .form-control {
            background: #16213e;
            color: #eee;
            border-color: #0f3460;
        }
        body.dark-mode .btn-outline-secondary {
            color: #eee;
            border-color: #0f3460;
        }
        body.dark-mode .btn-outline-secondary:hover {
            background: #0f3460;
            color: #fff;
        }
        body.dark-mode .accordion-button {
            background: #16213e;
            color: #eee;
        }

        /* Theme System */
        .analytics-card.theme-ocean { background: linear-gradient(135deg, #2E3192 0%, #1BFFFF 100%); }
        .analytics-card.theme-ocean.green { background: linear-gradient(135deg, #134E5E 0%, #71B280 100%); }
        .analytics-card.theme-ocean.orange { background: linear-gradient(135deg, #EE9CA7 0%, #FFDDE1 100%); }
        .analytics-card.theme-ocean.blue { background: linear-gradient(135deg, #06beb6 0%, #48b1bf 100%); }

        .analytics-card.theme-sunset { background: linear-gradient(135deg, #FF6B6B 0%, #FFE66D 100%); }
        .analytics-card.theme-sunset.green { background: linear-gradient(135deg, #F8B500 0%, #FF6B6B 100%); }
        .analytics-card.theme-sunset.orange { background: linear-gradient(135deg, #FF9A56 0%, #FF6B9D 100%); }
        .analytics-card.theme-sunset.blue { background: linear-gradient(135deg, #4FACFE 0%, #F093FB 100%); }

        .analytics-card.theme-forest { background: linear-gradient(135deg, #134E5E 0%, #71B280 100%); }
        .analytics-card.theme-forest.green { background: linear-gradient(135deg, #0F2027 0%, #2C5364 100%); }
        .analytics-card.theme-forest.orange { background: linear-gradient(135deg, #C02425 0%, #F0CB35 100%); }
        .analytics-card.theme-forest.blue { background: linear-gradient(135deg, #1D976C 0%, #93F9B9 100%); }

        .analytics-card.theme-neon { background: linear-gradient(135deg, #B226E1 0%, #D100D1 100%); }
        .analytics-card.theme-neon.green { background: linear-gradient(135deg, #00F260 0%, #0575E6 100%); }
        .analytics-card.theme-neon.orange { background: linear-gradient(135deg, #FA8BFF 0%, #2BD2FF 100%); }
        .analytics-card.theme-neon.blue { background: linear-gradient(135deg, #4E65FF 0%, #92EFFD 100%); }

        .analytics-card.theme-monochrome { background: linear-gradient(135deg, #2C3E50 0%, #4CA1AF 100%); }
        .analytics-card.theme-monochrome.green { background: linear-gradient(135deg, #373B44 0%, #4286f4 100%); }
        .analytics-card.theme-monochrome.orange { background: linear-gradient(135deg, #556270 0%, #FF6B6B 100%); }
        .analytics-card.theme-monochrome.blue { background: linear-gradient(135deg, #283048 0%, #859398 100%); }

        .dashboard-controls {
            animation: slideIn 0.5s ease-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .refresh-toast {
            position: fixed;
            top: 80px;
            right: 20px;
            background: rgba(40, 167, 69, 0.95);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 9999;
            font-weight: 500;
            backdrop-filter: blur(10px);
        }
        /* Demo mode styling */
        .alert-info:has(.badge.bg-warning) {
            border: 3px dashed #ffc107;
            background: linear-gradient(135deg, #fff3cd 0%, #fffaeb 100%);
            animation: demoPulse 3s ease-in-out infinite;
        }
        @keyframes demoPulse {
            0%, 100% { border-color: #ffc107; }
            50% { border-color: #ff9800; }
        }
    </style>
</head>
<body>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Secure Chat AI Monitor</h2>
        <div class="dashboard-controls d-flex gap-2 align-items-center">
            <button class="btn btn-sm btn-outline-primary" id="manualRefreshBtn" title="Refresh now">
                🔄
            </button>
            <div class="live-indicator" id="liveIndicator" style="display:none;">
                <span class="live-dot"></span>
                <span class="live-text">LIVE</span>
            </div>
            <select class="form-select form-select-sm" id="themeSelector" style="width: auto;">
                <option value="default">🎨 Default</option>
                <option value="ocean" selected>🌊 Ocean</option>
                <option value="sunset">🌅 Sunset</option>
                <option value="forest">🌲 Forest</option>
                <option value="neon">⚡ Neon</option>
                <option value="monochrome">⚫ Monochrome</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary" id="darkModeToggle">
                <span id="darkModeIcon">🌙</span>
            </button>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs" id="mainTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="analytics-tab" data-bs-toggle="tab" data-bs-target="#analytics" type="button" role="tab">
                📊 Analytics Dashboard
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs" type="button" role="tab">
                📋 Logs Table
            </button>
        </li>
    </ul>

    <div class="tab-content" id="mainTabsContent">

        <!-- Analytics Dashboard Tab -->
        <div class="tab-pane fade show active" id="analytics" role="tabpanel">

            <!-- Data Info Banner -->
            <div class="alert alert-info mb-3" id="dataBanner">
                <strong>📊 Analytics Overview</strong> - Showing data from <span id="dataDateRange">all available logs</span>
                (<span id="totalLogsCount">-</span> total records)
                <?php if ($isDemoMode): ?>
                <span class="badge bg-warning text-dark ms-2">🎭 DEMO MODE - Fake Data</span>
                <a href="<?= strtok($_SERVER['REQUEST_URI'], '?') ?>" class="btn btn-sm btn-outline-secondary ms-2">Exit Demo</a>
                <?php endif; ?>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="analytics-card">
                        <h3>Total Tokens</h3>
                        <div class="big-number" id="totalTokens">-</div>
                        <div class="trend" id="tokenTrend">-</div>
                        <svg class="sparkline" id="tokenSparkline"></svg>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="analytics-card green">
                        <h3>Estimated Cost</h3>
                        <div class="big-number" id="totalCost">-</div>
                        <div class="trend" id="costTrend">-</div>
                        <svg class="sparkline" id="costSparkline"></svg>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="analytics-card orange">
                        <h3>Total API Calls</h3>
                        <div class="big-number" id="totalCalls">-</div>
                        <div class="trend" id="callsTrend">-</div>
                        <svg class="sparkline" id="callsSparkline"></svg>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="analytics-card blue">
                        <h3>Agent Mode Usage</h3>
                        <div class="big-number" id="agentPercent">-</div>
                        <div class="trend" id="agentTrend">-</div>
                        <svg class="sparkline" id="agentSparkline"></svg>
                    </div>
                </div>
            </div>

            <!-- Main Charts Row -->
            <div class="row">
                <div class="col-md-8">
                    <div class="chart-container">
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="downloadChart('tokensChart')" title="Download as PNG">📥</button>
                            <button class="chart-btn" onclick="toggleFullscreen(this)" title="Fullscreen">⛶</button>
                        </div>
                        <div class="chart-title">Token Usage Over Time</div>
                        <div class="chart-subtitle">Interactive timeline showing prompt and completion tokens</div>
                        <div class="time-range-toggle" id="timeRangeToggle">
                            <button class="btn btn-sm btn-primary active" data-range="hourly">Hourly</button>
                            <button class="btn btn-sm btn-outline-primary" data-range="daily">Daily</button>
                            <button class="btn btn-sm btn-outline-primary" data-range="weekly">Weekly</button>
                        </div>
                        <div class="mb-2">
                            <span class="legend-item"><span class="legend-color" style="background:#667eea;"></span> Prompt Tokens</span>
                            <span class="legend-item"><span class="legend-color" style="background:#11998e;"></span> Completion Tokens</span>
                            <span class="legend-item"><span class="legend-color" style="background:#f5576c;"></span> Total Tokens</span>
                        </div>
                        <svg id="tokensChart" height="350"></svg>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="chart-container">
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="downloadChart('modelChart')" title="Download as PNG">📥</button>
                            <button class="chart-btn" onclick="toggleFullscreen(this)" title="Fullscreen">⛶</button>
                        </div>
                        <div class="chart-title">Model Distribution</div>
                        <div class="chart-subtitle">API calls by model type</div>
                        <svg id="modelChart" height="350"></svg>
                    </div>
                </div>
            </div>

            <!-- Cost Analysis -->
            <div class="row">
                <div class="col-md-12">
                    <div class="chart-container">
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="downloadChart('costChart')" title="Download as PNG">📥</button>
                            <button class="chart-btn" onclick="toggleFullscreen(this)" title="Fullscreen">⛶</button>
                        </div>
                        <div class="chart-title">Cost Analysis by Model</div>
                        <div class="chart-subtitle">Estimated daily costs across different models</div>
                        <svg id="costChart" height="300"></svg>
                    </div>
                </div>
            </div>

            <!-- Activity Heatmap & Agent Flow -->
            <div class="row">
                <div class="col-md-6">
                    <div class="chart-container">
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="downloadChart('heatmapChart')" title="Download as PNG">📥</button>
                            <button class="chart-btn" onclick="toggleFullscreen(this)" title="Fullscreen">⛶</button>
                        </div>
                        <div class="chart-title">Activity Heatmap</div>
                        <div class="chart-subtitle">Usage patterns by hour and day of week</div>
                        <svg id="heatmapChart" height="300"></svg>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <div class="chart-actions">
                            <button class="chart-btn" onclick="downloadChart('flowChart')" title="Download as PNG">📥</button>
                            <button class="chart-btn" onclick="toggleFullscreen(this)" title="Fullscreen">⛶</button>
                        </div>
                        <div class="chart-title">Agent Tool Flow</div>
                        <div class="chart-subtitle">Most common tool execution sequences</div>
                        <svg id="flowChart" height="300"></svg>
                    </div>
                </div>
            </div>

        </div>

        <!-- Logs Table Tab -->
        <div class="tab-pane fade" id="logs" role="tabpanel">

            <!-- Controls Panel -->
            <div class="controls-panel">
        <button class="btn btn-sm btn-primary" id="exportBtn">Export (CSV)</button>
        <button class="btn btn-sm btn-secondary" id="exportJsonBtn">Export (JSON)</button>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="autoRefreshToggle" checked>
            <label class="form-check-label" for="autoRefreshToggle">Auto-refresh (30s)</label>
            <span id="autoRefreshIndicator" class="auto-refresh-indicator ms-2">●</span>
        </div>
        <div class="ms-auto d-flex gap-2 align-items-center">
            <label>Date Range: </label>
            <input type="date" id="dateStart" class="form-control form-control-sm" style="width: 150px;">
            <span>to</span>
            <input type="date" id="dateEnd" class="form-control form-control-sm" style="width: 150px;">
            <button class="btn btn-sm btn-outline-primary" id="applyDateFilter">Apply</button>
            <button class="btn btn-sm btn-outline-secondary" id="clearDateFilter">Clear</button>
            <label class="ms-3">Limit: </label>
            <select id="limitSelect" class="form-select form-select-sm d-inline-block" style="width: auto;">
                <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                <option value="250" <?= $limit == 250 ? 'selected' : '' ?>>250</option>
                <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>500</option>
                <option value="1000" <?= $limit == 1000 ? 'selected' : '' ?>>1000</option>
            </select>
        </div>
    </div>

    <!-- Filters Panel -->
    <div class="filters-panel">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Model</label>
                <select class="form-select form-select-sm" id="modelFilter">
                    <option value="">All Models</option>
                    <?php foreach ($uniqueModels as $model): ?>
                        <option value="<?= htmlspecialchars($model, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($model, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Project ID</label>
                <select class="form-select form-select-sm" id="projectFilter">
                    <option value="">All Projects</option>
                    <?php foreach ($uniqueProjects as $project): ?>
                        <option value="<?= htmlspecialchars($project, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($project, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Type</label>
                <select class="form-select form-select-sm" id="typeFilter">
                    <option value="">All Types</option>
                    <?php foreach ($uniqueTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Agent Mode Only</label>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="agentModeFilter">
                    <label class="form-check-label" for="agentModeFilter">Show only agent calls</label>
                </div>
            </div>
        </div>
    </div>

            <table class="table table-striped table-bordered" id="logTable">
                <thead class="thead-dark">
                <tr>
                    <th>ID</th>
                    <th>Project ID</th>
                    <th>Type</th>
                    <th>Timestamp</th>
                    <th>Model</th>
                    <th>Tokens</th>
                    <th>Model Meta</th>
                    <th>Query</th>
                    <th>Response</th>
                    <th>Tools Used</th>
                </tr>
                </thead>
                <tbody>
                <?php echo $rows; ?>
                </tbody>
            </table>

        </div><!-- End Logs Tab -->

    </div><!-- End Tab Content -->

</div><!-- End Container -->

<!-- D3 Tooltip -->
<div class="tooltip-d3" id="d3-tooltip"></div>

<script>
    // Pass PHP data to JavaScript
    const logsData = <?php echo $logsJson; ?>;

    $(document).ready(function() {
        let autoRefreshInterval = null;

        // Initialize analytics dashboard
        initAnalyticsDashboard();

        // Custom sorting for token column
        $.fn.dataTable.ext.order['tokens-sort'] = function(settings, col) {
            return this.api().column(col, { order: 'index' }).nodes().map(function(td, i) {
                var totalTokens = $(td).find('.accordion-button').text().match(/Total: (\d+)/);
                return totalTokens ? parseInt(totalTokens[1], 10) : 0;
            });
        };

        // Custom sorting for tools column
        $.fn.dataTable.ext.order['tools-sort'] = function(settings, col) {
            return this.api().column(col, { order: 'index' }).nodes().map(function(td, i) {
                var toolCount = $(td).find('.accordion-button').text().match(/(\d+) tool/);
                return toolCount ? parseInt(toolCount[1], 10) : 0;
            });
        };

        // Initialize DataTable with export buttons
        var table = $('#logTable').DataTable({
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "fixedColumns": true,
            "pageLength": 25,
            "lengthMenu": [10, 25, 50, 75, 100],
            "order": [[0, 'desc']], // Default sort by ID descending (newest first)
            "columnDefs": [
                {
                    "targets": 5,
                    "orderDataType": "tokens-sort"
                },
                {
                    "targets": 9,
                    "orderDataType": "tools-sort"
                }
            ],
            "dom": '<"row"<"col-sm-6"l><"col-sm-6"f>>rtip', // Put length and search on same row
            "buttons": []
        });

        // Model filter
        $('#modelFilter').on('change', function() {
            table.column(4).search(this.value).draw();
        });

        // Project filter
        $('#projectFilter').on('change', function() {
            table.column(1).search(this.value).draw();
        });

        // Type filter
        $('#typeFilter').on('change', function() {
            table.column(2).search(this.value).draw();
        });

        // Agent mode filter
        $('#agentModeFilter').on('change', function() {
            if (this.checked) {
                // Show only rows with tools
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    var toolsCell = $(table.row(dataIndex).node()).find('.tools-column').text().trim();
                    return toolsCell !== '—' && toolsCell !== '';
                });
            } else {
                // Remove custom filter
                $.fn.dataTable.ext.search.pop();
            }
            table.draw();
        });

        // Limit selector
        $('#limitSelect').on('change', function() {
            window.location.href = window.location.pathname + '?limit=' + this.value;
        });

        // Export CSV
        $('#exportBtn').on('click', function() {
            // Get filtered/sorted data
            var data = table.rows({ search: 'applied' }).data();
            var csv = 'ID,Project ID,Type,Timestamp,Model,Tokens,Temperature,Top P,Freq Penalty,Pres Penalty\n';

            data.each(function(row) {
                // Extract data from HTML (simplified for CSV)
                var $row = $(table.row(':contains("' + row[0] + '")').node());
                csv += '"' + row[0] + '",';  // ID
                csv += '"' + row[1] + '",';  // Project ID
                csv += '"' + row[2] + '",';  // Type
                csv += '"' + row[3] + '",';  // Timestamp
                csv += '"' + row[4] + '"\n'; // Model
            });

            var blob = new Blob([csv], { type: 'text/csv' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'securechat-logs-' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
        });

        // Export JSON
        $('#exportJsonBtn').on('click', function() {
            var data = table.rows({ search: 'applied' }).data().toArray();
            var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'securechat-logs-' + new Date().toISOString().slice(0,10) + '.json';
            a.click();
        });

        // Auto-refresh toggle
        $('#autoRefreshToggle').on('change', function() {
            if (this.checked) {
                $('#autoRefreshIndicator').show();
                $('#liveIndicator').show();
                autoRefreshInterval = setInterval(function() {
                    refreshDataAjax();
                }, 30000); // 30 seconds
            } else {
                $('#autoRefreshIndicator').hide();
                $('#liveIndicator').hide();
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            }
        });

        // Start auto-refresh by default (since checkbox is checked)
        if ($('#autoRefreshToggle').is(':checked')) {
            $('#autoRefreshIndicator').show();
            $('#liveIndicator').show();
            autoRefreshInterval = setInterval(function() {
                refreshDataAjax();
            }, 30000);
        }

        // Date filtering
        $('#applyDateFilter').on('click', function() {
            const startDate = $('#dateStart').val();
            const endDate = $('#dateEnd').val();

            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }

            // Add date filters to URL and reload
            const params = new URLSearchParams(window.location.search);
            params.set('dateStart', startDate);
            params.set('dateEnd', endDate);
            window.location.search = params.toString();
        });

        $('#clearDateFilter').on('click', function() {
            const params = new URLSearchParams(window.location.search);
            params.delete('dateStart');
            params.delete('dateEnd');
            window.location.search = params.toString();
        });

        // Load date filters from URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('dateStart')) {
            $('#dateStart').val(urlParams.get('dateStart'));
        }
        if (urlParams.has('dateEnd')) {
            $('#dateEnd').val(urlParams.get('dateEnd'));
        }

        // Dark mode toggle
        $('#darkModeToggle').on('click', function() {
            $('body').toggleClass('dark-mode');
            const isDark = $('body').hasClass('dark-mode');
            $('#darkModeIcon').text(isDark ? '☀️' : '🌙');
            localStorage.setItem('darkMode', isDark);
        });

        // Load dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            $('body').addClass('dark-mode');
            $('#darkModeIcon').text('☀️');
        }

        // Theme selector
        $('#themeSelector').on('change', function() {
            const theme = this.value;
            $('.analytics-card').removeClass('theme-ocean theme-sunset theme-forest theme-neon theme-monochrome');
            if (theme !== 'default') {
                $('.analytics-card').addClass('theme-' + theme);
            }
            localStorage.setItem('dashboardTheme', theme);
        });

        // Load theme preference (default to ocean if none saved - for the humorless directors)
        const savedTheme = localStorage.getItem('dashboardTheme') || 'ocean';
        if (savedTheme !== 'default') {
            $('#themeSelector').val(savedTheme);
            $('.analytics-card').addClass('theme-' + savedTheme);
        }

        // Manual refresh button
        $('#manualRefreshBtn').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).css('animation', 'spin 0.6s linear');

            refreshDataAjax();

            setTimeout(() => {
                btn.prop('disabled', false).css('animation', 'none');
            }, 1000);
        });

        // Time range toggle buttons
        $('#timeRangeToggle button').on('click', function() {
            const btn = $(this);
            const range = btn.data('range');

            // Update button states
            $('#timeRangeToggle button').removeClass('btn-primary active').addClass('btn-outline-primary');
            btn.removeClass('btn-outline-primary').addClass('btn-primary active');

            // Re-render chart with new time range
            const processedData = processLogsData(window.logsData);
            renderTokensChart(processedData, range);
        });

        // Ensure all accordions in the row expand/collapse together
        $('#logTable').on("click", ".accordion-button", function(event) {
            event.stopPropagation();
            event.preventDefault();

            let row = $(this).closest("tr");
            let isExpanding = row.find('.accordion-collapse.show').length === 0;

            row.find('.accordion-button').each(function() {
                let button = $(this);
                let target = button.attr('data-bs-target');

                if (isExpanding) {
                    button.removeClass('collapsed').attr('aria-expanded', true);
                    $(target).addClass('show').collapse('show');
                } else {
                    button.addClass('collapsed').attr('aria-expanded', false);
                    $(target).removeClass('show').collapse('hide');
                }
            });
        });

        // Prevent sorting when clicking accordion buttons
        $('#logTable').on('click', 'th', function(event) {
            if ($(event.target).closest('.accordion-button').length > 0) {
                event.stopImmediatePropagation();
            }
        });
    });

    // ============================================================
    // ANALYTICS DASHBOARD - D3.js Visualizations
    // ============================================================

    // Model pricing (per 1M tokens)
    const MODEL_PRICING = {
        'gpt-4': { prompt: 30, completion: 60 },
        'gpt-4o': { prompt: 2.5, completion: 10 },
        'gpt-4o-mini': { prompt: 0.15, completion: 0.6 },
        'gpt-4.1': { prompt: 30, completion: 60 },
        'o1': { prompt: 15, completion: 60 },
        'o3-mini': { prompt: 1.1, completion: 4.4 },
        'gpt-5': { prompt: 50, completion: 100 },
        'claude-3.5-sonnet': { prompt: 3, completion: 15 },
        'claude-3-opus': { prompt: 15, completion: 75 },
        'claude-3-haiku': { prompt: 0.25, completion: 1.25 },
        'gemini-1.5-pro': { prompt: 1.25, completion: 5 },
        'gemini-1.5-flash': { prompt: 0.075, completion: 0.3 },
        'llama-3.3-70b': { prompt: 0.35, completion: 0.4 },
        'deepseek-chat': { prompt: 0.27, completion: 1.1 },
        'ada-002': { prompt: 0.1, completion: 0 }, // embeddings
        'default': { prompt: 1, completion: 2 }
    };

    function calculateCost(model, promptTokens, completionTokens) {
        const pricing = MODEL_PRICING[model] || MODEL_PRICING['default'];
        const promptCost = (promptTokens / 1000000) * pricing.prompt;
        const completionCost = (completionTokens / 1000000) * pricing.completion;
        return promptCost + completionCost;
    }

    function initAnalyticsDashboard() {
        console.log('Initializing analytics with', logsData.length, 'logs');

        // Process logs data
        const processedData = processLogsData(logsData);

        // Update info banner
        $('#totalLogsCount').text(logsData.length.toLocaleString());

        if (logsData.length > 0) {
            const timestamps = logsData
                .filter(l => l.timestamp)
                .map(l => new Date(l.timestamp));

            if (timestamps.length > 0) {
                const minDate = new Date(Math.min(...timestamps));
                const maxDate = new Date(Math.max(...timestamps));
                $('#dataDateRange').text(
                    minDate.toLocaleDateString() + ' to ' + maxDate.toLocaleDateString()
                );
            }
        }

        // Render summary cards
        renderSummaryCards(processedData);

        // Render charts
        renderTokensChart(processedData, 'hourly');
        renderModelChart(processedData);
        renderCostChart(processedData);
        renderHeatmap(processedData);
        renderAgentFlow(processedData);
    }

    function processLogsData(logs) {
        const now = new Date();
        const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const oneDayAgo = new Date(now.getTime() - 24 * 60 * 60 * 1000);
        const twoDaysAgo = new Date(now.getTime() - 48 * 60 * 60 * 1000);

        let totalTokensAllTime = 0;
        let totalCostAllTime = 0;
        let totalCalls = 0;
        let agentCalls = 0;

        // Trend calculation (last 24h vs previous 24h)
        let tokensLast24h = 0;
        let tokensPrev24h = 0;
        let costLast24h = 0;
        let costPrev24h = 0;
        let callsLast24h = 0;
        let callsPrev24h = 0;
        let agentCallsLast24h = 0;
        let agentCallsPrev24h = 0;

        const hourlyData = {};
        const dailyData = {};
        const modelCounts = {};
        const modelCosts = {};
        const heatmapData = Array(7).fill(0).map(() => Array(24).fill(0));
        const toolSequences = [];

        console.log('Processing logs:', logs.length);
        console.log('Start of day:', startOfDay);

        logs.forEach((log, idx) => {
            if (!log.timestamp) {
                console.warn('Log missing timestamp:', idx);
                return;
            }

            const timestamp = new Date(log.timestamp);
            const promptTokens = log.usage?.prompt_tokens || 0;
            const completionTokens = log.usage?.completion_tokens || 0;
            const totalTokens = log.usage?.total_tokens || 0;
            const model = log.model || 'unknown';
            const cost = calculateCost(model, promptTokens, completionTokens);

            totalCalls++;
            totalTokensAllTime += totalTokens;
            totalCostAllTime += cost;

            // Trend tracking (last 24h vs previous 24h)
            if (timestamp >= oneDayAgo) {
                tokensLast24h += totalTokens;
                costLast24h += cost;
                callsLast24h++;
            } else if (timestamp >= twoDaysAgo && timestamp < oneDayAgo) {
                tokensPrev24h += totalTokens;
                costPrev24h += cost;
                callsPrev24h++;
            }

            // Agent mode detection
            const hasAgentTools = log.choices?.[0]?.message?.tools_used?.length > 0;
            if (hasAgentTools) {
                agentCalls++;
                if (timestamp >= oneDayAgo) agentCallsLast24h++;
                else if (timestamp >= twoDaysAgo && timestamp < oneDayAgo) agentCallsPrev24h++;

                const tools = log.choices[0].message.tools_used;
                if (tools.length > 1) {
                    toolSequences.push(tools.map(t => t.name));
                }
            }

            // Hourly aggregation
            const hourKey = timestamp.toISOString().slice(0, 13); // YYYY-MM-DDTHH
            if (!hourlyData[hourKey]) {
                hourlyData[hourKey] = { prompt: 0, completion: 0, total: 0, cost: 0, calls: 0 };
            }
            hourlyData[hourKey].prompt += promptTokens;
            hourlyData[hourKey].completion += completionTokens;
            hourlyData[hourKey].total += totalTokens;
            hourlyData[hourKey].cost += cost;
            hourlyData[hourKey].calls += 1;

            // Daily aggregation
            const dayKey = timestamp.toISOString().slice(0, 10); // YYYY-MM-DD
            if (!dailyData[dayKey]) {
                dailyData[dayKey] = { prompt: 0, completion: 0, total: 0, cost: 0, calls: 0 };
            }
            dailyData[dayKey].prompt += promptTokens;
            dailyData[dayKey].completion += completionTokens;
            dailyData[dayKey].total += totalTokens;
            dailyData[dayKey].cost += cost;
            dailyData[dayKey].calls += 1;

            // Model distribution
            modelCounts[model] = (modelCounts[model] || 0) + 1;
            modelCosts[model] = (modelCosts[model] || 0) + cost;

            // Heatmap (hour x day of week)
            const hour = timestamp.getHours();
            const day = timestamp.getDay(); // 0 = Sunday
            heatmapData[day][hour] += totalTokens;
        });

        // Calculate trends
        const tokenTrend = tokensPrev24h > 0
            ? (((tokensLast24h - tokensPrev24h) / tokensPrev24h) * 100).toFixed(1)
            : 0;
        const costTrend = costPrev24h > 0
            ? (((costLast24h - costPrev24h) / costPrev24h) * 100).toFixed(1)
            : 0;
        const callsTrend = callsPrev24h > 0
            ? (((callsLast24h - callsPrev24h) / callsPrev24h) * 100).toFixed(1)
            : 0;
        const agentPercentLast24h = callsLast24h > 0
            ? ((agentCallsLast24h / callsLast24h) * 100).toFixed(1)
            : 0;
        const agentPercentPrev24h = callsPrev24h > 0
            ? ((agentCallsPrev24h / callsPrev24h) * 100).toFixed(1)
            : 0;
        const agentTrend = agentPercentPrev24h > 0
            ? (((agentPercentLast24h - agentPercentPrev24h) / agentPercentPrev24h) * 100).toFixed(1)
            : 0;

        console.log('Processed:', {
            totalCalls,
            totalTokens: totalTokensAllTime,
            totalCost: totalCostAllTime,
            trends: { tokenTrend, costTrend, callsTrend, agentTrend },
            hourlyDataPoints: Object.keys(hourlyData).length,
            dailyDataPoints: Object.keys(dailyData).length,
            models: Object.keys(modelCounts)
        });

        return {
            totalTokens: totalTokensAllTime,
            totalCost: totalCostAllTime,
            totalCalls,
            agentCalls,
            agentPercent: totalCalls > 0 ? ((agentCalls / totalCalls) * 100).toFixed(1) : 0,
            tokenTrend,
            costTrend,
            callsTrend,
            agentTrend,
            hourlyData,
            dailyData,
            modelCounts,
            modelCosts,
            heatmapData,
            toolSequences
        };
    }

    function renderSummaryCards(data) {
        // Format numbers nicely
        const tokensDisplay = data.totalTokens >= 1000000
            ? (data.totalTokens / 1000000).toFixed(2) + 'M'
            : (data.totalTokens / 1000).toFixed(1) + 'K';

        const costDisplay = data.totalCost >= 1
            ? '$' + data.totalCost.toFixed(2)
            : '$' + data.totalCost.toFixed(4);

        // Animated counters (start from current value on refresh, or 0 on first load)
        const isFirstLoad = $('#totalTokens').text() === '-';
        animateNumber('#totalTokens', isFirstLoad ? 0 : null, data.totalTokens, tokensDisplay, isFirstLoad ? 1500 : 800);
        animateNumber('#totalCost', isFirstLoad ? 0 : null, data.totalCost, costDisplay, isFirstLoad ? 1500 : 800);
        animateNumber('#totalCalls', isFirstLoad ? 0 : null, data.totalCalls, data.totalCalls.toLocaleString(), isFirstLoad ? 1500 : 800);
        animateNumber('#agentPercent', isFirstLoad ? 0 : null, parseFloat(data.agentPercent), data.agentPercent + '%', isFirstLoad ? 1500 : 800);

        // Add trend arrows
        const trendArrow = (trend) => {
            const val = parseFloat(trend);
            if (val > 0) return `<span class="trend-arrow trend-up">↗ +${Math.abs(val)}%</span>`;
            if (val < 0) return `<span class="trend-arrow trend-down">↘ ${val}%</span>`;
            return '<span class="trend-arrow">→ 0%</span>';
        };

        $('#tokenTrend').html('All time ' + trendArrow(data.tokenTrend));
        $('#costTrend').html(Object.keys(data.modelCounts).length + ' models ' + trendArrow(data.costTrend));
        $('#callsTrend').html(data.agentCalls + ' agent ' + trendArrow(data.callsTrend));
        $('#agentTrend').html('of total ' + trendArrow(data.agentTrend));

        // Sparklines
        renderSparkline('#tokenSparkline', data.hourlyData, 'total');
        renderSparkline('#costSparkline', data.hourlyData, 'cost');
        renderSparkline('#callsSparkline', data.hourlyData, 'calls');
        renderSparkline('#agentSparkline', data.hourlyData, 'calls'); // Same shape as calls for now
    }

    function renderSparkline(selector, hourlyData, field) {
        const svg = d3.select(selector);
        const width = svg.node().parentElement.clientWidth;
        const height = 40;

        svg.attr('viewBox', `0 0 ${width} ${height}`);

        const sortedData = Object.entries(hourlyData)
            .sort((a, b) => a[0].localeCompare(b[0]))
            .slice(-24) // Last 24 hours
            .map(([key, val]) => val[field]);

        if (sortedData.length === 0 || sortedData.every(d => d === 0)) {
            // Show flat line if no data
            svg.selectAll('*').remove();
            svg.append('line')
                .attr('x1', 0)
                .attr('x2', width)
                .attr('y1', height / 2)
                .attr('y2', height / 2)
                .attr('stroke', 'rgba(255,255,255,0.3)')
                .attr('stroke-width', 1)
                .attr('stroke-dasharray', '3,3');
            return;
        }

        const maxVal = d3.max(sortedData);
        const minVal = d3.min(sortedData);

        const x = d3.scaleLinear()
            .domain([0, sortedData.length - 1])
            .range([0, width]);

        const y = d3.scaleLinear()
            .domain([minVal * 0.9, maxVal * 1.1]) // Add padding
            .range([height - 2, 2]);

        const line = d3.line()
            .x((d, i) => x(i))
            .y(d => y(d))
            .curve(d3.curveMonotoneX);

        svg.selectAll('*').remove();

        // Add area fill
        const area = d3.area()
            .x((d, i) => x(i))
            .y0(height - 2)
            .y1(d => y(d))
            .curve(d3.curveMonotoneX);

        svg.append('path')
            .datum(sortedData)
            .attr('fill', 'rgba(255,255,255,0.2)')
            .attr('d', area);

        svg.append('path')
            .datum(sortedData)
            .attr('fill', 'none')
            .attr('stroke', 'rgba(255,255,255,0.9)')
            .attr('stroke-width', 2)
            .attr('d', line);
    }

    let currentTimeRange = 'hourly'; // Track current view

    function renderTokensChart(data, timeRange = 'hourly') {
        currentTimeRange = timeRange;

        const svg = d3.select('#tokensChart');
        const container = svg.node().parentElement;
        const width = container.clientWidth;
        const height = 350;
        const margin = { top: 20, right: 30, bottom: 50, left: 60 };

        svg.attr('viewBox', `0 0 ${width} ${height}`);
        svg.selectAll('*').remove();

        const g = svg.append('g')
            .attr('transform', `translate(${margin.left},${margin.top})`);

        const innerWidth = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;

        // Select data based on time range
        let currentData;
        let timeFormat;

        if (timeRange === 'hourly') {
            currentData = Object.entries(data.hourlyData)
                .sort((a, b) => a[0].localeCompare(b[0]))
                .slice(-48) // Last 48 hours
                .map(([key, val]) => ({
                    time: new Date(key + ':00'), // Ensure proper date parsing
                    prompt: val.prompt,
                    completion: val.completion,
                    total: val.total
                }));
            timeFormat = d3.timeFormat('%m/%d %H:%M');
        } else if (timeRange === 'daily') {
            currentData = Object.entries(data.dailyData)
                .sort((a, b) => a[0].localeCompare(b[0]))
                .slice(-30) // Last 30 days
                .map(([key, val]) => ({
                    time: new Date(key),
                    prompt: val.prompt,
                    completion: val.completion,
                    total: val.total
                }));
            timeFormat = d3.timeFormat('%m/%d');
        } else { // weekly
            // Aggregate daily data into weeks
            const weeklyData = {};
            Object.entries(data.dailyData)
                .sort((a, b) => a[0].localeCompare(b[0]))
                .forEach(([key, val]) => {
                    const date = new Date(key);
                    const weekStart = new Date(date);
                    weekStart.setDate(date.getDate() - date.getDay()); // Start of week (Sunday)
                    const weekKey = weekStart.toISOString().slice(0, 10);

                    if (!weeklyData[weekKey]) {
                        weeklyData[weekKey] = { prompt: 0, completion: 0, total: 0 };
                    }
                    weeklyData[weekKey].prompt += val.prompt;
                    weeklyData[weekKey].completion += val.completion;
                    weeklyData[weekKey].total += val.total;
                });

            currentData = Object.entries(weeklyData)
                .sort((a, b) => a[0].localeCompare(b[0]))
                .slice(-12) // Last 12 weeks
                .map(([key, val]) => ({
                    time: new Date(key),
                    prompt: val.prompt,
                    completion: val.completion,
                    total: val.total
                }));
            timeFormat = d3.timeFormat('%m/%d');
        }

        console.log('Token chart data points:', currentData.length, 'timeRange:', timeRange);

        if (currentData.length === 0) {
            g.append('text')
                .attr('x', innerWidth / 2)
                .attr('y', innerHeight / 2)
                .attr('text-anchor', 'middle')
                .style('fill', '#999')
                .text('No token data available');
            return;
        }

        const x = d3.scaleTime()
            .domain(d3.extent(currentData, d => d.time))
            .range([0, innerWidth]);

        const y = d3.scaleLinear()
            .domain([0, d3.max(currentData, d => d.total)])
            .nice()
            .range([innerHeight, 0]);

        // Add axes
        const tickCount = timeRange === 'hourly' ? 8 : timeRange === 'daily' ? 10 : 6;
        g.append('g')
            .attr('transform', `translate(0,${innerHeight})`)
            .call(d3.axisBottom(x)
                .ticks(tickCount)
                .tickFormat(timeFormat)
            )
            .selectAll('text')
            .attr('transform', 'rotate(-35)')
            .style('text-anchor', 'end')
            .style('font-size', '11px')
            .attr('dx', '-0.5em')
            .attr('dy', '0.5em');

        g.append('g')
            .call(d3.axisLeft(y).tickFormat(d => {
                if (d >= 1000) return (d / 1000) + 'K';
                return d;
            }));

        // Add lines
        const linePrompt = d3.line()
            .x(d => x(d.time))
            .y(d => y(d.prompt))
            .curve(d3.curveMonotoneX);

        const lineCompletion = d3.line()
            .x(d => x(d.time))
            .y(d => y(d.completion))
            .curve(d3.curveMonotoneX);

        const lineTotal = d3.line()
            .x(d => x(d.time))
            .y(d => y(d.total))
            .curve(d3.curveMonotoneX);

        g.append('path')
            .datum(currentData)
            .attr('fill', 'none')
            .attr('stroke', '#667eea')
            .attr('stroke-width', 2)
            .attr('d', linePrompt);

        g.append('path')
            .datum(currentData)
            .attr('fill', 'none')
            .attr('stroke', '#11998e')
            .attr('stroke-width', 2)
            .attr('d', lineCompletion);

        g.append('path')
            .datum(currentData)
            .attr('fill', 'none')
            .attr('stroke', '#f5576c')
            .attr('stroke-width', 3)
            .attr('d', lineTotal);

        // Add interactive dots
        const tooltip = d3.select('#d3-tooltip');

        g.selectAll('.dot')
            .data(currentData)
            .enter().append('circle')
            .attr('class', 'dot')
            .attr('cx', d => x(d.time))
            .attr('cy', d => y(d.total))
            .attr('r', 4)
            .attr('fill', '#f5576c')
            .style('cursor', 'pointer')
            .on('mouseover', function(event, d) {
                tooltip.style('opacity', 1)
                    .html(`
                        <strong>${d.time.toLocaleString()}</strong><br>
                        Prompt: ${d.prompt.toLocaleString()}<br>
                        Completion: ${d.completion.toLocaleString()}<br>
                        <strong>Total: ${d.total.toLocaleString()}</strong>
                    `)
                    .style('left', (event.pageX + 10) + 'px')
                    .style('top', (event.pageY - 10) + 'px');
            })
            .on('mouseout', function() {
                tooltip.style('opacity', 0);
            });
    }

    function renderModelChart(data) {
        const svg = d3.select('#modelChart');
        const container = svg.node().parentElement;
        const width = container.clientWidth;
        const height = 350;

        svg.attr('viewBox', `0 0 ${width} ${height}`);
        svg.selectAll('*').remove();

        const radius = Math.min(width, height) / 2 - 40;
        const g = svg.append('g')
            .attr('transform', `translate(${width/2},${height/2})`);

        const color = d3.scaleOrdinal()
            .range(['#667eea', '#764ba2', '#f093fb', '#f5576c', '#11998e', '#38ef7d', '#4facfe', '#00f2fe']);

        const pie = d3.pie()
            .value(d => d.value)
            .sort(null);

        const arc = d3.arc()
            .innerRadius(radius * 0.6)
            .outerRadius(radius);

        const data_array = Object.entries(data.modelCounts).map(([key, value]) => ({
            name: key,
            value: value
        }));

        const tooltip = d3.select('#d3-tooltip');

        g.selectAll('path')
            .data(pie(data_array))
            .enter().append('path')
            .attr('d', arc)
            .attr('fill', (d, i) => color(i))
            .attr('stroke', 'white')
            .attr('stroke-width', 2)
            .style('cursor', 'pointer')
            .on('mouseover', function(event, d) {
                d3.select(this).transition()
                    .duration(200)
                    .attr('d', d3.arc().innerRadius(radius * 0.6).outerRadius(radius * 1.1));

                const percent = ((d.data.value / data.totalCalls) * 100).toFixed(1);
                tooltip.style('opacity', 1)
                    .html(`
                        <strong>${d.data.name}</strong><br>
                        Calls: ${d.data.value}<br>
                        ${percent}% of total
                    `)
                    .style('left', (event.pageX + 10) + 'px')
                    .style('top', (event.pageY - 10) + 'px');
            })
            .on('mouseout', function() {
                d3.select(this).transition()
                    .duration(200)
                    .attr('d', arc);
                tooltip.style('opacity', 0);
            });

        // Center text
        g.append('text')
            .attr('text-anchor', 'middle')
            .attr('dy', '-0.5em')
            .style('font-size', '2em')
            .style('font-weight', 'bold')
            .text(data.totalCalls);

        g.append('text')
            .attr('text-anchor', 'middle')
            .attr('dy', '1.5em')
            .style('font-size', '0.9em')
            .style('fill', '#666')
            .text('total calls');

        // Add legend
        const legend = svg.append('g')
            .attr('transform', `translate(10, ${height - 80})`);

        data_array.slice(0, 5).forEach((d, i) => {
            const legendRow = legend.append('g')
                .attr('transform', `translate(0, ${i * 16})`);

            legendRow.append('rect')
                .attr('width', 12)
                .attr('height', 12)
                .attr('fill', color(i));

            legendRow.append('text')
                .attr('x', 18)
                .attr('y', 10)
                .style('font-size', '11px')
                .text(d.name.length > 20 ? d.name.substring(0, 18) + '...' : d.name);
        });
    }

    function renderCostChart(data) {
        const svg = d3.select('#costChart');
        const container = svg.node().parentElement;
        const width = container.clientWidth;
        const height = 300;
        const margin = { top: 20, right: 100, bottom: 50, left: 60 };

        svg.attr('viewBox', `0 0 ${width} ${height}`);
        svg.selectAll('*').remove();

        const g = svg.append('g')
            .attr('transform', `translate(${margin.left},${margin.top})`);

        const innerWidth = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;

        // Group costs by day and model
        const dailyCosts = {};
        Object.entries(data.dailyData).slice(-7).forEach(([day, dayData]) => {
            dailyCosts[day] = {};
        });

        // Aggregate by model (simplified for visualization)
        const topModels = Object.entries(data.modelCosts)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5)
            .map(([model]) => model);

        const modelData = topModels.map(model => ({
            model: model,
            cost: data.modelCosts[model]
        }));

        const x = d3.scaleBand()
            .domain(modelData.map(d => d.model))
            .range([0, innerWidth])
            .padding(0.3);

        const y = d3.scaleLinear()
            .domain([0, d3.max(modelData, d => d.cost)])
            .nice()
            .range([innerHeight, 0]);

        const color = d3.scaleOrdinal()
            .domain(topModels)
            .range(['#667eea', '#11998e', '#f5576c', '#4facfe', '#f093fb']);

        // Add axes with better formatting
        g.append('g')
            .attr('transform', `translate(0,${innerHeight})`)
            .call(d3.axisBottom(x))
            .selectAll('text')
            .attr('transform', 'rotate(-35)')
            .style('text-anchor', 'end')
            .style('font-size', '11px')
            .attr('dx', '-0.5em')
            .attr('dy', '0.5em');

        g.append('g')
            .call(d3.axisLeft(y).tickFormat(d => {
                if (d >= 1) return '$' + d.toFixed(2);
                if (d >= 0.01) return '$' + d.toFixed(4);
                return '$' + d.toExponential(2);
            }));

        // Add bars
        const tooltip = d3.select('#d3-tooltip');

        g.selectAll('.bar')
            .data(modelData)
            .enter().append('rect')
            .attr('class', 'bar')
            .attr('x', d => x(d.model))
            .attr('y', d => y(d.cost))
            .attr('width', x.bandwidth())
            .attr('height', d => innerHeight - y(d.cost))
            .attr('fill', d => color(d.model))
            .style('cursor', 'pointer')
            .on('mouseover', function(event, d) {
                d3.select(this).attr('opacity', 0.7);
                tooltip.style('opacity', 1)
                    .html(`
                        <strong>${d.model}</strong><br>
                        Total Cost: $${d.cost.toFixed(4)}
                    `)
                    .style('left', (event.pageX + 10) + 'px')
                    .style('top', (event.pageY - 10) + 'px');
            })
            .on('mouseout', function() {
                d3.select(this).attr('opacity', 1);
                tooltip.style('opacity', 0);
            });
    }

    function renderHeatmap(data) {
        const svg = d3.select('#heatmapChart');
        const container = svg.node().parentElement;
        const width = container.clientWidth;
        const height = 300;
        const margin = { top: 20, right: 20, bottom: 40, left: 60 };

        svg.attr('viewBox', `0 0 ${width} ${height}`);
        svg.selectAll('*').remove();

        const g = svg.append('g')
            .attr('transform', `translate(${margin.left},${margin.top})`);

        const innerWidth = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;

        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const hours = Array.from({length: 24}, (_, i) => i);

        const cellWidth = innerWidth / 24;
        const cellHeight = innerHeight / 7;

        const maxValue = d3.max(data.heatmapData.flat());

        const colorScale = d3.scaleSequential()
            .domain([0, maxValue])
            .interpolator(d3.interpolateBlues);

        const tooltip = d3.select('#d3-tooltip');

        // Render cells
        data.heatmapData.forEach((dayData, dayIdx) => {
            dayData.forEach((value, hourIdx) => {
                g.append('rect')
                    .attr('x', hourIdx * cellWidth)
                    .attr('y', dayIdx * cellHeight)
                    .attr('width', cellWidth - 1)
                    .attr('height', cellHeight - 1)
                    .attr('fill', value > 0 ? colorScale(value) : '#f0f0f0')
                    .attr('stroke', 'white')
                    .style('cursor', 'pointer')
                    .on('mouseover', function(event) {
                        tooltip.style('opacity', 1)
                            .html(`
                                <strong>${days[dayIdx]} ${hourIdx}:00</strong><br>
                                Tokens: ${value.toLocaleString()}
                            `)
                            .style('left', (event.pageX + 10) + 'px')
                            .style('top', (event.pageY - 10) + 'px');
                    })
                    .on('mouseout', function() {
                        tooltip.style('opacity', 0);
                    });
            });
        });

        // Add day labels
        days.forEach((day, i) => {
            g.append('text')
                .attr('x', -10)
                .attr('y', i * cellHeight + cellHeight / 2)
                .attr('text-anchor', 'end')
                .attr('dominant-baseline', 'middle')
                .style('font-size', '0.8em')
                .text(day);
        });

        // Add hour labels (every 3 hours)
        for (let i = 0; i < 24; i += 3) {
            g.append('text')
                .attr('x', i * cellWidth + cellWidth / 2)
                .attr('y', innerHeight + 20)
                .attr('text-anchor', 'middle')
                .style('font-size', '0.8em')
                .text(i);
        }
    }

    function renderAgentFlow(data) {
        const svg = d3.select('#flowChart');
        const container = svg.node().parentElement;
        const width = container.clientWidth;
        const height = 300;

        svg.attr('viewBox', `0 0 ${width} ${height}`);
        svg.selectAll('*').remove();

        if (data.toolSequences.length === 0) {
            svg.append('text')
                .attr('x', width / 2)
                .attr('y', height / 2)
                .attr('text-anchor', 'middle')
                .style('fill', '#999')
                .text('No agent tool sequences found');
            return;
        }

        // Count tool transitions
        const transitions = {};
        data.toolSequences.forEach(sequence => {
            for (let i = 0; i < sequence.length - 1; i++) {
                const key = `${sequence[i]}→${sequence[i+1]}`;
                transitions[key] = (transitions[key] || 0) + 1;
            }
        });

        // Get unique tools
        const allTools = [...new Set(data.toolSequences.flat())];

        // Simple visualization: list top transitions
        const topTransitions = Object.entries(transitions)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 10);

        const g = svg.append('g')
            .attr('transform', 'translate(20,30)');

        topTransitions.forEach((transition, i) => {
            const [flow, count] = transition;

            g.append('text')
                .attr('x', 0)
                .attr('y', i * 25)
                .style('font-size', '0.85em')
                .text(`${flow} (${count}x)`);

            // Bar visualization
            const barWidth = (count / topTransitions[0][1]) * (width - 200);
            g.append('rect')
                .attr('x', 180)
                .attr('y', i * 25 - 12)
                .attr('width', barWidth)
                .attr('height', 15)
                .attr('fill', '#667eea')
                .attr('opacity', 0.6);
        });
    }

    // ============================================================
    // FANCY FEATURES
    // ============================================================

    // Animated number counter
    function animateNumber(selector, start, end, displayText, duration) {
        const element = $(selector);
        const startTime = Date.now();

        // If start is null/undefined, extract current value from element
        let startValue = start;
        if (startValue === null || startValue === undefined) {
            const currentText = element.text().replace(/[^0-9.-]/g, '');
            startValue = parseFloat(currentText) || 0;

            // Adjust for K/M suffixes
            if (element.text().includes('K')) startValue *= 1000;
            if (element.text().includes('M')) startValue *= 1000000;
        }

        const endValue = parseFloat(end) || 0;

        function update() {
            const now = Date.now();
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);

            // Easing function (ease out cubic)
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = startValue + (endValue - startValue) * eased;

            if (progress < 1) {
                // Show intermediate value
                if (displayText.includes('K')) {
                    element.text((current / 1000).toFixed(1) + 'K');
                } else if (displayText.includes('M')) {
                    element.text((current / 1000000).toFixed(2) + 'M');
                } else if (displayText.includes('$')) {
                    element.text('$' + current.toFixed(displayText.includes('.') ? 4 : 2));
                } else if (displayText.includes('%')) {
                    element.text(current.toFixed(1) + '%');
                } else {
                    element.text(Math.floor(current).toLocaleString());
                }
                requestAnimationFrame(update);
            } else {
                // Show final value
                element.text(displayText);
            }
        }

        update();
    }

    // Download chart as PNG
    function downloadChart(chartId) {
        const svg = document.getElementById(chartId);
        if (!svg) return;

        const svgData = new XMLSerializer().serializeToString(svg);
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        const img = new Image();
        const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(svgBlob);

        img.onload = function() {
            canvas.width = svg.clientWidth * 2; // 2x for retina
            canvas.height = svg.clientHeight * 2;
            ctx.fillStyle = 'white';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            canvas.toBlob(function(blob) {
                const a = document.createElement('a');
                a.download = chartId + '-' + new Date().toISOString().slice(0,10) + '.png';
                a.href = URL.createObjectURL(blob);
                a.click();
            });

            URL.revokeObjectURL(url);
        };

        img.src = url;
    }

    // Toggle fullscreen for chart
    function toggleFullscreen(button) {
        const container = button.closest('.chart-container');

        if (!document.fullscreenElement) {
            container.requestFullscreen().catch(err => {
                console.error('Fullscreen error:', err);
            });
        } else {
            document.exitFullscreen();
        }
    }

    // Add pulse animation to new data
    function pulseElement(selector) {
        $(selector).css({
            animation: 'none'
        }).offset(); // Force reflow
        $(selector).css({
            animation: 'pulse 0.5s ease-in-out'
        });
    }


    // AJAX data refresh function (SILKY SMOOTH - NO PAGE RELOAD!)
    function refreshDataAjax() {
        // Pulse the live indicator
        $('#liveIndicator .live-dot').css('animation', 'none').offset();
        $('#liveIndicator .live-dot').css('animation', 'pulse-dot 0.5s ease-in-out');

        // Build URL with current filters
        const params = new URLSearchParams(window.location.search);
        params.set('format', 'json');
        const url = window.location.pathname + '?' + params.toString();

        // Fetch new data
        fetch(url)
            .then(response => response.json())
            .then(data => {
                console.log('Refreshed data:', data);

                // Update logsData globally
                window.logsData = data.logs;

                // Reprocess analytics
                const processedData = processLogsData(data.logs);

                // Update summary cards smoothly
                updateSummaryCardsSmooth(processedData);

                // Update info banner
                $('#totalLogsCount').text(data.logs.length.toLocaleString());

                // Update charts smoothly
                updateChartsSmooth(processedData);

                // Show success toast briefly
                showToast('✨ Updated', 'success');
            })
            .catch(error => {
                console.error('Refresh error:', error);
                showToast('⚠️ Update failed', 'error');
            });
    }

    // Update summary cards with smooth transitions
    function updateSummaryCardsSmooth(data) {
        const tokensDisplay = data.totalTokens >= 1000000
            ? (data.totalTokens / 1000000).toFixed(2) + 'M'
            : (data.totalTokens / 1000).toFixed(1) + 'K';

        const costDisplay = data.totalCost >= 1
            ? '$' + data.totalCost.toFixed(2)
            : '$' + data.totalCost.toFixed(4);

        // Animate from current values (not 0!)
        animateNumber('#totalTokens', null, data.totalTokens, tokensDisplay, 600);
        animateNumber('#totalCost', null, data.totalCost, costDisplay, 600);
        animateNumber('#totalCalls', null, data.totalCalls, data.totalCalls.toLocaleString(), 600);
        animateNumber('#agentPercent', null, parseFloat(data.agentPercent), data.agentPercent + '%', 600);

        // Update trend text (with smooth fade)
        const trendArrow = (trend) => {
            const val = parseFloat(trend);
            if (val > 0) return `<span class="trend-arrow trend-up">↗ +${Math.abs(val)}%</span>`;
            if (val < 0) return `<span class="trend-arrow trend-down">↘ ${val}%</span>`;
            return '<span class="trend-arrow">→ 0%</span>';
        };

        $('#tokenTrend').fadeOut(200, function() {
            $(this).html('All time ' + trendArrow(data.tokenTrend)).fadeIn(200);
        });
        $('#costTrend').fadeOut(200, function() {
            $(this).html(Object.keys(data.modelCounts).length + ' models ' + trendArrow(data.costTrend)).fadeIn(200);
        });
        $('#callsTrend').fadeOut(200, function() {
            $(this).html(data.agentCalls + ' agent ' + trendArrow(data.callsTrend)).fadeIn(200);
        });
        $('#agentTrend').fadeOut(200, function() {
            $(this).html('of total ' + trendArrow(data.agentTrend)).fadeIn(200);
        });

        // Update sparklines
        renderSparkline('#tokenSparkline', data.hourlyData, 'total');
        renderSparkline('#costSparkline', data.hourlyData, 'cost');
        renderSparkline('#callsSparkline', data.hourlyData, 'calls');
        renderSparkline('#agentSparkline', data.hourlyData, 'calls');
    }

    // Update charts smoothly (re-render with transition)
    function updateChartsSmooth(data) {
        // Fade charts slightly during update
        $('.chart-container svg').css('opacity', '0.7');

        setTimeout(() => {
            renderTokensChart(data, currentTimeRange); // Preserve selected time range
            renderModelChart(data);
            renderCostChart(data);
            renderHeatmap(data);
            renderAgentFlow(data);

            $('.chart-container svg').css('opacity', '1');
        }, 200);
    }

    // Toast notification system
    function showToast(message, type = 'success') {
        const bgColor = type === 'success' ? 'rgba(40, 167, 69, 0.95)' : 'rgba(220, 53, 69, 0.95)';
        const toast = $('<div class="refresh-toast"></div>')
            .css('background', bgColor)
            .html(message)
            .appendTo('body');

        toast.fadeIn(200);

        setTimeout(() => {
            toast.fadeOut(300, function() {
                $(this).remove();
            });
        }, 2000);
    }

    // Smooth number updates (call with null start to animate from current value)
    function updateNumberSmooth(selector, newValue, displayText) {
        animateNumber(selector, null, newValue, displayText, 1000);
    }

</script>
</body>
</html>
