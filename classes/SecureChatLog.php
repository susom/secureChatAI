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
        return $decoded;
    }


    /** SETTERS */

    /**
     * Add a note
     * @param $note
     * @return void
     */
//    public function addNote($note)
//    {
//        $note = $this->getValue('note') ?? '';
//        $prefix = empty($note) ? "" : "\n----\n";
//        $this->setValue('note',
//            $prefix . "[" . date("Y-m-d H:i:s") . "] " .
//            $note
//        );
//    }


    /** STATIC METHODS */

    /**
     * Load the active conversation after action_id
     * @param SecureChatAI $module
     * @param int $project_id
     * @return array Action
     * @throws \Exception
     */
    public static function getLogs($module, $project_id)
    {

        $filter_clause = "project_id = ? order by log_id asc";
        $objs = self::queryObjects(
            $module, $filter_clause, [$project_id]
        );

        $count = count($objs);
        if ($count > 0) {
            $module->emDebug("Loaded $count CS in need of action");
        }

        return $count === 0 ? [] : $objs;
    }

}
