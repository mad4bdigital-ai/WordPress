<?php
// WordPress Abilities names accept kebab-case, not underscore method identifiers.
define('ABSPATH',__DIR__);
$GLOBALS['registered_abilities']=array();
function wp_has_ability($name){return false;}
function wp_register_ability($name,$def){
    if(!preg_match('/^[a-z0-9-]+\\/[a-z0-9-]+$/D',$name))
        throw new RuntimeException('invalid_ability_name:'.$name);
    $GLOBALS['registered_abilities'][]=$name;
    return true;
}
require dirname(__DIR__).'/includes/class-mad4b-hub-bulk-jobs-queue.php';
MAD4B_Hub_Bulk_Jobs_Queue::abilities();
foreach(array('mad4b/hub-bulk-list-queue','mad4b/hub-bulk-batch-control',
              'mad4b/hub-bulk-acknowledge-cancellation','mad4b/hub-bulk-claim',
              'mad4b/hub-bulk-finish') as $name){
    if(!in_array($name,$GLOBALS['registered_abilities'],true))
        throw new RuntimeException('ability_not_registered:'.$name);
}
echo "BULK_QUEUE_ABILITY_REGISTRATION=PASS\n";
