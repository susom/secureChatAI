<?php
/**
 * AJAX endpoint for ProjectUsage.php
 * NOT listed in config.json links, so REDCap does not wrap it in header/footer.
 * Accessed via $module->getUrl('pages/ProjectUsageAjax.php')
 */

namespace Stanford\SecureChatAI;
/** @var \Stanford\SecureChatAI\SecureChatAI $module */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$current_pid = $_GET['pid'] ?? null;
if (!$current_pid) {
    echo json_encode(['error' => 'Missing project ID']);
    exit;
}

// Session detail endpoint
$sessionDetailId = isset($_GET['session_id']) ? $_GET['session_id'] : null;
if ($sessionDetailId) {
    $session = $module->rehydrateProjectSession($sessionDetailId, intval($current_pid));
    echo json_encode($session);
    exit;
}

// JSON data endpoint (for auto-refresh)
$dateStart = isset($_GET['dateStart']) ? $_GET['dateStart'] : null;
$dateEnd = isset($_GET['dateEnd']) ? $_GET['dateEnd'] : null;
$limit = isset($_GET['limit']) ? min(max(intval($_GET['limit']), 10), 1000) : 500;

$a = $module->getProjectSecureChatLogs($current_pid, 0);
$allLogs = [];
foreach ($a as $action) {
    if ($dateStart && $dateEnd && !empty($action['timestamp'])) {
        $logDate = date('Y-m-d', strtotime($action['timestamp']));
        if ($logDate < $dateStart || $logDate > $dateEnd) continue;
    }
    $allLogs[] = $action;
}

echo json_encode([
    'logs' => $allLogs,
    'limit' => $limit,
    'total' => count($allLogs),
    'dateStart' => $dateStart,
    'dateEnd' => $dateEnd,
    'timestamp' => time()
]);
