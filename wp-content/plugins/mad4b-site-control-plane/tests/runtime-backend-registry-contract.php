<?php
define('ABSPATH', __DIR__);
class WP_Error{
 function __construct($code='missing'){}
}
$GLOBALS['admin_cap']=false;
$GLOBALS['writes']=0;
$GLOBALS['saved_options']=[];
function current_user_can($cap){return $GLOBALS['admin_cap'];}
function update_option($name,$val,$autoload=false){$GLOBALS['writes']++;$GLOBALS['saved_options'][$name]=$val;return true;}
function delete_option($name){$GLOBALS['writes']++;return true;}
function get_option($name,$default=null){
 return array_key_exists($name,$GLOBALS['saved_options'])
  ? $GLOBALS['saved_options'][$name] : $default;
}
function apply_filters($name,$candidates){return $GLOBALS['backend_candidates']??$candidates;}
class MAD4B_SCP_Site_Profile {
 static $profile;
 public static function status(){
  if(!empty($GLOBALS['site_status_throw']))throw new RuntimeException('Site Profile unavailable');
  return self::$profile;
 }
}
class MAD4B_SCP_Developer_Host_Capabilities {
 static $data;
 public static function snapshot(){
  if(!empty($GLOBALS['host_snapshot_throw']))throw new RuntimeException('Host capabilities unavailable');
  return self::$data;
 }
}
function get_transient($name){return $GLOBALS['stdio_probe']??null;}
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-backend-registry.php';
$c=array(
 'docker1'=>array('type'=>'docker','operations'=>array('php.lint','runtime.status.read'),'discovered'=>true,'configured'=>true,'reachable'=>true,'certified'=>true,'authorized'=>true,'identity_verified'=>true,'isolation_verified'=>true,'latency_ms'=>15),
 'ssh1'=>array('type'=>'ssh','operations'=>array('php.lint'),'discovered'=>true,'configured'=>true,'reachable'=>true,'certified'=>false,'authorized'=>true,'identity_verified'=>true,'isolation_verified'=>true),
 'unknown'=>array('type'=>'custom','operations'=>array('php.lint'),'discovered'=>true,'configured'=>true,'reachable'=>true,'certified'=>true,'authorized'=>false,'identity_verified'=>true,'isolation_verified'=>true),
);
$s=MAD4B_SCP_Runtime_Backend_Registry::select($c,'php.lint','vps',1000);
if($s['selected']!=='docker1'||$s['eligible_count']!==1||$s['authorization_granted'])throw new RuntimeException('unsafe selection');
$c['docker1']['backoff_until']=2000;
if(MAD4B_SCP_Runtime_Backend_Registry::select($c,'php.lint','vps',1000)['selected']!==null)throw new RuntimeException('backoff bypass');
if(MAD4B_SCP_Runtime_Backend_Registry::fallback_allowed('content.publish','confirmed_not_started'))throw new RuntimeException('write fallback');
if(MAD4B_SCP_Runtime_Backend_Registry::fallback_allowed('php.lint','outcome_unknown'))throw new RuntimeException('uncertain replay');
if(!MAD4B_SCP_Runtime_Backend_Registry::fallback_allowed('php.lint','confirmed_not_started'))throw new RuntimeException('safe fallback');
$p=MAD4B_SCP_Runtime_Backend_Registry::snapshot($c,'invalid');
if($p['preset']!=='shared_hosting'||$p['authorization_granted']||isset($p['backends']['docker1']['host']))throw new RuntimeException('snapshot leak');
$validPreset=MAD4B_SCP_Runtime_Backend_Registry::normalize_preset(array('docker','ssh'));
if($validPreset!==array('docker','ssh'))throw new RuntimeException('preset validation');
if(MAD4B_SCP_Runtime_Backend_Registry::normalize_preset(array('docker','docker'))!==null)throw new RuntimeException('duplicate preset accepted');
if(MAD4B_SCP_Runtime_Backend_Registry::normalize_preset(array('unknown'))!==null)throw new RuntimeException('unknown backend accepted');
if(MAD4B_SCP_Runtime_Backend_Registry::save_preset('vps',array('docker'))!==false)throw new RuntimeException('unauthorized preset save');
$ready=[
 'type'=>'docker','operations'=>['php.lint'],'discovered'=>true,
 'configured'=>true,'reachable'=>true,'certified'=>true,'authorized'=>true,
 'identity_verified'=>true,'isolation_verified'=>true,'latency_ms'=>12
];
foreach(['discovered','configured','reachable','certified','authorized','identity_verified','isolation_verified'] as $flag){
 foreach([new WP_Error('unknown'),1,'true',null] as $untrusted){
  $bad=$ready;$bad[$flag]=$untrusted;
  $r=MAD4B_SCP_Runtime_Backend_Registry::select(['host1'=>$bad],'php.lint','vps',1000);
  if($r['selected']!==null||$r['eligible_count']!==0)
   throw new RuntimeException('Unverified candidate flag admitted: '.$flag);
  if(MAD4B_SCP_Runtime_Backend_Registry::ready($bad,'php.lint',1000))
   throw new RuntimeException('Unverified direct candidate flag ready: '.$flag);
 }
}
foreach([
 ['operations'=>[new WP_Error('unknown')]],
 ['operations'=>new WP_Error('unknown')],
 ['last_probe_at'=>new WP_Error('unknown')],
 ['backoff_until'=>new WP_Error('unknown')],
 ['latency_ms'=>new WP_Error('unknown')],
 ['operations'=>[['php.lint']]]
] as $delta){
 $r=MAD4B_SCP_Runtime_Backend_Registry::select(['host1'=>array_replace($ready,$delta)],'php.lint','vps',1000);
 if($r['selected']!==null)
  throw new RuntimeException('Untrusted original backend discovery accepted');
}
$GLOBALS['admin_cap']=new WP_Error('user_unknown');
$writes=$GLOBALS['writes'];
if(MAD4B_SCP_Runtime_Backend_Registry::save_preset('vps',['docker'])!==false||$writes!==$GLOBALS['writes'])
 throw new RuntimeException('Unverified admin cannot save executable backend preset');
