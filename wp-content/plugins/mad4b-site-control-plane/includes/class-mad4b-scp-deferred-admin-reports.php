<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** On-demand, administrator-only read reports; no authority is persisted. */
final class MAD4B_SCP_Deferred_Admin_Reports {
	const SOFT_BUDGET_MS = 5000;
	const LABELS = array(
		'write' => 'Write Authority', 'database' => 'Database Topology',
		'authority' => 'Full Authority', 'providers' => 'Provider Closure',
		'workspace' => 'Full operator workspace (aggregate report)',
		'adaptive' => 'Adaptive Runtime', 'host_authority' => 'Host Authority Lifecycle', 'chatgpt' => 'ChatGPT connection details',
	);

	public static function render( $surface ) {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator capability required.' );
		// Version the existing on-demand admin script by its deployed file
		// mtime. Otherwise a browser cached before v92 ignores host_authority.
		$asset = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'assets/deferred-admin-reports.js' : '';
		$version = $asset && is_file( $asset ) ? (string) filemtime( $asset ) : MAD4B_SCP_VERSION;
		wp_enqueue_script( 'mad4b-deferred-admin-reports', plugins_url( 'assets/deferred-admin-reports.js', MAD4B_SCP_FILE ), array(), $version, true );
		$keys = 'chatgpt' === $surface ? array( 'chatgpt' ) : array( 'write', 'database', 'authority', 'providers', 'adaptive', 'host_authority', 'workspace' );
		echo '<div class="wrap" data-mad4b-reports data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'mad4b_admin_report' ) ) . '">';
		echo '<h1>' . esc_html( 'chatgpt' === $surface ? 'Connect WordPress to ChatGPT' : 'Operator Control Center' ) . '</h1>';
		echo '<p>Not checked. Run one read-only report at a time. Local results do not certify external MCP access or authorize Production.</p>';
		echo '<p>A timeout stops browser waiting; PHP may still be running. No automatic retry is performed.</p>';
		foreach ( $keys as $key ) {
			echo '<section><h2>' . esc_html( self::LABELS[ $key ] ) . '</h2><button type="button" class="button" data-report="' . esc_attr( $key ) . '">Check</button><p data-report-result="' . esc_attr( $key ) . '" role="status">Not checked</p><div data-report-content="' . esc_attr( $key ) . '"></div></section>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mad4b-runtime-components&tab=diagnostics' ) ) . '">Runtime Diagnostics</a> · <a href="' . esc_url( admin_url( 'admin.php?page=mad4b-control-plane-site-profile' ) ) . '">Site Profile</a></p></div>';
	}

	public static function ajax() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_send_json_error( array( 'code' => 'method_not_allowed' ), 405 ); return; }
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'code' => 'forbidden' ), 403 ); return; }
		if ( ! check_ajax_referer( 'mad4b_admin_report', 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 ); return; }
		$key = isset( $_POST['report'] ) && is_string( $_POST['report'] ) ? $_POST['report'] : '';
		if ( ! isset( self::LABELS[ $key ] ) ) { wp_send_json_error( array( 'code' => 'unknown_report' ), 400 ); return; }
		nocache_headers();
		wp_send_json_success( self::measure( $key ) );
	}

	public static function measure( $key ) {
		$start = microtime( true );
		$queries = get_num_queries();
		$level = ob_get_level();
		$result = array( 'state' => 'NOT_CHECKED', 'ready' => null, 'html' => '' );
		try {
			ob_start(); // Discard unexpected provider output, including exception paths.
			switch ( $key ) {
				case 'write': $raw = MAD4B_SCP_Staging_Write_Authority::current_execution_readiness(); break;
				case 'database': $raw = MAD4B_SCP_Database_Topology::status( false ); break;
				case 'authority': $raw = MAD4B_SCP_Full_Staging_Authority::status(); break;
				case 'providers': $raw = MAD4B_SCP_Provider_Closure_Matrix::matrix(); break;
				case 'adaptive': $raw = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => false, 'limit' => 5 ) ); break;
				case 'host_authority':
					$raw = MAD4B_SCP_Host_Authority_Lifecycle::snapshot();
					MAD4B_SCP_Host_Authority_Lifecycle::render_admin();
					$result['html'] = ob_get_contents();
					break;
				case 'workspace':
					$raw = MAD4B_SCP_Operator_Control_Center::execute();
					$adaptive = MAD4B_SCP_Adaptive_Runtime_Convergence::status( array( 'include_capabilities' => true ) );
					MAD4B_SCP_Operator_Workspace::render( $raw, is_array( $adaptive ) ? $adaptive : array() );
					$result['html'] = ob_get_contents();
					break;
				case 'chatgpt':
					MAD4B_SCP_ChatGPT_Connection_Admin_UI::render_checked_page();
					$result['html'] = ob_get_contents();
					$raw = array();
					break;
				default: throw new InvalidArgumentException( 'Unknown report' );
			}
			if ( ! is_array( $raw ) ) throw new UnexpectedValueException( 'Invalid report' );
			$result['state'] = 'MEASURED';
			// Absence is unknown, never healthy. Do not project arbitrary provider data.
			$result['ready'] = isset( $raw['ready'] ) && is_bool( $raw['ready'] ) ? $raw['ready'] : null;
		} catch ( Throwable $error ) {
			$result = array( 'state' => 'REPORT_FAILED', 'ready' => null, 'html' => '' );
		} finally {
			while ( ob_get_level() > $level ) ob_end_clean();
		}
		$result['elapsed_ms'] = round( ( microtime( true ) - $start ) * 1000, 2 );
		$result['query_count'] = max( 0, get_num_queries() - $queries );
		// A soft budget detects overrun after return; it cannot interrupt a PHP callback.
		$result['budget_exceeded'] = $result['elapsed_ms'] > self::SOFT_BUDGET_MS;
		if ( $result['budget_exceeded'] ) {
			$result['state'] = 'BUDGET_EXCEEDED';
			$result['ready'] = null;
		}
		return $result;
	}
}

