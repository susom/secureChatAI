<?php

namespace Stanford\SecureChatAI;
/** @var \Stanford\SecureChatAI\SecureChatAI $module */

function createTable($action){
    $rows = '';

    // Extracting required fields from $action
    $id = $action['id'] ?? 'N/A';
    $timestamp = $action['timestamp'] ?? 'N/A';
    $completionTokens = $action['usage']['completion_tokens'] ?? 'N/A';
    $promptTokens = $action['usage']['prompt_tokens'] ?? 'N/A';
    $totalTokens = $action['usage']['total_tokens'] ?? 'N/A';
    $model = $action['model'] ?? 'N/A';
    $project_id = $action['project_id'];
    // Creating the row for the fields
    $rows .= "<tr>
                    <td>{$id}</td>
                    <td>{$project_id}</td>
                    <td>{$timestamp}</td>
                    <td>{$completionTokens}</td>
                    <td>{$promptTokens}</td>
                    <td>{$totalTokens}</td>
                    <td>{$model}</td>
                  </tr>";

    return $rows;
}

$a = $module->getSecureChatLogs();
echo '<table class="table table-striped table-bordered">';
echo '<thead class="thead-dark">';
echo '<tr><th>ID</th><th>Project ID</th><th>Timestamp</th><th>Completion Tokens</th><th>Prompt Tokens</th><th>Total Tokens</th><th>Model</th></tr>';
echo '</thead><tbody>';
foreach($a as $k => $v){
    $action = $v->getLog();
    echo createTable($action);
//    echo $k+1 . "..... Model: " . $action['model'].  "..... Total tokens: " . $action['usage']['total_tokens'] . "\n";
}
echo '</tbody></table>';



?>