$GLOBALS['admin_cap']=true;
if(!MAD4B_SCP_Runtime_Backend_Registry::save_preset('vps',['docker']))
 throw new RuntimeException('Exact WordPress admin cannot save vetted existing preset');
$GLOBALS['admin_cap']=false;
$GLOBALS['saved_options']['mad4b_runtime_backend_preset']=new WP_Error('preset_unknown');
$GLOBALS['backend_candidates']=new WP_Error('candidates_unknown');
$reported=MAD4B_SCP_Runtime_Backend_Registry::observe();
if($reported['preset']!=='shared_hosting'||$reported['backends']!==[]||$reported['authorization_granted']!==false)
 throw new RuntimeException('Unknown runtime backend source cannot become eligible');
MAD4B_SCP_Developer_Host_Capabilities::$data=new WP_Error('host_unknown');
$found=MAD4B_SCP_Runtime_Backend_Registry::builtin_candidates([]);
if(!isset($found['local_host'])||$found['local_host']['authorized']!==false
 ||$found['local_host']['certified']!==false)
 throw new RuntimeException('Unknown original Host capability registry must remain non-authorizing');
$GLOBALS['host_snapshot_throw']=true;
$found=MAD4B_SCP_Runtime_Backend_Registry::builtin_candidates([]);
if($found['local_host']['certified']!==false||$found['local_host']['authorized']!==false)
 throw new RuntimeException('Throwing original Host registry must not crash or authorize a runner');
$GLOBALS['host_snapshot_throw']=false;
MAD4B_SCP_Developer_Host_Capabilities::$data=[
 'normal_no_network_execution_ready'=>new WP_Error('host_unknown'),
 'non_root_verified'=>new WP_Error('identity_unknown'),
 'external_backends'=>new WP_Error('host_inventory_unknown')
];
$found=MAD4B_SCP_Runtime_Backend_Registry::builtin_candidates([]);
if($found['local_host']['certified']!==false
 ||$found['local_host']['identity_verified']!==false
 ||$found['isolated_vps_runner']['authorized']!==false)
 throw new RuntimeException('Unknown native Host truthy evidence cannot certify Runner');
$site_uuid='11111111-1111-4111-8111-111111111111';
MAD4B_SCP_Site_Profile::$profile=[
 'contract'=>'mad4b.site-profile.v2','site_uuid'=>$site_uuid,
 'origin_match'=>true,'environment_match'=>true,'deployment_binding_match'=>true,
 'canonical_origin'=>'https://company.example','environment'=>'staging'
];
$GLOBALS['stdio_probe']=[
 'contract'=>'mad4b.vps-mcp-stdio-read-probe.v1','site_uuid'=>$site_uuid,
 'canonical_origin'=>'https://company.example','environment'=>'staging',
 'observed_at'=>time(),'tool_count'=>2,
 'read_tool_name'=>'runtime.status.read','readback_sha256'=>str_repeat('a',64),
 'read_tool_verified'=>true,'oauth_used'=>false,'mutation_performed'=>false
];
if(MAD4B_SCP_Runtime_Backend_Registry::local_stdio_read_observation()!==true)
 throw new RuntimeException('Exact first-party original fresh STDIO read observation missing');
foreach(['origin_match','environment_match','deployment_binding_match'] as $proof){
 foreach([new WP_Error('profile_unknown'),1,'true',null] as $unknown){
  MAD4B_SCP_Site_Profile::$profile[$proof]=$unknown;
  if(MAD4B_SCP_Runtime_Backend_Registry::local_stdio_read_observation()!==false)
   throw new RuntimeException('Unknown Site Profile STDIO evidence was accepted: '.$proof);
 }
 MAD4B_SCP_Site_Profile::$profile[$proof]=true;
}
MAD4B_SCP_Site_Profile::$profile=new WP_Error('profile_missing');
if(MAD4B_SCP_Runtime_Backend_Registry::local_stdio_read_observation()!==false)
 throw new RuntimeException('Missing first-party site profile cannot certify a read canary');
$GLOBALS['site_status_throw']=true;
if(MAD4B_SCP_Runtime_Backend_Registry::local_stdio_read_observation()!==false)
 throw new RuntimeException('Throwing original Profile may not certify a read canary');
echo "PASS: RUNTIME_BACKEND_REGISTRY_FAIL_CLOSED\n";
