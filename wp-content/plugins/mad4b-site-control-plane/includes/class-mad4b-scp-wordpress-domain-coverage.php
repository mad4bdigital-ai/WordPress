<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/domains/class-mad4b-scp-domain-contracts.php';
require_once __DIR__ . '/domains/class-mad4b-scp-domain-forms.php';
require_once __DIR__ . '/domains/class-mad4b-scp-domain-commerce.php';
require_once __DIR__ . '/domains/class-mad4b-scp-domain-builders.php';
require_once __DIR__ . '/domains/class-mad4b-scp-domain-site-operations.php';
require_once __DIR__ . '/domains/class-mad4b-scp-domain-wordpress.php';

/** Read-only domain coverage, exact preflight proposals and independent observational readback. */
final class MAD4B_SCP_WordPress_Domain_Coverage {
	const CONTRACT = 'mad4b.wordpress-domain-coverage.v1';
	const PLAN = 'mad4b.wordpress-domain-plan.v1';
	const MAX_PROVIDERS = 64;
	const PLAN_TTL = 300;
	private static $providers = array();
	private static $initialized = false;

	public static function boot() { add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 ); }
	public static function can_manage() { return class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_admin(); }

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		foreach ( array( 'inventory'=>'Inspect WordPress Domain Coverage', 'plan'=>'Plan WordPress Domain Operation', 'readback'=>'Read Back WordPress Domain Proposal' ) as $method => $label ) {
			$name = 'mad4b/wordpress-domain-' . $method;
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
			wp_register_ability( $name, array( 'label'=>$label, 'description'=>'Read provider-owned coverage and preflight evidence without dispatching a mutation or creating authority.', 'category'=>'mad4b-admin', 'execute_callback'=>array( __CLASS__, $method ), 'permission_callback'=>array( __CLASS__, 'can_manage' ), 'input_schema'=>array( 'type'=>'object', 'additionalProperties'=>true ), 'output_schema'=>array( 'type'=>'object', 'additionalProperties'=>true ), 'meta'=>array( 'public'=>false, 'show_in_rest'=>false, 'mcp'=>array( 'public'=>false, 'type'=>'tool', 'surface'=>'admin' ), 'annotations'=>array( 'readonly'=>true, 'destructive'=>false, 'idempotent'=>true ), 'mad4b_contract'=>self::CONTRACT ) ) );
		}
	}

	/** Only installed reviewed provider objects can be registered; duplicate identities never replace. */
	public static function register( $provider ) {
		if ( ! $provider instanceof MAD4B_SCP_Domain_Provider || ! MAD4B_SCP_Domain_Contracts::identifier( $provider->id() ) || isset( self::$providers[ $provider->id() ] ) || count( self::$providers ) >= self::MAX_PROVIDERS ) return MAD4B_SCP_Domain_Contracts::error( 'provider_registration' );
		$definitions = $provider->capabilities();
		if ( ! is_array( $definitions ) || ! $definitions || count( $definitions ) > 32 || is_wp_error( MAD4B_SCP_Domain_Contracts::bounded( $definitions ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'provider_definitions' );
		foreach ( $definitions as $id => $definition ) {
			if ( ! MAD4B_SCP_Domain_Contracts::identifier( $id ) || ! is_array( $definition ) ) return MAD4B_SCP_Domain_Contracts::error( 'capability_definition' );
			$check = self::definition( $definition );
			if ( is_wp_error( $check ) ) return $check;
		}
		self::$providers[ $provider->id() ] = $provider;
		return true;
	}

	private static function initialize() {
		if ( self::$initialized ) return;
		self::$initialized = true;
		if ( class_exists( 'MAD4B_SCP_Domain_Native_Providers' ) ) MAD4B_SCP_Domain_Native_Providers::register();
		do_action( 'mad4b_scp_register_domain_providers', __CLASS__ );
	}

	private static function strategies() {
		return array( 'form_config'=>array('forms','MAD4B_SCP_Domain_Forms'), 'form_submissions'=>array('forms','MAD4B_SCP_Domain_Forms'), 'commerce_catalog'=>array('commerce','MAD4B_SCP_Domain_Commerce'), 'commerce_private'=>array('commerce','MAD4B_SCP_Domain_Commerce'), 'commerce_financial'=>array('commerce','MAD4B_SCP_Domain_Commerce'), 'builder_tree'=>array('builders','MAD4B_SCP_Domain_Builders'), 'operations_backup'=>array('site-operations','MAD4B_SCP_Domain_Site_Operations'), 'operations_cache'=>array('site-operations','MAD4B_SCP_Domain_Site_Operations'), 'operations_redirect'=>array('site-operations','MAD4B_SCP_Domain_Site_Operations'), 'operations_security'=>array('site-operations','MAD4B_SCP_Domain_Site_Operations'), 'wordpress_hierarchy'=>array('wordpress-breadth','MAD4B_SCP_Domain_WordPress'), 'wordpress_object'=>array('wordpress-breadth','MAD4B_SCP_Domain_WordPress'), 'wordpress_private_collection'=>array('wordpress-breadth','MAD4B_SCP_Domain_WordPress') );
	}

	private static function definition( array $definition ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $definition, array( 'family','profile','read_ability','target_schema' ), array( 'family','profile','read_ability','target_schema' ) );
		if ( is_wp_error( $check ) ) return $check;
		$strategies = self::strategies(); $profile = $definition['profile'];
		if ( ! is_string( $profile ) || ! isset( $strategies[ $profile ] ) || $strategies[ $profile ][0] !== $definition['family'] || ! is_string( $definition['read_ability'] ) || preg_match( '#^[a-z0-9][a-z0-9._-]{0,95}/[a-z0-9][a-z0-9._-]{0,95}$#D', $definition['read_ability'] ) !== 1 || ! is_array( $definition['target_schema'] ) || 'object' !== ( $definition['target_schema']['type'] ?? '' ) || false !== ( $definition['target_schema']['additionalProperties'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'typed_strategy_or_schema' );
		return true;
	}

	public static function inventory( $input = array() ) {
		if ( ! self::can_manage() ) return MAD4B_SCP_Domain_Contracts::error( 'admin_required' );
		self::initialize(); $rows = array();
		foreach ( self::$providers as $provider ) {
			$runtime = $provider->runtime();
			$rows[] = array( 'provider_id'=>$provider->id(), 'active'=>true === ( $runtime['active'] ?? null ), 'version'=>is_string( $runtime['version'] ?? null ) ? $runtime['version'] : '', 'artifact_sha256'=>is_string( $runtime['artifact_sha256'] ?? null ) ? $runtime['artifact_sha256'] : '', 'capabilities'=>$provider->capabilities(), 'execution_supported'=>false, 'live_acceptance_inferred'=>false );
		}
		return array( 'contract'=>self::CONTRACT, 'providers'=>$rows, 'discovery'=>class_exists( 'MAD4B_SCP_Domain_Native_Providers' ) ? MAD4B_SCP_Domain_Native_Providers::discovery() : array(), 'profiles'=>array_keys( self::strategies() ), 'native_objects'=>class_exists( 'MAD4B_SCP_Domain_Native_Providers' ) ? MAD4B_SCP_Domain_Native_Providers::native_objects() : array(), 'provider_absence_does_not_grant'=>true, 'authorizing'=>false, 'mutation_performed'=>false );
	}

	public static function plan( $input = array() ) {
		if ( ! self::can_manage() ) return MAD4B_SCP_Domain_Contracts::error( 'admin_required' );
		if ( ! is_array( $input ) || is_wp_error( MAD4B_SCP_Domain_Contracts::bounded( $input ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'input_bound' );
		$check = MAD4B_SCP_Domain_Contracts::keys( $input, array( 'provider_id','capability_id','target','desired','expected_state_sha256' ), array( 'provider_id','capability_id','target','desired','expected_state_sha256' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! is_array( $input['target'] ) || ! is_array( $input['desired'] ) || ! MAD4B_SCP_Domain_Contracts::sha( $input['expected_state_sha256'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'plan_shape' );
		$generation = self::generation(); if ( is_wp_error( $generation ) ) return $generation;
		$resolved = self::resolve( $input ); if ( is_wp_error( $resolved ) ) return $resolved;
		$provider = $resolved['provider']; $definition = $resolved['definition'];
		$context = self::inspect( $provider, $input['capability_id'], $definition, $input['target'], $input['desired'], $generation );
		if ( is_wp_error( $context ) ) return $context;
		if ( $context['state_sha256'] !== $input['expected_state_sha256'] ) return MAD4B_SCP_Domain_Contracts::error( 'stale_state' );
		$guard = self::guard( $definition['profile'], $input['desired'], $context['facts'] );
		if ( is_wp_error( $guard ) ) return $guard;
		$after = self::resolve( $input );
		if ( is_wp_error( $after ) || $resolved['contract_sha256'] !== $after['contract_sha256'] ) return MAD4B_SCP_Domain_Contracts::error( 'provider_contract_changed' );
		$check = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $generation ); if ( is_wp_error( $check ) ) return $check;
		$plan = array( 'contract'=>self::PLAN, 'provider_id'=>$input['provider_id'], 'capability_id'=>$input['capability_id'], 'target'=>$input['target'], 'target_sha256'=>MAD4B_SCP_Domain_Contracts::digest( $input['target'] ), 'desired_sha256'=>MAD4B_SCP_Domain_Contracts::digest( $input['desired'] ), 'before_state_sha256'=>$context['state_sha256'], 'runtime_generation'=>$generation, 'provider_contract_sha256'=>$resolved['contract_sha256'], 'capability_binding'=>$context['capability_binding'], 'actor_id'=>(int) get_current_user_id(), 'expires_at'=>self::now() + self::PLAN_TTL, 'preflight'=>$guard, 'authorizing'=>false, 'mutation_performed'=>false, 'execution_supported'=>false );
		$plan['plan_sha256'] = MAD4B_SCP_Domain_Contracts::digest( $plan );
		return $plan;
	}

	/** No before-state or raw provider values are returned; this is observation, never an approved receipt. */
	public static function readback( $input = array() ) {
		if ( ! self::can_manage() ) return MAD4B_SCP_Domain_Contracts::error( 'admin_required' );
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Generation_Fence' ) || ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' ) ) return MAD4B_SCP_Domain_Contracts::error( 'generation_runtime' );
		if ( ! is_array( $input ) || is_wp_error( MAD4B_SCP_Domain_Contracts::bounded( $input ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'input_bound' );
		$plan = $input['plan'] ?? null;
		if ( null !== $plan ) {
			$check = MAD4B_SCP_Domain_Contracts::keys( $input, array( 'plan','desired' ), array( 'plan','desired' ) );
			if ( is_wp_error( $check ) || ! is_array( $plan ) || ! is_array( $input['desired'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'readback_shape' );
			$fields = array( 'contract','provider_id','capability_id','target','target_sha256','desired_sha256','before_state_sha256','runtime_generation','provider_contract_sha256','capability_binding','actor_id','expires_at','preflight','authorizing','mutation_performed','execution_supported','plan_sha256' );
			if ( is_wp_error( MAD4B_SCP_Domain_Contracts::keys( $plan, $fields, $fields ) ) || ! is_array( $plan['capability_binding'] ) || ! is_array( $plan['preflight'] ) || ! MAD4B_SCP_Domain_Contracts::sha( $plan['provider_contract_sha256'] ) || ! MAD4B_SCP_Domain_Contracts::sha( $plan['before_state_sha256'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'plan_shape' );
			$declared = $plan['plan_sha256'] ?? ''; $basis = $plan; unset( $basis['plan_sha256'] );
			if ( ! MAD4B_SCP_Domain_Contracts::sha( $declared ) || $declared !== MAD4B_SCP_Domain_Contracts::digest( $basis ) || self::PLAN !== ( $plan['contract'] ?? '' ) || false !== ( $plan['authorizing'] ?? null ) || false !== ( $plan['execution_supported'] ?? null ) || false !== ( $plan['mutation_performed'] ?? null ) || ! is_int( $plan['expires_at'] ?? null ) || $plan['expires_at'] <= self::now() || $plan['expires_at'] > self::now() + self::PLAN_TTL || (int) get_current_user_id() !== ( $plan['actor_id'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'plan_binding_or_expiry' );
			if ( ! is_array( $plan['target'] ?? null ) || MAD4B_SCP_Domain_Contracts::digest( $plan['target'] ) !== ( $plan['target_sha256'] ?? null ) || MAD4B_SCP_Domain_Contracts::digest( $input['desired'] ) !== ( $plan['desired_sha256'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'readback_input_binding' );
			$request = array( 'provider_id'=>$plan['provider_id'] ?? '', 'capability_id'=>$plan['capability_id'] ?? '', 'target'=>$plan['target'], 'desired'=>$input['desired'] );
			$generation = $plan['runtime_generation'] ?? array();
			if ( ! is_array( $generation ) || ! $generation || is_wp_error( MAD4B_SCP_Runtime_Generation_Fence::assert_current( $generation ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'generation_drift' );
		} else {
			$check = MAD4B_SCP_Domain_Contracts::keys( $input, array( 'provider_id','capability_id','target' ), array( 'provider_id','capability_id','target' ) );
			if ( is_wp_error( $check ) || ! is_array( $input['target'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'readback_shape' );
			$request = $input; $request['desired'] = array();
			$generation = self::generation(); if ( is_wp_error( $generation ) ) return $generation;
		}
		$resolved = self::resolve( $request ); if ( is_wp_error( $resolved ) ) return $resolved;
		if ( null !== $plan && ( $resolved['contract_sha256'] !== ( $plan['provider_contract_sha256'] ?? '' ) || is_wp_error( MAD4B_SCP_Capability_Descriptor_Registry::assert_binding( $resolved['definition']['read_ability'], $plan['capability_binding'] ?? array(), 'wordpress_domain' ) ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'provider_or_descriptor_drift' );
		$context = self::inspect( $resolved['provider'], $request['capability_id'], $resolved['definition'], $request['target'], $request['desired'], $generation );
		if ( is_wp_error( $context ) ) return $context;
		$matches = false;
		if ( null !== $plan ) {
			$guard = self::guard( $resolved['definition']['profile'], $request['desired'], $context['facts'] );
			if ( is_wp_error( $guard ) ) return $guard;
			try { $matches = $resolved['provider']->matches( $request['capability_id'], $request['desired'], $context ); } catch ( Throwable $e ) { return MAD4B_SCP_Domain_Contracts::error( 'readback_comparator_failed' ); }
			if ( ! is_bool( $matches ) ) return MAD4B_SCP_Domain_Contracts::error( 'readback_comparator' );
		}
		$after = self::resolve( $request );
		if ( is_wp_error( $after ) || $resolved['contract_sha256'] !== $after['contract_sha256'] || true !== $resolved['provider']->authorize( $request['capability_id'], $request['target'], $request['desired'] ) || ! self::can_manage() ) return MAD4B_SCP_Domain_Contracts::error( 'authority_or_contract_changed' );
		$check = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $generation ); if ( is_wp_error( $check ) ) return $check;
		$check = MAD4B_SCP_Capability_Descriptor_Registry::assert_binding( $resolved['definition']['read_ability'], $context['capability_binding'], 'wordpress_domain' ); if ( is_wp_error( $check ) ) return $check;
		return array( 'contract'=>'mad4b.wordpress-domain-observation.v1', 'provider_id'=>$request['provider_id'], 'capability_id'=>$request['capability_id'], 'target_sha256'=>MAD4B_SCP_Domain_Contracts::digest( $request['target'] ), 'state_sha256'=>$context['state_sha256'], 'desired_matches_observation'=>$matches, 'plan_supplied'=>null !== $plan, 'approved_receipt_created'=>false, 'live_provider_acceptance'=>false, 'authorizing'=>false, 'mutation_performed'=>false, 'execution_supported'=>false );
	}

	private static function resolve( array $input ) {
		self::initialize();
		if ( ! MAD4B_SCP_Domain_Contracts::identifier( $input['provider_id'] ?? null ) || ! MAD4B_SCP_Domain_Contracts::identifier( $input['capability_id'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'provider_identity' );
		$provider = self::$providers[ $input['provider_id'] ] ?? null;
		if ( ! $provider ) return MAD4B_SCP_Domain_Contracts::error( 'provider_not_admitted' );
		$definitions = $provider->capabilities(); $definition = $definitions[ $input['capability_id'] ] ?? null;
		if ( ! is_array( $definition ) || is_wp_error( self::definition( $definition ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'capability_not_admitted' );
		$runtime = $provider->runtime();
		if ( ! is_array( $runtime ) || true !== ( $runtime['active'] ?? null ) || ! is_string( $runtime['version'] ?? null ) || '' === $runtime['version'] || ! MAD4B_SCP_Domain_Contracts::sha( $runtime['artifact_sha256'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'provider_runtime_binding' );
		if ( ! function_exists( 'rest_validate_value_from_schema' ) || ! is_array( $input['target'] ?? null ) || is_wp_error( rest_validate_value_from_schema( $input['target'], $definition['target_schema'], 'target' ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'target_schema' );
		$privacy = MAD4B_SCP_Domain_Contracts::no_secrets( $input['target'] ); if ( is_wp_error( $privacy ) ) return $privacy;
		$digest = MAD4B_SCP_Domain_Contracts::digest( array( 'definition'=>$definition, 'runtime'=>$runtime ) );
		if ( '' === $digest ) return MAD4B_SCP_Domain_Contracts::error( 'provider_contract_digest' );
		return array( 'provider'=>$provider, 'definition'=>$definition, 'contract_sha256'=>$digest );
	}

	private static function inspect( MAD4B_SCP_Domain_Provider $provider, $capability, array $definition, array $target, array $desired, array $generation ) {
		if ( true !== $provider->authorize( $capability, $target, $desired ) ) return MAD4B_SCP_Domain_Contracts::error( 'object_or_field_authority' );
		$runtime_before = MAD4B_SCP_Domain_Contracts::digest( array( 'runtime'=>$provider->runtime(), 'definitions'=>$provider->capabilities() ) );
		if ( '' === $runtime_before ) return MAD4B_SCP_Domain_Contracts::error( 'provider_runtime_binding' );
		$binding = MAD4B_SCP_Capability_Descriptor_Registry::binding( $definition['read_ability'], 'wordpress_domain' );
		if ( is_wp_error( $binding ) ) return $binding;
		try { $context = $provider->inspect( $capability, $target ); } catch ( Throwable $e ) { return MAD4B_SCP_Domain_Contracts::error( 'provider_inspection_failed' ); }
		if ( is_wp_error( $context ) ) return MAD4B_SCP_Domain_Contracts::error( 'provider_inspection_denied' );
		if ( ! is_array( $context ) || ! MAD4B_SCP_Domain_Contracts::sha( $context['state_sha256'] ?? null ) || ( $context['target_sha256'] ?? '' ) !== MAD4B_SCP_Domain_Contracts::digest( $target ) || ! is_array( $context['facts'] ?? null ) || is_wp_error( MAD4B_SCP_Domain_Contracts::bounded( $context ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'provider_context' );
		if ( $runtime_before !== MAD4B_SCP_Domain_Contracts::digest( array( 'runtime'=>$provider->runtime(), 'definitions'=>$provider->capabilities() ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'provider_changed_during_read' );
		if ( true !== $provider->authorize( $capability, $target, $desired ) || ! self::can_manage() ) return MAD4B_SCP_Domain_Contracts::error( 'authority_changed' );
		$check = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $generation ); if ( is_wp_error( $check ) ) return $check;
		$check = MAD4B_SCP_Capability_Descriptor_Registry::assert_binding( $definition['read_ability'], $binding, 'wordpress_domain' ); if ( is_wp_error( $check ) ) return $check;
		$context['capability_binding'] = $binding;
		return $context;
	}

	public static function guard( $profile, array $desired, array $facts ) {
		if ( is_wp_error( MAD4B_SCP_Domain_Contracts::bounded( $desired ) ) || is_wp_error( MAD4B_SCP_Domain_Contracts::bounded( $facts ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'preflight_bound' );
		$strategies = self::strategies();
		if ( ! is_string( $profile ) || ! isset( $strategies[ $profile ] ) ) return MAD4B_SCP_Domain_Contracts::error( 'strategy_not_admitted' );
		$types = array(
			'form_config'=>array('native_format'=>'string','definition_available'=>'boolean','serialization_contract'=>'boolean','allowed_field_types'=>'array','repeater_types'=>'array','upload_types'=>'array','field_contracts'=>'array'),
			'form_submissions'=>array('entries_available'=>'boolean','object_access'=>'boolean','consent_current'=>'boolean','field_access'=>'array'),
			'commerce_catalog'=>array('runtime_compatible'=>'boolean','hpos_compatible'=>'boolean','hooks_bounded'=>'boolean','objects'=>'array'),
			'commerce_private'=>array('object_access'=>'array','field_masks'=>'array'),
			'commerce_financial'=>array('object_access'=>'boolean'),
			'builder_tree'=>array('native_format'=>'string','serialization_contract'=>'boolean','revision_current'=>'boolean','editor_locked'=>'boolean','template_scope_authorized'=>'boolean','allowed_node_types'=>'array','current_ids'=>'array','allocated_clone_ids'=>'array','control_contracts'=>'array'),
			'operations_backup'=>array('package_sha256'=>'string','restore_epoch'=>'integer','protected_receipts_outside_backup'=>'boolean','allowed_artifact_paths'=>'array'),
			'operations_cache'=>array('frontend_readback_contract'=>'boolean','allowed_cache_targets'=>'array'),
			'operations_redirect'=>array('redirect_inventory_complete'=>'boolean','language_scope_authorized'=>'boolean','existing_redirects'=>'array','allowed_redirect_sources'=>'array'),
			'operations_security'=>array('object_access'=>'boolean','log_redaction_verified'=>'boolean','local_provider_scope'=>'boolean'),
			'wordpress_hierarchy'=>array('source_language'=>'string','hierarchy_inventory_complete'=>'boolean','known_parent_ids'=>'array','existing_parents'=>'array','objects'=>'array'),
			'wordpress_object'=>array('admitted_object_kinds'=>'array','object_id'=>'string','object_access'=>'boolean','field_contracts'=>'array'),
			'wordpress_private_collection'=>array('provider_available'=>'boolean','timezone'=>'string','object_access'=>'array','mask_verified'=>'array','private_communication'=>'array'),
		);
		$check = MAD4B_SCP_Domain_Contracts::fact_types( $facts, $types[ $profile ] ); if ( is_wp_error( $check ) ) return $check;
		$class = $strategies[ $profile ][1];
		return $class::validate( $profile, $desired, $facts );
	}

	private static function generation() {
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Generation_Fence' ) || ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' ) ) return MAD4B_SCP_Domain_Contracts::error( 'generation_runtime' );
		return MAD4B_SCP_Runtime_Generation_Fence::capture();
	}
	private static function now() { return class_exists( 'MAD4B_SCP_Time_Policy' ) ? (int) MAD4B_SCP_Time_Policy::now_epoch() : time(); }
}

MAD4B_SCP_WordPress_Domain_Coverage::boot();
