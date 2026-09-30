<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded, idempotent schema reconciliation across same-version package replacements. */
final class MAD4B_SCP_Schema_Lifecycle {
	const CONTRACT = 'mad4b.schema-lifecycle.v1';
	const STATE_OPTION = 'mad4b_scp_schema_lifecycle_v1';
	const CRON_HOOK = 'mad4b_scp_schema_lifecycle_reconcile';
	private static $package_identity = '';

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'reconcile' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 20, 2 );
	}

	public static function package_identity() {
		if ( '' !== self::$package_identity ) return self::$package_identity;
		$main_sha = defined( 'MAD4B_SCP_FILE' ) && is_file( MAD4B_SCP_FILE ) ? hash_file( 'sha256', MAD4B_SCP_FILE ) : '';
		$schema_file = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'includes/class-mad4b-scp-schema.php' : '';
		$schema_sha = '' !== $schema_file && is_file( $schema_file ) ? hash_file( 'sha256', $schema_file ) : '';
		$build_file = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-RUNTIME-BUILD.txt' : '';
		$build_sha = '' !== $build_file && is_file( $build_file ) ? hash_file( 'sha256', $build_file ) : '';
		self::$package_identity = hash( 'sha256', implode( "\0", array(
			self::CONTRACT,
			defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			(string) $main_sha,
			(string) $schema_sha,
			(string) $build_sha,
			MAD4B_SCP_Schema::MIGRATION_ID,
		) ) );
		return self::$package_identity;
	}

	public static function needs_reconciliation() {
		$state = get_option( self::STATE_OPTION, array() );
		$applied = is_array( $state ) && isset( $state['applied_package_identity'] ) ? strtolower( (string) $state['applied_package_identity'] ) : '';
		return ! MAD4B_SCP_Schema::is_ready() || ! hash_equals( self::package_identity(), $applied );
	}

	public static function maybe_schedule() {
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false ) && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		if ( ! self::needs_reconciliation() ) return;
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) return;
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
	}

	public static function after_upgrade( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '' ) ) return;
		$current = defined( 'MAD4B_SCP_FILE' ) && function_exists( 'plugin_basename' ) ? plugin_basename( MAD4B_SCP_FILE ) : '';
		$targets = array();
		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) $targets[] = (string) $hook_extra['plugin'];
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) $targets = array_merge( $targets, array_map( 'strval', $hook_extra['plugins'] ) );
		if ( '' === $current || ! in_array( $current, array_values( array_unique( $targets ) ), true ) ) return;
		self::$package_identity = '';
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	public static function reconcile() {
		$result = MAD4B_SCP_Schema::install_or_upgrade();
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) $state = array();
		$state['contract'] = self::CONTRACT;
		$state['package_identity'] = self::package_identity();
		$state['attempted_at'] = gmdate( 'c' );
		$state['schema_version'] = MAD4B_SCP_Schema::VERSION;
		if ( is_wp_error( $result ) ) {
			$state['state'] = 'blocked';
			$state['error_code'] = sanitize_key( (string) $result->get_error_code() );
			$state['attempts'] = min( 10, 1 + ( isset( $state['attempts'] ) ? absint( $state['attempts'] ) : 0 ) );
			update_option( self::STATE_OPTION, $state, false );
			$delay = min( HOUR_IN_SECONDS, max( MINUTE_IN_SECONDS, MINUTE_IN_SECONDS * ( 1 << min( 5, $state['attempts'] - 1 ) ) ) );
			if ( false === wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
			return $result;
		}
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::ensure_head_initialized() : true;
		if ( is_wp_error( $audit ) ) {
			$state['state'] = 'audit_blocked';
			$state['error_code'] = sanitize_key( (string) $audit->get_error_code() );
			$state['attempts'] = min( 10, 1 + ( isset( $state['attempts'] ) ? absint( $state['attempts'] ) : 0 ) );
			update_option( self::STATE_OPTION, $state, false );
			$delay = min( HOUR_IN_SECONDS, max( MINUTE_IN_SECONDS, MINUTE_IN_SECONDS * ( 1 << min( 5, $state['attempts'] - 1 ) ) ) );
			if ( false === wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
			return $audit;
		}
		$state['state'] = 'ready';
		$state['error_code'] = '';
		$state['attempts'] = 0;
		$state['applied_package_identity'] = self::package_identity();
		$state['completed_at'] = gmdate( 'c' );
		update_option( self::STATE_OPTION, $state, false );
		if ( class_exists( 'MAD4B_SCP_Runtime_Convergence' ) && method_exists( 'MAD4B_SCP_Runtime_Convergence', 'mark_activation_pending' ) ) MAD4B_SCP_Runtime_Convergence::mark_activation_pending();
		return true;
	}
}
