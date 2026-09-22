<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only, bounded runtime evidence for active functional-gap plugin families.
 *
 * This intentionally does not expose a generic filesystem primitive. Roots come
 * exclusively from MAD4B_SCP_Plugin_Discovery and are constrained below
 * WP_PLUGIN_DIR. The default response returns aggregate tree identities only;
 * per-file hashes are opt-in for one named family to keep MCP payloads bounded.
 */
final class MAD4B_SCP_Functional_Gap_Runtime_Diagnostic {
	const CONTRACT = 'mad4b.runtime-functional-gap-diagnostic.v2';
	const ABILITY = 'mad4b/runtime-functional-gap-diagnostic';
	const MAX_FILES_PER_FAMILY = 25000;
	const MAX_BYTES_PER_FAMILY = 1073741824; // 1 GiB safety ceiling.
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
		wp_register_ability( self::ABILITY, array(
			'label' => 'Get Functional Gap Runtime Diagnostic',
			'description' => 'Read exact file-tree identities for active contract-discovery and safety-blocked plugin families without generic filesystem access.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'execute' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'family' => array( 'type' => 'string', 'maxLength' => 120 ),
					'include_files' => array( 'type' => 'boolean' ),
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
		$input = is_array( $input ) ? $input : array();
		$requested_family = isset( $input['family'] ) ? sanitize_key( (string) $input['family'] ) : '';
		$include_files = ! empty( $input['include_files'] );
		if ( $include_files && '' === $requested_family ) {
			return new WP_Error( 'mad4b_functional_gap_family_required', 'include_files requires one exact family to keep diagnostic output bounded.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Plugin_Discovery' ) || ! method_exists( 'MAD4B_SCP_Plugin_Discovery', 'functional_coverage_report' ) ) {
			return new WP_Error( 'mad4b_functional_gap_discovery_unavailable', 'Functional plugin discovery is unavailable.' );
		}

		$coverage = MAD4B_SCP_Plugin_Discovery::functional_coverage_report();
		$families = self::target_families( is_array( $coverage ) ? $coverage : array() );
		if ( '' !== $requested_family ) {
			if ( ! isset( $families[ $requested_family ] ) ) return new WP_Error( 'mad4b_functional_gap_family_not_targeted', 'Requested family is not an active contract-discovery or safety-blocked family.' );
			$families = array( $requested_family => $families[ $requested_family ] );
		}

		$results = array();
		foreach ( $families as $family => $descriptor ) {
			$results[] = self::diagnose_family( $family, $descriptor, $include_files );
		}
		usort( $results, static function ( $a, $b ) { return strcmp( isset( $a['family'] ) ? (string) $a['family'] : '', isset( $b['family'] ) ? (string) $b['family'] : '' ); } );

		$complete = true;
		foreach ( $results as $result ) if ( empty( $result['complete'] ) ) { $complete = false; break; }
		$identity_rows = array();
		foreach ( $results as $result ) {
			$identity_rows[] = array(
				'family' => isset( $result['family'] ) ? (string) $result['family'] : '',
				'state' => isset( $result['functional_state'] ) ? (string) $result['functional_state'] : '',
				'tree_sha256' => isset( $result['tree_sha256'] ) ? (string) $result['tree_sha256'] : '',
				'file_count' => isset( $result['file_count'] ) ? (int) $result['file_count'] : 0,
				'total_bytes' => isset( $result['total_bytes'] ) ? (int) $result['total_bytes'] : 0,
			);
		}
		$report_sha256 = hash( 'sha256', wp_json_encode( $identity_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'mutation_performed' => false,
			'remote_request_performed' => false,
			'secret_values_returned' => false,
			'raw_sql_performed' => false,
			'generic_filesystem_surface_exposed' => false,
			'target_states' => array( 'contract_discovery_required', 'safety_blocked' ),
			'coverage_contract' => isset( $coverage['contract'] ) ? (string) $coverage['contract'] : '',
			'family_count' => count( $results ),
			'complete' => $complete,
			'tree_algorithm' => 'sha256(sorted(relative_path + NUL + size + NUL + file_sha256 + LF))',
			'report_sha256' => $report_sha256,
			'families' => $results,
			'generated_at' => gmdate( 'c' ),
		);
	}

	private static function target_families( array $coverage ) {
		$families = array();
		foreach ( isset( $coverage['items'] ) && is_array( $coverage['items'] ) ? $coverage['items'] : array() as $item ) {
			if ( ! is_array( $item ) || empty( $item['functional_coverage'] ) || ! is_array( $item['functional_coverage'] ) ) continue;
			$state = isset( $item['functional_coverage']['state'] ) ? sanitize_key( (string) $item['functional_coverage']['state'] ) : '';
			if ( ! in_array( $state, array( 'contract_discovery_required', 'safety_blocked' ), true ) ) continue;
			$family = ! empty( $item['functional_family_key'] ) ? sanitize_key( (string) $item['functional_family_key'] ) : sanitize_key( isset( $item['family'] ) ? (string) $item['family'] : '' );
			if ( '' === $family ) continue;
			if ( ! isset( $families[ $family ] ) ) {
				$families[ $family ] = array(
					'family' => $family,
					'functional_state' => $state,
					'plugin_files' => array(),
					'plugin_names' => array(),
				);
			}
			if ( 'safety_blocked' === $state ) $families[ $family ]['functional_state'] = 'safety_blocked';
			$plugin_file = isset( $item['plugin_file'] ) ? self::normalize_plugin_file( (string) $item['plugin_file'] ) : '';
			if ( '' !== $plugin_file ) $families[ $family ]['plugin_files'][] = $plugin_file;
			if ( ! empty( $item['plugin_name'] ) ) $families[ $family ]['plugin_names'][] = sanitize_text_field( (string) $item['plugin_name'] );
		}
		foreach ( $families as $family => $descriptor ) {
			$families[ $family ]['plugin_files'] = array_values( array_unique( $descriptor['plugin_files'] ) );
			sort( $families[ $family ]['plugin_files'], SORT_STRING );
			$families[ $family ]['plugin_names'] = array_values( array_unique( $descriptor['plugin_names'] ) );
			sort( $families[ $family ]['plugin_names'], SORT_STRING );
		}
		ksort( $families, SORT_STRING );
		return $families;
	}

	private static function diagnose_family( $family, array $descriptor, $include_files ) {
		$roots = array();
		$root_errors = array();
		foreach ( isset( $descriptor['plugin_files'] ) && is_array( $descriptor['plugin_files'] ) ? $descriptor['plugin_files'] : array() as $plugin_file ) {
			$root = self::plugin_root( $plugin_file );
			if ( is_wp_error( $root ) ) {
				$root_errors[] = array( 'plugin_file' => $plugin_file, 'error' => $root->get_error_code() );
				continue;
			}
			$roots[ wp_normalize_path( $root ) ] = true;
		}
		$roots = array_keys( $roots );
		sort( $roots, SORT_STRING );

		$manifest = array();
		$total_bytes = 0;
		$truncated = false;
		$symlink_count = 0;
		$read_error_count = 0;
		foreach ( $roots as $root ) {
			$root_manifest = self::tree_manifest( $root );
			if ( is_wp_error( $root_manifest ) ) {
				$root_errors[] = array( 'root' => self::relative_to_plugins( $root ), 'error' => $root_manifest->get_error_code() );
				continue;
			}
			foreach ( $root_manifest['files'] as $entry ) {
				$manifest[] = array(
					'path' => self::relative_to_plugins( $root ) . '/' . $entry['path'],
					'size' => (int) $entry['size'],
					'sha256' => (string) $entry['sha256'],
				);
			}
			$total_bytes += (int) $root_manifest['total_bytes'];
			$symlink_count += isset( $root_manifest['symlink_count'] ) ? (int) $root_manifest['symlink_count'] : 0;
			$read_error_count += isset( $root_manifest['read_error_count'] ) ? (int) $root_manifest['read_error_count'] : 0;
			if ( ! empty( $root_manifest['truncated'] ) ) $truncated = true;
		}
		usort( $manifest, static function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
		$material = '';
		foreach ( $manifest as $entry ) $material .= $entry['path'] . "\0" . $entry['size'] . "\0" . $entry['sha256'] . "\n";
		$tree_sha256 = hash( 'sha256', $material );

		$result = array(
			'family' => sanitize_key( (string) $family ),
			'functional_state' => isset( $descriptor['functional_state'] ) ? sanitize_key( (string) $descriptor['functional_state'] ) : '',
			'plugin_names' => isset( $descriptor['plugin_names'] ) ? array_values( $descriptor['plugin_names'] ) : array(),
			'plugin_files' => isset( $descriptor['plugin_files'] ) ? array_values( $descriptor['plugin_files'] ) : array(),
			'roots' => array_map( array( __CLASS__, 'relative_to_plugins' ), $roots ),
			'file_count' => count( $manifest ),
			'total_bytes' => $total_bytes,
			'tree_sha256' => $tree_sha256,
			'complete' => ! $truncated && 0 === $symlink_count && 0 === $read_error_count && empty( $root_errors ) && ! empty( $manifest ),
			'truncated' => $truncated,
			'symlink_count' => $symlink_count,
			'read_error_count' => $read_error_count,
			'errors' => $root_errors,
		);
		if ( $include_files ) $result['files'] = $manifest;
		return $result;
	}

	private static function plugin_root( $plugin_file ) {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) return new WP_Error( 'mad4b_functional_gap_plugin_dir_unavailable', 'WP_PLUGIN_DIR is unavailable.' );
		$plugin_file = self::normalize_plugin_file( $plugin_file );
		if ( '' === $plugin_file || false !== strpos( $plugin_file, '../' ) || 0 === strpos( $plugin_file, '/' ) ) return new WP_Error( 'mad4b_functional_gap_plugin_file_invalid', 'Plugin file is outside the bounded plugin namespace.' );
		if ( false === strpos( $plugin_file, '/' ) ) return new WP_Error( 'mad4b_functional_gap_top_level_plugin_unbounded', 'Top-level plugin files are not scanned because their directory root would include unrelated plugins.' );
		$plugins_root = realpath( WP_PLUGIN_DIR );
		if ( false === $plugins_root ) return new WP_Error( 'mad4b_functional_gap_plugin_dir_missing', 'WordPress plugin root is unavailable.' );
		$candidate = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;
		$root_candidate = dirname( $candidate );
		$root = realpath( $root_candidate );
		if ( false === $root || ! is_dir( $root ) ) {
			$file = realpath( $candidate );
			if ( false === $file || ! is_file( $file ) ) return new WP_Error( 'mad4b_functional_gap_plugin_root_missing', 'Plugin runtime root is unavailable.' );
			$root = dirname( $file );
		}
		$plugins_prefix = trailingslashit( wp_normalize_path( $plugins_root ) );
		$root_normalized = trailingslashit( wp_normalize_path( $root ) );
		if ( 0 !== strpos( $root_normalized, $plugins_prefix ) ) return new WP_Error( 'mad4b_functional_gap_plugin_root_escape', 'Plugin runtime root escaped WP_PLUGIN_DIR.' );
		return $root;
	}

	private static function tree_manifest( $root ) {
		$files = array();
		$total_bytes = 0;
		$symlink_count = 0;
		$read_error_count = 0;
		$root = realpath( $root );
		if ( false === $root || ! is_dir( $root ) ) return new WP_Error( 'mad4b_functional_gap_root_unavailable', 'Runtime family root is unavailable.' );
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof SplFileInfo ) continue;
				if ( $file->isLink() ) { ++$symlink_count; continue; }
				if ( ! $file->isFile() ) continue;
				if ( count( $files ) >= self::MAX_FILES_PER_FAMILY ) return array( 'files' => $files, 'total_bytes' => $total_bytes, 'truncated' => true, 'symlink_count' => $symlink_count, 'read_error_count' => $read_error_count );
				$size = max( 0, (int) $file->getSize() );
				if ( $total_bytes + $size > self::MAX_BYTES_PER_FAMILY ) return array( 'files' => $files, 'total_bytes' => $total_bytes, 'truncated' => true, 'symlink_count' => $symlink_count, 'read_error_count' => $read_error_count );
				$path = $file->getRealPath();
				if ( false === $path || ! is_readable( $path ) ) { ++$read_error_count; continue; }
				$sha = hash_file( 'sha256', $path );
				if ( false === $sha ) { ++$read_error_count; continue; }
				$relative = ltrim( substr( wp_normalize_path( $path ), strlen( trailingslashit( wp_normalize_path( $root ) ) ) ), '/' );
				$files[] = array( 'path' => $relative, 'size' => $size, 'sha256' => strtolower( $sha ) );
				$total_bytes += $size;
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'mad4b_functional_gap_tree_scan_failed', 'Bounded runtime tree scan failed.' );
		}
		usort( $files, static function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
		return array( 'files' => $files, 'total_bytes' => $total_bytes, 'truncated' => false, 'symlink_count' => $symlink_count, 'read_error_count' => $read_error_count );
	}

	public static function relative_to_plugins( $path ) {
		$root = defined( 'WP_PLUGIN_DIR' ) ? trailingslashit( wp_normalize_path( (string) WP_PLUGIN_DIR ) ) : '';
		$path = wp_normalize_path( (string) $path );
		return '' !== $root && 0 === strpos( trailingslashit( $path ), $root ) ? trim( substr( $path, strlen( $root ) ), '/' ) : basename( $path );
	}

	private static function normalize_plugin_file( $plugin_file ) {
		return ltrim( str_replace( '\\', '/', sanitize_text_field( (string) $plugin_file ) ), '/' );
	}
}
