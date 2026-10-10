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
	const DISCOVER_ABILITY = 'mad4b/progressive-requirements-discover';
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
		if ( ! class_exists( 'MAD4B_SCP_Progressive_Operation_Discovery', false ) )
			require_once __DIR__ . '/class-mad4b-scp-progressive-operation-discovery.php';
		self::register_provider( 'wordpress.selected_head', array( __CLASS__, 'selected_head' ) );
		self::register_provider( 'context.brand_core', array( __CLASS__, 'brand_core' ) );
		self::register_provider( 'registry.operation', array( __CLASS__, 'registered_operation' ) );
		self::register_provider( 'registry.ability', array( __CLASS__, 'registered_ability' ) );
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
				'target_operation_id'=>array('type'=>'string','maxLength'=>120),
				'target_ability_name'=>array('type'=>'string','maxLength'=>191),
				'intent'=>array('type'=>'string','maxLength'=>160),
				'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>50),
				'offset'=>array('type'=>'integer','minimum'=>0,'maximum'=>1000),
			) );
	}
	public static function register_abilities() {
		if ( ! function_exists('wp_register_ability') ) return;
		foreach ( array(
			array( self::DISCOVER_ABILITY, 'Discover Canonical Operations and Planner Variables Automatically', 'discover', true ),
			array( self::PLAN_ABILITY, 'Plan Adaptive Requirements Without Weakening Trust', 'plan', true ),
			array( self::LINK_ABILITY, 'Create Governed Pending Linked Work Handoff', 'link', false ),
		) as $item ) {
			if ( function_exists('wp_has_ability') && wp_has_ability($item[0]) ) continue;
			$schema = self::schema();
			if ( self::DISCOVER_ABILITY === $item[0] ) {
				$schema['required'] = array();
				$schema['properties'] = array(
					'intent' => array( 'type' => 'string', 'maxLength' => 160 ),
					'operation_id' => array( 'type' => 'string', 'maxLength' => 120 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
					'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000 ),
				);
			}
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
	/** Enumerate the live registry and registered provider contracts; no execution. */
	public static function discover( $input = array() ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ),
			array( 'intent', 'limit', 'offset', 'operation_id' ) ) )
			return new WP_Error( 'mad4b_progressive_discovery_invalid',
				'Only bounded discovery intent and pagination may be supplied.' );
		$catalog = MAD4B_SCP_Progressive_Operation_Discovery::catalog( self::$providers );
		if ( is_wp_error( $catalog ) ) return $catalog;
		$query = (string) ( $input['intent'] ?? $input['operation_id'] ?? '' );
		$result = MAD4B_SCP_Progressive_Operation_Discovery::select(
			$catalog, $query, $input['limit'] ?? 30, $input['offset'] ?? 0 );
		foreach ( $result['results'] as &$row ) {
			$row['planner_inputs'] = MAD4B_SCP_Progressive_Operation_Discovery::planner_variables(
				(string) ( $row['planner'] ?? '' ) );
		}
		unset( $row );
		$result['canonical_registry_sha256'] = $catalog['catalog_sha256'];
		$result['read_only'] = true;
		$result['authorizing'] = false;
		return $result;
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
		if ( class_exists('MAD4B_SCP_External_Handshake_Evidence',false) ) {
			$proof=MAD4B_SCP_External_Handshake_Evidence::status();
			$target=is_array($plan['package_identity']??null)?$plan['package_identity']:array();
			$installed=is_array($proof)&&!empty($proof['verified']) &&
				!empty($proof['package_identity_match']) &&
				!empty($proof['build_fingerprint_match']) &&
				hash_equals($sha,(string)($proof['source_commit_sha']??'')) &&
				!empty($target) &&
				hash_equals((string)($target['build_fingerprint']??''),
					(string)($proof['package_build_fingerprint']??'')) &&
				hash_equals((string)($target['package_manifest_digest']??''),
					(string)($proof['package_manifest_digest']??''));
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
			'install_per_attempt'=>false,'already_installed_exact_head_readback'=>$installed,'canonical_blockers'=>$blockers,
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
	/**
	 * Any operation in the governed canonical registry can participate.
	 * Planner/executor names come from the registry, never the MCP caller.
	 * Descriptor presence does NOT prove that prerequisites are satisfied.
	 */
	public static function registered_operation( array $input ) {
		if ( ! class_exists('MAD4B_SCP_Operation_Registry',false) )
			return new WP_Error('mad4b_progressive_registry_unavailable',
				'Canonical operation registry is not mounted.');
		$id=(string)($input['target_operation_id']??'');
		if(!preg_match('/^[a-z][a-z0-9._-]{1,119}$/D',$id))
			return new WP_Error('mad4b_progressive_exact_operation_required',
				'Use the registered exact operation id; arbitrary callbacks are never accepted.');
		$inventory=MAD4B_SCP_Operation_Registry::status();
		if(!is_array($inventory)||!is_array($inventory['operations']??null))
			return new WP_Error('mad4b_progressive_live_catalog_missing','Current mounted operation catalog is unavailable.');
		$verified=false;
		foreach($inventory['operations'] as $candidate) {
			if(!is_array($candidate)||($candidate['id']??'')!==$id)continue;
			$verified=!empty($candidate['descriptor_binding_ready']) &&
				true===($candidate['planner_registered']??false) &&
				true===($candidate['executor_registered']??false);
			break;
		}
		if(!$verified)
			return new WP_Error('mad4b_progressive_operation_unready',
				'Canonical planner and executor are not currently registered with verified descriptors.');
		$row=MAD4B_SCP_Operation_Registry::operation($id);
		if(is_wp_error($row))return $row;
		if(!is_array($row)||empty($row['descriptor_binding_ready']))
			return new WP_Error('mad4b_progressive_descriptor_unverified',
				'Planner and executor descriptors require canonical binding.');
		$planner=(string)($row['planner']??'');
		if(!preg_match('#^[a-z][a-z0-9._-]*/[a-z][a-z0-9._-]+$#D',$planner))
			return new WP_Error('mad4b_progressive_planner_invalid',
				'Registered operation planner does not map to a recognized Ability.');
		if ( ! class_exists('MAD4B_SCP_Operation_Pipeline',false) )
			return new WP_Error('mad4b_progressive_pipeline_unavailable',
				'Canonical declarative pipeline compiler is not mounted.');
		$pipeline=MAD4B_SCP_Operation_Pipeline::compile(array('operation'=>$id));
		if(is_wp_error($pipeline))return $pipeline;
		if(!is_array($pipeline)||($pipeline['operation_id']??'')!==$id ||
			!is_array($pipeline['stages']??null))
			return new WP_Error('mad4b_progressive_pipeline_identity_mismatch',
				'Declarative compiled operation does not match selected registered descriptor.');
		$dynamic_gates=array();
		foreach($pipeline['stages'] as $stage) {
			if(!is_array($stage)||empty($stage['enabled']))continue;
			$name=preg_replace('/[^a-z0-9._-]/','_',strtolower((string)($stage['id']??'')));
			if(''===$name)continue;
			$post=in_array((string)($stage['type']??''),array('verify','readback','reconcile','compensate'),true);
			$required=!empty($stage['required']);
			$dynamic_gates[]=self::gate('pipeline.'.$name,
				true===($stage['ability_registered']??false),
				$required?'hard':'advisory',$post?'after_effect':'before_effect');
		}
		if(count($dynamic_gates)>self::MAX_GATES-5)
			return new WP_Error('mad4b_progressive_stage_limit_exceeded',
				'Registered operation has too many dynamic stages for bounded safe planning.');
		$digest=hash('sha256',json_encode(array(
			'pipeline_sha256'=>$pipeline['pipeline_sha256']??'',
			'catalog_sha256'=>$pipeline['registry_catalog_sha256']??'',
			'registered_executor'=>$row['executor']??'',
			'id'=>$id,'planner'=>$planner,
			'executor'=>$row['executor']??'',
			'descriptor_bindings'=>$row['capability_descriptor_bindings']??array(),
		),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
		return array(
			'operation_identity'=>$digest,
			'canonical_observation_digest'=>$digest,
			'canonical_ready'=>false,
			'conditions'=>array_merge($dynamic_gates,array(
				self::gate('registered_descriptor_verified',true,'hard','before_effect'),
				self::gate('exact_planner_result_verified',false,'hard','before_effect'),
				self::gate('operation_native_requirements_satisfied',false,'hard','before_effect'),
				self::gate('separate_authorized_executor_approval',false,'hard','before_effect'),
				self::gate('independent_effect_postcondition',false,'postcondition','after_effect')
			)),
			'effect_ability'=>$planner,
			'effect_kind'=>'request_registered_planner_then_governed_executor',
			'effect_per_attempt'=>false,
			'install_per_attempt'=>false,
			'package_recreation_per_attempt'=>false,
			'operation_family'=>'registered_operation',
			'registered_executor' => (string)($row['executor']??''),
			'registered_operation_id'=>$id,
		);
	}

	/**
	 * Any WordPress registered Ability can be discovered without a new static
	 * adapter. Its ORIGINAL schema and original execution policy are the
	 * authority; this observation never calls execute() or escalates rights.
	 */
	public static function registered_ability( array $input ) {
		$name=(string)($input['target_ability_name']??'');
		if(!preg_match('#^[a-z][a-z0-9._-]*/[a-z][a-z0-9._-]+$#D',$name) ||
			0===strpos($name,'mad4b/progressive-requirements-'))
			return new WP_Error('mad4b_progressive_ability_name_invalid',
				'Exact separately registered native Ability required.');
		if(!function_exists('wp_get_ability'))
			return new WP_Error('mad4b_progressive_ability_registry_missing',
				'Native WordPress Abilities registry unavailable.');
		$ability=wp_get_ability($name);
		if(!is_object($ability)||!method_exists($ability,'get_input_schema'))
			return new WP_Error('mad4b_progressive_ability_unregistered',
				'Runtime Ability is not registered with an introspectable schema.');
		$schema=$ability->get_input_schema();
		if(!is_array($schema))
			return new WP_Error('mad4b_progressive_ability_schema_invalid',
				'Native Ability schema is invalid and cannot be dynamically bound.');
		$meta=method_exists($ability,'get_meta')?$ability->get_meta():array();
		$annotations=is_array($meta) && is_array($meta['annotations']??null)
			? $meta['annotations']:array();
		$readonly=true===($annotations['readonly']??false);
		$json=function_exists('wp_json_encode')?
			wp_json_encode(array('ability'=>$name,'schema'=>$schema,
				'declared_readonly'=>$readonly),
				JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):
			json_encode(array('ability'=>$name,'schema'=>$schema,
				'declared_readonly'=>$readonly),
				JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		if(!is_string($json)||''===$json||strlen($json)>131072)
			return new WP_Error('mad4b_progressive_ability_schema_unbounded',
				'Registered ability schema is too large to safely bind.');
		$digest=hash('sha256',$json);
		return array(
			'operation_identity'=>$digest,
			'canonical_observation_digest'=>$digest,
			'canonical_ready'=>false,
			'conditions'=>array(
				self::gate('native_ability_registered',true,'hard','before_effect'),
				self::gate('native_input_schema_bound',true,'hard','before_effect'),
				self::gate('original_plugin_permission_verified',false,'hard','before_effect'),
				self::gate('exact_native_plan_accepted',false,'hard','before_effect'),
				self::gate('separate_effect_approval',false,'hard','before_effect'),
				self::gate('native_postcondition_verified',false,'postcondition','after_effect'),
			),
			'effect_ability'=>$name,
			'effect_kind'=>$readonly?'original_governed_read':'original_governed_write',
			'original_native_ability'=>$name,
			'declared_readonly'=>$readonly,
			'ability_schema_sha256'=>$digest,
			'effect_per_attempt'=>false,
			'install_per_attempt'=>false,
			'package_recreation_per_attempt'=>false,
			'operation_family'=>'runtime_registered_ability',
		);
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
		$handoff_possible='linked'===$mode&&!$no_reinstall&&!$errors&&''!==$effect_ability&&
			(bool)preg_match('#^[a-z][a-z0-9._-]*/[a-z][a-z0-9._-]+$#D',$effect_ability);
		$out=array(
			'contract'=>self::CONTRACT,'state'=>$errors?'INTEGRITY_BLOCKED':
				($no_reinstall?'ALREADY_CURRENT_READBACK':
					($preconditions_ready?'CONDITIONS_SATISFIED':'REMEDIATION_REQUIRED')),
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
				($no_reinstall?'verify_existing_postconditions_only':
					($pending?'remediate_only_unresolved_conditions':
						($preconditions_ready?'prepare_separate_exact_approved_effect':'inspect_current_source_evidence'))),
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
		$environment=is_array($site)?
			(string)($site['environment']??$site['configured_environment']??''):'';
		if(!is_array($site)||empty($site['configured'])||empty($site['origin_match'])||
			empty($site['environment_match'])||
			!in_array($environment,array('staging','development','production','local'),true) ||
			!preg_match('/^[a-f0-9-]{36}$/D',(string)($site['site_uuid']??'')) ||
			!preg_match('/^[a-f0-9]{64}$/D',(string)($site['profile_digest']??'')))
			return '';
		return (string)$site['site_uuid'].'|'.(string)$site['profile_digest'].'|'.$environment;
	}
	public static function plan( $input=array() ) {
		if(!is_array($input))return new WP_Error('mad4b_progressive_invalid_input','Object required.');
		$allowed=array('operation_id','mode','attempt','candidate_source','reason','target_operation_id','target_ability_name','intent','limit','offset');
		if(array_diff(array_keys($input),$allowed))
			return new WP_Error('mad4b_progressive_unknown_input','Unknown dynamic input is not executable.');
		$operation=(string)($input['operation_id']??'auto');
		$mode=(string)($input['mode']??'detached');
		$attempt=(int)($input['attempt']??1);
		if ( 'auto' === $operation ) {
			$intent=trim((string)($input['intent']??''));
			if ( '' === $intent )
				return new WP_Error('mad4b_progressive_auto_intent_required',
					'Describe an operation or use its exact registered identifier.');
			$matched=self::discover(array('intent'=>$intent,'limit'=>50));
			if(is_wp_error($matched))return $matched;
			if(1!==(int)$matched['match_count'] ||
				!is_array($matched['auto_selection']??null))
				return new WP_Error('mad4b_progressive_auto_ambiguous',
					'Automatic selection was not unique. Inspect discovered alternatives.',
					array('candidate_count'=>$matched['match_count']));
			$selected=$matched['auto_selection'];
			if(empty($selected['available']))
				return new WP_Error('mad4b_progressive_auto_unready',
					'Registered planner and executor descriptors must first be proven.');
			$operation=(string)$selected['selector'];
			if('registry.operation'===$operation)
				$input['target_operation_id']=(string)$selected['target_operation_id'];
			if('registry.ability'===$operation)
				$input['target_ability_name']=(string)$selected['target_ability_name'];
		}
		if(!in_array($mode,array('detached','linked'),true)
			||!isset(self::$providers[$operation])||$attempt<1||$attempt>self::MAX_ATTEMPT)
			return new WP_Error('mad4b_progressive_unregistered_operation',
				'Operation, mode or retry budget unavailable.');
		$site=self::exact_site_identity();
		if(''===$site)
			return new WP_Error('mad4b_progressive_staging_identity_required','Exact enrolled Staging required.');
		$observed=call_user_func(self::$providers[$operation],$input);
		if(is_wp_error($observed))return $observed;
		if(!is_array($observed))
			return new WP_Error('mad4b_progressive_provider_invalid','Trusted source provider must return a structured observation.');
		$decision=self::decide($observed,$mode,$attempt,$site,
			'registry.operation'===$operation ?
				$operation.':'.(string)($input['target_operation_id']??'') : $operation);
		$decision['resolved_provider']=$operation;
		$decision['resolved_operation_id']=(string)($input['target_operation_id']??$operation);
		$decision['resolved_target_ability_name']=(string)($input['target_ability_name']??'');
		$decision['planner_inputs']=MAD4B_SCP_Progressive_Operation_Discovery::planner_variables(
			(string)($observed['effect_ability']??''));
		return $decision;
	}
	/** No arbitrary callback dispatch: linked mode only persists a governed handoff. */
	public static function can_link( $input=null ) {
		$site=class_exists('MAD4B_SCP_Site_Profile',false)?
			MAD4B_SCP_Site_Profile::status():array();
		if(!is_array($site)||
			'staging'!==(string)($site['environment']??$site['configured_environment']??'') ||
			''===self::exact_site_identity())
			return new WP_Error('mad4b_progressive_link_staging_only',
				'Generic read-only observation is portable; linked mutation requests remain Staging-only.');
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
		$bound_key=hash('sha256',$plan['operation_id'].'|'.$plan['operation_identity'].'|'.$plan['site_identity_sha256']);
		$key='mad4b_scp_progressive_handoff_'.substr($bound_key,0,24);
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
			if(!is_array($existing) ||
				($existing['operation_identity']??'')!==$plan['operation_identity'] ||
				($existing['site_identity_sha256']??'')!==$plan['site_identity_sha256'])
				return new WP_Error('mad4b_progressive_handoff_conflict','Another exact operation owns this handoff.');
			return array('state'=>($existing['plan_sha256']??'')===$plan['plan_sha256']?
					'already_queued':'queued_plan_stale_reconciliation_required',
				'plan_sha256'=>(string)($existing['plan_sha256']??''),
				'current_plan_sha256'=>$plan['plan_sha256'],
				'fresh_governed_approval_required_for_effect'=>true,
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
