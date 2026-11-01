<?php
/** QA31 W2A: isolated, no WordPress boot, database, provider, network or writes. */
declare(strict_types=1);
define('ABSPATH','/isolated-qa31');
$GLOBALS['forced_gate_registry']=null;
$GLOBALS['auth_ready']=true;
$GLOBALS['can_manage_wc']=true;
$GLOBALS['broker_permitted']=true;
$GLOBALS['write_count']=0;
function apply_filters($hook,$value) {
  return $GLOBALS['forced_gate_registry'] ?? $value;
}
function wp_json_encode($a,$flags=0){return json_encode($a,$flags);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_get_environment_type(){return 'staging';}
function home_url($path=''){return 'https://example.invalid'.$path;}
function admin_url($path=''){return 'https://example.invalid/wp-admin/'.$path;}
function wp_parse_url($u,$part=-1){return parse_url($u,$part);}
function is_ssl(){return true;}
function current_user_can($cap){return $cap==='manage_woocommerce' && $GLOBALS['can_manage_wc'];}
function record_mutation(){++$GLOBALS['write_count'];}
class WP_Error {
 public $code;
 public function __construct($code,$message='',$data=[]){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Context_Authority {
 static function status(){return ['ready'=>$GLOBALS['auth_ready'],'site_uuid'=>'fixture-site','registry_revision'=>2];}
 static function review_policy(){return ['mode'=>'manual_review'];}
}
class MAD4B_SCP_Schema { static function critical_ready(){return true;} }
class MAD4B_SCP_Adaptive_Interaction_Broker { static function permitted(){return $GLOBALS['broker_permitted'];} }
class WooCommerce {}
class QA31DB { function get_database_path(){return __FILE__;} }
class QA31Geo { function get_database_service(){return new QA31DB();}
 function get_option($k){return $k==='license_key'?'fictional-secret':'';} }
class QA31Integrations { function get_integration($k){return $k==='maxmind_geolocation'?new QA31Geo():null;} }
class QA31WC {public $integrations;function __construct(){$this->integrations=new QA31Integrations();}}
function WC(){static $v=null;return $v??($v=new QA31WC());}
function verify($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}echo "PASS: ".$label."\n";}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-unified-gate-funnel.php';
require_once dirname(__DIR__).'/includes/class-mad4b-scp-native-settings-locator.php';
$u='MAD4B_SCP_Unified_Gate_Funnel';
$n='MAD4B_SCP_Native_Settings_Locator';
$p=$u::plan();
verify($p['mode']==='read_only' && $p['execution_authorized']===false && $p['approval_authorized']===false,'gate plan never authorizes writes');
verify($p['gate_count']===6 && preg_match('/^[a-f0-9]{64}$/',$p['plan_sha256'])===1,'six identity-bound prerequisite gates');
verify($p['plan_sha256']===$u::plan()['plan_sha256'],'stable source plan hash');
$r=$u::readiness();
verify($r['readiness']==='blocked' && $r['execution_authorized']===false,'execution remains blocked');
verify(in_array('content.execution',$r['mandatory_blockers'],true),'execution gate remains mandatory blocker');
$r=$u::readiness(['content.execution'=>true,'brand.source'=>true]);
verify(in_array('content.execution',$r['mandatory_blockers'],true),'caller cannot open gate by observation');
$GLOBALS['auth_ready']=false;
$r=$u::readiness();
verify(in_array('brand.source',$r['mandatory_blockers'],true),'missing source authority propagates');
$GLOBALS['auth_ready']=true;
$GLOBALS['forced_gate_registry']=['first'=>['surface'=>'content_generation','requires'=>['second']],'second'=>['surface'=>'content_generation','requires'=>['first']]];
try {$u::plan();verify(false,'cycle must fail closed');}catch(DomainException $e){verify($e->getMessage()==='gate_dependency_cycle','dependency cycle denied');}
$GLOBALS['forced_gate_registry']=null;
try {$u::plan(['provider_mutation']);verify(false,'invalid surface rejected');}catch(DomainException $e){verify($e->getMessage()==='gate_surfaces_invalid','unknown surface denied');}
$deny=$n::status('other/secret-settings');
verify($deny instanceof WP_Error && $deny->code==='mad4b_native_owner_unknown','unknown settings target denied');
$GLOBALS['can_manage_wc']=false;
$deny=$n::status($n::MAXMIND);
verify($deny instanceof WP_Error && $deny->code==='mad4b_native_owner_permission_denied','native Woo permission required');
$GLOBALS['can_manage_wc']=true;$GLOBALS['broker_permitted']=false;
verify($n::status($n::MAXMIND) instanceof WP_Error,'missing broker permission denied');
$GLOBALS['broker_permitted']=true;
$s=$n::status($n::MAXMIND);
verify(is_array($s)&&$s['owner']==='woocommerce'&&$s['state']==='READY','read existing Woo native setup');
verify($s['transport_attested']===true,'HTTPS transport is attested');
verify($s['license_key_present']===true&&!array_key_exists('license_key',$s),'license reported only as presence boolean');
verify($s['secret_value_exposed']===false&&$s['native_write_performed']===false&&$s['mutation_authorized']===false,'no secret or native write permission');
verify($GLOBALS['write_count']===0,'no state change');
echo "QA31_W2A_READONLY_RUNTIME: PASS\n";
