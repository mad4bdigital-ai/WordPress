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
	const INDEX_VERSION = 1;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_status_ability' ), 36 );
		add_action( 'admin_post_mad4b_apply_admin_query_indexes', array( __CLASS__, 'handle_explicit_apply' ) );
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
			'index_version' => self::INDEX_VERSION,
			'ready' => $ready,
			'indexes' => $indexes,
			'last_apply' => is_array( $stored ) ? $stored : array(),
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

	public static function maybe_ensure_staging_indexes() {
		if ( self::is_forbidden_automatic_lifecycle_request() ) return array( 'contract' => self::CONTRACT, 'environment' => self::environment(), 'state' => 'lifecycle_protected', 'production_changed' => false );
		if ( 'staging' !== self::environment() ) return array( 'contract' => self::CONTRACT, 'environment' => self::environment(), 'state' => 'not_applicable', 'production_changed' => false );
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return array( 'contract' => self::CONTRACT, 'environment' => 'staging', 'state' => 'admin_required', 'production_changed' => false );

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
