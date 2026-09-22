<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tenant-bound governed write authority for ChatGPT/MCP.
 *
 * Installation is never authority. The write plane becomes eligible only after
 * an exact MAD4B Site Profile is enrolled for the live origin/environment and
 * its governed-write feature is enabled. OAuth remains identity only; execution
 * still requires an enabled NHI, exact provider/ability grant, runtime/provider
 * certification, mutation budget, one-time exact human approval, and audit.
 *
 * Breakglass/raw SQL are never included in this authority.
 */
final class MAD4B_SCP_Staging_Write_Authority {
	const CONTRACT = 'mad4b.governed-write-authority.v2';
	const CANDIDATE_BINDING_CONTRACT = 'mad4b.governed-write-authority-candidate-binding.v1';
	const CANDIDATE_BOOTSTRAP_CONTRACT = 'mad4b.governed-write-candidate-bootstrap.v1';
	const CANDIDATE_BOOTSTRAP_ABILITY = 'mad4b/acceptance-target-provision';
	const OPTION = 'mad4b_scp_staging_write_authority_v1';
	const VERSION = 2;
	const APPROVAL_INPUT_KEY = '_mad4b_approval_ticket_id';
	const CONTEXT_RECEIPT_INPUT_KEY = '_mad4b_context_receipt';

	private static $booted = false;
	private static $reconciling = false;
	private static $status = array();
	private static $candidate_identity = null;

	public static function bootstrap() {
		$status = self::base_status();
		if ( empty( $status['eligible'] ) ) {
			self::$status = $status;
			return $status;
		}

		if ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true !== constant( 'MAD4B_MCP_MUTATION_ENABLED' ) ) {
			$status['blocker'] = 'explicit_mutation_disabled';
			$status['state'] = 'blocked';
			self::$status = $status;
			return $status;
		}

