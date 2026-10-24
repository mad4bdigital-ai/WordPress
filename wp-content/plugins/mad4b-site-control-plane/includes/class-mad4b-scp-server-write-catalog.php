<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Request-local write catalog composition, using the facade's guarded caches. */
trait MAD4B_SCP_Server_Write_Catalog {
	private static function core_write_candidates() {
		$candidates = array_merge(
			array(
				'mad4b/content-get-post', 'mad4b/content-update-post',
				'mad4b/content-job-create', 'mad4b/content-job-transition', 'mad4b/content-job-cancel',
				'mad4b/intent-registry-reconcile', 'mad4b/draft-apply', 'mad4b/data-processing-record-decision', 'mad4b/rights-record-apply', 'mad4b/data-processing-profile-apply', 'mad4b/data-processing-bound-decision-record', 'mad4b/rights-takedown-apply', 'mad4b/scheduler-backlog-enqueue', 'mad4b/scheduler-backlog-claim-next', 'mad4b/scheduler-backlog-heartbeat', 'mad4b/scheduler-backlog-complete', 'mad4b/scheduler-backlog-reconcile', 'mad4b/decommission-quiesce-apply', 'mad4b/decommission-resume-apply', 'mad4b/decommission-finalize-apply', 'mad4b/portability-import-quarantine-apply',
			),
			array(
                // Existing Host Bridge is native signed-job and exact-approval
                // fenced; cataloguing this does not grant Host authority.
                'mad4b/host-operation-apply', 'mad4b/optional-governance-apply',
                // Existing ChatGPT write-execute still enforces the governed
                // Write authority, exact OAuth/actor grants and approval ticket.
                // The transfer Ability itself additionally enforces scoped
                // Staging-only media permission and the global write kill switch.
                // Never surface this as an unreviewed direct ChatGPT step-up.
                'mad4b/attachment-transfer',
                'mad4b/native-recovery-start', 'mad4b/native-recovery-cleanup',
                'mad4b/deployment-binding-setup-apply', 'mad4b/deployment-binding-native-apply',
                // Exact Staging-native bootstrap was previously visible only
                // as Admin and not mounted on the governed Write authority,
                // so approval planning failed with target_not_write. The
                // site opt-in, adapter-projected build-only start and reviewed install each
                // retain separate owner tickets and all original protections.
                'mad4b/wordpress-native-candidate-opt-in-apply',
                'mad4b/wp-native-self-approve-install',
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/plugin-package-apply', 'mad4b/plugin-remote-update-apply', 'mad4b/control-plane-upload-apply', 'mad4b/control-plane-native-apply', 'mad4b/control-plane-selected-head-apply', 'mad4b/runtime-release-set-apply', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			)
		);
		if ( class_exists( 'MAD4B_SCP_Adaptive_Search_Intelligence' ) ) $candidates = array_merge( $candidates, MAD4B_SCP_Adaptive_Search_Intelligence::ability_names( 'write' ) );
		if ( class_exists( 'MAD4B_SCP_Context_Authority' )
			&& function_exists( 'wp_has_ability' )
			&& wp_has_ability( MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY ) ) {
			$candidates[] = MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY;
		}
		if ( class_exists( 'MAD4B_SCP_AI_Approval' )
			&& function_exists( 'wp_has_ability' )
			&& wp_has_ability( MAD4B_SCP_AI_Approval::ABILITY ) ) {
			$candidates[] = MAD4B_SCP_AI_Approval::ABILITY;
		}
		return array_values( array_unique( $candidates ) );
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

	public static function catalog_external_write_tools() {
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

	public static function catalog_blocked_write_tools() {
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

	// Resolve structural provider identity; runtime mount/certification remains a separate live authorization gate.
	public static function provider_for_capability_descriptor( $server_id, $ability_name ) {
		$server_id = sanitize_key( (string) $server_id );
		$ability_name = (string) $ability_name;
		if ( 'mad4b-write' === $server_id ) {
			if ( in_array( $ability_name, self::core_write_candidates(), true ) && self::registered_mutation_ability( $ability_name ) ) return 'core';
			$candidates = self::registered_adapter_write_candidates();
			if ( isset( $candidates[ $ability_name ] ) && self::registered_mutation_ability( $ability_name ) ) return (string) $candidates[ $ability_name ];
		}
		if ( in_array( $server_id, array( 'mad4b-content', 'mad4b-admin' ), true ) ) {
			// Canonical descriptor identity must not enumerate runtime Write eligibility
			// for every Content/Admin ability. Those live gates still run in
			// provider_for_ability(), original materialization and authorization.
			if ( in_array( $ability_name, self::declared_core_tools( $server_id ), true ) ) return 'core';
			if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return null;
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			$registry->register_defaults();
			$surface = self::surface_for_server( $server_id );
			foreach ( $registry->all() as $adapter ) {
				$map = $adapter->ability_names();
				if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) && in_array( $ability_name, $map[ $surface ], true ) ) {
					return method_exists( $adapter, 'provider_key' ) ? $adapter->provider_key() : sanitize_key( (string) $adapter->id() );
				}
			}
			return null;
		}
		return self::provider_for_ability( $server_id, $ability_name );
	}

}
