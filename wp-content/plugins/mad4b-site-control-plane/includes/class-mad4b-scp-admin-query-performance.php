<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded Staging-only performance hardening for the two meta lookups observed
 * on ETG wp-admin. The indexes are additive and idempotent; no existing index
 * is removed or rewritten and Production is never mutated by this component.
 */
final class MAD4B_SCP_Admin_Query_Performance {
	const CONTRACT = 'mad4b.admin-query-performance.v1';
	const OPTION = 'mad4b_scp_admin_query_performance_v1';
	const JOB_OPTION = 'mad4b_scp_admin_query_performance_job_v1';
	const CRON_HOOK = 'mad4b_scp_admin_query_performance_async';
	const INDEX_VERSION = 1;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_status_ability' ), 36 );
		add_action( 'admin_post_mad4b_apply_admin_query_indexes', array( __CLASS__, 'handle_explicit_apply' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_apply' ), 10, 1 );
	}

	public static function register_status_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/admin-query-performance-status' ) ) ) return;
		wp_register_ability( 'mad4b/admin-query-performance-status', array(
			'label' => 'Get Admin Query Performance Status',
			'description' => 'Read the bounded ETG admin-query index and attribution status without changing database state.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'status' ),
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

	public static function status() {
		global $wpdb;
		$environment = self::environment();
		$specs = self::index_specs();
		$indexes = array();
		$ready = true;
		foreach ( $specs as $key => $spec ) {
			$present = self::equivalent_index_present( $spec['table'], $spec['columns'] );
			$indexes[ $key ] = array(
				'table' => $spec['table'],
				'index_name' => $spec['name'],
				'columns' => $spec['columns'],
				'present' => $present,
			);
			if ( ! $present ) $ready = false;
		}
		$stored = get_option( self::OPTION, array() );
		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'mutation_performed' => false,
			'environment' => $environment,
			'staging_only_mutation' => true,
			'automatic_apply' => false,
			'explicit_admin_action' => 'mad4b_apply_admin_query_indexes',
			'lifecycle_protected_routes' => array( 'update.php', 'update-core.php', 'plugin-install.php', 'plugins.php' ),
			'index_version' => self::INDEX_VERSION,
			'ready' => $ready,
			'indexes' => $indexes,
			'last_apply' => is_array( $stored ) ? $stored : array(),
			'maintenance_job' => self::maintenance_job_status(),
			'query_signatures' => array(
				array(
					'id' => 'attachment_meta_key_discovery',
					'tables' => array( $wpdb->posts, $wpdb->postmeta ),
					'bounded_by' => 'post_type=attachment',
					'benefits_from' => 'postmeta(post_id,meta_key)',
				),
				array(
					'id' => 'rank_math_term_focus_keyword_probe',
					'tables' => array( $wpdb->term_taxonomy, $wpdb->termmeta ),
					'bounded_by' => 'taxonomy + rank_math_focus_keyword',
					'benefits_from' => 'termmeta(term_id,meta_key)',
				),
			),
			'production_changed' => false,
		);
	}

	public static function handle_explicit_apply() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required to apply MAD4B performance indexes.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( 'mad4b_apply_admin_query_indexes', 'mad4b_admin_query_performance_nonce' );
		$result = self::apply_explicit();
		$state = is_array( $result ) && isset( $result['state'] ) ? sanitize_key( (string) $result['state'] ) : ( ! empty( $result['ready'] ) ? 'ready' : 'unknown' );
		$url = add_query_arg( array( 'page' => 'mad4b-control-plane', 'mad4b_performance_apply' => $state ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function maintenance_job_status() {
		$job = get_option( self::JOB_OPTION, array() );
		return is_array( $job ) ? $job : array();
	}

	public static function enqueue_explicit( array $expected_identity = array() ) {
		if ( 'staging' !== self::environment() ) return new WP_Error( 'mad4b_admin_query_performance_staging_only', 'Performance-index maintenance is Staging-only.' );
		$current = self::maintenance_job_status();
		if ( is_array( $current ) && in_array( isset( $current['status'] ) ? (string) $current['status'] : '', array( 'pending', 'running' ), true ) ) {
			return array( 'contract' => 'mad4b.admin-query-performance-job.v1', 'state' => 'already_queued', 'job' => $current, 'production_changed' => false );
		}
		$identity = self::current_build_identity();
		if ( is_wp_error( $identity ) ) return $identity;
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key ) {
			if ( isset( $expected_identity[ $key ] ) && '' !== (string) $expected_identity[ $key ] && ! hash_equals( strtolower( (string) $identity[ $key ] ), strtolower( (string) $expected_identity[ $key ] ) ) ) {
				return new WP_Error( 'mad4b_admin_query_performance_build_changed', 'Exact build identity changed before maintenance job admission.' );
			}
		}
		$job = array(
			'contract' => 'mad4b.admin-query-performance-job.v1',
			'job_id' => strtolower( wp_generate_uuid4() ),
			'status' => 'pending',
			'expected_identity' => $identity,
			'queued_at' => gmdate( 'c' ),
			'started_at' => '',
			'completed_at' => '',
			'result' => array(),
			'production_changed' => false,
		);
		if ( ! update_option( self::JOB_OPTION, $job, false ) ) {
			$stored = self::maintenance_job_status();
			if ( empty( $stored ) || ! hash_equals( (string) $job['job_id'], (string) ( isset( $stored['job_id'] ) ? $stored['job_id'] : '' ) ) ) return new WP_Error( 'mad4b_admin_query_performance_job_persist_failed', 'Unable to persist performance maintenance job.' );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $job['job_id'] ) ) ) wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $job['job_id'] ) );
		return array( 'contract' => 'mad4b.admin-query-performance-job.v1', 'state' => 'queued', 'job' => $job, 'production_changed' => false );
	}

	public static function run_scheduled_apply( $job_id ) {
		$job = self::maintenance_job_status();
		if ( empty( $job ) || ! hash_equals( (string) ( isset( $job['job_id'] ) ? $job['job_id'] : '' ), (string) $job_id ) || 'pending' !== ( isset( $job['status'] ) ? (string) $job['status'] : '' ) ) return;
		if ( 'staging' !== self::environment() ) {
			$job['status'] = 'blocked';
			$job['completed_at'] = gmdate( 'c' );
			$job['result'] = array( 'state' => 'environment_changed' );
			update_option( self::JOB_OPTION, $job, false );
			return;
		}
		$identity = self::current_build_identity();
		$expected = isset( $job['expected_identity'] ) && is_array( $job['expected_identity'] ) ? $job['expected_identity'] : array();
		if ( is_wp_error( $identity ) || ! self::identity_matches( $identity, $expected ) ) {
			$job['status'] = 'blocked';
			$job['completed_at'] = gmdate( 'c' );
			$job['result'] = array( 'state' => 'build_identity_changed' );
			update_option( self::JOB_OPTION, $job, false );
			return;
		}
		$job['status'] = 'running';
		$job['started_at'] = gmdate( 'c' );
		update_option( self::JOB_OPTION, $job, false );
		$result = self::apply_indexes();
		$job['status'] = is_array( $result ) && ! empty( $result['ready'] ) ? 'completed' : 'failed';
		$job['completed_at'] = gmdate( 'c' );
		$job['result'] = is_array( $result ) ? $result : array( 'state' => 'invalid_result' );
		update_option( self::JOB_OPTION, $job, false );
	}

	private static function current_build_identity() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_admin_query_performance_provenance_unavailable', 'Build provenance is unavailable.' );
		$p = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $p ) || empty( $p['manifest_valid'] ) || empty( $p['runtime_manifest_match'] ) || ! empty( $p['stale'] ) ) return new WP_Error( 'mad4b_admin_query_performance_provenance_not_ready', 'Current build provenance is not ready.' );
		return array(
			'source_commit_sha' => strtolower( (string) ( isset( $p['source_commit_sha'] ) ? $p['source_commit_sha'] : '' ) ),
			'build_fingerprint' => strtolower( (string) ( isset( $p['build_fingerprint'] ) ? $p['build_fingerprint'] : '' ) ),
			'package_manifest_digest' => strtolower( (string) ( isset( $p['package_manifest_digest'] ) ? $p['package_manifest_digest'] : '' ) ),
		);
	}

	private static function identity_matches( array $current, array $expected ) {
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key ) {
			if ( empty( $current[ $key ] ) || empty( $expected[ $key ] ) || ! hash_equals( (string) $current[ $key ], (string) $expected[ $key ] ) ) return false;
		}
		return true;
	}

	public static function apply_explicit() {
		if ( 'staging' !== self::environment() ) return array( 'contract' => self::CONTRACT, 'environment' => self::environment(), 'state' => 'not_applicable', 'production_changed' => false );
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_admin_query_performance_admin_required', 'Administrator capability is required to apply MAD4B performance indexes.' );
		return self::apply_indexes();
	}

	public static function maybe_ensure_staging_indexes() {
		if ( self::is_forbidden_automatic_lifecycle_request() ) return array( 'contract' => self::CONTRACT, 'environment' => self::environment(), 'state' => 'lifecycle_protected', 'production_changed' => false );
		if ( 'staging' !== self::environment() ) return array( 'contract' => self::CONTRACT, 'environment' => self::environment(), 'state' => 'not_applicable', 'production_changed' => false );
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return array( 'contract' => self::CONTRACT, 'environment' => 'staging', 'state' => 'admin_required', 'production_changed' => false );
		return self::apply_indexes();
	}

	private static function apply_indexes() {
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && self::INDEX_VERSION === (int) ( isset( $stored['index_version'] ) ? $stored['index_version'] : 0 ) ) {
			if ( ! empty( $stored['ready_after'] ) ) return array( 'contract' => self::CONTRACT, 'environment' => 'staging', 'state' => 'already_applied', 'index_version' => self::INDEX_VERSION, 'production_changed' => false );
			$attempted = ! empty( $stored['applied_at'] ) ? strtotime( (string) $stored['applied_at'] ) : false;
			if ( false !== $attempted && ( time() - $attempted ) < 600 ) return array( 'contract' => self::CONTRACT, 'environment' => 'staging', 'state' => 'retry_deferred', 'index_version' => self::INDEX_VERSION, 'production_changed' => false );
		}

		$before = self::status();
		if ( ! empty( $before['ready'] ) ) {
			$record = array(
				'contract' => 'mad4b.admin-query-performance-apply.v1',
				'index_version' => self::INDEX_VERSION,
				'environment' => 'staging',
				'changed' => false,
				'results' => array(),
				'ready_after' => true,
				'applied_at' => gmdate( 'c' ),
				'production_changed' => false,
			);
			update_option( self::OPTION, $record, false );
			return array_merge( $before, array( 'last_apply' => $record ) );
		}

		$results = array();
		$changed = false;
		foreach ( self::index_specs() as $key => $spec ) {
			if ( self::equivalent_index_present( $spec['table'], $spec['columns'] ) ) {
				$results[ $key ] = array( 'state' => 'already_present', 'changed' => false );
				continue;
			}
			$result = self::create_index( $spec );
			$results[ $key ] = $result;
			if ( ! empty( $result['changed'] ) ) $changed = true;
		}

		$after = self::status();
		$record = array(
			'contract' => 'mad4b.admin-query-performance-apply.v1',
			'index_version' => self::INDEX_VERSION,
			'environment' => self::environment(),
			'changed' => $changed,
			'results' => $results,
			'ready_after' => ! empty( $after['ready'] ),
			'applied_at' => gmdate( 'c' ),
			'production_changed' => false,
		);
		update_option( self::OPTION, $record, false );
		return array_merge( $after, array( 'last_apply' => $record ) );
	}

	private static function is_forbidden_automatic_lifecycle_request() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) return false;
		if ( ! is_admin() ) return true;
		$pagenow = isset( $GLOBALS['pagenow'] ) ? sanitize_key( (string) $GLOBALS['pagenow'] ) : '';
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lifecycle observation only.
		if ( in_array( $pagenow, array( 'update.php', 'update-core.php', 'plugin-install.php', 'plugins.php' ), true ) ) return true;
		if ( in_array( $action, array( 'upload-plugin', 'install-plugin', 'update-plugin', 'activate', 'deactivate', 'delete-selected' ), true ) ) return true;
		return false;
	}

	private static function create_index( array $spec ) {
		global $wpdb;
		$table = $spec['table'];
		$name = $spec['name'];
		$sql = '';
		if ( 'postmeta' === $spec['kind'] ) {
			$sql = "ALTER TABLE `{$table}` ADD INDEX `{$name}` (`post_id`,`meta_key`(191))";
		} elseif ( 'termmeta' === $spec['kind'] ) {
			$sql = "ALTER TABLE `{$table}` ADD INDEX `{$name}` (`term_id`,`meta_key`(191))";
		}
		if ( '' === $sql ) return array( 'state' => 'unsupported_spec', 'changed' => false );
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- trusted table/index identifiers only.
		if ( false === $result ) {
			return array(
				'state' => 'alter_failed',
				'changed' => false,
				'error' => isset( $wpdb->last_error ) ? substr( (string) $wpdb->last_error, 0, 240 ) : '',
			);
		}
		return array( 'state' => 'created', 'changed' => true );
	}

	private static function equivalent_index_present( $table, array $expected ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- trusted core table identifier.
		if ( ! is_array( $rows ) ) return false;
		$keys = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['Key_name'], $row['Column_name'], $row['Seq_in_index'] ) ) continue;
			$key = (string) $row['Key_name'];
			$seq = max( 1, (int) $row['Seq_in_index'] );
			$keys[ $key ][ $seq ] = (string) $row['Column_name'];
		}
		foreach ( $keys as $columns ) {
			ksort( $columns, SORT_NUMERIC );
			$columns = array_values( $columns );
			if ( count( $columns ) < count( $expected ) ) continue;
			if ( array_slice( $columns, 0, count( $expected ) ) === array_values( $expected ) ) return true;
		}
		return false;
	}

	private static function index_specs() {
		global $wpdb;
		return array(
			'postmeta_post_key' => array(
				'kind' => 'postmeta',
				'table' => $wpdb->postmeta,
				'name' => 'mad4b_post_id_meta_key',
				'columns' => array( 'post_id', 'meta_key' ),
			),
			'termmeta_term_key' => array(
				'kind' => 'termmeta',
				'table' => $wpdb->termmeta,
				'name' => 'mad4b_term_id_meta_key',
				'columns' => array( 'term_id', 'meta_key' ),
			),
		);
	}

	private static function environment() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' ) ) {
			return sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
		}
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}
}
