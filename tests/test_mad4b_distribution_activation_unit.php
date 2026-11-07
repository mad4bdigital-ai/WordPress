<?php
/** Simulated partner responses, in-memory data; never claims an actual provider connection. */
define('ABSPATH','/isolated');
$GLOBALS['stub_auth']=true;$GLOBALS['stub_options']=[];$GLOBALS['stub_send']=true;$GLOBALS['stub_verify']=true;
class WP_Error {public function __construct(private string $code,private string $msg=''){}public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['stub_auth'];}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function get_option($name,$default=null){return $GLOBALS['stub_options'][$name]??$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['stub_options'][$name]=$value;return true;}
function has_filter($name){if(str_ends_with($name,'/send'))return $GLOBALS['stub_send']?10:false;if(str_ends_with($name,'/verify'))return $GLOBALS['stub_verify']?10:false;return false;}
function apply_filters($name,$value,...$args){if(str_ends_with($name,'/verify'))return ['provider_verified'=>true,'provider_account_id'=>'simulated-provider-id'];return $value;}
function admin_url($route){return 'https://company.example/wp-admin/'.$route;}
function add_action($name,$callback,$priority=10){}
class MAD4B_Distribution_Channel_Registry_V1 {
 const STORE='mad4b_distribution_channels_v1';
 public static function rows(){return get_option(self::STORE,[]);}
 public static function connection_health(){
  $out=[];foreach(self::rows() as $id=>$row){
   $missing=[];
   if(($row['provider_approval']??'')!=='approved')$missing[]='provider_enrollment_pending';
   if(empty($row['account_alias']))$missing[]='account_not_bound';
   $receipt=$row['verified_connection_receipt']??null;
   if(($row['status']??'')!=='connected_verified'||empty($receipt))$missing[]='provider_credential_not_tested';
   $out[$id]=['missing'=>$missing];
  }
  return $out;
 }
}
require_once __DIR__.'/../wp-content/plugins/mad4b-distribution-connectors/includes/class-mad4b-distribution-activation.php';
function must($ok,$label){$GLOBALS['tests']++;if(!$ok)throw new RuntimeException('FAILED_'.$label);}
$GLOBALS['tests']=0;
$row=['id'=>'viator','status'=>'not_connected','account_alias'=>'',
 'provider_approval'=>'pending','configuration_revision'=>1,
 'external_delivery_enabled'=>false,'publishing_enabled'=>false,
 'activation_requested'=>false,'verified_connection_receipt'=>null];
$GLOBALS['stub_options'][MAD4B_Distribution_Channel_Registry_V1::STORE]=['viator'=>$row];
$c='MAD4B_Distribution_Activation_V1';
must(!$c::readiness('viator')['eligible'],'PENDING_CHANNEL_INELIGIBLE');
must(is_wp_error($c::activate('viator')),'NO_ACTIVATION_UNREQUESTED');
$r=$c::request('viator');must($r['requested']===true,'REQUESTED');
must($r['delivery_enabled']===false,'REQUEST_DOES_NOT_SEND');
must($c::readiness('viator')['state']==='WAITING_PROVIDER_READINESS','WAITING_NOT_FAKE_CONNECTED');
must(is_wp_error($c::verify('viator')),'NO_APPROVAL_NO_VERIFICATION');
must(is_wp_error($c::activate('viator')),'BLOCK_ACTIVATION_MISSING_PROVIDER');
$all=MAD4B_Distribution_Channel_Registry_V1::rows();$all['viator']['provider_approval']='approved';$all['viator']['account_alias']='My Account';update_option(MAD4B_Distribution_Channel_Registry_V1::STORE,$all);
$GLOBALS['stub_verify']=false;
must(is_wp_error($c::verify('viator')),'MISSING_PROVIDER_VERIFIER');
$GLOBALS['stub_verify']=true;
$v=$c::verify('viator');
must(!is_wp_error($v)&&$v['delivery_enabled']===false,'REAL_VERIFICATION_SEPARATE_FROM_SEND');
$all=MAD4B_Distribution_Channel_Registry_V1::rows();
must(!empty($all['viator']['verified_connection_receipt']['provider_account_hash']),'HASHED_REMOTE_ACCOUNT_REFERENCE');
must(!isset($all['viator']['verified_connection_receipt']['provider_account_id']),'NO_PROVIDER_ACCOUNT_ID_LEAK');
must($c::readiness('viator')['eligible'],'VERIFIED_READY');
$GLOBALS['stub_send']=false;
must(!$c::readiness('viator')['eligible'],'SEND_ADAPTER_REQUIRED');
must(is_wp_error($c::activate('viator')),'NO_ACTIVATION_WITHOUT_ADAPTER');
$GLOBALS['stub_send']=true;
$on=$c::activate('viator');
must($on['delivery_enabled']===true,'ACTIVATE_AFTER_APPROVAL_AND_VERIFY');
must($c::enforce_gate('viator'),'ENFORCEMENT_ON');
$all=MAD4B_Distribution_Channel_Registry_V1::rows();$all['viator']['verified_connection_receipt']['verified_at']=gmdate('c',time()-90000);update_option(MAD4B_Distribution_Channel_Registry_V1::STORE,$all);
must(!$c::enforce_gate('viator'),'EXPIRED_VERIFICATION_FAILS_CLOSED');
$disabled=$c::disable('viator');must(!$disabled['delivery_enabled'],'DISABLE');
must(!$c::enforce_gate('viator'),'DISABLED_ENFORCED');
$GLOBALS['stub_auth']=false;
must(is_wp_error($c::request('viator')),'ANONYMOUS_REQUEST_DENIED');
must(is_wp_error($c::activate('viator')),'ANONYMOUS_ACTIVATE_DENIED');
must(is_wp_error($c::disable('viator')),'ANONYMOUS_DISABLE_DENIED');
echo json_encode(['suite'=>'mad4b.distribution-activation','checks_passed'=>$GLOBALS['tests'],
 'simulated_provider_only'=>true,'real_provider_calls'=>0,'external_sends'=>0,
 'blocked_until_verified'=>true,'receipt_ttl_enforced'=>true]).PHP_EOL;
