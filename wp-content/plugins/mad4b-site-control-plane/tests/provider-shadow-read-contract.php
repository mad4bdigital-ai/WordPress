<?php
define('ABSPATH',__DIR__.'/');
function add_action($h,$c,$p=10){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;public function __construct($c,$m='',$d=null){$this->c=$c;}public function get_error_code(){return $this->c;}}
class FakeAbility{
	private $result;private $schema;
	public function __construct($result){$this->result=$result;$this->schema=array('type'=>'object','properties'=>array('q'=>array('type'=>'string')));}
	public function get_meta(){return array('annotations'=>array('readonly'=>true));}
	public function get_input_schema(){return $this->schema;}
	public function execute($input){return is_callable($this->result)?call_user_func($this->result,$input):$this->result;}
}
$GLOBALS['abilities']=array();
function wp_has_ability($n){return isset($GLOBALS['abilities'][$n]);}
function wp_get_ability($n){return $GLOBALS['abilities'][$n];}
final class MAD4B_SCP_Runtime_Generation_Fence{public static $stale=false;public static function assert_current(array $x=array()){return self::$stale?new WP_Error('generation_changed'):array('ready'=>true);}}
final class MAD4B_SCP_Execution_Fence{
 public static $denied=false;public static $calls=array();
 public static function with_governed_child($name,$input,$callback,$context){if(self::$denied)return new WP_Error('exact_grant_denied');self::$calls[]=$name;return $callback();}
}
final class MAD4B_SCP_Capability_Descriptor_Registry{
	public static function describe($n){return array('lane'=>'read','execution_eligible'=>true);}
	public static function assert_binding($n,array $b,$c){return !empty($b['binding_sha256'])?true:new WP_Error('binding_missing');}
}
final class MAD4B_SCP_Provider_Compatibility_Certification{
	public static $candidate_ok=true;
	public static function ability_status($p,$a,$x=null){return self::$candidate_ok?array('capability_id'=>'search.observe','risk'=>'read','structural_compatible'=>true):array();}
}
final class MAD4B_SCP_Crypto_Profile{
	public static function verify_digest_for_purpose(array $s,$d,$p){return isset($s['signed_sha256'])&&hash_equals($d,(string)$s['signed_sha256'])&&'behavioral_evidence'===$p?array('valid'=>true):new WP_Error('sig_bad');}
	public static function default_profile($p){return 'behavioral-evidence-rs256-v1';}
	public static function sign_digest($p,$d){return array('contract'=>'sig','profile_id'=>$p,'signed_sha256'=>$d);}
}
final class MAD4B_SCP_Structural_Redaction{
	public static function classify($v,$c=''){return is_array($v)&&isset($v['secret'])?array('classification'=>'sensitive_redacted'):array('classification'=>'public_bounded');}
}
final class MAD4B_SCP_Time_Policy{public static function now_epoch(){return 1700000000;}}

require dirname(__DIR__).'/includes/class-mad4b-scp-provider-shadow-read.php';

