<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Out-of-webroot Developer Workspace and exact Staging promotion lane.
 *
 * Source is authored outside WordPress executable roots, exact-manifest bound,
 * PHP-linted, packaged locally, backed up, installed through Plugin_Upgrader and
 * read back byte-for-byte. Production and Developer Breakglass are excluded.
 */
final class MAD4B_SCP_Developer_Workspace {
	const CONTRACT = 'mad4b.developer-workspace.v1';
	const PROMOTION_CONTRACT = 'mad4b.developer-workspace-promotion.v1';
	const MAX_FILES = 1000;
	const MAX_PROJECT_BYTES = 16777216;
	const MAX_FILE_BYTES = 1048576;
	const MAX_BATCH_BYTES = 1048576;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 19 );
	}

	public static function tool_names() {
		return array(
			'mad4b/developer-workspace-status',
			'mad4b/developer-workspace-read',
			'mad4b/developer-workspace-apply',
			'mad4b/developer-workspace-promote',
		);
	}

	public static function register_abilities() {
		self::add(
			'mad4b/developer-workspace-status',
			'Developer Workspace Status',
			'status',
			true,
			self::schema(
				array(
					'project_slug' => self::project_slug_schema( false ),
				)
			)
		);
		self::add(
			'mad4b/developer-workspace-read',
			'Developer Workspace Read',
			'read',
			true,
			self::schema(
				array(
					'project_slug' => self::project_slug_schema(),
					'path' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ),
					'max_bytes' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 262144, 'default' => 262144 ),
				),
				array( 'project_slug', 'path' )
			)
		);
		self::add(
			'mad4b/developer-workspace-apply',
			'Developer Workspace Apply',
			'apply',
			false,
			self::schema(
				array(
					'project_slug' => self::project_slug_schema(),
					'expected_manifest_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'expected_absent' => array( 'type' => 'boolean', 'default' => false ),
					'operations' => array(
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => 100,
						'items' => array(
							'type' => 'object',
							'additionalProperties' => false,
							'properties' => array(
								'action' => array( 'type' => 'string', 'enum' => array( 'mkdir', 'write', 'delete' ) ),
								'path' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ),
								'content' => array( 'type' => 'string', 'maxLength' => self::MAX_FILE_BYTES, 'default' => '' ),
							),
							'required' => array( 'action', 'path' ),
						),
					),
					'reason' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 500 ),
					'_mad4b_approval_ticket_id' => self::approval_schema(),
				),
				array( 'project_slug', 'operations', 'reason', '_mad4b_approval_ticket_id' )
			)
		);
		self::add(
			'mad4b/developer-workspace-promote',
			'Promote Developer Workspace Plugin to Staging',
			'promote',
			false,
			self::schema(
				array(
					'project_slug' => self::project_slug_schema(),
					'plugin_file' => array( 'type' => 'string', 'minLength' => 5, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9._-]+\\.php$' ),
					'expected_workspace_manifest_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}
					'reason' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 500 ),
					'_mad4b_approval_ticket_id' => self::approval_schema(),
				),
				array( 'project_slug', 'plugin_file', 'expected_workspace_manifest_sha256', 'reason', '_mad4b_approval_ticket_id' )
			)
		);
	}

	private static function add( $name, $label, $method, $readonly, array $schema ) {
		if ( ! $readonly ) {
			if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) $schema['properties'] = array();
			$schema['properties']['expected_source_commit_sha'] = array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' );
			$schema['properties']['expected_site_uuid'] = array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 );
			$schema['properties']['expected_environment'] = array( 'type' => 'string', 'enum' => array( 'staging', 'development', 'local' ) );
			if ( ! isset( $schema['required'] ) || ! is_array( $schema['required'] ) ) $schema['required'] = array();
			$schema['required'] = array_values( array_unique( array_merge( $schema['required'], array( 'expected_source_commit_sha', 'expected_site_uuid', 'expected_environment' ) ) ) );
		}
		$permission = $readonly
			? static function ( $input = null ) use ( $name ) {
				unset( $input );
				if ( ! MAD4B_SCP_Policy::can_developer_read() ) return false;
				if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return false;
				$identity = MAD4B_SCP_Identity_Context::current();
				if ( is_wp_error( $identity ) ) return false;
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( is_wp_error( $agent ) || empty( $agent['id'] ) ) return false;
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-developer', (string) $name, 'core' );
				return ! is_wp_error( $grant );
			}
			: static function ( $input = null ) use ( $name ) {
				$granted = MAD4B_SCP_Policy::can_developer();
				if ( is_wp_error( $granted ) || ! $granted ) return $granted;
				if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_developer_workspace_authorization_unavailable', 'Central authorization is unavailable.' );
				$authorized = MAD4B_SCP_Authorization::authorize_mutation( $name, 'mad4b-developer', 'core', is_array( $input ) ? $input : array() );
				return is_wp_error( $authorized ) ? $authorized : true;
			};
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the isolated MAD4B Developer Workspace.',
				'category' => 'mad4b-developer',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $permission,
				'input_schema' => $schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'developer',
						'mad4b_governed_write_authority' => ! $readonly,
						'mad4b_developer_plane' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::CONTRACT : 'mad4b.developer-runtime.v1',
						'mad4b_developer_workspace' => self::CONTRACT,
						'production_mutation_allowed' => false,
						'breakglass_allowed' => false,
					),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => (bool) $readonly,
					),
				),
			)
		);
	}

	private static function schema( array $properties, array $required = array() ) {
		$out = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $out['required'] = $required;
		return $out;
	}

	private static function approval_schema() {
		return array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' );
	}

	private static function project_slug_schema( $required = true ) {
		$schema = array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$' );
		if ( $required ) $schema['minLength'] = 2;
		$schema['default'] = '';
		return $schema;
	}

	private static function environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function mutation_gate( array $input, $promotion = false ) {
		$environment = self::environment();
		if ( 'production' === $environment ) return new WP_Error( 'mad4b_developer_workspace_production_denied', 'Developer Workspace mutation is never authorized in Production.' );
		if ( ! in_array( $environment, array( 'staging', 'development', 'local' ), true ) ) return new WP_Error( 'mad4b_developer_workspace_environment_denied', 'Developer Workspace requires an explicit non-Production environment.' );
		if ( $promotion && 'staging' !== $environment ) return new WP_Error( 'mad4b_developer_workspace_promotion_staging_only', 'Developer Workspace promotion is Staging-only.' );
		if ( ! class_exists( 'MAD4B_SCP_Developer_Runtime' ) || ! MAD4B_SCP_Developer_Runtime::developer_flag_enabled() || ! MAD4B_SCP_Developer_Runtime::direct_execution_enabled() || MAD4B_SCP_Developer_Runtime::kill_switch_enabled() ) {
			return new WP_Error( 'mad4b_developer_workspace_runtime_not_ready', 'Developer runtime is not ready.' );
		}
		$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
		$expected_site = isset( $input['expected_site_uuid'] ) ? strtolower( trim( (string) $input['expected_site_uuid'] ) ) : '';
		$expected_env = isset( $input['expected_environment'] ) ? sanitize_key( (string) $input['expected_environment'] ) : '';
		$current_sha = self::current_source_commit_sha();
		$current_site = class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'site_uuid' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || 1 !== preg_match( '/^[a-f0-9]{40}$/', $current_sha ) || ! hash_equals( $current_sha, $expected_sha ) ) return new WP_Error( 'mad4b_developer_workspace_source_binding_mismatch', 'Workspace mutation source commit does not match the loaded exact package.' );
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $expected_site ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/', $current_site ) || ! hash_equals( $current_site, $expected_site ) ) return new WP_Error( 'mad4b_developer_workspace_site_binding_mismatch', 'Workspace mutation site UUID does not match the enrolled site.' );
		if ( ! hash_equals( $environment, $expected_env ) ) return new WP_Error( 'mad4b_developer_workspace_environment_binding_mismatch', 'Workspace mutation environment does not match the live runtime.' );
		return true;
	}

	private static function current_source_commit_sha() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) return '';
		$data = json_decode( (string) file_get_contents( $path ), true );
		$sha = is_array( $data ) && isset( $data['source_commit_sha'] ) ? strtolower( trim( (string) $data['source_commit_sha'] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{40}$/', $sha ) ? $sha : '';
	}

	private static function workspace_root() {
		$configured = defined( 'MAD4B_MCP_DEVELOPER_WORKSPACE_ROOT' ) ? trim( (string) constant( 'MAD4B_MCP_DEVELOPER_WORKSPACE_ROOT' ) ) : '';
		if ( '' !== $configured ) {
			$base = $configured;
			if ( ! file_exists( $base ) && ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_developer_workspace_root_create_failed', 'Configured Developer Workspace root could not be created.' );
		} else {
			if ( ! class_exists( 'MAD4B_SCP_Policy' ) ) return new WP_Error( 'mad4b_developer_workspace_policy_unavailable', 'Workspace policy is unavailable.' );
			$protected = MAD4B_SCP_Policy::prepare_backup_root();
			if ( is_wp_error( $protected ) ) return $protected;
			$base = trailingslashit( $protected ) . 'developer-workspaces';
			if ( ! file_exists( $base ) && ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_developer_workspace_root_create_failed', 'Developer Workspace root could not be created.' );
		}
		$resolved = realpath( $base );
		if ( false === $resolved || ! is_dir( $resolved ) || ! is_writable( $resolved ) ) return new WP_Error( 'mad4b_developer_workspace_root_unusable', 'Developer Workspace root is not usable.' );
		$check = rtrim( str_replace( '\\', '/', $resolved ), '/' );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $web_root ) {
			$web = realpath( $web_root );
			if ( false === $web ) continue;
			$web = rtrim( str_replace( '\\', '/', $web ), '/' );
			if ( $check === $web || 0 === strpos( $check, $web . '/' ) ) return new WP_Error( 'mad4b_developer_workspace_web_exposed', 'Developer Workspace must remain outside WordPress web roots.' );
		}
		@chmod( $resolved, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $resolved;
	}

	private static function valid_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])$/', $slug ) ? $slug : '';
	}

	private static function normalize_relative_path( $path, $directory = false ) {
		$path = str_replace( '\\', '/', trim( (string) $path ) );
		if ( '' === $path || false !== strpos( $path, "\0" ) || '/' === substr( $path, 0, 1 ) || preg_match( '#^[A-Za-z]:/#', $path ) ) return new WP_Error( 'mad4b_developer_workspace_path_invalid', 'Workspace path must be a non-empty relative path.' );
		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || '.' === substr( $part, 0, 1 ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $part ) ) return new WP_Error( 'mad4b_developer_workspace_path_invalid', 'Workspace path contains a denied segment.' );
		}
		$normalized = implode( '/', $parts );
		if ( $directory ) return $normalized;
		$extension = strtolower( pathinfo( $normalized, PATHINFO_EXTENSION ) );
		$allowed = array( 'php', 'js', 'css', 'json', 'md', 'txt', 'xml', 'po', 'pot', 'yaml', 'yml', 'twig' );
		if ( ! in_array( $extension, $allowed, true ) ) return new WP_Error( 'mad4b_developer_workspace_file_type_denied', 'Workspace source file type is not allowlisted.' );
		$base = strtolower( basename( $normalized ) );
		if ( preg_match( '/^(?:wp-config|\.env|auth|credentials|secrets?)(?:\.|$)/', $base ) ) return new WP_Error( 'mad4b_developer_workspace_sensitive_name_denied', 'Secret/configuration-style filenames are denied in Developer Workspace.' );
		return $normalized;
	}

	private static function project_root( $slug, $create = false ) {
		$slug = self::valid_slug( $slug );
		if ( '' === $slug ) return new WP_Error( 'mad4b_developer_workspace_slug_invalid', 'Developer Workspace project slug is invalid.' );
		$root = self::workspace_root();
		if ( is_wp_error( $root ) ) return $root;
		$path = trailingslashit( $root ) . $slug;
		if ( $create && ! file_exists( $path ) && ! wp_mkdir_p( $path ) ) return new WP_Error( 'mad4b_developer_workspace_project_create_failed', 'Developer Workspace project could not be created.' );
		if ( file_exists( $path ) ) {
			if ( is_link( $path ) || ! is_dir( $path ) ) return new WP_Error( 'mad4b_developer_workspace_project_invalid', 'Workspace project root must be a real directory.' );
			$resolved = realpath( $path );
			$root_resolved = realpath( $root );
			if ( false === $resolved || false === $root_resolved || 0 !== strpos( str_replace( '\\', '/', $resolved ), rtrim( str_replace( '\\', '/', $root_resolved ), '/' ) . '/' ) ) return new WP_Error( 'mad4b_developer_workspace_project_escape', 'Workspace project escaped the protected root.' );
			return $resolved;
		}
		return $path;
	}

	private static function resolve_project_path( $project_root, $relative, $must_exist, $directory = false ) {
		$relative = self::normalize_relative_path( $relative, $directory );
		if ( is_wp_error( $relative ) ) return $relative;
		$candidate = trailingslashit( $project_root ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$root_n = rtrim( str_replace( '\\', '/', (string) realpath( $project_root ) ), '/' );
		if ( '' === $root_n ) return new WP_Error( 'mad4b_developer_workspace_project_missing', 'Workspace project root is unavailable.' );
		if ( $must_exist ) {
			$resolved = realpath( $candidate );
			if ( false === $resolved ) return new WP_Error( 'mad4b_developer_workspace_path_missing', 'Workspace path does not exist.' );
		} elseif ( file_exists( $candidate ) ) {
			$resolved = realpath( $candidate );
		} else {
			$parent = realpath( dirname( $candidate ) );
			if ( false === $parent ) return new WP_Error( 'mad4b_developer_workspace_parent_missing', 'Workspace parent directory does not exist.' );
			$resolved = $parent . DIRECTORY_SEPARATOR . basename( $candidate );
		}
		$path_n = str_replace( '\\', '/', $resolved );
		if ( $path_n !== $root_n && 0 !== strpos( $path_n, $root_n . '/' ) ) return new WP_Error( 'mad4b_developer_workspace_path_escape', 'Workspace path escaped the project root.' );
		$cursor = $project_root;
		foreach ( explode( '/', $relative ) as $segment ) {
			$cursor .= DIRECTORY_SEPARATOR . $segment;
			if ( file_exists( $cursor ) && is_link( $cursor ) ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlinks are denied inside Developer Workspace.' );
		}
		return $resolved;
	}

	private static function manifest( $project_root ) {
		if ( ! is_dir( $project_root ) || is_link( $project_root ) ) return new WP_Error( 'mad4b_developer_workspace_project_missing', 'Workspace project is unavailable.' );
		$files = array();
		$total = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $project_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlinks are denied inside Developer Workspace.' );
			if ( ! $item->isFile() ) continue;
			$absolute = $item->getPathname();
			$relative = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $project_root ) ) ), '/' );
			$safe = self::normalize_relative_path( $relative, false );
			if ( is_wp_error( $safe ) ) return $safe;
			$size = (int) $item->getSize();
			$total += $size;
			if ( count( $files ) + 1 > self::MAX_FILES || $total > self::MAX_PROJECT_BYTES ) return new WP_Error( 'mad4b_developer_workspace_size_limit', 'Workspace project exceeds bounded file-count or byte limits.' );
			$hash = hash_file( 'sha256', $absolute );
			if ( ! is_string( $hash ) ) return new WP_Error( 'mad4b_developer_workspace_hash_failed', 'Workspace file hash could not be calculated.' );
			$files[] = array( 'path' => $safe, 'size' => $size, 'sha256' => strtolower( $hash ) );
		}
		usort( $files, static function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
		$material = array( 'files' => $files, 'file_count' => count( $files ), 'total_bytes' => $total );
		$json = wp_json_encode( $material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) return new WP_Error( 'mad4b_developer_workspace_manifest_encode_failed', 'Workspace manifest could not be encoded.' );
		$material['manifest_sha256'] = hash( 'sha256', $json );
		return $material;
	}

	public static function status( $input = array() ) {
		$root = self::workspace_root();
		if ( is_wp_error( $root ) ) return $root;
		$slug = isset( $input['project_slug'] ) ? self::valid_slug( $input['project_slug'] ) : '';
		if ( '' !== $slug ) {
			$project = self::project_root( $slug, false );
			if ( is_wp_error( $project ) ) return $project;
			if ( ! is_dir( $project ) ) return array( 'contract' => self::CONTRACT, 'project_slug' => $slug, 'exists' => false, 'read_only' => true, 'mutation_performed' => false );
			$manifest = self::manifest( $project );
			if ( is_wp_error( $manifest ) ) return $manifest;
			return array( 'contract' => self::CONTRACT, 'project_slug' => $slug, 'exists' => true, 'manifest' => $manifest, 'workspace_web_exposed' => false, 'read_only' => true, 'mutation_performed' => false );
		}
		$projects = array();
		foreach ( new DirectoryIterator( $root ) as $item ) {
			if ( $item->isDot() || $item->isLink() || ! $item->isDir() ) continue;
			$name = self::valid_slug( $item->getFilename() );
			if ( '' === $name ) continue;
			$manifest = self::manifest( $item->getPathname() );
			if ( is_wp_error( $manifest ) ) continue;
			$projects[] = array( 'project_slug' => $name, 'manifest_sha256' => $manifest['manifest_sha256'], 'file_count' => $manifest['file_count'], 'total_bytes' => $manifest['total_bytes'] );
			if ( count( $projects ) >= 100 ) break;
		}
		usort( $projects, static function ( $a, $b ) { return strcmp( $a['project_slug'], $b['project_slug'] ); } );
		return array( 'contract' => self::CONTRACT, 'workspace_ready' => true, 'workspace_web_exposed' => false, 'projects' => $projects, 'count' => count( $projects ), 'read_only' => true, 'mutation_performed' => false );
	}

	public static function read( $input ) {
		$slug = self::valid_slug( isset( $input['project_slug'] ) ? $input['project_slug'] : '' );
		$project = self::project_root( $slug, false );
		if ( is_wp_error( $project ) ) return $project;
		if ( ! is_dir( $project ) ) return new WP_Error( 'mad4b_developer_workspace_project_missing', 'Workspace project does not exist.' );
		$path = self::resolve_project_path( $project, isset( $input['path'] ) ? $input['path'] : '', true, false );
		if ( is_wp_error( $path ) ) return $path;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_developer_workspace_file_unreadable', 'Workspace file is not readable.' );
		$max = isset( $input['max_bytes'] ) ? max( 1, min( 262144, absint( $input['max_bytes'] ) ) ) : 262144;
		$size = filesize( $path );
		if ( false === $size || $size > $max ) return new WP_Error( 'mad4b_developer_workspace_read_limit', 'Workspace file exceeds requested read limit.' );
		$content = file_get_contents( $path );
		if ( false === $content ) return new WP_Error( 'mad4b_developer_workspace_read_failed', 'Workspace file could not be read.' );
		return array( 'contract' => self::CONTRACT, 'project_slug' => $slug, 'path' => self::normalize_relative_path( $input['path'] ), 'content' => $content, 'sha256' => hash( 'sha256', $content ), 'read_only' => true, 'mutation_performed' => false );
	}

	public static function apply( $input ) {
		$gate = self::mutation_gate( is_array( $input ) ? $input : array(), false );
		if ( is_wp_error( $gate ) ) return $gate;
		$slug = self::valid_slug( isset( $input['project_slug'] ) ? $input['project_slug'] : '' );
		if ( '' === $slug ) return new WP_Error( 'mad4b_developer_workspace_slug_invalid', 'Workspace project slug is invalid.' );
		$root = self::workspace_root();
		if ( is_wp_error( $root ) ) return $root;
		$project_path = trailingslashit( $root ) . $slug;
		$exists = is_dir( $project_path ) && ! is_link( $project_path );
		$expected_absent = ! empty( $input['expected_absent'] );
		$expected_manifest = isset( $input['expected_manifest_sha256'] ) ? strtolower( trim( (string) $input['expected_manifest_sha256'] ) ) : '';
		if ( $exists ) {
			if ( $expected_absent ) return new WP_Error( 'mad4b_developer_workspace_project_unexpectedly_exists', 'Workspace project exists but reviewed operation expected it absent.' );
			$current = self::manifest( $project_path );
			if ( is_wp_error( $current ) ) return $current;
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_manifest ) || ! hash_equals( $current['manifest_sha256'], $expected_manifest ) ) return new WP_Error( 'mad4b_developer_workspace_manifest_stale', 'Workspace project changed since review.', array( 'current_manifest_sha256' => $current['manifest_sha256'] ) );
		} else {
			if ( ! $expected_absent || '' !== $expected_manifest ) return new WP_Error( 'mad4b_developer_workspace_absence_confirmation_required', 'Creating a new workspace project requires expected_absent=true and no manifest digest.' );
		}

		$snapshot = self::snapshot_project( $slug, $exists ? $project_path : '' );
		if ( is_wp_error( $snapshot ) ) return $snapshot;
		if ( ! $exists && ! wp_mkdir_p( $project_path ) ) return new WP_Error( 'mad4b_developer_workspace_project_create_failed', 'Workspace project could not be created.' );
		@chmod( $project_path, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$operations = isset( $input['operations'] ) && is_array( $input['operations'] ) ? $input['operations'] : array();
		$batch_bytes = 0;
		foreach ( $operations as $operation ) {
			if ( is_array( $operation ) && 'write' === ( isset( $operation['action'] ) ? sanitize_key( (string) $operation['action'] ) : '' ) ) $batch_bytes += strlen( isset( $operation['content'] ) ? (string) $operation['content'] : '' );
		}
		if ( $batch_bytes > self::MAX_BATCH_BYTES ) return new WP_Error( 'mad4b_developer_workspace_batch_too_large', 'Workspace source batch exceeds the bounded transport limit; split the reviewed change into smaller batches.', array( 'max_batch_bytes' => self::MAX_BATCH_BYTES, 'requested_batch_bytes' => $batch_bytes ) );
		$applied = array();
		foreach ( $operations as $index => $operation ) {
			$result = self::apply_operation( $project_path, is_array( $operation ) ? $operation : array() );
			if ( is_wp_error( $result ) ) {
				$rollback = self::restore_snapshot( $slug, $project_path, $snapshot );
				self::audit( 'mad4b/developer-workspace-apply', array(
					'project_slug' => $slug,
					'operation_index' => (int) $index,
					'failure_code' => $result->get_error_code(),
					'rollback_ok' => ! is_wp_error( $rollback ),
				), 'failure' );
				return new WP_Error( 'mad4b_developer_workspace_apply_failed_with_rollback_status', 'Workspace batch failed; rollback status is attached.', array( 'cause_code' => $result->get_error_code(), 'rollback_ok' => ! is_wp_error( $rollback ) ) );
			}
			$applied[] = $result;
		}
		$manifest = self::manifest( $project_path );
		if ( is_wp_error( $manifest ) ) {
			$rollback = self::restore_snapshot( $slug, $project_path, $snapshot );
			return new WP_Error( 'mad4b_developer_workspace_post_manifest_failed', 'Workspace post-mutation manifest failed; rollback was attempted.', array( 'rollback_ok' => ! is_wp_error( $rollback ) ) );
		}
		self::audit( 'mad4b/developer-workspace-apply', array(
			'project_slug' => $slug,
			'operation_count' => count( $applied ),
			'manifest_sha256' => $manifest['manifest_sha256'],
			'snapshot_id' => $snapshot['snapshot_id'],
			'reason' => sanitize_text_field( (string) $input['reason'] ),
			'production_mutation' => false,
			'breakglass_authorized' => false,
		) );
		return array(
			'contract' => self::CONTRACT,
			'project_slug' => $slug,
			'operations_applied' => count( $applied ),
			'manifest' => $manifest,
			'snapshot_id' => $snapshot['snapshot_id'],
			'workspace_web_exposed' => false,
			'production_mutation' => false,
			'breakglass_authorized' => false,
			'mutation_performed' => true,
		);
	}

	private static function apply_operation( $project_root, array $operation ) {
		$action = isset( $operation['action'] ) ? sanitize_key( (string) $operation['action'] ) : '';
		$relative = isset( $operation['path'] ) ? (string) $operation['path'] : '';
		if ( 'mkdir' === $action ) {
			$path = self::resolve_project_path( $project_root, $relative, false, true );
			if ( is_wp_error( $path ) ) return $path;
			if ( file_exists( $path ) ) return is_dir( $path ) && ! is_link( $path ) ? array( 'action' => 'mkdir', 'path' => self::normalize_relative_path( $relative, true ), 'changed' => false ) : new WP_Error( 'mad4b_developer_workspace_directory_conflict', 'Workspace mkdir target conflicts with a non-directory path.' );
			$ok = wp_mkdir_p( $path );
			if ( $ok ) @chmod( $path, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $ok ? array( 'action' => 'mkdir', 'path' => self::normalize_relative_path( $relative, true ), 'changed' => true ) : new WP_Error( 'mad4b_developer_workspace_mkdir_failed', 'Workspace directory could not be created.' );
		}
		if ( 'write' === $action ) {
			$path = self::resolve_project_path( $project_root, $relative, false, false );
			if ( is_wp_error( $path ) ) return $path;
			if ( file_exists( $path ) && ! is_file( $path ) ) return new WP_Error( 'mad4b_developer_workspace_write_target_invalid', 'Workspace write target is not a regular file.' );
			$content = isset( $operation['content'] ) ? (string) $operation['content'] : '';
			if ( false !== strpos( $content, "\0" ) || strlen( $content ) > self::MAX_FILE_BYTES ) return new WP_Error( 'mad4b_developer_workspace_write_content_invalid', 'Workspace source content is invalid or too large.' );
			$bytes = file_put_contents( $path, $content, LOCK_EX );
			if ( false === $bytes ) return new WP_Error( 'mad4b_developer_workspace_write_failed', 'Workspace source file could not be written.' );
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array( 'action' => 'write', 'path' => self::normalize_relative_path( $relative ), 'sha256' => hash( 'sha256', $content ), 'changed' => true );
		}
		if ( 'delete' === $action ) {
			$path = self::resolve_project_path( $project_root, $relative, true, false );
			if ( is_wp_error( $path ) ) return $path;
			if ( ! is_file( $path ) || is_link( $path ) ) return new WP_Error( 'mad4b_developer_workspace_delete_target_invalid', 'Workspace delete is limited to regular source files.' );
			return unlink( $path ) ? array( 'action' => 'delete', 'path' => self::normalize_relative_path( $relative ), 'changed' => true ) : new WP_Error( 'mad4b_developer_workspace_delete_failed', 'Workspace source file could not be deleted.' );
		}
		return new WP_Error( 'mad4b_developer_workspace_action_invalid', 'Unknown workspace operation.' );
	}

	public static function promote( $input ) {
		$gate = self::mutation_gate( is_array( $input ) ? $input : array(), true );
		if ( is_wp_error( $gate ) ) return $gate;
		$slug = self::valid_slug( isset( $input['project_slug'] ) ? $input['project_slug'] : '' );
		if ( '' === $slug ) return new WP_Error( 'mad4b_developer_workspace_slug_invalid', 'Workspace project slug is invalid.' );
		if ( in_array( $slug, array( 'mad4b-site-control-plane', 'mcp-adapter' ), true ) ) return new WP_Error( 'mad4b_developer_workspace_protected_plugin_denied', 'Control Plane and MCP Adapter cannot be promoted through Developer Workspace.' );
		$plugin_file = isset( $input['plugin_file'] ) ? basename( (string) $input['plugin_file'] ) : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+\.php$/', $plugin_file ) ) return new WP_Error( 'mad4b_developer_workspace_plugin_file_invalid', 'Plugin main file is invalid.' );
		$project = self::project_root( $slug, false );
		if ( is_wp_error( $project ) || ! is_dir( $project ) ) return new WP_Error( 'mad4b_developer_workspace_project_missing', 'Workspace project does not exist.' );
		$manifest = self::manifest( $project );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$expected = strtolower( trim( (string) $input['expected_workspace_manifest_sha256'] ) );
		if ( ! hash_equals( $manifest['manifest_sha256'], $expected ) ) return new WP_Error( 'mad4b_developer_workspace_promotion_manifest_stale', 'Workspace changed since promotion review.', array( 'current_manifest_sha256' => $manifest['manifest_sha256'] ) );
		if ( empty( $manifest['file_count'] ) ) return new WP_Error( 'mad4b_developer_workspace_empty_project', 'Empty workspace project cannot be promoted.' );

		$main = trailingslashit( $project ) . $plugin_file;
		if ( ! is_file( $main ) || is_link( $main ) ) return new WP_Error( 'mad4b_developer_workspace_plugin_main_missing', 'Plugin main PHP file is missing from workspace root.' );
		$headers = get_file_data( $main, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
		$name = isset( $headers['Name'] ) ? trim( (string) $headers['Name'] ) : '';
		$version = isset( $headers['Version'] ) ? trim( (string) $headers['Version'] ) : '';
		if ( '' === $name || ! preg_match( '/^[0-9][0-9A-Za-z._+-]{0,63}$/', $version ) ) return new WP_Error( 'mad4b_developer_workspace_plugin_header_invalid', 'Plugin main file requires valid Plugin Name and Version headers.' );
		$plugin_key = $slug . '/' . $plugin_file;
		if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::plugin_lifecycle_protected( $plugin_key ) ) return new WP_Error( 'mad4b_developer_workspace_protected_plugin_denied', 'Protected plugin cannot be promoted through Developer Workspace.' );

		$lint = self::lint_php_files( $project, $manifest );
		if ( is_wp_error( $lint ) ) return $lint;
		$package = self::build_package( $slug, $project, $manifest );
		if ( is_wp_error( $package ) ) return $package;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_clean_plugins_cache( true );
		$plugins = get_plugins();
		$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug;
		$directory_exists = is_dir( $plugin_dir );
		$installed = isset( $plugins[ $plugin_key ] );
		if ( $directory_exists && ! $installed ) {
			@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_developer_workspace_existing_directory_conflict', 'Target plugin directory already exists but does not match the reviewed plugin main file.' );
		}
		$expected_absent = ! empty( $input['expected_absent'] );
		$expected_installed_manifest = isset( $input['expected_installed_manifest_sha256'] ) ? strtolower( trim( (string) $input['expected_installed_manifest_sha256'] ) ) : '';
		$before_manifest = null;
		if ( $directory_exists ) {
			if ( $expected_absent ) {
				@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'mad4b_developer_workspace_target_unexpectedly_exists', 'Promotion expected an absent plugin target but the plugin directory now exists.' );
			}
			$before_manifest = self::manifest( $plugin_dir );
			if ( is_wp_error( $before_manifest ) ) {
				@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $before_manifest;
			}
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_installed_manifest ) || ! hash_equals( $before_manifest['manifest_sha256'], $expected_installed_manifest ) ) {
				@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'mad4b_developer_workspace_target_manifest_stale', 'Installed plugin changed since promotion review.', array( 'current_installed_manifest_sha256' => $before_manifest['manifest_sha256'] ) );
			}
		} elseif ( ! $expected_absent || '' !== $expected_installed_manifest ) {
			@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_developer_workspace_target_absence_confirmation_required', 'First promotion requires expected_absent=true and no installed-manifest digest.' );
		}
		$before = array(
			'installed' => $installed,
			'active' => $installed && is_plugin_active( $plugin_key ),
			'network_active' => $installed && is_multisite() && is_plugin_active_for_network( $plugin_key ),
			'version' => $installed && isset( $plugins[ $plugin_key ]['Version'] ) ? (string) $plugins[ $plugin_key ]['Version'] : '',
		);
		$backup = self::backup_installed_plugin( $slug, $plugin_dir, $directory_exists );
		if ( is_wp_error( $backup ) ) {
			@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $backup;
		}

		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$installed_result = $upgrader->install( $package['path'], array( 'overwrite_package' => true ) );
		@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_wp_error( $installed_result ) || false === $installed_result ) {
			$rollback = self::rollback_plugin( $slug, $plugin_key, $plugin_dir, $backup, $before );
			return new WP_Error( 'mad4b_developer_workspace_install_failed_with_rollback_status', 'Workspace package install failed; rollback status is attached.', array( 'rollback_ok' => ! is_wp_error( $rollback ), 'cause_code' => is_wp_error( $installed_result ) ? $installed_result->get_error_code() : 'plugin_upgrader_false' ) );
		}

		$activation = self::restore_activation_state( $plugin_key, $before, ! empty( $input['activate_after_install'] ) );
		if ( is_wp_error( $activation ) ) {
			$rollback = self::rollback_plugin( $slug, $plugin_key, $plugin_dir, $backup, $before );
			return new WP_Error( 'mad4b_developer_workspace_activation_failed_with_rollback_status', 'Workspace plugin activation state could not be established; rollback was attempted.', array( 'rollback_ok' => ! is_wp_error( $rollback ), 'cause_code' => $activation->get_error_code() ) );
		}

		$readback = self::verify_installed( $slug, $plugin_file, $version, $manifest );
		if ( is_wp_error( $readback ) ) {
			$rollback = self::rollback_plugin( $slug, $plugin_key, $plugin_dir, $backup, $before );
			return new WP_Error( 'mad4b_developer_workspace_readback_failed_with_rollback_status', 'Workspace plugin readback failed; rollback was attempted.', array( 'rollback_ok' => ! is_wp_error( $rollback ), 'cause_code' => $readback->get_error_code() ) );
		}

		self::audit( 'mad4b/developer-workspace-promote', array(
			'project_slug' => $slug,
			'plugin_file' => $plugin_key,
			'version' => $version,
			'workspace_manifest_sha256' => $manifest['manifest_sha256'],
			'before_installed_manifest_sha256' => is_array( $before_manifest ) ? $before_manifest['manifest_sha256'] : '',
			'package_sha256' => $package['sha256'],
			'backup_id' => $backup['backup_id'],
			'readback_verified' => true,
			'active' => $readback['active'],
			'reason' => sanitize_text_field( (string) $input['reason'] ),
			'production_mutation' => false,
			'breakglass_authorized' => false,
		) );
		return array(
			'contract' => self::PROMOTION_CONTRACT,
			'project_slug' => $slug,
			'plugin_file' => $plugin_key,
			'plugin_name' => $name,
			'version' => $version,
			'workspace_manifest_sha256' => $manifest['manifest_sha256'],
			'before_installed_manifest_sha256' => is_array( $before_manifest ) ? $before_manifest['manifest_sha256'] : '',
			'package_sha256' => $package['sha256'],
			'backup_id' => $backup['backup_id'],
			'readback_verified' => true,
			'active' => $readback['active'],
			'network_active' => $readback['network_active'],
			'runtime_reboot_required' => true,
			'addon_registry_state' => 'unregistered_development_candidate',
			'production_promotion_authorized' => false,
			'production_mutation' => false,
			'breakglass_authorized' => false,
			'mutation_performed' => true,
		);
	}

	private static function lint_php_files( $project, array $manifest ) {
		$count = 0;
		foreach ( $manifest['files'] as $file ) {
			if ( 'php' !== strtolower( pathinfo( $file['path'], PATHINFO_EXTENSION ) ) ) continue;
			$count++;
			if ( $count > 200 ) return new WP_Error( 'mad4b_developer_workspace_php_file_limit', 'Workspace exceeds the bounded PHP syntax-validation file count.' );
			$absolute = trailingslashit( $project ) . str_replace( '/', DIRECTORY_SEPARATOR, $file['path'] );
			if ( ! is_file( $absolute ) || is_link( $absolute ) || ! is_readable( $absolute ) ) return new WP_Error( 'mad4b_developer_workspace_php_source_unreadable', 'Workspace PHP source is unavailable for syntax validation.' );
			$source = file_get_contents( $absolute );
			if ( false === $source || strlen( $source ) > self::MAX_FILE_BYTES ) return new WP_Error( 'mad4b_developer_workspace_php_source_invalid', 'Workspace PHP source could not be read within the bounded file limit.' );
			try {
				token_get_all( $source, TOKEN_PARSE );
			} catch ( \ParseError $error ) {
				return new WP_Error(
					'mad4b_developer_workspace_php_lint_failed',
					'Workspace PHP syntax validation failed.',
					array(
						'path' => $file['path'],
						'diagnostic' => substr( sanitize_text_field( $error->getMessage() ), 0, 500 ),
					)
				);
			}
		}
		return array( 'php_files_linted' => $count, 'validator' => 'php_token_parse' );
	}

	private static function build_package( $slug, $project, array $manifest ) {
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_developer_workspace_zip_unavailable', 'ZipArchive is required for Developer Workspace promotion.' );
		$protected = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $protected ) ) return $protected;
		$dir = trailingslashit( $protected ) . 'developer-promotions';
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) return new WP_Error( 'mad4b_developer_workspace_package_root_failed', 'Promotion package directory could not be created.' );
		$path = trailingslashit( $dir ) . $slug . '-' . substr( $manifest['manifest_sha256'], 0, 20 ) . '.zip';
		if ( file_exists( $path ) ) @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) return new WP_Error( 'mad4b_developer_workspace_zip_create_failed', 'Promotion ZIP could not be created.' );
		foreach ( $manifest['files'] as $file ) {
			$absolute = trailingslashit( $project ) . str_replace( '/', DIRECTORY_SEPARATOR, $file['path'] );
			if ( ! $zip->addFile( $absolute, $slug . '/' . $file['path'] ) ) {
				$zip->close();
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'mad4b_developer_workspace_zip_add_failed', 'Promotion ZIP could not include all reviewed files.' );
			}
		}
		$zip->close();
		$sha = hash_file( 'sha256', $path );
		if ( ! is_string( $sha ) ) return new WP_Error( 'mad4b_developer_workspace_package_hash_failed', 'Promotion package hash could not be calculated.' );
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return array( 'path' => $path, 'sha256' => strtolower( $sha ) );
	}

	private static function verify_installed( $slug, $plugin_file, $version, array $workspace_manifest ) {
		wp_clean_plugins_cache( true );
		$key = $slug . '/' . $plugin_file;
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $key ] ) ) return new WP_Error( 'mad4b_developer_workspace_readback_plugin_missing', 'Promoted plugin is missing after install.' );
		if ( ! hash_equals( $version, isset( $plugins[ $key ]['Version'] ) ? (string) $plugins[ $key ]['Version'] : '' ) ) return new WP_Error( 'mad4b_developer_workspace_readback_version_mismatch', 'Promoted plugin version does not match workspace header.' );
		$root = trailingslashit( WP_PLUGIN_DIR ) . $slug;
		$installed = self::manifest( $root );
		if ( is_wp_error( $installed ) ) return $installed;
		if ( ! hash_equals( $workspace_manifest['manifest_sha256'], $installed['manifest_sha256'] ) ) return new WP_Error( 'mad4b_developer_workspace_readback_manifest_mismatch', 'Installed plugin file set does not exactly match the reviewed workspace manifest.', array( 'installed_manifest_sha256' => $installed['manifest_sha256'] ) );
		return array(
			'plugin_file' => $key,
			'version' => $version,
			'manifest_sha256' => $installed['manifest_sha256'],
			'active' => is_plugin_active( $key ),
			'network_active' => is_multisite() && is_plugin_active_for_network( $key ),
		);
	}

	private static function restore_activation_state( $plugin_key, array $before, $activate_after_install ) {
		$should_activate = ! empty( $before['active'] ) || ! empty( $before['network_active'] ) || $activate_after_install;
		if ( ! $should_activate ) {
			if ( is_plugin_active( $plugin_key ) ) deactivate_plugins( $plugin_key, false, is_multisite() && is_plugin_active_for_network( $plugin_key ) );
			return true;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) return new WP_Error( 'mad4b_developer_workspace_activation_capability_denied', 'Current user cannot activate plugins.' );
		$network = ! empty( $before['network_active'] );
		if ( $network && ! current_user_can( 'manage_network_plugins' ) ) return new WP_Error( 'mad4b_developer_workspace_network_activation_denied', 'Network activation requires manage_network_plugins.' );
		$result = activate_plugin( $plugin_key, '', $network );
		return is_wp_error( $result ) ? $result : true;
	}

	private static function snapshot_project( $slug, $source ) {
		$protected = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $protected ) ) return $protected;
		$id = 'developer-workspace-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$base = trailingslashit( $protected ) . 'developer-workspace-snapshots/' . $id;
		if ( ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_developer_workspace_snapshot_root_failed', 'Workspace snapshot directory could not be created.' );
		$created = '' !== $source && is_dir( $source );
		if ( $created ) {
			$destination = trailingslashit( $base ) . $slug;
			$copy = self::copy_tree( $source, $destination );
			if ( is_wp_error( $copy ) ) return $copy;
		}
		return array( 'snapshot_id' => $id, 'snapshot_root' => $base, 'had_project' => $created );
	}

	private static function restore_snapshot( $slug, $project_path, array $snapshot ) {
		if ( file_exists( $project_path ) ) {
			$delete = self::delete_tree( $project_path );
			if ( is_wp_error( $delete ) ) return $delete;
		}
		if ( ! empty( $snapshot['had_project'] ) ) {
			$source = trailingslashit( $snapshot['snapshot_root'] ) . $slug;
			return self::copy_tree( $source, $project_path );
		}
		return true;
	}

	private static function backup_installed_plugin( $slug, $source, $exists ) {
		$protected = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $protected ) ) return $protected;
		$id = 'developer-promotion-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$base = trailingslashit( $protected ) . 'developer-promotion-backups/' . $id;
		if ( ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_developer_workspace_backup_root_failed', 'Promotion backup directory could not be created.' );
		if ( $exists ) {
			$copy = self::copy_tree( $source, trailingslashit( $base ) . $slug );
			if ( is_wp_error( $copy ) ) return $copy;
		}
		return array( 'backup_id' => $id, 'backup_root' => $base, 'had_plugin' => (bool) $exists );
	}

	private static function rollback_plugin( $slug, $plugin_key, $plugin_dir, array $backup, array $before ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( $plugin_key ) ) deactivate_plugins( $plugin_key, true, is_multisite() && is_plugin_active_for_network( $plugin_key ) );
		if ( file_exists( $plugin_dir ) ) {
			$delete = self::delete_tree( $plugin_dir );
			if ( is_wp_error( $delete ) ) return $delete;
		}
		if ( ! empty( $backup['had_plugin'] ) ) {
			$source = trailingslashit( $backup['backup_root'] ) . $slug;
			$copy = self::copy_tree( $source, $plugin_dir );
			if ( is_wp_error( $copy ) ) return $copy;
			wp_clean_plugins_cache( true );
			if ( ! empty( $before['active'] ) || ! empty( $before['network_active'] ) ) {
				$restore = activate_plugin( $plugin_key, '', ! empty( $before['network_active'] ) );
				if ( is_wp_error( $restore ) ) return $restore;
			}
		}
		return true;
	}

	private static function copy_tree( $source, $destination ) {
		if ( is_link( $source ) || ! is_dir( $source ) ) return new WP_Error( 'mad4b_developer_workspace_copy_source_invalid', 'Copy source must be a real directory.' );
		if ( ! file_exists( $destination ) && ! wp_mkdir_p( $destination ) ) return new WP_Error( 'mad4b_developer_workspace_copy_destination_failed', 'Copy destination could not be created.' );
		foreach ( new DirectoryIterator( $source ) as $item ) {
			if ( $item->isDot() ) continue;
			if ( $item->isLink() ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlinks are denied in Developer Workspace copies.' );
			$target = trailingslashit( $destination ) . $item->getFilename();
			if ( $item->isDir() ) {
				$copy = self::copy_tree( $item->getPathname(), $target );
				if ( is_wp_error( $copy ) ) return $copy;
			} elseif ( $item->isFile() ) {
				if ( ! copy( $item->getPathname(), $target ) ) return new WP_Error( 'mad4b_developer_workspace_copy_failed', 'File copy failed.' );
			}
		}
		return true;
	}

	private static function delete_tree( $path ) {
		if ( is_link( $path ) ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlink deletion is denied.' );
		if ( is_file( $path ) ) return unlink( $path ) ? true : new WP_Error( 'mad4b_developer_workspace_delete_failed', 'File deletion failed.' );
		if ( ! is_dir( $path ) ) return true;
		foreach ( new DirectoryIterator( $path ) as $item ) {
			if ( $item->isDot() ) continue;
			if ( $item->isLink() ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlink deletion is denied.' );
			$result = self::delete_tree( $item->getPathname() );
			if ( is_wp_error( $result ) ) return $result;
		}
		return rmdir( $path ) ? true : new WP_Error( 'mad4b_developer_workspace_delete_failed', 'Directory deletion failed.' );
	}

	private static function audit( $ability, array $summary, $status = 'ok' ) {
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::record( (string) $ability, $summary, $status );
	}
}

MAD4B_SCP_Developer_Workspace::boot();
 ),
					'expected_installed_manifest_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'expected_absent' => array( 'type' => 'boolean', 'default' => false ),
					'activate_after_install' => array( 'type' => 'boolean', 'default' => false ),
					'reason' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 500 ),
					'_mad4b_approval_ticket_id' => self::approval_schema(),
				),
				array( 'project_slug', 'plugin_file', 'expected_workspace_manifest_sha256', 'reason', '_mad4b_approval_ticket_id' )
			)
		);
	}

	private static function add( $name, $label, $method, $readonly, array $schema ) {
		if ( ! $readonly ) {
			if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) $schema['properties'] = array();
			$schema['properties']['expected_source_commit_sha'] = array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' );
			$schema['properties']['expected_site_uuid'] = array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 );
			$schema['properties']['expected_environment'] = array( 'type' => 'string', 'enum' => array( 'staging', 'development', 'local' ) );
			if ( ! isset( $schema['required'] ) || ! is_array( $schema['required'] ) ) $schema['required'] = array();
			$schema['required'] = array_values( array_unique( array_merge( $schema['required'], array( 'expected_source_commit_sha', 'expected_site_uuid', 'expected_environment' ) ) ) );
		}
		$permission = $readonly
			? static function ( $input = null ) use ( $name ) {
				unset( $input );
				if ( ! MAD4B_SCP_Policy::can_developer_read() ) return false;
				if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return false;
				$identity = MAD4B_SCP_Identity_Context::current();
				if ( is_wp_error( $identity ) ) return false;
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( is_wp_error( $agent ) || empty( $agent['id'] ) ) return false;
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-developer', (string) $name, 'core' );
				return ! is_wp_error( $grant );
			}
			: static function ( $input = null ) use ( $name ) {
				$granted = MAD4B_SCP_Policy::can_developer();
				if ( is_wp_error( $granted ) || ! $granted ) return $granted;
				if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_developer_workspace_authorization_unavailable', 'Central authorization is unavailable.' );
				$authorized = MAD4B_SCP_Authorization::authorize_mutation( $name, 'mad4b-developer', 'core', is_array( $input ) ? $input : array() );
				return is_wp_error( $authorized ) ? $authorized : true;
			};
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the isolated MAD4B Developer Workspace.',
				'category' => 'mad4b-developer',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $permission,
				'input_schema' => $schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'developer',
						'mad4b_governed_write_authority' => ! $readonly,
						'mad4b_developer_plane' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::CONTRACT : 'mad4b.developer-runtime.v1',
						'mad4b_developer_workspace' => self::CONTRACT,
						'production_mutation_allowed' => false,
						'breakglass_allowed' => false,
					),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => (bool) $readonly,
					),
				),
			)
		);
	}

	private static function schema( array $properties, array $required = array() ) {
		$out = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $out['required'] = $required;
		return $out;
	}

	private static function approval_schema() {
		return array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' );
	}

	private static function project_slug_schema( $required = true ) {
		$schema = array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$' );
		if ( $required ) $schema['minLength'] = 2;
		$schema['default'] = '';
		return $schema;
	}

	private static function environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function mutation_gate( array $input, $promotion = false ) {
		$environment = self::environment();
		if ( 'production' === $environment ) return new WP_Error( 'mad4b_developer_workspace_production_denied', 'Developer Workspace mutation is never authorized in Production.' );
		if ( ! in_array( $environment, array( 'staging', 'development', 'local' ), true ) ) return new WP_Error( 'mad4b_developer_workspace_environment_denied', 'Developer Workspace requires an explicit non-Production environment.' );
		if ( $promotion && 'staging' !== $environment ) return new WP_Error( 'mad4b_developer_workspace_promotion_staging_only', 'Developer Workspace promotion is Staging-only.' );
		if ( ! class_exists( 'MAD4B_SCP_Developer_Runtime' ) || ! MAD4B_SCP_Developer_Runtime::developer_flag_enabled() || ! MAD4B_SCP_Developer_Runtime::direct_execution_enabled() || MAD4B_SCP_Developer_Runtime::kill_switch_enabled() ) {
			return new WP_Error( 'mad4b_developer_workspace_runtime_not_ready', 'Developer runtime is not ready.' );
		}
		$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
		$expected_site = isset( $input['expected_site_uuid'] ) ? strtolower( trim( (string) $input['expected_site_uuid'] ) ) : '';
		$expected_env = isset( $input['expected_environment'] ) ? sanitize_key( (string) $input['expected_environment'] ) : '';
		$current_sha = self::current_source_commit_sha();
		$current_site = class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'site_uuid' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || 1 !== preg_match( '/^[a-f0-9]{40}$/', $current_sha ) || ! hash_equals( $current_sha, $expected_sha ) ) return new WP_Error( 'mad4b_developer_workspace_source_binding_mismatch', 'Workspace mutation source commit does not match the loaded exact package.' );
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $expected_site ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/', $current_site ) || ! hash_equals( $current_site, $expected_site ) ) return new WP_Error( 'mad4b_developer_workspace_site_binding_mismatch', 'Workspace mutation site UUID does not match the enrolled site.' );
		if ( ! hash_equals( $environment, $expected_env ) ) return new WP_Error( 'mad4b_developer_workspace_environment_binding_mismatch', 'Workspace mutation environment does not match the live runtime.' );
		return true;
	}

	private static function current_source_commit_sha() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) return '';
		$data = json_decode( (string) file_get_contents( $path ), true );
		$sha = is_array( $data ) && isset( $data['source_commit_sha'] ) ? strtolower( trim( (string) $data['source_commit_sha'] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{40}$/', $sha ) ? $sha : '';
	}

	private static function workspace_root() {
		$configured = defined( 'MAD4B_MCP_DEVELOPER_WORKSPACE_ROOT' ) ? trim( (string) constant( 'MAD4B_MCP_DEVELOPER_WORKSPACE_ROOT' ) ) : '';
		if ( '' !== $configured ) {
			$base = $configured;
			if ( ! file_exists( $base ) && ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_developer_workspace_root_create_failed', 'Configured Developer Workspace root could not be created.' );
		} else {
			if ( ! class_exists( 'MAD4B_SCP_Policy' ) ) return new WP_Error( 'mad4b_developer_workspace_policy_unavailable', 'Workspace policy is unavailable.' );
			$protected = MAD4B_SCP_Policy::prepare_backup_root();
			if ( is_wp_error( $protected ) ) return $protected;
			$base = trailingslashit( $protected ) . 'developer-workspaces';
			if ( ! file_exists( $base ) && ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_developer_workspace_root_create_failed', 'Developer Workspace root could not be created.' );
		}
		$resolved = realpath( $base );
		if ( false === $resolved || ! is_dir( $resolved ) || ! is_writable( $resolved ) ) return new WP_Error( 'mad4b_developer_workspace_root_unusable', 'Developer Workspace root is not usable.' );
		$check = rtrim( str_replace( '\\', '/', $resolved ), '/' );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $web_root ) {
			$web = realpath( $web_root );
			if ( false === $web ) continue;
			$web = rtrim( str_replace( '\\', '/', $web ), '/' );
			if ( $check === $web || 0 === strpos( $check, $web . '/' ) ) return new WP_Error( 'mad4b_developer_workspace_web_exposed', 'Developer Workspace must remain outside WordPress web roots.' );
		}
		@chmod( $resolved, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $resolved;
	}

	private static function valid_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])$/', $slug ) ? $slug : '';
	}

	private static function normalize_relative_path( $path, $directory = false ) {
		$path = str_replace( '\\', '/', trim( (string) $path ) );
		if ( '' === $path || false !== strpos( $path, "\0" ) || '/' === substr( $path, 0, 1 ) || preg_match( '#^[A-Za-z]:/#', $path ) ) return new WP_Error( 'mad4b_developer_workspace_path_invalid', 'Workspace path must be a non-empty relative path.' );
		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || '.' === substr( $part, 0, 1 ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $part ) ) return new WP_Error( 'mad4b_developer_workspace_path_invalid', 'Workspace path contains a denied segment.' );
		}
		$normalized = implode( '/', $parts );
		if ( $directory ) return $normalized;
		$extension = strtolower( pathinfo( $normalized, PATHINFO_EXTENSION ) );
		$allowed = array( 'php', 'js', 'css', 'json', 'md', 'txt', 'xml', 'po', 'pot', 'yaml', 'yml', 'twig' );
		if ( ! in_array( $extension, $allowed, true ) ) return new WP_Error( 'mad4b_developer_workspace_file_type_denied', 'Workspace source file type is not allowlisted.' );
		$base = strtolower( basename( $normalized ) );
		if ( preg_match( '/^(?:wp-config|\.env|auth|credentials|secrets?)(?:\.|$)/', $base ) ) return new WP_Error( 'mad4b_developer_workspace_sensitive_name_denied', 'Secret/configuration-style filenames are denied in Developer Workspace.' );
		return $normalized;
	}

	private static function project_root( $slug, $create = false ) {
		$slug = self::valid_slug( $slug );
		if ( '' === $slug ) return new WP_Error( 'mad4b_developer_workspace_slug_invalid', 'Developer Workspace project slug is invalid.' );
		$root = self::workspace_root();
		if ( is_wp_error( $root ) ) return $root;
		$path = trailingslashit( $root ) . $slug;
		if ( $create && ! file_exists( $path ) && ! wp_mkdir_p( $path ) ) return new WP_Error( 'mad4b_developer_workspace_project_create_failed', 'Developer Workspace project could not be created.' );
		if ( file_exists( $path ) ) {
			if ( is_link( $path ) || ! is_dir( $path ) ) return new WP_Error( 'mad4b_developer_workspace_project_invalid', 'Workspace project root must be a real directory.' );
			$resolved = realpath( $path );
			$root_resolved = realpath( $root );
			if ( false === $resolved || false === $root_resolved || 0 !== strpos( str_replace( '\\', '/', $resolved ), rtrim( str_replace( '\\', '/', $root_resolved ), '/' ) . '/' ) ) return new WP_Error( 'mad4b_developer_workspace_project_escape', 'Workspace project escaped the protected root.' );
			return $resolved;
		}
		return $path;
	}

	private static function resolve_project_path( $project_root, $relative, $must_exist, $directory = false ) {
		$relative = self::normalize_relative_path( $relative, $directory );
		if ( is_wp_error( $relative ) ) return $relative;
		$candidate = trailingslashit( $project_root ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$root_n = rtrim( str_replace( '\\', '/', (string) realpath( $project_root ) ), '/' );
		if ( '' === $root_n ) return new WP_Error( 'mad4b_developer_workspace_project_missing', 'Workspace project root is unavailable.' );
		if ( $must_exist ) {
			$resolved = realpath( $candidate );
			if ( false === $resolved ) return new WP_Error( 'mad4b_developer_workspace_path_missing', 'Workspace path does not exist.' );
		} elseif ( file_exists( $candidate ) ) {
			$resolved = realpath( $candidate );
		} else {
			$parent = realpath( dirname( $candidate ) );
			if ( false === $parent ) return new WP_Error( 'mad4b_developer_workspace_parent_missing', 'Workspace parent directory does not exist.' );
			$resolved = $parent . DIRECTORY_SEPARATOR . basename( $candidate );
		}
		$path_n = str_replace( '\\', '/', $resolved );
		if ( $path_n !== $root_n && 0 !== strpos( $path_n, $root_n . '/' ) ) return new WP_Error( 'mad4b_developer_workspace_path_escape', 'Workspace path escaped the project root.' );
		$cursor = $project_root;
		foreach ( explode( '/', $relative ) as $segment ) {
			$cursor .= DIRECTORY_SEPARATOR . $segment;
			if ( file_exists( $cursor ) && is_link( $cursor ) ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlinks are denied inside Developer Workspace.' );
		}
		return $resolved;
	}

	private static function manifest( $project_root ) {
		if ( ! is_dir( $project_root ) || is_link( $project_root ) ) return new WP_Error( 'mad4b_developer_workspace_project_missing', 'Workspace project is unavailable.' );
		$files = array();
		$total = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $project_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlinks are denied inside Developer Workspace.' );
			if ( ! $item->isFile() ) continue;
			$absolute = $item->getPathname();
			$relative = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $project_root ) ) ), '/' );
			$safe = self::normalize_relative_path( $relative, false );
			if ( is_wp_error( $safe ) ) return $safe;
			$size = (int) $item->getSize();
			$total += $size;
			if ( count( $files ) + 1 > self::MAX_FILES || $total > self::MAX_PROJECT_BYTES ) return new WP_Error( 'mad4b_developer_workspace_size_limit', 'Workspace project exceeds bounded file-count or byte limits.' );
			$hash = hash_file( 'sha256', $absolute );
			if ( ! is_string( $hash ) ) return new WP_Error( 'mad4b_developer_workspace_hash_failed', 'Workspace file hash could not be calculated.' );
			$files[] = array( 'path' => $safe, 'size' => $size, 'sha256' => strtolower( $hash ) );
		}
		usort( $files, static function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
		$material = array( 'files' => $files, 'file_count' => count( $files ), 'total_bytes' => $total );
		$json = wp_json_encode( $material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) return new WP_Error( 'mad4b_developer_workspace_manifest_encode_failed', 'Workspace manifest could not be encoded.' );
		$material['manifest_sha256'] = hash( 'sha256', $json );
		return $material;
	}

	public static function status( $input = array() ) {
		$root = self::workspace_root();
		if ( is_wp_error( $root ) ) return $root;
		$slug = isset( $input['project_slug'] ) ? self::valid_slug( $input['project_slug'] ) : '';
		if ( '' !== $slug ) {
			$project = self::project_root( $slug, false );
			if ( is_wp_error( $project ) ) return $project;
			if ( ! is_dir( $project ) ) return array( 'contract' => self::CONTRACT, 'project_slug' => $slug, 'exists' => false, 'read_only' => true, 'mutation_performed' => false );
			$manifest = self::manifest( $project );
			if ( is_wp_error( $manifest ) ) return $manifest;
			return array( 'contract' => self::CONTRACT, 'project_slug' => $slug, 'exists' => true, 'manifest' => $manifest, 'workspace_web_exposed' => false, 'read_only' => true, 'mutation_performed' => false );
		}
		$projects = array();
		foreach ( new DirectoryIterator( $root ) as $item ) {
			if ( $item->isDot() || $item->isLink() || ! $item->isDir() ) continue;
			$name = self::valid_slug( $item->getFilename() );
			if ( '' === $name ) continue;
			$manifest = self::manifest( $item->getPathname() );
			if ( is_wp_error( $manifest ) ) continue;
			$projects[] = array( 'project_slug' => $name, 'manifest_sha256' => $manifest['manifest_sha256'], 'file_count' => $manifest['file_count'], 'total_bytes' => $manifest['total_bytes'] );
			if ( count( $projects ) >= 100 ) break;
		}
		usort( $projects, static function ( $a, $b ) { return strcmp( $a['project_slug'], $b['project_slug'] ); } );
		return array( 'contract' => self::CONTRACT, 'workspace_ready' => true, 'workspace_web_exposed' => false, 'projects' => $projects, 'count' => count( $projects ), 'read_only' => true, 'mutation_performed' => false );
	}

	public static function read( $input ) {
		$slug = self::valid_slug( isset( $input['project_slug'] ) ? $input['project_slug'] : '' );
		$project = self::project_root( $slug, false );
		if ( is_wp_error( $project ) ) return $project;
		if ( ! is_dir( $project ) ) return new WP_Error( 'mad4b_developer_workspace_project_missing', 'Workspace project does not exist.' );
		$path = self::resolve_project_path( $project, isset( $input['path'] ) ? $input['path'] : '', true, false );
		if ( is_wp_error( $path ) ) return $path;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_developer_workspace_file_unreadable', 'Workspace file is not readable.' );
		$max = isset( $input['max_bytes'] ) ? max( 1, min( 262144, absint( $input['max_bytes'] ) ) ) : 262144;
		$size = filesize( $path );
		if ( false === $size || $size > $max ) return new WP_Error( 'mad4b_developer_workspace_read_limit', 'Workspace file exceeds requested read limit.' );
		$content = file_get_contents( $path );
		if ( false === $content ) return new WP_Error( 'mad4b_developer_workspace_read_failed', 'Workspace file could not be read.' );
		return array( 'contract' => self::CONTRACT, 'project_slug' => $slug, 'path' => self::normalize_relative_path( $input['path'] ), 'content' => $content, 'sha256' => hash( 'sha256', $content ), 'read_only' => true, 'mutation_performed' => false );
	}

	public static function apply( $input ) {
		$gate = self::mutation_gate( is_array( $input ) ? $input : array(), false );
		if ( is_wp_error( $gate ) ) return $gate;
		$slug = self::valid_slug( isset( $input['project_slug'] ) ? $input['project_slug'] : '' );
		if ( '' === $slug ) return new WP_Error( 'mad4b_developer_workspace_slug_invalid', 'Workspace project slug is invalid.' );
		$root = self::workspace_root();
		if ( is_wp_error( $root ) ) return $root;
		$project_path = trailingslashit( $root ) . $slug;
		$exists = is_dir( $project_path ) && ! is_link( $project_path );
		$expected_absent = ! empty( $input['expected_absent'] );
		$expected_manifest = isset( $input['expected_manifest_sha256'] ) ? strtolower( trim( (string) $input['expected_manifest_sha256'] ) ) : '';
		if ( $exists ) {
			if ( $expected_absent ) return new WP_Error( 'mad4b_developer_workspace_project_unexpectedly_exists', 'Workspace project exists but reviewed operation expected it absent.' );
			$current = self::manifest( $project_path );
			if ( is_wp_error( $current ) ) return $current;
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_manifest ) || ! hash_equals( $current['manifest_sha256'], $expected_manifest ) ) return new WP_Error( 'mad4b_developer_workspace_manifest_stale', 'Workspace project changed since review.', array( 'current_manifest_sha256' => $current['manifest_sha256'] ) );
		} else {
			if ( ! $expected_absent || '' !== $expected_manifest ) return new WP_Error( 'mad4b_developer_workspace_absence_confirmation_required', 'Creating a new workspace project requires expected_absent=true and no manifest digest.' );
		}

		$snapshot = self::snapshot_project( $slug, $exists ? $project_path : '' );
		if ( is_wp_error( $snapshot ) ) return $snapshot;
		if ( ! $exists && ! wp_mkdir_p( $project_path ) ) return new WP_Error( 'mad4b_developer_workspace_project_create_failed', 'Workspace project could not be created.' );
		@chmod( $project_path, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$operations = isset( $input['operations'] ) && is_array( $input['operations'] ) ? $input['operations'] : array();
		$batch_bytes = 0;
		foreach ( $operations as $operation ) {
			if ( is_array( $operation ) && 'write' === ( isset( $operation['action'] ) ? sanitize_key( (string) $operation['action'] ) : '' ) ) $batch_bytes += strlen( isset( $operation['content'] ) ? (string) $operation['content'] : '' );
		}
		if ( $batch_bytes > self::MAX_BATCH_BYTES ) return new WP_Error( 'mad4b_developer_workspace_batch_too_large', 'Workspace source batch exceeds the bounded transport limit; split the reviewed change into smaller batches.', array( 'max_batch_bytes' => self::MAX_BATCH_BYTES, 'requested_batch_bytes' => $batch_bytes ) );
		$applied = array();
		foreach ( $operations as $index => $operation ) {
			$result = self::apply_operation( $project_path, is_array( $operation ) ? $operation : array() );
			if ( is_wp_error( $result ) ) {
				$rollback = self::restore_snapshot( $slug, $project_path, $snapshot );
				self::audit( 'mad4b/developer-workspace-apply', array(
					'project_slug' => $slug,
					'operation_index' => (int) $index,
					'failure_code' => $result->get_error_code(),
					'rollback_ok' => ! is_wp_error( $rollback ),
				), 'failure' );
				return new WP_Error( 'mad4b_developer_workspace_apply_failed_with_rollback_status', 'Workspace batch failed; rollback status is attached.', array( 'cause_code' => $result->get_error_code(), 'rollback_ok' => ! is_wp_error( $rollback ) ) );
			}
			$applied[] = $result;
		}
		$manifest = self::manifest( $project_path );
		if ( is_wp_error( $manifest ) ) {
			$rollback = self::restore_snapshot( $slug, $project_path, $snapshot );
			return new WP_Error( 'mad4b_developer_workspace_post_manifest_failed', 'Workspace post-mutation manifest failed; rollback was attempted.', array( 'rollback_ok' => ! is_wp_error( $rollback ) ) );
		}
		self::audit( 'mad4b/developer-workspace-apply', array(
			'project_slug' => $slug,
			'operation_count' => count( $applied ),
			'manifest_sha256' => $manifest['manifest_sha256'],
			'snapshot_id' => $snapshot['snapshot_id'],
			'reason' => sanitize_text_field( (string) $input['reason'] ),
			'production_mutation' => false,
			'breakglass_authorized' => false,
		) );
		return array(
			'contract' => self::CONTRACT,
			'project_slug' => $slug,
			'operations_applied' => count( $applied ),
			'manifest' => $manifest,
			'snapshot_id' => $snapshot['snapshot_id'],
			'workspace_web_exposed' => false,
			'production_mutation' => false,
			'breakglass_authorized' => false,
			'mutation_performed' => true,
		);
	}

	private static function apply_operation( $project_root, array $operation ) {
		$action = isset( $operation['action'] ) ? sanitize_key( (string) $operation['action'] ) : '';
		$relative = isset( $operation['path'] ) ? (string) $operation['path'] : '';
		if ( 'mkdir' === $action ) {
			$path = self::resolve_project_path( $project_root, $relative, false, true );
			if ( is_wp_error( $path ) ) return $path;
			if ( file_exists( $path ) ) return is_dir( $path ) && ! is_link( $path ) ? array( 'action' => 'mkdir', 'path' => self::normalize_relative_path( $relative, true ), 'changed' => false ) : new WP_Error( 'mad4b_developer_workspace_directory_conflict', 'Workspace mkdir target conflicts with a non-directory path.' );
			$ok = wp_mkdir_p( $path );
			if ( $ok ) @chmod( $path, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $ok ? array( 'action' => 'mkdir', 'path' => self::normalize_relative_path( $relative, true ), 'changed' => true ) : new WP_Error( 'mad4b_developer_workspace_mkdir_failed', 'Workspace directory could not be created.' );
		}
		if ( 'write' === $action ) {
			$path = self::resolve_project_path( $project_root, $relative, false, false );
			if ( is_wp_error( $path ) ) return $path;
			if ( file_exists( $path ) && ! is_file( $path ) ) return new WP_Error( 'mad4b_developer_workspace_write_target_invalid', 'Workspace write target is not a regular file.' );
			$content = isset( $operation['content'] ) ? (string) $operation['content'] : '';
			if ( false !== strpos( $content, "\0" ) || strlen( $content ) > self::MAX_FILE_BYTES ) return new WP_Error( 'mad4b_developer_workspace_write_content_invalid', 'Workspace source content is invalid or too large.' );
			$bytes = file_put_contents( $path, $content, LOCK_EX );
			if ( false === $bytes ) return new WP_Error( 'mad4b_developer_workspace_write_failed', 'Workspace source file could not be written.' );
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array( 'action' => 'write', 'path' => self::normalize_relative_path( $relative ), 'sha256' => hash( 'sha256', $content ), 'changed' => true );
		}
		if ( 'delete' === $action ) {
			$path = self::resolve_project_path( $project_root, $relative, true, false );
			if ( is_wp_error( $path ) ) return $path;
			if ( ! is_file( $path ) || is_link( $path ) ) return new WP_Error( 'mad4b_developer_workspace_delete_target_invalid', 'Workspace delete is limited to regular source files.' );
			return unlink( $path ) ? array( 'action' => 'delete', 'path' => self::normalize_relative_path( $relative ), 'changed' => true ) : new WP_Error( 'mad4b_developer_workspace_delete_failed', 'Workspace source file could not be deleted.' );
		}
		return new WP_Error( 'mad4b_developer_workspace_action_invalid', 'Unknown workspace operation.' );
	}

	public static function promote( $input ) {
		$gate = self::mutation_gate( is_array( $input ) ? $input : array(), true );
		if ( is_wp_error( $gate ) ) return $gate;
		$slug = self::valid_slug( isset( $input['project_slug'] ) ? $input['project_slug'] : '' );
		if ( '' === $slug ) return new WP_Error( 'mad4b_developer_workspace_slug_invalid', 'Workspace project slug is invalid.' );
		if ( in_array( $slug, array( 'mad4b-site-control-plane', 'mcp-adapter' ), true ) ) return new WP_Error( 'mad4b_developer_workspace_protected_plugin_denied', 'Control Plane and MCP Adapter cannot be promoted through Developer Workspace.' );
		$plugin_file = isset( $input['plugin_file'] ) ? basename( (string) $input['plugin_file'] ) : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+\.php$/', $plugin_file ) ) return new WP_Error( 'mad4b_developer_workspace_plugin_file_invalid', 'Plugin main file is invalid.' );
		$project = self::project_root( $slug, false );
		if ( is_wp_error( $project ) || ! is_dir( $project ) ) return new WP_Error( 'mad4b_developer_workspace_project_missing', 'Workspace project does not exist.' );
		$manifest = self::manifest( $project );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$expected = strtolower( trim( (string) $input['expected_workspace_manifest_sha256'] ) );
		if ( ! hash_equals( $manifest['manifest_sha256'], $expected ) ) return new WP_Error( 'mad4b_developer_workspace_promotion_manifest_stale', 'Workspace changed since promotion review.', array( 'current_manifest_sha256' => $manifest['manifest_sha256'] ) );
		if ( empty( $manifest['file_count'] ) ) return new WP_Error( 'mad4b_developer_workspace_empty_project', 'Empty workspace project cannot be promoted.' );

		$main = trailingslashit( $project ) . $plugin_file;
		if ( ! is_file( $main ) || is_link( $main ) ) return new WP_Error( 'mad4b_developer_workspace_plugin_main_missing', 'Plugin main PHP file is missing from workspace root.' );
		$headers = get_file_data( $main, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
		$name = isset( $headers['Name'] ) ? trim( (string) $headers['Name'] ) : '';
		$version = isset( $headers['Version'] ) ? trim( (string) $headers['Version'] ) : '';
		if ( '' === $name || ! preg_match( '/^[0-9][0-9A-Za-z._+-]{0,63}$/', $version ) ) return new WP_Error( 'mad4b_developer_workspace_plugin_header_invalid', 'Plugin main file requires valid Plugin Name and Version headers.' );
		$plugin_key = $slug . '/' . $plugin_file;
		if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::plugin_lifecycle_protected( $plugin_key ) ) return new WP_Error( 'mad4b_developer_workspace_protected_plugin_denied', 'Protected plugin cannot be promoted through Developer Workspace.' );

		$lint = self::lint_php_files( $project, $manifest );
		if ( is_wp_error( $lint ) ) return $lint;
		$package = self::build_package( $slug, $project, $manifest );
		if ( is_wp_error( $package ) ) return $package;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_clean_plugins_cache( true );
		$plugins = get_plugins();
		$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug;
		$directory_exists = is_dir( $plugin_dir );
		$installed = isset( $plugins[ $plugin_key ] );
		if ( $directory_exists && ! $installed ) {
			@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_developer_workspace_existing_directory_conflict', 'Target plugin directory already exists but does not match the reviewed plugin main file.' );
		}
		$before = array(
			'installed' => $installed,
			'active' => $installed && is_plugin_active( $plugin_key ),
			'network_active' => $installed && is_multisite() && is_plugin_active_for_network( $plugin_key ),
			'version' => $installed && isset( $plugins[ $plugin_key ]['Version'] ) ? (string) $plugins[ $plugin_key ]['Version'] : '',
		);
		$backup = self::backup_installed_plugin( $slug, $plugin_dir, $directory_exists );
		if ( is_wp_error( $backup ) ) {
			@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $backup;
		}

		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$installed_result = $upgrader->install( $package['path'], array( 'overwrite_package' => true ) );
		@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_wp_error( $installed_result ) || false === $installed_result ) {
			$rollback = self::rollback_plugin( $slug, $plugin_key, $plugin_dir, $backup, $before );
			return new WP_Error( 'mad4b_developer_workspace_install_failed_with_rollback_status', 'Workspace package install failed; rollback status is attached.', array( 'rollback_ok' => ! is_wp_error( $rollback ), 'cause_code' => is_wp_error( $installed_result ) ? $installed_result->get_error_code() : 'plugin_upgrader_false' ) );
		}

		$activation = self::restore_activation_state( $plugin_key, $before, ! empty( $input['activate_after_install'] ) );
		if ( is_wp_error( $activation ) ) {
			$rollback = self::rollback_plugin( $slug, $plugin_key, $plugin_dir, $backup, $before );
			return new WP_Error( 'mad4b_developer_workspace_activation_failed_with_rollback_status', 'Workspace plugin activation state could not be established; rollback was attempted.', array( 'rollback_ok' => ! is_wp_error( $rollback ), 'cause_code' => $activation->get_error_code() ) );
		}

		$readback = self::verify_installed( $slug, $plugin_file, $version, $manifest );
		if ( is_wp_error( $readback ) ) {
			$rollback = self::rollback_plugin( $slug, $plugin_key, $plugin_dir, $backup, $before );
			return new WP_Error( 'mad4b_developer_workspace_readback_failed_with_rollback_status', 'Workspace plugin readback failed; rollback was attempted.', array( 'rollback_ok' => ! is_wp_error( $rollback ), 'cause_code' => $readback->get_error_code() ) );
		}

		self::audit( 'mad4b/developer-workspace-promote', array(
			'project_slug' => $slug,
			'plugin_file' => $plugin_key,
			'version' => $version,
			'workspace_manifest_sha256' => $manifest['manifest_sha256'],
			'package_sha256' => $package['sha256'],
			'backup_id' => $backup['backup_id'],
			'readback_verified' => true,
			'active' => $readback['active'],
			'reason' => sanitize_text_field( (string) $input['reason'] ),
			'production_mutation' => false,
			'breakglass_authorized' => false,
		) );
		return array(
			'contract' => self::PROMOTION_CONTRACT,
			'project_slug' => $slug,
			'plugin_file' => $plugin_key,
			'plugin_name' => $name,
			'version' => $version,
			'workspace_manifest_sha256' => $manifest['manifest_sha256'],
			'package_sha256' => $package['sha256'],
			'backup_id' => $backup['backup_id'],
			'readback_verified' => true,
			'active' => $readback['active'],
			'network_active' => $readback['network_active'],
			'runtime_reboot_required' => true,
			'addon_registry_state' => 'unregistered_development_candidate',
			'production_promotion_authorized' => false,
			'production_mutation' => false,
			'breakglass_authorized' => false,
			'mutation_performed' => true,
		);
	}

	private static function lint_php_files( $project, array $manifest ) {
		$count = 0;
		foreach ( $manifest['files'] as $file ) {
			if ( 'php' !== strtolower( pathinfo( $file['path'], PATHINFO_EXTENSION ) ) ) continue;
			$count++;
			if ( $count > 200 ) return new WP_Error( 'mad4b_developer_workspace_php_file_limit', 'Workspace exceeds the bounded PHP syntax-validation file count.' );
			$absolute = trailingslashit( $project ) . str_replace( '/', DIRECTORY_SEPARATOR, $file['path'] );
			if ( ! is_file( $absolute ) || is_link( $absolute ) || ! is_readable( $absolute ) ) return new WP_Error( 'mad4b_developer_workspace_php_source_unreadable', 'Workspace PHP source is unavailable for syntax validation.' );
			$source = file_get_contents( $absolute );
			if ( false === $source || strlen( $source ) > self::MAX_FILE_BYTES ) return new WP_Error( 'mad4b_developer_workspace_php_source_invalid', 'Workspace PHP source could not be read within the bounded file limit.' );
			try {
				token_get_all( $source, TOKEN_PARSE );
			} catch ( \ParseError $error ) {
				return new WP_Error(
					'mad4b_developer_workspace_php_lint_failed',
					'Workspace PHP syntax validation failed.',
					array(
						'path' => $file['path'],
						'diagnostic' => substr( sanitize_text_field( $error->getMessage() ), 0, 500 ),
					)
				);
			}
		}
		return array( 'php_files_linted' => $count, 'validator' => 'php_token_parse' );
	}

	private static function build_package( $slug, $project, array $manifest ) {
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_developer_workspace_zip_unavailable', 'ZipArchive is required for Developer Workspace promotion.' );
		$protected = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $protected ) ) return $protected;
		$dir = trailingslashit( $protected ) . 'developer-promotions';
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) return new WP_Error( 'mad4b_developer_workspace_package_root_failed', 'Promotion package directory could not be created.' );
		$path = trailingslashit( $dir ) . $slug . '-' . substr( $manifest['manifest_sha256'], 0, 20 ) . '.zip';
		if ( file_exists( $path ) ) @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) return new WP_Error( 'mad4b_developer_workspace_zip_create_failed', 'Promotion ZIP could not be created.' );
		foreach ( $manifest['files'] as $file ) {
			$absolute = trailingslashit( $project ) . str_replace( '/', DIRECTORY_SEPARATOR, $file['path'] );
			if ( ! $zip->addFile( $absolute, $slug . '/' . $file['path'] ) ) {
				$zip->close();
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'mad4b_developer_workspace_zip_add_failed', 'Promotion ZIP could not include all reviewed files.' );
			}
		}
		$zip->close();
		$sha = hash_file( 'sha256', $path );
		if ( ! is_string( $sha ) ) return new WP_Error( 'mad4b_developer_workspace_package_hash_failed', 'Promotion package hash could not be calculated.' );
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return array( 'path' => $path, 'sha256' => strtolower( $sha ) );
	}

	private static function verify_installed( $slug, $plugin_file, $version, array $workspace_manifest ) {
		wp_clean_plugins_cache( true );
		$key = $slug . '/' . $plugin_file;
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $key ] ) ) return new WP_Error( 'mad4b_developer_workspace_readback_plugin_missing', 'Promoted plugin is missing after install.' );
		if ( ! hash_equals( $version, isset( $plugins[ $key ]['Version'] ) ? (string) $plugins[ $key ]['Version'] : '' ) ) return new WP_Error( 'mad4b_developer_workspace_readback_version_mismatch', 'Promoted plugin version does not match workspace header.' );
		$root = trailingslashit( WP_PLUGIN_DIR ) . $slug;
		$installed = self::manifest( $root );
		if ( is_wp_error( $installed ) ) return $installed;
		if ( ! hash_equals( $workspace_manifest['manifest_sha256'], $installed['manifest_sha256'] ) ) return new WP_Error( 'mad4b_developer_workspace_readback_manifest_mismatch', 'Installed plugin file set does not exactly match the reviewed workspace manifest.', array( 'installed_manifest_sha256' => $installed['manifest_sha256'] ) );
		return array(
			'plugin_file' => $key,
			'version' => $version,
			'manifest_sha256' => $installed['manifest_sha256'],
			'active' => is_plugin_active( $key ),
			'network_active' => is_multisite() && is_plugin_active_for_network( $key ),
		);
	}

	private static function restore_activation_state( $plugin_key, array $before, $activate_after_install ) {
		$should_activate = ! empty( $before['active'] ) || ! empty( $before['network_active'] ) || $activate_after_install;
		if ( ! $should_activate ) {
			if ( is_plugin_active( $plugin_key ) ) deactivate_plugins( $plugin_key, false, is_multisite() && is_plugin_active_for_network( $plugin_key ) );
			return true;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) return new WP_Error( 'mad4b_developer_workspace_activation_capability_denied', 'Current user cannot activate plugins.' );
		$network = ! empty( $before['network_active'] );
		if ( $network && ! current_user_can( 'manage_network_plugins' ) ) return new WP_Error( 'mad4b_developer_workspace_network_activation_denied', 'Network activation requires manage_network_plugins.' );
		$result = activate_plugin( $plugin_key, '', $network );
		return is_wp_error( $result ) ? $result : true;
	}

	private static function snapshot_project( $slug, $source ) {
		$protected = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $protected ) ) return $protected;
		$id = 'developer-workspace-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$base = trailingslashit( $protected ) . 'developer-workspace-snapshots/' . $id;
		if ( ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_developer_workspace_snapshot_root_failed', 'Workspace snapshot directory could not be created.' );
		$created = '' !== $source && is_dir( $source );
		if ( $created ) {
			$destination = trailingslashit( $base ) . $slug;
			$copy = self::copy_tree( $source, $destination );
			if ( is_wp_error( $copy ) ) return $copy;
		}
		return array( 'snapshot_id' => $id, 'snapshot_root' => $base, 'had_project' => $created );
	}

	private static function restore_snapshot( $slug, $project_path, array $snapshot ) {
		if ( file_exists( $project_path ) ) {
			$delete = self::delete_tree( $project_path );
			if ( is_wp_error( $delete ) ) return $delete;
		}
		if ( ! empty( $snapshot['had_project'] ) ) {
			$source = trailingslashit( $snapshot['snapshot_root'] ) . $slug;
			return self::copy_tree( $source, $project_path );
		}
		return true;
	}

	private static function backup_installed_plugin( $slug, $source, $exists ) {
		$protected = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $protected ) ) return $protected;
		$id = 'developer-promotion-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$base = trailingslashit( $protected ) . 'developer-promotion-backups/' . $id;
		if ( ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_developer_workspace_backup_root_failed', 'Promotion backup directory could not be created.' );
		if ( $exists ) {
			$copy = self::copy_tree( $source, trailingslashit( $base ) . $slug );
			if ( is_wp_error( $copy ) ) return $copy;
		}
		return array( 'backup_id' => $id, 'backup_root' => $base, 'had_plugin' => (bool) $exists );
	}

	private static function rollback_plugin( $slug, $plugin_key, $plugin_dir, array $backup, array $before ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( $plugin_key ) ) deactivate_plugins( $plugin_key, true, is_multisite() && is_plugin_active_for_network( $plugin_key ) );
		if ( file_exists( $plugin_dir ) ) {
			$delete = self::delete_tree( $plugin_dir );
			if ( is_wp_error( $delete ) ) return $delete;
		}
		if ( ! empty( $backup['had_plugin'] ) ) {
			$source = trailingslashit( $backup['backup_root'] ) . $slug;
			$copy = self::copy_tree( $source, $plugin_dir );
			if ( is_wp_error( $copy ) ) return $copy;
			wp_clean_plugins_cache( true );
			if ( ! empty( $before['active'] ) || ! empty( $before['network_active'] ) ) {
				$restore = activate_plugin( $plugin_key, '', ! empty( $before['network_active'] ) );
				if ( is_wp_error( $restore ) ) return $restore;
			}
		}
		return true;
	}

	private static function copy_tree( $source, $destination ) {
		if ( is_link( $source ) || ! is_dir( $source ) ) return new WP_Error( 'mad4b_developer_workspace_copy_source_invalid', 'Copy source must be a real directory.' );
		if ( ! file_exists( $destination ) && ! wp_mkdir_p( $destination ) ) return new WP_Error( 'mad4b_developer_workspace_copy_destination_failed', 'Copy destination could not be created.' );
		foreach ( new DirectoryIterator( $source ) as $item ) {
			if ( $item->isDot() ) continue;
			if ( $item->isLink() ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlinks are denied in Developer Workspace copies.' );
			$target = trailingslashit( $destination ) . $item->getFilename();
			if ( $item->isDir() ) {
				$copy = self::copy_tree( $item->getPathname(), $target );
				if ( is_wp_error( $copy ) ) return $copy;
			} elseif ( $item->isFile() ) {
				if ( ! copy( $item->getPathname(), $target ) ) return new WP_Error( 'mad4b_developer_workspace_copy_failed', 'File copy failed.' );
			}
		}
		return true;
	}

	private static function delete_tree( $path ) {
		if ( is_link( $path ) ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlink deletion is denied.' );
		if ( is_file( $path ) ) return unlink( $path ) ? true : new WP_Error( 'mad4b_developer_workspace_delete_failed', 'File deletion failed.' );
		if ( ! is_dir( $path ) ) return true;
		foreach ( new DirectoryIterator( $path ) as $item ) {
			if ( $item->isDot() ) continue;
			if ( $item->isLink() ) return new WP_Error( 'mad4b_developer_workspace_symlink_denied', 'Symlink deletion is denied.' );
			$result = self::delete_tree( $item->getPathname() );
			if ( is_wp_error( $result ) ) return $result;
		}
		return rmdir( $path ) ? true : new WP_Error( 'mad4b_developer_workspace_delete_failed', 'Directory deletion failed.' );
	}

	private static function audit( $ability, array $summary, $status = 'ok' ) {
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::record( (string) $ability, $summary, $status );
	}
}

MAD4B_SCP_Developer_Workspace::boot();
