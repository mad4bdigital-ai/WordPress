<?php
/** Generic adaptive condition engine: isolated synthetic runtime, zero real side effects. */
define('ABSPATH', '/');
class WP_Error {
	private $code;
	public function __construct($code,$message=''){$this->code=$code;}
	public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action($name,$callback,$priority=10){}
function wp_has_ability($x){return false;}
function wp_register_ability($name,$def){$GLOBALS['abilities'][$name]=$def;}
function add_option($name,$value,$unused='',$autoload=false){
	if(isset($GLOBALS['options'][$name]))return false;
	$GLOBALS['options'][$name]=$value;return true;
}
function get_option($name,$default=array()){return $GLOBALS['options'][$name]??$default;}
function delete_option($name){unset($GLOBALS['options'][$name]);return true;}
class MAD4B_SCP_Test_Ability {
	public function get_name(){return 'vendor/create-cruise';}
	public function get_input_schema(){return array('type'=>'object',
		'properties'=>array(
			'itinerary'=>array('type'=>'string'),
			'post_status'=>array('type'=>'string')),
		'required'=>array('itinerary'));}
	public function get_meta(){return array('annotations'=>array('readonly'=>false));}
}
function wp_get_abilities(){return array('vendor/create-cruise'=>new MAD4B_SCP_Test_Ability());}
function wp_get_ability($name){return $name==='vendor/create-cruise'
	?new MAD4B_SCP_Test_Ability():null;}
class MAD4B_SCP_Policy {
	public static function can_admin(){return true;}
	public static function can_mutate(){return true;}
	public static function can_read(){return true;}
}
class MAD4B_SCP_Authorization {
	public static function authorize_mutation($ability,$lane,$provider,$input){return true;}
}
class MAD4B_SCP_Audit {
	public static function storage_status(){return array('ready'=>true);}
	public static function record($ability,$payload=array(),$status='ok'){
		$GLOBALS['audit'][]=$ability;return empty($GLOBALS['audit_fail']);
	}
}
class MAD4B_SCP_Site_Profile {
	public static function status() {
		return array('configured'=>true,'origin_match'=>true,'environment_match'=>true,
			'environment'=>'staging','site_uuid'=>'11111111-2222-4333-8444-555555555555',
			'profile_digest'=>str_repeat('3',64));
	}
}
class MAD4B_SCP_Selected_Head_Update {
	const APPLY_ABILITY='mad4b/control-plane-selected-head-apply';
	public static function plan($input){
		$ready=!empty($GLOBALS['ready']);
		$sha=$input['candidate_source']['reference'];
		return array(
			'source'=>array('resolved_sha'=>$sha),
			'plan_sha256'=>hash('sha256',$sha.'|'.($ready?'ready':'blocked')),
			'eligible'=>$ready,
			'blockers'=>$ready?array():array('selected_head_opt_in_disabled',
				'exact_staging_binding_required',
				'mad4b_selected_head_certified_artifact_unavailable'),
			'package_identity'=>$ready?array('build_fingerprint'=>str_repeat('a',64),
				'package_manifest_digest'=>str_repeat('b',64)):array());
	}
}
class MAD4B_SCP_External_Handshake_Evidence {
	public static function status(){
		return array('verified'=>!empty($GLOBALS['already_installed']),
			'package_identity_match'=>!empty($GLOBALS['already_installed']),
			'build_fingerprint_match'=>!empty($GLOBALS['already_installed']),
			'source_commit_sha'=>$GLOBALS['target_sha'],
			'package_build_fingerprint'=>str_repeat('a',64),
			'package_manifest_digest'=>str_repeat('b',64));
	}
}
class MAD4B_SCP_Operation_Pipeline {
	public static function compile($input){
		return array('operation_id'=>$input['operation'],
			'pipeline_sha256'=>str_repeat('e',64),
			'registry_catalog_sha256'=>str_repeat('d',64),
			'stages'=>array(
				array('id'=>'discover','type'=>'read','required'=>true,
					'enabled'=>true,'ability_registered'=>true),
				array('id'=>'verify','type'=>'readback','required'=>true,
					'enabled'=>true,'ability_registered'=>true)));
	}
}
class MAD4B_SCP_Operation_Registry {
	public static function status() {
		$a=array('id'=>'content.create_draft',
			'planner'=>'mad4b/content-orchestration-plan',
			'executor'=>'mad4b/content-apply-bundle',
			'planner_registered'=>true,'executor_registered'=>true,
			'descriptor_binding_ready'=>true,'supports'=>array('content','draft'));
		$operations=array($a);
		if(!empty($GLOBALS['extra_operation']))
			$operations[]=array_merge($a,array('id'=>'content.update_draft'));
		return array('operations'=>$operations,'catalog_sha256'=>str_repeat('d',64));
	}
	public static function operation($id) {
		if (!in_array($id,array('content.create_draft','content.update_draft'),true))
			return new WP_Error('mad4b_operation_not_registered');
		return array('id'=>$id,'planner'=>'mad4b/content-orchestration-plan',
			'executor'=>'mad4b/content-apply-bundle',
			'descriptor_binding_ready'=>true,
			'capability_descriptor_bindings'=>array('canonical'=>str_repeat('c',64)));
	}
}
class MAD4B_SCP_Context_Authority {
	public static function status(){return array(
		'quarantined_source_record_count'=>!empty($GLOBALS['brand_quarantine'])?1:0);
	}
	public static function brand_core_coverage(){
		$ready=!empty($GLOBALS['brand_ready']);
		return array('ready'=>$ready,'authority_manifest_fingerprint'=>str_repeat('b',64),
			'context_fingerprint'=>str_repeat('c',64),
			'coverage'=>array_fill_keys(array('brand_strategy','tone_of_voice','editorial_guidelines'),
				array('ready'=>$ready,'conflict'=>false)));
	}
}
function check($truth,$label){
	if(!$truth){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-progressive-requirements.php';
MAD4B_SCP_Progressive_Requirements::boot();
MAD4B_SCP_Progressive_Requirements::register_abilities();
check(isset($GLOBALS['abilities'][MAD4B_SCP_Progressive_Requirements::PLAN_ABILITY]) &&
	isset($GLOBALS['abilities'][MAD4B_SCP_Progressive_Requirements::LINK_ABILITY]),
	'read and governed link abilities must both register');
$inventory=MAD4B_SCP_Progressive_Requirements::discover(array('intent'=>'content.create_draft'));
check(!is_wp_error($inventory) && 1===$inventory['match_count'] &&
	'content.create_draft'===$inventory['auto_selection']['id'],
	'Canonical registry discovery failed');
$auto=MAD4B_SCP_Progressive_Requirements::plan(array(
	'operation_id'=>'auto','mode'=>'detached','intent'=>'content.create_draft'));
check(!is_wp_error($auto) && 'registry.operation'===$auto['resolved_provider'] &&
	'content.create_draft'===$auto['resolved_target_operation_id'],
	'Automatic operation selection did not bind exact registered target');
$GLOBALS['extra_operation']=true;
$ambiguous=MAD4B_SCP_Progressive_Requirements::plan(array(
	'operation_id'=>'auto','mode'=>'linked','intent'=>'content.'));
check(is_wp_error($ambiguous) &&
	'mad4b_progressive_auto_ambiguous'===$ambiguous->get_error_code(),
	'Multiple registered operations were selected without disambiguation');
$GLOBALS['extra_operation']=false;
$sha=$GLOBALS['target_sha']=str_repeat('f',40);
$input=array('operation_id'=>'wordpress.selected_head','mode'=>'detached','attempt'=>1,
	'candidate_source'=>array('repository'=>'mad4bdigital-ai/WordPress',
		'type'=>'commit','reference'=>$sha),'reason'=>'Test retry planning');
$first=MAD4B_SCP_Progressive_Requirements::plan($input);
check(!is_wp_error($first) && 'REMEDIATION_REQUIRED'===$first['state'] &&
	count($first['remaining_hard_requirements'])>=3,'first attempt omitted mandatory trust gates');
check(empty($first['effect_connected']) && empty($first['mutation_performed']) &&
	empty($first['skip_duplicate_install']), 'detached planning created an effect or claimed installed HEAD');
check(!$first['policy_downgrade_permitted'] && !$first['install_per_retry'] &&
	!$first['package_recreation_per_retry'],'repeat request must not weaken trust or reinstall');
$input['attempt']=2;
$second=MAD4B_SCP_Progressive_Requirements::plan($input);
check(!is_wp_error($second) && count($first['remaining_hard_requirements'])===
	count($second['remaining_hard_requirements']),
	'retry number alone cannot clear original trust gates');
$linked=$input;$linked['mode']='linked';$linked['attempt']=1;
$lp=MAD4B_SCP_Progressive_Requirements::plan($linked);
check($lp['effect_connected'] && !$lp['authorization_granted'] &&
	!$lp['external_effect_performed'] && $lp['linked_handoff_may_be_queued'],
	'linked planning must show a real handoff without executing target');
$bad=$linked;$bad['expected_plan_sha256']=str_repeat('0',64);
$bad['confirmation']='QUEUE EXACT GOVERNED WORK HANDOFF';
check(is_wp_error(MAD4B_SCP_Progressive_Requirements::link($bad)),
	'stale linked plan was accepted');
$apply=$linked;$apply['expected_plan_sha256']=$lp['plan_sha256'];
$apply['confirmation']='QUEUE EXACT GOVERNED WORK HANDOFF';
$r=MAD4B_SCP_Progressive_Requirements::link($apply);
check(is_array($r)&&!empty($r['pending_handoff_recorded']) &&
	!empty($r['mutation_performed']) && !$r['effect_executed'] &&
	$r['state']==='pending_separate_effect_approval',
	'approved linked work handoff not persisted separately from execution');
$dupe=MAD4B_SCP_Progressive_Requirements::link($apply);
check(is_array($dupe)&&'already_queued'===$dupe['state'] &&
	!$dupe['mutation_performed'],'repeat attempt created another side effect');
$GLOBALS['ready']=true;
$GLOBALS['already_installed']=false;
$changed=MAD4B_SCP_Progressive_Requirements::plan($linked);
$change_approval=$linked;
$change_approval['expected_plan_sha256']=$changed['plan_sha256'];
$change_approval['confirmation']='QUEUE EXACT GOVERNED WORK HANDOFF';
$changed_result=MAD4B_SCP_Progressive_Requirements::link($change_approval);
check(is_array($changed_result) &&
	'queued_plan_stale_reconciliation_required'===$changed_result['state'],
	'Changing the retry observations must not create another linked action');
$GLOBALS['already_installed']=true;
$installed=MAD4B_SCP_Progressive_Requirements::plan($linked);
check(!is_wp_error($installed) && $installed['skip_duplicate_install'] &&
	$installed['already_installed_exact_head_verified'] &&
	!$installed['install_per_retry'],'exact verified installed build must skip repeat installation');
$newapply=$linked;$newapply['expected_plan_sha256']=$installed['plan_sha256'];
$newapply['confirmation']='QUEUE EXACT GOVERNED WORK HANDOFF';
$installed_effect=MAD4B_SCP_Progressive_Requirements::link($newapply);
check(is_wp_error($installed_effect),'Exact installed build must never queue another installation');
$GLOBALS['already_installed']=false;
$needs=MAD4B_SCP_Progressive_Requirements::plan($linked);
check(!$needs['skip_duplicate_install'],'caller-supplied/replayed old installation proof bypassed new readback');
$GLOBALS['brand_quarantine']=true;
$brand=MAD4B_SCP_Progressive_Requirements::plan(array('operation_id'=>'context.brand_core','mode'=>'detached'));
check(!is_wp_error($brand)&&count($brand['remaining_hard_requirements'])===4,
	'quarantined Brand context was treated as clear');
$GLOBALS['brand_quarantine']=false;$GLOBALS['brand_ready']=true;
$brand=MAD4B_SCP_Progressive_Requirements::plan(array('operation_id'=>'context.brand_core','mode'=>'detached'));
check($brand['conditions_satisfied'] && empty($brand['remaining_hard_requirements']),
	'fresh trusted context evidence failed to converge');
$registry=MAD4B_SCP_Progressive_Requirements::plan(array(
	'operation_id'=>'registry.operation','mode'=>'linked',
	'target_operation_id'=>'content.create_draft'));
check(!is_wp_error($registry)&&$registry['effect_connected']&&
	$registry['effect_ability']==='mad4b/content-orchestration-plan' &&
	$registry['state']==='REMEDIATION_REQUIRED' &&
	count($registry['remaining_hard_requirements'])===3 &&
	!$registry['conditions_satisfied'],
	'Any registered operation should discover planner but never auto-grant execution');
$nonregistered=MAD4B_SCP_Progressive_Requirements::plan(array(
	'operation_id'=>'registry.operation','mode'=>'detached',
	'target_operation_id'=>'arbitrary.system_exec'));
check(is_wp_error($nonregistered),
	'External operation or arbitrary callback cannot be admitted as a generic effect');
$unknown=MAD4B_SCP_Progressive_Requirements::plan(array('operation_id'=>'untrusted.php_callback','mode'=>'linked'));
check(is_wp_error($unknown),'arbitrary callback must not enter operation registry');
$pure=MAD4B_SCP_Progressive_Requirements::decide(
	array('operation_identity'=>$sha,'canonical_observation_digest'=>str_repeat('a',64),
		'canonical_ready'=>false,'effect_ability'=>'mad4b/executor',
		'conditions'=>array(array('id'=>'external_rights','ready'=>false,'policy'=>'hard',
			'phase'=>'before_effect'),
			array('id'=>'cache_refresh','ready'=>false,'policy'=>'advisory','phase'=>'before_effect'))),
	'linked',7,'exact-staging','any.adapter');
check($pure['remaining_hard_requirements']===array('external_rights') &&
	!$pure['conditions_satisfied'] && !$pure['policy_downgrade_permitted'],
	'soft optional work improperly relaxed mandatory external rights');
echo "PASS progressive requirements, no repeated install, detached/linked, stale identity and quarantined Brand\n";
