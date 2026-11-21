<?php
/** Source-bound original operation catalog, no ability execution or registered grant. */
define('ABSPATH',__DIR__.'/');
class MAD4B_SCP_Remote_Operation_Parity{
 const SKILLS_ABILITY='mad4b/reconcile-managed-skills';
 const FRONTEND_SAMPLE_ABILITY='mad4b/frontend-performance-sample-run';
 const BROWSER_ACCEPTANCE_ABILITY='mad4b/browser-acceptance-run';
 const WORK_QUEUE_ABILITY='mad4b/remote-operation-work-queue';
 const WORK_CLAIM_ABILITY='mad4b/remote-operation-work-claim';
 const WORK_COMPLETE_ABILITY='mad4b/remote-operation-work-complete';
 const QUERY_MONITOR_ATTRIBUTION_ABILITY='mad4b/query-monitor-db-attribution-bootstrap';
 const PERFORMANCE_INDEX_ABILITY='mad4b/admin-query-performance-apply';
 const PERFORMANCE_RECONCILE_ABILITY='mad4b/admin-query-performance-reconcile';
 static function original_catalog(){return require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/config/remote-operation-catalog.php';}
}
function catalog_check($good,$why){if(!$good){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
$rows=MAD4B_SCP_Remote_Operation_Parity::original_catalog();
catalog_check(is_array($rows)&&count($rows)>=20 && count($rows)<=500,
 'First-party catalog has bounded original registrations');
foreach($rows as $id=>$row){
 catalog_check(is_string($id)&&preg_match('/^[a-z0-9_]+$/D',$id)===1
  &&is_array($row),'Every first-party operation must have a stable valid ID');
 catalog_check(is_string($row['remote_ability']??null) &&strlen($row['remote_ability'])<=160
  &&is_string($row['status_ability']??null)
  &&is_string($row['provider']??null)
  &&is_string($row['feature_id']??null),'Operations must declare original provider and exact Ability identity');
 catalog_check(in_array($row['production_policy']??null,['deny','governed_source_policy','read_only'],true)
  &&is_bool($row['human_decision_required']??null),
  'Original Production policy must deny or defer to governed source; never auto-authorize');
 catalog_check(is_array($row['capability_tags']??null)
  &&count($row['capability_tags'])>0 && count($row['capability_tags'])<=30
  &&is_string($row['authority_surface']??null)
  &&strlen($row['authority_surface'])>0 &&strlen($row['authority_surface'])<=64,
  'Original operation requires bounded discoverability with explicit authority lane');
}
echo 'PASS: REMOTE_OPERATION_CATALOG_ORIGINAL '.($GLOBALS['checks']??0).
 ' original descriptor source and nonauthorizing dynamic catalog assertions'.PHP_EOL;
