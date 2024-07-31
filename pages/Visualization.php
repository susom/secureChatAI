<?php

namespace Stanford\SecureChatAI;
/** @var \Stanford\SecureChatAI\SecureChatAI $module */

$a = $module->getSecureChatLogs();
foreach($a as $k => $v){
    $action = $v->getLog();
    echo $k+1 . "..... Model: " . $action['model'].  "..... Total tokens: " . $action['usage']['total_tokens'] . "\n";
}




