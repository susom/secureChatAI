<?php

namespace Stanford\SecureChatAI;
/** @var \Stanford\SecureChatAI\SecureChatAI $module */

require_once __DIR__ . '/includes/usage_helpers.php';

$current_pid = $_GET['pid'] ?? null;

// AJAX URL for session detail and data refresh (separate endpoint without REDCap chrome)
$ajaxUrl = $module->getUrl('pages/ProjectUsageAjax.php');

// Gate check: is project usage enabled?
$usageEnabled = $module->getProjectSetting('enable-project-usage');
if (!$usageEnabled) {
    ?>
    <div class="container mt-5">
        <div class="alert alert-warning">
            <h4>AI Usage Dashboard</h4>
            <p>The project-level usage dashboard is not enabled for this project.</p>
            <p>An administrator can enable it in the External Module settings for this project.</p>
        </div>
    </div>
    <?php
    return;
}

// Get limit from query param or default to 500
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
$limit = min(max($limit, 10), 1000);

// Get date range filters
$dateStart = isset($_GET['dateStart']) ? $_GET['dateStart'] : null;
$dateEnd = isset($_GET['dateEnd']) ? $_GET['dateEnd'] : null;

$offset = 0;
$a = $module->getProjectSecureChatLogs($current_pid, $offset);

// Collect unique values for filters
$uniqueModels = [];
$uniqueTypes = [];
$uniqueSessionIds = [];

$allLogs = [];

foreach ($a as $index => $action) {

    // Apply date filtering if specified
    if ($dateStart && $dateEnd && !empty($action['timestamp'])) {
        $logDate = date('Y-m-d', strtotime($action['timestamp']));
        if ($logDate < $dateStart || $logDate > $dateEnd) {
            continue;
        }
    }

    $allLogs[] = $action;

    if (!empty($action['model'])) $uniqueModels[$action['model']] = true;
    if (!empty($action['record'])) $uniqueTypes[$action['record']] = true;
    if (!empty($action['session_id'])) $uniqueSessionIds[$action['session_id']] = true;
}

// Create table rows
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

// Convert to sorted arrays for dropdowns
$uniqueModels = array_keys($uniqueModels);
$uniqueTypes = array_keys($uniqueTypes);
$uniqueSessionIds = array_keys($uniqueSessionIds);
sort($uniqueModels);
sort($uniqueTypes);
sort($uniqueSessionIds);

// Pass data to JavaScript for analytics
$logsJson = json_encode($allLogs);

$safePid = htmlspecialchars($current_pid, ENT_QUOTES, 'UTF-8');
?>

<!-- REDCap provides jQuery and Bootstrap via show-header-and-footer -->
<!-- Only load DataTables and D3 which REDCap does not include -->
<link rel="stylesheet" href="https://cdn.datatables.net/2.1.3/css/dataTables.dataTables.css" />
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.0.0/css/buttons.dataTables.css" />
<script src="https://cdn.datatables.net/2.1.3/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.0/js/dataTables.buttons.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.0/js/buttons.html5.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://d3js.org/d3.v7.min.js"></script>

<?php include __DIR__ . '/includes/usage_styles.php'; ?>

<style>
    /* Constrain to REDCap project chrome content area */
    .project-usage-wrapper { max-width: 100%; overflow-x: hidden; }
    .project-usage-wrapper .table { font-size: 0.8rem; }
    .project-usage-wrapper .query-column,
    .project-usage-wrapper .response-column,
    .project-usage-wrapper .tools-column { width: 200px; max-width: 200px; }
</style>
<div class="project-usage-wrapper mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>AI Usage Dashboard - Project <?= $safePid ?></h2>
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
                <strong>📊 Project Usage</strong> - Showing data for project <?= $safePid ?> from <span id="dataDateRange">all available logs</span>
                (<span id="totalLogsCount">-</span> total records)
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

    <!-- Filters Panel (no project filter - already scoped) -->
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
                <label class="form-label">Type</label>
                <select class="form-select form-select-sm" id="typeFilter">
                    <option value="">All Types</option>
                    <?php foreach ($uniqueTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Session ID</label>
                <select class="form-select form-select-sm" id="sessionFilter">
                    <option value="">All Sessions</option>
                    <?php foreach ($uniqueSessionIds as $session): ?>
                        <option value="<?= htmlspecialchars($session, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($session, ENT_QUOTES, 'UTF-8') ?></option>
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

    <!-- Session Modal -->
    <div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sessionModalLabel">Session Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="sessionContent">
                    <!-- Session content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                    <th>Session ID</th>
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

    <!-- Phase 2/3 Stub Cards -->
    <div class="row mt-4 mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body text-center text-muted">
                    <h5 class="card-title">API Configuration</h5>
                    <p class="card-text">Project API key management and endpoint configuration.</p>
                    <span class="badge bg-secondary">Coming Soon</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body text-center text-muted">
                    <h5 class="card-title">Usage Limits</h5>
                    <p class="card-text">Monthly token and cost limits with alerts.</p>
                    <span class="badge bg-secondary">Coming Soon</span>
                </div>
            </div>
        </div>
    </div>

</div><!-- End Wrapper -->

<!-- D3 Tooltip -->
<div class="tooltip-d3" id="d3-tooltip"></div>

<script>
    // Pass PHP data to JavaScript
    const logsData = <?php echo $logsJson; ?>;
    window.logsData = logsData;
    // AJAX endpoint (separate page without REDCap header/footer)
    window.USAGE_AJAX_URL = '<?= $ajaxUrl ?>';
</script>
<script src="<?= $module->getUrl('pages/includes/usage_analytics.js') ?>"></script>
<script src="<?= $module->getUrl('pages/includes/usage_table.js') ?>"></script>
