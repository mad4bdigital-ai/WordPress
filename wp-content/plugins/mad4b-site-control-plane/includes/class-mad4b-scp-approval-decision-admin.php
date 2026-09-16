<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Human approval transition for exact Site Profile + build bound tickets.
 *
 * GET remains read-model only. POST revalidates tenant profile, build provenance,
 * agent, provider projection, payload and one-time ticket state before deciding.
 * Breakglass/recovery are intentionally outside this normal approval console.
 */
final class MAD4B_SCP_Approval_Decision_Admin {
	const CONTRACT = 'mad4b.approval-decision-admin.v2';
	const PAGE_SLUG = 'mad4b-approval-decisions';
	const ACTION = 'mad4b_approval_decision';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'admin_init', array( __CLASS__, 'protect_read_model_hot_path' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 90 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_admin_post' ) );
	}

	public static function protect_read_model_hot_path() {
		if ( ! is_admin() || 'GET' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) return;
		remove_action( 'admin_init', array( 'MAD4B_SCP_Plugin', 'prime_admin_mcp_runtime' ), 1 );
		remove_action( 'admin_init', array( 'MAD4B_SCP_Plugin', 'reconcile_authority_on_mad4b_admin' ), 20 );
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
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = self::home_host();
		$base = array(
			'ready' => false,
			'source_commit_sha' => '',
			'build_fingerprint' => '',
			'environment' => $environment,
			'host' => $host,
			'origin' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::current_origin() : '',
			'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() ) : '',
			'site_profile_revision' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? absint( MAD4B_SCP_Site_Profile::revision() ) : 0,
			'site_profile_digest' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ) : '',
			'agent_slug' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) : '',
			'profile_ready' => false,
		);
		$base['profile_ready'] = class_exists( 'MAD4B_SCP_Site_Profile' )
			&& MAD4B_SCP_Site_Profile::configured()
			&& MAD4B_SCP_Site_Profile::origin_enrolled()
			&& MAD4B_SCP_Site_Profile::write_enabled()
			&& ! empty( $base['site_uuid'] )
			&& $base['site_profile_revision'] > 0
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $base['site_profile_digest'] );
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return $base;
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $provenance ) ) return $base;
		$sha = isset( $provenance['source_commit_sha'] ) ? strtolower( trim( (string) $provenance['source_commit_sha'] ) ) : '';
		$fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( trim( (string) $provenance['build_fingerprint'] ) ) : '';
		$base['source_commit_sha'] = $sha;
		$base['build_fingerprint'] = $fingerprint;
		$base['ready'] = ! empty( $base['profile_ready'] )
			&& ! empty( $provenance['manifest_present'] )
			&& ! empty( $provenance['manifest_valid'] )
			&& ! empty( $provenance['runtime_manifest_match'] )
			&& empty( $provenance['stale'] )
			&& 1 === preg_match( '/^[a-f0-9]{40}$/', $sha )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint );
		return $base;
	}

	public static function validate_decision_for_test( array $request, array $ticket, array $binding, array $candidate, array $agent, $is_approver, $environment, $host, $now ) {
		if ( ! $is_approver ) return new WP_Error( 'mad4b_approval_decision_admin_required', 'Approval capability is required to decide an approval ticket.' );
		if ( empty( $candidate['ready'] ) || empty( $candidate['profile_ready'] ) ) return new WP_Error( 'mad4b_approval_decision_candidate_unavailable', 'Exact current Site Profile/build provenance is not ready.' );
		$environment = sanitize_key( (string) $environment );
		$host = strtolower( rtrim( (string) $host, '.' ) );
		if ( ! hash_equals( (string) $candidate['environment'], $environment ) || ! hash_equals( (string) $candidate['host'], $host ) ) return new WP_Error( 'mad4b_approval_decision_site_mismatch', 'Human approval is restricted to the exact enrolled origin/environment.' );

		$decision = isset( $request['decision'] ) ? sanitize_key( (string) $request['decision'] ) : '';
		if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) return new WP_Error( 'mad4b_approval_decision_invalid', 'Decision must be approve or reject.' );
		$ticket_id = isset( $request['ticket_id'] ) ? strtolower( trim( (string) $request['ticket_id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) || empty( $ticket['ticket_id'] ) || ! hash_equals( strtolower( (string) $ticket['ticket_id'] ), $ticket_id ) ) return new WP_Error( 'mad4b_approval_decision_ticket_mismatch', 'Decision does not match the exact ticket.' );
		if ( 'pending' !== ( isset( $ticket['status'] ) ? (string) $ticket['status'] : '' ) ) return new WP_Error( 'mad4b_approval_decision_not_pending', 'Only pending tickets may be decided.' );
		$expires = ! empty( $ticket['expires_at'] ) ? strtotime( (string) $ticket['expires_at'] . ' UTC' ) : false;
		if ( false === $expires || $expires < (int) $now ) return new WP_Error( 'mad4b_approval_decision_expired', 'The pending approval ticket has expired.' );
		if ( 'mutation' !== ( isset( $ticket['ticket_class'] ) ? sanitize_key( (string) $ticket['ticket_class'] ) : '' ) ) return new WP_Error( 'mad4b_approval_decision_class_denied', 'Breakglass and recovery tickets cannot be decided from the normal approval console.' );
		if ( 'mad4b-write' !== ( isset( $ticket['server_id'] ) ? sanitize_key( (string) $ticket['server_id'] ) : '' ) ) return new WP_Error( 'mad4b_approval_decision_server_denied', 'Only exact mad4b-write mutation tickets may be decided here.' );
		$ability = isset( $ticket['ability_name'] ) ? (string) $ticket['ability_name'] : '';
		if ( '' === $ability || in_array( $ability, array( 'mad4b/database-raw-query', 'mad4b/approval-plan' ), true ) ) return new WP_Error( 'mad4b_approval_decision_target_denied', 'The approval target is not eligible for the normal governed mutation path.' );

		if ( empty( $agent ) || 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' ) ) return new WP_Error( 'mad4b_approval_decision_agent_denied', 'Ticket is not bound to an enabled governed-write agent.' );
		if ( ! isset( $agent['environment'] ) || ! hash_equals( (string) $candidate['environment'], (string) $agent['environment'] ) ) return new WP_Error( 'mad4b_approval_decision_agent_denied', 'Ticket agent environment no longer matches the current Site Profile.' );
		if ( ! empty( $candidate['agent_slug'] ) && ( empty( $agent['slug'] ) || ! hash_equals( (string) $candidate['agent_slug'], (string) $agent['slug'] ) ) ) return new WP_Error( 'mad4b_approval_decision_agent_denied', 'Ticket is not bound to the current Site Profile governed-write agent.' );

		$payload = isset( $request['expected_payload_sha256'] ) ? strtolower( trim( (string) $request['expected_payload_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $payload ) || empty( $ticket['payload_sha256'] ) || ! hash_equals( strtolower( (string) $ticket['payload_sha256'] ), $payload ) ) return new WP_Error( 'mad4b_approval_decision_payload_mismatch', 'Expected payload digest does not match the pending ticket.' );
		$expected_sha = isset( $request['expected_candidate_sha'] ) ? strtolower( trim( (string) $request['expected_candidate_sha'] ) ) : '';
		$expected_build = isset( $request['expected_build_fingerprint'] ) ? strtolower( trim( (string) $request['expected_build_fingerprint'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || empty( $candidate['source_commit_sha'] ) || ! hash_equals( (string) $candidate['source_commit_sha'], $expected_sha ) ) return new WP_Error( 'mad4b_approval_decision_candidate_mismatch', 'Expected candidate SHA does not match the exact live build.' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_build ) || empty( $candidate['build_fingerprint'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], $expected_build ) ) return new WP_Error( 'mad4b_approval_decision_build_mismatch', 'Expected build fingerprint does not match the exact live build.' );
		if ( empty( $binding ) || MAD4B_SCP_Approval_Tickets::CANDIDATE_BINDING_CONTRACT !== ( isset( $binding['contract'] ) ? (string) $binding['contract'] : '' ) ) return new WP_Error( 'mad4b_approval_decision_binding_missing', 'Ticket has no v2 Site Profile/build binding from its planning request.' );

		$expected_binding = array(
			'ticket_id' => $ticket_id,
			'payload_sha256' => $payload,
			'candidate_sha' => $expected_sha,
			'build_fingerprint' => $expected_build,
			'site_uuid' => (string) $candidate['site_uuid'],
			'profile_revision' => (string) (int) $candidate['site_profile_revision'],
			'profile_digest' => (string) $candidate['site_profile_digest'],
			'environment' => (string) $candidate['environment'],
			'host' => (string) $candidate['host'],
		);
		foreach ( $expected_binding as $key => $expected ) {
			if ( ! isset( $binding[ $key ] ) || ! hash_equals( (string) $expected, (string) $binding[ $key ] ) ) return new WP_Error( 'mad4b_approval_decision_binding_mismatch', 'Pending ticket was planned for a different site/profile/build/payload.' );
		}
		return true;
	}

	private static function prepare_authority_runtime_after_validation() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) return new WP_Error( 'mad4b_approval_decision_write_authority_not_ready', 'Governed write authority is unavailable.' );
		if ( function_exists( 'rest_get_server' ) ) {
			try {
				rest_get_server();
				if ( class_exists( 'MAD4B_SCP_MCP_Registration_Rescue' ) ) MAD4B_SCP_MCP_Registration_Rescue::reconcile( 'admin_approval_decision' );
			} catch ( Throwable $e ) {
				return new WP_Error( 'mad4b_approval_decision_runtime_prime_failed', 'Governed runtime could not be primed for the approval decision.' );
			}
		}
		$status = MAD4B_SCP_Staging_Write_Authority::reconcile();
		if ( ! is_array( $status ) || empty( $status['ready'] ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return new WP_Error( 'mad4b_approval_decision_write_authority_not_ready', 'Governed write authority is not ready.' );
		return $status;
	}

	public static function decide( array $request ) {
		if ( ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_approval_decision_schema_unavailable', 'Approval decisions require physically verified governance schema integrity.' );
		$ticket_id = isset( $request['ticket_id'] ) ? strtolower( trim( (string) $request['ticket_id'] ) ) : '';
		$ticket = preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ? MAD4B_SCP_Approval_Tickets::get( $ticket_id ) : null;
		if ( ! is_array( $ticket ) ) return new WP_Error( 'mad4b_approval_decision_ticket_missing', 'Approval ticket does not exist.' );
		$binding = MAD4B_SCP_Approval_Tickets::candidate_binding_from_ticket( $ticket );
		$candidate = self::current_candidate();
		$agent = ! empty( $ticket['agent_id'] ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::get_agent_by_id( (int) $ticket['agent_id'] ) : null;
		$is_approver = class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'can_approve_mutations' ) ? MAD4B_SCP_Policy::can_approve_mutations() : current_user_can( 'manage_options' );
		$validated = self::validate_decision_for_test( $request, $ticket, is_array( $binding ) ? $binding : array(), $candidate, is_array( $agent ) ? $agent : array(), $is_approver, function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown', self::home_host(), time() );
		if ( is_wp_error( $validated ) ) return $validated;
		$runtime = self::prepare_authority_runtime_after_validation();
		if ( is_wp_error( $runtime ) ) return $runtime;
		$ability = (string) $ticket['ability_name'];
		if ( ! MAD4B_SCP_Staging_Write_Authority::is_write_ability( $ability ) ) return new WP_Error( 'mad4b_approval_decision_target_unmounted', 'Ticket target is no longer in the certified write inventory.' );
		$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
		if ( null === $provider || sanitize_key( (string) $ticket['provider'] ) !== sanitize_key( (string) $provider ) ) return new WP_Error( 'mad4b_approval_decision_provider_mismatch', 'Ticket provider no longer matches the certified write projection.' );
		return MAD4B_SCP_Approval_Tickets::decide_pending( $ticket_id, (string) $request['decision'], (string) $request['expected_payload_sha256'], array( 'candidate_sha' => (string) $candidate['source_commit_sha'], 'build_fingerprint' => (string) $candidate['build_fingerprint'], 'site_uuid' => (string) $candidate['site_uuid'], 'site_profile_revision' => (int) $candidate['site_profile_revision'], 'site_profile_digest' => (string) $candidate['site_profile_digest'], 'contract' => self::CONTRACT ) );
	}

	public static function handle_admin_post() {
		$is_approver = class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'can_approve_mutations' ) ? MAD4B_SCP_Policy::can_approve_mutations() : current_user_can( 'manage_options' );
		if ( ! $is_approver ) wp_die( esc_html__( 'Approval capability is required to decide MAD4B approval tickets.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		$request = array(
			'ticket_id' => isset( $_POST['ticket_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_id'] ) ) : '',
			'decision' => isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '',
			'expected_payload_sha256' => isset( $_POST['expected_payload_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_payload_sha256'] ) ) : '',
			'expected_candidate_sha' => isset( $_POST['expected_candidate_sha'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_candidate_sha'] ) ) : '',
			'expected_build_fingerprint' => isset( $_POST['expected_build_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_build_fingerprint'] ) ) : '',
		);
		check_admin_referer( self::nonce_action( $request['ticket_id'], $request['expected_payload_sha256'] ) );
		$result = self::decide( $request );
		$code = is_wp_error( $result ) ? $result->get_error_code() : 'success';
		$status = is_wp_error( $result ) ? 'error' : ( isset( $result['status'] ) ? (string) $result['status'] : 'updated' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'mad4b_decision_result' => $code, 'mad4b_decision_status' => $status ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		$is_approver = class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'can_approve_mutations' ) ? MAD4B_SCP_Policy::can_approve_mutations() : current_user_can( 'manage_options' );
		if ( ! $is_approver ) wp_die( esc_html__( 'Approval capability is required to decide MAD4B approval tickets.', 'mad4b-site-control-plane' ) );
		$candidate = self::current_candidate();
		echo '<div class="wrap"><h1>' . esc_html__( 'MAD4B Approval Decisions', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Human-only decision inbox. GET is read-only; every POST revalidates the exact Site Profile, deployed build, provider and one-time ticket before changing state.', 'mad4b-site-control-plane' ) . '</p>';
		if ( isset( $_GET['mad4b_decision_result'] ) ) {
			$code = sanitize_key( wp_unslash( $_GET['mad4b_decision_result'] ) );
			$status = isset( $_GET['mad4b_decision_status'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_decision_status'] ) ) : '';
			echo '<div class="notice ' . ( 'success' === $code ? 'notice-success' : 'notice-error' ) . ' inline"><p><code>' . esc_html( $code ) . '</code> · ' . esc_html( $status ) . '</p></div>';
		}
		echo '<p><strong>Site:</strong> <code>' . esc_html( $candidate['site_uuid'] ) . '</code> · revision <code>' . esc_html( (string) $candidate['site_profile_revision'] ) . '</code><br><strong>Environment:</strong> <code>' . esc_html( $candidate['environment'] ) . '</code> · <code>' . esc_html( $candidate['host'] ) . '</code><br><strong>Candidate:</strong> <code>' . esc_html( $candidate['source_commit_sha'] ) . '</code><br><strong>Build:</strong> <code>' . esc_html( $candidate['build_fingerprint'] ) . '</code></p>';
		if ( ! MAD4B_SCP_Schema::is_ready() ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Current governance schema metadata is not ready. The decision inbox remains fail-closed.', 'mad4b-site-control-plane' ) . '</p></div></div>'; return; }
		if ( empty( $candidate['ready'] ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Exact Site Profile/build provenance is not ready. No ticket can be decided.', 'mad4b-site-control-plane' ) . '</p></div></div>'; return; }
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'actionable';
		if ( ! in_array( $view, array( 'actionable', 'history' ), true ) ) $view = 'actionable';
		$base_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		echo '<nav class="nav-tab-wrapper"><a class="nav-tab ' . ( 'actionable' === $view ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'view', 'actionable', $base_url ) ) . '">' . esc_html__( 'Needs action', 'mad4b-site-control-plane' ) . '</a><a class="nav-tab ' . ( 'history' === $view ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'view', 'history', $base_url ) ) . '">' . esc_html__( 'History', 'mad4b-site-control-plane' ) . '</a></nav>';
		if ( 'history' === $view ) {
			$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
			$result = MAD4B_SCP_Approval_Repository::history( $candidate, $page );
			if ( is_wp_error( $result ) ) { self::render_read_error( $result ); echo '</div>'; return; }
			self::render_table( isset( $result['rows'] ) ? $result['rows'] : array(), $candidate, false );
			if ( $page > 1 || ! empty( $result['has_more'] ) ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				if ( $page > 1 ) echo '<a class="button" href="' . esc_url( add_query_arg( array( 'view' => 'history', 'paged' => $page - 1 ), $base_url ) ) . '">' . esc_html__( 'Previous', 'mad4b-site-control-plane' ) . '</a> ';
				if ( ! empty( $result['has_more'] ) ) echo '<a class="button" href="' . esc_url( add_query_arg( array( 'view' => 'history', 'paged' => $page + 1 ), $base_url ) ) . '">' . esc_html__( 'Next', 'mad4b-site-control-plane' ) . '</a>';
				echo '</div></div>';
			}
		} else {
			$rows = MAD4B_SCP_Approval_Repository::actionable( $candidate );
			if ( is_wp_error( $rows ) ) { self::render_read_error( $rows ); echo '</div>'; return; }
			if ( empty( $rows ) ) echo '<p>' . esc_html__( 'No fresh exact-profile/build approval ticket currently needs a human decision.', 'mad4b-site-control-plane' ) . '</p>';
			else self::render_table( $rows, $candidate, true );
		}
		echo '</div>';
	}

	private static function render_table( array $rows, array $candidate, $allow_decisions ) {
		if ( empty( $rows ) ) { echo '<p>' . esc_html__( 'No approval tickets found for this view.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Ticket</th><th>Ability</th><th>Status</th><th>Expires</th><th>Site/build binding</th><th>Decision</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$ticket_id = isset( $row['ticket_id'] ) ? (string) $row['ticket_id'] : '';
			$payload = isset( $row['payload_sha256'] ) ? strtolower( (string) $row['payload_sha256'] ) : '';
			$binding_ok = ! empty( $row['binding_exact'] );
			$effective = isset( $row['effective_status'] ) ? (string) $row['effective_status'] : ( isset( $row['status'] ) ? (string) $row['status'] : '' );
			echo '<tr><td><code>' . esc_html( $ticket_id ) . '</code></td><td><code>' . esc_html( isset( $row['ability_name'] ) ? $row['ability_name'] : '' ) . '</code></td><td><strong>' . esc_html( $effective ) . '</strong></td><td>' . esc_html( isset( $row['expires_at'] ) ? $row['expires_at'] : '' ) . '</td><td>' . esc_html( $binding_ok ? 'exact current site/build' : 'missing/stale' ) . '</td><td>';
			if ( $allow_decisions && ! empty( $row['actionable'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:6px">';
				foreach ( array( 'action' => self::ACTION, 'ticket_id' => $ticket_id, 'expected_payload_sha256' => $payload, 'expected_candidate_sha' => $candidate['source_commit_sha'], 'expected_build_fingerprint' => $candidate['build_fingerprint'] ) as $name => $value ) echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
				wp_nonce_field( self::nonce_action( $ticket_id, $payload ) );
				echo '<button type="submit" class="button button-primary" name="decision" value="approve">' . esc_html__( 'Approve', 'mad4b-site-control-plane' ) . '</button><button type="submit" class="button" name="decision" value="reject">' . esc_html__( 'Reject', 'mad4b-site-control-plane' ) . '</button></form>';
			} else echo '—';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_read_error( WP_Error $error ) {
		echo '<div class="notice notice-error inline"><p><code>' . esc_html( $error->get_error_code() ) . '</code> · ' . esc_html( $error->get_error_message() ) . '</p></div>';
	}

	private static function nonce_action( $ticket_id, $payload ) { return 'mad4b_approval_decision:' . strtolower( trim( (string) $ticket_id ) ) . ':' . strtolower( trim( (string) $payload ) ); }

	private static function home_host() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) return strtolower( rtrim( (string) MAD4B_SCP_Site_Profile::current_host(), '.' ) );
		$url = home_url( '/' );
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		return is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	}
}
