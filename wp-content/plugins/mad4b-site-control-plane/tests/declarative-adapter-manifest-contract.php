<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action($h,$c,$p=10){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function rest_validate_value_from_schema($value,$schema,$name=''){
 foreach($value as$key=>$item)if(!isset($schema['properties'][$key])&&false===($schema['additionalProperties']??true))return new WP_Error('rest_additional_properties_forbidden');
 return true;
}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{
	private $c;
	public function __construct($c,$m='',$d=null){$this->c=$c;}
	public function get_error_code(){return $this->c;}
}
final class MAD4B_SCP_Runtime_Generation_Fence {
	public static $drift=false;
	public static function assert_current(array $expected=array()){
		if(self::$drift)return new WP_Error('mad4b_runtime_generation_changed');
		return array('ready'=>true,'generation_sha256'=>isset($expected['generation_sha256'])?$expected['generation_sha256']:'');
	}
}
final class MAD4B_SCP_Capability_Descriptor_Registry {
	public static function assert_binding($name,array $expected,$consumer){
		if('declarative_adapter_manifest'!==$consumer)return new WP_Error('bad_consumer');
		if(empty($expected['binding_sha256']))return new WP_Error('binding_missing');
		return array('valid'=>true);
	}
}
final class MAD4B_SCP_Provider_Compatibility_Certification {
	public static $artifact;
	public static function assess_provider($provider,$adapter=null){
		return array('artifact'=>array('runtime_artifact_fingerprint'=>self::$artifact));
	}
}
final class MAD4B_SCP_Crypto_Profile {
	public static function verify_digest_for_purpose(array $signature,$digest,$purpose){
		if('certification_pack'!==$purpose||'certification_pack'!==($signature['purpose']??'certification_pack'))return new WP_Error('wrong_signature_purpose');
		return isset($signature['signed_sha256'])&&hash_equals($digest,(string)$signature['signed_sha256'])
			? array('valid'=>true)
			: new WP_Error('mad4b_crypto_signature_payload_mismatch');
	}
}
final class MAD4B_SCP_Structural_Redaction {
	public static function classify($value,$context='metadata'){return array('classification'=>'public_bounded');}
}
final class MAD4B_SCP_Time_Policy { public static function now_epoch(){return 1700000000;} }

require dirname(__DIR__).'/includes/class-mad4b-scp-declarative-adapter-manifest.php';

$fail=static function($m){fwrite(STDERR,"FAIL declarative-adapter-manifest-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
MAD4B_SCP_Provider_Compatibility_Certification::$artifact=str_repeat('a',64);

$manifest=array(
	'contract'=>'mad4b.declarative-adapter-manifest.v1',
	'schema_version'=>1,
	'manifest_id'=>'provider_read_binding',
	'manifest_version'=>'1.0.0',
	'provider_id'=>'provider_one',
	'capability_id'=>'search.observe',
	'artifact_sha256'=>str_repeat('a',64),
	'runtime_generation'=>array('contract'=>'mad4b.runtime-generation-fence.v1','generation_sha256'=>str_repeat('b',64)),
	'executor'=>array('strategy_id'=>'exact_read_ability_v1','target_type'=>'ability','target_id'=>'mad4b/provider-execution-binding'),
	'capability_descriptor_binding'=>array('binding_sha256'=>str_repeat('c',64)),
	'input_schema'=>array('type'=>'object','properties'=>array('plan_family'=>array('type'=>'string')),'additionalProperties'=>false),
	'output_schema'=>array('type'=>'object','additionalProperties'=>true),
	'preconditions'=>array(array('type'=>'exact_capability_binding')),
	'effects'=>array(array('type'=>'local_read','authority_effect'=>false,'grant_effect'=>false,'tool_mount_effect'=>false,'secret_access'=>false)),
	'readback'=>array('type'=>'exact_return_value'),
	'rollback'=>array('type'=>'none'),
	'egress'=>array('mode'=>'none'),
	'cost'=>array('provider_units'=>0,'currency_minor_units'=>0),
	'expires_at'=>1700003600,
);
$manifest['manifest_sha256']=MAD4B_SCP_Declarative_Adapter_Manifest::payload_sha256($manifest);
$manifest['signature']=array('signed_sha256'=>$manifest['manifest_sha256']);

$valid=MAD4B_SCP_Declarative_Adapter_Manifest::validate($manifest);
$check(is_array($valid),'valid manifest rejected');
$check('exact_read_ability_v1'===$valid['executor']['strategy_id'],'strategy identity drifted');

$preview=MAD4B_SCP_Declarative_Adapter_Manifest::preview(array('manifest'=>$manifest));
$check(is_array($preview) && false===$preview['execution_performed'] && false===$preview['mutation_performed'] && false===$preview['authorizing'],'preview widened authority/execution');

$plan=MAD4B_SCP_Declarative_Adapter_Manifest::interpret(array('manifest'=>$manifest,'target_input'=>array('plan_family'=>'research_provider')));
$check(is_array($plan) && 'mad4b/provider-execution-binding'===$plan['target_id'] && false===$plan['execution_performed'],'interpreter dispatched or changed exact target');
$bad_input=MAD4B_SCP_Declarative_Adapter_Manifest::interpret(array('manifest'=>$manifest,'target_input'=>array('unknown'=>'x')));
$check(is_wp_error($bad_input),'interpreter accepted input outside the signed schema');
$wrong_purpose=$manifest;$wrong_purpose['signature']['purpose']='execution_receipt';
$check(is_wp_error(MAD4B_SCP_Declarative_Adapter_Manifest::validate($wrong_purpose)),'manifest accepted an execution receipt signature purpose');

$bad=$manifest;
$bad['executor']['target_type']='route';
$bad['manifest_sha256']=MAD4B_SCP_Declarative_Adapter_Manifest::payload_sha256($bad);
$bad['signature']=array('signed_sha256'=>$bad['manifest_sha256']);
$r=MAD4B_SCP_Declarative_Adapter_Manifest::validate($bad);
$check(is_wp_error($r) && 'mad4b_declarative_manifest_target_type_denied'===$r->get_error_code(),'route target was not rejected');

$bad=$manifest;
$bad['executor']['strategy_id']='discovered_php_symbol';
$bad['manifest_sha256']=MAD4B_SCP_Declarative_Adapter_Manifest::payload_sha256($bad);
$bad['signature']=array('signed_sha256'=>$bad['manifest_sha256']);
$r=MAD4B_SCP_Declarative_Adapter_Manifest::validate($bad);
$check(is_wp_error($r) && 'mad4b_declarative_manifest_strategy_unreviewed'===$r->get_error_code(),'unreviewed strategy was not rejected');

$bad=$manifest;
$bad['egress']=array('mode'=>'none','url'=>'https://example.invalid');
$bad['manifest_sha256']=MAD4B_SCP_Declarative_Adapter_Manifest::payload_sha256($bad);
$bad['signature']=array('signed_sha256'=>$bad['manifest_sha256']);
$r=MAD4B_SCP_Declarative_Adapter_Manifest::validate($bad);
$check(is_wp_error($r) && 'mad4b_declarative_manifest_generic_http_denied'===$r->get_error_code(),'generic HTTP endpoint was not rejected');

$bad=$manifest;
$bad['effects'][0]['authority_effect']=true;
$bad['manifest_sha256']=MAD4B_SCP_Declarative_Adapter_Manifest::payload_sha256($bad);
$bad['signature']=array('signed_sha256'=>$bad['manifest_sha256']);
$r=MAD4B_SCP_Declarative_Adapter_Manifest::validate($bad);
$check(is_wp_error($r) && 'mad4b_declarative_manifest_authority_effect_denied'===$r->get_error_code(),'authority effect was not rejected');

$bad=$manifest;
$bad['cost']['provider_units']=1;
$bad['manifest_sha256']=MAD4B_SCP_Declarative_Adapter_Manifest::payload_sha256($bad);
$bad['signature']=array('signed_sha256'=>$bad['manifest_sha256']);
$r=MAD4B_SCP_Declarative_Adapter_Manifest::validate($bad);
$check(is_wp_error($r) && 'mad4b_declarative_manifest_nonzero_cost_denied'===$r->get_error_code(),'unreviewed billable execution was not rejected');

$bad=$manifest;
$bad['artifact_sha256']=str_repeat('d',64);
$bad['manifest_sha256']=MAD4B_SCP_Declarative_Adapter_Manifest::payload_sha256($bad);
$bad['signature']=array('signed_sha256'=>$bad['manifest_sha256']);
$r=MAD4B_SCP_Declarative_Adapter_Manifest::validate($bad);
$check(is_wp_error($r) && 'mad4b_declarative_manifest_artifact_drift'===$r->get_error_code(),'artifact drift was not fenced');

$bad=$manifest;
$bad['signature']=array('signed_sha256'=>str_repeat('f',64));
$r=MAD4B_SCP_Declarative_Adapter_Manifest::validate($bad);
$check(is_wp_error($r) && 'mad4b_crypto_signature_payload_mismatch'===$r->get_error_code(),'signature mismatch was not rejected');

MAD4B_SCP_Runtime_Generation_Fence::$drift=true;
$r=MAD4B_SCP_Declarative_Adapter_Manifest::validate($manifest);
$check(is_wp_error($r) && 'mad4b_runtime_generation_changed'===$r->get_error_code(),'runtime generation drift was not fenced');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-declarative-adapter-manifest.php');
foreach(array('eval(','shell_exec(','proc_open(','wp_remote_get(','wp_remote_post(','call_user_func(') as $forbidden){
	$check(false===strpos($source,$forbidden),'manifest interpreter contains forbidden primitive: '.$forbidden);
}
echo "mad4b.declarative-adapter-manifest.v1: PASS\n";
