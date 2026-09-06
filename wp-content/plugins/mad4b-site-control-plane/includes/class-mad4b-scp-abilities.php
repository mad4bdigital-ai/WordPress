<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Abilities {
	public function register_categories() {
		foreach (
			array(
				'mad4b-read'       => array( 'label' => 'MAD4B Read', 'description' => 'Read-only discovery and diagnostics.' ),
				'mad4b-content'    => array( 'label' => 'MAD4B Content', 'description' => 'Governed content editing.' ),
				'mad4b-admin'      => array( 'label' => 'MAD4B Admin', 'description' => 'Administrative repair abilities.' ),
				'mad4b-breakglass' => array( 'label' => 'MAD4B Breakglass', 'description' => 'Exceptional recovery abilities.' ),
			) as $slug => $args
		) {
			wp_register_ability_category( $slug, $args );
		}
	}

	public function register_abilities() {
		$this->add( 'mad4b/site-info', 'Get Site Info', 'mad4b-read', 'site_info', 'read', null, true, true, false, true );
		$this->add( 'mad4b/list-post-types', 'List Post Types', 'mad4b-read', 'list_post_types', 'read', null, true, true, false, true );
		$this->add( 'mad4b/list-plugins', 'List Plugins', 'mad4b-read', 'list_plugins', 'read', null, true, true, false, true );
		$this->add( 'mad4b/abilities-inventory', 'Abilities Inventory', 'mad4b-read', 'abilities_inventory', 'read', null, true, true, false, true );
		$this->add( 'mad4b/filesystem-list', 'List Files', 'mad4b-read', 'filesystem_list', 'read', $this->schema(
			array(
				'root' => array( 'type' => 'string', 'enum' => $this->roots() ),
				'path' => array( 'type' => 'string', 'default' => '' ),
			), array( 'root' )
		), true, true, false, true );
		$this->add( 'mad4b/filesystem-read', 'Read Text File', 'mad4b-read', 'filesystem_read', 'read', $this->schema(
			array(
				'root' => array( 'type' => 'string', 'enum' => $this->roots() ),
				'path' => array( 'type' => 'string', 'minLength' => 1 ),
				'max_bytes' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1048576, 'default' => 262144 ),
			), array( 'root', 'path' )
		), true, true, false, true );
		$this->add( 'mad4b/database-list-tables', 'List Database Tables', 'mad4b-read', 'database_list_tables', 'read', null, true, true, false, true );
		$this->add( 'mad4b/database-describe-table', 'Describe Database Table', 'mad4b-read', 'database_describe_table', 'read', $this->schema(
			array( 'table' => array( 'type' => 'string', 'minLength' => 1 ) ), array( 'table' )
		), true, true, false, true );
		$this->add( 'mad4b/database-select', 'Select Database Rows', 'mad4b-read', 'database_select', 'read', $this->schema(
			array(
				'table' => array( 'type' => 'string', 'minLength' => 1 ),
				'columns' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'default' => array() ),
				'where' => array( 'type' => 'object', 'default' => array() ),
				'order_by' => array( 'type' => 'string', 'default' => '' ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50 ),
			), array( 'table' )
		), true, true, false, true );
		$this->add( 'mad4b/diagnostics-health', 'Diagnostics Health', 'mad4b-read', 'diagnostics_health', 'read', null, true, true, false, true );

		$this->add( 'mad4b/content-get-post', 'Get Post', 'mad4b-content', 'content_get_post', 'read_post', $this->post_schema(), false, true, false, true );
		$this->add( 'mad4b/content-update-post', 'Update Post', 'mad4b-content', 'content_update_post', 'edit_post', $this->schema(
			array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'post_title' => array( 'type' => 'string' ),
				'post_content' => array( 'type' => 'string' ),
				'post_excerpt' => array( 'type' => 'string' ),
				'post_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ),
			), array( 'post_id' )
		), false, false, false, true );

		$this->add( 'mad4b/plugin-activate', 'Activate Plugin', 'mad4b-admin', 'plugin_activate', 'admin', $this->plugin_schema(), false, false, false, true );
		$this->add( 'mad4b/plugin-deactivate', 'Deactivate Plugin', 'mad4b-admin', 'plugin_deactivate', 'admin', $this->plugin_schema(), false, false, true, true );
		$this->add( 'mad4b/filesystem-write', 'Write Text File', 'mad4b-admin', 'filesystem_write', 'admin', $this->schema(
			array(
				'root' => array( 'type' => 'string', 'enum' => $this->roots() ),
				'path' => array( 'type' => 'string', 'minLength' => 1 ),
				'content' => array( 'type' => 'string' ),
				'expected_sha256' => array( 'type' => 'string', 'default' => '' ),
				'allow_create' => array( 'type' => 'boolean', 'default' => false ),
				'create_backup' => array( 'type' => 'boolean', 'default' => true ),
			), array( 'root', 'path', 'content' )
		), false, false, true, true );
		$this->add( 'mad4b/filesystem-patch', 'Patch Text File', 'mad4b-admin', 'filesystem_patch', 'admin', $this->schema(
			array(
				'root' => array( 'type' => 'string', 'enum' => $this->roots() ),
				'path' => array( 'type' => 'string', 'minLength' => 1 ),
				'search' => array( 'type' => 'string', 'minLength' => 1 ),
				'replace' => array( 'type' => 'string' ),
				'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'max_replacements' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 1 ),
				'create_backup' => array( 'type' => 'boolean', 'default' => true ),
			), array( 'root', 'path', 'search', 'replace', 'expected_sha256' )
		), false, false, true, true );
		$this->add( 'mad4b/database-update', 'Update Database Rows', 'mad4b-admin', 'database_update', 'admin', $this->schema(
			array(
				'table' => array( 'type' => 'string', 'minLength' => 1 ),
				'data' => array( 'type' => 'object' ),
				'where' => array( 'type' => 'object' ),
				'max_affected' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 1 ),
			), array( 'table', 'data', 'where' )
		), false, false, true, true );
		$this->add( 'mad4b/audit-tail', 'Read Audit Trail', 'mad4b-admin', 'audit_tail', 'admin', $this->schema(
			array( 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ) )
		), false, true, false, true );

		$this->add( 'mad4b/database-raw-query', 'Raw Database Query', 'mad4b-breakglass', 'database_raw_query', 'breakglass', $this->schema(
			array(
				'sql' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 50000 ),
				'max_rows' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			), array( 'sql', 'reason' )
		), false, false, true, false );

		do_action( 'mad4b_scp_abilities_registered', $this );
	}

	private function add( $name, $label, $category, $method, $permission, $input, $mcp_public, $readonly, $destructive, $idempotent ) {
		$args = array(
			'label' => $label,
			'description' => $label . ' through the governed MAD4B Site Control Plane.',
			'category' => $category,
			'execute_callback' => array( $this, $method ),
			'permission_callback' => $this->permission_callback( $permission ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => (bool) $mcp_public, 'type' => 'tool' ),
				'annotations' => array(
					'readonly' => (bool) $readonly,
					'destructive' => (bool) $destructive,
					'idempotent' => (bool) $idempotent,
				),
			),
		);
		if ( is_array( $input ) ) {
			$args['input_schema'] = $input;
		}
		wp_register_ability( $name, $args );
	}

	private function permission_callback( $permission ) {
		if ( 'read' === $permission ) return array( 'MAD4B_SCP_Policy', 'can_read' );
		if ( 'admin' === $permission ) return array( 'MAD4B_SCP_Policy', 'can_admin' );
		if ( 'breakglass' === $permission ) return array( 'MAD4B_SCP_Policy', 'can_breakglass' );
		if ( 'read_post' === $permission ) return array( $this, 'can_read_post' );
		if ( 'edit_post' === $permission ) return array( $this, 'can_edit_post' );
		return array( 'MAD4B_SCP_Policy', 'can_content' );
	}

	private function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}

	private function roots() { return array( 'wordpress', 'content', 'plugins', 'themes', 'uploads' ); }
	private function post_schema() { return $this->schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'post_id' ) ); }
	private function plugin_schema() { return $this->schema( array( 'plugin' => array( 'type' => 'string', 'minLength' => 1 ) ), array( 'plugin' ) ); }

	public function site_info() {
		global $wpdb, $wp_version;
		return array( 'site' => array(
			'wordpress_version' => $wp_version,
			'php_version' => PHP_VERSION,
			'db_server_info' => method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : $wpdb->db_version(),
			'site_url' => site_url(), 'home_url' => home_url(), 'is_multisite' => is_multisite(),
			'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
			'control_plane' => MAD4B_SCP_VERSION,
			'mcp_adapter' => class_exists( 'WP\\MCP\\Core\\McpAdapter' ),
			'breakglass' => MAD4B_SCP_Policy::can_breakglass(),
		) );
	}

	public function list_post_types() {
		$items = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			$items[] = array( 'name' => $name, 'label' => $object->label, 'public' => (bool) $object->public, 'show_ui' => (bool) $object->show_ui, 'show_in_rest' => (bool) $object->show_in_rest, 'hierarchical' => (bool) $object->hierarchical );
		}
		return array( 'post_types' => $items, 'count' => count( $items ) );
	}

	public function list_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$items = array();
		foreach ( get_plugins() as $file => $data ) {
			$name = isset( $data['Name'] ) ? $data['Name'] : $file;
			$items[] = array( 'file' => $file, 'name' => $name, 'version' => isset( $data['Version'] ) ? $data['Version'] : '', 'active' => is_plugin_active( $file ), 'network_active' => is_multisite() ? is_plugin_active_for_network( $file ) : false, 'adapter_namespace' => $this->suggest_adapter_namespace( $name . ' ' . $file ) );
		}
		return array( 'plugins' => $items, 'count' => count( $items ) );
	}

	private function suggest_adapter_namespace( $text ) {
		$text = strtolower( $text );
		$map = array( 'elementor' => 'elementor', 'jetengine' => 'jetengine', 'jet engine' => 'jetengine', 'jetsmartfilters' => 'jetsmartfilters', 'jet smart filters' => 'jetsmartfilters', 'bit integrations' => 'bitflows', 'bit flow' => 'bitflows', 'woocommerce' => 'woocommerce', 'rank math' => 'seo', 'seopress' => 'seo', 'yoast' => 'seo', 'polylang' => 'polylang', 'litespeed' => 'cache' );
		foreach ( $map as $needle => $namespace ) if ( false !== strpos( $text, $needle ) ) return $namespace;
		return 'unmapped';
	}

	public function abilities_inventory() {
		$items = array();
		foreach ( function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array() as $name => $ability ) {
			$items[] = array( 'name' => $name, 'label' => method_exists( $ability, 'get_label' ) ? $ability->get_label() : '', 'description' => method_exists( $ability, 'get_description' ) ? $ability->get_description() : '', 'category' => method_exists( $ability, 'get_category' ) ? $ability->get_category() : '' );
		}
		return array( 'abilities' => $items, 'count' => count( $items ) );
	}

	public function filesystem_list( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], isset( $input['path'] ) ? $input['path'] : '', true );
		if ( is_wp_error( $path ) ) return $path;
		if ( ! is_dir( $path ) ) return new WP_Error( 'mad4b_not_directory', 'Requested path is not a directory.' );
		$entries = scandir( $path );
		if ( false === $entries ) return new WP_Error( 'mad4b_list_failed', 'Unable to list directory.' );
		$items = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) continue;
			$full = $path . DIRECTORY_SEPARATOR . $entry;
			$items[] = array( 'name' => $entry, 'type' => is_dir( $full ) ? 'directory' : ( is_link( $full ) ? 'symlink' : 'file' ), 'size' => is_file( $full ) ? filesize( $full ) : null, 'modified' => file_exists( $full ) ? gmdate( 'c', filemtime( $full ) ) : null );
			if ( count( $items ) >= 500 ) break;
		}
		return array( 'entries' => $items, 'count' => count( $items ) );
	}

	public function filesystem_read( $input ) {
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

	public function database_list_tables() { global $wpdb; $tables = $wpdb->get_col( 'SHOW TABLES' ); return array( 'tables' => array_values( $tables ), 'count' => count( $tables ) ); }

	public function database_describe_table( $input ) {
		global $wpdb; $table = $input['table'];
		if ( ! MAD4B_SCP_Policy::table_exists( $table ) ) return new WP_Error( 'mad4b_invalid_table', 'Table not visible to WordPress.' );
		return array( 'table' => $table, 'columns' => $wpdb->get_results( 'DESCRIBE `' . $table . '`', ARRAY_A ) );
	}

	public function database_select( $input ) {
		global $wpdb; $table = $input['table'];
		if ( ! MAD4B_SCP_Policy::table_exists( $table ) ) return new WP_Error( 'mad4b_invalid_table', 'Table not visible to WordPress.' );
		$columns = '*';
		if ( ! empty( $input['columns'] ) ) {
			$list = array();
			foreach ( $input['columns'] as $column ) { if ( ! MAD4B_SCP_Policy::validate_identifier( $column ) ) return new WP_Error( 'mad4b_invalid_column', 'Invalid column identifier.' ); $list[] = '`' . $column . '`'; }
			$columns = implode( ', ', $list );
		}
		$where = $this->where_sql( isset( $input['where'] ) && is_array( $input['where'] ) ? $input['where'] : array() );
		if ( is_wp_error( $where ) ) return $where;
		$order = '';
		if ( ! empty( $input['order_by'] ) ) { if ( ! preg_match( '/^([A-Za-z0-9_$]+)(?:\s+(ASC|DESC))?$/i', trim( $input['order_by'] ), $m ) ) return new WP_Error( 'mad4b_invalid_order', 'Invalid order_by.' ); $order = ' ORDER BY `' . $m[1] . '` ' . ( isset( $m[2] ) ? strtoupper( $m[2] ) : 'ASC' ); }
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 50;
		$sql = 'SELECT ' . $columns . ' FROM `' . $table . '`' . $where . $order . ' LIMIT ' . $limit;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array( 'table' => $table, 'rows' => $rows, 'count' => count( $rows ) );
	}

	private function where_sql( array $where ) {
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

	public function diagnostics_health() {
		global $wpdb; $uploads = wp_upload_dir( null, false );
		return array( 'status' => 'ok', 'checks' => array( 'database' => '1' === (string) $wpdb->get_var( 'SELECT 1' ), 'abilities_api' => function_exists( 'wp_register_ability' ), 'mcp_adapter' => class_exists( 'WP\\MCP\\Core\\McpAdapter' ), 'wp_content_write' => is_writable( WP_CONTENT_DIR ), 'plugins_write' => is_writable( WP_PLUGIN_DIR ), 'uploads_write' => isset( $uploads['basedir'] ) ? is_writable( $uploads['basedir'] ) : false, 'breakglass_enabled' => MAD4B_SCP_Policy::can_breakglass() ) );
	}

	public function can_read_post( $input ) { $id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }

	public function content_get_post( $input ) {
		$post = get_post( absint( $input['post_id'] ) ); if ( ! $post ) return new WP_Error( 'mad4b_post_missing', 'Post not found.' );
		return array( 'post' => array( 'ID' => $post->ID, 'post_type' => $post->post_type, 'post_status' => $post->post_status, 'post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_excerpt' => $post->post_excerpt, 'post_parent' => $post->post_parent, 'modified_gmt' => $post->post_modified_gmt, 'permalink' => get_permalink( $post ) ) );
	}

	public function content_update_post( $input ) {
		$id = absint( $input['post_id'] ); $update = array( 'ID' => $id );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status' ) as $field ) if ( array_key_exists( $field, $input ) ) $update[ $field ] = $input[ $field ];
		if ( isset( $update['post_status'] ) && 'publish' === $update['post_status'] ) { $post = get_post( $id ); $type = $post ? get_post_type_object( $post->post_type ) : null; $cap = $type && isset( $type->cap->publish_posts ) ? $type->cap->publish_posts : 'publish_posts'; if ( ! current_user_can( $cap ) ) return new WP_Error( 'mad4b_cannot_publish', 'Current user cannot publish this post type.' ); }
		$result = wp_update_post( wp_slash( $update ), true ); if ( is_wp_error( $result ) ) return $result;
		MAD4B_SCP_Audit::record( 'mad4b/content-update-post', array( 'post_id' => $id, 'fields' => implode( ',', array_keys( $update ) ) ) );
		return array( 'post_id' => $result, 'updated' => true );
	}

	public function plugin_activate( $input ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php'; $plugin = sanitize_text_field( $input['plugin'] ); $plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) return new WP_Error( 'mad4b_plugin_missing', 'Plugin is not installed.' );
		$result = activate_plugin( $plugin ); if ( is_wp_error( $result ) ) return $result;
		MAD4B_SCP_Audit::record( 'mad4b/plugin-activate', array( 'plugin' => $plugin ) ); return array( 'plugin' => $plugin, 'active' => is_plugin_active( $plugin ) );
	}

	public function plugin_deactivate( $input ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php'; $plugin = sanitize_text_field( $input['plugin'] ); $plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) return new WP_Error( 'mad4b_plugin_missing', 'Plugin is not installed.' );
		$network = is_multisite() && is_plugin_active_for_network( $plugin ); deactivate_plugins( $plugin, false, $network );
		MAD4B_SCP_Audit::record( 'mad4b/plugin-deactivate', array( 'plugin' => $plugin ) ); return array( 'plugin' => $plugin, 'active' => is_plugin_active( $plugin ) );
	}

	public function filesystem_write( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], $input['path'], false ); if ( is_wp_error( $path ) ) return $path;
		$content = (string) $input['content']; if ( false !== strpos( $content, "\0" ) ) return new WP_Error( 'mad4b_binary_write_denied', 'NUL bytes are not allowed.' );
		$exists = file_exists( $path ); $expected = isset( $input['expected_sha256'] ) ? strtolower( trim( $input['expected_sha256'] ) ) : '';
		if ( $exists ) { if ( ! is_file( $path ) ) return new WP_Error( 'mad4b_not_file', 'Target is not a regular file.' ); $current = hash_file( 'sha256', $path ); if ( ! $expected || ! hash_equals( $current, $expected ) ) return new WP_Error( 'mad4b_stale_file', 'Current SHA-256 is required.', array( 'current_sha256' => $current ) ); }
		elseif ( empty( $input['allow_create'] ) ) return new WP_Error( 'mad4b_create_not_allowed', 'Set allow_create=true to create a new file.' );
		$result = $this->atomic_write( $path, $content, ! isset( $input['create_backup'] ) || (bool) $input['create_backup'] ); if ( is_wp_error( $result ) ) return $result;
		MAD4B_SCP_Audit::record( 'mad4b/filesystem-write', array( 'root' => $input['root'], 'path' => $input['path'], 'sha256' => $result['sha256'], 'create' => ! $exists ) ); return $result;
	}

	public function filesystem_patch( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], $input['path'], true ); if ( is_wp_error( $path ) ) return $path;
		$content = is_file( $path ) ? file_get_contents( $path ) : false; if ( false === $content ) return new WP_Error( 'mad4b_read_failed', 'Unable to read target.' );
		$current = hash( 'sha256', $content ); if ( ! hash_equals( $current, strtolower( trim( $input['expected_sha256'] ) ) ) ) return new WP_Error( 'mad4b_stale_file', 'Target SHA-256 no longer matches.', array( 'current_sha256' => $current ) );
		$count = substr_count( $content, (string) $input['search'] ); $max = isset( $input['max_replacements'] ) ? max( 1, min( 100, absint( $input['max_replacements'] ) ) ) : 1;
		if ( 0 === $count ) return new WP_Error( 'mad4b_patch_not_found', 'Search text not found.' ); if ( $count > $max ) return new WP_Error( 'mad4b_patch_too_broad', 'Search matches exceed max_replacements.', array( 'matches' => $count ) );
		$patched = str_replace( (string) $input['search'], (string) $input['replace'], $content, $replacements ); $result = $this->atomic_write( $path, $patched, ! isset( $input['create_backup'] ) || (bool) $input['create_backup'] ); if ( is_wp_error( $result ) ) return $result;
		$result['replacements'] = $replacements; MAD4B_SCP_Audit::record( 'mad4b/filesystem-patch', array( 'root' => $input['root'], 'path' => $input['path'], 'replacements' => $replacements, 'sha256' => $result['sha256'] ) ); return $result;
	}

	private function atomic_write( $path, $content, $backup ) {
		$dir = dirname( $path ); if ( ! is_writable( $dir ) ) return new WP_Error( 'mad4b_directory_not_writable', 'Target directory is not writable.' );
		$backup_path = ''; if ( $backup && file_exists( $path ) ) { $backup_path = $path . '.mad4b-backup-' . gmdate( 'YmdHis' ); if ( ! copy( $path, $backup_path ) ) return new WP_Error( 'mad4b_backup_failed', 'Unable to create backup.' ); }
		$temp = tempnam( $dir, '.mad4b-' ); if ( false === $temp ) return new WP_Error( 'mad4b_temp_failed', 'Unable to create temporary file.' );
		$bytes = file_put_contents( $temp, $content, LOCK_EX ); if ( false === $bytes ) { @unlink( $temp ); return new WP_Error( 'mad4b_write_failed', 'Unable to write temporary file.' ); }
		if ( ! rename( $temp, $path ) ) { @unlink( $temp ); return new WP_Error( 'mad4b_replace_failed', 'Unable to atomically replace target.' ); }
		return array( 'written' => true, 'bytes' => $bytes, 'sha256' => hash( 'sha256', $content ), 'backup_path' => $backup_path ? basename( $backup_path ) : '' );
	}

	public function database_update( $input ) {
		global $wpdb; $table = $input['table']; if ( ! MAD4B_SCP_Policy::table_exists( $table ) ) return new WP_Error( 'mad4b_invalid_table', 'Table not visible to WordPress.' );
		if ( empty( $input['data'] ) || ! is_array( $input['data'] ) ) return new WP_Error( 'mad4b_empty_data', 'data must be non-empty.' ); if ( empty( $input['where'] ) || ! is_array( $input['where'] ) ) return new WP_Error( 'mad4b_where_required', 'A non-empty where object is required.' );
		foreach ( array_keys( $input['data'] ) as $column ) if ( ! MAD4B_SCP_Policy::validate_identifier( $column ) ) return new WP_Error( 'mad4b_invalid_column', 'Invalid data column.' );
		$where = $this->where_sql( $input['where'] ); if ( is_wp_error( $where ) ) return $where; $max = isset( $input['max_affected'] ) ? max( 1, min( 100, absint( $input['max_affected'] ) ) ) : 1;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' . $where ); if ( $count > $max ) return new WP_Error( 'mad4b_update_too_broad', 'Preflight count exceeds max_affected.', array( 'matched_rows' => $count ) );
		$result = $wpdb->update( $table, $input['data'], $input['where'] ); if ( false === $result ) return new WP_Error( 'mad4b_update_failed', $wpdb->last_error ? $wpdb->last_error : 'Database update failed.' );
		MAD4B_SCP_Audit::record( 'mad4b/database-update', array( 'table' => $table, 'affected' => $result, 'matched' => $count ) ); return array( 'table' => $table, 'matched_rows' => $count, 'affected_rows' => (int) $result );
	}

	public function audit_tail( $input ) { $items = MAD4B_SCP_Audit::tail( isset( $input['limit'] ) ? absint( $input['limit'] ) : 50 ); return array( 'entries' => $items, 'count' => count( $items ) ); }

	public function database_raw_query( $input ) {
		global $wpdb; $sql = trim( (string) $input['sql'] ); $reason = sanitize_text_field( (string) $input['reason'] ); $sql = preg_replace( '/;\s*$/', '', $sql );
		if ( false !== strpos( $sql, ';' ) ) return new WP_Error( 'mad4b_multi_statement_denied', 'Only one SQL statement is allowed.' );
		if ( preg_match( '/\b(GRANT|REVOKE|CREATE\s+USER|DROP\s+USER|SET\s+PASSWORD|LOAD\s+DATA|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $sql ) ) return new WP_Error( 'mad4b_sql_hard_denied', 'SQL operation is hard-denied.' );
		if ( ! preg_match( '/^\s*([A-Za-z]+)/', $sql, $m ) ) return new WP_Error( 'mad4b_sql_unclassified', 'Unable to classify SQL.' );
		$verb = strtoupper( $m[1] ); $read = array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' ); $write = array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' ); $ddl = array( 'ALTER', 'CREATE', 'DROP', 'TRUNCATE', 'RENAME' );
		if ( in_array( $verb, $write, true ) && ( ! defined( 'MAD4B_MCP_BREAKGLASS_WRITE_SQL_ENABLED' ) || true !== MAD4B_MCP_BREAKGLASS_WRITE_SQL_ENABLED ) ) return new WP_Error( 'mad4b_sql_write_disabled', 'Raw SQL writes are disabled.' );
		if ( in_array( $verb, $ddl, true ) && ( ! defined( 'MAD4B_MCP_BREAKGLASS_DDL_ENABLED' ) || true !== MAD4B_MCP_BREAKGLASS_DDL_ENABLED ) ) return new WP_Error( 'mad4b_sql_ddl_disabled', 'DDL is disabled.' );
		if ( ! in_array( $verb, array_merge( $read, $write, $ddl ), true ) ) return new WP_Error( 'mad4b_sql_verb_denied', 'SQL verb is not allowed.' );
		$hash = hash( 'sha256', $sql ); MAD4B_SCP_Audit::record( 'mad4b/database-raw-query', array( 'verb' => $verb, 'query_hash' => $hash, 'reason' => $reason ), 'attempt' );
		if ( in_array( $verb, $read, true ) ) { $rows = $wpdb->get_results( $sql, ARRAY_A ); if ( null === $rows && $wpdb->last_error ) return new WP_Error( 'mad4b_raw_query_failed', $wpdb->last_error ); $max = isset( $input['max_rows'] ) ? max( 1, min( 500, absint( $input['max_rows'] ) ) ) : 100; $rows = array_slice( (array) $rows, 0, $max ); MAD4B_SCP_Audit::record( 'mad4b/database-raw-query', array( 'verb' => $verb, 'query_hash' => $hash, 'rows' => count( $rows ) ) ); return array( 'verb' => $verb, 'query_hash' => $hash, 'rows' => $rows, 'count' => count( $rows ) ); }
		$result = $wpdb->query( $sql ); if ( false === $result ) return new WP_Error( 'mad4b_raw_query_failed', $wpdb->last_error ? $wpdb->last_error : 'Raw query failed.' );
		MAD4B_SCP_Audit::record( 'mad4b/database-raw-query', array( 'verb' => $verb, 'query_hash' => $hash, 'affected' => $result ) ); return array( 'verb' => $verb, 'query_hash' => $hash, 'affected_rows' => (int) $result );
	}
}