/**
 * Host Authority Lifecycle — bounded, administrator-only, read-only
 * observation of the actual Original305/328 enrollment and Original318 WP
 * signed Anchor state. Root Original312/313 certification is NOT available
 * through WordPress and cannot be inferred from an active signed option.
 *
 * This class intentionally registers NO issue/revoke/rollback endpoint and
 * does not accept a caller-provided company, target, original proof or key.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Host_Authority_Lifecycle {
	const CONTRACT = 'mad4b.host-authority-lifecycle.operator-read.v1';
	const API_ROUTE = '/host-authority-lifecycle';
	const MAX_TARGETS = 25;

	public static function can_read() {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes() {
		register_rest_route( 'mad4b/v1', self::API_ROUTE, array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'can_read' ),
			'callback'            => array( __CLASS__, 'rest_read' ),
		) );
	}

	public static function rest_read() {
		// Guard the callback as well as the REST permission callback; no
		// ability, direct PHP call or custom router may skip authorization.
		if ( ! self::can_read() ) {
			return new WP_Error( 'mad4b_host_authority_forbidden', 'Administrator permission required.', array( 'status' => 403 ) );
		}
		$response = rest_ensure_response( self::snapshot() );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}

	/** No caller-supplied scope: read only current *enrolled* central slots. */
	private static function enrolled_targets() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb )
			|| ! class_exists( 'MAD4B_Company_Native_WPCLI_Enrollment_V1', false )
			|| ! defined( 'MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT' ) ) return array();
		$prefix = MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT;
		$like = $wpdb->esc_like( $prefix ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d",
			$like, self::MAX_TARGETS
		) );
		if ( ! is_array( $names ) ) return array();
		$targets = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || 0 !== strpos( $name, $prefix ) ) continue;
			$slot = get_option( $name, false );
			if ( ! is_array( $slot ) ) continue;
			$company = $slot['company'] ?? null;
			$env = $slot['environment'] ?? null;
			if ( ! is_string( $company ) || ! is_string( $env )
				|| ! preg_match( '/^[a-z][a-z0-9-]{2,47}$/D', $company )
				|| ! preg_match( '/^[a-z][a-z0-9_-]{1,39}$/D', $env )
				|| $name !== $prefix . $company . '_' . $env
				|| ! is_string( $slot['site_uuid'] ?? null )
				|| ! function_exists( 'wp_is_uuid' )
				|| ! wp_is_uuid( $slot['site_uuid'] ) ) continue;
			$key = $company . ':' . $env;
			if ( isset( $targets[ $key ] ) ) continue;
			$targets[ $key ] = array(
				'company' => $company,
				'environment' => $env,
				'site_uuid' => $slot['site_uuid'],
				'slot' => $slot,
			);
		}
		return array_values( $targets );
	}

	private static function operation_readback( $op ) {
		$default = array(
			'registered' => false,
			'provider_state' => 'NOT_REGISTERED',
			'provider_ready' => false,
		);
		if ( ! class_exists( 'MAD4B_Original_Native_Operation_Providers_V1', false ) ) return $default;
		try {
			$report = MAD4B_Original_Native_Operation_Providers_V1::discover( $op );
			$found = $report['operations'][0] ?? null;
			if ( ! is_array( $found ) || ( $found['operation'] ?? null ) !== $op ) return $default;
			return array(
				'registered' => ( $found['registration_verified'] ?? false ) === true,
				'provider_state' => (string) ( $found['provider_readback_state'] ?? 'UNVERIFIED' ),
				'provider_ready' => ( $found['provider_capability_ready'] ?? false ) === true,
			);
		} catch ( Throwable $e ) {
			return $default;
		}
	}

	private static function target_readback( array $target, array $issue, array $revoke ) {
		$company = $target['company'];
		$env = $target['environment'];
		$slot = $target['slot'];
		$original305 = 'UNVERIFIED';
		$original328 = false;
		if ( class_exists( 'MAD4B_Company_Native_WPCLI_Enrollment_V1', false ) ) {
			try {
				$row = MAD4B_Company_Native_WPCLI_Enrollment_V1::readback( $company, $env );
				if ( is_array( $row ) ) $original305 = (string) ( $row['state'] ?? 'UNVERIFIED' );
			} catch ( Throwable $e ) {}
		}
		if ( class_exists( 'MAD4B_Central_SSH_Command_Intake_V1', false )
			&& class_exists( 'MAD4B_Original_Remote_Hostinger_328_V1', false ) ) {
			try {
				$transport = get_option(
					MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX . (string) ( $slot['transport_id'] ?? '' ), false );
				$grant = get_option(
					MAD4B_Central_SSH_Command_Intake_V1::PREFIX . (string) ( $slot['grant_id'] ?? '' ), false );
				$original328 = is_array( $transport ) && is_array( $grant )
					&& MAD4B_Original_Remote_Hostinger_328_V1::active( $transport, $grant ) === true;
			} catch ( Throwable $e ) {}
		}
		$anchor = array(
			'state' => 'UNVERIFIED', 'revision' => 0,
			'readback_verified' => false, 'original313_certified' => false,
			'write_authorized' => false,
		);
		if ( class_exists( 'MAD4B_Native_Host_Exact_Grant_V1', false ) ) {
			try {
				$current = MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2( $company, $env );
				if ( is_array( $current ) ) $anchor = $current;
			} catch ( Throwable $e ) {}
		}
		return array(
			'company' => $company,
			'environment' => $env,
			'site_uuid' => $target['site_uuid'],
			'original305_state' => $original305,
			'original328_active' => $original328,
			'anchor_state' => (string) ( $anchor['state'] ?? 'UNVERIFIED' ),
			'anchor_revision' => max( 0, (int) ( $anchor['revision'] ?? 0 ) ),
			'wordpress_anchor_readback_verified' => ( $anchor['readback_verified'] ?? false ) === true,
			'original313_independent_checkpoint_verified' => false,
			'write_authorized' => false,
			'issue' => $issue,
			'revoke' => $revoke,
			'lifecycle_execution_enabled' => false,
		);
	}

	public static function snapshot() {
		if ( ! self::can_read() ) {
			return array( 'contract' => self::CONTRACT, 'state' => 'FORBIDDEN', 'targets' => array() );
		}
		$issue = self::operation_readback( 'company.host-authority.anchor.issue' );
		$revoke = self::operation_readback( 'company.host-authority.anchor.revoke' );
		$targets = array();
		foreach ( self::enrolled_targets() as $target ) {
			$targets[] = self::target_readback( $target, $issue, $revoke );
		}
		return array(
			'contract' => self::CONTRACT,
			'state' => empty( $targets ) ? 'NO_ENROLLED_TARGETS' : 'READ_ONLY__INDEPENDENT_AUTHORITY_NOT_CERTIFIED',
			'observed_epoch' => time(),
			'read_only' => true,
			'issue_execution_available' => false,
			'revoke_execution_available' => false,
			'original313_remote_receipt_verified' => false,
			'root_highwater_verified' => false,
			'remote_root_status' => 'NOT_AVAILABLE_FROM_WORDPRESS',
			'issue' => $issue,
			'revoke' => $revoke,
			'target_count' => count( $targets ),
			'targets' => $targets,
		);
	}

	/** Native WordPress admin markup; never submits an action. */
	public static function render_admin() {
		if ( ! self::can_read() ) wp_die( 'Administrator capability required.' );
		$data = self::snapshot();
		echo '<div class="wrap" id="mad4b-host-authority-lifecycle" aria-labelledby="mad4b-host-authority-title">';
		echo '<h2 id="mad4b-host-authority-title">Host Authority Lifecycle</h2>';
		echo '<p>Read-only Original305 / Original328 / Original318 status for enrolled companies. The independent Original312 issuer and Original313 signed checkpoint must be verified outside WordPress before issue or revoke can be enabled.</p>';
		echo '<div class="notice notice-warning inline" role="status" id="mad4b-host-authority-hold"><p><strong>Signing admission on hold.</strong> Issue, Revoke and Rollback are unavailable. A registered provider or signed WordPress option is not an independent Original313 receipt.</p></div>';
		if ( empty( $data['targets'] ) ) {
			echo '<p role="status">No verified company enrollment slots found.</p>';
		} else {
			echo '<div style="overflow-x:auto"><table class="widefat striped"><caption class="screen-reader-text">Current Host Authority status for enrolled companies</caption><thead><tr>';
			foreach ( array( 'Company', 'Environment', 'Original305', 'Original328', 'WordPress Anchor', 'Revision', 'Issue provider', 'Revoke provider', 'Original313' ) as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $data['targets'] as $row ) {
				echo '<tr>';
				echo '<th scope="row">' . esc_html( $row['company'] ) . '</th>';
				echo '<td>' . esc_html( $row['environment'] ) . '</td>';
				echo '<td><code>' . esc_html( $row['original305_state'] ) . '</code></td>';
				echo '<td>' . esc_html( $row['original328_active'] ? 'Active' : 'Not verified' ) . '</td>';
				echo '<td><code>' . esc_html( $row['anchor_state'] ) . '</code></td>';
				echo '<td>' . esc_html( (string) $row['anchor_revision'] ) . '</td>';
				echo '<td>' . esc_html( $row['issue']['provider_state'] ) . '</td>';
				echo '<td>' . esc_html( $row['revoke']['provider_state'] ) . '</td>';
				echo '<td>Not independently certified</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '<h3>Lifecycle checkpoints</h3><ol>';
		echo '<li>Owner-bound Original305/328 enrollment and exact signed Job</li>';
		echo '<li>Purpose-specific Original312 issue or revoke with one-time reservation</li>';
		echo '<li>Fresh WordPress Original318 readback and revision validation</li>';
		echo '<li>Independent Original313 signed checkpoint, high-water and rollback receipt</li>';
		echo '</ol>';
		echo '<p><button class="button button-primary" type="button" disabled aria-describedby="mad4b-host-authority-hold">Issue Anchor</button> ';
		echo '<button class="button" type="button" disabled aria-describedby="mad4b-host-authority-hold">Revoke Anchor</button> ';
		echo '<button class="button" type="button" disabled aria-describedby="mad4b-host-authority-hold">Rollback</button></p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=mad4b-operator-control-center#mad4b-host-authority-lifecycle' ) ) . '">Refresh status</a></p>';
		echo '<p><small>WordPress has no direct access to the root-signed Original313 ledger. The API is administrator-only, GET-only, and never authorizes writes.</small></p>';
		echo '</div>';
	}
}
