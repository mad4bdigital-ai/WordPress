<?php
/**
 * Adaptive prerequisite scheduling for any explicitly registered operation.
 *
 * This is NOT a security-policy downgrade engine: hard trust, artifacts,
 * ownership, exact approval, production fences and rollback are never waived.
 * It reduces unnecessary repeated work using fresh canonical observations,
 * separates preparation from effects and never calls a generic executor.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Progressive_Requirements {
	const CONTRACT = 'mad4b.progressive-requirements.v1';
	const PLAN_ABILITY = 'mad4b/progressive-requirements-plan';
	const LINK_ABILITY = 'mad4b/progressive-requirements-link';
	const MAX_GATES = 96;
	const MAX_ATTEMPT = 12;
	const MAX_EXTERNAL_HANDOFFS = 16;
	private static $providers = array();

	/** Register an operation from trusted PHP, never an MCP input callback. */
	public static function register_provider( $id, $callback ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9._-]{1,79}$/D', $id ) ||
			! is_callable( $callback ) || isset( self::$providers[ $id ] ) ) return false;
		self::$providers[ $id ] = $callback;
		return true;
	}

	public static function boot() {
		self::register_provider( 'wordpress.selected_head', array( __CLASS__, 'selected_head' ) );
		self::register_provider( 'context.brand_core', array( __CLASS__, 'brand_core' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	private static function schema() {
		return array( 'type' => 'object', 'additionalProperties' => false,
			'required' => array( 'operation_id', 'mode' ), 'properties' => array(
				'operation_id' => array( 'type'=>'string', 'pattern'=>'^[a-z][a-z0-9._-]{1,79}$' ),
				'mode' => array( 'type'=>'string', 'enum'=>array( 'detached', 'linked' ) ),
				'attempt' => array( 'type'=>'integer', 'minimum'=>1, 'maximum'=>self::MAX_ATTEMPT ),
				'candidate_source' => array( 'type'=>'object', 'additionalProperties'=>false,
					'properties'=>array(
						'repository'=>array( 'type'=>'string', 'maxLength'=>140 ),
						'type'=>array( 'type'=>'string', 'enum'=>array('pull_request','branch','commit') ),
						'reference'=>array( 'type'=>'string', 'maxLength'=>120 ) ) ),
				'reason' => array( 'type'=>'string', 'maxLength'=>500 ),
			) );
	}
	public static function register_abilities() {
		if ( ! function_exists('wp_register_ability') ) return;
		foreach ( array(
			array( self::PLAN_ABILITY, 'Plan Adaptive Requirements Without Weakening Trust', 'plan', true ),
			array( self::LINK_ABILITY, 'Create Governed Pending Linked Work Handoff', 'link', false ),
		) as $item ) {
			if ( function_exists('wp_has_ability') && wp_has_ability($item[0]) ) continue;
			$schema = self::schema();
			if ( ! $item[3] ) {
				$schema['required'][] = 'expected_plan_sha256';
				$schema['required'][] = 'confirmation';
				$schema['properties']['expected_plan_sha256'] = array('type'=>'string','pattern'=>'^[a-f0-9]{64}$');
				$schema['properties']['confirmation'] = array('type'=>'string',
					'enum'=>array('QUEUE EXACT GOVERNED WORK HANDOFF'));
			}
			wp_register_ability( $item[0], array(
				'label'=>$item[1],
				'description'=>'Adaptive prerequisites, idempotent proof reuse and optional governed handoff. No automated executor or policy downgrade.',
				'category'=>$item[3]?'mad4b-read':'mad4b-admin',
				'execute_callback'=>array(__CLASS__,$item[2]),
				'permission_callback'=>$item[3] ?
					array('MAD4B_SCP_Policy','can_read'):array(__CLASS__,'can_link'),
				'input_schema'=>$schema,
				'output_schema'=>array('type'=>'object','additionalProperties'=>true),
				'meta'=>array('public'=>false,'show_in_rest'=>false,
					'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$item[3]?'read':'admin'),
					'annotations'=>array('readonly'=>$item[3],'destructive'=>!$item[3],'idempotent'=>$item[3])),
			));
		}
	}
	/** A blocker is always a non-waivable gate unless registered by source as advisory. */
	private static function gate( $name, $ready, $policy, $phase, $dependencies = array() ) {
		return array('id'=>$name, 'ready'=>(bool)$ready,'policy'=>$policy,
			'phase'=>$phase,'dependencies'=>$dependencies);
	}
	public static function selected_head( array $input ) {
		if ( ! class_exists('MAD4B_SCP_Selected_Head_Update',false) )
			return new WP_Error('mad4b_progressive_selected_head_unavailable','Source selector unavailable.');
		$source=$input['candidate_source']??null;
		if ( ! is_array($source) || count(array_intersect(
			array('repository','type','reference'),array_keys($source)))!==3 )
			return new WP_Error('mad4b_progressive_source_required','An exact selected-head source is required.');
		$plan=MAD4B_SCP_Selected_Head_Update::plan(array(
			'candidate_source'=>$source,
			'reason'=>(string)($input['reason']??'Staging progress and exact conditional assessment'),
		));
		if ( is_wp_error($plan) ) return $plan;
		$sha=(string)($plan['source']['resolved_sha']??'');
		if ( 1!==preg_match('/^[a-f0-9]{40}$/D',$sha) )
			return new WP_Error('mad4b_progressive_source_not_verified','Exact immutable HEAD not confirmed.');
		$blockers=array_values(array_unique((array)($plan['blockers']??array())));
		$mapped=array();
		foreach($blockers as $b) {
			if(!is_string($b)||!preg_match('/^[a-z][a-z0-9_]{1,99}$/D',$b))
				return new WP_Error('mad4b_progressive_unrecognized_blocker','Unsafe unrecognized gate format.');
			$mapped[]=self::gate($b,false,'hard','before_effect');
		}
		// A package install is a one-time effect per immutable source identity,
		// NEVER a per-attempt requirement. No claimed previous success is used.
		$installed = false;
		$identity = array();
		if ( class_exists('MAD4B_SCP_Self_Update',false)
			&& method_exists('MAD4B_SCP_Self_Update','native_plan') ) {
			// No network fetch here; local matching is only a conditional
			// optimization. Real installer performs its own protected readback.
		}
		$mapped[]=self::gate('exact_package_proof',!empty($plan['package_identity']) &&
			!in_array('mad4b_selected_head_certified_artifact_unavailable',$blockers,true),
			'hard','before_effect');
		$mapped[]=self::gate('fresh_exact_host_authority',!in_array('exact_staging_binding_required',$blockers,true),
			'hard','before_effect');
		$mapped[]=self::gate('explicit_operation_opt_in',!in_array('selected_head_opt_in_disabled',$blockers,true),
			'hard','before_effect');
		$mapped[]=self::gate('installed_exact_head_readback',$installed,'postcondition','after_effect',
			array('exact_package_proof'));
		return array('operation_identity'=>$sha,'canonical_observation_digest'=>(string)($plan['plan_sha256']??''),
			'canonical_ready'=>!empty($plan['eligible']), 'conditions'=>$mapped,
			'effect_ability'=>MAD4B_SCP_Selected_Head_Update::APPLY_ABILITY,
			'effect_kind'=>'exact_head_install_if_not_already_installed',
			'effect_per_attempt'=>false,'package_recreation_per_attempt'=>false,
			'install_per_attempt'=>false,'canonical_blockers'=>$blockers,
			'operation_family'=>'plugin_install',
			'current_package_identity_readback_required'=>true);
	}
	public static function brand_core( array $input ) {
		if ( ! class_exists('MAD4B_SCP_Context_Authority',false) )
			return new WP_Error('mad4b_progressive_context_unavailable','Original Context Authority unavailable.');
		$coverage=MAD4B_SCP_Context_Authority::brand_core_coverage();
		if(!is_array($coverage))return new WP_Error('mad4b_progressive_context_invalid','Canonical coverage unavailable.');
		$gates=array();
		foreach(array('brand_strategy','tone_of_voice','editorial_guidelines') as $name)
			$gates[]=self::gate('brand_core.'.$name,
				!empty($coverage['coverage'][$name]['ready']) &&
					empty($coverage['coverage'][$name]['conflict']),'hard','before_effect');
		$state=class_exists('MAD4B_SCP_Context_Authority',false)
			? MAD4B_SCP_Context_Authority::status():array();
		$quarantine=!empty($state['quarantined_source_record_count']) ||
			!empty($state['quarantined_asset_record_count']);
		$gates[]=self::gate('no_unresolved_foreign_context',$quarantine?false:true,
			'hard','before_effect');
		return array('operation_identity'=>(string)($coverage['authority_manifest_fingerprint']??''),
			'canonical_observation_digest'=>(string)($coverage['context_fingerprint']??''),
			'canonical_ready'=>!empty($coverage['ready'])&&!$quarantine,
			'conditions'=>$gates,'effect_ability'=>'context/brand-core-control-loop',
			'effect_kind'=>'review_restore_existing_context_first',
			'effect_per_attempt'=>false,'install_per_attempt'=>false,
			'package_recreation_per_attempt'=>false,'operation_family'=>'brand_context');
	}
	/** Pure reducer for tests and future trusted operation providers. */
	public static function decide( array $observation, $mode='detached', $attempt=1,
		$site_identity='', $operation_id='' ) {
		$mode='linked'===$mode?'linked':'detached';
		$attempt=(int)$attempt;
		$conditions=$observation['conditions']??array();
		$errors=array();
		if(!is_array($conditions)||count($conditions)>self::MAX_GATES||
			$attempt<1||$attempt>self::MAX_ATTEMPT)$errors[]='unbounded_or_invalid_conditions';
		$ids=array();$rows=array();$pending=array();$per_attempt=array();
		foreach(array_slice(is_array($conditions)?$conditions:array(),0,self::MAX_GATES) as $item){
			if(!is_array($item)||!is_string($item['id']??null)||
				!preg_match('/^[a-z][a-z0-9._-]{1,99}$/D',(string)($item['id']??''))) {
				$errors[]='invalid_condition';continue;
			}
			$id=$item['id'];if(isset($ids[$id])){$errors[]='duplicate_condition';continue;}
			$ids[$id]=true;
			$policy=(string)($item['policy']??'hard');
			$phase=(string)($item['phase']??'before_effect');
			if(!in_array($policy,array('hard','revalidatable','advisory','postcondition'),true)
				||!in_array($phase,array('before_effect','after_effect'),true)) {
				$errors[]='unsupported_policy_or_phase';$policy='hard';$phase='before_effect';
			}
			$ready=true===($item['ready']??false);
			$dependencies=$item['dependencies']??array();
			if(!is_array($dependencies)||count($dependencies)>24){$errors[]='dependencies_invalid';$dependencies=array();}
			$row=array('id'=>$id,'policy'=>$policy,'phase'=>$phase,
				'currently_verified'=>$ready,'dependencies'=>$dependencies,
				'waived'=>false,'automatically_authorized'=>false,
				'requirement'=>'recheck_exact_authority_before_effect');
			if('advisory'===$policy) $row['requirement']='optional_advisory_can_defer';
			if('revalidatable'===$policy)$row['requirement']='reuse_if_source_unchanged_else_revalidate';
			if('postcondition'===$policy)$row['requirement']='verify_after_effect_once';
			if(!$ready){
				if('before_effect'===$phase&&'advisory'!==$policy)$pending[]=$id;
				if('before_effect'===$phase&&'advisory'===$policy)$row['deferred']=true;
				if('after_effect'===$phase)$per_attempt[]=$id;
			}
			$rows[]=$row;
		}
		foreach($rows as $row)foreach($row['dependencies'] as $dep)
			if(!is_string($dep)||!isset($ids[$dep])||$dep===$row['id'])
				$errors[]='invalid_condition_dependency';
		$identity=(string)($observation['operation_identity']??'');
		$server_digest=(string)($observation['canonical_observation_digest']??'');
		if(''===$site_identity||''===$operation_id||''===$identity||''===$server_digest)
			$errors[]='canonical_identity_missing';
		$errors=array_values(array_unique($errors));
		$preconditions_ready=empty($errors)&&!$pending&&!empty($observation['canonical_ready']);
		$no_reinstall='plugin_install'===($observation['operation_family']??'')&&
			!empty($observation['already_installed_exact_head_readback']);
		$effect_ability=(string)($observation['effect_ability']??'');
		$handoff_possible='linked'===$mode&&!$errors&&''!==$effect_ability&&
			(bool)preg_match('#^[a-z][a-z0-9._-]*/[a-z][a-z0-9._-]+$#D',$effect_ability);
		$out=array(
			'contract'=>self::CONTRACT,'state'=>$errors?'INTEGRITY_BLOCKED':
				($preconditions_ready?'CONDITIONS_SATISFIED':'REMEDIATION_REQUIRED'),
			'operation_id'=>$operation_id,'operation_identity'=>$identity,
			'site_identity_sha256'=>hash('sha256',$site_identity),
			'canonical_observation_digest'=>$server_digest,'mode'=>$mode,'attempt'=>$attempt,
			'requirements'=>$rows,'remaining_hard_requirements'=>$pending,
			'pending_postconditions'=>$per_attempt,
			'diagnostic_errors'=>$errors,'canonical_ready'=>!empty($observation['canonical_ready']),
			'conditions_satisfied'=>$preconditions_ready,
			'effect_kind'=>(string)($observation['effect_kind']??''),
			'effect_ability'=>$effect_ability,'effect_connected'=>$handoff_possible,
			'linked_handoff_may_be_queued'=>$handoff_possible,
			'external_effect_performed'=>false,'authorization_granted'=>false,
			'install_per_retry'=>false,'package_recreation_per_retry'=>false,
			'already_installed_exact_head_verified'=>$no_reinstall,
			'skip_duplicate_install'=>$no_reinstall,
			'next_action'=>$errors?'inspect_canonical_condition_integrity':
				($pending?'remediate_only_unresolved_conditions':
					($preconditions_ready?'prepare_separate_exact_approved_effect':'inspect_current_source_evidence')),
			'policy_downgrade_permitted'=>false,
			'production_mutation_authorized'=>false,'breakglass_authorized'=>false,
			'read_only'=>true,'authorizing'=>false,'mutation_performed'=>false,
		);
		$out['plan_sha256']=hash('sha256',json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
		return $out;
	}
	private static function exact_site_identity() {
		$site=class_exists('MAD4B_SCP_Site_Profile',false) ?
			MAD4B_SCP_Site_Profile::status():array();
		if(!is_array($site)||empty($site['configured'])||empty($site['origin_match'])||
			empty($site['environment_match'])||'staging'!==($site['environment']??$site['configured_environment']??''))
			return '';
		return (string)($site['site_uuid']??'').'|'.(string)($site['profile_digest']??'').'|staging';
	}
	public static function plan( $input=array() ) {
		if(!is_array($input))return new WP_Error('mad4b_progressive_invalid_input','Object required.');
		$allowed=array('operation_id','mode','attempt','candidate_source','reason');
		if(array_diff(array_keys($input),$allowed))
			return new WP_Error('mad4b_progressive_unknown_input','Unknown dynamic input is not executable.');
		$operation=(string)($input['operation_id']??'');
		$mode=(string)($input['mode']??'');
		$attempt=(int)($input['attempt']??1);
		if(!in_array($mode,array('detached','linked'),true)
			||!isset(self::$providers[$operation])||$attempt<1||$attempt>self::MAX_ATTEMPT)
			return new WP_Error('mad4b_progressive_unregistered_operation','Operation, mode or retry budget unavailable.');
		$site=self::exact_site_identity();
		if(''===$site)
			return new WP_Error('mad4b_progressive_staging_identity_required','Exact enrolled Staging required.');
		$observed=call_user_func(self::$providers[$operation],$input);
		if(is_wp_error($observed))return $observed;
		if(!is_array($observed))
			return new WP_Error('mad4b_progressive_provider_invalid','Trusted source provider must return a structured observation.');
		return self::decide($observed,$mode,$attempt,$site,$operation);
	}
	/** No arbitrary callback dispatch: linked mode only persists a governed handoff. */
	public static function can_link( $input=null ) {
		if(!class_exists('MAD4B_SCP_Policy',false)||!MAD4B_SCP_Policy::can_admin($input)
			||!MAD4B_SCP_Policy::can_mutate())
			return new WP_Error('mad4b_progressive_admin_required','Governed administrator mutation authority required.');
		if(!class_exists('MAD4B_SCP_Authorization',false))
			return new WP_Error('mad4b_progressive_authorization_missing','No central mutation authority.');
		return MAD4B_SCP_Authorization::authorize_mutation(self::LINK_ABILITY,
			'mad4b-admin','core',is_array($input)?$input:array());
	}
	public static function link( $input=array() ) {
		if(!is_array($input) || 'linked'!==($input['mode']??'') ||
			'QUEUE EXACT GOVERNED WORK HANDOFF'!==($input['confirmation']??''))
			return new WP_Error('mad4b_progressive_confirmation_required','Approved linked handoff input required.');
		$expected=(string)($input['expected_plan_sha256']??'');
		$plan_input=$input;unset($plan_input['expected_plan_sha256'],$plan_input['confirmation']);
		$plan=self::plan($plan_input);
		if(is_wp_error($plan))return $plan;
		if(!preg_match('/^[a-f0-9]{64}$/D',$expected)
			||!hash_equals($plan['plan_sha256'],$expected)
			||empty($plan['linked_handoff_may_be_queued']))
			return new WP_Error('mad4b_progressive_handoff_stale','Only a fresh exact linked plan may be queued.');
		if(!class_exists('MAD4B_SCP_Audit',false))
			return new WP_Error('mad4b_progressive_audit_required','Independent audit unavailable.');
		$audit=MAD4B_SCP_Audit::storage_status();
		if(!is_array($audit)||empty($audit['ready']))
			return new WP_Error('mad4b_progressive_audit_unready','Audit must be ready before handoff.');
		$key='mad4b_scp_progressive_handoff_'.substr($plan['plan_sha256'],0,24);
		$record=array('contract'=>'mad4b.progressive-linked-handoff.v1',
			'plan_sha256'=>$plan['plan_sha256'],
			'operation_id'=>$plan['operation_id'],
			'operation_identity'=>$plan['operation_identity'],
			'site_identity_sha256'=>$plan['site_identity_sha256'],
			'effect_ability'=>$plan['effect_ability'],
			'remaining_hard_requirements'=>$plan['remaining_hard_requirements'],
			'state'=>'pending_separate_effect_approval','queued_at'=>time(),
			'execution_authorized'=>false,'production_allowed'=>false);
		if(!function_exists('add_option')||!add_option($key,$record,'','no')) {
			$existing=function_exists('get_option')?get_option($key,array()):array();
			if(!is_array($existing)||($existing['plan_sha256']??'')!==$plan['plan_sha256'])
				return new WP_Error('mad4b_progressive_handoff_conflict','Bound handoff record conflicts with original plan.');
			return array('state'=>'already_queued','plan_sha256'=>$plan['plan_sha256'],
				'effect_executed'=>false,'mutation_performed'=>false);
		}
		$audit_result=MAD4B_SCP_Audit::record('mad4b/progressive-handoff',
			array('plan_sha256'=>$plan['plan_sha256'],'operation'=>$plan['operation_id'],
				'effect_ability'=>$plan['effect_ability'],
				'pending_requirements_count'=>count($plan['remaining_hard_requirements']),
				'performed_under_separate_mutation_authority'=>true),'ok');
		if(is_wp_error($audit_result)||false===$audit_result){
			if(function_exists('delete_option'))delete_option($key);
			return new WP_Error('mad4b_progressive_handoff_audit_failed','Handoff rolled back after failed audit.');
		}
		return array('contract'=>'mad4b.progressive-linked-handoff.v1',
			'state'=>'pending_separate_effect_approval',
			'plan_sha256'=>$plan['plan_sha256'],
			'condition_waivers_granted'=>0,'effect_executed'=>false,
			'pending_handoff_recorded'=>true,'mutation_performed'=>true,
			'requires_fresh_governed_effect_approval'=>true,
			'production_mutation_authorized'=>false);
	}
}
