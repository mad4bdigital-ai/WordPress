<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded, idempotent schema reconciliation across same-version package replacements. */
final class MAD4B_SCP_Schema_Lifecycle {
	const CONTRACT = 'mad4b.schema-lifecycle.v1';
	const STATE_OPTION = 'mad4b_scp_schema_lifecycle_v1';
	const CRON_HOOK = 'mad4b_scp_schema_lifecycle_reconcile';
	const LOCK_OPTION = 'mad4b_scp_runtime_maintenance_lock_v1';
	private static $package_identity = '';

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_reconcile_admin_lifecycle' ), 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'reconcile_scheduled' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 20, 2 );
	}

	public static function package_identity() {
		if ( '' !== self::$package_identity ) return self::$package_identity;
		// Schema convergence is keyed only by release/schema identity. A code-only
		// same-version package replacement does not require database migration.
		// Missing/incomplete schema remains independently fail-closed via is_ready().
		self::$package_identity = hash( 'sha256', implode( "\0", array(
			self::CONTRACT,
			defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			(string) MAD4B_SCP_Schema::VERSION,
			(string) MAD4B_SCP_Schema::MIGRATION_ID,
		) ) );
		return self::$package_identity;
	}

	public static function mark_current_package_applied( $source = 'lifecycle' ) {
		if ( ! MAD4B_SCP_Schema::is_ready() || ! MAD4B_SCP_Schema::critical_ready() ) return false;
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) $state = array();
		$now = gmdate( 'c' );
		$state['contract'] = self::CONTRACT;
		$state['state'] = 'ready';
		$state['error_code'] = '';
		$state['attempts'] = 0;
		$state['next_attempt_at'] = 0;
		$state['schema_version'] = MAD4B_SCP_Schema::VERSION;
		$state['package_identity'] = self::package_identity();
		$state['applied_package_identity'] = self::package_identity();
		$state['source'] = sanitize_key( (string) $source );
		$state['attempted_at'] = isset( $state['attempted_at'] ) ? $state['attempted_at'] : $now;
		$state['completed_at'] = $now;
		update_option( self::STATE_OPTION, $state, false );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		return true;
	}

	public static function needs_reconciliation() {
		$state = get_option( self::STATE_OPTION, array() );
		$applied = is_array( $state ) && isset( $state['applied_package_identity'] ) ? strtolower( (string) $state['applied_package_identity'] ) : '';
		return ! MAD4B_SCP_Schema::is_ready() || ! hash_equals( self::package_identity(), $applied );
	}

	private static function restart_grace() {
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Convergence' ) || ! method_exists( 'MAD4B_SCP_Runtime_Convergence', 'restart_grace_status' ) ) {
			return array( 'active' => false, 'retry_after_seconds' => 0, 'resume_not_before' => 0 );
		}
		$status = MAD4B_SCP_Runtime_Convergence::restart_grace_status();
		return is_array( $status ) ? $status : array( 'active' => false, 'retry_after_seconds' => 0, 'resume_not_before' => 0 );
	}

	public static function maybe_schedule() {
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false ) && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		$grace = self::restart_grace();
		if ( ! empty( $grace['active'] ) ) return;
		if ( ! self::needs_reconciliation() || ! self::retry_due() ) return;
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) return;
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
	}

	public static function reconcile_scheduled() {
		if ( ! self::needs_reconciliation() || ! self::retry_due() ) return;
		$lock = self::acquire_lock();
		if ( '' === $lock ) return;
		try {
			self::reconcile( 'scheduled' );
		} finally {
			self::release_lock( $lock );
		}
	}

	public static function maybe_reconcile_admin_lifecycle() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false ) && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		$grace = self::restart_grace();
		if ( ! empty( $grace['active'] ) ) return;
		if ( ! self::needs_reconciliation() || ! self::retry_due() ) return;
		$lock = self::acquire_lock();
		if ( '' === $lock ) return;
		try {
			self::reconcile( 'admin_package_lifecycle' );
		} finally {
			self::release_lock( $lock );
		}
	}

	private static function retry_due() {
		$state = get_option( self::STATE_OPTION, array() );
		$next = is_array( $state ) && isset( $state['next_attempt_at'] ) ? absint( $state['next_attempt_at'] ) : 0;
		return 0 === $next || time() >= $next;
	}

	private static function acquire_lock() {
		$now = time();
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && ! empty( $current['expires_at'] ) && absint( $current['expires_at'] ) > $now ) return '';
		if ( is_array( $current ) && ! empty( $current ) ) delete_option( self::LOCK_OPTION );
		$token = hash( 'sha256', self::package_identity() . "\0" . microtime( true ) . "\0" . wp_rand() );
		$record = array( 'token' => $token, 'owner' => 'schema_lifecycle', 'expires_at' => $now + 120 );
		if ( ! add_option( self::LOCK_OPTION, $record, '', false ) ) return '';
		return $token;
	}

	private static function release_lock( $token ) {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['token'] ) && is_string( $current['token'] ) && hash_equals( $current['token'], (string) $token ) ) delete_option( self::LOCK_OPTION );
	}

	public static function after_upgrade( $upgrader, $hook_extra ) {
		// Governed self-update owns post-install convergence. Scheduling another
		// schema job from Plugin_Upgrader here creates a worker/DB race exactly at
		// the reconnect boundary, so defer that path to Runtime Convergence.
		if ( class_exists( 'MAD4B_SCP_Self_Update' )
			&& method_exists( 'MAD4B_SCP_Self_Update', 'managed_apply_in_progress' )
			&& MAD4B_SCP_Self_Update::managed_apply_in_progress() ) return;
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '' ) ) return;
		$current = defined( 'MAD4B_SCP_FILE' ) && function_exists( 'plugin_basename' ) ? plugin_basename( MAD4B_SCP_FILE ) : '';
		$targets = array();
		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) $targets[] = (string) $hook_extra['plugin'];
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) $targets = array_merge( $targets, array_map( 'strval', $hook_extra['plugins'] ) );
		if ( '' === $current || ! in_array( $current, array_values( array_unique( $targets ) ), true ) ) return;
		self::$package_identity = '';
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	public static function reconcile( $source = 'scheduled' ) {
		$source = sanitize_key( (string) $source );
		if ( 'scheduled' === $source && ! self::retry_due() ) {
			$state = get_option( self::STATE_OPTION, array() );
			$next = is_array( $state ) && isset( $state['next_attempt_at'] ) ? absint( $state['next_attempt_at'] ) : 0;
			if ( $next > time() && false === wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_single_event( $next, self::CRON_HOOK );
			return false;
		}
		$result = MAD4B_SCP_Schema::install_or_upgrade();
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) $state = array();
		$state['contract'] = self::CONTRACT;
		$state['package_identity'] = self::package_identity();
		$state['attempted_at'] = gmdate( 'c' );
		$state['schema_version'] = MAD4B_SCP_Schema::VERSION;
		$state['source'] = sanitize_key( (string) $source );
		if ( is_wp_error( $result ) ) {
			$state['state'] = 'blocked';
			$state['error_code'] = sanitize_key( (string) $result->get_error_code() );
			$state['attempts'] = min( 10, 1 + ( isset( $state['attempts'] ) ? absint( $state['attempts'] ) : 0 ) );
			$delay = min( HOUR_IN_SECONDS, max( MINUTE_IN_SECONDS, MINUTE_IN_SECONDS * ( 1 << min( 5, $state['attempts'] - 1 ) ) ) );
			$state['next_attempt_at'] = time() + $delay;
			update_option( self::STATE_OPTION, $state, false );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_single_event( $state['next_attempt_at'], self::CRON_HOOK );
			return $result;
		}
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::ensure_head_initialized() : true;
		if ( is_wp_error( $audit ) ) {
			$state['state'] = 'audit_blocked';
			$state['error_code'] = sanitize_key( (string) $audit->get_error_code() );
			$state['attempts'] = min( 10, 1 + ( isset( $state['attempts'] ) ? absint( $state['attempts'] ) : 0 ) );
			$delay = min( HOUR_IN_SECONDS, max( MINUTE_IN_SECONDS, MINUTE_IN_SECONDS * ( 1 << min( 5, $state['attempts'] - 1 ) ) ) );
			$state['next_attempt_at'] = time() + $delay;
			update_option( self::STATE_OPTION, $state, false );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_single_event( $state['next_attempt_at'], self::CRON_HOOK );
			return $audit;
		}
		$state['state'] = 'ready';
		$state['error_code'] = '';
		$state['attempts'] = 0;
		$state['next_attempt_at'] = 0;
		$state['applied_package_identity'] = self::package_identity();
		$state['completed_at'] = gmdate( 'c' );
		update_option( self::STATE_OPTION, $state, false );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( class_exists( 'MAD4B_SCP_Runtime_Convergence' ) && method_exists( 'MAD4B_SCP_Runtime_Convergence', 'mark_activation_pending' ) ) MAD4B_SCP_Runtime_Convergence::mark_activation_pending();
		return true;
	}
}
