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

    $queryDump = print_r($action['messages'], true);
    $responseDump = str_replace('\\', '', $action['choices'][0]['message']['content']);

       // Unique IDs for each accordion based on row and column index
    $tokensId = "collapse-tokens-{$index}";
    $metaId = "collapse-meta-{$index}";
    $queryId = "collapse-query-{$index}";
    $responseId = "collapse-response-{$index}";

    return "<tr>
                <td class='id-column'>{$id}</td>
                <td class='project-id-column'>{$project_id}</td>
                <td>{$timestamp}</td>
                <td>{$model}</td>
                <td>
                    <div class='accordion' id='accordionTokens-{$index}'>
                        <div class='accordion-item'>
                            <h2 class='accordion-header' id='heading-tokens-{$index}'>
                                <button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#{$tokensId}' aria-expanded='false' aria-controls='{$tokensId}'>
                                    Total: {$totalTokens}
                                </button>
                            </h2>
                            <div id='{$tokensId}' class='accordion-collapse collapse' aria-labelledby='heading-tokens-{$index}' data-bs-parent='#accordionTokens-{$index}'>
                                <div class='accordion-body'>
                                    <div>Prompt Tokens: {$promptTokens}</div>
                                    <div>Completion Tokens: {$completionTokens}</div>
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
                                    Temp: {$action['temperature']}
                                </div>
                            </h2>
                            <div id='{$metaId}' class='accordion-collapse collapse' aria-labelledby='heading-meta-{$index}' data-bs-parent='#accordionMeta-{$index}'>
                                <div class='accordion-body'>
                                    <div><strong>Top P:</strong> {$action['top_p']}</div>
                                    <div><strong>Frequency Penalty:</strong> {$action['frequency_penalty']}</div>
                                    <div><strong>Presence Penalty:</strong> {$action['presence_penalty']}</div>
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
                                    <pre class='scrollable-content'><?= htmlspecialchars($queryDump) ?></pre>
                                <div>
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
            </tr>";
}
$offset = 0;
$a = $module->getSecureChatLogs($offset);
$rows = '';
foreach ($a as $index => $v) {
    $action = $v->getLog();
    $rows .= createTable($action, $index);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Chat AI Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/2.1.3/css/dataTables.dataTables.css" />
    <script src="https://cdn.datatables.net/2.1.3/js/dataTables.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <style>
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0;
        }
        html, body {
            font-size: 0.9rem; /* Small font size */
        }
        table.dataTable tbody tr {
            height: 24px; /* Compact row height */
        }
        .accordion-header {
            padding: 0.2rem 0.5rem;
        }
        .accordion-body {
            padding: 0.2rem 0.5rem;
        }
        .accordion-button {
            padding: 0.2rem 0.5rem;
            font-size: 0.8rem;
        }
        .table td, .table th {
            word-wrap: break-word;
        }

        /* Adjust ID and Project ID columns to 5% width */Giver
        .id-column, .project-id-column {
            width: 5%;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Set fixed widths for the Query and Response columns */
        .query-column, .response-column {
            width: 25%;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Adjust other columns to split the remaining width */
        .table th:not(.query-column):not(.response-column):not(.id-column):not(.project-id-column) {
            width: 9%;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .scrollable-content {
            max-height: 120px;  /* Set the maximum height */
            /*max-width: 20%;*/
            overflow-y: auto;   /* Enable vertical scrolling */
            white-space: pre-wrap; /* Preserve whitespace and line breaks */
        }
        /* Change cursor and background color on row hover */
        #logTable tbody tr:hover,
        #logTable tbody td:hover {
            background-color: #f2f2f2 !important; /* Light grey background on hover */
            cursor: pointer;
        }

        /* Ensure individual cells do not override the row hover effect */
        #logTable tbody td {
            background-color: inherit; /* Inherit background color from row hover */
        }
    </style>
</head>
<body>

<div class="container-fluid mt-4">
    <table class="table table-striped table-bordered" id="logTable">
        <thead class="thead-dark">
        <tr>
            <th>ID</th>
            <th>Project ID</th>
            <th>Timestamp</th>
            <th>Model</th>
            <th>Tokens</th>
            <th>Model Meta</th>
            <th>Query</th>
            <th>Response</th>
        </tr>
        </thead>
        <tbody>
        <?php echo $rows; ?>
        </tbody>
    </table>
</div>

<script>
    $(document).ready(function() {
        $.fn.dataTable.ext.order['tokens-sort'] = function(settings, col) {
            return this.api().column(col, { order: 'index' }).nodes().map(function(td, i) {
                // Extract the total tokens from the column data
                var totalTokens = $(td).find('.accordion-button').text().match(/Total: (\d+)/);
                return totalTokens ? parseInt(totalTokens[1], 10) : 0;
            });
        };

        $('#logTable').DataTable({
            "paging": true,
            "lengthChange": true,
            "searching": true,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "fixedColumns": true,
            "pageLength": 10,
            "lengthMenu": [10, 25, 50, 75, 100],
            "columnDefs": [
                {
                    "targets": 4,    // The "Tokens" column
                    "orderDataType": "tokens-sort"
                }
            ]
        });
        // Prevent accordion button click from triggering the row click event
        $('#logTable').on("click", ".accordion-button", function(event) {
            event.stopPropagation();
        });

        // Expand the accordion when a table row cell is clicked
        $('#logTable').on("click", "tbody td", function() {
            let accordionButtons = $(this).parent("tr").find('.accordion-button');

            // Iterate over each accordion button in the row
            accordionButtons.each(function() {
                let button = $(this);
                let target = button.attr('data-bs-target');
                let isCollapsed = button.hasClass('collapsed');
                // Toggle the collapsed class and accordion
                if (isCollapsed) {
                    button.removeClass('collapsed')
                    $(target).addClass('show').collapse('show');
                } else {
                    button.addClass('collapsed').attr('aria-expanded', false);
                    $(target).removeClass('show').collapse('hide');
                }
            });
        });
    });
</script>
</body>
</html>
