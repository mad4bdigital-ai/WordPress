<?php
/** Original source-based Brand Context reconciliation. Read-only, no live Drive writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim($v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',$v));}
function wp_json_encode($v,$options=0){return json_encode($v,$options);}
class MAD4B_SCP_Context_Authority {
 static $source=['source_id'=>'abc','provider'=>'google_drive','external_root_id'=>'private'];
 static function source($id){return self::$source;}
 static function asset($id){return ['asset_id'=>$id,'source_id'=>'abc'];}
}
class MAD4B_SCP_Google_Drive_Context {
 static $calls=0;
 static function create_asset(...$args){self::$calls++;return ['fake'=>'write'];}
 static function create_brand_asset(...$args){self::$calls++;return ['fake'=>'write'];}
 static function read_context_asset(...$args){return ['observed'=>'native'];}
 static function scan_folder(...$args){return ['observed'=>'native'];}
}
function assert_gw($v,$reason){
 if(!$v){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-context-provider-gateway.php';
$c='MAD4B_SCP_Context_Provider_Gateway';
$identity=['mad4b_kind'=>'brand_context',
 'mad4b_artifact'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
 'mad4b_source'=>str_repeat('a',64),
 'mad4b_idempotency'=>str_repeat('b',64),
 'mad4b_request'=>str_repeat('c',64)];
$valid=[
 'reconciliation_contract'=>$c::MATERIALIZATION_RECONCILIATION_CONTRACT,
 'provider_scan_complete'=>true,'provider_candidate_count'=>1,
 'provider_identity'=>$identity,
 'artifact_id'=>$identity['mad4b_artifact'],
 'source_id'=>str_repeat('a',64),'asset_id'=>str_repeat('d',64),
 'file_id'=>'private-native-file','target_folder_id'=>'folder-1',
 'after_sha256'=>str_repeat('e',64),
 'mime_type'=>'text/markdown','format'=>'markdown',
 'provider_scan_generation'=>'scan-generation-1'
];
$good=$c::materialization_reconciliation_ref($valid);
assert_gw(is_string($good)&&strpos($good,'brand-materialization:')===0,
 'Exact original one-candidate provider materialization reconciliation ref is deterministic');
assert_gw($good===$c::materialization_reconciliation_ref($valid),
 'Original reconciliation digest is stable without external writes');
$verify=['result'=>$valid,'reconciliation_ref'=>$good];
assert_gw($c::verify_durable_reconciliation(false,'idempotency_completion',$verify)===true,
 'Exact existing original reconciliation receipt remains recognizable');
foreach([new WP_Error('scan_unknown'),1,'true',null,[]] as $unknown){
 $bad=$valid;$bad['provider_scan_complete']=$unknown;
 assert_gw(is_wp_error($c::materialization_reconciliation_ref($bad)),
  'Unverified original provider scan completion is not one-candidate proof');
}
foreach([new WP_Error('count_unknown'),'1bad',true,1.0,null] as $unknown){
 $bad=$valid;$bad['provider_candidate_count']=$unknown;
 assert_gw(is_wp_error($c::materialization_reconciliation_ref($bad)),
  'Unverified provider candidate count is not proof of exactly one original file');
}
foreach(['mad4b_kind','mad4b_artifact','mad4b_source','mad4b_idempotency','mad4b_request'] as $field){
 $bad=$valid;$bad['provider_identity'][$field]=new WP_Error('identity_unknown');
 assert_gw(is_wp_error($c::materialization_reconciliation_ref($bad)),
  'Original idempotency provider identity does not accept WP_Error or create a fake receipt');
}
$zero=['reconciliation_contract'=>$c::MATERIALIZATION_ZERO_OBSERVATION_CONTRACT,
 'provider_scan_complete'=>true,'provider_candidate_count'=>0,
 'provider_identity'=>$identity,
 'artifact_id'=>$valid['artifact_id'],'source_id'=>$valid['source_id'],
 'target_folder_id'=>'folder-1','expected_name'=>'Brand.md',
 'expected_content_sha256'=>str_repeat('f',64),
 'expected_mime_type'=>'text/markdown','format'=>'markdown',
 'provider_scan_generation'=>'second-complete-observation'];
$ref=$c::materialization_zero_observation_ref($zero);
assert_gw(is_string($ref)&&strpos($ref,'brand-materialization-zero:')===0,
 'Original complete no-effect provider scan is a read-only reconciliation observation');
foreach([new WP_Error('scan_unknown'),1,'true',null] as $unknown){
 $bad=$zero;$bad['provider_scan_complete']=$unknown;
 assert_gw(is_wp_error($c::materialization_zero_observation_ref($bad)),
  'Unverified source scan cannot claim zero published Brand assets');
}
foreach([new WP_Error('count_unknown'),'0junk',false,0.0,null] as $unknown){
 $bad=$zero;$bad['provider_candidate_count']=$unknown;
 assert_gw(is_wp_error($c::materialization_zero_observation_ref($bad)),
  'Unknown candidate count is not verified zero existing Brand assets');
}
$noeffect=$zero;
$noeffect['reconciliation_contract']=$c::MATERIALIZATION_NO_EFFECT_CONTRACT;
$noeffect['durable_observation_count']=2;
$noeffect['durable_observation_elapsed_seconds']=120;
$noeffect['durable_scan_generations']=['generation-1','generation-2'];
$ref2=$c::materialization_no_effect_ref($noeffect);
assert_gw(is_string($ref2)&&strpos($ref2,'brand-materialization-no-effect:')===0,
 'Original two distinct durable observations certify no effect without creating another asset');
foreach([new WP_Error('attempts_unknown'),'2junk',true,2.0,null] as $unknown){
 $bad=$noeffect;$bad['durable_observation_count']=$unknown;
 assert_gw(is_wp_error($c::materialization_no_effect_ref($bad)),
  'Invalid durable observation count cannot release an original idempotency reservation');
}
foreach([new WP_Error('duration_unknown'),'120junk',true,120.0,null] as $unknown){
 $bad=$noeffect;$bad['durable_observation_elapsed_seconds']=$unknown;
 assert_gw(is_wp_error($c::materialization_no_effect_ref($bad)),
  'Invalid durable observation elapsed time cannot certify a minimum waiting window');
}
foreach([new WP_Error('generation_unknown'),['generation-1',new WP_Error('generation_unknown')]] as $unknown){
 $bad=$noeffect;$bad['durable_scan_generations']=$unknown;
 assert_gw(is_wp_error($c::materialization_no_effect_ref($bad)),
  'Unverified original durable scan generation is not a distinct provider observation');
}
// A receipt from a provider is untrusted input until every original
// immutable binding has been validated; no PHP object-to-string coercion.
foreach(['artifact_id','source_id','asset_id','file_id','target_folder_id',
 'after_sha256','mime_type','format','provider_scan_generation'] as $field){
 $bad=$valid;$bad[$field]=new WP_Error('receipt_unknown');
 assert_gw(is_wp_error($c::materialization_reconciliation_ref($bad)),
  'Error-shaped original Brand materialization receipt binding cannot certify a write');
}
foreach(['artifact_id','source_id','target_folder_id','expected_name',
 'expected_content_sha256','expected_mime_type','format','provider_scan_generation'] as $field){
 $bad=$zero;$bad[$field]=new WP_Error('receipt_unknown');
 assert_gw(is_wp_error($c::materialization_zero_observation_ref($bad)),
  'Error-shaped zero candidate receipt binding cannot certify no-effect');
 $bad=$noeffect;$bad[$field]=new WP_Error('receipt_unknown');
 assert_gw(is_wp_error($c::materialization_no_effect_ref($bad)),
  'Error-shaped durable no-effect receipt binding cannot release a retry');
}
assert_gw($c::verify_durable_reconciliation(false,'idempotency_completion',[
 'result'=>$valid,'reconciliation_ref'=>new WP_Error('ref_unknown')
])===false,'Invalid original durable reconciliation reference cannot be accepted');
MAD4B_SCP_Context_Authority::$source=new WP_Error('original_source_unknown');
$before=MAD4B_SCP_Google_Drive_Context::$calls;
assert_gw(is_wp_error($c::create_asset('abc','Name','content','markdown'))&&
 MAD4B_SCP_Google_Drive_Context::$calls===$before,
 'Original Context Authority error must not invoke the provider writer');
MAD4B_SCP_Context_Authority::$source=['source_id'=>'abc','provider'=>new WP_Error('provider_unknown')];
assert_gw(is_wp_error($c::create_brand_asset('abc','Name','content','markdown',$identity))&&
 MAD4B_SCP_Google_Drive_Context::$calls===$before,
 'Untrusted original provider ID must never dispatch to a Google Drive writer');
MAD4B_SCP_Context_Authority::$source=['source_id'=>'abc','provider'=>'google_drive'];
assert_gw($c::read_context_asset('asset-1')['observed']==='native',
 'Unchanged original provider read dispatch continues to work');
echo 'PASS: CONTEXT_PROVIDER_GATEWAY_ORIGINAL '.($GLOBALS['checks']??0).
 ' source reconciliation receipts, durable no-effect and dispatch guards'.PHP_EOL;
