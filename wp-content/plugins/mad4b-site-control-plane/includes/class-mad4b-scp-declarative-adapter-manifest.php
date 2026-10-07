<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Signed declarative adapter manifest interpreter.
 *
 * The manifest is data only. It can bind an already-admitted typed strategy to
 * an exact canonical Ability, but it cannot introduce PHP symbols, routes,
 * generic HTTP endpoints, shell/eval/template execution, authority or grants.
 */
final class MAD4B_SCP_Declarative_Adapter_Manifest {
	const CONTRACT = 'mad4b.declarative-adapter-manifest.v1';
	const PREVIEW_CONTRACT = 'mad4b.declarative-adapter-manifest-preview.v1';
	const INTERPRETATION_CONTRACT = 'mad4b.declarative-adapter-interpretation.v1';
	const SCHEMA_VERSION = 1;
	const MAX_SCHEMA_BYTES = 32768;
	const MAX_PRECONDITIONS = 32;
	const MAX_EFFECTS = 32;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 35 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		$category = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::CAT_READ : 'mad4b-read';

		wp_register_ability( 'mad4b/declarative-adapter-manifest-preview', array(
			'label' => 'Preview Declarative Adapter Manifest',
			'description' => 'Validate and preview a signed declarative adapter manifest without executing provider code or mutation.',
			'category' => $category,
			'input_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
			'output_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
			'execute_callback' => array( __CLASS__, 'preview' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
			'meta' => array(
				'annotations' => array( 'readonly'=>true, 'destructive'=>false, 'idempotent'=>true ),
				'mad4b_contract' => self::PREVIEW_CONTRACT,
				'public'=>false, 'show_in_rest'=>false, 'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'admin'),
			),
		) );

		wp_register_ability( 'mad4b/declarative-adapter-manifest-interpret', array(
			'label' => 'Interpret Declarative Adapter Manifest',
			'description' => 'Produce a non-authorizing exact typed execution plan from a valid signed manifest; never dispatches the target.',
			'category' => $category,
			'input_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
			'output_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
			'execute_callback' => array( __CLASS__, 'interpret' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
			'meta' => array(
				'annotations' => array( 'readonly'=>true, 'destructive'=>false, 'idempotent'=>true ),
				'mad4b_contract' => self::INTERPRETATION_CONTRACT,
				'public'=>false, 'show_in_rest'=>false, 'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'admin'),
			),
		) );
	}

	public static function strategies() {
		return array(
			'exact_read_ability_v1' => array(
				'risk' => 'read',
				'target_type' => 'ability',
				'mutation_allowed' => false,
				'external_effect_allowed' => false,
				'secret_access_allowed' => false,
				'nonzero_cost_allowed' => false,
			),
			'provider_canary_exact_v1' => array(
				'risk' => 'canary_write',
				'target_type' => 'ability',
				'mutation_allowed' => true,
				'external_effect_allowed' => false,
				'secret_access_allowed' => false,
				'nonzero_cost_allowed' => false,
			),
		);
	}

	public static function preview( $input = array() ) {
		$manifest = isset( $input['manifest'] ) && is_array( $input['manifest'] ) ? $input['manifest'] : $input;
		$validated = self::validate( $manifest );
		if ( is_wp_error( $validated ) ) return $validated;

		return array(
			'contract' => self::PREVIEW_CONTRACT,
			'manifest_id' => $validated['manifest_id'],
			'manifest_version' => $validated['manifest_version'],
			'provider_id' => $validated['provider_id'],
			'capability_id' => $validated['capability_id'],
			'executor_strategy_id' => $validated['executor']['strategy_id'],
			'target_type' => $validated['executor']['target_type'],
			'target_id' => $validated['executor']['target_id'],
			'artifact_sha256' => $validated['artifact_sha256'],
			'runtime_generation_sha256' => $validated['runtime_generation']['generation_sha256'],
			'effects' => $validated['effects'],
			'egress' => $validated['egress'],
			'cost' => $validated['cost'],
			'supported_strategy_ids' => array_keys( self::strategies() ),
			'execution_performed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	public static function interpret( $input = array() ) {
		$manifest = isset( $input['manifest'] ) && is_array( $input['manifest'] ) ? $input['manifest'] : array();
		$target_input = isset( $input['target_input'] ) && is_array( $input['target_input'] ) ? $input['target_input'] : array();
		$validated = self::validate( $manifest );
		if ( is_wp_error( $validated ) ) return $validated;

		$input_check = self::bounded_json( $target_input, self::MAX_SCHEMA_BYTES );
		if ( is_wp_error( $input_check ) ) return $input_check;
		if ( ! function_exists( 'rest_validate_value_from_schema' ) ) return new WP_Error( 'mad4b_declarative_manifest_schema_validator_unavailable', 'WordPress schema validation is required before interpreting input.' );
		$schema_check = rest_validate_value_from_schema( $target_input, $validated['input_schema'], 'target_input' );
		if ( is_wp_error( $schema_check ) ) return $schema_check;

		return array(
			'contract' => self::INTERPRETATION_CONTRACT,
			'manifest_id' => $validated['manifest_id'],
			'manifest_sha256' => $validated['manifest_sha256'],
			'executor_strategy_id' => $validated['executor']['strategy_id'],
			'target_type' => 'ability',
			'target_id' => $validated['executor']['target_id'],
			'target_input' => $target_input,
			'capability_descriptor_binding' => $validated['capability_descriptor_binding'],
			'artifact_sha256' => $validated['artifact_sha256'],
			'runtime_generation' => $validated['runtime_generation'],
			'readback' => $validated['readback'],
			'rollback' => $validated['rollback'],
			'execution_performed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	public static function validate( $manifest ) {
		if ( ! is_array( $manifest ) ) return new WP_Error( 'mad4b_declarative_manifest_invalid', 'Declarative adapter manifest must be an object.' );
		$size = self::bounded_json( $manifest, self::MAX_SCHEMA_BYTES );
		if ( is_wp_error( $size ) ) return $size;

		$allowed_root = array(
			'contract','schema_version','manifest_id','manifest_version','provider_id','capability_id',
			'artifact_sha256','runtime_generation','executor','capability_descriptor_binding',
			'input_schema','output_schema','preconditions','effects','readback','rollback','egress','cost',
			'expires_at','manifest_sha256','signature',
		);
		$unknown = array_diff( array_keys( $manifest ), $allowed_root );
		if ( ! empty( $unknown ) ) return new WP_Error( 'mad4b_declarative_manifest_unknown_fields', 'Declarative adapter manifest contains unsupported root fields.', array( 'fields'=>array_values( $unknown ) ) );

		if ( self::CONTRACT !== ( isset( $manifest['contract'] ) ? (string) $manifest['contract'] : '' ) ) return new WP_Error( 'mad4b_declarative_manifest_contract_invalid', 'Declarative adapter manifest contract is invalid.' );
		if ( self::SCHEMA_VERSION !== (int) ( isset( $manifest['schema_version'] ) ? $manifest['schema_version'] : 0 ) ) return new WP_Error( 'mad4b_declarative_manifest_schema_version_invalid', 'Declarative adapter manifest schema version is not supported.' );

		$id = sanitize_key( isset( $manifest['manifest_id'] ) ? (string) $manifest['manifest_id'] : '' );
		$version = isset( $manifest['manifest_version'] ) ? trim( (string) $manifest['manifest_version'] ) : '';
		$provider = sanitize_key( isset( $manifest['provider_id'] ) ? (string) $manifest['provider_id'] : '' );
		$capability = isset( $manifest['capability_id'] ) ? strtolower( trim( (string) $manifest['capability_id'] ) ) : '';
		if ( '' === $id || strlen( $id ) > 96 ) return new WP_Error( 'mad4b_declarative_manifest_id_invalid', 'Manifest id is required.' );
		if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) return new WP_Error( 'mad4b_declarative_manifest_version_invalid', 'Manifest version must be an explicit semantic version.' );
		if ( '' === $provider || strlen( $provider ) > 64 ) return new WP_Error( 'mad4b_declarative_manifest_provider_invalid', 'Exact provider id is required.' );
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]{0,99}$/', $capability ) ) return new WP_Error( 'mad4b_declarative_manifest_capability_invalid', 'Exact capability id is required.' );

		$artifact = strtolower( trim( (string) ( isset( $manifest['artifact_sha256'] ) ? $manifest['artifact_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $artifact ) ) return new WP_Error( 'mad4b_declarative_manifest_artifact_invalid', 'Exact artifact SHA-256 is required.' );

		$executor = isset( $manifest['executor'] ) && is_array( $manifest['executor'] ) ? $manifest['executor'] : array();
		$strategy_id = sanitize_key( isset( $executor['strategy_id'] ) ? (string) $executor['strategy_id'] : '' );
		$target_type = sanitize_key( isset( $executor['target_type'] ) ? (string) $executor['target_type'] : '' );
		$target_id = isset( $executor['target_id'] ) ? strtolower( trim( (string) $executor['target_id'] ) ) : '';
		$strategies = self::strategies();
		if ( ! isset( $strategies[ $strategy_id ] ) ) return new WP_Error( 'mad4b_declarative_manifest_strategy_unreviewed', 'Executor strategy is not in the reviewed typed strategy registry.' );
		if ( 'ability' !== $target_type || 'ability' !== $strategies[ $strategy_id ]['target_type'] ) return new WP_Error( 'mad4b_declarative_manifest_target_type_denied', 'Only exact canonical Ability targets are supported.' );
		if ( 1 !== preg_match( '#^[a-z0-9][a-z0-9._-]{0,99}/[a-z0-9][a-z0-9._\/-]{0,159}$#', $target_id ) ) return new WP_Error( 'mad4b_declarative_manifest_target_invalid', 'Exact canonical Ability target is invalid.' );
		if ( self::contains_forbidden_token( $target_id ) ) return new WP_Error( 'mad4b_declarative_manifest_target_forbidden', 'Manifest target attempts to reference a forbidden execution primitive.' );

		$generation = isset( $manifest['runtime_generation'] ) && is_array( $manifest['runtime_generation'] ) ? $manifest['runtime_generation'] : array();
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Generation_Fence' ) ) return new WP_Error( 'mad4b_declarative_manifest_generation_unavailable', 'Runtime generation fence is unavailable.' );
		$generation_status = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $generation );
		if ( is_wp_error( $generation_status ) ) return $generation_status;

		$binding = isset( $manifest['capability_descriptor_binding'] ) && is_array( $manifest['capability_descriptor_binding'] ) ? $manifest['capability_descriptor_binding'] : array();
		if ( ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' ) ) return new WP_Error( 'mad4b_declarative_manifest_capability_registry_unavailable', 'Capability Descriptor registry is unavailable.' );
		$binding_status = MAD4B_SCP_Capability_Descriptor_Registry::assert_binding( $target_id, $binding, 'declarative_adapter_manifest' );
		if ( is_wp_error( $binding_status ) ) return $binding_status;

		if ( ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ) return new WP_Error( 'mad4b_declarative_manifest_provider_certification_unavailable', 'Provider certification is unavailable.' );
		$assessment = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( $provider );
		if ( is_wp_error( $assessment ) || ! is_array( $assessment ) ) return is_wp_error( $assessment ) ? $assessment : new WP_Error( 'mad4b_declarative_manifest_provider_assessment_invalid', 'Provider assessment is unavailable.' );
		$current_artifact = strtolower( trim( (string) ( isset( $assessment['artifact']['runtime_artifact_fingerprint'] ) ? $assessment['artifact']['runtime_artifact_fingerprint'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $current_artifact ) || ! hash_equals( $artifact, $current_artifact ) ) return new WP_Error( 'mad4b_declarative_manifest_artifact_drift', 'Manifest artifact binding no longer matches the live certified provider artifact.' );

		$schemas = array( 'input_schema', 'output_schema', 'readback', 'rollback' );
		foreach ( $schemas as $schema_key ) {
			if ( ! isset( $manifest[ $schema_key ] ) || ! is_array( $manifest[ $schema_key ] ) ) return new WP_Error( 'mad4b_declarative_manifest_schema_missing', 'Declarative manifest schema section is missing.', array( 'section'=>$schema_key ) );
			$bounded = self::bounded_json( $manifest[ $schema_key ], self::MAX_SCHEMA_BYTES );
			if ( is_wp_error( $bounded ) ) return $bounded;
			if ( self::contains_forbidden_structure( $manifest[ $schema_key ] ) ) return new WP_Error( 'mad4b_declarative_manifest_embedded_code_denied', 'Manifest schema contains a forbidden executable/symbol/route primitive.', array( 'section'=>$schema_key ) );
		}

		$preconditions = isset( $manifest['preconditions'] ) && is_array( $manifest['preconditions'] ) ? array_values( $manifest['preconditions'] ) : array();
		if ( count( $preconditions ) > self::MAX_PRECONDITIONS ) return new WP_Error( 'mad4b_declarative_manifest_preconditions_excessive', 'Manifest contains too many preconditions.' );
		if ( self::contains_forbidden_structure( $preconditions ) ) return new WP_Error( 'mad4b_declarative_manifest_precondition_code_denied', 'Preconditions may not contain executable/symbol/route primitives.' );

		$effects = isset( $manifest['effects'] ) && is_array( $manifest['effects'] ) ? array_values( $manifest['effects'] ) : array();
		if ( count( $effects ) > self::MAX_EFFECTS ) return new WP_Error( 'mad4b_declarative_manifest_effects_excessive', 'Manifest contains too many effects.' );
		foreach ( $effects as $effect ) {
			if ( ! is_array( $effect ) ) return new WP_Error( 'mad4b_declarative_manifest_effect_invalid', 'Manifest effect must be an object.' );
			$type = sanitize_key( isset( $effect['type'] ) ? (string) $effect['type'] : '' );
			if ( ! in_array( $type, array( 'none','local_read','remote_read','local_write' ), true ) ) return new WP_Error( 'mad4b_declarative_manifest_effect_type_denied', 'Manifest effect type is not supported by the declarative adapter interpreter.' );
			if ( ! empty( $effect['authority_effect'] ) || ! empty( $effect['grant_effect'] ) || ! empty( $effect['tool_mount_effect'] ) ) return new WP_Error( 'mad4b_declarative_manifest_authority_effect_denied', 'Manifest may not create authority, grants or tool mounts.' );
			if ( ! empty( $effect['secret_access'] ) ) return new WP_Error( 'mad4b_declarative_manifest_secret_effect_denied', 'Manifest may not request secret material.' );
			if ( 'local_write' === $type && empty( $strategies[ $strategy_id ]['mutation_allowed'] ) ) return new WP_Error( 'mad4b_declarative_manifest_write_strategy_mismatch', 'Write effect requires an explicitly reviewed write strategy.' );
		}
		if ( self::contains_forbidden_structure( $effects ) ) return new WP_Error( 'mad4b_declarative_manifest_effect_code_denied', 'Effects may not contain executable/symbol/route primitives.' );

		$egress = isset( $manifest['egress'] ) && is_array( $manifest['egress'] ) ? $manifest['egress'] : array();
		$egress_mode = sanitize_key( isset( $egress['mode'] ) ? (string) $egress['mode'] : 'none' );
		if ( ! in_array( $egress_mode, array( 'none','certified_purpose' ), true ) ) return new WP_Error( 'mad4b_declarative_manifest_egress_mode_denied', 'Manifest egress mode is invalid.' );
		if ( 'certified_purpose' === $egress_mode ) {
			if ( empty( $strategies[ $strategy_id ]['external_effect_allowed'] ) ) return new WP_Error( 'mad4b_declarative_manifest_egress_strategy_denied', 'Selected strategy does not permit external egress.' );
			$purpose = sanitize_key( isset( $egress['purpose'] ) ? (string) $egress['purpose'] : '' );
			if ( '' === $purpose ) return new WP_Error( 'mad4b_declarative_manifest_egress_purpose_required', 'Certified egress requires an existing purpose id.' );
		}
		foreach ( array( 'url','uri','host','endpoint','route','method','headers' ) as $forbidden_egress_key ) {
			if ( array_key_exists( $forbidden_egress_key, $egress ) ) return new WP_Error( 'mad4b_declarative_manifest_generic_http_denied', 'Manifest may not define generic HTTP endpoints or transport details.' );
		}

		$cost = isset( $manifest['cost'] ) && is_array( $manifest['cost'] ) ? $manifest['cost'] : array();
		if ( ! is_int( $cost['provider_units'] ?? null ) || ! is_int( $cost['currency_minor_units'] ?? null ) ) return new WP_Error( 'mad4b_declarative_manifest_cost_invalid', 'Manifest cost must use explicit non-negative integer units.' );
		$provider_units = isset( $cost['provider_units'] ) ? (int) $cost['provider_units'] : 0;
		$currency_minor_units = isset( $cost['currency_minor_units'] ) ? (int) $cost['currency_minor_units'] : 0;
		if ( $provider_units < 0 || $currency_minor_units < 0 ) return new WP_Error( 'mad4b_declarative_manifest_cost_invalid', 'Manifest cost cannot be negative.' );
		if ( ( $provider_units > 0 || $currency_minor_units > 0 ) && empty( $strategies[ $strategy_id ]['nonzero_cost_allowed'] ) ) return new WP_Error( 'mad4b_declarative_manifest_nonzero_cost_denied', 'Selected strategy is not reviewed for billable manifest-driven execution.' );

		if ( class_exists( 'MAD4B_SCP_Structural_Redaction' ) ) {
			$classification = MAD4B_SCP_Structural_Redaction::classify( $manifest, 'declarative_adapter_manifest' );
			if ( is_array( $classification ) && 'sensitive_redacted' === ( isset( $classification['classification'] ) ? (string) $classification['classification'] : '' ) ) return new WP_Error( 'mad4b_declarative_manifest_secret_material_denied', 'Signed manifest contains secret-like material and cannot be interpreted.' );
		}

		$expires_at = isset( $manifest['expires_at'] ) ? (int) $manifest['expires_at'] : 0;
		if ( $expires_at <= 0 || $expires_at <= self::now_epoch() ) return new WP_Error( 'mad4b_declarative_manifest_expired', 'Declarative adapter manifest is expired.' );

		$digest = self::payload_sha256( $manifest );
		$declared_digest = strtolower( trim( (string) ( isset( $manifest['manifest_sha256'] ) ? $manifest['manifest_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $declared_digest ) || ! hash_equals( $digest, $declared_digest ) ) return new WP_Error( 'mad4b_declarative_manifest_digest_mismatch', 'Declarative adapter manifest digest does not match canonical payload.' );

		$signature = isset( $manifest['signature'] ) && is_array( $manifest['signature'] ) ? $manifest['signature'] : array();
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return new WP_Error( 'mad4b_declarative_manifest_signature_verifier_unavailable', 'Manifest signature verifier is unavailable.' );
		$verified = MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose( $signature, $digest, 'certification_pack' );
		if ( is_wp_error( $verified ) ) return $verified;

		$out = $manifest;
		$out['manifest_id'] = $id;
		$out['manifest_version'] = $version;
		$out['provider_id'] = $provider;
		$out['capability_id'] = $capability;
		$out['artifact_sha256'] = $artifact;
		$out['executor'] = array( 'strategy_id'=>$strategy_id, 'target_type'=>'ability', 'target_id'=>$target_id );
		$out['preconditions'] = $preconditions;
		$out['effects'] = $effects;
		$out['egress'] = array( 'mode'=>$egress_mode, 'purpose'=>isset( $egress['purpose'] ) ? sanitize_key( (string) $egress['purpose'] ) : '' );
		$out['cost'] = array( 'provider_units'=>$provider_units, 'currency_minor_units'=>$currency_minor_units );
		$out['manifest_sha256'] = $digest;
		return $out;
	}

	public static function payload_sha256( array $manifest ) {
		unset( $manifest['manifest_sha256'], $manifest['signature'] );
		$json = self::stable_json( $manifest );
		return '' === $json ? '' : hash( 'sha256', $json );
	}

	public static function can_manage( $input = null ) {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	private static function bounded_json( $value, $max_bytes ) {
		$json = self::stable_json( $value );
		if ( '' === $json ) return new WP_Error( 'mad4b_declarative_manifest_json_invalid', 'Manifest value cannot be canonically encoded.' );
		if ( strlen( $json ) > (int) $max_bytes ) return new WP_Error( 'mad4b_declarative_manifest_value_too_large', 'Manifest value exceeds bounded canonical size.' );
		return $json;
	}

	private static function contains_forbidden_structure( $value, $depth = 0 ) {
		if ( $depth > 12 ) return true;
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( self::contains_forbidden_token( (string) $key ) ) return true;
				if ( self::contains_forbidden_structure( $item, $depth + 1 ) ) return true;
			}
			return false;
		}
		if ( is_object( $value ) ) return true;
		if ( is_string( $value ) ) return self::contains_forbidden_token( $value );
		return false;
	}

	private static function contains_forbidden_token( $value ) {
		$v = strtolower( preg_replace( '/\s+/', '', (string) $value ) );
		foreach ( array(
			'e' . 'val(', 'ass' . 'ert(', 'shell_exec', 'ex' . 'ec(', 'sys' . 'tem(', 'pass' . 'thru(', 'proc_open', 'popen(',
			'curl_', 'wp_remote_', 'http://', 'https://', '<?php', 'function:', 'class:', 'route:',
			'call_user_func', 'call_user_func_array', 'include(', 'require(', 'template_php', 'raw_sql',
		) as $needle ) {
			if ( false !== strpos( $v, $needle ) ) return true;
		}
		return false;
	}

	private static function stable_json( $value ) {
		$value = self::canonicalize( $value );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			$is_list = array() === $value || $keys === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) $out[] = self::canonicalize( $item );
				return $out;
			}
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) $out[ (string) $key ] = self::canonicalize( $value[ $key ] );
			return $out;
		}
		if ( is_object( $value ) ) return self::canonicalize( get_object_vars( $value ) );
		return $value;
	}

	private static function now_epoch() {
		return class_exists( 'MAD4B_SCP_Time_Policy' ) && method_exists( 'MAD4B_SCP_Time_Policy', 'now_epoch' ) ? (int) MAD4B_SCP_Time_Policy::now_epoch() : time();
	}
}

MAD4B_SCP_Declarative_Adapter_Manifest::boot();
