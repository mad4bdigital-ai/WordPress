<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Purpose-specific, read-only Code Snippets diagnostic for REST bootstrap risks.
 *
 * This is deliberately not a generic snippet/code reader. Snippet source is
 * inspected in memory and never returned. Output contains only bounded metadata,
 * code hashes, line numbers, and recognized REST bootstrap patterns.
 */
final class MAD4B_SCP_Code_Snippets_Runtime_Diagnostic {
	const CONTRACT = 'mad4b.code-snippets-rest-bootstrap-diagnostic.v1';
	const ABILITY = 'mad4b/code-snippets-rest-bootstrap-diagnostic';
	const MAX_ACTIVE_SNIPPETS = 500;
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
		wp_register_ability( self::ABILITY, array(
			'label' => 'Inspect Code Snippets REST Bootstrap Risk',
			'description' => 'Read bounded metadata for active Code Snippets entries that invoke or reference WordPress REST bootstrap primitives. Snippet source is never returned.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'execute' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'snippet_id' => array( 'type' => 'integer', 'minimum' => 1 ),
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
		) );
	}

	public static function execute( $input = array() ) {
		global $wpdb;
		$input = is_array( $input ) ? $input : array();
		$snippet_id = isset( $input['snippet_id'] ) ? max( 0, (int) $input['snippet_id'] ) : 0;
		$table = isset( $wpdb->prefix ) ? $wpdb->prefix . 'snippets' : '';
		$base = array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'mutation_performed' => false,
			'raw_sql_surface_exposed' => false,
			'snippet_source_returned' => false,
			'secret_values_returned' => false,
			'table' => $table,
			'table_present' => false,
			'active_snippets_scanned' => 0,
			'matching_snippet_count' => 0,
			'matches' => array(),
			'patterns' => array(
				'do_action(rest_api_init)',
				'rest_get_server',
				'rest_do_request',
				'WP_REST_Server',
				'register_rest_route',
			),
		);
		if ( ! is_object( $wpdb ) || '' === $table || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			$base['state'] = 'database_adapter_unavailable';
			return $base;
		}

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( $found !== $table ) {
			$base['state'] = 'code_snippets_table_missing';
			return $base;
		}
		$base['table_present'] = true;

		if ( $snippet_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT id,name,scope,priority,active,code FROM {$table} WHERE active = 1 AND id = %d LIMIT 1",
				$snippet_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT id,name,scope,priority,active,code FROM {$table} WHERE active = %d ORDER BY priority ASC,id ASC LIMIT %d",
				1,
				self::MAX_ACTIVE_SNIPPETS
			);
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = is_array( $rows ) ? $rows : array();
		$base['active_snippets_scanned'] = count( $rows );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			$code = isset( $row['code'] ) ? (string) $row['code'] : '';
			$inspection = self::inspect_code( $code );
			if ( empty( $inspection['patterns'] ) ) continue;
			$base['matches'][] = array(
				'id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'name' => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
				'scope' => isset( $row['scope'] ) ? sanitize_key( (string) $row['scope'] ) : '',
				'priority' => isset( $row['priority'] ) ? (int) $row['priority'] : 0,
				'active' => ! empty( $row['active'] ),
				'code_sha256' => hash( 'sha256', $code ),
				'code_bytes' => strlen( $code ),
				'patterns' => $inspection['patterns'],
				'risk_flags' => $inspection['risk_flags'],
				'rest_bootstrap_risk' => ! empty( $inspection['rest_bootstrap_risk'] ),
				'snippet_source_returned' => false,
			);
		}
		usort( $base['matches'], static function ( $a, $b ) {
			if ( ! empty( $a['rest_bootstrap_risk'] ) !== ! empty( $b['rest_bootstrap_risk'] ) ) return ! empty( $a['rest_bootstrap_risk'] ) ? -1 : 1;
			if ( (int) $a['priority'] === (int) $b['priority'] ) return (int) $a['id'] <=> (int) $b['id'];
			return (int) $a['priority'] <=> (int) $b['priority'];
		} );
		$base['matching_snippet_count'] = count( $base['matches'] );
		$base['state'] = empty( $base['matches'] ) ? 'no_rest_bootstrap_patterns_detected' : 'rest_bootstrap_patterns_detected';
		$base['ready'] = true;
		return $base;
	}

	/** @internal Pure tokenizer seam for runtime tests. */
	public static function inspect_code_for_test( $code ) {
		return self::inspect_code( (string) $code );
	}

	private static function inspect_code( $code ) {
		$source = "<?php\n" . (string) $code;
		$tokens = token_get_all( $source );
		$significant = array();
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				$id = (int) $token[0];
				if ( in_array( $id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG ), true ) ) continue;
				$significant[] = array(
					'id' => $id,
					'text' => (string) $token[1],
					'line' => max( 1, (int) $token[2] - 1 ),
				);
			} else {
				$significant[] = array( 'id' => 0, 'text' => (string) $token, 'line' => 0 );
			}
		}

		$patterns = array();
		$flags = array();
		$count = count( $significant );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $significant[ $i ];
			$text = isset( $token['text'] ) ? (string) $token['text'] : '';
			$lower = strtolower( $text );
			$line = isset( $token['line'] ) ? max( 1, (int) $token['line'] ) : 1;

			if ( T_STRING === (int) $token['id'] ) {
				if ( 'rest_get_server' === $lower ) {
					$patterns[] = array( 'pattern' => 'rest_get_server', 'line' => $line, 'kind' => 'rest_server_bootstrap' );
					$flags['rest_server_bootstrap'] = true;
				} elseif ( 'rest_do_request' === $lower ) {
					$patterns[] = array( 'pattern' => 'rest_do_request', 'line' => $line, 'kind' => 'rest_internal_request' );
					$flags['rest_internal_request'] = true;
				} elseif ( 'register_rest_route' === $lower ) {
					$patterns[] = array( 'pattern' => 'register_rest_route', 'line' => $line, 'kind' => 'rest_route_registration' );
					$flags['rest_route_registration'] = true;
				} elseif ( 'wp_rest_server' === $lower ) {
					$patterns[] = array( 'pattern' => 'WP_REST_Server', 'line' => $line, 'kind' => 'rest_server_reference' );
					$flags['rest_server_reference'] = true;
				} elseif ( 'do_action' === $lower ) {
					$j = $i + 1;
					if ( $j < $count && '(' === $significant[ $j ]['text'] ) ++$j;
					if ( $j < $count && T_CONSTANT_ENCAPSED_STRING === (int) $significant[ $j ]['id'] ) {
						$hook = trim( (string) $significant[ $j ]['text'], "'\"" );
						if ( 'rest_api_init' === $hook ) {
							$patterns[] = array( 'pattern' => 'do_action(rest_api_init)', 'line' => $line, 'kind' => 'rest_action_replay' );
							$flags['rest_action_replay'] = true;
						}
					}
				}
			}
		}

		$unique = array();
		foreach ( $patterns as $entry ) $unique[ $entry['pattern'] . ':' . $entry['line'] . ':' . $entry['kind'] ] = $entry;
		$patterns = array_values( $unique );
		usort( $patterns, static function ( $a, $b ) {
			if ( $a['line'] === $b['line'] ) return strcmp( $a['pattern'], $b['pattern'] );
			return $a['line'] <=> $b['line'];
		} );
		$risk = ! empty( $flags['rest_action_replay'] ) || ! empty( $flags['rest_server_bootstrap'] ) || ! empty( $flags['rest_internal_request'] );
		return array(
			'patterns' => $patterns,
			'risk_flags' => array_keys( array_filter( $flags ) ),
			'rest_bootstrap_risk' => $risk,
		);
	}
}
