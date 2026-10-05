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
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 32 );
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
			'mutation_uncertain' => false,
			'recovery_required' => false,
		);
		$validated = null;
		$error_code = '';

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
			'environment' => function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'unknown',
			'build' => self::runtime_build(),
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
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html( $state ) . '</strong></p></div><h2>' . esc_html__( 'Reasons', 'mad4b-site-control-plane' ) . '</h2><ul>';
		foreach ( $reasons as $reason ) echo '<li><code>' . esc_html( (string) $reason ) . '</code></li>';
		echo '</ul><h2>' . esc_html__( 'Next actions', 'mad4b-site-control-plane' ) . '</h2><ol>';
		foreach ( $actions as $action ) echo '<li><code>' . esc_html( (string) $action ) . '</code></li>';
		echo '</ol><p><strong>' . esc_html__( 'Production authorized:', 'mad4b-site-control-plane' ) . '</strong> <code>false</code></p></div>';
	}
}