$fail=static function($m){fwrite(STDERR,"FAIL provider-shadow-read-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$schemaSha=hash('sha256',json_encode(array('properties'=>array('q'=>array('type'=>'string')),'type'=>'object'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$GLOBALS['abilities']['core/search']=new FakeAbility(static function($i){return array('items'=>array((string)($i['q']??'')),'count'=>1);});
$GLOBALS['abilities']['candidate/search']=new FakeAbility(static function($i){return array('items'=>array((string)($i['q']??'')),'count'=>1);});

$recipe=array(
 'contract'=>'mad4b.provider-shadow-recipe.v1','recipe_id'=>'search_shadow_v1','provider_id'=>'p1','capability_id'=>'search.observe',
 'runtime_generation'=>array('contract'=>'mad4b.runtime-generation-fence.v1','generation_sha256'=>str_repeat('b',64)),
 'active'=>array('ability'=>'core/search','zero_effect_read'=>true,'nonsecret_read'=>true,'input_schema_sha256'=>$schemaSha,'capability_descriptor_binding'=>array('binding_sha256'=>str_repeat('c',64))),
 'candidate'=>array('ability'=>'candidate/search','zero_effect_read'=>true,'nonsecret_read'=>true,'input_schema_sha256'=>$schemaSha,'capability_descriptor_binding'=>array('binding_sha256'=>str_repeat('d',64))),
 'policy'=>array('min_samples'=>2,'max_samples'=>3,'require_distinct_workloads'=>true,'candidate_output_discarded'=>true,'max_latency_regression_ms'=>5000,'max_semantic_mismatch_ppm'=>0),
 'cost_authority'=>array('mode'=>'signed_zero_cost','provider_units_per_sample'=>0,'currency_minor_units_per_sample'=>0),
 'expires_at'=>1700003600,
);
$recipe['recipe_sha256']=MAD4B_SCP_Provider_Shadow_Read::payload_sha256($recipe);
$recipe['signature']=array('signed_sha256'=>$recipe['recipe_sha256']);

$r=MAD4B_SCP_Provider_Shadow_Read::run(array('recipe'=>$recipe,'workloads'=>array(
 array('workload_id'=>'a','active_input'=>array('q'=>'x'),'candidate_input'=>array('q'=>'x')),
 array('workload_id'=>'b','active_input'=>array('q'=>'y'),'candidate_input'=>array('q'=>'y')),
)));
$check(is_array($r),'valid shadow run failed');
$check(true===$r['candidate_result_discarded'],'candidate raw output not discarded');
$check(count($r['active_results'])===2,'active results missing');
$check(!isset($r['candidate_results']),'candidate raw results leaked');
$check(true===$r['comparison_receipt']['read_exposure_promotion_eligible'],'matching candidate not eligible');
$check(false===$r['comparison_receipt']['authorizing']&&false===$r['comparison_receipt']['new_grant_or_mount_performed'],'shadow receipt widened authority');

$GLOBALS['abilities']['candidate/search']=new FakeAbility(array('items'=>array('different'),'count'=>1));
$r=MAD4B_SCP_Provider_Shadow_Read::run(array('recipe'=>$recipe,'workloads'=>array(
 array('workload_id'=>'a','active_input'=>array('q'=>'x')),
 array('workload_id'=>'b','active_input'=>array('q'=>'y')),
)));
$check(is_array($r)&&false===$r['comparison_receipt']['read_exposure_promotion_eligible'],'semantic mismatch did not block eligibility');

$GLOBALS['abilities']['candidate/search']=new FakeAbility(array('secret'=>'token'));
$r=MAD4B_SCP_Provider_Shadow_Read::run(array('recipe'=>$recipe,'workloads'=>array(
 array('workload_id'=>'a','active_input'=>array('q'=>'x')),
 array('workload_id'=>'b','active_input'=>array('q'=>'y')),
)));
$check(is_wp_error($r)&&'mad4b_shadow_candidate_private_field_leakage'===$r->get_error_code(),'private candidate field did not fail closed');

$bad=$recipe;$bad['cost_authority']['provider_units_per_sample']=1;
$bad['recipe_sha256']=MAD4B_SCP_Provider_Shadow_Read::payload_sha256($bad);$bad['signature']=array('signed_sha256'=>$bad['recipe_sha256']);
$r=MAD4B_SCP_Provider_Shadow_Read::validate_recipe($bad);
$check(is_wp_error($r)&&'mad4b_shadow_cost_authority_required'===$r->get_error_code(),'hidden/nonzero cost was not rejected');

MAD4B_SCP_Provider_Compatibility_Certification::$candidate_ok=false;
$r=MAD4B_SCP_Provider_Shadow_Read::validate_recipe($recipe);
$check(is_wp_error($r)&&'mad4b_shadow_candidate_not_proven_readonly'===$r->get_error_code(),'uncertain read effects did not prohibit sampling');
MAD4B_SCP_Provider_Compatibility_Certification::$candidate_ok=true;
MAD4B_SCP_Execution_Fence::$calls=array();
$r=MAD4B_SCP_Provider_Shadow_Read::run(array('recipe'=>$recipe,'workloads'=>array(
 array('workload_id'=>'a','active_input'=>array('q'=>'x'),'candidate_input'=>array('q'=>'other-object')),
 array('workload_id'=>'b','active_input'=>array('q'=>'y')),
)));
$check(is_wp_error($r)&&empty(MAD4B_SCP_Execution_Fence::$calls),'different objects were executed as one shadow workload');
MAD4B_SCP_Execution_Fence::$denied=true;
$r=MAD4B_SCP_Provider_Shadow_Read::run(array('recipe'=>$recipe,'workloads'=>array(array('workload_id'=>'a','active_input'=>array('q'=>'x')),array('workload_id'=>'b','active_input'=>array('q'=>'y')))));
$check(is_wp_error($r)&&empty(MAD4B_SCP_Execution_Fence::$calls),'shadow bypassed the exact-grant child fence');
MAD4B_SCP_Execution_Fence::$denied=false;
$GLOBALS['abilities']['core/search']=new FakeAbility(array('secret'=>'private-active-value'));
$r=MAD4B_SCP_Provider_Shadow_Read::run(array('recipe'=>$recipe,'workloads'=>array(array('workload_id'=>'a','active_input'=>array('q'=>'x')),array('workload_id'=>'b','active_input'=>array('q'=>'y')))));
$check(is_wp_error($r)&&'mad4b_shadow_active_private_field_leakage'===$r->get_error_code()&&array('core/search')===MAD4B_SCP_Execution_Fence::$calls,'candidate ran after private active output');
MAD4B_SCP_Execution_Fence::$calls=array();
$bad=$recipe;$bad['policy']['min_samples']=13;$bad['policy']['max_samples']=13;
$r=MAD4B_SCP_Provider_Shadow_Read::validate_recipe($bad);
$check(is_wp_error($r)&&'mad4b_shadow_sample_policy_invalid'===$r->get_error_code()&&empty(MAD4B_SCP_Execution_Fence::$calls),'runner silently clamped invalid signed sample bounds');
$GLOBALS['abilities']['core/search']=new FakeAbility(static function($i){MAD4B_SCP_Runtime_Generation_Fence::$stale=true;return array('items'=>array($i['q']));});
$r=MAD4B_SCP_Provider_Shadow_Read::run(array('recipe'=>$recipe,'workloads'=>array(array('workload_id'=>'a','active_input'=>array('q'=>'x')),array('workload_id'=>'b','active_input'=>array('q'=>'y')))));
$check(is_wp_error($r)&&array('core/search')===MAD4B_SCP_Execution_Fence::$calls,'candidate ran after active read changed the generation');

echo "mad4b.provider-shadow-read.v1: PASS\n";
