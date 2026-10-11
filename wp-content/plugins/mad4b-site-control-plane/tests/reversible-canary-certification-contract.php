<?php
define('ABSPATH',__DIR__.'/');
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;public function __construct($c,$m='',$d=null){$this->c=$c;}public function get_error_code(){return $this->c;}}
final class MAD4B_SCP_Runtime_Generation_Fence{public static $stale=false;public static function assert_current(array$x=array()){return self::$stale?new WP_Error('mad4b_runtime_generation_changed'):array('ready'=>true);}}
final class MAD4B_SCP_Restore_Epoch{public static $epoch=9;public static function material(){return array('epoch'=>self::$epoch);}}
final class MAD4B_SCP_Crypto_Profile{
 public static function verify_digest_for_purpose(array$s,$d,$p){return isset($s['signed_sha256'])&&hash_equals($d,(string)$s['signed_sha256'])&&'behavioral_evidence'===$p?array('valid'=>true):new WP_Error('sig_bad');}
 public static function default_profile($p){return 'behavioral-evidence-rs256-v1';}
 public static function sign_digest($p,$d){return array('profile_id'=>$p,'signed_sha256'=>$d);}
}
final class MAD4B_SCP_Identity_Context{public static function current(){return array('subject_fingerprint'=>str_repeat('e',64));}}
final class MAD4B_SCP_Site_Profile{public static $environment='staging';public static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}public static function current_environment(){return self::$environment;}}
final class MAD4B_SCP_Time_Policy{public static function now_epoch(){return 1700000000;}}
class FakeAdapter{
 public $state=array('value'=>'before');public $restore_ok=true;public $throw=false;public $observe_throw=false;public $generation_drift=false;public $calls=0;public $restores=0;
 public function reversible_contract_for($a){return 'rollback.v1';}
 public function canary_target_is_disposable($a,$t){return true;}
 public function capture_canary_effect_state($a,$t){return array('hooks'=>'clean','external'=>'none');}
 public function capture_reversible_state($a,$i){return array('target'=>array('id'=>7),'state'=>$this->state);}
 public function execute_canary($a,$i){$this->calls++;$this->state=array('value'=>'after');if($this->generation_drift)MAD4B_SCP_Runtime_Generation_Fence::$stale=true;if($this->throw)throw new Exception('x');return array('ok'=>true);}
 public function read_reversible_state($a,$t){if($this->observe_throw&&0===$this->restores)throw new Exception('readback');return $this->state;}
 public function restore_reversible_state($a,$t,$s,$m){$this->restores++;if(!$this->restore_ok)return false;$this->state=$s;return true;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-reversible-canary-certification.php';
$fail=static function($m){fwrite(STDERR,"FAIL reversible-canary-certification-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$adapter=new FakeAdapter();
$context=array(
 'provider_id'=>'p1','capability_id'=>'write.item','target_ability'=>'p1/item-write',
 'artifact_fingerprint'=>str_repeat('a',64),'capability_contract_digest'=>str_repeat('b',64),'canary_basis_digest'=>str_repeat('c',64),
 'candidate'=>array('source_commit_sha'=>str_repeat('d',40),'build_fingerprint'=>str_repeat('f',64)),
 'target_input'=>array('id'=>7),'target_input_digest'=>hash('sha256',json_encode(array('id'=>7))),'adapter'=>$adapter,
);
$targetDigest=hash('sha256',json_encode(array('id'=>7),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$recipe=array(
 'site_uuid'=>'11111111-1111-4111-8111-111111111111','environment'=>'staging','subject_fingerprint'=>str_repeat('e',64),'target_input_digest'=>$context['target_input_digest'],
 'contract'=>'mad4b.reversible-canary-recipe.v1','recipe_id'=>'p1_fixture_v1','autonomy_level'=>'L3','target_scope'=>'disposable_fixture','disposable_target_required'=>true,
 'financial_effects_allowed'=>false,'blind_retry_allowed'=>false,'provider_id'=>'p1','capability_id'=>'write.item','target_ability'=>'p1/item-write',
 'artifact_fingerprint'=>str_repeat('a',64),'capability_contract_digest'=>str_repeat('b',64),'canary_basis_digest'=>str_repeat('c',64),
 'runtime_generation'=>array('generation_sha256'=>str_repeat('9',64)),'restore_epoch'=>9,'rollback_contract'=>'rollback.v1',
 'cost_authority'=>array('mode'=>'signed_zero_cost','provider_units'=>0,'currency_minor_units'=>0),
 'disposable_target_digest'=>$targetDigest,'expires_at'=>1700003600,
);
$recipe['recipe_sha256']=MAD4B_SCP_Reversible_Canary_Certification::payload_sha256($recipe);$recipe['signature']=array('signed_sha256'=>$recipe['recipe_sha256']);
$r=MAD4B_SCP_Reversible_Canary_Certification::execute($context,$recipe);
$check(is_array($r),'valid reversible canary failed');
$check(true===$r['receipt']['restoration_verified']&&$r['receipt']['before_state_sha256']===$r['receipt']['restored_state_sha256'],'restoration not exact');
$check(false===$r['receipt']['blind_retry_allowed']&&false===$r['receipt']['promotion_granted'],'receipt widened retry/promotion');

$adapter->restore_ok=false;
$r=MAD4B_SCP_Reversible_Canary_Certification::execute($context,$recipe);
$check(is_wp_error($r)&&'mad4b_reversible_canary_restoration_failed'===$r->get_error_code(),'failed cleanup did not block');

$adapter=new FakeAdapter();$context['adapter']=$adapter;MAD4B_SCP_Restore_Epoch::$epoch=10;
$r=MAD4B_SCP_Reversible_Canary_Certification::validate_recipe($context,$recipe);
$check(is_wp_error($r)&&'mad4b_reversible_canary_restore_epoch_stale'===$r->get_error_code(),'stale restore epoch not fenced');
MAD4B_SCP_Restore_Epoch::$epoch=9;
$adapter=new FakeAdapter();$context['adapter']=$adapter;$adapter->observe_throw=true;
$r=MAD4B_SCP_Reversible_Canary_Certification::execute($context,$recipe);
$check(is_wp_error($r)&&'mad4b_reversible_canary_observation_uncertain'===$r->get_error_code()&&1===$adapter->calls&&1===$adapter->restores&&array('value'=>'before')===$adapter->state,'observation exception skipped exact cleanup or retried');
$adapter=new FakeAdapter();$context['adapter']=$adapter;$adapter->throw=true;
$r=MAD4B_SCP_Reversible_Canary_Certification::execute($context,$recipe);
$check(is_wp_error($r)&&1===$adapter->calls&&1===$adapter->restores&&array('value'=>'before')===$adapter->state,'provider exception skipped cleanup or retried');
$adapter=new FakeAdapter();$context['adapter']=$adapter;$adapter->generation_drift=true;
$r=MAD4B_SCP_Reversible_Canary_Certification::execute($context,$recipe);
$check(is_wp_error($r)&&1===$adapter->restores&&array('value'=>'before')===$adapter->state,'generation drift issued a receipt or skipped cleanup');
MAD4B_SCP_Runtime_Generation_Fence::$stale=false;
$adapter=new FakeAdapter();$context['adapter']=$adapter;MAD4B_SCP_Site_Profile::$environment='production';
$r=MAD4B_SCP_Reversible_Canary_Certification::execute($context,$recipe);
$check(is_wp_error($r)&&0===$adapter->calls,'Production canary was executed');
MAD4B_SCP_Site_Profile::$environment='staging';
$bad=$recipe;$bad['subject_fingerprint']=str_repeat('f',64);$bad['recipe_sha256']=MAD4B_SCP_Reversible_Canary_Certification::payload_sha256($bad);$bad['signature']=array('signed_sha256'=>$bad['recipe_sha256']);
$r=MAD4B_SCP_Reversible_Canary_Certification::execute($context,$bad);
$check(is_wp_error($r)&&0===$adapter->calls,'foreign subject canary was executed');

echo "mad4b.reversible-canary-receipt.v1: PASS\n";
