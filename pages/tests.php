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

$skip = [];//['gpt-4o', 'ada-002', 'whisper', 'llama3370b', 'gpt-4.1', 'claude', 'gemini20flash', 'o1', 'o3-mini', 'llama-Maverick'];

$rows = '';
foreach ($modelConfig as $alias => $config) {
    if (in_array($alias, $skip)) continue;

    $status = "❌ Failed";
    $statusClass = "fail";
    $statusIcon = "❌";
    $output = '';
    $inputVar = $config['api-input-var'] ?? 'messages';
    $params = [];

    $module->emDebug("so lets see", $alias, $config);
    try {
        if ($alias === 'whisper') {
            $filePath = dirname(__FILE__, 2) . '/for_test.mp3';
            if (!file_exists($filePath)) throw new \Exception("Missing for_test.mp3 at root.");
            $params = [
                'file' => $filePath,
                'language' => 'en',
                'response_format' => 'json'
            ];
        } elseif ($alias === 'ada-002') {
            $params['input'] = "Say hi from $alias";
        } elseif (stripos($alias, 'gemini') !== false) {
            $params['messages'] = [
                ["role" => "user", "content" => "Say hi from $alias"]
            ];
        } else {
            // Generic chat models
            $params[$inputVar] = [
                ["role" => "user", "content" => "Say hi from $alias"]
            ];
        }

        $response = $module->callAI($alias, $params);

        // Check for API-level error in response
        $output = $module->extractResponseText($response);

        // API-level error
        $isError = isset($response['error']) && $response['error'];

        // Output-based error (common error phrases or empty output)
        $errorIndicators = [
            'unsupported', 'error', 'invalid', 'not supported', 'missing', 'failed', 'unavailable'
        ];
        $outputError = (empty($output) ||
            (is_string($output) && preg_match('/(' . implode('|', $errorIndicators) . ')/i', $output))
        );

        // Final status logic
        if ($isError || $outputError) {
            $status = "❌ Failed";
            $statusIcon = "❌";
            $statusClass = "fail";
        } else {
            $status = "✅ Success";
            $statusIcon = "✅";
            $statusClass = "success";
        }

    } catch (\Exception $e) {
        $output = htmlentities($e->getMessage());
    }

    $rows .= "<tr>
        <td>$alias</td>
        <td class=\"$statusClass\">$status</td>
        <td><pre>" . htmlentities($output) . "</pre></td>
      </tr>";
}


// Fetch all model subsettings from REDCap EM framework
$settings = $module->framework->getSubSettings('api-settings');

// For download link
$settings_json = json_encode($settings, JSON_PRETTY_PRINT);

// Write to temp file for download (securely, one-time-use temp file)
$tempFile = tempnam(sys_get_temp_dir(), 'securechatai_') . '.json';
file_put_contents($tempFile, $settings_json);
$filename = 'securechatai_model_settings_' . date('Ymd_His') . '.json';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SecureChatAI Model Test Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h2>SecureChatAI Model Sanity Tests</h2>
    <table class="table table-bordered table-striped">
        <thead class="thead-dark">
            <tr>
                <th>Model</th>
                <th>Status</th>
                <th>Output / Error</th>
            </tr>
        </thead>
        <tbody>
            <?= $rows ?>
        </tbody>
    </table>

    <?php if (file_exists($tempFile)): ?>
        <div class="mt-3">
            <a href="data:application/json;charset=utf-8,<?= urlencode($settings_json) ?>"
            download="<?= $filename ?>"
            class="btn btn-primary">
                Download Current Model Settings (config.json)
            </a>
        </div>
        <div class="text-muted small mt-2">
            <strong>Heads up:</strong> This export includes API keys and all settings for every model in SecureChatAI.
        </div>
    <?php endif; ?>
</div>
</body>
</html>
