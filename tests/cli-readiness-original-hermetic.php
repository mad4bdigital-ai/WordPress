<?php
/** Native read-only WP CLI status; never runs a WP shell command or mutation. */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
class WP_CLI {
 public static $registered=[],$output=[];
 public static function add_command($name,$callback){self::$registered[]=$name;}
 public static function line($json){self::$output[]=json_decode($json,true);}
 public static function error($msg){throw new RuntimeException($msg);}
}
class WP_Error {
 function __construct($code='unknown'){}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function wp_json_encode($v,$options=0){return json_encode($v,$options);}
function wp_get_environment_type(){return 'staging';}
class MAD4B_SCP_Staging_Write_Authority {
 static $checkpoint=['ready'=>true],$execution=['ready'=>true,'candidate_binding_match'=>true,'blockers'=>[]],
 $plan=['write_tool_count'=>1,'exact_grants_missing_count'=>0,'stale_allow_grants_count'=>0];
 static function persisted_status(){return self::$checkpoint;}
 static function current_execution_readiness(){return self::$execution;}
 static function reconciliation_plan(){return self::$plan;}
}
class MAD4B_SCP_Live_Acceptance_Observer {
 static $status=[
  'manifest_present'=>true,'manifest_valid'=>true,'runtime_manifest_match'=>true,
  'stale'=>false,'provenance_mismatch'=>false
 ];
 static $throw=false;
 static function build_provenance_status(){
  if(self::$throw)throw new RuntimeException('provider unknown');
  return self::$status;
 }
}
function cli_assert($ok,$reason){
 if(!$ok){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cli.php';
$c='MAD4B_SCP_CLI';
cli_assert(count(WP_CLI::$registered)===6
 &&!in_array('mad4b shell',WP_CLI::$registered,true)
 &&!in_array('mad4b eval',WP_CLI::$registered,true),
 'Original WP CLI registers only six review and status commands, never arbitrary shell');
$c::diagnostics_write();
$diagnostic=end(WP_CLI::$output);
cli_assert($diagnostic['checkpoint_ready']===true
 &&$diagnostic['current_execution_ready']===true
 &&$diagnostic['candidate_binding_match']===true
 &&$diagnostic['authorizing']===false
 &&$diagnostic['mutation_performed']===false,
 'Exact signed host Staging write readiness remains a nonauthorizing CLI report');
foreach(['checkpoint','execution'] as $field){
 foreach([new WP_Error('original_status_unknown'),1,'true',null,[]] as $wrong){
  MAD4B_SCP_Staging_Write_Authority::$$field=
   $field==='checkpoint' ? ['ready'=>$wrong] :
   ['ready'=>$wrong,'candidate_binding_match'=>$wrong,'blockers'=>[]];
  $c::diagnostics_write();
  $d=end(WP_CLI::$output);
  cli_assert($d['checkpoint_ready']!==true || $field==='execution',
   'Unknown original persisted checkpoint cannot appear authorized in diagnostics');
  cli_assert($d['current_execution_ready']!==true || $field==='checkpoint',
   'Unknown original current readiness cannot appear verified in diagnostics');
  if($field==='execution')
   cli_assert($d['candidate_binding_match']===false,
    'Unknown Host candidate binding cannot be called matched');
 }
 MAD4B_SCP_Staging_Write_Authority::$$field=['ready'=>true,
 'candidate_binding_match'=>true,'blockers'=>[]];
}
MAD4B_SCP_Staging_Write_Authority::$checkpoint=new WP_Error('checkpoint_unavailable');
MAD4B_SCP_Staging_Write_Authority::$execution=new WP_Error('execution_unavailable');
MAD4B_SCP_Staging_Write_Authority::$plan=new WP_Error('plan_unavailable');
$c::diagnostics_write();
$d=end(WP_CLI::$output);
cli_assert($d['checkpoint_ready']===false
 &&$d['current_execution_ready']===false
 &&$d['candidate_binding_match']===false
 &&$d['write_inventory_count']===0,
 'Error-shaped original write status does not throw or invent a green WP CLI report');
MAD4B_SCP_Staging_Write_Authority::$checkpoint=['ready'=>true];
MAD4B_SCP_Staging_Write_Authority::$execution=['ready'=>true,'candidate_binding_match'=>true,'blockers'=>[]];
MAD4B_SCP_Staging_Write_Authority::$plan=['write_tool_count'=>1];
$c::package_verify();
$verified=end(WP_CLI::$output);
cli_assert($verified['verified']===true
 &&$verified['external_release_root_required_for_release_authorization']===true,
 'Valid runtime manifest only certifies a read-only runtime identity, not release permission');
foreach(['manifest_present','manifest_valid','runtime_manifest_match'] as $field){
 foreach([new WP_Error('runtime_unknown'),1,'true',null] as $bad){
  MAD4B_SCP_Live_Acceptance_Observer::$status[$field]=$bad;
  $c::package_verify();
  $observed=end(WP_CLI::$output);
  cli_assert($observed['verified']===false,
   'Error-shaped or truthy nonboolean build evidence cannot verify a package');
 }
 MAD4B_SCP_Live_Acceptance_Observer::$status[$field]=true;
}
MAD4B_SCP_Live_Acceptance_Observer::$throw=true;
$c::package_verify();
$unavailable=end(WP_CLI::$output);
cli_assert($unavailable['verified']===false
 &&$unavailable['runtime']['available']===false,
 'Throwing original build provenance readback cannot appear valid or crash WP CLI');
MAD4B_SCP_Live_Acceptance_Observer::$throw=false;
$c::package_verify();
$restored=end(WP_CLI::$output);
cli_assert($restored['verified']===true,'Exact original runtime status recovers after nonauthorizing errors');
echo 'PASS: CLI_READINESS_ORIGINAL '.($GLOBALS['checks']??0).
 ' native immutable runtime, package, current write and source error cases'.PHP_EOL;
