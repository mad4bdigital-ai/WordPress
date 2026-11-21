<?php
/** Original JetEngine CCT credits after-commit hook; read-only and zero cache writes. */
define('ABSPATH',__DIR__.'/');
define('OBJECT','OBJECT');
$GLOBALS['registered_hooks']=[];
$GLOBALS['rights_page_lookups']=0;
function add_action($name,$callback,$priority=10,$args=1){
  $GLOBALS['registered_hooks'][$name][]=$callback;
}
function add_filter($name,$callback,$priority=10,$args=1){}
function add_shortcode($name,$callback){}
function get_page_by_path($path,$output=OBJECT,$type='page'){
  $GLOBALS['rights_page_lookups']++;
  return null; // No real WP site; count invocations only.
}
function check_rights($ok,$reason){
  if(!$ok){fwrite(STDERR,"FAIL: $reason\n");exit(1);}
  $GLOBALS['n']=($GLOBALS['n']??0)+1;
}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-media-attribution-registry.php';
$hooks=$GLOBALS['registered_hooks'];
check_rights(!isset($hooks['jet-engine/custom-content-types/update-item/media_rights']),
 'Pre-commit JetEngine hook must not purge cache before CCT persistence');
$update=$hooks['jet-engine/custom-content-types/updated-item/media_rights'][0]??null;
$delete=$hooks['jet-engine/custom-content-types/delete-item/media_rights'][0]??null;
check_rights(is_callable($update)&&is_callable($delete),
 'Original post-commit update/delete events are wired to rights archive');
foreach([
 [['cct_status'=>'draft'],['cct_status'=>'draft'],0],
 [['cct_status'=>'publish'],['cct_status'=>'draft'],1],
 [['cct_status'=>'draft'],['cct_status'=>'publish'],1],
 [['cct_status'=>'publish'],['cct_status'=>'publish'],1],
 [['cct_status'=>'pending'],['cct_status'=>'pending'],0],
] as $case){
 $GLOBALS['rights_page_lookups']=0;
 $update($case[0],$case[1]);
 check_rights($GLOBALS['rights_page_lookups']===$case[2],
  'Only published credit changes and unpublication may purge rights page');
}
foreach([['cct_status'=>'publish'],['cct_status'=>'draft'],['cct_status'=>'pending']] as $row){
 $GLOBALS['rights_page_lookups']=0;
 $delete(77,$row);
 check_rights($GLOBALS['rights_page_lookups']===($row['cct_status']==='publish'?1:0),
  'Only deletion of a published credit may purge rights page');
}
echo 'PASS: MEDIA_RIGHTS_CCT_AFTER_COMMIT_ORIGINAL '.($GLOBALS['n']??0).
 ' native post-commit published-only and zero-site-write cases'.PHP_EOL;
