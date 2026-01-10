<?php
namespace Stanford\SecureChatAI;

/** @var \Stanford\SecureChatAI\SecureChatAI $module */
$module = new SecureChatAI();
$reflect = new \ReflectionClass($module);

// Init model config manually (avoids PID check)
$init = $reflect->getMethod('initSecureChatAI');
$init->setAccessible(true);
$init->invoke($module);

$configProp = $reflect->getProperty('modelConfig');
$configProp->setAccessible(true);
$modelConfig = $configProp->getValue($module);

// Model type detection
function detectModelType($alias) {
    if (stripos($alias, 'whisper') !== false) return 'stt';
    if (stripos($alias, 'tts') !== false) return 'tts';
    if (stripos($alias, 'ada') !== false || stripos($alias, 'embed') !== false) return 'embedding';
    return 'chat';
}

// Run test for a single model
function testModel($module, $alias, $config) {
    $type = detectModelType($alias);
    $startTime = microtime(true);

    $result = [
        'alias' => $alias,
        'type' => $type,
        'status' => 'fail',
        'output' => '',
        'timing' => 0,
        'tokens' => null,
        'error' => null
    ];

    try {
        $params = [];

        // Build appropriate test payload per model type
        switch ($type) {
            case 'stt': // Whisper
                $filePath = dirname(__FILE__, 2) . '/for_test.mp3';
                if (!file_exists($filePath)) {
                    throw new \Exception("Missing for_test.mp3 in module root");
                }
                $params = [
                    'file' => $filePath,
                    'language' => 'en',
                    'response_format' => 'json'
                ];
                break;

            case 'tts':
                $params = ['input' => "Testing TTS from $alias"];
                break;

            case 'embedding':
                $params = ['input' => "Testing embedding generation from $alias"];
                break;

            case 'chat':
            default:
                $params['messages'] = [
                    ["role" => "user", "content" => "Reply with exactly: 'Test successful for $alias'"]
                ];
                break;
        }

        $response = $module->callAI($alias, $params);
        $result['timing'] = round((microtime(true) - $startTime) * 1000, 0); // ms

        // Validate response per type
        switch ($type) {
            case 'embedding':
                // Check for embedding vector
                if (isset($response['data'][0]['embedding']) && is_array($response['data'][0]['embedding'])) {
                    $vectorLength = count($response['data'][0]['embedding']);
                    $result['status'] = 'success';
                    $result['output'] = "✓ Generated {$vectorLength}-dimensional embedding vector";
                    $result['tokens'] = $response['usage'] ?? null;
                } else {
                    $result['status'] = 'fail';
                    $result['error'] = 'No embedding vector in response';
                    $result['output'] = json_encode($response, JSON_PRETTY_PRINT);
                }
                break;

            case 'tts':
                // Check for audio
                $outputArr = is_string($response) ? json_decode($response, true) : $response;
                if (is_array($outputArr) && !empty($outputArr['audio_base64'])) {
                    $result['status'] = 'success';
                    $result['output'] = '<audio controls src="data:audio/mpeg;base64,' .
                                       htmlspecialchars($outputArr['audio_base64']) .
                                       '" style="max-width:300px;"></audio>';
                } else {
                    $result['status'] = 'fail';
                    $result['error'] = 'No audio_base64 in response';
                    $result['output'] = json_encode($response, JSON_PRETTY_PRINT);
                }
                break;

            case 'stt':
                // Check for transcription text
                $text = $module->extractResponseText($response);
                if (!empty($text) && is_string($text) && strlen($text) > 5) {
                    $result['status'] = 'success';
                    $result['output'] = htmlspecialchars($text);
                } else {
                    $result['status'] = 'fail';
                    $result['error'] = 'Empty or invalid transcription';
                    $result['output'] = json_encode($response, JSON_PRETTY_PRINT);
                }
                break;

            case 'chat':
            default:
                // Check for text content
                $hasError = isset($response['error']) && $response['error'];
                $text = $module->extractResponseText($response);
                $tokens = $module->extractUsageTokens($response);

                // Detect sanitized error messages (often polite apologies)
                $errorKeywords = ['unsupported', 'invalid', 'not supported', 'missing', 'failed', 'unavailable',
                                  'I apologize', 'technical difficulties', 'network difficulties'];
                $hasErrorKeyword = false;
                if (is_string($text)) {
                    foreach ($errorKeywords as $kw) {
                        if (stripos($text, $kw) !== false) {
                            $hasErrorKeyword = true;
                            break;
                        }
                    }
                }

                // Zero tokens = likely an error (no actual API call succeeded)
                $zeroTokens = ($tokens['total_tokens'] ?? 0) === 0;

                if (!$hasError && !empty($text) && !$hasErrorKeyword && !$zeroTokens) {
                    $result['status'] = 'success';
                    $result['output'] = htmlspecialchars($text);
                    $result['tokens'] = $tokens;
                } else {
                    $result['status'] = 'fail';
                    if ($zeroTokens) {
                        $result['error'] = 'Zero tokens returned (likely network/API error)';
                    } elseif ($hasError) {
                        $result['error'] = 'API returned error';
                    } else {
                        $result['error'] = 'Invalid response content';
                    }
                    $result['output'] = htmlspecialchars($text);
                    $result['tokens'] = $tokens;
                }
                break;
        }

    } catch (\Exception $e) {
        $result['status'] = 'fail';
        $result['error'] = $e->getMessage();
        $result['output'] = '';
        $result['timing'] = round((microtime(true) - $startTime) * 1000, 0);
    }

    return $result;
}