		if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) {
			define( 'MAD4B_MCP_MUTATION_ENABLED', true );
		}
		$status['mutation_gate_configured'] = true;
		$status['configuration_source'] = 'site_profile';
		$status = self::restore_persisted_ready_status( $status );
		self::$status = $status;
		return $status;
	}

	private static function restore_persisted_ready_status( array $current ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored )
			|| ! isset( $stored['contract'] )
			|| self::CONTRACT !== (string) $stored['contract']
			|| empty( $stored['ready'] )
			|| 'ready' !== ( isset( $stored['state'] ) ? (string) $stored['state'] : '' )
			|| ! empty( $stored['blocker'] )
			|| empty( $stored['agent_public_id'] )
			|| ! preg_match( '/^[a-f0-9-]{36}$/i', (string) $stored['agent_public_id'] )
			|| empty( $stored['write_tool_count'] )
			|| empty( $stored['write_inventory_fingerprint'] )
			|| ! preg_match( '/^[a-f0-9]{64}$/', (string) $stored['write_inventory_fingerprint'] ) ) {
			return $current;
		}
		foreach ( array( 'site_uuid', 'site_profile_revision', 'site_profile_digest', 'environment', 'origin' ) as $key ) {
			if ( ! array_key_exists( $key, $stored ) || ! array_key_exists( $key, $current ) || (string) $stored[ $key ] !== (string) $current[ $key ] ) return $current;
		}
		$stored['mutation_gate_configured'] = true;
		$stored['configuration_source'] = 'site_profile';
		$stored['restored_from_persisted_authority'] = true;
		return $stored;
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		self::bootstrap();

		add_filter( 'wp_register_ability_args', array( __CLASS__, 'augment_write_ability' ), 70, 2 );
		add_filter( 'mad4b_scp_low_impact_requires_approval', array( __CLASS__, 'force_remote_write_approval' ), 100, 4 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_status_ability' ), 35 );

		// Grant/subject reconciliation is mutation authority and must never run
		// automatically during Abilities bootstrap or ordinary wp-admin lifecycle.
		// Explicit bounded bootstrap surfaces call reconcile() after exact validation.
	}

	public static function eligible() {
		$status = self::base_status();
		return ! empty( $status['eligible'] );
	}

	public static function effective() {
		// Authorization hot paths must not recursively rebuild the provider/write
		// inventory. Only an explicitly reconciled persisted authority may enable
		// this predicate. Packaged runtimes additionally require the persisted
		// authority to be bound to the exact current package candidate.
		$status = self::status();
		if ( empty( $status['ready'] ) ) return false;
		$binding = self::candidate_binding_status();
		return empty( $binding['required'] ) || ! empty( $binding['match'] );
	}

	public static function status() {
		$status = self::raw_status();
		return array_merge( $status, self::approval_policy_projection() );
	}

	public static function approval_policy_projection( $candidate_bootstrap_exception_active = null ) {
		$resolved = is_bool( $candidate_bootstrap_exception_active );
		$active = true === $candidate_bootstrap_exception_active;
		$policy = array(
			'approval_policy_contract' => 'mad4b.remote-write-approval-policy.v1',
			'approval_policy_scope' => $resolved ? 'effective_runtime' : 'capability_definition',
			'approval_policy_effective_state_resolved' => $resolved,
			'normal_remote_writes_require_exact_approval' => true,
			'candidate_bootstrap_exception_defined' => true,
			'candidate_bootstrap_contract' => self::CANDIDATE_BOOTSTRAP_CONTRACT,
			'remote_write_prior_approval_exceptions' => array( self::CANDIDATE_BOOTSTRAP_ABILITY ),
		);
		if ( $resolved ) {
			$policy['candidate_bootstrap_exception_active'] = $active;
			$policy['all_remote_writes_require_exact_approval'] = ! $active;
			$policy['remote_write_approval_policy'] = $active ? 'exact_approval_except_bounded_candidate_bootstrap' : 'exact_approval_required';
			$policy['remote_write_approval_exceptions'] = $active ? array( self::CANDIDATE_BOOTSTRAP_ABILITY ) : array();
		} else {
			// Definition scope declares the one possible exception but deliberately
			// does not claim whether it is active for the current runtime.
			$policy['all_remote_writes_require_exact_approval'] = false;
			$policy['remote_write_approval_policy'] = 'exact_approval_except_bounded_candidate_bootstrap';
		}
		return $policy;
	}

	private static function raw_status() {
		if ( ! empty( self::$status ) && isset( self::$status['contract'] ) ) return self::$status;
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['contract'] ) && self::CONTRACT === (string) $stored['contract'] ) return $stored;
		return self::base_status();
	}

	public static function candidate_binding_status() {
		$status = self::raw_status();
		$current = self::current_candidate_identity();
		$stored_sha = isset( $status['source_commit_sha'] ) ? strtolower( trim( (string) $status['source_commit_sha'] ) ) : '';
		$stored_build = isset( $status['build_fingerprint'] ) ? strtolower( trim( (string) $status['build_fingerprint'] ) ) : '';
		$stored_bound = 1 === preg_match( '/^[a-f0-9]{40}$/', $stored_sha ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $stored_build );
		// Once authority has ever been package-bound, losing the current
		// provenance manifest is itself a stale-candidate condition.
		$required = ! empty( $current['available'] ) || $stored_bound;
		$match = ! empty( $current['available'] )
			&& $stored_bound
			&& hash_equals( (string) $current['source_commit_sha'], $stored_sha )
			&& hash_equals( (string) $current['build_fingerprint'], $stored_build );
		return array(
			'contract' => self::CANDIDATE_BINDING_CONTRACT,
			'required' => $required,
			'stored_bound' => $stored_bound,
			'match' => $match,
			'stored_source_commit_sha' => $stored_sha,
			'stored_build_fingerprint' => $stored_build,
			'current_source_commit_sha' => isset( $current['source_commit_sha'] ) ? (string) $current['source_commit_sha'] : '',
			'current_build_fingerprint' => isset( $current['build_fingerprint'] ) ? (string) $current['build_fingerprint'] : '',
			'current_package_manifest_digest' => isset( $current['package_manifest_digest'] ) ? (string) $current['package_manifest_digest'] : '',
			'current_artifact_identity' => isset( $current['artifact_identity'] ) ? (string) $current['artifact_identity'] : '',
		);
	}

	public static function bind_candidate_identity( $source_commit_sha, $build_fingerprint ) {
		$source_commit_sha = strtolower( trim( (string) $source_commit_sha ) );
		$build_fingerprint = strtolower( trim( (string) $build_fingerprint ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $source_commit_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $build_fingerprint ) ) {
			return new WP_Error( 'mad4b_write_authority_candidate_invalid', 'Exact source commit and build fingerprint are required to bind write authority.' );
		}
		$current = self::current_candidate_identity();
		if ( empty( $current['available'] )
			|| ! hash_equals( (string) $current['source_commit_sha'], $source_commit_sha )
			|| ! hash_equals( (string) $current['build_fingerprint'], $build_fingerprint ) ) {
			return new WP_Error( 'mad4b_write_authority_candidate_mismatch', 'Current package candidate does not match the reviewed reconciliation candidate.' );
		}
		$status = self::status();
		if ( empty( $status['ready'] ) || empty( $status['write_inventory_fingerprint'] ) || empty( $status['agent_public_id'] ) ) {
			return new WP_Error( 'mad4b_write_authority_not_ready_for_candidate_binding', 'Write authority must be reconciled before binding the exact package candidate.' );
		}
		$status['candidate_binding_contract'] = self::CANDIDATE_BINDING_CONTRACT;
		$status['source_commit_sha'] = $source_commit_sha;
		$status['build_fingerprint'] = $build_fingerprint;
		$status['package_manifest_digest'] = isset( $current['package_manifest_digest'] ) ? (string) $current['package_manifest_digest'] : '';
		$status['artifact_identity'] = isset( $current['artifact_identity'] ) ? (string) $current['artifact_identity'] : '';
		$status['candidate_bound_at'] = gmdate( 'c' );
		update_option( self::OPTION, $status, false );
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored )
			|| ! isset( $stored['source_commit_sha'], $stored['build_fingerprint'] )
			|| ! hash_equals( $source_commit_sha, (string) $stored['source_commit_sha'] )
			|| ! hash_equals( $build_fingerprint, (string) $stored['build_fingerprint'] ) ) {
			return new WP_Error( 'mad4b_write_authority_candidate_persist_failed', 'Exact package candidate binding could not be persisted.' );
		}
		self::$status = $stored;
		return $stored;
	}

	private static function current_candidate_identity() {
		if ( null !== self::$candidate_identity ) return self::$candidate_identity;
		$base = array(
			'available' => false,
			'source_commit_sha' => '',
			'build_fingerprint' => '',
			'package_manifest_digest' => '',
			'artifact_identity' => '',
		);
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) return self::$candidate_identity = $base;
		$raw = file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) return self::$candidate_identity = $base;
		$sha = isset( $data['source_commit_sha'] ) ? strtolower( trim( (string) $data['source_commit_sha'] ) ) : '';
		$build = isset( $data['build_fingerprint'] ) ? strtolower( trim( (string) $data['build_fingerprint'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $build ) ) return self::$candidate_identity = $base;
		$base['available'] = true;
		$base['source_commit_sha'] = $sha;
		$base['build_fingerprint'] = $build;
		$base['package_manifest_digest'] = isset( $data['package_manifest_digest'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $data['package_manifest_digest'] ) ? strtolower( (string) $data['package_manifest_digest'] ) : '';
		$base['artifact_identity'] = isset( $data['artifact_identity'] ) ? sanitize_text_field( (string) $data['artifact_identity'] ) : '';
		return self::$candidate_identity = $base;
	}

	public static function write_tools() {
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) ) return array();
		$tools = MAD4B_SCP_Servers::write_tools();
		$tools = array_values( array_unique( array_filter( array_map( 'strval', is_array( $tools ) ? $tools : array() ) ) ) );
		return array_values( array_diff( $tools, array( 'mad4b/database-raw-query' ) ) );
	}

	public static function is_write_ability( $ability_name ) {
		return in_array( (string) $ability_name, self::write_tools(), true );
	}

	public static function approval_ticket_from_input( $input ) {
		if ( ! is_array( $input ) || ! isset( $input[ self::APPROVAL_INPUT_KEY ] ) ) return '';
		$value = strtolower( trim( (string) $input[ self::APPROVAL_INPUT_KEY ] ) );
		return preg_match( '/^[a-f0-9-]{36}$/', $value ) ? $value : '';
	}

	public static function context_receipt_from_input( $input ) {
		if ( ! is_array( $input ) || ! isset( $input[ self::CONTEXT_RECEIPT_INPUT_KEY ] ) || ! is_array( $input[ self::CONTEXT_RECEIPT_INPUT_KEY ] ) ) return array();
		return $input[ self::CONTEXT_RECEIPT_INPUT_KEY ];
	}

	public static function authorization_input( $input ) {
		if ( ! is_array( $input ) ) return $input;
		$clean = $input;
		unset( $clean[ self::APPROVAL_INPUT_KEY ] );
		return $clean;
	}

	public static function provider_input( $input ) {
		$clean = self::authorization_input( $input );
		if ( is_array( $clean ) ) unset( $clean[ self::CONTEXT_RECEIPT_INPUT_KEY ] );
		return $clean;
	}

	/**
	 * Narrow pre-binding bootstrap for the isolated acceptance target.
	 *
	 * This does not grant mutation authority. It only allows the stable ChatGPT
	 * transport to delegate this one exact ability to mad4b-write while the
	 * persisted authority is ready but stale against a newly deployed package.
	 * Central authorization still resolves the OAuth/NHI identity, exact grant,
	 * budget and audit path. A prior approval ticket is intentionally not required
	 * for this bootstrap operation because approval planning itself is candidate-
	 * bound and would otherwise recreate the same circular dependency.
	 */
	public static function candidate_bootstrap_status( $ability_name, $input = null ) {
		$ability_name = (string) $ability_name;
		$blockers = array();
		$input_binding_verified = false;
		$target = array();
		$binding = self::candidate_binding_status();
		$authority = self::raw_status();

		if ( self::CANDIDATE_BOOTSTRAP_ABILITY !== $ability_name ) $blockers[] = 'ability_not_bootstrap_allowlisted';
		if ( ! self::eligible() ) $blockers[] = 'write_authority_ineligible';
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) {
			$blockers[] = 'site_profile_missing';
		} else {
			if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) $blockers[] = 'environment_not_staging';
			if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) $blockers[] = 'site_profile_origin_mismatch';
			if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) $blockers[] = 'site_profile_write_disabled';
		}
		if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) || true !== constant( 'MAD4B_MCP_MUTATION_ENABLED' ) ) $blockers[] = 'mutation_gate_disabled';
		if ( empty( $authority['ready'] ) || ! empty( $authority['blocker'] ) ) $blockers[] = 'persisted_authority_not_ready';
		if ( empty( $authority['agent_public_id'] ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/i', (string) $authority['agent_public_id'] ) ) $blockers[] = 'canonical_agent_missing';
		if ( empty( $authority['write_inventory_fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $authority['write_inventory_fingerprint'] ) ) $blockers[] = 'write_inventory_unbound';
		if ( ! empty( $authority['breakglass_included'] ) || in_array( 'mad4b/database-raw-query', self::write_tools(), true ) ) $blockers[] = 'breakglass_leak';
		if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) $blockers[] = 'breakglass_enabled';

		if ( empty( $binding['required'] ) ) $blockers[] = 'candidate_binding_not_required';
		if ( ! empty( $binding['match'] ) ) $blockers[] = 'candidate_already_bound';
		$current_sha = isset( $binding['current_source_commit_sha'] ) ? strtolower( (string) $binding['current_source_commit_sha'] ) : '';
		$current_build = isset( $binding['current_build_fingerprint'] ) ? strtolower( (string) $binding['current_build_fingerprint'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $current_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $current_build ) ) $blockers[] = 'current_candidate_provenance_invalid';

		if ( is_array( $input ) ) {
			$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
			$expected_build = isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '';
			$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
			$expected_digest = isset( $input['expected_profile_digest'] ) ? strtolower( trim( (string) $input['expected_profile_digest'] ) ) : '';
			$current_revision = class_exists( 'MAD4B_SCP_Site_Profile' ) ? (int) MAD4B_SCP_Site_Profile::revision() : 0;
			$current_digest = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || ! hash_equals( $current_sha, $expected_sha ) ) $blockers[] = 'candidate_input_sha_mismatch';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_build ) || ! hash_equals( $current_build, $expected_build ) ) $blockers[] = 'candidate_input_build_mismatch';
			if ( $expected_revision < 1 || $expected_revision !== $current_revision ) $blockers[] = 'candidate_input_revision_mismatch';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) $blockers[] = 'candidate_input_profile_mismatch';
			$input_binding_verified = empty( array_intersect( $blockers, array( 'candidate_input_sha_mismatch', 'candidate_input_build_mismatch', 'candidate_input_revision_mismatch', 'candidate_input_profile_mismatch' ) ) );
		} else {
			$blockers[] = 'exact_bootstrap_input_required';
		}

		if ( class_exists( 'MAD4B_SCP_Agent_Registry' ) && class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::is_ready() ) {
			$counts = MAD4B_SCP_Agent_Registry::counts();
			if ( ! empty( $counts['wildcard_grants'] ) ) $blockers[] = 'wildcard_grants_detected';
		} else {
			$blockers[] = 'grant_registry_unavailable';
		}

		$bootstrap_provider = null;
		if ( ! in_array( self::CANDIDATE_BOOTSTRAP_ABILITY, self::write_tools(), true ) ) $blockers[] = 'bootstrap_ability_not_runtime_eligible';
		if ( class_exists( 'MAD4B_SCP_Servers' ) ) {
			$bootstrap_provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', self::CANDIDATE_BOOTSTRAP_ABILITY );
			if ( null === $bootstrap_provider ) $blockers[] = 'bootstrap_provider_unmounted';
			if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', self::CANDIDATE_BOOTSTRAP_ABILITY ) ) $blockers[] = 'bootstrap_ability_unmounted';
		} else {
			$blockers[] = 'server_registry_unavailable';
		}

		// Actual execution eligibility additionally proves the same OAuth/NHI and
		// exact ability/provider grant that central authorization will re-check.
		// Policy projection without an input remains read-only and does not require
		// an active bearer identity.
		if ( is_array( $input ) ) {
			if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
				$blockers[] = 'bootstrap_identity_registry_unavailable';
			} else {
				$identity = MAD4B_SCP_Identity_Context::current();
				if ( is_wp_error( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) {
					$blockers[] = 'bootstrap_oauth_identity_required';
				} else {
					$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
					if ( is_wp_error( $agent ) || empty( $agent['id'] ) || 'chatgpt-governed-write' !== ( isset( $agent['slug'] ) ? (string) $agent['slug'] : '' ) || 'staging' !== ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' ) ) {
						$blockers[] = 'bootstrap_canonical_agent_required';
					} elseif ( null === $bootstrap_provider ) {
						$blockers[] = 'bootstrap_exact_grant_provider_missing';
					} else {
						$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-write', self::CANDIDATE_BOOTSTRAP_ABILITY, sanitize_key( (string) $bootstrap_provider ) );
						if ( ! is_array( $grant ) || 'allow' !== ( isset( $grant['effect'] ) ? (string) $grant['effect'] : '' ) || 'staging' !== ( isset( $grant['environment'] ) ? (string) $grant['environment'] : '' ) ) $blockers[] = 'bootstrap_exact_nhi_grant_missing';
					}
				}
			}
		}

		if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			$registry->register_defaults();
			$adapter = null;
			foreach ( $registry->all() as $candidate_adapter ) {
				if ( is_object( $candidate_adapter ) && method_exists( $candidate_adapter, 'id' ) && 'acceptance-target' === (string) $candidate_adapter->id() ) { $adapter = $candidate_adapter; break; }
			}
			if ( $adapter && method_exists( $adapter, 'target_status' ) ) {
				$target = $adapter->target_status();
				if ( is_wp_error( $target ) ) {
					$blockers[] = 'acceptance_target_status_error';
					$target = array( 'error_code' => $target->get_error_code() );
				} else {
					if ( ! empty( $target['exists'] ) ) $blockers[] = 'acceptance_target_already_exists';
					if ( empty( $target['safe_for_mutation_acceptance'] ) ) $blockers[] = 'acceptance_target_not_safe';
					if ( empty( $target['isolated'] ) ) $blockers[] = 'acceptance_target_not_isolated';
				}
			} else {
				$blockers[] = 'acceptance_target_adapter_unavailable';
			}
		} else {
			$blockers[] = 'adapter_registry_unavailable';
		}

		$blockers = array_values( array_unique( $blockers ) );
		$policy_blockers = array_values( array_diff( $blockers, array( 'exact_bootstrap_input_required' ) ) );
		return array(
			'contract' => self::CANDIDATE_BOOTSTRAP_CONTRACT,
			'ability' => $ability_name,
			'applicable' => self::CANDIDATE_BOOTSTRAP_ABILITY === $ability_name,
			'policy_available' => empty( $policy_blockers ),
			'allowed' => empty( $blockers ) && $input_binding_verified,
			'blockers' => $blockers,
			'input_binding_verified' => $input_binding_verified,
			'prior_approval_required' => false,
			'exact_nhi_grant_verified' => is_array( $input ) && ! in_array( 'bootstrap_exact_nhi_grant_missing', $blockers, true ) && ! in_array( 'bootstrap_exact_grant_provider_missing', $blockers, true ) && ! in_array( 'bootstrap_oauth_identity_required', $blockers, true ) && ! in_array( 'bootstrap_canonical_agent_required', $blockers, true ),
			'exact_nhi_grant_required_downstream' => true,
			'budget_required_downstream' => true,
			'audit_required_downstream' => true,
			'breakglass_allowed' => false,
			'candidate_binding_match' => ! empty( $binding['match'] ),
			'current_source_commit_sha' => $current_sha,
			'current_build_fingerprint' => $current_build,
			'acceptance_target' => is_array( $target ) ? $target : array(),
		);
	}

	public static function candidate_bootstrap_allowed( $ability_name, $input = null ) {
		$status = self::candidate_bootstrap_status( $ability_name, $input );
		return ! empty( $status['allowed'] );
	}

	public static function remote_scope_delegation_allowed( array $identity, $server_id, $ability_name, $input ) {
		$bootstrap = self::candidate_bootstrap_allowed( $ability_name, $input );
		if ( ( ! self::effective() && ! $bootstrap ) || 'mad4b-write' !== sanitize_key( (string) $server_id ) || ! self::is_write_ability( $ability_name ) ) return false;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return false;
		if ( $bootstrap ) return true;
		return '' !== self::approval_ticket_from_input( $input );
	}

	public static function force_remote_write_approval( $required, $ability_name, $provider, $input ) {
		if ( $required ) return true;
		if ( ! self::is_write_ability( $ability_name ) ) return $required;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $required;
		$current = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		if ( ! in_array( $current, array( 'mad4b-chatgpt', 'mad4b-write' ), true ) ) return $required;
		if ( self::candidate_bootstrap_allowed( $ability_name, $input ) ) return false;
		return self::effective() ? true : $required;
	}

	public static function augment_write_ability( $args, $name ) {
		if ( ! is_array( $args ) || 'mad4b/approval-plan' === (string) $name ) return $args;
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return $args;

		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			if ( ! isset( $args['input_schema']['properties'] ) || ! is_array( $args['input_schema']['properties'] ) ) $args['input_schema']['properties'] = array();
			$args['input_schema']['properties'][ self::APPROVAL_INPUT_KEY ] = array(
				'type' => 'string',
				'minLength' => 36,
				'maxLength' => 36,
				'pattern' => '^[A-Fa-f0-9-]{36}$',
				'description' => 'One-time exact MAD4B approval ticket required for remote governed writes.',
			);
			$args['input_schema']['properties'][ self::CONTEXT_RECEIPT_INPUT_KEY ] = array(
				'type' => 'object',
				'additionalProperties' => true,
				'description' => 'Governed Context Receipt returned by mad4b/skill-get. Required automatically for brand-bearing content text mutations and bound into the exact approval payload.',
			);
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original = $args['execute_callback'];
			$args['execute_callback'] = static function ( $input = null ) use ( $original ) {
				$clean = MAD4B_SCP_Staging_Write_Authority::provider_input( $input );
				return call_user_func( $original, $clean );
			};
		}
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_governed_write_authority'] = self::CONTRACT;
		$args['meta']['mcp']['mad4b_remote_write_approval_required'] = true;
		if ( self::CANDIDATE_BOOTSTRAP_ABILITY === (string) $name ) {
			$args['meta']['mcp']['mad4b_candidate_bootstrap_contract'] = self::CANDIDATE_BOOTSTRAP_CONTRACT;
			$args['meta']['mcp']['mad4b_candidate_bootstrap_prior_approval_exception'] = true;
		}
		return $args;
	}

	public static function reconciliation_plan() {
		$status = self::base_status();
		$tools = self::write_tools();
		$rows = array();
		$missing = array();
		$stale = array();
		$existing_count = 0;
		$agent = self::agent_by_slug( self::agent_slug() );
		$environment = self::current_environment();

		if ( is_array( $agent ) && ! empty( $agent['id'] ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$desired = array();
			foreach ( $tools as $ability ) {
				$provider = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability ) : null;
				$row = array(
					'ability' => (string) $ability,
					'provider' => null === $provider ? '' : (string) $provider,
					'mounted' => null !== $provider,
					'exact_grant_present' => false,
				);
				if ( null !== $provider ) {
					$key = (string) $ability . "\0" . (string) $provider;
					$desired[ $key ] = true;
					$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-write', $ability, $provider );
					if ( ! is_wp_error( $grant ) && $environment === (string) $grant['environment'] ) {
						$row['exact_grant_present'] = true;
						++$existing_count;
					} else {
						$missing[] = array( 'ability' => (string) $ability, 'provider' => (string) $provider );
					}
				} else {
					$missing[] = array( 'ability' => (string) $ability, 'provider' => '', 'reason' => 'write_provider_unmounted' );
				}
				$rows[] = $row;
			}

			$grants = MAD4B_SCP_Agent_Registry::grants_for_agent( (int) $agent['id'], 'mad4b-write' );
			foreach ( $grants as $grant ) {
				if ( ! is_array( $grant ) || 'allow' !== (string) $grant['effect'] ) continue;
				$key = (string) $grant['ability_name'] . "\0" . (string) $grant['provider'];
				if ( ! isset( $desired[ $key ] ) || $environment !== (string) $grant['environment'] ) {
					$stale[] = array(
						'id' => isset( $grant['id'] ) ? (int) $grant['id'] : 0,
						'ability' => isset( $grant['ability_name'] ) ? (string) $grant['ability_name'] : '',
						'provider' => isset( $grant['provider'] ) ? (string) $grant['provider'] : '',
						'environment' => isset( $grant['environment'] ) ? (string) $grant['environment'] : '',
					);
				}
			}
		}

		return array(
			'contract' => 'mad4b.governed-write-authority-reconciliation-plan.v1',
			'read_only' => true,
			'mutation_performed' => false,
			'eligible' => ! empty( $status['eligible'] ),
			'current_ready' => ! empty( self::status()['ready'] ),
			'environment' => $environment,
			'agent_present' => is_array( $agent ) && ! empty( $agent['id'] ),
			'agent_public_id' => is_array( $agent ) && isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '',
			'write_tool_count' => count( $tools ),
			'exact_grants_existing' => $existing_count,
			'exact_grants_missing_count' => count( $missing ),
			'exact_grants_missing' => $missing,
			'stale_allow_grants_count' => count( $stale ),
			'stale_allow_grants' => $stale,
			'wildcard_grants' => is_array( $agent ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ? (int) MAD4B_SCP_Agent_Registry::counts()['wildcard_grants'] : 0,
			'breakglass_included' => in_array( 'mad4b/database-raw-query', $tools, true ),
			'candidate_binding' => self::candidate_binding_status(),
			'rows' => $rows,
			'apply_requires_explicit_operator_action' => true,
			'apply_method' => 'MAD4B_SCP_Staging_Write_Authority::reconcile',
		);
	}

	public static function reconcile() {
		if ( self::$reconciling ) return self::status();
		self::$reconciling = true;
		$status = self::base_status();
		$status['mutation_gate_configured'] = defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === constant( 'MAD4B_MCP_MUTATION_ENABLED' );

		if ( empty( $status['eligible'] ) || empty( $status['mutation_gate_configured'] ) ) {
			if ( ! empty( $status['eligible'] ) && empty( $status['mutation_gate_configured'] ) ) {
				$status['blocker'] = 'mutation_gate_disabled';
				$status['state'] = 'blocked';
			}
			self::deprovision_managed_authority( $status );
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) {
			$status['blocker'] = 'governance_schema_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) {
			$status['blocker'] = 'audit_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		if ( ! function_exists( 'wp_get_ability' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) {
			$status['blocker'] = 'abilities_runtime_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$issuer = self::oauth_issuer();
		if ( '' === $issuer ) {
			$status['blocker'] = 'oauth_issuer_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}

		$user_ids = self::enrolled_user_ids();
		if ( empty( $user_ids ) ) {
			$status['blocker'] = 'oauth_subject_unavailable';
			$status['state'] = 'blocked';
			self::$status = $status;
			self::$reconciling = false;
			return $status;
		}
		foreach ( $user_ids as $user_id ) {
			if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_connect_user( $user_id ) ) {
				$status['blocker'] = 'oauth_subject_not_enrolled';
				$status['state'] = 'blocked';
				$status['invalid_user_id'] = absint( $user_id );
				self::$status = $status;
				self::$reconciling = false;
				return $status;
			}
		}

		$environment = self::current_environment();
		$agent_slug = self::agent_slug();
		$agent = self::agent_by_slug( $agent_slug );
		$primary_user_id = (int) reset( $user_ids );
		$label = 'ChatGPT Governed Write';
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
			$display = trim( (string) MAD4B_SCP_Site_Profile::display_name() );
			if ( '' !== $display ) $label .= ' — ' . $display;
		}
		$label = substr( $label, 0, 191 );

		if ( ! $agent ) {
			$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
				'slug' => $agent_slug,
				'label' => $label,
				'status' => 'enabled',
				'wp_user_id' => $primary_user_id,
				'environment' => $environment,
			) );
			if ( is_wp_error( $agent ) ) return self::finish_blocked( $status, $agent->get_error_code() );
		} else {
			$changes = array();
			if ( 'enabled' !== (string) $agent['status'] ) $changes['status'] = 'enabled';
			if ( $environment !== (string) $agent['environment'] ) $changes['environment'] = $environment;
			if ( (int) $agent['wp_user_id'] !== $primary_user_id ) $changes['wp_user_id'] = $primary_user_id;
			if ( $label !== (string) $agent['label'] ) $changes['label'] = $label;
			if ( ! empty( $changes ) ) {
				$updated = MAD4B_SCP_Agent_Registry::update_agent( $agent['public_id'], $changes, (int) $agent['revision'] );
				if ( is_wp_error( $updated ) ) return self::finish_blocked( $status, $updated->get_error_code() );
				$agent = $updated;
			}
		}

		$desired_subjects = array();
		$subject_blockers = array();
		foreach ( $user_ids as $user_id ) {
			$fingerprint = self::subject_fingerprint( $issuer, $user_id );
			$desired_subjects[ $fingerprint ] = true;
			$identity = array( 'authenticated' => true, 'subject_type' => 'oauth', 'subject_fingerprint' => $fingerprint );
			$bound = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
			if ( is_wp_error( $bound ) ) {
				if ( 'mad4b_nhi_subject_unbound' !== $bound->get_error_code() ) {
					$subject_blockers[] = $bound->get_error_code() . ':user:' . $user_id;
					continue;
				}
				$binding = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], 'oauth', $fingerprint, 'Local OAuth user:' . $user_id );
				if ( is_wp_error( $binding ) ) $subject_blockers[] = $binding->get_error_code() . ':user:' . $user_id;
			} elseif ( (int) $bound['id'] !== (int) $agent['id'] ) {
				$subject_blockers[] = 'oauth_subject_bound_to_other_agent:user:' . $user_id;
			}
		}

		$subjects = MAD4B_SCP_Agent_Registry::subjects_for_agent( $agent['id'], 'oauth' );
		$subjects_disabled = 0;
		foreach ( $subjects as $subject ) {
			$fingerprint = isset( $subject['subject_fingerprint'] ) ? strtolower( (string) $subject['subject_fingerprint'] ) : '';
			if ( isset( $desired_subjects[ $fingerprint ] ) ) continue;
			if ( 'enabled' !== (string) $subject['status'] ) continue;
			$disabled = MAD4B_SCP_Agent_Registry::set_subject_status( $agent['public_id'], 'oauth', $fingerprint, 'disabled' );
			if ( is_wp_error( $disabled ) ) $subject_blockers[] = $disabled->get_error_code() . ':stale_subject';
			else ++$subjects_disabled;
		}

		$tools = self::write_tools();
		if ( empty( $tools ) ) return self::finish_blocked( $status, 'write_tool_inventory_empty' );

		$desired_grants = array();
		$inventory_rows = array();
		$grant_blockers = array();
		foreach ( $tools as $ability ) {
			if ( 'mad4b/database-raw-query' === $ability ) {
				$grant_blockers[] = 'breakglass_leak';
				continue;
			}
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) {
				$grant_blockers[] = 'unmounted:' . $ability;
				continue;
			}
			$key = (string) $ability . "\0" . (string) $provider;
			$desired_grants[ $key ] = array( 'ability' => (string) $ability, 'provider' => (string) $provider );
			$inventory_rows[] = array( 'ability' => (string) $ability, 'provider' => (string) $provider );
		}

		$grants_revoked = 0;
		$existing_grants = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
		foreach ( $existing_grants as $grant ) {
			if ( 'allow' !== (string) $grant['effect'] ) continue;
			$key = (string) $grant['ability_name'] . "\0" . (string) $grant['provider'];
			$stale = ! isset( $desired_grants[ $key ] ) || $environment !== (string) $grant['environment'];
			if ( ! $stale ) continue;
			$revoked = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant['id'], 'mad4b-write' );
			if ( is_wp_error( $revoked ) ) $grant_blockers[] = $revoked->get_error_code() . ':stale_grant';
			else ++$grants_revoked;
		}

		$granted = 0;
		$existing = 0;
		foreach ( $desired_grants as $desired ) {
			$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $desired['ability'], $desired['provider'] );
			if ( ! is_wp_error( $grant ) ) {
				if ( $environment === (string) $grant['environment'] ) ++$existing;
				continue;
			}
			if ( 'mad4b_nhi_grant_denied' === $grant->get_error_code() ) {
				$grant_blockers[] = 'explicit_deny:' . $desired['ability'];
				continue;
			}
			if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) {
				$grant_blockers[] = $grant->get_error_code() . ':' . $desired['ability'];
				continue;
			}
			$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', $desired['ability'], $desired['provider'], array(), 'allow', $environment );
			if ( is_wp_error( $created ) ) $grant_blockers[] = $created->get_error_code() . ':' . $desired['ability'];
			else ++$granted;
		}

		usort( $inventory_rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		$all_blockers = array_values( array_unique( array_merge( $subject_blockers, $grant_blockers ) ) );
		$status['agent_public_id'] = (string) $agent['public_id'];
		$status['agent_slug'] = $agent_slug;
		$status['oauth_user_ids'] = array_values( array_map( 'absint', $user_ids ) );
		$status['subject_count_expected'] = count( $desired_subjects );
		$status['stale_subjects_disabled'] = $subjects_disabled;
		$status['write_tool_count'] = count( $tools );
		$status['write_inventory_fingerprint'] = hash( 'sha256', wp_json_encode( $inventory_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$status['exact_grants_existing'] = $existing;
		$status['exact_grants_created'] = $granted;
		$status['stale_allow_grants_revoked'] = $grants_revoked;
		$status['grant_blockers'] = $all_blockers;
		$status['all_remote_writes_require_exact_approval'] = false;
		$status['normal_remote_writes_require_exact_approval'] = true;
		$status['remote_write_approval_policy'] = 'exact_approval_except_bounded_candidate_bootstrap';
		$status['remote_write_prior_approval_exceptions'] = array( self::CANDIDATE_BOOTSTRAP_ABILITY );
		$status['breakglass_included'] = in_array( 'mad4b/database-raw-query', $tools, true );
		$status['ready'] = empty( $all_blockers ) && ! $status['breakglass_included'];
		$status['state'] = $status['ready'] ? 'ready' : 'blocked';
		$status['blocker'] = $status['ready'] ? '' : ( ! empty( $all_blockers ) ? 'authority_reconciliation_incomplete' : 'breakglass_leak' );
		$status['updated_at'] = gmdate( 'c' );
		$status['remote_transport'] = 'mad4b-chatgpt';
		$status['authority_server'] = 'mad4b-write';
		$status['oauth_role'] = 'identity_only';
		$status['write_authority_components'] = array( 'site_profile', 'exact_origin', 'oauth_identity', 'nhi_subject_binding', 'exact_mad4b_write_grant', 'provider_runtime', 'global_mutation_gate', 'budget_reservation', 'one_time_exact_approval', 'audit' );

		if ( $status['ready'] ) self::persist_ready_status( $status, $granted, $existing, $grants_revoked, $subjects_disabled );
		self::$status = $status;
		self::$reconciling = false;
		return $status;
	}

	public static function register_status_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! wp_has_ability( 'mad4b/write-authority-status' ) ) wp_register_ability( 'mad4b/write-authority-status', array(
			'label' => 'Get Governed Write Authority Status',
			'description' => 'Read the site-profile-bound NHI/grant/approval status for governed writes.',
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

		if ( ! wp_has_ability( 'mad4b/write-authority-reconciliation-plan' ) ) wp_register_ability( 'mad4b/write-authority-reconciliation-plan', array(
			'label' => 'Get Governed Write Authority Reconciliation Plan',
			'description' => 'Read the exact missing/stale governed write grants without mutating authority.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'reconciliation_plan' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	private static function persist_ready_status( array &$status, $granted, $existing, $revoked, $subjects_disabled ) {
		$stored = get_option( self::OPTION, array() );
		$keys = array( 'agent_public_id', 'write_tool_count', 'write_inventory_fingerprint', 'site_uuid', 'site_profile_revision', 'site_profile_digest', 'environment', 'origin' );
		$changed = ! is_array( $stored ) || empty( $stored['ready'] ) || ! empty( $stored['blocker'] );
		foreach ( $keys as $key ) {
			if ( $changed ) break;
			if ( ! array_key_exists( $key, $stored ) || ! array_key_exists( $key, $status ) || (string) $stored[ $key ] !== (string) $status[ $key ] ) $changed = true;
		}
		if ( $changed ) {
			if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
				MAD4B_SCP_Audit::record( 'mad4b/governed-write-authority-reconciled', array(
					'agent_public_id' => $status['agent_public_id'],
					'site_uuid' => $status['site_uuid'],
					'site_profile_revision' => $status['site_profile_revision'],
					'site_profile_digest' => $status['site_profile_digest'],
					'write_tool_count' => $status['write_tool_count'],
					'write_inventory_fingerprint' => $status['write_inventory_fingerprint'],
					'exact_grants_created' => (int) $granted,
					'exact_grants_existing' => (int) $existing,
					'stale_allow_grants_revoked' => (int) $revoked,
					'stale_subjects_disabled' => (int) $subjects_disabled,
					'environment' => $status['environment'],
					'origin' => $status['origin'],
					'breakglass_included' => false,
				), 'ok' );
			}
			update_option( self::OPTION, $status, false );
			$status['persistence'] = 'recorded';
		} else {
			$status['persistence'] = 'unchanged';
			$status['persisted_updated_at'] = isset( $stored['updated_at'] ) ? (string) $stored['updated_at'] : '';
		}
	}

	private static function deprovision_managed_authority( array &$status ) {
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return;
		$agent = self::agent_by_slug( self::agent_slug() );
		if ( ! $agent ) return;
		$changed = false;
		$subjects = MAD4B_SCP_Agent_Registry::subjects_for_agent( $agent['id'], 'oauth' );
		foreach ( $subjects as $subject ) {
			if ( 'enabled' !== (string) $subject['status'] ) continue;
			$result = MAD4B_SCP_Agent_Registry::set_subject_status( $agent['public_id'], 'oauth', $subject['subject_fingerprint'], 'disabled' );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		$grants = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
		foreach ( $grants as $grant ) {
			if ( 'allow' !== (string) $grant['effect'] ) continue;
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant['id'], 'mad4b-write' );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		if ( 'enabled' === (string) $agent['status'] ) {
			$result = MAD4B_SCP_Agent_Registry::disable_agent( $agent['public_id'], (int) $agent['revision'] );
			if ( ! is_wp_error( $result ) ) $changed = true;
		}
		if ( $changed && class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/governed-write-authority-deprovisioned', array(
				'agent_public_id' => (string) $agent['public_id'],
				'site_uuid' => isset( $status['site_uuid'] ) ? (string) $status['site_uuid'] : '',
				'environment' => isset( $status['environment'] ) ? (string) $status['environment'] : '',
				'origin' => isset( $status['origin'] ) ? (string) $status['origin'] : '',
				'blocker' => isset( $status['blocker'] ) ? (string) $status['blocker'] : '',
			), 'ok' );
		}
	}

	private static function finish_blocked( array $status, $blocker ) {
		$status['blocker'] = sanitize_key( (string) $blocker );
		$status['state'] = 'blocked';
		$status['ready'] = false;
		self::$status = $status;
		self::$reconciling = false;
		return $status;
	}

	private static function agent_by_slug( $slug ) {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) return null;
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['agents']} WHERE slug = %s LIMIT 1", sanitize_key( (string) $slug ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $row ? $row : null;
	}

	private static function enrolled_user_ids() {
		$users = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::oauth_user_ids() : array();
		if ( ! empty( $users ) ) return $users;
		$oauth = class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ) ? MAD4B_SCP_Staging_OAuth_Autoconfig::status() : array();
		$user_id = isset( $oauth['wp_user_id'] ) ? absint( $oauth['wp_user_id'] ) : 0;
		return $user_id > 0 ? array( $user_id ) : array();
	}

	private static function oauth_issuer() {
		return class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' ) : '';
	}

	private static function subject_fingerprint( $issuer, $user_id ) {
		return hash( 'sha256', 'oauth' . "\0" . (string) $issuer . "\0" . 'user:' . absint( $user_id ) );
	}

	private static function agent_slug() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) return sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() );
		return 'chatgpt-governed-write';
	}

	private static function current_environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function base_status() {
		$environment = self::current_environment();
		$origin = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_origin() : '';
		$profile_status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$configured = ! empty( $profile_status['configured'] );
		$origin_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled();
		$site_urls_match = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
		$exact_profile_bound = $origin_enrolled && $site_urls_match;
		$write_enabled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::write_enabled();
		$eligible = $configured && $exact_profile_bound && $write_enabled;
		$blocker = '';
		if ( ! $configured ) $blocker = 'site_profile_unconfigured';
		elseif ( ! $origin_enrolled ) {
			$profile_blockers = isset( $profile_status['blockers'] ) && is_array( $profile_status['blockers'] ) ? $profile_status['blockers'] : array();
			$blocker = ! empty( $profile_blockers ) ? sanitize_key( (string) reset( $profile_blockers ) ) : 'site_profile_drift';
		} elseif ( ! $site_urls_match ) $blocker = 'site_profile_site_urls_mismatch';
		elseif ( ! $write_enabled ) $blocker = 'site_profile_write_disabled';

		return array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'environment' => $environment,
			'origin' => $origin,
			'host' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_host() : '',
			'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '',
			'site_profile_revision' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::revision() : 0,
			'site_profile_digest' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::profile_digest() : '',
			'profile_origin_enrolled' => $origin_enrolled,
			'profile_site_urls_match' => $site_urls_match,
			'exact_profile_bound' => $exact_profile_bound,
			'profile_write_enabled' => $write_enabled,
			'eligible' => $eligible,
			'ready' => false,
			'state' => $eligible ? 'pending' : 'ineligible',
			'blocker' => $blocker,
			'mutation_gate_configured' => false,
			'configuration_source' => 'none',
			'production_auto_enable' => false,
			'breakglass_auto_enable' => false,
			'breakglass_included' => false,
			'all_remote_writes_require_exact_approval' => false,
			'normal_remote_writes_require_exact_approval' => true,
			'remote_write_approval_policy' => 'exact_approval_except_bounded_candidate_bootstrap',
			'remote_write_prior_approval_exceptions' => array( self::CANDIDATE_BOOTSTRAP_ABILITY ),
			'candidate_bootstrap_contract' => self::CANDIDATE_BOOTSTRAP_CONTRACT,
			'remote_transport' => 'mad4b-chatgpt',
			'authority_server' => 'mad4b-write',
			'oauth_role' => 'identity_only',
		);
	}
}
