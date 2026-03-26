<?php

namespace Stanford\SecureChatAI;
require_once "ASEMLO.php";

/**
 * The Conversation State extends the Simple EM Log Object to provide a data store for all conversations
 *
 */
class SecureChatLog extends ASEMLO
{
    /** @var SecureChatAI $this ->module */


    /**
     * @param $module
     * @param $type
     * @param $log_id
     * @param $limit_params //used if you want to obtain a specific log_id and then only pull certain parameters
     * @throws \Exception
     */
    public function __construct($module, $log_id = null, $limit_params = [])
    {
        parent::__construct($module, $log_id, $limit_params);
    }

    public function getLog()
    {
        $message = $this->getValue('message');
        $decoded = json_decode($message, true);
        $decoded['timestamp'] = $this->getValue('timestamp');
        $decoded['id'] = $this->getId();
        $decoded['record'] = $this->getValue('record');

        return $decoded;
    }

    /** STATIC METHODS */

    /**
     * Load the active conversation after action_id
     * @param SecureChatAI $module
     * @param int $project_id
     * @param int $offset
     * @return array Action
     * @throws \Exception
     */
    public static function getLogs($module, $project_id, $offset)
    {

        $filter_clause = "project_id = ? order by log_id desc limit 1000 offset $offset";
        $objs = self::queryObjects(
            $module, $filter_clause, [$project_id]
        );

        $count = count($objs);
        if ($count > 0) {
            $module->emDebug("Loaded $count CS in need of action");
        }

        return $count === 0 ? [] : $objs;
    }


    public static function getAllLogs($module, $offset)
    {
        $filter_clause = "order by log_id desc limit 1000 offset $offset";
        $objs = self::queryObjects(
            $module, $filter_clause, []
        );

        $count = count($objs);
        return $count === 0 ? [] : $objs;
    }

    /**
     * Get logs by session ID
     * @param SecureChatAI $module
     * @param string $session_id
     * @param int|null $project_id Optional project filter
     * @return array
     * @throws \Exception
     */
    public static function getLogsBySession($module, $session_id, $project_id = null)
    {
        if (empty($session_id)) {
            return [];
        }

        // Fetch all needed columns in one query — avoids N+1 object instantiation per row
        $sql = "SELECT l.log_id, l.message, l.timestamp, l.record
                FROM redcap_external_modules_log l
                JOIN redcap_external_modules_log_parameters p
                    ON l.log_id = p.log_id AND p.name = 'session_id' AND p.value = ?
                WHERE l.record IN (?, ?)";

        $params = [$session_id, 'SecureChatLog', 'SecureChatLogError'];

        if ($project_id !== null) {
            $sql .= " AND l.project_id = ?";
            $params[] = $project_id;
        }

        $sql .= " ORDER BY l.log_id DESC";

        $module->emDebug("getLogsBySession SQL: $sql with params: " . json_encode($params));
        $result = $module->query($sql, $params);

        $results = [];
        while ($row = $result->fetch_assoc()) {
            $decoded = json_decode($row['message'], true) ?: [];
            $decoded['timestamp'] = $row['timestamp'];
            $decoded['id']        = $row['log_id'];
            $decoded['record']    = $row['record'];
            $results[] = $decoded;
        }

        // Fallback: SQL scan for older logs whose session_id lives only in the JSON message blob
        if (empty($results)) {
            $module->emDebug("Parameter lookup empty, falling back to SQL scan for session_id: $session_id");
            $like_pattern   = '%"session_id":"' . addslashes($session_id) . '"%';
            $fallback_sql   = "SELECT l.log_id, l.message, l.timestamp, l.record
                               FROM redcap_external_modules_log l
                               WHERE l.record IN (?, ?) AND l.message LIKE ?";
            $fallback_params = ['SecureChatLog', 'SecureChatLogError', $like_pattern];
            if ($project_id !== null) {
                $fallback_sql   .= " AND l.project_id = ?";
                $fallback_params[] = $project_id;
            }
            $fallback_result = $module->query($fallback_sql, $fallback_params);
            while ($row = $fallback_result->fetch_assoc()) {
                $decoded = json_decode($row['message'], true) ?: [];
                $decoded['timestamp'] = $row['timestamp'];
                $decoded['id']        = $row['log_id'];
                $decoded['record']    = $row['record'];
                $results[] = $decoded;
            }
        }

        $module->emDebug("Found " . count($results) . " logs for session_id: $session_id");
        return $results;
    }

    /**
     * Rehydrate a complete chat session from atomic logs
     * Returns a ready-to-use conversation object with messages array and metadata
     * 
     * @param SecureChatAI $module
     * @param string $session_id
     * @param int|null $project_id Optional project filter
     * @return array Session object with 'messages', 'metadata', 'stats'
     * @throws \Exception
     */
    public static function rehydrateSession($module, $session_id, $project_id = null)
    {
        $logs = self::getLogsBySession($module, $session_id, $project_id);
        
        if (empty($logs)) {
            return [
                'session_id' => $session_id,
                'messages' => [],
                'metadata' => [],
                'stats' => [
                    'total_turns' => 0,
                    'total_tokens' => 0,
                    'models_used' => []
                ]
            ];
        }

        // Sort by timestamp ascending to maintain conversation order
        usort($logs, function($a, $b) {
            return strtotime($a['timestamp'] ?? 0) - strtotime($b['timestamp'] ?? 0);
        });

        $messages = [];
        $models = [];
        $total_tokens = 0;
        $first_timestamp = null;
        $last_timestamp = null;

        foreach ($logs as $index => $log) {
            // Skip error logs for message reconstruction
            if (!empty($log['error'])) {
                continue;
            }

            // Add user message
            if (!empty($log['user_message'])) {
                $messages[] = [
                    'role' => 'user',
                    'content' => $log['user_message'],
                    'turn' => $index + 1
                ];
            }

            // Add assistant response
            if (!empty($log['assistant_response'])) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $log['assistant_response'],
                    'turn' => $index + 1,
                    'tools_used' => $log['tools_used'] ?? null
                ];
            }

            // Collect metadata
            if (!empty($log['model'])) {
                $models[$log['model']] = true;
            }

            if (!empty($log['usage']['total_tokens'])) {
                $total_tokens += $log['usage']['total_tokens'];
            }

            if ($first_timestamp === null) {
                $first_timestamp = $log['timestamp'];
            }
            $last_timestamp = $log['timestamp'];
        }

        return [
            'session_id' => $session_id,
            'messages' => $messages,
            'metadata' => [
                'project_id' => $logs[0]['project_id'] ?? null,
                'start_time' => $first_timestamp,
                'end_time' => $last_timestamp,
                'duration_seconds' => $first_timestamp && $last_timestamp ? 
                    strtotime($last_timestamp) - strtotime($first_timestamp) : 0
            ],
            'stats' => [
                'total_turns' => count($messages) / 2, // User + Assistant = 1 turn
                'total_tokens' => $total_tokens,
                'models_used' => array_keys($models)
            ]
        ];
    }
}
