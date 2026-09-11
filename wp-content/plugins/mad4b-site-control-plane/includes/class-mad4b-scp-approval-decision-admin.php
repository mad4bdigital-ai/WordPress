<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Independent human approval transition for exact governed Staging tickets.
 *
 * This surface deliberately lives in wp-admin rather than the ChatGPT MCP tool
 * inventory. ChatGPT may plan a pending exact ticket and, after an administrator
 * makes an explicit decision here, consume that ticket exactly once. This class
 * never invokes the target ability, never creates mutation authority, and never
 * operates on Production, Breakglass, recovery tickets, or stale candidates.
 */
final class MAD4B_SCP_Approval_Decision_Admin {
	const CONTRACT = 'mad4b.approval-decision-admin.v1';
	const PAGE_SLUG = 'mad4b-approval-decisions';
	const ACTION = 'mad4b_approval_decision';
	const STAGING_HOST = 'staging.egypttourgates.com';
	const MAX_ROWS = 50;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 90 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_admin_post' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'mad4b-control-plane',
			__( 'MAD4B Approval Decisions', 'mad4b-site-control-plane' ),
			__( 'Approval Decisions', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function current_candidate() {
		$base = array(
			'ready' => false,
			'source_commit_sha' => '',
			'build_fingerprint' => '',
			'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
			'host' => self::home_host(),
		);
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return $base;
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $provenance ) ) return $base;
		$sha = isset( $provenance['source_commit_sha'] ) ? strtolower( trim( (string) $provenance['source_commit_sha'] ) ) : '';
		$fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( trim( (string) $provenance['build_fingerprint'] ) ) : '';
		$base['source_commit_sha'] = $sha;
		$base['build_fingerprint'] = $fingerprint;
		$base['ready'] = 'staging' === $base['environment']
			&& self::STAGING_HOST === $base['host']
			&& ! empty( $provenance['manifest_present'] )
			&& ! empty( $provenance['manifest_valid'] )
			&& ! empty( $provenance['runtime_manifest_match'] )
			&& empty( $provenance['stale'] )
			&& 1 === preg_match( '/^[a-f0-9]{40}$/', $sha )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint );
		return $base;
	}

	/**
	 * Pure decision validator used by the live handler and by regression tests.
	 */
	public static function validate_decision_for_test( array $request, array $ticket, array $binding, array $candidate, array $agent, $is_admin, $environment, $host, $now ) {
		if ( ! $is_admin ) return new WP_Error( 'mad4b_approval_decision_admin_required', 'Administrator capability is required to decide an approval ticket.' );
		if ( 'staging' !== (string) $environment || self::STAGING_HOST !== strtolower( rtrim( (string) $host, '.' ) ) ) {
			return new WP_Error( 'mad4b_approval_decision_staging_only', 'Human approval decisions are restricted to the exact governed Staging origin.' );
		}

		$decision = isset( $request['decision'] ) ? sanitize_key( (string) $request['decision'] ) : '';
		if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) return new WP_Error( 'mad4b_approval_decision_invalid', 'Decision must be approve or reject.' );
		$ticket_id = isset( $request['ticket_id'] ) ? strtolower( trim( (string) $request['ticket_id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) || empty( $ticket['ticket_id'] ) || ! hash_equals( strtolower( (string) $ticket['ticket_id'] ), $ticket_id ) ) {
			return new WP_Error( 'mad4b_approval_decision_ticket_mismatch', 'Decision does not match the exact ticket.' );
		}
		if ( 'pending' !== ( isset( $ticket['status'] ) ? (string) $ticket['status'] : '' ) ) {
			return new WP_Error( 'mad4b_approval_decision_not_pending', 'Only pending tickets may be decided.' );
		}
		$expires = ! empty( $ticket['expires_at'] ) ? strtotime( (string) $ticket['expires_at'] . ' UTC' ) : false;
		if ( false === $expires || $expires < (int) $now ) return new WP_Error( 'mad4b_approval_decision_expired', 'The pending approval ticket has expired.' );

		if ( 'mutation' !== ( isset( $ticket['ticket_class'] ) ? sanitize_key( (string) $ticket['ticket_class'] ) : '' ) ) {
			return new WP_Error( 'mad4b_approval_decision_class_denied', 'Breakglass and recovery tickets cannot be decided from the normal Staging approval console.' );
		}
		if ( 'mad4b-write' !== ( isset( $ticket['server_id'] ) ? sanitize_key( (string) $ticket['server_id'] ) : '' ) ) {
			return new WP_Error( 'mad4b_approval_decision_server_denied', 'Only exact mad4b-write mutation tickets may be decided here.' );
		}
		$ability = isset( $ticket['ability_name'] ) ? (string) $ticket['ability_name'] : '';
		if ( '' === $ability || in_array( $ability, array( 'mad4b/database-raw-query', 'mad4b/approval-plan' ), true ) ) {
			return new WP_Error( 'mad4b_approval_decision_target_denied', 'The approval target is not eligible for the normal governed mutation path.' );
		}

		if ( empty( $agent ) || 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' )
			|| 'staging' !== ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' )
			|| ! defined( 'MAD4B_SCP_VERSION' ) && false ) {
			return new WP_Error( 'mad4b_approval_decision_agent_denied', 'Ticket is not bound to an enabled Staging agent.' );
		}
		if ( defined( 'MAD4B_SCP_VERSION' ) && class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && defined( 'MAD4B_SCP_Staging_Write_Authority::AGENT_SLUG' ) ) {
			if ( empty( $agent['slug'] ) || MAD4B_SCP_Staging_Write_Authority::AGENT_SLUG !== (string) $agent['slug'] ) return new WP_Error( 'mad4b_approval_decision_agent_denied', 'Ticket is not bound to the dedicated governed Staging write agent.' );
		}

		$payload = isset( $request['expected_payload_sha256'] ) ? strtolower( trim( (string) $request['expected_payload_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $payload ) || empty( $ticket['payload_sha256'] ) || ! hash_equals( strtolower( (string) $ticket['payload_sha256'] ), $payload ) ) {
			return new WP_Error( 'mad4b_approval_decision_payload_mismatch', 'Expected payload digest does not match the pending ticket.' );
		}
		if ( empty( $candidate['ready'] ) ) return new WP_Error( 'mad4b_approval_decision_candidate_unavailable', 'Exact current build provenance is not ready.' );
		$expected_sha = isset( $request['expected_candidate_sha'] ) ? strtolower( trim( (string) $request['expected_candidate_sha'] ) ) : '';
		$expected_build = isset( $request['expected_build_fingerprint'] ) ? strtolower( trim( (string) $request['expected_build_fingerprint'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || empty( $candidate['source_commit_sha'] ) || ! hash_equals( (string) $candidate['source_commit_sha'], $expected_sha ) ) {
			return new WP_Error( 'mad4b_approval_decision_candidate_mismatch', 'Expected candidate SHA does not match the exact live build.' );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_build ) || empty( $candidate['build_fingerprint'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], $expected_build ) ) {
			return new WP_Error( 'mad4b_approval_decision_build_mismatch', 'Expected build fingerprint does not match the exact live build.' );
		}

		if ( empty( $binding ) || 'mad4b.approval-candidate-binding.v1' !== ( isset( $binding['contract'] ) ? (string) $binding['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_approval_decision_binding_missing', 'Ticket has no candidate binding from its planning request; create a new exact ticket on this build.' );
		}
		foreach ( array(
			'ticket_id' => $ticket_id,
			'payload_sha256' => $payload,
			'candidate_sha' => $expected_sha,
			'build_fingerprint' => $expected_build,
			'environment' => 'staging',
			'host' => self::STAGING_HOST,
		) as $key => $expected ) {
			if ( ! isset( $binding[ $key ] ) || ! hash_equals( (string) $expected, (string) $binding[ $key ] ) ) {
				return new WP_Error( 'mad4b_approval_decision_binding_mismatch', 'Pending ticket was planned for a different candidate, payload, or environment.' );
			}
		}
		return true;
	}

	public static function decide( array $request ) {
		$is_admin = current_user_can( 'manage_options' );
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = self::home_host();
		$ticket_id = isset( $request['ticket_id'] ) ? strtolower( trim( (string) $request['ticket_id'] ) ) : '';
		$ticket = preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ? MAD4B_SCP_Approval_Tickets::get( $ticket_id ) : null;
		if ( ! is_array( $ticket ) ) return new WP_Error( 'mad4b_approval_decision_ticket_missing', 'Approval ticket does not exist.' );
		$binding = MAD4B_SCP_Approval_Tickets::candidate_binding( $ticket_id );
		$candidate = self::current_candidate();
		$agent = ! empty( $ticket['agent_id'] ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::get_agent_by_id( (int) $ticket['agent_id'] ) : null;
		$validated = self::validate_decision_for_test( $request, $ticket, is_array( $binding ) ? $binding : array(), $candidate, is_array( $agent ) ? $agent : array(), $is_admin, $environment, $host, time() );
		if ( is_wp_error( $validated ) ) return $validated;

		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) {
			return new WP_Error( 'mad4b_approval_decision_write_authority_not_ready', 'Governed Staging write authority is not ready.' );
		}
		$ability = (string) $ticket['ability_name'];
		if ( ! MAD4B_SCP_Staging_Write_Authority::is_write_ability( $ability ) ) return new WP_Error( 'mad4b_approval_decision_target_unmounted', 'Ticket target is no longer in the certified write inventory.' );
		$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
		if ( null === $provider || sanitize_key( (string) $ticket['provider'] ) !== sanitize_key( (string) $provider ) ) {
			return new WP_Error( 'mad4b_approval_decision_provider_mismatch', 'Ticket provider no longer matches the certified write projection.' );
		}

		return MAD4B_SCP_Approval_Tickets::decide_pending(
			$ticket_id,
			(string) $request['decision'],
			(string) $request['expected_payload_sha256'],
			array(
				'candidate_sha' => (string) $candidate['source_commit_sha'],
				'build_fingerprint' => (string) $candidate['build_fingerprint'],
				'contract' => self::CONTRACT,
			)
		);
	}

	public static function handle_admin_post() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required to decide MAD4B approval tickets.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		$request = array(
			'ticket_id' => isset( $_POST['ticket_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_id'] ) ) : '',
			'decision' => isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '',
			'expected_payload_sha256' => isset( $_POST['expected_payload_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_payload_sha256'] ) ) : '',
			'expected_candidate_sha' => isset( $_POST['expected_candidate_sha'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_candidate_sha'] ) ) : '',
			'expected_build_fingerprint' => isset( $_POST['expected_build_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_build_fingerprint'] ) ) : '',
		);
		$nonce_action = self::nonce_action( $request['ticket_id'], $request['expected_payload_sha256'] );
		check_admin_referer( $nonce_action );
		$result = self::decide( $request );
		$code = is_wp_error( $result ) ? $result->get_error_code() : 'success';
		$status = is_wp_error( $result ) ? 'error' : ( isset( $result['status'] ) ? (string) $result['status'] : 'updated' );
		$url = add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'mad4b_decision_result' => rawurlencode( $code ), 'mad4b_decision_status' => rawurlencode( $status ) ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required to decide MAD4B approval tickets.', 'mad4b-site-control-plane' ) );
		$candidate = self::current_candidate();
		echo '<div class="wrap"><h1>' . esc_html__( 'MAD4B Approval Decisions', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Human-only transition from pending to approved/rejected. This page never executes the target mutation. Production, Breakglass, recovery, stale-build and unbound tickets fail closed.', 'mad4b-site-control-plane' ) . '</p>';
		if ( isset( $_GET['mad4b_decision_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post-redirect status only.
			$code = sanitize_key( wp_unslash( $_GET['mad4b_decision_result'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = isset( $_GET['mad4b_decision_status'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_decision_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$class = 'success' === $code ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p><code>' . esc_html( $code ) . '</code> · ' . esc_html( $status ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'Exact live candidate', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
		foreach ( array( 'ready' => ! empty( $candidate['ready'] ) ? 'yes' : 'no', 'environment' => $candidate['environment'], 'host' => $candidate['host'], 'source_commit_sha' => $candidate['source_commit_sha'], 'build_fingerprint' => $candidate['build_fingerprint'] ) as $key => $value ) {
			echo '<tr><th style="width:220px">' . esc_html( $key ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		if ( empty( $candidate['ready'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Exact Staging build provenance is not ready. No ticket can be decided.', 'mad4b-site-control-plane' ) . '</p></div></div>';
			return;
		}

		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			"SELECT a.*,g.public_id AS agent_public_id,g.slug AS agent_slug,g.label AS agent_label,g.status AS agent_status,g.environment AS agent_environment FROM {$t['approvals']} a LEFT JOIN {$t['agents']} g ON g.id = a.agent_id ORDER BY a.id DESC LIMIT " . self::MAX_ROWS,
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $rows ) || empty( $rows ) ) { echo '<p>' . esc_html__( 'No approval tickets found.', 'mad4b-site-control-plane' ) . '</p></div>'; return; }
		echo '<h2>' . esc_html__( 'Recent tickets', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Ticket</th><th>Ability</th><th>Provider</th><th>Status</th><th>Expires</th><th>Payload</th><th>Candidate binding</th><th>Human decision</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$ticket_id = isset( $row['ticket_id'] ) ? (string) $row['ticket_id'] : '';
			$payload = isset( $row['payload_sha256'] ) ? strtolower( (string) $row['payload_sha256'] ) : '';
			$binding = MAD4B_SCP_Approval_Tickets::candidate_binding( $ticket_id );
			$binding_ok = is_array( $binding )
				&& ! empty( $binding['candidate_sha'] ) && hash_equals( (string) $candidate['source_commit_sha'], (string) $binding['candidate_sha'] )
				&& ! empty( $binding['build_fingerprint'] ) && hash_equals( (string) $candidate['build_fingerprint'], (string) $binding['build_fingerprint'] )
				&& ! empty( $binding['payload_sha256'] ) && hash_equals( $payload, (string) $binding['payload_sha256'] );
			echo '<tr><td><code>' . esc_html( $ticket_id ) . '</code></td><td><code>' . esc_html( isset( $row['ability_name'] ) ? $row['ability_name'] : '' ) . '</code></td><td>' . esc_html( isset( $row['provider'] ) ? $row['provider'] : '' ) . '</td><td><strong>' . esc_html( isset( $row['status'] ) ? $row['status'] : '' ) . '</strong></td><td>' . esc_html( isset( $row['expires_at'] ) ? $row['expires_at'] : '' ) . '</td><td><code>' . esc_html( self::short_hash( $payload ) ) . '</code></td><td>' . esc_html( $binding_ok ? 'exact current build' : 'missing/stale' ) . '</td><td>';
			$can_decide = 'pending' === ( isset( $row['status'] ) ? (string) $row['status'] : '' ) && $binding_ok && ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] . ' UTC' ) >= time() && 'mutation' === (string) $row['ticket_class'] && 'mad4b-write' === (string) $row['server_id'];
			if ( $can_decide ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:6px;align-items:center">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
				echo '<input type="hidden" name="ticket_id" value="' . esc_attr( $ticket_id ) . '">';
				echo '<input type="hidden" name="expected_payload_sha256" value="' . esc_attr( $payload ) . '">';
				echo '<input type="hidden" name="expected_candidate_sha" value="' . esc_attr( $candidate['source_commit_sha'] ) . '">';
				echo '<input type="hidden" name="expected_build_fingerprint" value="' . esc_attr( $candidate['build_fingerprint'] ) . '">';
				wp_nonce_field( self::nonce_action( $ticket_id, $payload ) );
				echo '<button type="submit" class="button button-primary" name="decision" value="approve">' . esc_html__( 'Approve', 'mad4b-site-control-plane' ) . '</button>';
				echo '<button type="submit" class="button" name="decision" value="reject" onclick="return confirm(\'' . esc_js( __( 'Reject this pending ticket?', 'mad4b-site-control-plane' ) ) . '\');">' . esc_html__( 'Reject', 'mad4b-site-control-plane' ) . '</button></form>';
			} else {
				echo '<span aria-label="not decidable">—</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function nonce_action( $ticket_id, $payload ) {
		return 'mad4b_approval_decision:' . strtolower( trim( (string) $ticket_id ) ) . ':' . strtolower( trim( (string) $payload ) );
	}

	private static function home_host() {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) );
		return is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	}

	private static function short_hash( $value ) {
		$value = (string) $value;
		return strlen( $value ) > 20 ? substr( $value, 0, 16 ) . '…' : $value;
	}
}
