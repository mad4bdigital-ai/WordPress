<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only operator summary for release/runtime health.
 *
 * This surface reduces verified/local signals into a small operator-state
 * vocabulary. It never grants authority, persists readiness, mutates state or
 * treats caller-supplied booleans as Production evidence.
 */
final class MAD4B_SCP_Operator_Control_Center {
	const CONTRACT = 'mad4b.operator-control-center.v1';
	const ABILITY = 'mad4b/operator-control-center';
	const PAGE_SLUG = 'mad4b-operator-control-center';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
		MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'register_menu' ), 32 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'Operator Control Center',
				'description' => 'Read-only consolidated runtime/release health projection. It cannot self-certify Production readiness.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'execute' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'bundle' => array( 'type' => 'object', 'additionalProperties' => true ),
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
			)
		);
	}

	public static function register_menu() {
		if ( ! class_exists( 'MAD4B_SCP_Admin_UI' ) ) return;
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'Operator Control Center', 'mad4b-site-control-plane' ),
			__( 'Operator Control', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$signals = array(
			'repository_green' => null,
			'runtime_identity_match' => null,
			'live_evidence_ready' => null,
			'optional_capabilities_fail_closed' => null,
			'external_machine_ingress_reachable' => null,
			'write_authority_ready' => null,
			'candidate_binding_match' => null,
			'database_topology_ready' => null,
			'governed_write_lane_ready' => null,
			'developer_lane_ready' => null,
			'developer_breakglass_lane_ready' => null,
			'provider_closure_action_required' => null,
			'mutation_uncertain' => false,
			'recovery_required' => false,
		);
		$validated = null;
		$error_code = '';

		$environment_snapshot = class_exists( 'MAD4B_SCP_Environment' ) && method_exists( 'MAD4B_SCP_Environment', 'snapshot' )
			? MAD4B_SCP_Environment::snapshot()
			: array(
				'effective_environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
				'wordpress_environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
				'wordpress_environment_explicit' => defined( 'WP_ENVIRONMENT_TYPE' ),
			);
		$write_readiness = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'current_execution_readiness' )
			? MAD4B_SCP_Staging_Write_Authority::current_execution_readiness()
			: array();
		$write_readiness = is_array( $write_readiness ) ? $write_readiness : array();
		$signals['write_authority_ready'] = array_key_exists( 'ready', $write_readiness ) ? (bool) $write_readiness['ready'] : null;
		$signals['candidate_binding_match'] = array_key_exists( 'candidate_binding_match', $write_readiness ) ? (bool) $write_readiness['candidate_binding_match'] : null;

		$database_topology = class_exists( 'MAD4B_SCP_Database_Topology' ) && method_exists( 'MAD4B_SCP_Database_Topology', 'status' )
			? MAD4B_SCP_Database_Topology::status( false )
			: array();
		$database_topology = is_array( $database_topology ) ? $database_topology : array();
		$signals['database_topology_ready'] = array_key_exists( 'ready', $database_topology ) ? (bool) $database_topology['ready'] : null;

		$full_authority = class_exists( 'MAD4B_SCP_Full_Staging_Authority' ) && method_exists( 'MAD4B_SCP_Full_Staging_Authority', 'status' )
			? MAD4B_SCP_Full_Staging_Authority::status()
			: array();
		$full_authority = is_array( $full_authority ) ? $full_authority : array();
		$lane_readiness = isset( $full_authority['lane_readiness'] ) && is_array( $full_authority['lane_readiness'] ) ? $full_authority['lane_readiness'] : array();
		$signals['governed_write_lane_ready'] = array_key_exists( 'governed_write', $lane_readiness ) ? (bool) $lane_readiness['governed_write'] : null;
		$signals['developer_lane_ready'] = array_key_exists( 'developer', $lane_readiness ) ? (bool) $lane_readiness['developer'] : null;
		$signals['developer_breakglass_lane_ready'] = array_key_exists( 'developer_breakglass', $lane_readiness ) ? (bool) $lane_readiness['developer_breakglass'] : null;

		$provider_closure = class_exists( 'MAD4B_SCP_Provider_Closure_Matrix' ) && method_exists( 'MAD4B_SCP_Provider_Closure_Matrix', 'matrix' )
			? MAD4B_SCP_Provider_Closure_Matrix::matrix()
			: array();
		$provider_closure = is_array( $provider_closure ) ? $provider_closure : array();
		$signals['provider_closure_action_required'] = array_key_exists( 'operational_action_required_count', $provider_closure )
			? ( (int) $provider_closure['operational_action_required_count'] > 0 )
			: null;

		if ( isset( $input['bundle'] ) && is_array( $input['bundle'] ) && ! empty( $input['bundle'] ) && class_exists( 'MAD4B_SCP_Production_Readiness_Evaluator' ) ) {
			$validated = MAD4B_SCP_Production_Readiness_Evaluator::execute( array( 'bundle' => $input['bundle'] ) );
			if ( is_wp_error( $validated ) ) {
				$error_code = (string) $validated->get_error_code();
				$signals['live_evidence_ready'] = false;
				if ( 'mad4b_production_readiness_runtime_identity_mismatch' === $error_code ) $signals['runtime_identity_match'] = false;
			} elseif ( is_array( $validated ) ) {
				$signals['runtime_identity_match'] = true;
				$signals['live_evidence_ready'] = ! empty( $validated['production_ready'] );
				$signals['optional_capabilities_fail_closed'] = true;
			}
		}

		$snapshot = self::reduce( $signals );
		$snapshot['runtime'] = array(
			'environment' => isset( $environment_snapshot['effective_environment'] ) ? sanitize_key( (string) $environment_snapshot['effective_environment'] ) : 'unknown',
			'effective_environment' => isset( $environment_snapshot['effective_environment'] ) ? sanitize_key( (string) $environment_snapshot['effective_environment'] ) : 'unknown',
			'wordpress_environment' => isset( $environment_snapshot['wordpress_environment'] ) ? sanitize_key( (string) $environment_snapshot['wordpress_environment'] ) : 'unknown',
			'wordpress_environment_explicit' => ! empty( $environment_snapshot['wordpress_environment_explicit'] ),
			'environment_source' => isset( $environment_snapshot['effective_source'] ) ? sanitize_key( (string) $environment_snapshot['effective_source'] ) : '',
			'build' => self::runtime_build(),
		);
		$snapshot['operational'] = array(
			'write_authority' => array(
				'ready' => $signals['write_authority_ready'],
				'state' => isset( $write_readiness['state'] ) ? sanitize_key( (string) $write_readiness['state'] ) : '',
				'candidate_binding_match' => $signals['candidate_binding_match'],
				'current_grant_snapshot_ready' => array_key_exists( 'current_grant_snapshot_ready', $write_readiness ) ? (bool) $write_readiness['current_grant_snapshot_ready'] : null,
				'blockers' => isset( $write_readiness['blockers'] ) && is_array( $write_readiness['blockers'] ) ? array_values( array_slice( array_unique( array_map( 'sanitize_key', $write_readiness['blockers'] ) ), 0, 16 ) ) : array(),
			),
			'database_topology' => array(
				'ready' => $signals['database_topology_ready'],
				'mode' => isset( $database_topology['mode'] ) ? sanitize_key( (string) $database_topology['mode'] ) : '',
				'read_your_writes' => array_key_exists( 'read_your_writes', $database_topology ) ? (bool) $database_topology['read_your_writes'] : null,
				'database_dropin_ownership' => isset( $database_topology['database_dropin_ownership'] ) ? sanitize_key( (string) $database_topology['database_dropin_ownership'] ) : '',
				'blockers' => isset( $database_topology['blockers'] ) && is_array( $database_topology['blockers'] ) ? array_values( array_slice( array_unique( array_map( 'sanitize_key', $database_topology['blockers'] ) ), 0, 12 ) ) : array(),
			),
			'lanes' => array(
				'operational_ready' => array_key_exists( 'operational_ready', $full_authority ) ? (bool) $full_authority['operational_ready'] : null,
				'degraded_mode' => ! empty( $full_authority['degraded_mode'] ),
				'degraded_mode_semantics' => isset( $full_authority['degraded_mode_semantics'] ) ? sanitize_key( (string) $full_authority['degraded_mode_semantics'] ) : '',
				'lane_readiness' => $lane_readiness,
				'available_lanes' => isset( $full_authority['available_lanes'] ) && is_array( $full_authority['available_lanes'] ) ? array_values( array_slice( array_map( 'sanitize_key', $full_authority['available_lanes'] ), 0, 8 ) ) : array(),
				'blocked_lanes' => isset( $full_authority['blocked_lanes'] ) && is_array( $full_authority['blocked_lanes'] ) ? array_values( array_slice( array_map( 'sanitize_key', $full_authority['blocked_lanes'] ), 0, 8 ) ) : array(),
				'blockers' => isset( $full_authority['operational_blockers'] ) && is_array( $full_authority['operational_blockers'] ) ? array_values( array_slice( array_unique( array_map( 'sanitize_key', $full_authority['operational_blockers'] ) ), 0, 16 ) ) : array(),
				'client_action' => isset( $full_authority['operational_client_action'] ) ? sanitize_key( (string) $full_authority['operational_client_action'] ) : '',
				'developer_execution' => isset( $full_authority['developer_execution'] ) && is_array( $full_authority['developer_execution'] ) ? $full_authority['developer_execution'] : array(),
			),
			'provider_closure' => array(
				'state' => isset( $provider_closure['operational_state'] ) ? sanitize_key( (string) $provider_closure['operational_state'] ) : 'unavailable',
				'provider_gated_count' => isset( $provider_closure['provider_gated_count'] ) ? (int) $provider_closure['provider_gated_count'] : 0,
				'action_required_count' => isset( $provider_closure['operational_action_required_count'] ) ? (int) $provider_closure['operational_action_required_count'] : 0,
				'not_applicable_count' => isset( $provider_closure['not_applicable_count'] ) ? (int) $provider_closure['not_applicable_count'] : 0,
				'unresolved_applicability_count' => isset( $provider_closure['unresolved_applicability_count'] ) ? (int) $provider_closure['unresolved_applicability_count'] : 0,
				'owner_review_required_count' => isset( $provider_closure['owner_review_required_count'] ) ? (int) $provider_closure['owner_review_required_count'] : 0,
				'closure_class_counts' => isset( $provider_closure['closure_class_counts'] ) && is_array( $provider_closure['closure_class_counts'] ) ? $provider_closure['closure_class_counts'] : array(),
				'read_only' => true,
			),
			'database_recovery' => array(
				'scope' => 'external_provider_disaster_recovery',
				'control_plane_core_blocking' => false,
				'disaster_recovery_operability_required' => true,
				'current_provider_evaluated' => false,
				'host_runner_database_access' => false,
				'generic_raw_sql_allowed' => false,
				'production_authorized' => false,
				'state' => 'external_provider_certification_required',
				'next_action' => 'certify_external_database_recovery_provider_and_rehearse_restore',
			),
		);
		$snapshot['evidence_binding'] = array(
			'repository' => 'EXTERNAL_EXACT_HEAD_EVIDENCE_REQUIRED',
			'external_machine_ingress' => 'EXTERNAL_EDGE_EVIDENCE_REQUIRED',
			'live_readiness_bundle_supplied' => isset( $input['bundle'] ) && is_array( $input['bundle'] ) && ! empty( $input['bundle'] ),
			'live_readiness_bundle_validated' => is_array( $validated ) && ! is_wp_error( $validated ),
			'readiness_error' => $error_code,
		);
		$snapshot['production_ready_claimed'] = is_array( $validated ) && ! is_wp_error( $validated ) && ! empty( $validated['production_ready'] );
		$snapshot['production_authorized'] = false;
		$snapshot['authorizing'] = false;
		$snapshot['mutation_performed'] = false;
		return $snapshot;
	}

	public static function reduce( array $signals ) {
		$recovery = array();
		$blocking = array();
		$degraded = array();

		if ( true === self::tri( $signals, 'mutation_uncertain' ) ) $recovery[] = 'mutation_state_uncertain';
		if ( true === self::tri( $signals, 'recovery_required' ) ) $recovery[] = 'recovery_required';
		if ( false === self::tri( $signals, 'runtime_identity_match' ) ) $blocking[] = 'runtime_identity_mismatch';
		if ( false === self::tri( $signals, 'repository_green' ) ) $blocking[] = 'repository_evidence_failed';
		if ( false === self::tri( $signals, 'optional_capabilities_fail_closed' ) ) $blocking[] = 'optional_capabilities_not_fail_closed';
		if ( array_key_exists( 'write_authority_ready', $signals ) && false === self::tri( $signals, 'write_authority_ready' ) ) $blocking[] = 'write_authority_not_current';
		if ( array_key_exists( 'candidate_binding_match', $signals ) && false === self::tri( $signals, 'candidate_binding_match' ) ) $blocking[] = 'runtime_authority_candidate_not_reconciled';
		if ( array_key_exists( 'database_topology_ready', $signals ) && false === self::tri( $signals, 'database_topology_ready' ) ) $blocking[] = 'database_topology_not_write_safe';

		if ( array_key_exists( 'governed_write_lane_ready', $signals ) && false === self::tri( $signals, 'governed_write_lane_ready' ) ) $blocking[] = 'governed_write_lane_not_ready';
		if ( array_key_exists( 'developer_lane_ready', $signals ) && false === self::tri( $signals, 'developer_lane_ready' ) ) $degraded[] = 'developer_lane_not_ready';
		if ( array_key_exists( 'developer_breakglass_lane_ready', $signals ) && false === self::tri( $signals, 'developer_breakglass_lane_ready' ) ) $degraded[] = 'developer_breakglass_lane_not_ready';
		if ( true === self::tri( $signals, 'provider_closure_action_required' ) ) $degraded[] = 'provider_closure_actions_pending';

		foreach ( array(
			'repository_green' => 'repository_evidence_unbound',
			'runtime_identity_match' => 'runtime_identity_unbound',
			'live_evidence_ready' => 'live_evidence_unbound',
			'optional_capabilities_fail_closed' => 'optional_capability_state_unbound',
			'external_machine_ingress_reachable' => 'external_machine_ingress_unbound',
		) as $key => $reason ) {
			if ( null === self::tri( $signals, $key ) ) $degraded[] = $reason;
		}
		if ( false === self::tri( $signals, 'live_evidence_ready' ) ) $degraded[] = 'live_evidence_not_ready';
		if ( false === self::tri( $signals, 'external_machine_ingress_reachable' ) ) $degraded[] = 'external_machine_ingress_not_reachable';

		$state = 'HEALTHY';
		if ( ! empty( $degraded ) ) $state = 'DEGRADED';
		if ( ! empty( $blocking ) ) $state = 'BLOCKED';
		if ( ! empty( $recovery ) ) $state = 'RECOVERY_REQUIRED';

		$reasons = array_values( array_unique( array_merge( $recovery, $blocking, $degraded ) ) );
		return array(
			'contract' => self::CONTRACT,
			'state' => $state,
			'reasons' => $reasons,
			'next_actions' => self::next_actions( $reasons ),
			'signals' => $signals,
			'authorizing' => false,
			'production_authorized' => false,
			'mutation_performed' => false,
		);
	}

	private static function tri( array $signals, $key ) {
		if ( ! array_key_exists( $key, $signals ) || null === $signals[ $key ] ) return null;
		return true === $signals[ $key ];
	}

	private static function next_actions( array $reasons ) {
		$map = array(
			'mutation_state_uncertain' => 'reconcile_mutation_before_retry',
			'recovery_required' => 'execute_governed_recovery_path',
			'runtime_identity_mismatch' => 'deploy_exact_runtime_release',
			'repository_evidence_failed' => 'restore_exact_head_repository_green',
			'optional_capabilities_not_fail_closed' => 'disable_uncertified_optional_capabilities',
			'repository_evidence_unbound' => 'bind_exact_head_repository_evidence',
			'runtime_identity_unbound' => 'bind_exact_deployed_runtime_identity',
			'live_evidence_unbound' => 'run_exact_candidate_staging_certification',
			'optional_capability_state_unbound' => 'prove_optional_capabilities_fail_closed',
			'external_machine_ingress_unbound' => 'prove_machine_diagnostic_ingress_without_bypass',
			'live_evidence_not_ready' => 'close_staging_live_evidence_gates',
			'external_machine_ingress_not_reachable' => 'fix_scoped_edge_machine_ingress',
			'write_authority_not_current' => 'reconcile_exact_staging_write_authority',
			'runtime_authority_candidate_not_reconciled' => 'reconcile_exact_staging_write_authority',
			'database_topology_not_write_safe' => 'repair_query_monitor_db_attribution_then_retry',
			'governed_write_lane_not_ready' => 'reconcile_exact_staging_write_authority',
			'developer_lane_not_ready' => 'resolve_developer_host_execution_prerequisites',
			'developer_breakglass_lane_not_ready' => 'resolve_developer_host_execution_prerequisites',
			'provider_closure_actions_pending' => 'close_active_provider_certification_gaps',
		);
		$out = array();
		foreach ( $reasons as $reason ) if ( isset( $map[ $reason ] ) ) $out[] = $map[ $reason ];
		return array_values( array_unique( $out ) );
	}

	private static function runtime_build() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-RUNTIME-BUILD.txt' : '';
		if ( '' === $path || ! is_readable( $path ) ) return array( 'state' => 'UNAVAILABLE' );
		$rows = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $rows ) ) return array( 'state' => 'UNAVAILABLE' );
		$out = array( 'state' => 'PRESENT' );
		foreach ( $rows as $row ) {
			$parts = explode( '=', (string) $row, 2 );
			if ( 2 !== count( $parts ) ) continue;
			$key = trim( $parts[0] );
			if ( ! in_array( $key, array( 'release', 'contract', 'purpose' ), true ) ) continue;
			$out[ $key ] = trim( $parts[1] );
		}
		return $out;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to inspect operator state.', 'mad4b-site-control-plane' ) );
		$snapshot = self::execute();
		$state = isset( $snapshot['state'] ) ? (string) $snapshot['state'] : 'DEGRADED';
		$reasons = isset( $snapshot['reasons'] ) && is_array( $snapshot['reasons'] ) ? $snapshot['reasons'] : array();
		$actions = isset( $snapshot['next_actions'] ) && is_array( $snapshot['next_actions'] ) ? $snapshot['next_actions'] : array();
		echo '<div class="wrap"><h1>' . esc_html__( 'MAD4B Operator Control Center', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only consolidated operator state. Missing external evidence is never success and this page never grants Production authority.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html( $state ) . '</strong></p></div>';
		$runtime = isset( $snapshot['runtime'] ) && is_array( $snapshot['runtime'] ) ? $snapshot['runtime'] : array();
		$operational = isset( $snapshot['operational'] ) && is_array( $snapshot['operational'] ) ? $snapshot['operational'] : array();
		echo '<p><strong>' . esc_html__( 'Effective environment:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( isset( $runtime['effective_environment'] ) ? (string) $runtime['effective_environment'] : 'unknown' ) . '</code>';
		echo ' &nbsp; <strong>' . esc_html__( 'WordPress raw environment:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( isset( $runtime['wordpress_environment'] ) ? (string) $runtime['wordpress_environment'] : 'unknown' ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Write authority current:', 'mad4b-site-control-plane' ) . '</strong> <code>' . ( ! empty( $operational['write_authority']['ready'] ) ? 'true' : 'false' ) . '</code>';
		echo ' &nbsp; <strong>' . esc_html__( 'Database topology ready:', 'mad4b-site-control-plane' ) . '</strong> <code>' . ( ! empty( $operational['database_topology']['ready'] ) ? 'true' : 'false' ) . '</code></p>';
		$lanes = isset( $operational['lanes'] ) && is_array( $operational['lanes'] ) ? $operational['lanes'] : array();
		$provider_closure = isset( $operational['provider_closure'] ) && is_array( $operational['provider_closure'] ) ? $operational['provider_closure'] : array();
		echo '<p><strong>' . esc_html__( 'Governed Write lane:', 'mad4b-site-control-plane' ) . '</strong> <code>' . ( ! empty( $lanes['lane_readiness']['governed_write'] ) ? 'ready' : 'blocked' ) . '</code>';
		echo ' &nbsp; <strong>' . esc_html__( 'Developer lane:', 'mad4b-site-control-plane' ) . '</strong> <code>' . ( ! empty( $lanes['lane_readiness']['developer'] ) ? 'ready' : 'blocked' ) . '</code>';
		echo ' &nbsp; <strong>' . esc_html__( 'Degraded mode:', 'mad4b-site-control-plane' ) . '</strong> <code>' . ( ! empty( $lanes['degraded_mode'] ) ? 'true' : 'false' ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Provider actions required:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( isset( $provider_closure['action_required_count'] ) ? (string) $provider_closure['action_required_count'] : '0' ) . '</code>';
		echo ' &nbsp; <strong>' . esc_html__( 'Provider gates not applicable:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( isset( $provider_closure['not_applicable_count'] ) ? (string) $provider_closure['not_applicable_count'] : '0' ) . '</code>';
		echo ' &nbsp; <strong>' . esc_html__( 'Applicability unresolved:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( isset( $provider_closure['unresolved_applicability_count'] ) ? (string) $provider_closure['unresolved_applicability_count'] : '0' ) . '</code></p>';
		echo '<h2>' . esc_html__( 'Reasons', 'mad4b-site-control-plane' ) . '</h2><ul>';
		foreach ( $reasons as $reason ) echo '<li><code>' . esc_html( (string) $reason ) . '</code></li>';
		echo '</ul><h2>' . esc_html__( 'Next actions', 'mad4b-site-control-plane' ) . '</h2><ol>';
		foreach ( $actions as $action ) echo '<li><code>' . esc_html( (string) $action ) . '</code></li>';
		echo '</ol>';
		if ( class_exists( 'MAD4B_SCP_Adaptive_Runtime_Convergence', false ) ) {
			$adaptive = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
			echo '<h2>' . esc_html__( 'Automatic runtime observations', 'mad4b-site-control-plane' ) . '</h2>';
			echo '<p>' . esc_html( $adaptive['state'] ) . ' — ' . esc_html__( 'Capabilities are assessed separately. Stored observations do not grant permission.', 'mad4b-site-control-plane' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Provider', 'mad4b-site-control-plane' ) . '</th><th>' . esc_html__( 'Capability', 'mad4b-site-control-plane' ) . '</th><th>' . esc_html__( 'State', 'mad4b-site-control-plane' ) . '</th></tr></thead><tbody>';
			$shown = 0;
			foreach ( $adaptive['providers'] as $provider => $observation ) foreach ( $observation['capabilities'] ?? array() as $capability => $row ) {
				if ( ++$shown > 64 ) break 2;
				echo '<tr><td>' . esc_html( $provider ) . '</td><td>' . esc_html( $capability ) . '</td><td>' . esc_html( $row['state'] ) . '</td></tr>';
			}
			echo '</tbody></table><p><a href="' . esc_url( admin_url( 'admin.php?page=mad4b-adapter-coverage' ) ) . '">' . esc_html__( 'Review provider capabilities', 'mad4b-site-control-plane' ) . '</a></p>';
			if ( ! empty( $adaptive['page']['has_more'] ) || $shown > 64 ) echo '<p class="description">' . esc_html__( 'This overview shows a bounded sample. Open provider coverage for the remaining capabilities.', 'mad4b-site-control-plane' ) . '</p>';
		}
		echo '<p><strong>' . esc_html__( 'Production authorized:', 'mad4b-site-control-plane' ) . '</strong> <code>false</code></p></div>';
	}
}

// Routes are declared without booting menus or provider lifecycle on frontend requests.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Operator_Control_Center::PAGE_SLUG, 'manage_options' );
