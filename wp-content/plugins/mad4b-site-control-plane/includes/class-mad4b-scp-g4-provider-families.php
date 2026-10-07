<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Feature 007 G4 typed provider-family catalog.
 *
 * This is a non-authorizing registry and planning surface. It never calls a
 * provider, never mutates WordPress, never restores data and never performs a
 * financial/external effect. Provider-specific execution remains behind exact
 * canonical abilities, certification, impact/approval and readback contracts.
 */
final class MAD4B_SCP_G4_Provider_Families {
	const CATALOG_CONTRACT = 'mad4b.g4-provider-family-catalog.v1';
	const READINESS_CONTRACT = 'mad4b.g4-provider-family-readiness.v1';
	const PLAN_CONTRACT = 'mad4b.g4-provider-family-plan.v1';
	const READINESS_ABILITY = 'mad4b/g4-provider-family-readiness';
	const PLAN_ABILITY = 'mad4b/g4-provider-family-plan';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 42 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register_read( self::READINESS_ABILITY, 'Inspect G4 Provider Family Readiness', 'readiness' );
		self::register_read( self::PLAN_ABILITY, 'Build G4 Provider Family Plan', 'plan' );
	}

	private static function register_read( $name, $label, $method ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; non-authorizing, no provider execution and no mutation.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'mad4b_contract' => self::READINESS_ABILITY === $name ? self::READINESS_CONTRACT : self::PLAN_CONTRACT,
				),
			)
		);
	}

	public static function catalog() {
		return array(
			'contract' => self::CATALOG_CONTRACT,
			'families' => array(
				'forms' => array(
					'family' => 'CPFORMS',
					'capability_ids' => array( 'CE015', 'CE016' ),
					'providers' => array(
						self::provider( 'contact-form-7', 'plugin', array(), array( 'contact-form-7' ) ),
						self::provider( 'wpforms', 'plugin', array(), array( 'wpforms-lite', 'wpforms' ) ),
						self::provider( 'gravityforms', 'plugin', array(), array( 'gravityforms' ) ),
						self::provider( 'fluentforms', 'plugin', array( 'fluentforms' ), array( 'fluentform', 'fluentformpro' ) ),
						self::provider( 'jetformbuilder', 'plugin', array( 'jetformbuilder' ), array( 'jetformbuilder', 'jet-form-builder' ) ),
					),
					'operations' => array(
						'discover' => self::operation( 'read', 'public_configuration', false, false, false, array() ),
						'inventory' => self::operation( 'read', 'public_configuration', false, false, false, array( 'bounded_pagination' ) ),
						'schema_read' => self::operation( 'read', 'public_configuration', false, false, false, array() ),
						'config_plan' => self::operation( 'reviewed_write_plan', 'configuration', true, false, false, array( 'provider_serialization', 'exact_readback' ) ),
						'submission_read' => self::operation( 'sensitive_read', 'submission_pii', false, false, false, array( 'separate_pii_authority', 'field_redaction', 'bounded_pagination', 'retention_policy' ) ),
						'submission_export_plan' => self::operation( 'high_risk_explicit_gate', 'submission_pii', false, true, false, array( 'separate_pii_authority', 'export_review', 'retention_policy' ) ),
						'submission_delete_plan' => self::operation( 'high_risk_explicit_gate', 'submission_pii', true, true, true, array( 'separate_pii_authority', 'deletion_review', 'no_undo_claim' ) ),
					),
				),
				'commerce' => array(
					'family' => 'CPWC',
					'capability_ids' => array( 'CE017', 'CE018', 'CE019' ),
					'providers' => array( self::provider( 'woocommerce', 'plugin', array( 'woocommerce' ), array( 'woocommerce' ) ) ),
					'operations' => array(
						'discover' => self::operation( 'read', 'public_configuration', false, false, false, array( 'hpos_compatibility' ) ),
						'catalog_read' => self::operation( 'read', 'commerce_catalog', false, false, false, array( 'bounded_pagination' ) ),
						'order_read' => self::operation( 'sensitive_read', 'order_private', false, false, false, array( 'object_level_authority', 'pii_masking', 'bounded_pagination' ) ),
						'customer_read' => self::operation( 'sensitive_read', 'customer_pii', false, false, false, array( 'object_level_authority', 'pii_masking', 'bounded_pagination' ) ),
						'catalog_change_plan' => self::operation( 'reviewed_write_plan', 'commerce_catalog', true, false, false, array( 'disposable_canary', 'hook_aware_readback' ) ),
						'stock_change_plan' => self::operation( 'high_risk_explicit_gate', 'inventory', true, false, false, array( 'concurrency_check', 'stock_readback', 'hook_aware_readback' ) ),
						'refund_plan' => self::operation( 'irreversible_external_effect', 'financial', true, true, true, array( 'financial_effect_gate', 'no_undo_claim', 'manual_execution_only' ) ),
						'gateway_change_plan' => self::operation( 'irreversible_external_effect', 'financial_configuration', true, true, true, array( 'financial_effect_gate', 'manual_execution_only' ) ),
					),
				),
				'builders' => array(
					'family' => 'CPBUILD',
					'capability_ids' => array( 'CE020', 'CE021', 'CE022' ),
					'providers' => array(
						self::provider( 'elementor', 'plugin', array( 'elementor' ), array( 'elementor', 'elementor-pro' ) ),
						self::provider( 'divi', 'plugin', array(), array( 'divi-builder', 'divi' ) ),
						self::provider( 'kadence', 'plugin', array(), array( 'kadence-blocks', 'kadence-pro' ) ),
						self::provider( 'wordpress-core', 'core' ),
					),
					'operations' => array(
						'discover' => self::operation( 'read', 'builder_metadata', false, false, false, array() ),
						'tree_read' => self::operation( 'read', 'content_structure', false, false, false, array( 'provider_native_schema' ) ),
						'clone_plan' => self::operation( 'reviewed_write_plan', 'content_structure', true, false, false, array( 'fresh_ids', 'revision_check', 'editor_lock_check', 'semantic_readback' ) ),
						'content_change_plan' => self::operation( 'reviewed_write_plan', 'content', true, false, false, array( 'revision_check', 'editor_lock_check', 'field_ownership', 'semantic_readback' ) ),
						'template_change_plan' => self::operation( 'high_risk_explicit_gate', 'template_scope', true, false, false, array( 'template_scope_check', 'dynamic_data_leakage_check', 'partial_rollback_disclosure' ) ),
					),
				),
				'site-operations' => array(
					'family' => 'CPOPS',
					'capability_ids' => array( 'CE032', 'CE033', 'CE034', 'CE035' ),
					'providers' => array(
						self::provider( 'updraftplus', 'plugin', array(), array( 'updraftplus' ) ),
						self::provider( 'w3-total-cache', 'plugin', array(), array( 'w3-total-cache' ) ),
						self::provider( 'all-in-one-wp-migration', 'plugin', array(), array( 'all-in-one-wp-migration' ) ),
						self::provider( 'wordfence', 'plugin', array(), array( 'wordfence' ) ),
						self::provider( 'redirection', 'plugin', array(), array( 'redirection' ) ),
						self::provider( 'litespeed', 'plugin', array( 'litespeed' ), array( 'litespeed-cache' ) ),
					),
					'operations' => array(
						'discover' => self::operation( 'read', 'site_operations', false, false, false, array() ),
						'backup_inventory' => self::operation( 'read', 'backup_metadata', false, false, false, array( 'no_archive_secret_disclosure' ) ),
						'backup_trigger_plan' => self::operation( 'high_risk_explicit_gate', 'backup_operation', true, true, false, array( 'exact_provider_binding', 'readback_required' ) ),
						'restore_readiness' => self::operation( 'high_risk_explicit_gate', 'restore', false, false, false, array( 'restore_epoch', 'current_authority_rebind', 'no_automatic_restore', 'no_time_travel_authority' ) ),
						'migration_artifact_inspect' => self::operation( 'read', 'migration_artifact', false, false, false, array( 'no_installer_disclosure', 'no_credential_disclosure', 'path_bounds' ) ),
						'cache_purge_plan' => self::operation( 'reviewed_write_plan', 'cache', true, true, false, array( 'blast_radius_bound', 'path_url_egress_bound', 'cache_readback' ) ),
						'redirect_plan' => self::operation( 'reviewed_write_plan', 'redirects', true, false, false, array( 'loop_detection', 'language_awareness', 'cache_awareness' ) ),
						'security_status' => self::operation( 'sensitive_read', 'security_logs', false, false, false, array( 'pii_masking', 'bounded_pagination' ) ),
						'security_change_plan' => self::operation( 'high_risk_explicit_gate', 'security_control', true, true, false, array( 'lockout_self_denial_check', 'reviewed_handoff', 'host_install_external' ) ),
					),
				),
				'wordpress-breadth' => array(
					'family' => 'CPCORE',
					'capability_ids' => array( 'CE036', 'CE037', 'CE038' ),
					'providers' => array(
						self::provider( 'wordpress-core', 'core' ),
						self::provider( 'wpml', 'plugin', array(), array( 'sitepress-multilingual-cms', 'wpml-string-translation', 'wpml-translation-management' ) ),
						self::provider( 'polylang', 'plugin', array( 'polylang' ), array( 'polylang', 'polylang-pro' ) ),
						self::provider( 'advanced-custom-fields', 'plugin', array(), array( 'advanced-custom-fields', 'advanced-custom-fields-pro' ) ),
						self::provider( 'buddypress', 'plugin', array(), array( 'buddypress' ) ),
						self::provider( 'the-events-calendar', 'plugin', array(), array( 'the-events-calendar' ) ),
					),
					'operations' => array(
						'discover' => self::operation( 'read', 'wordpress_objects', false, false, false, array() ),
						'object_inventory' => self::operation( 'read', 'wordpress_objects', false, false, false, array( 'bounded_pagination', 'field_ownership' ) ),
						'taxonomy_language_diff' => self::operation( 'read', 'multilingual_hierarchy', false, false, false, array( 'source_language', 'parent_identity', 'duplicate_detection', 'translation_group_evidence' ) ),
						'hierarchy_change_plan' => self::operation( 'reviewed_write_plan', 'multilingual_hierarchy', true, false, false, array( 'cycle_detection', 'orphan_detection', 'field_ownership', 'exact_readback' ) ),
						'media_change_plan' => self::operation( 'reviewed_write_plan', 'media', true, true, false, array( 'malicious_url_denial', 'egress_bounds', 'field_ownership' ) ),
						'menu_change_plan' => self::operation( 'reviewed_write_plan', 'menus', true, false, false, array( 'hierarchy_cycle_denial', 'exact_readback' ) ),
						'private_message_read' => self::operation( 'sensitive_read', 'private_messages', false, false, false, array( 'separate_private_authority', 'bounded_pagination' ) ),
						'event_read' => self::operation( 'read', 'events', false, false, false, array( 'timezone_semantics', 'bounded_pagination' ) ),
					),
				),
			),
			'global_boundaries' => array(
				'production_authorized' => false,
				'breakglass_widened' => false,
				'generic_shell_allowed' => false,
				'generic_raw_sql_allowed' => false,
				'generic_outbound_http_allowed' => false,
				'provider_execution_performed' => false,
				'mutation_performed' => false,
				'restore_execution_performed' => false,
				'financial_effect_performed' => false,
				'authority_created' => false,
				'grant_created' => false,
				'tool_mount_created' => false,
			),
			'authorizing' => false,
		);
	}

	public static function readiness( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$family_id = sanitize_key( isset( $input['family_id'] ) ? (string) $input['family_id'] : '' );
		$provider_id = sanitize_key( isset( $input['provider_id'] ) ? (string) $input['provider_id'] : '' );
		$catalog = self::catalog();
		$families = $catalog['families'];
		if ( '' !== $family_id && ! isset( $families[ $family_id ] ) ) {
			return new WP_Error( 'mad4b_g4_family_unknown', 'G4 provider family is not in the reviewed catalog.' );
		}

		$selected = '' === $family_id ? $families : array( $family_id => $families[ $family_id ] );
		$rows = array();
		$provider_found = '' === $provider_id;
		foreach ( $selected as $id => $family ) {
			foreach ( $family['providers'] as $provider ) {
				if ( '' !== $provider_id && $provider_id !== $provider['provider_id'] ) continue;
				$provider_found = true;
				$rows[] = self::provider_readiness( $id, $family, $provider );
			}
		}
		if ( ! $provider_found ) return new WP_Error( 'mad4b_g4_provider_unknown_for_family', 'Provider is not admitted for the selected G4 family.' );

		return array(
			'contract' => self::READINESS_CONTRACT,
			'family_id' => $family_id,
			'provider_id' => $provider_id,
			'providers' => $rows,
			'provider_count' => count( $rows ),
			'provider_execution_performed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	public static function plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$family_id = sanitize_key( isset( $input['family_id'] ) ? (string) $input['family_id'] : '' );
		$provider_id = sanitize_key( isset( $input['provider_id'] ) ? (string) $input['provider_id'] : '' );
		$operation_id = sanitize_key( isset( $input['operation_id'] ) ? (string) $input['operation_id'] : '' );
		$catalog = self::catalog();
		if ( '' === $family_id || ! isset( $catalog['families'][ $family_id ] ) ) return new WP_Error( 'mad4b_g4_plan_family_invalid', 'Exact G4 family is required.' );
		$family = $catalog['families'][ $family_id ];
		if ( '' === $provider_id || ! self::family_has_provider( $family, $provider_id ) ) return new WP_Error( 'mad4b_g4_plan_provider_invalid', 'Exact admitted provider is required.' );
		if ( '' === $operation_id || ! isset( $family['operations'][ $operation_id ] ) ) return new WP_Error( 'mad4b_g4_plan_operation_invalid', 'Exact reviewed family operation is required.' );

		$operation = $family['operations'][ $operation_id ];
		$readiness = self::readiness( array( 'family_id' => $family_id, 'provider_id' => $provider_id ) );
		if ( is_wp_error( $readiness ) ) return $readiness;
		$provider = $readiness['providers'][0];
		$gates = array_values( $operation['requires'] );
		$certification_state = isset( $provider['certification_state'] ) ? sanitize_key( (string) $provider['certification_state'] ) : 'not_profiled';
		if ( ! in_array( $certification_state, array( 'certified_exact_version', 'core_runtime' ), true ) ) {
			$gates[] = 'provider_profile_certification';
			if ( in_array( $certification_state, array( 'installed_version_unprofiled', 'provider_contract_drift', 'adapter_read_ready_uncertified' ), true ) ) $gates[] = 'provider_exact_version_certification';
			if ( empty( $provider['installed'] ) ) $gates[] = 'provider_runtime_presence';
		}
		if ( in_array( $operation['risk'], array( 'read', 'sensitive_read' ), true ) && empty( $provider['read_surface_ready'] ) && 'core' !== $provider['provider_kind'] ) $gates[] = 'provider_read_adapter_required';
		if ( ! empty( $operation['mutation'] ) && empty( $provider['adapter_registered'] ) && 'core' !== $provider['provider_kind'] ) $gates[] = 'provider_native_executor_required';
		if ( ! empty( $operation['mutation'] ) && empty( $operation['irreversible'] ) && empty( $provider['reversible_surface_declared'] ) && 'core' !== $provider['provider_kind'] ) $gates[] = 'reversible_contract_required';
		if ( in_array( $operation['risk'], array( 'high_risk_explicit_gate', 'irreversible_external_effect' ), true ) ) $gates[] = 'explicit_impact_approval';
		if ( ! empty( $operation['mutation'] ) ) $gates[] = 'exact_plan_apply_readback';
		if ( ! empty( $operation['irreversible'] ) ) $gates[] = 'irreversible_effect_disclosure';

		return array(
			'contract' => self::PLAN_CONTRACT,
			'family_id' => $family_id,
			'family' => $family['family'],
			'capability_ids' => $family['capability_ids'],
			'provider_id' => $provider_id,
			'provider_readiness' => $provider,
			'operation_id' => $operation_id,
			'operation' => $operation,
			'required_gates' => array_values( array_unique( $gates ) ),
			'execution_surface' => 'separate_exact_canonical_ability_required',
			'execution_ready' => false,
			'provider_execution_performed' => false,
			'mutation_performed' => false,
			'restore_execution_performed' => false,
			'financial_effect_performed' => false,
			'authorizing' => false,
		);
	}

	private static function provider( $provider_id, $kind, array $adapter_ids = array(), array $plugin_slugs = array() ) {
		return array(
			'provider_id' => sanitize_key( $provider_id ),
			'kind' => sanitize_key( $kind ),
			'adapter_ids' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $adapter_ids ) ) ) ),
			'plugin_slugs' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $plugin_slugs ) ) ) ),
		);
	}

	private static function operation( $risk, $data_classification, $mutation, $external_effect, $irreversible, array $requires ) {
		return array(
			'risk' => sanitize_key( $risk ),
			'data_classification' => sanitize_key( $data_classification ),
			'mutation' => (bool) $mutation,
			'external_effect' => (bool) $external_effect,
			'irreversible' => (bool) $irreversible,
			'requires' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $requires ) ) ) ),
		);
	}

	private static function family_has_provider( array $family, $provider_id ) {
		foreach ( $family['providers'] as $provider ) if ( $provider_id === $provider['provider_id'] ) return true;
		return false;
	}

	private static function provider_readiness( $family_id, array $family, array $provider ) {
		$id = $provider['provider_id'];
		$kind = $provider['kind'];
		$adapter_ids = isset( $provider['adapter_ids'] ) && is_array( $provider['adapter_ids'] ) ? $provider['adapter_ids'] : array();
		$plugin_slugs = isset( $provider['plugin_slugs'] ) && is_array( $provider['plugin_slugs'] ) ? $provider['plugin_slugs'] : array();
		$version = '';
		$certified_versions = array();
		$state = 'not_observed';
		$installed = false;
		$adapter_registered = false;
		$adapter_available = false;
		$adapter_id = '';
		$read_abilities = array();
		$reversible_contracts = array();
		$adapter_certification = array();
		$plugin_identities = array();
		$provider_contract = array();

		if ( 'core' === $kind && 'wordpress-core' === $id ) {
			$version = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'version' ) ) : '';
			$installed = true;
			$state = 'core_runtime';
		} else {
			if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
				$registry = MAD4B_SCP_Adapter_Registry::instance();
				if ( is_object( $registry ) && method_exists( $registry, 'register_defaults' ) ) $registry->register_defaults();
				foreach ( $adapter_ids as $candidate_id ) {
					$adapter = is_object( $registry ) && method_exists( $registry, 'get' ) ? $registry->get( $candidate_id ) : null;
					if ( ! is_object( $adapter ) ) continue;
					$adapter_registered = true;
					$adapter_id = sanitize_key( (string) $candidate_id );
					$status_value = method_exists( $adapter, 'status' ) ? $adapter->status() : array();
					$status = is_array( $status_value ) ? $status_value : array();
					$adapter_available = method_exists( $adapter, 'is_available' ) ? (bool) $adapter->is_available() : ! empty( $status['available'] );
					if ( '' === $version && ! empty( $status['version'] ) ) $version = sanitize_text_field( (string) $status['version'] );
					$abilities = isset( $status['abilities'] ) && is_array( $status['abilities'] ) ? $status['abilities'] : ( method_exists( $adapter, 'ability_names' ) ? $adapter->ability_names() : array() );
					$read_abilities = isset( $abilities['read'] ) && is_array( $abilities['read'] ) ? array_values( array_unique( array_filter( array_map( 'strval', $abilities['read'] ) ) ) ) : array();
					$reversible_contracts = isset( $status['reversible_contracts'] ) && is_array( $status['reversible_contracts'] ) ? $status['reversible_contracts'] : ( method_exists( $adapter, 'reversible_contracts' ) ? $adapter->reversible_contracts() : array() );
					$cert = isset( $status['provider_certification'] ) && is_array( $status['provider_certification'] ) ? $status['provider_certification'] : array();
					$adapter_certification = array(
						'provider' => isset( $cert['provider'] ) ? sanitize_key( (string) $cert['provider'] ) : '',
						'status' => isset( $cert['status'] ) ? sanitize_key( (string) $cert['status'] ) : '',
						'installed_version' => isset( $cert['installed_version'] ) ? sanitize_text_field( (string) $cert['installed_version'] ) : '',
						'certified_version' => isset( $cert['certified_version'] ) ? sanitize_text_field( (string) $cert['certified_version'] ) : '',
						'runtime_contract_ok' => ! empty( $cert['runtime_contract_ok'] ),
					);
					break;
				}
			}

			if ( class_exists( 'MAD4B_SCP_Plugin_Discovery' ) && method_exists( 'MAD4B_SCP_Plugin_Discovery', 'coverage' ) ) {
				$coverage = MAD4B_SCP_Plugin_Discovery::coverage();
				$plugins = isset( $coverage['plugins'] ) && is_array( $coverage['plugins'] ) ? $coverage['plugins'] : array();
				foreach ( $plugins as $plugin ) {
					if ( ! is_array( $plugin ) ) continue;
					$slug = isset( $plugin['slug'] ) ? sanitize_key( (string) $plugin['slug'] ) : '';
					$observed_adapter = isset( $plugin['adapter_id'] ) ? sanitize_key( (string) $plugin['adapter_id'] ) : '';
					$observed_family = isset( $plugin['family'] ) ? sanitize_key( (string) $plugin['family'] ) : '';
					$matches = in_array( $slug, $plugin_slugs, true ) || in_array( $observed_adapter, $adapter_ids, true ) || ( '' !== $observed_family && hash_equals( $id, $observed_family ) );
					if ( ! $matches ) continue;
					$identity = array(
						'plugin_file' => isset( $plugin['plugin_file'] ) ? sanitize_text_field( (string) $plugin['plugin_file'] ) : '',
						'slug' => $slug,
						'name' => isset( $plugin['name'] ) ? sanitize_text_field( (string) $plugin['name'] ) : '',
						'version' => isset( $plugin['version'] ) ? sanitize_text_field( (string) $plugin['version'] ) : '',
						'active' => ! empty( $plugin['active'] ),
						'network_active' => ! empty( $plugin['network_active'] ),
						'coverage_state' => isset( $plugin['coverage_state'] ) ? sanitize_key( (string) $plugin['coverage_state'] ) : '',
						'functional_state' => isset( $plugin['functional_coverage']['state'] ) ? sanitize_key( (string) $plugin['functional_coverage']['state'] ) : '',
					);
					$plugin_identities[] = $identity;
					$installed = true;
					if ( '' === $version && '' !== $identity['version'] ) $version = $identity['version'];
					if ( count( $plugin_identities ) >= 20 ) break;
				}
			}

			if ( $adapter_available ) $installed = true;

			if ( class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) {
				$contract = MAD4B_SCP_Provider_Contracts::get( $id );
				if ( ! empty( $contract ) ) {
					$certified_versions = MAD4B_SCP_Provider_Contracts::certified_versions( $id );
					if ( method_exists( 'MAD4B_SCP_Provider_Contracts', 'runtime_status' ) ) {
						$runtime = MAD4B_SCP_Provider_Contracts::runtime_status( $id, null );
						$provider_contract = is_array( $runtime ) ? array(
							'provider' => isset( $runtime['provider'] ) ? sanitize_key( (string) $runtime['provider'] ) : $id,
							'status' => isset( $runtime['status'] ) ? sanitize_key( (string) $runtime['status'] ) : '',
							'installed_version' => isset( $runtime['installed_version'] ) ? sanitize_text_field( (string) $runtime['installed_version'] ) : '',
							'certified_version' => isset( $runtime['certified_version'] ) ? sanitize_text_field( (string) $runtime['certified_version'] ) : '',
							'runtime_contract_ok' => ! empty( $runtime['runtime_contract_ok'] ),
						) : array();
						if ( '' === $version && ! empty( $provider_contract['installed_version'] ) ) $version = $provider_contract['installed_version'];
						if ( ! empty( $provider_contract['installed_version'] ) ) $installed = true;
						if ( ! empty( $provider_contract['runtime_contract_ok'] ) && 'certified' === $provider_contract['status'] ) $state = 'certified_exact_version';
						elseif ( $installed && in_array( $provider_contract['status'], array( 'version_drift', 'component_drift' ), true ) ) $state = 'provider_contract_drift';
						elseif ( $installed ) $state = 'installed_version_unprofiled';
						else $state = 'profile_available_not_observed';
					} else {
						if ( '' === $version ) $version = (string) MAD4B_SCP_Provider_Contracts::installed_version( $id );
						$installed = $installed || '' !== $version;
						$state = $installed && in_array( $version, $certified_versions, true ) ? 'certified_exact_version' : ( $installed ? 'installed_version_unprofiled' : 'profile_available_not_observed' );
					}
				}
			}

			if ( 'not_observed' === $state ) {
				if ( $adapter_available && ! empty( $read_abilities ) ) $state = 'adapter_read_ready_uncertified';
				elseif ( $adapter_registered ) $state = 'adapter_registered_runtime_absent';
				elseif ( $installed ) $state = 'installed_adapter_missing';
			}
		}

		return array(
			'family_id' => $family_id,
			'family' => $family['family'],
			'provider_id' => $id,
			'provider_kind' => $kind,
			'installed' => $installed,
			'installed_version' => $version,
			'observed_plugin_identities' => $plugin_identities,
			'certified_versions' => array_values( $certified_versions ),
			'certification_state' => $state,
			'provider_contract' => $provider_contract,
			'adapter_id' => $adapter_id,
			'adapter_registered' => $adapter_registered,
			'adapter_runtime_available' => $adapter_available,
			'adapter_read_abilities' => $read_abilities,
			'adapter_read_ability_count' => count( $read_abilities ),
			'read_surface_ready' => $adapter_available && ! empty( $read_abilities ),
			'reversible_contracts' => $reversible_contracts,
			'reversible_surface_declared' => ! empty( $reversible_contracts ),
			'adapter_certification' => $adapter_certification,
			'capability_ids' => $family['capability_ids'],
			'operation_ids' => array_keys( $family['operations'] ),
			'execution_admitted' => false,
			'provider_execution_performed' => false,
			'mutation_performed' => false,
			'authority_created' => false,
			'authorizing' => false,
		);
	}
}

MAD4B_SCP_G4_Provider_Families::boot();
