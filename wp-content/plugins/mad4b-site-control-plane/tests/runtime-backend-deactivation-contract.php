<?php
define('ABSPATH',__DIR__);
$GLOBALS['cleared']=array();
function is_multisite(){return false;}
function wp_clear_scheduled_hook($name){$GLOBALS['cleared'][]=$name;}
require dirname(__DIR__).'/includes/class-mad4b-scp-catalog-lifecycle.php';
MAD4B_SCP_Catalog_Lifecycle::deactivate(false);
sort($GLOBALS['cleared']);
$expected=array('mad4b_catalog_gc','mad4b_developer_runner_refresh','mad4b_runtime_backend_refresh');
sort($expected);
if($GLOBALS['cleared']!==$expected)throw new RuntimeException('Backend refresh schedules not cleared on deactivation');
echo "PASS: RUNTIME_BACKEND_DEACTIVATION\n";
