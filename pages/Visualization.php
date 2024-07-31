<?php

namespace Stanford\SecureChatAI;
/** @var \Stanford\SecureChatAI\SecureChatAI $module */

function createTable($action) {
    $rows = '';

    // Extracting required fields from $action
    $id = $action['id'] ?? 'N/A';
    $timestamp = $action['timestamp'] ?? 'N/A';
    $completionTokens = $action['usage']['completion_tokens'] ?? 'N/A';
    $promptTokens = $action['usage']['prompt_tokens'] ?? 'N/A';
    $totalTokens = $action['usage']['total_tokens'] ?? 'N/A';
    $model = $action['model'] ?? 'N/A';
    $project_id = $action ['project_id'] ?? 'N/A';
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
$rows = '';
foreach ($a as $k => $v) {
    $action = $v->getLog();
    $rows .= createTable($action);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Chat AI Logs</title>
<!--    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.21/css/jquery.dataTables.min.css">-->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.21/js/dataTables.bootstrap4.min.js"></script>
    <style>
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0;
        }
        .dataTables_wrapper {
            font-size: 0.8em; /* Small font size */
        }
        table.dataTable tbody tr {
            height: 24px; /* Compact row height */
        }
    </style>
</head>
<body>

<div class="container mt-4">
    <table class="table table-striped table-bordered" id="logTable">
        <thead class="thead-dark">
        <tr>
            <th>ID</th>
            <th>Project ID</th>
            <th>Timestamp</th>
            <th>Completion Tokens</th>
            <th>Prompt Tokens</th>
            <th>Total Tokens</th>
            <th>Model</th>
        </tr>
        </thead>
        <tbody>
        <?php echo $rows; ?>
        </tbody>
    </table>
</div>

<script>
    $(document).ready(function() {
        $('#logTable').DataTable({
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "pageLength": 10,
            "lengthMenu": [10, 25, 50, 75, 100]
        });
    });
</script>

</body>
</html>
