<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Default bounded provider autopilot.
 *
 * Generates deterministic adapter candidates and shadow/read certification
 * proposals from discovery evidence. It never writes code, registers generated
 * adapters, creates authority, enables mutation or promotes Production.
 */
final class MAD4B_SCP_Provider_Autopilot {
	const CONTRACT = 'mad4b.provider-autopilot.v1';
	const ADAPTER_CANDIDATE_CONTRACT = 'mad4b.generated-adapter-candidate.v1';
	const SHADOW_CERT_CONTRACT = 'mad4b.provider-shadow-certification.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/provider-autopilot-status' ) ) {
			wp_register_ability( 'mad4b/provider-autopilot-status', array(
				'label' => 'Provider Autopilot Status',
				'description' => 'Inspect the default bounded provider autopilot mode and its non-mutating promotion limits.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/provider-autopilot-promotion-plan' ) ) {
			wp_register_ability( 'mad4b/provider-autopilot-promotion-plan', array(
				'label' => 'Provider Autopilot Promotion Plan',
				'description' => 'Plan the exact evidence and governed gates required to move an installed provider from its current L0-L4 support level without performing promotion or mutation.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'promotion_plan' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'plugin' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
						'target_level' => array( 'type' => 'string', 'enum' => array( 'L1_lifecycle', 'L2_read', 'L3_governed_write', 'L4_certified_governed' ) ),
					),
					'required' => array( 'plugin' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/provider-autopilot-plan' ) ) {
			wp_register_ability( 'mad4b/provider-autopilot-plan', array(
				'label' => 'Provider Autopilot Plan',
				'description' => 'Generate deterministic adapter candidates and shadow/read certification proposals for installed plugins without writing files or granting authority.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'plan' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'plugin' => array( 'type' => 'string', 'maxLength' => 191 ),
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
	}

	private static function config() {
		if ( class_exists( 'MAD4B_SCP_Operation_Registry' ) && method_exists( 'MAD4B_SCP_Operation_Registry', 'autopilot_config' ) ) {
			$config = MAD4B_SCP_Operation_Registry::autopilot_config();
			return is_array( $config ) ? $config : array();
		}
		return array();
	}

	private static function environment() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' ) ) {
			$environment = sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
			if ( '' !== $environment ) return $environment;
		}
		return defined( 'WP_ENVIRONMENT_TYPE' ) ? sanitize_key( (string) WP_ENVIRONMENT_TYPE ) : 'production';
	}

	private static function effective_mode( array $config ) {
		$environment = self::environment();
		$modes = isset( $config['environments'] ) && is_array( $config['environments'] ) ? $config['environments'] : array();
		$mode = isset( $modes[ $environment ] ) ? sanitize_key( (string) $modes[ $environment ] ) : sanitize_key( isset( $config['mode'] ) ? (string) $config['mode'] : 'observe_propose_only' );
		return in_array( $mode, array( 'shadow_auto', 'observe_propose_only' ), true ) ? $mode : 'observe_propose_only';
	}

	public static function status( $input = null ) {
		$config = self::config();
		$enabled = ! empty( $config['enabled_by_default'] );
		$environment = self::environment();
		$mode = self::effective_mode( $config );
		return array(
			'contract' => self::CONTRACT,
			'enabled' => $enabled,
			'enabled_by_default' => $enabled,
			'environment' => $environment,
			'effective_mode' => $mode,
			'auto_generate_adapter_candidate' => $enabled && ! empty( $config['auto_generate_adapter_candidate'] ),
			'auto_shadow_certify_provider' => $enabled && ! empty( $config['auto_shadow_certify_provider'] ),
			'auto_materialize_candidate_code' => false,
			'auto_register_generated_adapter' => false,
			'auto_write_certification' => false,
			'auto_create_authority' => false,
			'auto_enable_mutation' => false,
			'max_automatic_support_level' => isset( $config['max_automatic_support_level'] ) ? (string) $config['max_automatic_support_level'] : 'L1_lifecycle',
			'promotion_required_for' => isset( $config['promotion_required_for'] ) && is_array( $config['promotion_required_for'] ) ? array_values( $config['promotion_required_for'] ) : array(),
			'production_behavior' => 'observe_propose_only',
			'mutation_performed' => false,
			'authority_created' => false,
		);
	}

	private static function normalize_plugin_file( $plugin_file ) {
		$plugin_file = ltrim( wp_normalize_path( sanitize_text_field( (string) $plugin_file ) ), '/' );
		return false !== strpos( $plugin_file, '..' ) ? '' : $plugin_file;
	}

	private static function class_name_for( array $candidate ) {
		$seed = isset( $candidate['family'] ) && 'unknown' !== $candidate['family']
			? (string) $candidate['family']
			: ( isset( $candidate['plugin_file'] ) ? dirname( (string) $candidate['plugin_file'] ) : 'provider' );
		$seed = preg_replace( '/[^A-Za-z0-9]+/', ' ', (string) $seed );
		$seed = str_replace( ' ', '_', ucwords( strtolower( trim( $seed ) ) ) );
		$seed = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $seed );
		if ( '' === $seed || ctype_digit( substr( $seed, 0, 1 ) ) ) $seed = 'Generated_' . $seed;
		return 'MAD4B_SCP_Generated_' . $seed . '_Adapter_Candidate';
	}

	private static function adapter_id_for( array $candidate ) {
		$base = isset( $candidate['family'] ) && 'unknown' !== $candidate['family']
			? sanitize_key( (string) $candidate['family'] )
			: sanitize_key( basename( dirname( isset( $candidate['plugin_file'] ) ? (string) $candidate['plugin_file'] : 'provider' ) ) );
		if ( '' === $base || '.' === $base ) $base = 'provider';
		return 'generated-' . $base;
	}

	private static function adapter_candidate( array $candidate, $mode ) {
		$plugin_file = self::normalize_plugin_file( isset( $candidate['plugin_file'] ) ? $candidate['plugin_file'] : '' );
		$adapter_id = self::adapter_id_for( $candidate );
		$class_name = self::class_name_for( $candidate );
		$plugin_name = isset( $candidate['plugin_name'] ) && '' !== trim( (string) $candidate['plugin_name'] ) ? sanitize_text_field( (string) $candidate['plugin_name'] ) : $adapter_id;
		$skeleton = "<?php\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n\n"
			. "final class " . $class_name . " extends MAD4B_SCP_Adapter_Base {\n"
			. "\tpublic function id() { return '" . $adapter_id . "'; }\n"
			. "\tpublic function label() { return " . var_export( $plugin_name . ' Candidate', true ) . "; }\n"
			. "\tpublic function is_available() { if ( ! function_exists( 'is_plugin_active' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php'; return is_plugin_active( " . var_export( $plugin_file, true ) . " ); }\n"
			. "\tpublic function ability_names() { return array( 'read' => array(), 'content' => array(), 'admin' => array() ); }\n"
			. "\tpublic function register_abilities() { /* Candidate only: no abilities are registered automatically. */ }\n"
			. "}\n";
		$descriptor = array(
			'contract' => self::ADAPTER_CANDIDATE_CONTRACT,
			'adapter_id' => $adapter_id,
			'class_name' => $class_name,
			'plugin_file' => $plugin_file,
			'plugin_version' => isset( $candidate['plugin_version'] ) ? (string) $candidate['plugin_version'] : '',
			'mode' => $mode,
			'candidate_only' => true,
			'executable' => false,
			'auto_registered' => false,
			'declared_abilities' => array(),
			'write_abilities' => array(),
			'generated_php_sha256' => hash( 'sha256', $skeleton ),
			'generated_php' => $skeleton,
		);
		$encoded = wp_json_encode( $descriptor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$descriptor['candidate_sha256'] = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		return $descriptor;
	}

	private static function shadow_certification( array $candidate, $mode, array $adapter_candidate ) {
		$active = ! empty( $candidate['active'] );
		$side_channel_blocked = ! empty( $candidate['side_channel_blocked'] );
		$identity_ok = '' !== self::normalize_plugin_file( isset( $candidate['plugin_file'] ) ? $candidate['plugin_file'] : '' )
			&& '' !== trim( isset( $candidate['plugin_version'] ) ? (string) $candidate['plugin_version'] : '' );
		$shadow_ok = $identity_ok && $active && ! $side_channel_blocked;
		$cert = array(
			'contract' => self::SHADOW_CERT_CONTRACT,
			'certification_level' => $shadow_ok ? 'SHADOW_IDENTITY_CERTIFIED' : 'SHADOW_DISCOVERY_ONLY',
			'activation_stage' => 'shadow',
			'identity_certified' => $identity_ok,
			'provider_active' => $active,
			'side_channel_clear' => ! $side_channel_blocked,
			'adapter_candidate_sha256' => isset( $adapter_candidate['candidate_sha256'] ) ? (string) $adapter_candidate['candidate_sha256'] : '',
			'read_execution_eligible' => false,
			'write_eligible' => false,
			'canary_eligible' => false,
			'max_automatic_support_level' => 'L1_lifecycle',
			'promotion_required_for_read_execution' => true,
			'promotion_required_for_write' => true,
			'mode' => $mode,
			'persisted' => false,
			'authority_created' => false,
		);
		$encoded = wp_json_encode( $cert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$cert['certification_sha256'] = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		return $cert;
	}

	public static function proposal_for_candidate( array $candidate ) {
		$config = self::config();
		$enabled = ! empty( $config['enabled_by_default'] );
		$mode = self::effective_mode( $config );
		$adapter = $enabled && ! empty( $config['auto_generate_adapter_candidate'] )
			? self::adapter_candidate( $candidate, $mode )
			: array();
		$cert = $enabled && ! empty( $config['auto_shadow_certify_provider'] ) && ! empty( $adapter )
			? self::shadow_certification( $candidate, $mode, $adapter )
			: array();
		return array(
			'contract' => self::CONTRACT,
			'enabled' => $enabled,
			'enabled_by_default' => $enabled,
			'effective_mode' => $mode,
			'adapter_candidate' => $adapter,
			'shadow_certification' => $cert,
			'auto_materialized' => false,
			'auto_registered' => false,
			'auto_write_certified' => false,
			'auto_authority_created' => false,
			'auto_mutation_enabled' => false,
			'next_gate' => empty( $adapter ) ? 'manual_discovery' : 'review_or_shadow_validation',
		);
	}

	public static function promotion_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$plugin = self::normalize_plugin_file( isset( $input['plugin'] ) ? $input['plugin'] : '' );
		if ( '' === $plugin ) return new WP_Error( 'mad4b_provider_autopilot_plugin_required', 'Installed plugin file is required for promotion planning.' );
		if ( ! class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ) return new WP_Error( 'mad4b_provider_autopilot_discovery_unavailable', 'Plugin discovery is unavailable.' );

		$candidate = MAD4B_SCP_Plugin_Discovery::provider_candidate_for( $plugin );
		if ( is_wp_error( $candidate ) ) return $candidate;

		$levels = array( 'L0_inventory', 'L1_lifecycle', 'L2_read', 'L3_governed_write', 'L4_certified_governed' );
		$current = isset( $candidate['support_level'] ) ? (string) $candidate['support_level'] : 'L0_inventory';
		$current_index = array_search( $current, $levels, true );
		if ( false === $current_index ) $current_index = 0;
		$target = isset( $input['target_level'] ) && '' !== (string) $input['target_level'] ? (string) $input['target_level'] : ( isset( $levels[ $current_index + 1 ] ) ? $levels[ $current_index + 1 ] : $current );
		$target_index = array_search( $target, $levels, true );
		if ( false === $target_index ) return new WP_Error( 'mad4b_provider_autopilot_target_level_invalid', 'Requested support level is invalid.' );
		if ( $target_index < $current_index ) return new WP_Error( 'mad4b_provider_autopilot_promotion_downgrade_invalid', 'Promotion planner does not plan support-level downgrades.' );

		$requirements = array();
		$blockers = array();
		$automatic_steps = array();
		$governed_steps = array();
		$autopilot = isset( $candidate['autopilot'] ) && is_array( $candidate['autopilot'] ) ? $candidate['autopilot'] : self::proposal_for_candidate( $candidate );

		if ( $target_index >= 1 ) {
			$requirements[] = 'installed_plugin_identity';
			$automatic_steps[] = 'inventory_and_lifecycle_classification';
		}
		if ( $target_index >= 2 ) {
			$requirements[] = 'runtime_adapter_available';
			$requirements[] = 'bounded_read_abilities_declared';
			$requirements[] = 'side_channel_clear';
			$governed_steps[] = 'materialize_and_review_generated_adapter_or_register_existing_adapter';
			$governed_steps[] = 'runtime_read_contract_validation';
			if ( empty( $candidate['adapter_runtime_available'] ) ) $blockers[] = 'adapter_runtime_unavailable';
			if ( ! empty( $candidate['side_channel_blocked'] ) ) $blockers[] = 'provider_side_channel_blocked';
		}
		if ( $target_index >= 3 ) {
			$requirements[] = 'provider_certification_ok';
			$requirements[] = 'reversible_write_contracts';
			$requirements[] = 'capability_release_ring';
			$governed_steps[] = 'behavioral_recertification';
			$governed_steps[] = 'reversible_contract_verification';
			if ( empty( $candidate['provider_certification_ok'] ) ) $blockers[] = 'provider_certification_required';
			if ( empty( $candidate['reversible_contract_count'] ) ) $blockers[] = 'reversible_contract_required';
		}
		if ( $target_index >= 4 ) {
			$requirements[] = 'functional_ready';
			$requirements[] = 'exact_operation_plan';
			$requirements[] = 'authorization_boundary';
			$requirements[] = 'readback_and_reconciliation';
			$governed_steps[] = 'functional_acceptance';
			if ( 'functional_ready' !== ( isset( $candidate['functional_state'] ) ? (string) $candidate['functional_state'] : '' ) ) $blockers[] = 'functional_acceptance_required';
		}

		$requirements = array_values( array_unique( $requirements ) );
		$blockers = array_values( array_unique( $blockers ) );
		$automatic_steps = array_values( array_unique( $automatic_steps ) );
		$governed_steps = array_values( array_unique( $governed_steps ) );
		sort( $requirements, SORT_STRING );
		sort( $blockers, SORT_STRING );
		sort( $automatic_steps, SORT_STRING );
		sort( $governed_steps, SORT_STRING );

		$result = array(
			'contract' => 'mad4b.provider-autopilot-promotion-plan.v1',
			'plugin_file' => $plugin,
			'candidate_fingerprint' => isset( $candidate['candidate_fingerprint'] ) ? (string) $candidate['candidate_fingerprint'] : '',
			'current_level' => $current,
			'target_level' => $target,
			'already_at_or_above_target' => $current_index >= $target_index,
			'eligible_now' => empty( $blockers ),
			'requirements' => $requirements,
			'blockers' => $blockers,
			'automatic_steps' => $automatic_steps,
			'governed_steps' => $governed_steps,
			'autopilot_candidate_sha256' => isset( $autopilot['adapter_candidate']['candidate_sha256'] ) ? (string) $autopilot['adapter_candidate']['candidate_sha256'] : '',
			'shadow_certification_sha256' => isset( $autopilot['shadow_certification']['certification_sha256'] ) ? (string) $autopilot['shadow_certification']['certification_sha256'] : '',
			'auto_materialize_candidate_code' => false,
			'auto_register_generated_adapter' => false,
			'auto_write_certification' => false,
			'auto_create_authority' => false,
			'auto_enable_mutation' => false,
			'mutation_performed' => false,
			'authority_created' => false,
			'next_action' => empty( $blockers ) ? 'enter_governed_promotion_lane' : 'satisfy_blockers_then_recompile',
		);
		$encoded = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$result['promotion_plan_sha256'] = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		return $result;
	}

	public static function plan( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ) return new WP_Error( 'mad4b_provider_autopilot_discovery_unavailable', 'Plugin discovery is unavailable.' );
		$matrix = MAD4B_SCP_Plugin_Discovery::provider_candidate_matrix();
		$plugin = self::normalize_plugin_file( isset( $input['plugin'] ) ? $input['plugin'] : '' );
		$items = array();
		foreach ( isset( $matrix['items'] ) && is_array( $matrix['items'] ) ? $matrix['items'] : array() as $candidate ) {
			if ( '' !== $plugin && ( ! isset( $candidate['plugin_file'] ) || ! hash_equals( $plugin, (string) $candidate['plugin_file'] ) ) ) continue;
			$items[] = array(
				'plugin_file' => isset( $candidate['plugin_file'] ) ? (string) $candidate['plugin_file'] : '',
				'support_level' => isset( $candidate['support_level'] ) ? (string) $candidate['support_level'] : '',
				'candidate_fingerprint' => isset( $candidate['candidate_fingerprint'] ) ? (string) $candidate['candidate_fingerprint'] : '',
				'autopilot' => self::proposal_for_candidate( $candidate ),
			);
		}
		if ( '' !== $plugin && empty( $items ) ) return new WP_Error( 'mad4b_provider_autopilot_plugin_not_found', 'Requested installed plugin was not found in provider discovery.' );
		$result = array(
			'contract' => self::CONTRACT,
			'status' => self::status(),
			'items' => $items,
			'count' => count( $items ),
			'mutation_performed' => false,
			'authority_created' => false,
		);
		$encoded = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$result['plan_sha256'] = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		return $result;
	}
}
