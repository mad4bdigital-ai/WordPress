<?php
/** First-party Developer can_access authority gate only. No plan/apply/grants. */
define('ABSPATH',__DIR__.'/');
class WP_Error{private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Execution_Authority{
 const CONTRACT='mad4b.execution-authority.v1';
 static $result=[
  'contract'=>'mad4b.execution-authority.v1',
  'transport'=>'host_cli','wp_user_id'=>0,'authenticated'=>true,
  'subject_type'=>'host_operator','subject_fingerprint'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  'canonical_principal'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
  'execution_authorized'=>false,'mutation_performed'=>false,
  'registry_bound'=>false
 ];
 static function resolve($mode){return self::$result;}
}
class MAD4B_SCP_Site_Profile{
 static $authority=true,$managed=true;
 static function status(){return ['authority_ready'=>self::$authority];}
 static function managed_runtime_enabled(){return self::$managed;}
}
function da_check($yes,$why){if(!$yes){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);} $GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-developer-authority.php';
$c='MAD4B_SCP_Developer_Authority';
da_check($c::can_access()===true,
 'Valid verified Host NHI Developer actor and Site Profile remain eligible to plan');
foreach(['authority','managed'] as $field){
 foreach([new WP_Error('site_unknown'),1,'true',null,false,[]] as $bad){
  MAD4B_SCP_Site_Profile::$$field=$bad;
  da_check(is_wp_error($c::can_access()),
   'Unverified native Site Profile bool may not admit Developer Agent plan');
 }
 MAD4B_SCP_Site_Profile::$$field=true;
}
foreach([new WP_Error('actor_unverified'),null,false,1,'true',[],
 ['transport'=>new WP_Error('transport_unknown')],
 ['transport'=>'unknown_transport','wp_user_id'=>17],
 ['transport'=>'host_cli','wp_user_id'=>new WP_Error('user_unknown')],
 ['transport'=>'host_cli','wp_user_id'=>0],
 ['transport'=>'host_cli','wp_user_id'=>0,'authenticated'=>new WP_Error('auth_unknown')]] as $bad){
 MAD4B_SCP_Execution_Authority::$result=$bad;
 da_check(is_wp_error($c::can_access()),
  'Malformed original Developer actor cannot reach any mutable provisioning route');
}
MAD4B_SCP_Execution_Authority::$result=[
 'contract'=>'mad4b.execution-authority.v1',
 'transport'=>'host_cli','wp_user_id'=>0,'authenticated'=>true,
 'subject_type'=>'host_operator','subject_fingerprint'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
 'canonical_principal'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
 'execution_authorized'=>false,'mutation_performed'=>false,
 'registry_bound'=>false
];
da_check($c::can_access()===true,'Valid source actor recovers without changing grants');
echo 'PASS: DEVELOPER_AUTHORITY_ACCESS_ORIGINAL '.($GLOBALS['checks']??0).' immutable original policy/source/actor cases'.PHP_EOL;
