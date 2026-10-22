<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** On-demand, administrator-only read reports; no authority is persisted. */
final class MAD4B_SCP_Deferred_Admin_Reports {
	const SOFT_BUDGET_MS = 5000;
	const LABELS = array(
		'write' => 'Write Authority', 'database' => 'Database Topology',
		'authority' => 'Full Authority', 'providers' => 'Provider Closure',
		'workspace' => 'Full operator workspace (aggregate report)',
		'adaptive' => 'Adaptive Runtime', 'chatgpt' => 'ChatGPT connection details',
	);

	public static function render( $surface ) {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator capability required.' );
		wp_enqueue_script( 'mad4b-deferred-admin-reports', plugins_url( 'assets/deferred-admin-reports.js', MAD4B_SCP_FILE ), array(), MAD4B_SCP_VERSION, true );
		$keys = 'chatgpt' === $surface ? array( 'chatgpt' ) : array( 'write', 'database', 'authority', 'providers', 'adaptive', 'workspace' );
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
