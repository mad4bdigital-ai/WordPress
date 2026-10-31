<?php
/**
 * Read-only file/database inspection and validated SQL WHERE construction.
 * This is a mechanical extraction from MAD4B_SCP_Abilities; the public Ability
 * ABI, registration, permission callbacks and governed write paths remain owned
 * by the original facade. No direct MCP tool or independent authority is added.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Read_Ability_Service {
	public static function filesystem_list( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], isset( $input['path'] ) ? $input['path'] : '', true );
		if ( is_wp_error( $path ) ) return $path;
		if ( ! is_dir( $path ) ) return new WP_Error( 'mad4b_not_directory', 'Requested path is not a directory.' );
		$entries = scandir( $path );
		if ( false === $entries ) return new WP_Error( 'mad4b_list_failed', 'Unable to list directory.' );
		$items = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) continue;
			$full = $path . DIRECTORY_SEPARATOR . $entry;
			if ( MAD4B_SCP_Policy::is_sensitive_path( $full ) ) continue;
			$items[] = array( 'name' => $entry, 'type' => is_dir( $full ) ? 'directory' : ( is_link( $full ) ? 'symlink' : 'file' ), 'size' => is_file( $full ) ? filesize( $full ) : null, 'modified' => file_exists( $full ) ? gmdate( 'c', filemtime( $full ) ) : null );
			if ( count( $items ) >= 500 ) break;
		}
		return array( 'entries' => $items, 'count' => count( $items ) );
	}

	public static function filesystem_read( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], $input['path'], true );
		if ( is_wp_error( $path ) ) return $path;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_not_readable_file', 'Requested path is not a readable file.' );
		$max = isset( $input['max_bytes'] ) ? max( 1, min( 1048576, absint( $input['max_bytes'] ) ) ) : 262144;
		$size = filesize( $path );
		if ( false !== $size && $size > $max ) return new WP_Error( 'mad4b_file_too_large', 'File exceeds max_bytes.', array( 'size' => $size ) );
		$content = file_get_contents( $path );
		if ( false === $content ) return new WP_Error( 'mad4b_read_failed', 'Unable to read file.' );
		if ( false !== strpos( $content, "\0" ) ) return new WP_Error( 'mad4b_binary_file', 'Binary files are not returned.' );
		return array( 'content' => $content, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ) );
	}

	public static function database_list_tables() {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$items = array();
		foreach ( $tables as $table ) $items[] = array( 'name' => $table, 'structured_read_allowed' => MAD4B_SCP_Policy::can_structured_database_read( $table ), 'structured_write_allowed' => MAD4B_SCP_Policy::can_structured_database_write( $table ) );
		return array( 'tables' => $items, 'count' => count( $items ) );
	}

	public static function database_describe_table( $input ) {
		global $wpdb; $table = $input['table'];
		if ( ! MAD4B_SCP_Policy::table_exists( $table ) ) return new WP_Error( 'mad4b_invalid_table', 'Table not visible to WordPress.' );
		$columns = $wpdb->get_results( 'DESCRIBE `' . $table . '`', ARRAY_A );
		foreach ( $columns as &$column ) if ( isset( $column['Field'] ) ) $column['sensitive'] = MAD4B_SCP_Policy::is_sensitive_database_column( $column['Field'] );
		return array( 'table' => $table, 'structured_read_allowed' => MAD4B_SCP_Policy::can_structured_database_read( $table ), 'columns' => $columns );
	}

	public static function database_select( $input ) {
		global $wpdb; $table = $input['table'];
		if ( ! MAD4B_SCP_Policy::can_structured_database_read( $table ) ) return new WP_Error( 'mad4b_sensitive_table_denied', 'Structured reads are denied for this sensitive database table. Use explicitly enabled Breakglass when genuinely required.' );

		$available = array();
		foreach ( $wpdb->get_results( 'DESCRIBE `' . $table . '`', ARRAY_A ) as $definition ) if ( isset( $definition['Field'] ) ) $available[] = $definition['Field'];
		$requested = ! empty( $input['columns'] ) ? $input['columns'] : $available;
		$list = array();
		foreach ( $requested as $column ) {
			if ( ! MAD4B_SCP_Policy::validate_identifier( $column ) || ! in_array( $column, $available, true ) ) return new WP_Error( 'mad4b_invalid_column', 'Invalid or unknown column identifier.' );
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $column ) ) return new WP_Error( 'mad4b_sensitive_column_denied', 'Structured reads of secret/authentication columns are denied.' );
			$list[] = '`' . $column . '`';
		}
		if ( empty( $list ) ) return new WP_Error( 'mad4b_no_safe_columns', 'No non-sensitive columns are available for structured reading.' );
		foreach ( array_keys( isset( $input['where'] ) && is_array( $input['where'] ) ? $input['where'] : array() ) as $column ) {
			if ( ! in_array( $column, $available, true ) ) return new WP_Error( 'mad4b_invalid_column', 'Unknown WHERE column.' );
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $column ) ) return new WP_Error( 'mad4b_sensitive_where_denied', 'Sensitive columns cannot be used in structured WHERE clauses.' );
		}

		$where = self::where_sql( isset( $input['where'] ) && is_array( $input['where'] ) ? $input['where'] : array() );
		if ( is_wp_error( $where ) ) return $where;
		$order = '';
		if ( ! empty( $input['order_by'] ) ) {
			if ( ! preg_match( '/^([A-Za-z0-9_$]+)(?:\s+(ASC|DESC))?$/i', trim( $input['order_by'] ), $m ) ) return new WP_Error( 'mad4b_invalid_order', 'Invalid order_by.' );
			if ( ! in_array( $m[1], $available, true ) || MAD4B_SCP_Policy::is_sensitive_database_column( $m[1] ) ) return new WP_Error( 'mad4b_sensitive_order_denied', 'Invalid or sensitive order_by column.' );
			$order = ' ORDER BY `' . $m[1] . '` ' . ( isset( $m[2] ) ? strtoupper( $m[2] ) : 'ASC' );
		}
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 50;
		$sql = 'SELECT ' . implode( ', ', $list ) . ' FROM `' . $table . '`' . $where . $order . ' LIMIT ' . $limit;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array( 'table' => $table, 'rows' => $rows, 'count' => count( $rows ) );
	}

	public static function where_sql( array $where ) {
		global $wpdb; if ( ! $where ) return '';
		$parts = array();
		foreach ( $where as $column => $value ) {
			if ( ! MAD4B_SCP_Policy::validate_identifier( $column ) ) return new WP_Error( 'mad4b_invalid_column', 'Invalid WHERE column.' );
			if ( null === $value ) $parts[] = '`' . $column . '` IS NULL';
			elseif ( is_scalar( $value ) ) $parts[] = $wpdb->prepare( '`' . $column . '` = %s', (string) $value );
			else return new WP_Error( 'mad4b_invalid_where', 'WHERE values must be scalar or null.' );
		}
		return ' WHERE ' . implode( ' AND ', $parts );
	}
}
