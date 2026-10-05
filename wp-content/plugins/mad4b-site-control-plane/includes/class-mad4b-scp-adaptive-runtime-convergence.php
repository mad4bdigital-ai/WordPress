<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Lifecycle observer and capability-local certification registry.
 *
 * Observations are automatic on enrolled Staging, bounded and non-authorizing.
 * The existing compatibility engine alone verifies behavioral receipts and
 * controls mounts. This registry cannot mint grants, approvals or canary proof.
 */
final class MAD4B_SCP_Adaptive_Runtime_Convergence {
	const CONTRACT = 'mad4b.adaptive-runtime-convergence.v1';
	const RECEIPT_CONTRACT = 'mad4b.runtime-capability-observation.v1';
	const OPTION = 'mad4b_scp_adaptive_runtime_registry_v1';
	const EVENT_OPTION = 'mad4b_scp_adaptive_runtime_event_v1';
	const CRON_HOOK = 'mad4b_scp_adaptive_runtime_observe';
	const ABILITY = 'mad4b/adaptive-runtime-status';
	const SLICE_SIZE = 3;
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ), 24 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'observe' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_plugin_update' ), 20, 2 );
		add_action( 'activated_plugin', array( __CLASS__, 'enqueue' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'enqueue' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 41 );
	}

	public static function register_adapter( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! class_exists( 'MAD4B_SCP_Adapter_Base', false ) ) return;
		$registry->register( new class extends MAD4B_SCP_Adapter_Base {
			public function id() { return 'adaptive-runtime'; }
			public function label() { return 'Adaptive Runtime'; }
			public function is_available() { return true; }
			public function ability_names() { return array( 'read' => array( MAD4B_SCP_Adaptive_Runtime_Convergence::ABILITY ), 'content' => array(), 'admin' => array() ); }
			public function register_abilities() { MAD4B_SCP_Adaptive_Runtime_Convergence::register_ability(); }
			protected function mutation_requires_certification() { return false; }
			protected function provider_certification( $available ) { return null; }
		} );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability( self::ABILITY, array(
			'label' => 'Inspect Adaptive Runtime Convergence', 'description' => 'Read persisted artifact, contract and behavioral states per capability. No provider scans, execution, approval or authority changes.',
			'category' => 'mad4b-read', 'execute_callback' => array( __CLASS__, 'status' ), 'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array( 'type' => 'object', 'properties' => array( 'provider_id' => array( 'type' => 'string', 'maxLength' => 80 ) ), 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array( 'public' => false, 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		) );
	}

	private static function eligible() {
		return class_exists( 'MAD4B_SCP_Site_Profile', false ) && MAD4B_SCP_Site_Profile::configured()
			&& 'staging' === MAD4B_SCP_Site_Profile::current_environment() && MAD4B_SCP_Site_Profile::origin_enrolled()
			&& MAD4B_SCP_Site_Profile::site_urls_match_enrollment() && MAD4B_SCP_Site_Profile::managed_runtime_enabled()
			&& ! ( defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === MAD4B_MCP_BREAKGLASS_ENABLED );
	}

	private static function stamp() {
		// One local stat detects same-version manual replacements without hashing a
		// provider tree on the request path. Full identity is verified in the worker.
		return hash( 'sha256', ( defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '' ) . ':' . (string) filemtime( __FILE__ ) );
	}

	private static function event() {
		$event = get_option( self::EVENT_OPTION, array() );
		return is_array( $event ) ? $event : array();
	}

	public static function maybe_schedule() {
		if ( ! self::eligible() || is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| class_exists( 'MAD4B_SCP_MCP_Request_Scope', false ) && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		$event = self::event();
		if ( ( $event['build_stamp'] ?? '' ) !== self::stamp() ) self::enqueue();
	}

	public static function on_plugin_update( $upgrader, $details ) {
		if ( is_array( $details ) && 'plugin' === ( $details['type'] ?? '' ) ) self::enqueue();
	}

	public static function enqueue( $unused = null ) {
		if ( ! self::eligible() ) return false;
		$event = array( 'event_id' => wp_generate_uuid4(), 'build_stamp' => self::stamp(), 'observed_at' => gmdate( 'c' ) );
		update_option( self::EVENT_OPTION, $event, false );
		return self::schedule( 5 );
	}

	private static function schedule( $delay ) {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON || ! function_exists( 'wp_schedule_single_event' ) ) return false;
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next && $next <= time() + $delay ) return true;
		if ( $next ) wp_unschedule_event( $next, self::CRON_HOOK );
		return false !== wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
	}

	private static function note_failure( $code, $expected_event_id = '' ) {
		$event = self::event();
		if ( ( $event['event_id'] ?? '' ) !== $expected_event_id ) { self::schedule( 5 ); return; }
		$event['attempts'] = min( 6, (int) ( $event['attempts'] ?? 0 ) + 1 );
		$event['failure_code'] = sanitize_key( $code );
		$event['failure_state'] = $event['attempts'] < 6 ? 'RETRY_PENDING' : 'EXTERNAL_ACTION_REQUIRED';
		update_option( self::EVENT_OPTION, $event, false );
		if ( $event['attempts'] < 6 ) self::schedule( min( 300, 60 * $event['attempts'] ) );
	}

	/** Each state follows measured capability evidence, never a version comparison. */
	public static function reduce_capability( array $row ) {
		$evidence = is_array( $row['behavioral_evidence'] ?? null ) ? $row['behavioral_evidence'] : array();
		$risk = $row['risk'] ?? 'unknown';
		$state = 'ISOLATED'; $action = 'repair_capability_contract';
		if ( ! empty( $row['structural_compatible'] ) ) {
			if ( 'read' === $risk ) { $state = ! empty( $row['read_eligible'] ) ? 'READ_COMPATIBLE' : 'ISOLATED'; $action = 'READ_COMPATIBLE' === $state ? '' : 'reconcile_read_adapter'; }
			elseif ( ! empty( $row['artifact_authority_required'] ) ) { $state = 'EXTERNAL_ACTION_REQUIRED'; $action = 'approve_provider_artifact_authority'; }
			elseif ( 'high_risk_write' === $risk ) { $state = ! empty( $row['write_eligible'] ) ? 'ACTIVE' : 'HIGH_RISK_GATED'; $action = 'ACTIVE' === $state ? '' : 'run_governed_high_risk_canary_and_promotion'; }
			elseif ( 'bounded_write' === $risk && ! empty( $row['write_eligible'] ) ) { $state = 'ACTIVE'; $action = ''; }
			elseif ( 'bounded_write' === $risk && ! empty( $row['reversible'] ) ) { $state = 'CANARY_REQUIRED'; $action = 'run_exact_disposable_behavioral_recertification'; }
			else { $state = 'ISOLATED'; $action = 'provide_reversible_capability_contract'; }
		}
		return array( 'state' => $state, 'next_action' => $action, 'risk' => $risk,
			'contract_digest' => $row['capability_contract_digest'] ?? '', 'structural_compatible' => ! empty( $row['structural_compatible'] ),
			'behavioral_verified' => ! empty( $evidence['behavioral_verified'] ), 'rollback_verified' => ! empty( $evidence['rollback_verified'] ),
			'receipt_sha256' => $evidence['receipt_sha256'] ?? '', 'certification_source' => $row['certification_source'] ?? '',
			'surface_exposed' => ! empty( $row['surface_exposed'] ), 'grant_created' => false );
	}

	public static function observe() {
		if ( ! self::eligible() || ! class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease', false ) ) return;
		$lock = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'adaptive_runtime_observation' );
		if ( is_wp_error( $lock ) ) { self::schedule( 60 ); return; }
		$event = self::event();
		if ( empty( $event['event_id'] ) ) { self::enqueue(); $event = self::event(); }
		try {
			$identity = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			if ( empty( $identity['runtime_manifest_match'] ) || empty( $identity['build_fingerprint'] ) ) { self::note_failure( 'installed_package_manifest_unverified', $event['event_id'] ?? '' ); return; }
			$profile_digest = MAD4B_SCP_Site_Profile::profile_digest();
			$previous = get_option( self::OPTION, array() );
			$registry = self::valid_registry( $previous ) ? $previous : array( 'providers' => array() );
			$generation = hash( 'sha256', (string) $identity['build_fingerprint'] . ':' . $profile_digest );
			if ( ( $registry['core_enqueued_build'] ?? '' ) !== $identity['build_fingerprint'] && class_exists( 'MAD4B_SCP_Runtime_Convergence', false ) ) {
				$checkpoint = get_option( MAD4B_SCP_Runtime_Convergence::CHECKPOINT_OPTION, array() );
				if ( ! in_array( $checkpoint['state'] ?? '', array( 'blocked', 'authority_blocked' ), true ) ) {
					$registry['core_convergence'] = MAD4B_SCP_Runtime_Convergence::mark_activation_pending();
				}
				$registry['core_enqueued_build'] = $identity['build_fingerprint'];
			}
			$cursor = ( $registry['event_id'] ?? '' ) === ( $event['event_id'] ?? '' ) && ( $registry['generation'] ?? '' ) === $generation ? (int) ( $registry['cursor'] ?? 0 ) : 0;
			$epoch = 0 === $cursor ? wp_generate_uuid4() : ( $registry['observation_epoch'] ?? '' );
			$catalog = MAD4B_SCP_Provider_Contracts::all();
			$providers = array_values( array_filter( array_keys( $catalog ), array( 'MAD4B_SCP_Provider_Compatibility_Certification', 'supports_provider' ) ) ); sort( $providers, SORT_STRING );
			$registry['providers'] = array_intersect_key( $registry['providers'], array_flip( $providers ) );
			foreach ( array_slice( $providers, $cursor, self::SLICE_SIZE ) as $provider ) {
				if ( is_wp_error( MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lock, 'adaptive_runtime_observation' ) ) ) { self::schedule( 60 ); return; }
				try {
					MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
					$assessment = MAD4B_SCP_Provider_Compatibility_Certification::capability_certification( array( 'provider_id' => $provider ) );
					if ( ! is_array( $assessment ) || 'mad4b.provider-capability-certification-result.v1' !== ( $assessment['contract'] ?? '' )
						|| $provider !== ( $assessment['provider_id'] ?? '' ) || ! is_array( $assessment['capabilities'] ?? null ) ) throw new UnexpectedValueException( 'Provider assessment contract unavailable.' );
				} catch ( Throwable $error ) {
					// A broken provider cannot prevent observations of its neighbors.
					$registry['providers'][ $provider ] = array( 'contract' => self::RECEIPT_CONTRACT, 'provider_id' => $provider, 'generation' => $generation, 'observation_epoch' => $epoch,
						'state' => 'ISOLATED', 'error_code' => 'provider_observation_failed', 'capabilities' => array(), 'authorizing' => false, 'observed_at' => gmdate( 'c' ) );
					continue;
				}
				$capabilities = array(); $diff = array();
				foreach ( $assessment['capabilities'] ?? array() as $id => $row ) {
					$capabilities[ $id ] = self::reduce_capability( is_array( $row ) ? $row : array() );
					$old = $registry['providers'][ $provider ]['capabilities'][ $id ] ?? array();
					$diff[ $id ] = empty( $old ) ? 'new_capability' : ( ( $old['contract_digest'] ?? '' ) === $capabilities[ $id ]['contract_digest'] ? 'contract_unchanged' : 'contract_changed' );
				}
				foreach ( $registry['providers'][ $provider ]['capabilities'] ?? array() as $id => $old ) if ( ! isset( $capabilities[ $id ] ) ) $diff[ $id ] = 'removed_capability';
				$registry['providers'][ $provider ] = array( 'contract' => self::RECEIPT_CONTRACT, 'provider_id' => $provider, 'generation' => $generation, 'observation_epoch' => $epoch,
					'artifact_fingerprint' => $assessment['artifact']['runtime_artifact_fingerprint'] ?? '', 'installed_version' => $assessment['artifact']['installed_version'] ?? '',
					'capabilities' => $capabilities, 'capability_diff' => $diff, 'observed_at' => gmdate( 'c' ), 'authorizing' => false );
			}
			$cursor += self::SLICE_SIZE; $pending = $cursor < count( $providers );
			if ( ! $pending ) {
				$graph = array();
				foreach ( $registry['providers'] as $id => $observation ) $graph[ $id ] = array( 'artifact' => $observation['artifact_fingerprint'] ?? '', 'capabilities' => $observation['capabilities'] ?? array() );
				ksort( $graph, SORT_STRING );
				$fabric_generation = hash( 'sha256', wp_json_encode( array( 'package' => $generation, 'graph' => $graph ) ) );
				if ( ( ( $registry['fabric_generation'] ?? '' ) !== $fabric_generation || 'RECONCILED' !== ( $registry['managed_skills']['state'] ?? '' ) ) && class_exists( 'MAD4B_SCP_Skill_Provider_Discovery', false )
					&& MAD4B_SCP_Site_Profile::skills_enabled() && class_exists( 'MAD4B_SCP_Schema', false ) && MAD4B_SCP_Schema::is_ready() ) {
					$skills = MAD4B_SCP_Skill_Provider_Discovery::reconcile();
					$registry['managed_skills'] = is_wp_error( $skills ) ? array( 'state' => 'RECONCILIATION_REQUIRED', 'error_code' => $skills->get_error_code() ) : array( 'state' => 'RECONCILED' );
				}
				$registry['fabric_generation'] = $fabric_generation;
			}
			if ( ! $pending && class_exists( 'MAD4B_SCP_Post_Update_Continuation', false ) && method_exists( 'MAD4B_SCP_Post_Update_Continuation', 'capture_ready_baseline' ) ) {
				$baseline = MAD4B_SCP_Post_Update_Continuation::capture_ready_baseline( $lock, 'adaptive_runtime_observation' );
				$registry['authority_baseline'] = is_wp_error( $baseline ) ? array( 'state' => 'NOT_OBSERVED', 'error_code' => $baseline->get_error_code() ) : $baseline;
			}
			$postflight = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			if ( ! self::eligible() || MAD4B_SCP_Site_Profile::profile_digest() !== $profile_digest
				|| empty( $postflight['runtime_manifest_match'] ) || ( $postflight['build_fingerprint'] ?? '' ) !== $identity['build_fingerprint']
				|| ( self::event()['event_id'] ?? '' ) !== ( $event['event_id'] ?? '' ) ) { self::schedule( 5 ); return; }
			$registry = array_merge( $registry, array( 'contract' => self::CONTRACT, 'generation' => $generation, 'profile_digest' => $profile_digest, 'build_stamp' => self::stamp(),
				'event_id' => $event['event_id'] ?? '', 'observation_epoch' => $epoch, 'cursor' => $pending ? $cursor : 0, 'state' => $pending ? 'OBSERVING' : 'OBSERVED', 'observed_at' => gmdate( 'c' ), 'authorizing' => false ) );
			unset( $registry['seal'] ); $registry['seal'] = self::seal( $registry );
			if ( is_wp_error( MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lock, 'adaptive_runtime_observation' ) ) ) { self::schedule( 60 ); return; }
			update_option( self::OPTION, $registry, false );
			$readback = get_option( self::OPTION, array() );
			if ( ! self::valid_registry( $readback ) || ! hash_equals( $registry['seal'], $readback['seal'] ) ) { self::schedule( 60 ); return; }
			$latest_event = self::event();
			if ( isset( $latest_event['failure_code'] ) && ( $latest_event['event_id'] ?? '' ) === ( $event['event_id'] ?? '' ) ) { unset( $latest_event['failure_code'], $latest_event['failure_state'], $latest_event['attempts'] ); update_option( self::EVENT_OPTION, $latest_event, false ); }
			self::schedule( $pending ? 5 : 3600 );
		} catch ( Throwable $error ) { self::note_failure( 'runtime_observation_failed', $event['event_id'] ?? '' ); }
		finally { MAD4B_SCP_Runtime_Maintenance_Lease::release( $lock, 'adaptive_runtime_observation' ); }
	}

	private static function seal( array $registry ) { unset( $registry['seal'] ); return hash_hmac( 'sha256', wp_json_encode( $registry ), wp_salt( 'auth' ) ); }
	private static function valid_registry( $registry ) { return is_array( $registry ) && self::CONTRACT === ( $registry['contract'] ?? '' ) && is_array( $registry['providers'] ?? null ) && is_string( $registry['seal'] ?? null ) && hash_equals( self::seal( $registry ), $registry['seal'] ); }

	public static function status( $input = array() ) {
		$registry = get_option( self::OPTION, array() );
		$valid = self::valid_registry( $registry );
		if ( ! $valid ) $registry = array( 'state' => 'NOT_OBSERVED', 'providers' => array() );
		$event = self::event();
		$current = $valid && ( $registry['event_id'] ?? '' ) === ( $event['event_id'] ?? '' ) && ( $registry['build_stamp'] ?? '' ) === self::stamp()
			&& class_exists( 'MAD4B_SCP_Site_Profile', false ) && ( $registry['profile_digest'] ?? '' ) === MAD4B_SCP_Site_Profile::profile_digest();
		if ( $valid && ! $current ) $registry['state'] = 'STALE_OBSERVATION';
		foreach ( $registry['providers'] as &$observation ) {
			$observation['observation_current'] = $current && ! empty( $registry['observation_epoch'] ) && ( $observation['observation_epoch'] ?? '' ) === $registry['observation_epoch'];
			if ( $observation['observation_current'] ) continue;
			$observation['state'] = 'STALE_OBSERVATION';
			foreach ( $observation['capabilities'] as &$capability ) { $capability['last_observed_state'] = $capability['state']; $capability['state'] = 'STALE_OBSERVATION'; $capability['next_action'] = 'await_current_provider_observation'; }
			unset( $capability );
		}
		unset( $observation );
		$registry['last_worker_failure'] = isset( $event['failure_code'] ) ? array( 'state' => $event['failure_state'] ?? '', 'code' => sanitize_key( $event['failure_code'] ), 'attempts' => (int) ( $event['attempts'] ?? 0 ) ) : array();
		unset( $registry['seal'] );
		$provider = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		if ( '' !== $provider ) $registry['providers'] = isset( $registry['providers'][ $provider ] ) ? array( $provider => $registry['providers'][ $provider ] ) : array();
		return array_merge( $registry, array( 'contract' => self::CONTRACT, 'receipt_integrity_valid' => $valid, 'observation_current' => $current, 'authorizing' => false, 'read_only' => true, 'production_mutation' => false,
			'canary_policy' => 'actual_disposable_probe_exact_readback_and_rollback_required', 'provider_live_validation_deferred' => true, 'external_evidence_policy' => 'real_current_build_oauth_initialize_and_tools_list_required',
			'scheduler_state' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'EXTERNAL_ACTION_REQUIRED' : 'AVAILABLE' ) );
	}
}
