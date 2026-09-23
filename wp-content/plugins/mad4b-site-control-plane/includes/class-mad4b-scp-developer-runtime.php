<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Isolated developer execution plane.
 *
 * This surface is never mounted on mad4b-chatgpt/mad4b-write. It is disabled by
 * default, non-Production only, exact-agent bound, exact-grant bound and routed
 * through the existing MAD4B one-time approval/budget/audit execution boundary.
 */
final class MAD4B_SCP_Developer_Runtime {
	const CONTRACT = 'mad4b.developer-runtime.v1';
	const RECEIPT_CONTRACT = 'mad4b.developer-execution-receipt.v1';
	const NORMAL_SERVER = 'mad4b-developer';
	const BREAKGLASS_SERVER = 'mad4b-developer-breakglass';
	const DEFAULT_TIMEOUT = 30;
	const MAX_TIMEOUT = 120;
	const MAX_OUTPUT_BYTES = 262144;
	const DEFAULT_MEMORY_LIMIT_BYTES = 536870912;
	const MAX_MEMORY_LIMIT_BYTES = 1073741824;
	const MAX_OPEN_FILES = 256;
	const MAX_PROCESSES = 64;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ), 18 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 18 );
	}

	public static function register_categories() {
		wp_register_ability_category( 'mad4b-developer', array(
			'label' => 'MAD4B Developer',
			'description' => 'Explicit non-Production developer-agent execution abilities.',
		) );
		wp_register_ability_category( 'mad4b-developer-breakglass', array(
			'label' => 'MAD4B Developer Breakglass',
			'description' => 'Exceptional non-Production developer recovery abilities.',
		) );
	}

	public static function tool_names( $breakglass = false ) {
		if ( $breakglass ) {
			return array(
				'mad4b/developer-breakglass-shell',
				'mad4b/developer-breakglass-wp-eval',
			);
		}
		return array(
			'mad4b/developer-runtime-status',
			'mad4b/developer-wp-cli',
			'mad4b/developer-filesystem',
			'mad4b/developer-package-install',
		);
	}

	public static function register_abilities() {
		self::add(
			'mad4b/developer-runtime-status',
			'Developer Runtime Status',
			'mad4b-developer',
			'runtime_status',
			true,
			self::schema( array() ),
			array( 'MAD4B_SCP_Policy', 'can_developer_runtime_status' ),
			'developer'
		);

		self::add(
			'mad4b/developer-wp-cli',
			'Developer WP-CLI',
			'mad4b-developer',
			'wp_cli',
			false,
			self::schema(
				array(
					'args' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 64, 'items' => array( 'type' => 'string', 'maxLength' => 4096 ) ),
					'timeout_seconds' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TIMEOUT, 'default' => self::DEFAULT_TIMEOUT ),
					'_mad4b_approval_ticket_id' => self::approval_schema(),
				),
				array( 'args', '_mad4b_approval_ticket_id' )
			),
			array( 'MAD4B_SCP_Policy', 'can_developer' ),
			'developer'
		);

		self::add(
			'mad4b/developer-filesystem',
			'Developer Filesystem',
			'mad4b-developer',
			'filesystem',
			false,
			self::schema(
				array(
					'action' => array( 'type' => 'string', 'enum' => array( 'read', 'write', 'delete', 'mkdir' ) ),
					'root' => array( 'type' => 'string', 'enum' => array( 'wordpress', 'content', 'plugins', 'themes', 'uploads' ) ),
					'path' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ),
					'content' => array( 'type' => 'string', 'maxLength' => 1048576, 'default' => '' ),
					'expected_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'expected_absent' => array( 'type' => 'boolean', 'default' => false ),
					'_mad4b_approval_ticket_id' => self::approval_schema(),
				),
				array( 'action', 'root', 'path', '_mad4b_approval_ticket_id' )
			),
			array( 'MAD4B_SCP_Policy', 'can_developer' ),
			'developer'
		);

		self::add(
			'mad4b/developer-package-install',
			'Developer Package Install',
			'mad4b-developer',
			'package_install',
			false,
			self::schema(
				array(
					'package' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'pattern' => '^[a-z0-9](?:[a-z0-9-]{0,189}[a-z0-9])?$' ),
					'version' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[0-9][0-9A-Za-z._-]{0,63}$' ),
					'timeout_seconds' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TIMEOUT, 'default' => 60 ),
					'_mad4b_approval_ticket_id' => self::approval_schema(),
				),
				array( 'package', 'version', '_mad4b_approval_ticket_id' )
			),
			array( 'MAD4B_SCP_Policy', 'can_developer' ),
			'developer'
		);

		self::add(
			'mad4b/developer-breakglass-shell',
			'Developer Breakglass Shell',
			'mad4b-developer-breakglass',
			'breakglass_shell',
			false,
			self::schema(
				array(
					'command' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 65536 ),
					'working_dir' => array( 'type' => 'string', 'maxLength' => 500, 'default' => '' ),
					'timeout_seconds' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TIMEOUT, 'default' => self::DEFAULT_TIMEOUT ),
					'reason' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 500 ),
					'_mad4b_approval_ticket_id' => self::approval_schema(),
				),
				array( 'command', 'reason', '_mad4b_approval_ticket_id' )
			),
			array( 'MAD4B_SCP_Policy', 'can_developer_breakglass' ),
			'developer-breakglass'
		);

		self::add(
			'mad4b/developer-breakglass-wp-eval',
			'Developer Breakglass WP Eval',
			'mad4b-developer-breakglass',
			'breakglass_wp_eval',
			false,
			self::schema(
				array(
					'code' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 131072 ),
					'working_dir' => array( 'type' => 'string', 'maxLength' => 500, 'default' => '' ),
					'timeout_seconds' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TIMEOUT, 'default' => self::DEFAULT_TIMEOUT ),
					'reason' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 500 ),
					'_mad4b_approval_ticket_id' => self::approval_schema(),
				),
				array( 'code', 'reason', '_mad4b_approval_ticket_id' )
			),
			array( 'MAD4B_SCP_Policy', 'can_developer_breakglass' ),
			'developer-breakglass'
		);
	}

	private static function add( $name, $label, $category, $method, $readonly, $input, $permission, $surface ) {
		if ( ! $readonly && is_array( $input ) ) {
			if ( ! isset( $input['properties'] ) || ! is_array( $input['properties'] ) ) $input['properties'] = array();
			$input['properties']['expected_source_commit_sha'] = array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' );
			$input['properties']['expected_site_uuid'] = array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 );
			$input['properties']['expected_environment'] = array( 'type' => 'string', 'enum' => array( 'staging', 'development', 'local' ) );
			$input['properties']['allow_network'] = array( 'type' => 'boolean', 'default' => false );
			if ( ! isset( $input['required'] ) || ! is_array( $input['required'] ) ) $input['required'] = array();
			$input['required'] = array_values( array_unique( array_merge( $input['required'], array( 'expected_source_commit_sha', 'expected_site_uuid', 'expected_environment' ) ) ) );
		}
		$args = array(
			'label' => $label,
			'description' => $label . ' through the isolated MAD4B Developer Plane.',
			'category' => $category,
			'execute_callback' => array( __CLASS__, $method ),
			'permission_callback' => self::permission_callback( $permission, $readonly, $name, $category ),
			'input_schema' => $input,
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array(
					'public' => false,
					'type' => 'tool',
					'surface' => $surface,
					'mad4b_governed_write_authority' => ! $readonly,
					'mad4b_developer_plane' => self::CONTRACT,
				),
				'annotations' => array(
					'readonly' => (bool) $readonly,
					'destructive' => ! $readonly,
					'idempotent' => $readonly,
				),
			),
		);
		wp_register_ability( $name, $args );
	}

	private static function permission_callback( $permission, $readonly, $ability_name, $server_id ) {
		if ( $readonly ) return $permission;
		return static function ( $input = null ) use ( $permission, $ability_name, $server_id ) {
			$granted = is_callable( $permission ) ? call_user_func( $permission, $input ) : false;
			if ( is_wp_error( $granted ) || ! $granted ) return $granted;
			if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_developer_authorization_unavailable', 'Central MAD4B authorization is unavailable for Developer execution.' );
			$authorized = MAD4B_SCP_Authorization::authorize_mutation(
				(string) $ability_name,
				sanitize_key( (string) $server_id ),
				'core',
				is_array( $input ) ? $input : array()
			);
			return is_wp_error( $authorized ) ? $authorized : true;
		};
	}


	private static function approval_schema() {
		return array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 );
	}

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}

	public static function runtime_status() {
		$env = self::environment();
		$wp = self::wp_cli_binary();
		$shell = self::shell_binary();
		$agent = self::configured_agent_public_id();
		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'environment' => $env,
			'production_authorized' => false,
			'developer_enabled' => self::developer_flag_enabled(),
			'direct_execution_enabled' => self::direct_execution_enabled(),
			'breakglass_enabled' => self::breakglass_flag_enabled(),
			'kill_switch_enabled' => self::kill_switch_enabled(),
			'configuration_source' => self::configuration_source(),
			'configured_agent_public_id' => $agent,
			'configured_agent_exact' => '' !== $agent,
			'proc_open_available' => function_exists( 'proc_open' ),
			'wp_cli_available' => '' !== $wp,
			'wp_cli_binary' => '' !== $wp ? basename( $wp ) : '',
			'shell_available' => '' !== $shell,
			'shell_binary' => '' !== $shell ? basename( $shell ) : '',
			'network_default_deny' => true,
			'network_per_job_opt_in_required' => true,
			'network_global_gate_enabled' => defined( 'MAD4B_MCP_DEVELOPER_NETWORK_ENABLED' ) && true === constant( 'MAD4B_MCP_DEVELOPER_NETWORK_ENABLED' ),
			'network_isolation_backend' => self::network_sandbox_type(),
			'network_sandbox_binary_present' => '' !== self::network_sandbox_binary(),
			'network_enforcement' => 'execution_proves_os_sandbox_or_fails_closed_unless_explicit_global_plus_per_job_opt_in',
			'resource_limit_backend' => '' !== self::prlimit_binary() ? 'prlimit' : '',
			'resource_limiter_binary_present' => '' !== self::prlimit_binary(),
			'resource_limits_enforcement' => 'execution_proves_prlimit_or_fails_closed',
			'default_memory_limit_bytes' => self::DEFAULT_MEMORY_LIMIT_BYTES,
			'max_open_files' => self::MAX_OPEN_FILES,
			'max_processes' => self::MAX_PROCESSES,
			'non_root_verified' => function_exists( 'posix_geteuid' ) ? 0 !== (int) posix_geteuid() : null,
			'exact_runtime_binding_required' => true,
			'secret_redaction_enabled' => true,
			'max_timeout_seconds' => self::MAX_TIMEOUT,
			'max_output_bytes' => self::MAX_OUTPUT_BYTES,
			'normal_server' => self::NORMAL_SERVER,
			'breakglass_server' => self::BREAKGLASS_SERVER,
		);
	}

	public static function wp_cli( $input ) {
		$gate = self::runtime_gate( false, $input );
		if ( is_wp_error( $gate ) ) return $gate;
		$wp = self::wp_cli_binary();
		if ( '' === $wp ) return new WP_Error( 'mad4b_developer_wp_cli_unavailable', 'WP-CLI executable is unavailable.' );
		$args = isset( $input['args'] ) && is_array( $input['args'] ) ? array_values( array_map( 'strval', $input['args'] ) ) : array();
		if ( empty( $args ) ) return new WP_Error( 'mad4b_developer_wp_cli_args_required', 'At least one WP-CLI argument is required.' );
		$guard = self::normal_wp_cli_guard( $args );
		if ( is_wp_error( $guard ) ) return $guard;
		if ( self::wp_cli_may_use_network( $args ) && ! self::network_authorized( $input ) ) return new WP_Error( 'mad4b_developer_network_denied', 'This WP-CLI operation may use outbound network access; explicit per-job network authority is required.' );
		return self::execute( 'mad4b/developer-wp-cli', array_merge( array( $wp, '--path=' . ABSPATH, '--skip-packages' ), $args ), $input, false, true, true );
	}

	public static function filesystem( $input ) {
		$gate = self::runtime_gate( false, $input, false );
		if ( is_wp_error( $gate ) ) return $gate;
		$action = isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '';
		$root = isset( $input['root'] ) ? sanitize_key( (string) $input['root'] ) : '';
		$path_input = isset( $input['path'] ) ? (string) $input['path'] : '';
		$must_exist = ! in_array( $action, array( 'write', 'mkdir' ), true );
		$path = MAD4B_SCP_Policy::resolve_path( $root, $path_input, $must_exist );
		if ( is_wp_error( $path ) ) return $path;
		if ( MAD4B_SCP_Policy::is_sensitive_path( $path ) ) return new WP_Error( 'mad4b_developer_sensitive_path_denied', 'Sensitive credential/configuration paths remain denied outside Developer Breakglass.' );
		if ( in_array( $action, array( 'write', 'delete', 'mkdir' ), true ) ) {
			$mutation_guard = self::normal_filesystem_mutation_guard( $action, $root, $path );
			if ( is_wp_error( $mutation_guard ) ) return $mutation_guard;
		}

		if ( 'read' === $action ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_developer_file_unreadable', 'Requested file is not readable.' );
			$content = file_get_contents( $path );
			if ( false === $content ) return new WP_Error( 'mad4b_developer_file_read_failed', 'Unable to read requested file.' );
			if ( strlen( $content ) > self::MAX_OUTPUT_BYTES ) return new WP_Error( 'mad4b_developer_file_too_large', 'File exceeds developer output limit.' );
			return array( 'contract' => self::CONTRACT, 'action' => 'read', 'content' => self::redact( $content ), 'sha256' => hash( 'sha256', $content ), 'mutation_performed' => false );
		}
		if ( 'mkdir' === $action ) {
			if ( file_exists( $path ) ) return array( 'contract' => self::CONTRACT, 'action' => 'mkdir', 'path_exists' => true, 'mutation_performed' => false );
			$ok = wp_mkdir_p( $path );
			return self::filesystem_receipt( $action, $path, $ok, $ok );
		}
		if ( 'write' === $action ) {
			$expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
			$expected_absent = ! empty( $input['expected_absent'] );
			if ( $expected_absent && '' !== $expected ) return new WP_Error( 'mad4b_developer_file_expectation_conflict', 'Write cannot require both expected_absent and expected_sha256.' );
			$exists = file_exists( $path );
			if ( $exists ) {
				if ( $expected_absent ) return new WP_Error( 'mad4b_developer_file_unexpectedly_exists', 'File now exists but the reviewed write expected an absent target.' );
				if ( 64 !== strlen( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ) return new WP_Error( 'mad4b_developer_write_sha_required', 'Overwriting an existing file requires its exact reviewed SHA-256.' );
				$current = hash_file( 'sha256', $path );
				if ( ! is_string( $current ) || ! hash_equals( $expected, strtolower( $current ) ) ) return new WP_Error( 'mad4b_developer_file_stale', 'File SHA-256 changed since review.' );
			} else {
				if ( '' !== $expected ) return new WP_Error( 'mad4b_developer_file_missing_after_review', 'Reviewed file no longer exists.' );
				if ( ! $expected_absent ) return new WP_Error( 'mad4b_developer_write_absence_confirmation_required', 'Creating a new file requires expected_absent=true.' );
			}
			$content = isset( $input['content'] ) ? (string) $input['content'] : '';
			$bytes = file_put_contents( $path, $content, LOCK_EX );
			$ok = false !== $bytes;
			return self::filesystem_receipt( $action, $path, $ok, $ok, $ok ? hash( 'sha256', $content ) : '' );
		}
		if ( 'delete' === $action ) {
			if ( ! is_file( $path ) ) return new WP_Error( 'mad4b_developer_delete_file_required', 'Developer delete is limited to files.' );
			$expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
			if ( 64 !== strlen( $expected ) ) return new WP_Error( 'mad4b_developer_delete_sha_required', 'Delete requires expected_sha256.' );
			$current = hash_file( 'sha256', $path );
			if ( ! is_string( $current ) || ! hash_equals( $expected, strtolower( $current ) ) ) return new WP_Error( 'mad4b_developer_file_stale', 'File SHA-256 changed since review.' );
			$ok = unlink( $path );
			return self::filesystem_receipt( $action, $path, $ok, $ok );
		}
		return new WP_Error( 'mad4b_developer_filesystem_action_invalid', 'Unknown developer filesystem action.' );
	}

	private static function normal_filesystem_mutation_guard( $action, $root, $path ) {
		$action = sanitize_key( (string) $action );
		$root = sanitize_key( (string) $root );
		if ( 'mkdir' === $action ) {
			$allowed_roots = apply_filters( 'mad4b_scp_mutable_data_roots', array( 'uploads' ) );
			if ( ! is_array( $allowed_roots ) || ! in_array( $root, $allowed_roots, true ) ) {
				return new WP_Error( 'mad4b_developer_filesystem_code_root_denied', 'Normal Developer directory creation is limited to mutable non-code data roots.' );
			}
			if ( MAD4B_SCP_Policy::is_sensitive_path( $path ) || MAD4B_SCP_Policy::is_code_or_server_config_path( $path ) ) {
				return new WP_Error( 'mad4b_developer_filesystem_code_mutation_denied', 'Normal Developer cannot create executable or sensitive filesystem targets.' );
			}
			return true;
		}
		if ( ! method_exists( 'MAD4B_SCP_Policy', 'can_mutate_file' ) ) return new WP_Error( 'mad4b_developer_filesystem_policy_unavailable', 'Mutable-file policy is unavailable.' );
		$allowed = MAD4B_SCP_Policy::can_mutate_file( $root, $path );
		if ( is_wp_error( $allowed ) ) return $allowed;
		return true;
	}


	public static function package_install( $input ) {
		$gate = self::runtime_gate( false, $input );
		if ( is_wp_error( $gate ) ) return $gate;
		$wp = self::wp_cli_binary();
		if ( '' === $wp ) return new WP_Error( 'mad4b_developer_wp_cli_unavailable', 'WP-CLI executable is unavailable.' );
		$package = isset( $input['package'] ) ? trim( (string) $input['package'] ) : '';
		if ( '' === $package || false !== strpos( $package, "\\0" ) ) return new WP_Error( 'mad4b_developer_package_invalid', 'Package identifier is invalid.' );
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,189}[a-z0-9])?$/', $package ) ) return new WP_Error( 'mad4b_developer_package_source_breakglass_required', 'Normal Developer package installation accepts only a WordPress.org-style plugin slug. Custom URLs, archives and alternate sources require Developer Breakglass.' );
		$version = isset( $input['version'] ) ? trim( (string) $input['version'] ) : '';
		if ( ! preg_match( '/^[0-9][0-9A-Za-z._-]{0,63}$/', $version ) ) return new WP_Error( 'mad4b_developer_package_version_required', 'Normal Developer package installation requires an exact non-development WordPress.org plugin version.' );
		if ( ! empty( $input['activate'] ) ) return new WP_Error( 'mad4b_developer_package_activation_denied', 'Normal Developer package installation never activates code; use the governed plugin activation surface separately.' );
		if ( ! empty( $input['force'] ) ) return new WP_Error( 'mad4b_developer_package_overwrite_denied', 'Normal Developer package installation never overwrites an installed plugin; updates require a separate governed lifecycle or Developer Breakglass.' );
		if ( ! self::network_authorized( $input ) ) return new WP_Error( 'mad4b_developer_network_denied', 'Package installation requires explicit per-job network authority.' );
		$args = array( $wp, '--path=' . ABSPATH, 'plugin', 'install', $package, '--version=' . $version );
		$args = array_merge( array_slice( $args, 0, 2 ), array( '--skip-packages' ), array_slice( $args, 2 ) );
		return self::execute( 'mad4b/developer-package-install', $args, $input, false, true, true );
	}

	public static function breakglass_shell( $input ) {
		$gate = self::runtime_gate( true, $input );
		if ( is_wp_error( $gate ) ) return $gate;
		$shell = self::shell_binary();
		if ( '' === $shell ) return new WP_Error( 'mad4b_developer_shell_unavailable', 'A supported shell executable is unavailable.' );
		$command = isset( $input['command'] ) ? (string) $input['command'] : '';
		if ( '' === trim( $command ) ) return new WP_Error( 'mad4b_developer_command_required', 'Shell command is required.' );
		if ( self::shell_may_use_network( $command ) && ! self::network_authorized( $input ) ) return new WP_Error( 'mad4b_developer_network_denied', 'Outbound network use requires explicit per-job network authority even in Developer Breakglass.' );
		return self::execute( 'mad4b/developer-breakglass-shell', array( $shell, '-lc', $command ), $input, true, true );
	}

	public static function breakglass_wp_eval( $input ) {
		$gate = self::runtime_gate( true, $input );
		if ( is_wp_error( $gate ) ) return $gate;
		$wp = self::wp_cli_binary();
		if ( '' === $wp ) return new WP_Error( 'mad4b_developer_wp_cli_unavailable', 'WP-CLI executable is unavailable.' );
		$code = isset( $input['code'] ) ? (string) $input['code'] : '';
		if ( '' === trim( $code ) ) return new WP_Error( 'mad4b_developer_code_required', 'PHP code is required.' );
		$network_guard = self::php_network_guard( $code, self::network_authorized( $input ) );
		if ( is_wp_error( $network_guard ) ) return $network_guard;
		return self::execute( 'mad4b/developer-breakglass-wp-eval', array( $wp, '--path=' . ABSPATH, 'eval', $code ), $input, true, true );
	}

	private static function runtime_gate( $breakglass, $input = array(), $needs_process_backend = true ) {
		$environment = self::environment();
		if ( 'production' === $environment ) return new WP_Error( 'mad4b_developer_production_denied', 'Developer execution is never authorized in Production.' );
		if ( ! in_array( $environment, array( 'staging', 'development', 'local' ), true ) ) return new WP_Error( 'mad4b_developer_environment_denied', 'Developer execution requires an explicit non-Production environment.' );
		if ( ! self::developer_flag_enabled() ) return new WP_Error( 'mad4b_developer_disabled', 'Developer Plane is disabled.' );
		if ( ! self::direct_execution_enabled() ) return new WP_Error( 'mad4b_developer_direct_execution_disabled', 'Direct developer execution backend is disabled.' );
		if ( $needs_process_backend ) {
			if ( ! function_exists( 'proc_open' ) ) return new WP_Error( 'mad4b_developer_proc_open_unavailable', 'proc_open is unavailable on this runtime.' );
			if ( '' === self::prlimit_binary() ) return new WP_Error( 'mad4b_developer_resource_limiter_unavailable', 'Developer execution requires the prlimit resource-limiter backend.' );
		}
		if ( function_exists( 'posix_geteuid' ) && 0 === (int) posix_geteuid() ) return new WP_Error( 'mad4b_developer_root_execution_denied', 'Developer execution under Unix root is forbidden.' );
		if ( $breakglass && ! self::breakglass_flag_enabled() ) return new WP_Error( 'mad4b_developer_breakglass_disabled', 'Developer Breakglass is disabled.' );
		$binding = self::runtime_binding_gate( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $binding ) ) return $binding;
		return true;
	}

	public static function kill_switch_enabled() {
		return '1' === (string) get_option( 'mad4b_scp_developer_kill_switch', '0' );
	}

	public static function developer_flag_enabled() {
		if ( self::kill_switch_enabled() ) return false;
		if ( defined( 'MAD4B_MCP_DEVELOPER_ENABLED' ) ) return true === constant( 'MAD4B_MCP_DEVELOPER_ENABLED' );
		return '1' === (string) get_option( 'mad4b_scp_developer_enabled', '0' );
	}

	public static function direct_execution_enabled() {
		if ( self::kill_switch_enabled() ) return false;
		if ( defined( 'MAD4B_MCP_DEVELOPER_DIRECT_EXECUTION_ENABLED' ) ) return true === constant( 'MAD4B_MCP_DEVELOPER_DIRECT_EXECUTION_ENABLED' );
		return '1' === (string) get_option( 'mad4b_scp_developer_direct_execution_enabled', '0' );
	}

	public static function breakglass_flag_enabled() {
		if ( self::kill_switch_enabled() || ! self::developer_flag_enabled() ) return false;
		$environment = self::environment();
		if ( ! in_array( $environment, array( 'staging', 'development', 'local' ), true ) ) return false;
		return defined( 'MAD4B_MCP_DEVELOPER_BREAKGLASS_ENABLED' )
			? true === constant( 'MAD4B_MCP_DEVELOPER_BREAKGLASS_ENABLED' )
			: '1' === (string) get_option( 'mad4b_scp_developer_breakglass_enabled', '0' );
	}

	public static function configured_agent_public_id() {
		$value = defined( 'MAD4B_MCP_DEVELOPER_AGENT_PUBLIC_ID' )
			? strtolower( trim( (string) constant( 'MAD4B_MCP_DEVELOPER_AGENT_PUBLIC_ID' ) ) )
			: strtolower( trim( (string) get_option( 'mad4b_scp_developer_agent_public_id', '' ) ) );
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $value ) ? $value : '';
	}

	public static function configuration_source() {
		return array(
			'enabled' => defined( 'MAD4B_MCP_DEVELOPER_ENABLED' ) ? 'constant' : 'option',
			'direct_execution' => defined( 'MAD4B_MCP_DEVELOPER_DIRECT_EXECUTION_ENABLED' ) ? 'constant' : 'option',
			'agent_public_id' => defined( 'MAD4B_MCP_DEVELOPER_AGENT_PUBLIC_ID' ) ? 'constant' : 'option',
			'breakglass' => defined( 'MAD4B_MCP_DEVELOPER_BREAKGLASS_ENABLED' ) ? 'dedicated_constant' : 'dedicated_managed_option',
			'kill_switch' => 'option',
		);
	}

	private static function environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function wp_cli_binary() {
		$candidates = array();
		if ( defined( 'MAD4B_MCP_WP_CLI_BIN' ) ) $candidates[] = (string) constant( 'MAD4B_MCP_WP_CLI_BIN' );
		$candidates = array_merge( $candidates, array( '/usr/local/bin/wp', '/usr/bin/wp', '/bin/wp' ) );
		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate && is_file( $candidate ) && is_executable( $candidate ) ) return $candidate;
		}
		return '';
	}

	private static function shell_binary() {
		$candidates = array( '/bin/bash', '/bin/sh' );
		foreach ( $candidates as $candidate ) if ( is_file( $candidate ) && is_executable( $candidate ) ) return $candidate;
		return '';
	}

	private static function prlimit_binary() {
		$candidates = array();
		if ( defined( 'MAD4B_MCP_DEVELOPER_PRLIMIT_BIN' ) ) $candidates[] = (string) constant( 'MAD4B_MCP_DEVELOPER_PRLIMIT_BIN' );
		$candidates = array_merge( $candidates, array( '/usr/bin/prlimit', '/bin/prlimit' ) );
		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate && is_file( $candidate ) && is_executable( $candidate ) ) return $candidate;
		}
		return '';
	}

	private static function network_sandbox_binary() {
		$candidates = array();
		if ( defined( 'MAD4B_MCP_DEVELOPER_NETWORK_SANDBOX_BIN' ) ) $candidates[] = (string) constant( 'MAD4B_MCP_DEVELOPER_NETWORK_SANDBOX_BIN' );
		$candidates = array_merge( $candidates, array( '/usr/bin/bwrap', '/bin/bwrap', '/usr/bin/unshare', '/bin/unshare' ) );
		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate && is_file( $candidate ) && is_executable( $candidate ) ) return $candidate;
		}
		return '';
	}

	private static function network_sandbox_type() {
		$binary = self::network_sandbox_binary();
		if ( '' === $binary ) return '';
		$name = strtolower( basename( $binary ) );
		if ( 'bwrap' === $name ) return 'bubblewrap';
		if ( 'unshare' === $name ) return 'unshare-net';
		return 'configured';
	}

	private static function bounded_execution_argv( array $argv, $cwd, $timeout, $network_authorized ) {
		$prlimit = self::prlimit_binary();
		if ( '' === $prlimit ) return new WP_Error( 'mad4b_developer_resource_limiter_unavailable', 'Developer execution requires prlimit.' );

		$wrapped = array(
			$prlimit,
			'--as=' . (string) self::DEFAULT_MEMORY_LIMIT_BYTES . ':' . (string) self::DEFAULT_MEMORY_LIMIT_BYTES,
			'--cpu=' . (string) max( 1, (int) $timeout + 1 ) . ':' . (string) max( 1, (int) $timeout + 1 ),
			'--nofile=' . (string) self::MAX_OPEN_FILES . ':' . (string) self::MAX_OPEN_FILES,
			'--nproc=' . (string) self::MAX_PROCESSES . ':' . (string) self::MAX_PROCESSES,
			'--',
		);

		if ( ! $network_authorized ) {
			$sandbox = self::network_sandbox_binary();
			if ( '' === $sandbox ) return new WP_Error( 'mad4b_developer_network_isolation_unavailable', 'No-network Developer execution requires an OS network sandbox backend.' );
			$type = self::network_sandbox_type();
			if ( 'bubblewrap' === $type ) {
				$wrapped = array_merge( $wrapped, array( $sandbox, '--unshare-net', '--die-with-parent', '--bind', '/', '/', '--chdir', (string) $cwd, '--' ) );
			} elseif ( 'unshare-net' === $type ) {
				$wrapped = array_merge( $wrapped, array( $sandbox, '--net', '--fork', '--' ) );
			} else {
				return new WP_Error( 'mad4b_developer_network_sandbox_unknown', 'Configured network sandbox backend is not a certified MAD4B backend.' );
			}
		}
		return array_merge( $wrapped, array_values( array_map( 'strval', $argv ) ) );
	}

	private static function normal_wp_cli_guard( array $args ) {
		$normalized = array_values( array_map( 'strval', $args ) );
		$positionals = array();
		foreach ( $normalized as $arg ) {
			if ( false !== strpos( $arg, "\0" ) ) return new WP_Error( 'mad4b_developer_argument_invalid', 'NUL bytes are forbidden.' );
			$lower = strtolower( trim( $arg ) );
			if ( '' === $lower ) continue;
			if ( 0 === strpos( $lower, '@' ) ) return new WP_Error( 'mad4b_developer_wp_cli_alias_denied', 'WP-CLI aliases are denied on the normal Developer Plane.' );
			foreach ( array( '--exec', '--require', '--ssh', '--http', '--path' ) as $prefix ) {
				if ( 0 === strpos( $lower, $prefix ) ) return new WP_Error( 'mad4b_developer_wp_cli_escape_denied', 'This WP-CLI execution, remote-bootstrap, or path-override flag requires Developer Breakglass.' );
			}
			if ( '-' !== $lower[0] ) $positionals[] = $lower;
		}
		if ( empty( $positionals ) ) return new WP_Error( 'mad4b_developer_wp_cli_command_required', 'A concrete WP-CLI command is required.' );

		$command = $positionals[0];
		$subcommand = isset( $positionals[1] ) ? $positionals[1] : '';
		$allow = array(
			'plugin' => array( 'list', 'status', 'get', 'is-active', 'is-installed', 'path', 'verify-checksums', 'search' ),
			'theme'  => array( 'list', 'status', 'get', 'is-active', 'is-installed', 'path', 'verify-checksums', 'search' ),
			'core'   => array( 'version', 'check-update', 'verify-checksums', 'is-installed' ),
		);
		if ( ! isset( $allow[ $command ] ) ) {
			return new WP_Error( 'mad4b_developer_wp_cli_command_not_allowlisted', 'Normal Developer WP-CLI is a finite read/verification surface. Other command families require a dedicated governed ability or Developer Breakglass.' );
		}
		if ( '' === $subcommand || ! in_array( $subcommand, $allow[ $command ], true ) ) {
			return new WP_Error( 'mad4b_developer_wp_cli_subcommand_not_allowlisted', 'This WP-CLI subcommand is not in the normal Developer read/verification allowlist.' );
		}
		return true;
	}

	private static function normal_shell_guard( $command, $network_authorized = false ) {
		$lower = strtolower( (string) $command );
		$always_denied = array(
			'/wp-config', 'wp-config.php', '/.env', ' .env', '/.ssh/', 'id_rsa', 'id_ed25519',
			'printenv', '/proc/self/environ', '/proc/1/environ', 'sudo ', ' su ', 'passwd ',
		);
		foreach ( $always_denied as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) return new WP_Error( 'mad4b_developer_shell_policy_denied', 'Normal Developer Shell denied a secret or privilege-escalation pattern.' );
		}
		if ( ! $network_authorized && self::shell_may_use_network( $command ) ) return new WP_Error( 'mad4b_developer_network_denied', 'Normal Developer Shell denies outbound network use unless this exact job carries explicit network authority.' );
		return true;
	}

	private static function shell_may_use_network( $command ) {
		$lower = strtolower( (string) $command );
		foreach ( array( 'curl ', 'wget ', 'nc ', 'netcat ', 'ssh ', 'scp ', 'sftp ', 'ftp ', 'telnet ', 'openssl s_client', 'composer require ', 'composer update ', 'npm install ', 'pnpm install ', 'yarn add ' ) as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) return true;
		}
		return false;
	}

	private static function php_network_guard( $code, $network_authorized = false ) {
		if ( $network_authorized ) return true;
		$lower = strtolower( (string) $code );
		foreach ( array( 'wp_remote_', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect', 'https://', 'http://' ) as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) return new WP_Error( 'mad4b_developer_network_denied', 'Developer PHP/WP eval denies outbound network use unless this exact job carries explicit network authority.' );
		}
		return true;
	}

	private static function wp_cli_may_use_network( array $args ) {
		$joined = strtolower( implode( ' ', array_map( 'strval', $args ) ) );
		foreach ( array( 'plugin install', 'plugin update', 'plugin search', 'plugin verify-checksums', 'theme install', 'theme update', 'theme search', 'theme verify-checksums', 'core download', 'core update', 'core check-update', 'core verify-checksums', 'package install', 'package update', 'cli update', 'language core install', 'language plugin install', 'language theme install' ) as $needle ) {
			if ( false !== strpos( $joined, $needle ) ) return true;
		}
		return false;
	}

	private static function network_authorized( $input ) {
		return is_array( $input )
			&& ! empty( $input['allow_network'] )
			&& defined( 'MAD4B_MCP_DEVELOPER_NETWORK_ENABLED' )
			&& true === constant( 'MAD4B_MCP_DEVELOPER_NETWORK_ENABLED' );
	}

	private static function runtime_binding_gate( array $input ) {
		$expected_sha = isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '';
		$expected_site = isset( $input['expected_site_uuid'] ) ? strtolower( trim( (string) $input['expected_site_uuid'] ) ) : '';
		$expected_env = isset( $input['expected_environment'] ) ? sanitize_key( (string) $input['expected_environment'] ) : '';
		$current_sha = self::current_source_commit_sha();
		$current_site = class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'site_uuid' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		$current_env = self::environment();
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $expected_sha ) || 1 !== preg_match( '/^[a-f0-9]{40}$/', $current_sha ) || ! hash_equals( $current_sha, $expected_sha ) ) return new WP_Error( 'mad4b_developer_source_binding_mismatch', 'Developer job source commit does not match the loaded exact package.' );
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $expected_site ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/', $current_site ) || ! hash_equals( $current_site, $expected_site ) ) return new WP_Error( 'mad4b_developer_site_binding_mismatch', 'Developer job site UUID does not match the enrolled site.' );
		if ( $expected_env !== $current_env ) return new WP_Error( 'mad4b_developer_environment_binding_mismatch', 'Developer job environment does not match the live runtime.' );
		return true;
	}

	private static function current_source_commit_sha() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) return '';
		$raw = file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$sha = is_array( $data ) && isset( $data['source_commit_sha'] ) ? strtolower( trim( (string) $data['source_commit_sha'] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{40}$/', $sha ) ? $sha : '';
	}

	private static function working_dir( $input ) {
		$requested = isset( $input['working_dir'] ) ? trim( (string) $input['working_dir'] ) : '';
		if ( '' === $requested ) return ABSPATH;
		if ( false !== strpos( $requested, "\0" ) ) return new WP_Error( 'mad4b_developer_working_dir_invalid', 'NUL bytes are denied in working_dir.' );
		$normalized_requested = str_replace( '\\', '/', $requested );
		foreach ( explode( '/', $normalized_requested ) as $segment ) if ( '..' === $segment ) return new WP_Error( 'mad4b_developer_working_dir_invalid', 'Working directory traversal is denied.' );
		if ( preg_match( '#^(?:[A-Za-z]:[\\/]|/)#', $requested ) ) return new WP_Error( 'mad4b_developer_working_dir_absolute_denied', 'working_dir must be relative to the WordPress root.' );
		$candidate = realpath( trailingslashit( ABSPATH ) . $requested );
		$root = realpath( ABSPATH );
		if ( false === $candidate || false === $root || ! is_dir( $candidate ) ) return new WP_Error( 'mad4b_developer_working_dir_missing', 'Working directory does not exist.' );
		$root_n = rtrim( str_replace( '\\', '/', $root ), '/' );
		$candidate_n = str_replace( '\\', '/', $candidate );
		if ( $candidate_n !== $root_n && 0 !== strpos( $candidate_n, $root_n . '/' ) ) return new WP_Error( 'mad4b_developer_working_dir_escape', 'Working directory escapes the WordPress root.' );
		return $candidate;
	}

	private static function isolated_wp_cli_cwd() {
		$base = function_exists( 'sys_get_temp_dir' ) ? realpath( sys_get_temp_dir() ) : false;
		if ( false === $base || ! is_dir( $base ) || ! is_writable( $base ) ) return new WP_Error( 'mad4b_developer_wp_cli_isolated_cwd_unavailable', 'Normal Developer WP-CLI requires a writable system temporary directory outside the WordPress tree.' );
		$cwd = trailingslashit( $base ) . 'mad4b-developer-wp-cli';
		if ( ! is_dir( $cwd ) && ! wp_mkdir_p( $cwd ) ) return new WP_Error( 'mad4b_developer_wp_cli_isolated_cwd_create_failed', 'Normal Developer WP-CLI could not create its isolated working directory.' );
		$resolved = realpath( $cwd );
		$root = realpath( ABSPATH );
		if ( false === $resolved || false === $root || ! is_dir( $resolved ) ) return new WP_Error( 'mad4b_developer_wp_cli_isolated_cwd_invalid', 'Normal Developer WP-CLI isolated working directory is invalid.' );
		$resolved_n = rtrim( str_replace( '\\', '/', $resolved ), '/' );
		$root_n = rtrim( str_replace( '\\', '/', $root ), '/' );
		if ( $resolved_n === $root_n || 0 === strpos( $resolved_n, $root_n . '/' ) ) return new WP_Error( 'mad4b_developer_wp_cli_isolated_cwd_inside_wordpress', 'Normal Developer WP-CLI isolated working directory must be outside the WordPress tree.' );
		@chmod( $resolved, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $resolved;
	}

	private static function execute( $ability, array $argv, $input, $breakglass, $mutation_assumed, $isolated_wp_cli = false ) {
		$cwd = $isolated_wp_cli ? self::isolated_wp_cli_cwd() : self::working_dir( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $cwd ) ) return $cwd;
		$timeout = isset( $input['timeout_seconds'] ) ? absint( $input['timeout_seconds'] ) : self::DEFAULT_TIMEOUT;
		$timeout = max( 1, min( self::MAX_TIMEOUT, $timeout ) );
		$started = microtime( true );
		$started_at = gmdate( 'c' );
		$network_authorized = self::network_authorized( is_array( $input ) ? $input : array() );
		$bounded_argv = self::bounded_execution_argv( $argv, $cwd, $timeout, $network_authorized );
		if ( is_wp_error( $bounded_argv ) ) return $bounded_argv;
		$descriptor = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$env = self::sanitized_env( $isolated_wp_cli );
		$process = @proc_open( $bounded_argv, $descriptor, $pipes, $cwd, $env, array( 'bypass_shell' => true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_resource( $process ) ) return new WP_Error( 'mad4b_developer_process_start_failed', 'Developer subprocess could not be started.' );
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$stdout = '';
		$stderr = '';
		$timed_out = false;
		while ( true ) {
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );
			if ( strlen( $stdout ) > self::MAX_OUTPUT_BYTES || strlen( $stderr ) > self::MAX_OUTPUT_BYTES ) {
				@proc_terminate( $process, 9 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				fclose( $pipes[1] ); fclose( $pipes[2] ); @proc_close( $process ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'mad4b_developer_output_limit_exceeded', 'Developer subprocess exceeded the bounded output limit.' );
			}
			$status = proc_get_status( $process );
			if ( empty( $status['running'] ) ) break;
			if ( microtime( true ) - $started >= $timeout ) {
				$timed_out = true;
				@proc_terminate( $process ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				usleep( 200000 );
				$status = proc_get_status( $process );
				if ( ! empty( $status['running'] ) ) @proc_terminate( $process, 9 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			}
			usleep( 50000 );
		}
		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		$status = proc_get_status( $process );
		$exit = isset( $status['exitcode'] ) && $status['exitcode'] >= 0 ? (int) $status['exitcode'] : -1;
		$closed = @proc_close( $process ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $exit < 0 && is_int( $closed ) ) $exit = $closed;
		if ( $timed_out ) $exit = 124;
		$receipt = array(
			'contract' => self::RECEIPT_CONTRACT,
			'ability' => (string) $ability,
			'environment' => self::environment(),
			'breakglass' => (bool) $breakglass,
			'production_allowed' => false,
			'command_digest' => self::argv_digest( $argv ),
			'resource_limit_backend' => 'prlimit',
			'network_authorized' => (bool) $network_authorized,
			'network_sandbox' => $network_authorized ? 'none_explicitly_authorized' : self::network_sandbox_type(),
			'exit_code' => $exit,
			'timed_out' => $timed_out,
			'timeout_seconds' => $timeout,
			'stdout' => self::redact( $stdout ),
			'stderr' => self::redact( $stderr ),
			'stdout_digest' => hash( 'sha256', $stdout ),
			'stderr_digest' => hash( 'sha256', $stderr ),
			'started_at' => $started_at,
			'completed_at' => gmdate( 'c' ),
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'mutation_detected' => (bool) $mutation_assumed,
			'secret_values_returned' => false,
		);
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/developer-execution', array_diff_key( $receipt, array( 'stdout' => true, 'stderr' => true ) ), 0 === $exit ? 'ok' : 'error' );
		}
		return $receipt;
	}

	private static function filesystem_receipt( $action, $path, $ok, $mutation, $sha = '' ) {
		$receipt = array(
			'contract' => self::RECEIPT_CONTRACT,
			'ability' => 'mad4b/developer-filesystem',
			'action' => (string) $action,
			'path_digest' => hash( 'sha256', str_replace( '\\', '/', (string) $path ) ),
			'ok' => (bool) $ok,
			'mutation_detected' => (bool) $mutation,
			'secret_values_returned' => false,
			'sha256' => (string) $sha,
			'completed_at' => gmdate( 'c' ),
		);
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::record( 'mad4b/developer-execution', $receipt, $ok ? 'ok' : 'error' );
		return $receipt;
	}

	private static function sanitized_env( $isolated_wp_cli = false ) {
		$env = array();
		foreach ( array( 'PATH', 'HOME', 'TMPDIR', 'TEMP', 'TMP', 'LANG', 'LC_ALL' ) as $key ) {
			$value = getenv( $key );
			if ( false !== $value && '' !== (string) $value ) $env[ $key ] = (string) $value;
		}
		$env['MAD4B_DEVELOPER_EXECUTION'] = '1';
		if ( $isolated_wp_cli ) {
			$null = defined( 'PHP_OS_FAMILY' ) && 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null';
			$env['WP_CLI_CONFIG_PATH'] = $null;
			$env['WP_CLI_SYSTEM_SETTINGS_PATH'] = $null;
			$env['WP_CLI_DISABLE_AUTO_CHECK_UPDATE'] = '1';
		}
		return $env;
	}

	private static function argv_digest( array $argv ) {
		$normalized = array_map( 'strval', $argv );
		$json = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	private static function redact( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/(?i)(password|passwd|secret|token|api[_-]?key|client[_-]?secret|consumer[_-]?secret|authorization)\s*[:=]\s*([^\s\r\n]+)/', '$1=[REDACTED]', $text );
		$text = preg_replace( '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', '[REDACTED_JWT]', $text );
		$text = preg_replace( "/(?i)define\s*\(\s*['\"](?:DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", 'define([REDACTED])', $text );
		if ( strlen( $text ) > self::MAX_OUTPUT_BYTES ) $text = substr( $text, 0, self::MAX_OUTPUT_BYTES ) . "\n[TRUNCATED]";
		return $text;
	}
}

MAD4B_SCP_Developer_Runtime::boot();
