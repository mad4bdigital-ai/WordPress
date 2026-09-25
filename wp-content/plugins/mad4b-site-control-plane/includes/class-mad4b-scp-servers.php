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
				'mad4b/database-list-tables', 'mad4b/database-describe-table', 'mad4b/database-select', 'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/schema-status', 'mad4b/multi-authority-registry-status', 'mad4b/connection-status', 'mad4b/context-authority-status',
				'mad4b/plugin-lifecycle-plan', 'mad4b/plugin-package-plan', 'mad4b/workflow-provider-status', 'mad4b/workflow-plan', 'mad4b/runtime-functional-gap-diagnostic', 'mad4b/code-snippets-rest-bootstrap-diagnostic',
				'mad4b/operating-model-status', 'mad4b/semantic-identity-map', 'mad4b/site-feature-bundle-validate', 'mad4b/state-diff', 'mad4b/operation-plan', 'mad4b/evidence-invalidation-plan', 'mad4b/invariant-evaluate', 'mad4b/candidate-state', 'mad4b/workflow-compile',
				'mad4b/capability-trait-profile', 'mad4b/capability-trait-resolve',
				'mad4b/data-processing-evaluate',
				'mad4b/decommission-preflight',
				'mad4b/export-bundle-build', 'mad4b/import-bundle-validate',
				'mad4b/scheduler-admission-evaluate',
				'mad4b/scheduler-fair-rank',
				'mad4b/operator-doctor', 'mad4b/operator-dead-letter-status',
				'mad4b/site-bootstrap-snapshot',
			), $governed_status ),
			'mad4b-chatgpt' => array_merge( array(
				'mad4b/site-info', 'mad4b/site-profile-status',
				'mad4b/tool-discover', 'mad4b/tool-info', 'mad4b/read-execute',
				'mad4b/write-discover', 'mad4b/write-info', 'mad4b/write-execute',
				'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/multi-authority-registry-status', 'mad4b/connection-status',
				'mad4b/plugin-package-plan',
			), $governed_status ),
			'mad4b-enrollment' => array_merge(
				array( 'mad4b/site-info', 'mad4b/site-profile-status', 'mad4b/build-provenance-status', 'mad4b/multi-authority-registry-status', 'mad4b/site-profile-feature-reenroll', 'mad4b/site-profile-write-enable', 'mad4b/staging-write-grant-reconcile', 'mad4b/staging-write-candidate-bind', 'mad4b/staging-write-candidate-binding-audit' ),
				class_exists( 'MAD4B_SCP_Developer_Authority' ) ? MAD4B_SCP_Developer_Authority::enrollment_tools() : array(),
				class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) ? MAD4B_SCP_Full_Staging_Authority::enrollment_tools() : array()
			),
			'mad4b-content' => array(
				'mad4b/content-get-post', 'mad4b/content-update-post',
				'mad4b/content-job-list', 'mad4b/content-job-get', 'mad4b/content-job-events',
				'mad4b/intent-registry-current', 'mad4b/intent-conflicts-analyze',
				'mad4b/draft-plan', 'mad4b/draft-verify', 'mad4b/publication-verification-evaluate',
			),
			'mad4b-admin' => array(
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/plugin-package-apply', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			),
			'mad4b-developer' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( false ) : array(),
			'mad4b-developer-breakglass' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( true ) : array(),
			'mad4b-breakglass' => array( 'mad4b/database-raw-query' ),
		);
		if ( 'mad4b-write' === $server_id ) return self::write_tools();
		$tools = isset( $map[ $server_id ] ) ? $map[ $server_id ] : array();
		if ( 'mad4b-admin' === $server_id
			&& class_exists( 'MAD4B_SCP_Context_Authority' )
			&& function_exists( 'wp_has_ability' )
			&& wp_has_ability( MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY ) ) {
			$tools[] = MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY;
		}
		return array_values( array_unique( $tools ) );
	}

	private static function core_write_candidates() {
		$candidates = array_merge(
			array(
				'mad4b/content-get-post', 'mad4b/content-update-post',
				'mad4b/content-job-create', 'mad4b/content-job-transition', 'mad4b/content-job-cancel',
				'mad4b/intent-registry-reconcile', 'mad4b/draft-apply', 'mad4b/data-processing-record-decision',
			),
			array(
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/plugin-package-apply', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			)
		);
		if ( class_exists( 'MAD4B_SCP_Context_Authority' )
			&& function_exists( 'wp_has_ability' )
			&& wp_has_ability( MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY ) ) {
			$candidates[] = MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY;
		}
		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Request-local catalog caches are safe as soon as the Abilities registry has
	 * completed. MCP server construction itself runs inside rest_api_init, so
	 * requiring rest_api_init to finish would make the cache useless on the exact
	 * tools/list hot path we need to protect. Nothing here persists across HTTP
	 * requests.
	 */
	private static function catalog_cacheable() {
		return function_exists( 'did_action' )
			&& did_action( 'wp_abilities_api_init' ) > 0
			&& ( ! function_exists( 'doing_action' ) || ! doing_action( 'wp_abilities_api_init' ) );
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
		// AI Agent review is always discoverable in the stable catalog, but it is
		// runtime-write eligible only while the bounded Staging delegation is active.
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
	 * write_tools(), an exact NHI grant, and a one-time approval ticket.
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
		// Provider/runtime-certification diagnostics only. Core governance gates,
		// such as Context AI standing-delegation eligibility, are enforced by
		// write_tools()/authority status and must not be mislabeled as provider
		// certification failures.
		$projection = self::adapter_write_projection();
		$blocked = array_values( $projection['blocked'] );

		usort( $blocked, static function ( $a, $b ) {
			return strcmp( isset( $a['ability'] ) ? $a['ability'] : '', isset( $b['ability'] ) ? $b['ability'] : '' );
		} );
		return $blocked;
	}

	/**
	 * Core governance gates are distinct from provider/runtime certification.
	 * These abilities remain part of stable external discovery but cannot mount
	 * on mad4b-write until their own bounded governance predicate is satisfied.
	 */
	public static function governance_gated_write_tools() {
		$gated = array();
		if ( class_exists( 'MAD4B_SCP_Context_Authority' )
			&& function_exists( 'wp_has_ability' )
			&& wp_has_ability( MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY )
			&& ! MAD4B_SCP_Context_Authority::ai_review_catalog_eligible() ) {
			$status = method_exists( 'MAD4B_SCP_Context_Authority', 'ai_review_policy_status' )
				? MAD4B_SCP_Context_Authority::ai_review_policy_status()
				: array();
			$violations = isset( $status['blockers'] ) && is_array( $status['blockers'] )
				? array_values( array_unique( array_map( 'strval', $status['blockers'] ) ) )
				: array();
			if ( empty( $violations ) ) $violations[] = 'ai_review_standing_delegation_not_eligible';
			$gated[] = array(
				'ability' => MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY,
				'provider' => 'core',
				'reason' => 'ai_review_standing_delegation_not_eligible',
				'violations' => $violations,
			);
		}
		usort( $gated, static function ( $a, $b ) {
			return strcmp( isset( $a['ability'] ) ? $a['ability'] : '', isset( $b['ability'] ) ? $b['ability'] : '' );
		} );
		return $gated;
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
		$governance_abilities = array();
		foreach ( self::governance_gated_write_tools() as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['ability'] ) ) $governance_abilities[] = (string) $entry['ability'];
		}
		$governance_names = self::mcp_tool_names_from_abilities( $governance_abilities );
		$governance_gated = array_values( array_intersect( $external_names, $governance_names ) );
		$governance_leaks = array_values( array_intersect( $eligible_names, $governance_names ) );

		$stored['eligible_write_tool_count'] = count( $eligible_names );
		$stored['expected_eligible_write_tool_count'] = count( $eligible_names );
		$stored['provider_gated_write_tool_count'] = count( $provider_gated );
		$stored['provider_gated_write_tools'] = $provider_gated;
		$stored['governance_gated_write_tool_count'] = count( $governance_gated );
		$stored['governance_gated_write_tools'] = $governance_gated;
		$stored['provider_execution_mount_leaks'] = $execution_leaks;
		$stored['provider_blocked_tool_leaks'] = $execution_leaks;
		$stored['governance_execution_mount_leaks'] = $governance_leaks;
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
		// Runtime provider eligibility is intentionally more conservative than
		// the immutable transport/catalog caches above. Isolation/certification can
		// settle during rest_api_init, so never freeze this projection while REST
		// registration is still in progress.
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
		$breakglass = self::core_tools( 'mad4b-breakglass' );

		// ChatGPT always receives a bounded transport catalog. Full read and write
		// capability universes remain available through governed discovery/info
		// surfaces, while mutation execution is concentrated into the exact-target
		// write dispatcher. This keeps tools/list small and stable enough for client
		// refresh without weakening the underlying ability authority contracts.
		if ( ! self::chatgpt_unified_catalog_enabled() ) {
			$tools = array_values( array_diff( $core, $breakglass, array( 'mad4b/database-raw-query' ) ) );
			$tools = array_values( array_unique( array_map( 'strval', $tools ) ) );
			sort( $tools, SORT_STRING );
			if ( $cacheable ) self::$chatgpt_tools_cache = $tools;
			return $tools;
		}

		$step_up = class_exists( 'MAD4B_SCP_Full_Staging_Authority' )
			? MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()
			: array();
		$bootstrap = array(
			'mad4b/build-provenance-status',
			'mad4b/staging-write-candidate-binding-audit',
		);
		if ( class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) ) {
			$bootstrap = array_merge( $bootstrap, MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools(), $step_up );
		}
		$candidates = array_merge( $core, $bootstrap );
		$direct_mutation_transport = array_merge( array( 'mad4b/write-execute' ), $step_up );

		$tools = array();
		foreach ( array_values( array_unique( array_map( 'strval', $candidates ) ) ) as $ability_name ) {
			if ( '' === $ability_name || 'mad4b/database-raw-query' === $ability_name || in_array( $ability_name, $breakglass, true ) ) continue;
			if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) continue;
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) continue;
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( array_key_exists( 'readonly', $annotations ) && true === $annotations['readonly'] ) {
				$tools[] = $ability_name;
				continue;
			}
			if ( array_key_exists( 'readonly', $annotations ) && false === $annotations['readonly'] && in_array( $ability_name, $direct_mutation_transport, true ) ) {
				$tools[] = $ability_name;
			}
		}
		$tools = array_values( array_unique( $tools ) );
		sort( $tools, SORT_STRING );
		if ( $cacheable ) self::$chatgpt_tools_cache = $tools;
		return $tools;
	}

	private static function chatgpt_internal_enrollment_mutations() {
		return array(
			'mad4b/site-profile-feature-reenroll',
			'mad4b/site-profile-write-enable',
			'mad4b/staging-write-grant-reconcile',
			'mad4b/staging-write-candidate-bind',
		);
	}

	private static function chatgpt_enrollment_candidates() {
		$tools = self::core_tools( 'mad4b-enrollment' );
		$tools = array_values( array_diff( $tools, self::chatgpt_internal_enrollment_mutations() ) );
		if ( class_exists( 'MAD4B_SCP_Developer_Authority' ) ) $tools = array_values( array_diff( $tools, MAD4B_SCP_Developer_Authority::enrollment_tools() ) );
		if ( class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) ) $tools = array_values( array_diff( $tools, MAD4B_SCP_Full_Staging_Authority::enrollment_tools() ) );
		return $tools;
	}

	public static function chatgpt_full_catalog_candidates() {
		$candidates = array_merge(
			self::core_tools( 'mad4b-read' ),
			self::core_tools( 'mad4b-chatgpt' ),
			class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) ? MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools() : array(),
			class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) ? MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools() : array(),
			self::chatgpt_enrollment_candidates(),
			self::core_tools( 'mad4b-content' ),
			self::core_tools( 'mad4b-admin' ),
			self::external_write_tools()
		);
		if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			$registry->register_defaults();
			foreach ( array( 'read', 'content', 'admin', 'write' ) as $surface ) $candidates = array_merge( $candidates, $registry->ability_names( $surface ) );
		}
		$candidates = array_values( array_unique( array_map( 'strval', $candidates ) ) );
		$candidates = array_values( array_diff( $candidates, array( 'mad4b/database-raw-query' ) ) );
		sort( $candidates, SORT_STRING );
		return $candidates;
	}

	public static function is_chatgpt_full_catalog_candidate( $ability_name ) {
		return in_array( (string) $ability_name, self::chatgpt_full_catalog_candidates(), true );
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
		// The compact ChatGPT transport contains only core/bootstrap dispatchers.
		// Do not build the full logical write catalog merely to decide whether one
		// of those direct transport tools is cacheable. Dynamic write resolution
		// remains live on the dedicated write/content/admin surfaces.
		$dynamic_write_resolution = 'mad4b-write' === $server_id
			|| ( 'mad4b-chatgpt' !== $server_id && self::is_external_write_candidate( $ability_name ) );
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

			// The compact direct transport is overwhelmingly Control Plane core.
			// Resolve those without scanning adapter/write catalogs. Underlying
			// provider mutations are deliberately hidden behind write-execute.
			if ( in_array( $ability_name, self::core_tools( 'mad4b-chatgpt' ), true ) ) return $remember( 'core' );
			foreach ( array( 'mad4b-read', 'mad4b-enrollment', 'mad4b-content', 'mad4b-admin' ) as $core_server ) {
				if ( in_array( $ability_name, self::core_tools( $core_server ), true ) ) return $remember( 'core' );
			}
			if ( self::is_external_write_candidate( $ability_name ) ) {
				$runtime_provider = self::provider_for_ability( 'mad4b-write', $ability_name );
				return $remember( null !== $runtime_provider ? $runtime_provider : self::provider_for_external_write_candidate( $ability_name ) );
			}
			if ( self::chatgpt_unified_catalog_enabled() ) {
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
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
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

	/**
	 * Identify the single MAD4B MCP server addressed by the current HTTP request.
	 * Every request still registers every route, but only the addressed server
	 * eagerly materializes its Ability -> MCP Tool DTOs. This avoids rebuilding
	 * hundreds of schemas for sibling servers during ChatGPT tools/list.
	 */
	private static function current_request_server_id() {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return '';

		$route = '';
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing observation only.
			$route = wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed and exact-matched below.
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed only.
		if ( '' === $route && '' !== $uri ) {
			$query = wp_parse_url( $uri, PHP_URL_QUERY );
			if ( is_string( $query ) && '' !== $query ) {
				$parsed = array();
				parse_str( $query, $parsed );
				if ( isset( $parsed['rest_route'] ) && is_string( $parsed['rest_route'] ) ) $route = $parsed['rest_route'];
			}
		}
		if ( '' === $route && '' !== $uri ) {
			$path = wp_parse_url( $uri, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$path = '/' . ltrim( rawurldecode( $path ), '/' );
				$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
				$needle = '/' . $prefix . '/';
				$offset = strpos( $path, $needle );
				$route = false !== $offset ? '/' . ltrim( substr( $path, $offset + strlen( $needle ) ), '/' ) : $path;
			}
		}
		$route = '/' . ltrim( rtrim( (string) $route, '/' ), '/' );
		foreach ( self::expected_server_ids() as $server_id ) {
			if ( '/mcp/' . $server_id === $route ) return $server_id;
		}
		return '';
	}

	private static function should_materialize_server_tools( $server_id, $target_server_id ) {
		return '' === (string) $target_server_id || hash_equals( (string) $target_server_id, (string) $server_id );
	}

	public function register_servers( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) { foreach ( self::expected_server_ids() as $id ) self::$registrations[ $id ] = array( 'registered' => false, 'error' => 'adapter_contract_unavailable', 'materialized' => false, 'tool_count' => 0 ); return; }
		$transport = '\\WP\\MCP\\Transport\\HttpTransport';
		$error_handler = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
		$observability = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';
		$target_server_id = self::current_request_server_id();
		$registry = null;
		$registry_for_surface = static function () use ( &$registry ) {
			if ( null === $registry ) {
				$registry = MAD4B_SCP_Adapter_Registry::instance();
				$registry->register_defaults();
			}
			return $registry;
		};

		$materialize = static function ( $server_id, $factory ) use ( $target_server_id ) {
			if ( ! self::should_materialize_server_tools( $server_id, $target_server_id ) ) return array();
			$tools = call_user_func( $factory );
			return is_array( $tools ) ? array_values( array_unique( $tools ) ) : array();
		};

		$read_tools = $materialize( 'mad4b-read', static function () use ( $registry_for_surface ) { $registry = $registry_for_surface(); return array_merge( self::core_tools( 'mad4b-read' ), $registry->ability_names( 'read' ) ); } );
		$chatgpt_tools = $materialize( 'mad4b-chatgpt', static function () { return self::chatgpt_tools(); } );
		$enrollment_tools = $materialize( 'mad4b-enrollment', static function () { return self::chatgpt_enrollment_candidates(); } );
		$content_tools = $materialize( 'mad4b-content', static function () use ( $registry_for_surface ) { $registry = $registry_for_surface(); return array_merge( self::core_tools( 'mad4b-content' ), $registry->ability_names( 'content' ) ); } );
		$write_tools = $materialize( 'mad4b-write', static function () { return self::write_tools(); } );
		$admin_tools = $materialize( 'mad4b-admin', static function () use ( $registry_for_surface ) { $registry = $registry_for_surface(); return array_merge( self::core_tools( 'mad4b-admin' ), $registry->ability_names( 'admin' ) ); } );
		$developer_tools = $materialize( 'mad4b-developer', static function () { return self::core_tools( 'mad4b-developer' ); } );
		$developer_breakglass_tools = $materialize( 'mad4b-developer-breakglass', static function () { return self::core_tools( 'mad4b-developer-breakglass' ); } );
		$breakglass_tools = $materialize( 'mad4b-breakglass', static function () { return self::core_tools( 'mad4b-breakglass' ); } );

		$chatgpt_write_ready = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective();
		if ( self::chatgpt_unified_catalog_enabled() ) {
			$chatgpt_description = 'Exact enrolled Staging governed gateway with one compact app catalog. Read/write universes remain available through governed discovery/info/dispatch. Low-level enrollment mutations stay internal; the composite Full Staging Authority apply appears only as a temporary plan-bound step-up tool when the exact current plan is ready and unblocked. Breakglass and Raw SQL remain excluded.';
		} else {
			$chatgpt_description = $chatgpt_write_ready ? 'ChatGPT governed gateway with compact read/write discovery transports. Provider writes remain fail-closed until runtime eligible, exactly granted and approved. Generic filesystem/database introspection and breakglass remain excluded.' : 'ChatGPT-safe read gateway. Generic filesystem/database inspection and all content/write/admin/breakglass mutation surfaces are excluded.';
		}

		$this->create( $adapter, 'mad4b-read', 'MAD4B Read MCP', 'Read-only discovery and diagnostics for WordPress, plugin adapters, files and database.', $read_tools, array( __CLASS__, 'can_read_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-read', $target_server_id ) );
		$this->create( $adapter, 'mad4b-chatgpt', 'MAD4B ChatGPT MCP', $chatgpt_description, $chatgpt_tools, array( __CLASS__, 'can_chatgpt_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-chatgpt', $target_server_id ) );
		$this->create( $adapter, 'mad4b-enrollment', 'MAD4B Enrollment MCP', 'Bounded Staging-only Site Profile feature/App/write bootstrap. Administrative bootstrap authority is separate from normal governed write authority.', $enrollment_tools, array( __CLASS__, 'can_enrollment_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-enrollment', $target_server_id ) );
		$this->create( $adapter, 'mad4b-content', 'MAD4B Content MCP', 'Governed content, media, SEO and plugin-specific editing abilities.', $content_tools, array( __CLASS__, 'can_content_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-content', $target_server_id ) );
		$this->create( $adapter, 'mad4b-write', 'MAD4B Write MCP', 'Unified governed write authority containing every runtime-eligible registered content/admin/write mutation explicitly annotated non-readonly. Cataloged provider mutations are projected per ability from capability certification; adapter-native runtime capability checks are hard mount gates; legacy providers retain exact runtime certification; breakglass is excluded.', $write_tools, array( __CLASS__, 'can_write_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-write', $target_server_id ) );
		$this->create( $adapter, 'mad4b-admin', 'MAD4B Admin MCP', 'Administrative governance, repair, mutation evidence and governed recovery abilities.', $admin_tools, array( __CLASS__, 'can_admin_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-admin', $target_server_id ) );
		$this->create( $adapter, 'mad4b-developer', 'MAD4B Developer MCP', 'Isolated explicit non-Production Developer Agent plane. It is never mounted on mad4b-chatgpt or mad4b-write; execution requires the exact configured developer agent, exact grant, one-time approval, budget, audit and runtime gate.', $developer_tools, array( __CLASS__, 'can_developer_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-developer', $target_server_id ) );
		$this->create( $adapter, 'mad4b-developer-breakglass', 'MAD4B Developer Breakglass MCP', 'Exceptional non-Production Developer Agent recovery plane. Disabled by default and separately gated from normal developer execution.', $developer_breakglass_tools, array( __CLASS__, 'can_developer_breakglass_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-developer-breakglass', $target_server_id ) );
		$this->create( $adapter, 'mad4b-breakglass', 'MAD4B Breakglass MCP', 'Exceptional recovery surface. Disabled unless explicitly enabled in wp-config.php.', $breakglass_tools, array( __CLASS__, 'can_breakglass_transport' ), $transport, $error_handler, $observability, self::should_materialize_server_tools( 'mad4b-breakglass', $target_server_id ) );
	}

	private function create( $adapter, $id, $name, $description, array $tools, $permission, $transport, $error_handler, $observability, $materialized = true ) {
		$result = $adapter->create_server( $id, 'mcp', $id, $name, $description, MAD4B_SCP_VERSION, array( $transport ), $error_handler, $observability, $tools, array(), array(), $permission );
		if ( is_wp_error( $result ) ) { self::$registrations[ $id ] = array( 'registered' => false, 'error' => $result->get_error_code(), 'materialized' => false, 'tool_count' => 0 ); error_log( '[MAD4B SCP] Failed creating ' . $id . ': ' . $result->get_error_message() ); return; }
		self::$registrations[ $id ] = array( 'registered' => true, 'error' => '', 'materialized' => (bool) $materialized, 'tool_count' => count( $tools ) );
	}
}

add_filter( 'option_mad4b_scp_external_inventory_attestation_v1', array( 'MAD4B_SCP_Servers', 'filter_external_inventory_attestation_runtime' ), 120 );
