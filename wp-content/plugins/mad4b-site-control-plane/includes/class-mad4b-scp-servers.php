<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-mad4b-scp-post-identity.php';
require_once __DIR__ . '/class-mad4b-scp-site-profile-enrollment.php';
require_once __DIR__ . '/class-mad4b-scp-site-profile-write-enablement.php';
require_once __DIR__ . '/class-mad4b-scp-staging-write-grant-reconciliation.php';
require_once __DIR__ . '/class-mad4b-scp-developer-authority.php';
require_once __DIR__ . '/class-mad4b-scp-full-staging-authority.php';

MAD4B_SCP_Site_Profile_Enrollment::boot();
MAD4B_SCP_Site_Profile_Write_Enablement::boot();
MAD4B_SCP_Staging_Write_Grant_Reconciliation::boot();

final class MAD4B_SCP_Servers {
	private static $registrations = array();
	private static $adapter_write_projection_cache = null;
	private static $registered_adapter_write_candidates_cache = null;
	private static $external_write_tools_cache = null;
	private static $chatgpt_tools_cache = null;
	private static $provider_for_ability_cache = array();
	private static $external_attestation_projection_active = false;

	public static function expected_server_ids() {
		return array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-enrollment', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-developer', 'mad4b-developer-breakglass', 'mad4b-breakglass' );
	}

	public static function core_tools( $server_id ) {
		$governed_status = array( 'mad4b/write-authority-status', 'mad4b/write-authority-reconciliation-plan', 'mad4b/write-runtime-certification', 'mad4b/rest-compatibility-status', 'mad4b/staging-certification-status' );
		$map = array(
			'mad4b-read' => array_merge( array(
				'mad4b/site-info', 'mad4b/site-profile-status', 'mad4b/list-post-types', 'mad4b/post-identity', 'mad4b/list-plugins', 'mad4b/abilities-inventory', 'mad4b/filesystem-list', 'mad4b/filesystem-read',
				'mad4b/database-list-tables', 'mad4b/database-describe-table', 'mad4b/database-select', 'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/connection-status', 'mad4b/context-authority-status',
				'mad4b/plugin-lifecycle-plan', 'mad4b/workflow-provider-status', 'mad4b/workflow-plan', 'mad4b/runtime-functional-gap-diagnostic', 'mad4b/code-snippets-rest-bootstrap-diagnostic',
				'mad4b/operating-model-status', 'mad4b/semantic-identity-map', 'mad4b/site-feature-bundle-validate', 'mad4b/state-diff', 'mad4b/operation-plan', 'mad4b/evidence-invalidation-plan', 'mad4b/invariant-evaluate', 'mad4b/candidate-state', 'mad4b/workflow-compile',
			), $governed_status ),
			'mad4b-chatgpt' => array_merge( array(
				'mad4b/site-info', 'mad4b/site-profile-status', 'mad4b/list-post-types', 'mad4b/post-identity', 'mad4b/list-plugins', 'mad4b/abilities-inventory',
				'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/connection-status',
				'mad4b/plugin-lifecycle-plan', 'mad4b/workflow-provider-status', 'mad4b/workflow-plan', 'mad4b/runtime-functional-gap-diagnostic', 'mad4b/code-snippets-rest-bootstrap-diagnostic',
				'mad4b/operating-model-status', 'mad4b/semantic-identity-map', 'mad4b/site-feature-bundle-validate', 'mad4b/state-diff', 'mad4b/operation-plan', 'mad4b/evidence-invalidation-plan', 'mad4b/invariant-evaluate', 'mad4b/candidate-state', 'mad4b/workflow-compile',
			), $governed_status ),
			'mad4b-enrollment' => array_merge(
				array( 'mad4b/site-info', 'mad4b/site-profile-status', 'mad4b/build-provenance-status', 'mad4b/site-profile-feature-reenroll', 'mad4b/site-profile-write-enable', 'mad4b/staging-write-grant-reconcile', 'mad4b/staging-write-candidate-bind', 'mad4b/staging-write-candidate-binding-audit' ),
				class_exists( 'MAD4B_SCP_Developer_Authority' ) ? MAD4B_SCP_Developer_Authority::enrollment_tools() : array(),
				class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) ? MAD4B_SCP_Full_Staging_Authority::enrollment_tools() : array()
			),
			'mad4b-content' => array( 'mad4b/content-get-post', 'mad4b/content-update-post' ),
			'mad4b-admin' => array(
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			),
			'mad4b-developer' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( false ) : array(),
			'mad4b-developer-breakglass' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( true ) : array(),
			'mad4b-breakglass' => array( 'mad4b/database-raw-query' ),
		);
		if ( 'mad4b-write' === $server_id ) return self::write_tools();
		return isset( $map[ $server_id ] ) ? $map[ $server_id ] : array();
	}

	private static function core_write_candidates() {
		return array_merge(
			array( 'mad4b/content-get-post', 'mad4b/content-update-post' ),
			array(
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
				'mad4b/context-ai-review',
			)
		);
	}

	private static function catalog_cacheable() {
		return function_exists( 'did_action' )
			&& did_action( 'wp_abilities_api_init' ) > 0
			&& did_action( 'rest_api_init' ) > 0
			&& ( ! function_exists( 'doing_action' ) || ( ! doing_action( 'wp_abilities_api_init' ) && ! doing_action( 'rest_api_init' ) ) );
	}

	private static function registered_adapter_write_candidates() {
		$cacheable = self::catalog_cacheable();
		if ( $cacheable && is_array( self::$registered_adapter_write_candidates_cache ) ) return self::$registered_adapter_write_candidates_cache;
		$result = array();
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return $result;
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		$surface_candidates = array_values( array_unique( array_merge(
			$registry->ability_names( 'content' ),
			$registry->ability_names( 'admin' ),
			$registry->ability_names( 'write' )
		) ) );
		foreach ( $registry->all() as $adapter ) {
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'ability_names' ) ) continue;
			$map = $adapter->ability_names();
			$provider = method_exists( $adapter, 'provider_key' ) ? sanitize_key( (string) $adapter->provider_key() ) : sanitize_key( (string) $adapter->id() );
			foreach ( array( 'content', 'admin', 'write' ) as $surface ) {
				$abilities = isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ? $map[ $surface ] : array();
				foreach ( $abilities as $ability_name ) {
					$ability_name = (string) $ability_name;
					if ( in_array( $ability_name, $surface_candidates, true ) && self::registered_mutation_ability( $ability_name ) ) $result[ $ability_name ] = $provider;
				}
			}
		}
		ksort( $result, SORT_STRING );
		if ( $cacheable ) self::$registered_adapter_write_candidates_cache = $result;
		return $result;
	}

	/**
	 * Runtime-eligible write tools mounted on the dedicated mad4b-write authority.
	 * Provider certification and adapter-native capability checks are hard mount gates.
	 */
	public static function write_tools() {
		$candidates = self::core_write_candidates();
		// AI Agent review is a stable catalog candidate but becomes runtime-write eligible
		// only after an administrator explicitly enables the bounded Staging delegation.
		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) || ! MAD4B_SCP_Context_Authority::ai_review_catalog_eligible() ) {
			$candidates = array_values( array_diff( $candidates, array( 'mad4b/context-ai-review' ) ) );
		}
		$projection = self::adapter_write_projection();
		$candidates = array_merge( $candidates, $projection['eligible'] );
		$write = array();
		foreach ( array_values( array_unique( $candidates ) ) as $ability_name ) {
			if ( self::registered_mutation_ability( $ability_name ) ) $write[] = (string) $ability_name;
		}
		sort( $write, SORT_STRING );
		return array_values( array_unique( $write ) );
	}

	/**
	 * Stable governed ChatGPT write catalog. Discovery is deliberately independent
	 * of provider activation state; execution still requires the ability to be in
	 * write_tools() and an exact NHI grant. Normal writes require a one-time
	 * approval ticket; the dedicated Context AI review ability may instead use its
	 * explicit standing delegation when that bounded policy is active.
	 */
	public static function external_write_tools() {
		$cacheable = self::catalog_cacheable();
		if ( $cacheable && is_array( self::$external_write_tools_cache ) ) return self::$external_write_tools_cache;
		$candidates = self::core_write_candidates();
		$candidates = array_merge( $candidates, array_keys( self::registered_adapter_write_candidates() ) );
		$write = array();
		foreach ( array_values( array_unique( $candidates ) ) as $ability_name ) {
			if ( self::registered_mutation_ability( $ability_name ) ) $write[] = (string) $ability_name;
		}
		sort( $write, SORT_STRING );
		$result = array_values( array_unique( array_diff( $write, array( 'mad4b/database-raw-query' ) ) ) );
		if ( $cacheable ) self::$external_write_tools_cache = $result;
		return $result;
	}

	public static function is_external_write_candidate( $ability_name ) {
		return in_array( (string) $ability_name, self::external_write_tools(), true );
	}

	public static function blocked_write_tools() {
		$projection = self::adapter_write_projection();
		$blocked = array_values( $projection['blocked'] );
		usort( $blocked, static function ( $a, $b ) {
			return strcmp( isset( $a['ability'] ) ? $a['ability'] : '', isset( $b['ability'] ) ? $b['ability'] : '' );
		} );
		return $blocked;
	}

	/**
	 * Re-project dynamic provider eligibility over immutable external inventory
	 * evidence at read time. A same-build certification transition therefore
	 * updates gated/eligible semantics without rewriting the captured MCP receipt
	 * or requiring a new tools/list scan.
	 */
	public static function filter_external_inventory_attestation_runtime( $stored ) {
		if ( self::$external_attestation_projection_active || ! is_array( $stored ) || empty( $stored ) ) return $stored;
		if ( ! class_exists( '\\WP\\MCP\\Domain\\Utils\\McpNameSanitizer' ) ) return $stored;

		self::$external_attestation_projection_active = true;
		$external_names = self::mcp_tool_names_from_abilities( self::external_write_tools() );
		$eligible_names = self::mcp_tool_names_from_abilities( self::write_tools() );
		$blocked_abilities = array();
		foreach ( self::blocked_write_tools() as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['ability'] ) ) $blocked_abilities[] = (string) $entry['ability'];
		}
		$blocked_names = self::mcp_tool_names_from_abilities( $blocked_abilities );
		$provider_gated = array_values( array_intersect( $external_names, $blocked_names ) );
		$execution_leaks = array_values( array_intersect( $eligible_names, $blocked_names ) );

		$stored['eligible_write_tool_count'] = count( $eligible_names );
		$stored['expected_eligible_write_tool_count'] = count( $eligible_names );
		$stored['provider_gated_write_tool_count'] = count( $provider_gated );
		$stored['provider_gated_write_tools'] = $provider_gated;
		$stored['provider_execution_mount_leaks'] = $execution_leaks;
		$stored['provider_blocked_tool_leaks'] = $execution_leaks;
		$stored['runtime_projection_current'] = true;
		self::$external_attestation_projection_active = false;
		return $stored;
	}

	private static function mcp_tool_names_from_abilities( array $abilities ) {
		$names = array();
		foreach ( array_values( array_unique( array_map( 'strval', $abilities ) ) ) as $ability_name ) {
			$name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $ability_name );
			if ( is_wp_error( $name ) || ! is_string( $name ) || '' === trim( $name ) ) continue;
			$names[] = trim( $name );
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	private static function registered_mutation_ability( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( '' === $ability_name || 'mad4b/database-raw-query' === $ability_name ) return false;
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return false;
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) return false;
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return false;
		return true;
	}

	private static function adapter_write_projection() {
		$result = array( 'eligible' => array(), 'blocked' => array() );
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return $result;
		$cacheable = function_exists( 'did_action' )
			&& did_action( 'wp_abilities_api_init' ) > 0
			&& did_action( 'rest_api_init' ) > 0
			&& ( ! function_exists( 'doing_action' ) || ( ! doing_action( 'wp_abilities_api_init' ) && ! doing_action( 'rest_api_init' ) ) );
		if ( $cacheable && is_array( self::$adapter_write_projection_cache ) ) return self::$adapter_write_projection_cache;

		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		foreach ( $registry->all() as $adapter ) {
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'ability_names' ) ) continue;
			$map = $adapter->ability_names();
			$mutation_candidates = array();
			// `write` is a dedicated adapter surface for mutations that must exist on
			// mad4b-write but must not leak onto mad4b-content or mad4b-admin.
			foreach ( array( 'content', 'admin', 'write' ) as $surface ) {
				$abilities = isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ? $map[ $surface ] : array();
				foreach ( $abilities as $ability_name ) {
					$ability_name = (string) $ability_name;
					if ( self::registered_mutation_ability( $ability_name ) ) $mutation_candidates[] = $ability_name;
				}
			}
			$mutation_candidates = array_values( array_unique( $mutation_candidates ) );
			if ( empty( $mutation_candidates ) ) continue;

			$status = method_exists( $adapter, 'status' ) ? $adapter->status() : array();
			$requires_certification = ! empty( $status['mutation_requires_certification'] );
			$certification = isset( $status['provider_certification'] ) && is_array( $status['provider_certification'] ) ? $status['provider_certification'] : array();
			$provider = method_exists( $adapter, 'provider_key' ) ? sanitize_key( (string) $adapter->provider_key() ) : sanitize_key( (string) $adapter->id() );
			$capability_cataloged = $requires_certification
				&& class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' )
				&& MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $provider );
			$capability_projection = $capability_cataloged
				? MAD4B_SCP_Provider_Compatibility_Certification::adapter_mount_projection( $provider, $adapter )
				: array();
			$capability_eligible = array();
			$capability_blocked = array();
			if ( $capability_cataloged ) {
				foreach ( (array) ( $capability_projection['eligible'] ?? array() ) as $entry ) if ( ! empty( $entry['ability'] ) ) $capability_eligible[ (string) $entry['ability'] ] = $entry;
				foreach ( (array) ( $capability_projection['blocked'] ?? array() ) as $entry ) if ( ! empty( $entry['ability'] ) ) $capability_blocked[ (string) $entry['ability'] ] = $entry;
			}
			$legacy_runtime_contract_ok = ! $requires_certification || ! empty( $certification['legacy_runtime_contract_ok'] ) || ! empty( $certification['runtime_contract_ok'] );

			foreach ( $mutation_candidates as $ability_name ) {
				if ( method_exists( $adapter, 'mutation_ability_runtime_eligibility' ) ) {
					$runtime_eligibility = $adapter->mutation_ability_runtime_eligibility( $ability_name );
					if ( true !== $runtime_eligibility ) {
						$code = is_wp_error( $runtime_eligibility ) ? (string) $runtime_eligibility->get_error_code() : 'adapter_runtime_eligibility_unverified';
						$result['blocked'][ $ability_name ] = array(
							'ability' => $ability_name,
							'provider' => $provider,
							'reason' => 'adapter_runtime_capability_not_eligible',
							'runtime_eligibility_code' => $code,
							'violations' => array( $code ),
						);
						continue;
					}
				}
				if ( ! $requires_certification ) {
					$result['eligible'][] = $ability_name;
					continue;
				}
				if ( $capability_cataloged ) {
					if ( isset( $capability_eligible[ $ability_name ] ) ) {
						$result['eligible'][] = $ability_name;
						continue;
					}
					$entry = isset( $capability_blocked[ $ability_name ] ) ? $capability_blocked[ $ability_name ] : array();
					$result['blocked'][ $ability_name ] = array(
						'ability' => $ability_name,
						'provider' => $provider,
						'reason' => 'provider_capability_not_write_eligible',
						'capability_id' => isset( $entry['capability_id'] ) ? (string) $entry['capability_id'] : '',
						'certification_level' => isset( $entry['certification_level'] ) ? (string) $entry['certification_level'] : 'UNKNOWN',
						'runtime_status' => isset( $certification['status'] ) ? (string) $certification['status'] : 'unknown',
						'installed_version' => isset( $certification['installed_version'] ) ? (string) $certification['installed_version'] : '',
						'certified_version' => isset( $certification['certified_version'] ) ? (string) $certification['certified_version'] : '',
						'violations' => array( 'capability_write_certification_required' ),
					);
					continue;
				}
				if ( $legacy_runtime_contract_ok ) {
					$result['eligible'][] = $ability_name;
					continue;
				}
				$violations = class_exists( 'MAD4B_SCP_Provider_Contracts' )
					? MAD4B_SCP_Provider_Contracts::violations_for_status( $certification )
					: array( 'certification_authority_unavailable' );
				$result['blocked'][ $ability_name ] = array(
					'ability' => $ability_name,
					'provider' => $provider,
					'reason' => 'provider_runtime_contract_not_certified',
					'runtime_status' => isset( $certification['status'] ) ? (string) $certification['status'] : 'unknown',
					'installed_version' => isset( $certification['installed_version'] ) ? (string) $certification['installed_version'] : '',
					'certified_version' => isset( $certification['certified_version'] ) ? (string) $certification['certified_version'] : '',
					'violations' => array_values( array_unique( array_map( 'strval', $violations ) ) ),
				);
			}
		}
		$result['eligible'] = array_values( array_unique( $result['eligible'] ) );
		if ( $cacheable ) self::$adapter_write_projection_cache = $result;
		return $result;
	}

	private static function chatgpt_unified_catalog_enabled() {
		return class_exists( 'MAD4B_SCP_Site_Profile' )
			&& MAD4B_SCP_Site_Profile::configured()
			&& 'staging' === MAD4B_SCP_Site_Profile::current_environment()
			&& MAD4B_SCP_Site_Profile::origin_enrolled()
			&& MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
	}

	public static function chatgpt_tools() {
		$cacheable = self::catalog_cacheable();
		if ( $cacheable && is_array( self::$chatgpt_tools_cache ) ) return self::$chatgpt_tools_cache;
		$core = self::core_tools( 'mad4b-chatgpt' );
		$adapter_candidates = array();
		if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			$registry->register_defaults();
			$adapter_candidates = $registry->ability_names( 'read' );
		}

		if ( ! self::chatgpt_unified_catalog_enabled() ) {
			$forbidden = array(
				'mad4b/filesystem-list', 'mad4b/filesystem-read',
				'mad4b/database-list-tables', 'mad4b/database-describe-table', 'mad4b/database-select', 'mad4b/database-raw-query',
			);
			$tools = array_values( array_diff( $core, $forbidden ) );
			foreach ( array_values( array_unique( $adapter_candidates ) ) as $ability_name ) {
				if ( in_array( $ability_name, $forbidden, true ) ) continue;
				if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) continue;
				$ability = wp_get_ability( $ability_name );
				if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) continue;
				$meta = $ability->get_meta();
				$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
				if ( ! array_key_exists( 'readonly', $annotations ) || true !== $annotations['readonly'] ) continue;
				$tools[] = (string) $ability_name;
			}
			if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective() ) {
				$tools = array_merge( $tools, self::write_tools() );
				$tools = array_merge( $tools, array_values( array_diff( self::external_write_tools(), self::write_tools() ) ) );
			}
			$tools = array_values( array_unique( $tools ) );
			if ( $cacheable ) self::$chatgpt_tools_cache = $tools;
			return $tools;
		}

		$enrollment_candidates = self::core_tools( 'mad4b-enrollment' );
		if ( class_exists( 'MAD4B_SCP_Developer_Authority' ) ) {
			$enrollment_candidates = array_values( array_diff( $enrollment_candidates, MAD4B_SCP_Developer_Authority::enrollment_tools() ) );
		}
		if ( class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) ) {
			$enrollment_candidates = array_values( array_diff( $enrollment_candidates, MAD4B_SCP_Full_Staging_Authority::enrollment_tools() ) );
		}
		$candidates = array_merge(
			self::core_tools( 'mad4b-read' ),
			self::core_tools( 'mad4b-chatgpt' ),
			$enrollment_candidates,
			self::core_tools( 'mad4b-content' ),
			self::core_tools( 'mad4b-admin' ),
			self::external_write_tools()
		);
		if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			$registry->register_defaults();
			foreach ( array( 'read', 'content', 'admin', 'write' ) as $surface ) {
				$candidates = array_merge( $candidates, $registry->ability_names( $surface ) );
			}
		}

		$tools = array();
		$breakglass = self::core_tools( 'mad4b-breakglass' );
		$bounded_bootstrap = array( 'mad4b/site-profile-feature-reenroll', 'mad4b/site-profile-write-enable', 'mad4b/staging-write-grant-reconcile', 'mad4b/staging-write-candidate-bind' );
		foreach ( array_values( array_unique( array_map( 'strval', $candidates ) ) ) as $ability_name ) {
			if ( '' === $ability_name || 'mad4b/database-raw-query' === $ability_name || in_array( $ability_name, $breakglass, true ) ) continue;
			if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) continue;
			if ( in_array( $ability_name, $bounded_bootstrap, true ) ) {
				$tools[] = $ability_name;
				continue;
			}
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) continue;
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( array_key_exists( 'readonly', $annotations ) && true === $annotations['readonly'] ) {
				$tools[] = $ability_name;
				continue;
			}
			if ( array_key_exists( 'readonly', $annotations ) && false === $annotations['readonly'] && self::is_external_write_candidate( $ability_name ) ) {
				$tools[] = $ability_name;
			}
		}
		$tools = array_values( array_unique( $tools ) );
		sort( $tools, SORT_STRING );
		if ( $cacheable ) self::$chatgpt_tools_cache = $tools;
		return $tools;
	}

	private static function surface_for_server( $server_id ) {
		if ( 'mad4b-read' === $server_id || 'mad4b-chatgpt' === $server_id ) return 'read';
		if ( 'mad4b-content' === $server_id ) return 'content';
		if ( 'mad4b-admin' === $server_id ) return 'admin';
		return '';
	}

	public static function ability_is_mounted( $server_id, $ability_name ) { return null !== self::provider_for_ability( $server_id, $ability_name ); }

	private static function provider_for_external_write_candidate( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( ! self::is_external_write_candidate( $ability_name ) ) return null;
		if ( in_array( $ability_name, self::core_write_candidates(), true ) ) return 'core';
		$candidates = self::registered_adapter_write_candidates();
		return isset( $candidates[ $ability_name ] ) ? $candidates[ $ability_name ] : null;
	}

	public static function provider_for_ability( $server_id, $ability_name ) {
		$server_id = sanitize_key( (string) $server_id );
		$ability_name = (string) $ability_name;
		$cache_key = $server_id . "\0" . $ability_name;
		$cacheable = self::catalog_cacheable();
		$dynamic_write_resolution = 'mad4b-write' === $server_id || self::is_external_write_candidate( $ability_name );
		if ( $cacheable && ! $dynamic_write_resolution && array_key_exists( $cache_key, self::$provider_for_ability_cache ) ) return self::$provider_for_ability_cache[ $cache_key ];
		$remember = static function ( $value ) use ( $cache_key, $cacheable, $dynamic_write_resolution ) {
			if ( $cacheable && ! $dynamic_write_resolution ) self::$provider_for_ability_cache[ $cache_key ] = $value;
			return $value;
		};
		if ( ! in_array( $server_id, self::expected_server_ids(), true ) ) return $remember( null );
		if ( 'mad4b-write' === $server_id ) {
			if ( ! in_array( $ability_name, self::write_tools(), true ) ) return $remember( null );
			if ( in_array( $ability_name, self::core_write_candidates(), true ) ) return $remember( 'core' );
			$candidates = self::registered_adapter_write_candidates();
			return $remember( isset( $candidates[ $ability_name ] ) ? $candidates[ $ability_name ] : null );
		}
		if ( 'mad4b-chatgpt' === $server_id ) {
			if ( ! in_array( $ability_name, self::chatgpt_tools(), true ) ) return $remember( null );
			if ( self::is_external_write_candidate( $ability_name ) ) {
				if ( null !== self::provider_for_ability( 'mad4b-write', $ability_name ) ) return $remember( self::provider_for_ability( 'mad4b-write', $ability_name ) );
				return $remember( self::provider_for_external_write_candidate( $ability_name ) );
			}
			if ( self::chatgpt_unified_catalog_enabled() ) {
				foreach ( array( 'mad4b-read', 'mad4b-enrollment', 'mad4b-content', 'mad4b-admin' ) as $core_server ) {
					if ( in_array( $ability_name, self::core_tools( $core_server ), true ) ) return $remember( 'core' );
				}
				if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
					$registry = MAD4B_SCP_Adapter_Registry::instance();
					$registry->register_defaults();
					foreach ( $registry->all() as $adapter ) {
						$map = $adapter->ability_names();
						foreach ( array( 'read', 'content', 'admin', 'write' ) as $surface ) {
							if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) && in_array( $ability_name, $map[ $surface ], true ) ) {
								return $remember( method_exists( $adapter, 'provider_key' ) ? $adapter->provider_key() : sanitize_key( (string) $adapter->id() ) );
							}
						}
					}
				}
			}
		}
		if ( in_array( $ability_name, self::core_tools( $server_id ), true ) ) return $remember( 'core' );
		$surface = self::surface_for_server( $server_id );
		if ( '' === $surface || ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return $remember( null );
		$registry = MAD4B_SCP_Adapter_Registry::instance(); $registry->register_defaults();
		foreach ( $registry->all() as $adapter ) {
			$map = $adapter->ability_names();
			if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) && in_array( $ability_name, $map[ $surface ], true ) ) return $remember( method_exists( $adapter, 'provider_key' ) ? $adapter->provider_key() : sanitize_key( (string) $adapter->id() ) );
		}
		return $remember( null );
	}

	public static function registration_status() {
		$status = array(); foreach ( self::expected_server_ids() as $id ) $status[ $id ] = isset( self::$registrations[ $id ] ) ? self::$registrations[ $id ] : array( 'registered' => false, 'error' => 'not_registered' ); return $status;
	}
	public static function can_read_transport( $request = null ) { return self::transport_permission( 'mad4b-read', $request, array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public static function can_chatgpt_transport( $request = null ) { return self::transport_permission( 'mad4b-chatgpt', $request, array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public static function can_enrollment_transport( $request = null ) { return self::transport_permission( 'mad4b-enrollment', $request, array( 'MAD4B_SCP_Site_Profile_Enrollment', 'can_access_transport' ) ); }
	public static function can_content_transport( $request = null ) { return self::transport_permission( 'mad4b-content', $request, array( 'MAD4B_SCP_Policy', 'can_content' ) ); }
	public static function can_write_transport( $request = null ) { return self::transport_permission( 'mad4b-write', $request, array( 'MAD4B_SCP_Policy', 'can_admin' ) ); }
	public static function can_admin_transport( $request = null ) { return self::transport_permission( 'mad4b-admin', $request, array( 'MAD4B_SCP_Policy', 'can_admin' ) ); }
	public static function can_developer_transport( $request = null ) { return self::transport_permission( 'mad4b-developer', $request, array( 'MAD4B_SCP_Policy', 'can_developer_read' ) ); }
	public static function can_developer_breakglass_transport( $request = null ) { return self::transport_permission( 'mad4b-developer-breakglass', $request, array( 'MAD4B_SCP_Policy', 'can_developer_breakglass' ) ); }
	public static function can_breakglass_transport( $request = null ) { return self::transport_permission( 'mad4b-breakglass', $request, array( 'MAD4B_SCP_Policy', 'can_breakglass' ) ); }

	private static function transport_permission( $server_id, $request, $policy_callback ) {
		if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) ) return new WP_Error( 'mad4b_transport_context_unavailable', 'MAD4B transport context is unavailable.' );
		$bound = MAD4B_SCP_Transport_Context::bind( $server_id, $request ); if ( is_wp_error( $bound ) ) return $bound;
		if ( ! is_callable( $policy_callback ) ) return new WP_Error( 'mad4b_transport_policy_unavailable', 'MAD4B transport permission policy is unavailable.' );
		return call_user_func( $policy_callback );
	}

	public function register_servers( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) { foreach ( self::expected_server_ids() as $id ) self::$registrations[ $id ] = array( 'registered' => false, 'error' => 'adapter_contract_unavailable' ); return; }
		$transport = '\\WP\\MCP\\Transport\\HttpTransport';
		$error_handler = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
		$observability = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$read_tools = array_merge( self::core_tools( 'mad4b-read' ), $registry->ability_names( 'read' ) );
		$chatgpt_tools = self::chatgpt_tools();
		$enrollment_tools = self::core_tools( 'mad4b-enrollment' );
		$content_tools = array_merge( self::core_tools( 'mad4b-content' ), $registry->ability_names( 'content' ) );
		$write_tools = self::write_tools();
		$admin_tools = array_merge( self::core_tools( 'mad4b-admin' ), $registry->ability_names( 'admin' ) );
		$developer_tools = self::core_tools( 'mad4b-developer' );
		$developer_breakglass_tools = self::core_tools( 'mad4b-developer-breakglass' );
		$chatgpt_write_ready = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective();
		if ( self::chatgpt_unified_catalog_enabled() ) {
			$chatgpt_description = 'Exact enrolled Staging unified governed gateway exposing all registered normal read capabilities, bounded Site Profile bootstrap, and the stable governed write catalog. Write visibility never grants authority: execution remains delegated to mad4b-write and requires runtime eligibility and exact grants. Normal writes require exact approval; the Context AI review ability may use only its explicitly configured bounded standing delegation. Breakglass and Raw SQL remain excluded.';
		} else {
			$chatgpt_description = $chatgpt_write_ready ? 'ChatGPT governed gateway with read diagnostics plus a stable governed write catalog. Provider writes may be discoverable before activation but remain fail-closed until mounted on mad4b-write, exactly granted and approved. Generic filesystem/database introspection and breakglass remain excluded.' : 'ChatGPT-safe read gateway. Generic filesystem/database inspection and all content/write/admin/breakglass mutation surfaces are excluded.';
		}
		$this->create( $adapter, 'mad4b-read', 'MAD4B Read MCP', 'Read-only discovery and diagnostics for WordPress, plugin adapters, files and database.', array_values( array_unique( $read_tools ) ), array( __CLASS__, 'can_read_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-chatgpt', 'MAD4B ChatGPT MCP', $chatgpt_description, $chatgpt_tools, array( __CLASS__, 'can_chatgpt_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-enrollment', 'MAD4B Enrollment MCP', 'Bounded Staging-only Site Profile feature/App/write bootstrap. Administrative bootstrap authority is separate from normal governed write authority.', $enrollment_tools, array( __CLASS__, 'can_enrollment_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-content', 'MAD4B Content MCP', 'Governed content, media, SEO and plugin-specific editing abilities.', array_values( array_unique( $content_tools ) ), array( __CLASS__, 'can_content_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-write', 'MAD4B Write MCP', 'Unified governed write authority containing every runtime-eligible registered content/admin/write mutation explicitly annotated non-readonly. Cataloged provider mutations are projected per ability from capability certification; adapter-native runtime capability checks are hard mount gates; legacy providers retain exact runtime certification; breakglass is excluded.', array_values( array_unique( $write_tools ) ), array( __CLASS__, 'can_write_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-admin', 'MAD4B Admin MCP', 'Administrative governance, repair, mutation evidence and governed recovery abilities.', array_values( array_unique( $admin_tools ) ), array( __CLASS__, 'can_admin_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-developer', 'MAD4B Developer MCP', 'Isolated explicit non-Production Developer Agent plane. It is never mounted on mad4b-chatgpt or mad4b-write; execution requires the exact configured developer agent, exact grant, one-time approval, budget, audit and runtime gate.', array_values( array_unique( $developer_tools ) ), array( __CLASS__, 'can_developer_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-developer-breakglass', 'MAD4B Developer Breakglass MCP', 'Exceptional non-Production Developer Agent recovery plane. Disabled by default and separately gated from normal developer execution.', array_values( array_unique( $developer_breakglass_tools ) ), array( __CLASS__, 'can_developer_breakglass_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-breakglass', 'MAD4B Breakglass MCP', 'Exceptional recovery surface. Disabled unless explicitly enabled in wp-config.php.', self::core_tools( 'mad4b-breakglass' ), array( __CLASS__, 'can_breakglass_transport' ), $transport, $error_handler, $observability );
	}

	private function create( $adapter, $id, $name, $description, array $tools, $permission, $transport, $error_handler, $observability ) {
		$result = $adapter->create_server( $id, 'mcp', $id, $name, $description, MAD4B_SCP_VERSION, array( $transport ), $error_handler, $observability, $tools, array(), array(), $permission );
		if ( is_wp_error( $result ) ) { self::$registrations[ $id ] = array( 'registered' => false, 'error' => $result->get_error_code() ); error_log( '[MAD4B SCP] Failed creating ' . $id . ': ' . $result->get_error_message() ); return; }
		self::$registrations[ $id ] = array( 'registered' => true, 'error' => '' );
	}
}

add_filter( 'option_mad4b_scp_external_inventory_attestation_v1', array( 'MAD4B_SCP_Servers', 'filter_external_inventory_attestation_runtime' ), 120 );