// Run all tests
$results = [];
foreach ($modelConfig as $alias => $config) {
    // Skip empty/invalid aliases
    if (empty(trim($alias))) {
        $module->emDebug("Skipping empty model alias", $config);
        continue;
    }
    $results[] = testModel($module, $alias, $config);
}

// Group by type
$grouped = [
    'chat' => [],
    'embedding' => [],
    'tts' => [],
    'stt' => []
];

foreach ($results as $r) {
    $grouped[$r['type']][] = $r;
}

$totalTests = count($results);
$passedTests = count(array_filter($results, fn($r) => $r['status'] === 'success'));

// Export settings
$settings = $module->framework->getSubSettings('api-settings');
$settings_json = json_encode($settings, JSON_PRETTY_PRINT);
$filename = 'securechatai_model_settings_' . date('Ymd_His') . '.json';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SecureChatAI Model Tests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .status-badge { font-size: 1.2em; }
        .success { color: #28a745; }
        .fail { color: #dc3545; }
        .model-output {
            max-height: 150px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.85rem;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
        }
        .timing-badge {
            font-size: 0.75rem;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .token-info {
            font-size: 0.75rem;
            color: #6c757d;
        }
        .section-header {
            background: #f8f9fa;
            padding: 12px;
            margin: 20px 0 10px 0;
            border-left: 4px solid #007bff;
            font-weight: bold;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="container mt-4 mb-5">
    <h2 class="mb-3">SecureChatAI Model Tests</h2>

    <div class="summary-card">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h4 class="mb-2">Test Summary</h4>
                <p class="mb-0">
                    <strong><?= $passedTests ?> / <?= $totalTests ?></strong> models passed
                    <?php if ($passedTests === $totalTests): ?>
                        🎉 All systems operational!
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-4 text-end">
                <button onclick="window.location.reload()" class="btn btn-light">
                    ↻ Rerun Tests
                </button>
            </div>
        </div>
    </div>

    <?php foreach (['chat', 'embedding', 'tts', 'stt'] as $type): ?>
        <?php if (!empty($grouped[$type])): ?>
            <div class="section-header">
                <?= strtoupper($type) ?> Models (<?= count($grouped[$type]) ?>)
            </div>

            <table class="table table-bordered table-hover mb-4">
                <thead class="table-light">
                    <tr>
                        <th style="width: 20%;">Model</th>
                        <th style="width: 10%;" class="text-center">Status</th>
                        <th style="width: 10%;" class="text-center">Timing</th>
                        <th style="width: 15%;">Tokens</th>
                        <th style="width: 45%;">Output / Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped[$type] as $r): ?>
                        <tr>
                            <td>
                                <strong><?= !empty(trim($r['alias'])) ? htmlspecialchars($r['alias']) : '<em class="text-muted">(empty alias)</em>' ?></strong>
                                <?php if ($r['error']): ?>
                                    <br><small class="text-danger"><?= htmlspecialchars($r['error']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="status-badge <?= $r['status'] ?>">
                                    <?= $r['status'] === 'success' ? '✅' : '❌' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="timing-badge"><?= $r['timing'] ?> ms</span>
                            </td>
                            <td>
                                <?php if ($r['tokens']): ?>
                                    <div class="token-info">
                                        <?php if (isset($r['tokens']['prompt_tokens'])): ?>
                                            In: <?= $r['tokens']['prompt_tokens'] ?><br>
                                        <?php endif; ?>
                                        <?php if (isset($r['tokens']['completion_tokens'])): ?>
                                            Out: <?= $r['tokens']['completion_tokens'] ?><br>
                                        <?php endif; ?>
                                        <?php if (isset($r['tokens']['total_tokens'])): ?>
                                            <strong>Total: <?= $r['tokens']['total_tokens'] ?></strong>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="model-output">
                                    <?= $r['output'] ?: '<em class="text-muted">No output</em>' ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>

    <hr class="my-4">

    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Export Settings</h5>
            <p class="card-text">
                Download current model configuration (includes API keys and tokens).
            </p>
            <a href="data:application/json;charset=utf-8,<?= urlencode($settings_json) ?>"
               download="<?= $filename ?>"
               class="btn btn-primary">
                📥 Download Model Settings JSON
            </a>
            <div class="text-muted small mt-2">
                <strong>Warning:</strong> This export contains sensitive credentials. Handle securely.
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
