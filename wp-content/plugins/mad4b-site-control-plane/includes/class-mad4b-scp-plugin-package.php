<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed certified plugin-package install/replace surface.
 *
 * Authority is repository/provider-contract derived. Callers cannot supply
 * arbitrary package URLs, filesystem paths, versions, hashes, or plugin files.
 * The mutation is plan-bound, Staging-only by default, preserves activation
 * state, creates an out-of-webroot backup, verifies exact archive SHA-256 and
 * certified critical-file hashes, and rolls back on same-request disk readback
 * failure.
 */
final class MAD4B_SCP_Plugin_Package {
	const PLAN_CONTRACT  = 'mad4b.plugin-package-plan.v1';
	const APPLY_CONTRACT = 'mad4b.plugin-package-apply.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		if ( ! wp_has_ability( 'mad4b/plugin-package-plan' ) ) {
			wp_register_ability(
				'mad4b/plugin-package-plan',
				array(
					'label' => 'Plan Certified Plugin Package Install or Replace',
					'description' => 'Read-only exact-artifact preflight for a repository-certified plugin package.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'plan' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => self::plan_schema(),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
						'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					),
				)
			);
		}

		if ( ! wp_has_ability( 'mad4b/plugin-package-apply' ) ) {
			wp_register_ability(
				'mad4b/plugin-package-apply',
				array(
					'label' => 'Apply Certified Plugin Package Install or Replace',
					'description' => 'Governed Staging install/replace of an exact repository-certified plugin package with rollback.',
					'category' => 'mad4b-admin',
					'execute_callback' => array( __CLASS__, 'apply' ),
					'permission_callback' => array( __CLASS__, 'can_apply' ),
					'input_schema' => self::apply_schema(),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write' ),
						'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					),
				)
			);
		}
	}

	public static function can_apply( $input = null ) {
		$granted = MAD4B_SCP_Policy::can_admin();
		if ( is_wp_error( $granted ) || ! $granted ) return $granted;
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled.' );
		if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
		return MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/plugin-package-apply', 'mad4b-admin', 'core', is_array( $input ) ? $input : array() );
	}

	public static function plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$provider = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		$component = isset( $input['component'] ) ? sanitize_key( (string) $input['component'] ) : '';
		$source = isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : 'wordpress_update_offer';
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';

		$authority = self::authority( $provider, $component );
		if ( is_wp_error( $authority ) ) return $authority;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$plugin_file = $authority['plugin_file'];
		$installed = isset( $plugins[ $plugin_file ] );
		$current_version = $installed && isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '';
		$current_active = $installed ? is_plugin_active( $plugin_file ) : false;
		$current_network_active = $installed && is_multisite() ? is_plugin_active_for_network( $plugin_file ) : false;
		$blockers = array();

		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' ) ) {
			$blockers[] = 'staging_enrolled_write_profile_required';
		}
		if ( self::protected_plugin( $plugin_file ) ) $blockers[] = 'protected_control_plane_dependency';
		if ( $installed && hash_equals( $authority['version'], $current_version ) ) $blockers[] = 'already_on_certified_target_version';
		if ( $installed && '' !== $current_version && version_compare( $current_version, $authority['version'], '>' ) ) $blockers[] = 'certified_target_is_older_than_runtime';
		if ( ! $installed && ! current_user_can( 'install_plugins' ) ) $blockers[] = 'install_plugins_capability_required';
		if ( $installed && ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';

		$source_state = self::source_state( $source, $plugin_file, $authority );
		if ( is_wp_error( $source_state ) ) {
			$blockers[] = $source_state->get_error_code();
			$source_state = array(
				'source' => $source,
				'available' => false,
				'package_sha256_verified' => false,
				'caller_supplied_location_allowed' => false,
			);
		} elseif ( empty( $source_state['available'] ) ) {
			$blockers[] = 'certified_package_source_unavailable';
		}

		$state_payload = array(
			'plugin_file' => $plugin_file,
			'installed' => $installed,
			'current_version' => $current_version,
			'current_active' => (bool) $current_active,
			'current_network_active' => (bool) $current_network_active,
		);
		$state_sha = self::digest( $state_payload );

		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'provider_id' => $provider,
			'component' => $component,
			'operation' => $installed ? 'replace' : 'install',
			'plugin_file' => $plugin_file,
			'archive' => $authority['archive'],
			'current_version' => $current_version,
			'target_version' => $authority['version'],
			'certified_archive_sha256' => $authority['archive_sha256'],
			'critical_file_count' => count( $authority['critical_files'] ),
			'state_sha256' => $state_sha,
			'current_active' => (bool) $current_active,
			'current_network_active' => (bool) $current_network_active,
			'source' => $source_state,
			'caller_supplied_url_allowed' => false,
			'caller_supplied_path_allowed' => false,
			'production_allowed' => false,
			'backup_required' => $installed,
			'activation_state_preserved' => true,
			'rollback_on_failed_disk_readback' => true,
			'separate_runtime_readback_required' => true,
			'eligible' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'reason' => $reason,
			'mutation_performed' => false,
			'authority_created' => false,
			'authorizing' => false,
		);
		sort( $plan['blockers'], SORT_STRING );
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['write_binding'] = array( 'expected_plan_sha256' => $plan['plan_sha256'] );
		return $plan;
	}

	public static function apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected_plan = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_plan ) ) return new WP_Error( 'mad4b_plugin_package_plan_digest_required', 'expected_plan_sha256 from a reviewed plugin package plan is required.' );

		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( ! hash_equals( $plan['plan_sha256'], $expected_plan ) ) {
			return new WP_Error( 'mad4b_plugin_package_plan_changed', 'Plugin package plan changed since review.', array( 'current_plan_sha256' => $plan['plan_sha256'], 'expected_plan_sha256' => $expected_plan ) );
		}
		if ( empty( $plan['eligible'] ) ) return new WP_Error( 'mad4b_plugin_package_preflight_blocked', 'Plugin package preflight blocked the mutation.', array( 'blockers' => $plan['blockers'] ) );

		$provider = sanitize_key( (string) $input['provider_id'] );
		$component = isset( $input['component'] ) ? sanitize_key( (string) $input['component'] ) : '';
		$source = isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : 'wordpress_update_offer';
		$authority = self::authority( $provider, $component );
		if ( is_wp_error( $authority ) ) return $authority;

		$package = self::materialize_package( $source, $authority['plugin_file'], $authority );
		if ( is_wp_error( $package ) ) return $package;

		$before = self::disk_state( $authority['plugin_file'] );
		if ( is_wp_error( $before ) && 'install' !== $plan['operation'] ) {
			self::cleanup_package( $package );
			return $before;
		}

		$backup = 'install' === $plan['operation'] ? array( 'backup_id' => '', 'backup_path' => '', 'created' => false ) : self::backup_plugin( $authority['plugin_file'] );
		if ( is_wp_error( $backup ) ) {
			self::cleanup_package( $package );
			return $backup;
		}

		$install = self::install_package( $package['path'] );
		self::cleanup_package( $package );
		if ( is_wp_error( $install ) ) {
			self::rollback( $authority['plugin_file'], $backup, $before );
			MAD4B_SCP_Audit::record( 'mad4b/plugin-package-apply', array( 'provider_id' => $provider, 'component' => $component, 'plugin' => $authority['plugin_file'], 'target_version' => $authority['version'], 'plan_sha256' => $expected_plan, 'backup_id' => isset( $backup['backup_id'] ) ? $backup['backup_id'] : '', 'readback_verified' => false, 'rollback_attempted' => true ), 'failure' );
			return $install;
		}

		$activation = self::restore_activation_state( $authority['plugin_file'], is_array( $before ) ? $before : array() );
		if ( is_wp_error( $activation ) ) {
			self::rollback( $authority['plugin_file'], $backup, $before );
			return $activation;
		}

		$readback = self::verify_disk_readback( $authority );
		if ( is_wp_error( $readback ) ) {
			$rollback = self::rollback( $authority['plugin_file'], $backup, $before );
			MAD4B_SCP_Audit::record( 'mad4b/plugin-package-apply', array( 'provider_id' => $provider, 'component' => $component, 'plugin' => $authority['plugin_file'], 'target_version' => $authority['version'], 'plan_sha256' => $expected_plan, 'backup_id' => isset( $backup['backup_id'] ) ? $backup['backup_id'] : '', 'readback_verified' => false, 'rollback_attempted' => true, 'rollback_ok' => ! is_wp_error( $rollback ) ), 'failure' );
			return new WP_Error( 'mad4b_plugin_package_readback_failed_rolled_back', 'Certified plugin package disk readback failed; previous plugin files were restored when available.', array( 'readback_error' => $readback->get_error_code(), 'rollback_ok' => ! is_wp_error( $rollback ) ) );
		}

		MAD4B_SCP_Audit::record(
			'mad4b/plugin-package-apply',
			array(
				'provider_id' => $provider,
				'component' => $component,
				'plugin' => $authority['plugin_file'],
				'before_version' => is_array( $before ) && isset( $before['version'] ) ? $before['version'] : '',
				'after_version' => $readback['version'],
				'package_sha256' => $package['sha256'],
				'plan_sha256' => $expected_plan,
				'backup_id' => isset( $backup['backup_id'] ) ? $backup['backup_id'] : '',
				'readback_verified' => true,
				'activation_state_preserved' => true,
			)
		);

		return array(
			'contract' => self::APPLY_CONTRACT,
			'provider_id' => $provider,
			'component' => $component,
			'plugin_file' => $authority['plugin_file'],
			'operation' => $plan['operation'],
			'before_version' => is_array( $before ) && isset( $before['version'] ) ? $before['version'] : '',
			'after_version' => $readback['version'],
			'certified_archive_sha256' => $authority['archive_sha256'],
			'package_sha256' => $package['sha256'],
			'plan_sha256' => $expected_plan,
			'backup_id' => isset( $backup['backup_id'] ) ? $backup['backup_id'] : '',
			'readback_verified' => true,
			'activation_state_preserved' => true,
			'runtime_reboot_required' => true,
			'next_readback' => array( 'wp-import-export/status', 'mad4b/provider-capability-certification', 'mad4b/provider-mcp-mount-plan', 'mad4b/runtime-self-test' ),
			'authorizing' => false,
			'authority_created' => false,
		);
	}

	private static function authority( $provider, $component ) {
		if ( '' === $provider || ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return new WP_Error( 'mad4b_plugin_package_provider_required', 'A cataloged provider_id is required.' );
		$contract = MAD4B_SCP_Provider_Contracts::get( $provider );
		if ( ! is_array( $contract ) || empty( $contract ) ) return new WP_Error( 'mad4b_plugin_package_provider_uncertified', 'Provider has no repository certification authority.' );

		$authority = $contract;
		if ( '' !== $component ) {
			if ( empty( $contract['components'][ $component ] ) || ! is_array( $contract['components'][ $component ] ) ) return new WP_Error( 'mad4b_plugin_package_component_uncertified', 'Requested provider component has no certification authority.' );
			$authority = $contract['components'][ $component ];
		}
		$plugin_file = isset( $authority['plugin_file'] ) ? wp_normalize_path( (string) $authority['plugin_file'] ) : '';
		$version = isset( $authority['version'] ) ? trim( (string) $authority['version'] ) : '';
		$archive = isset( $authority['archive'] ) ? basename( (string) $authority['archive'] ) : '';
		$sha = isset( $authority['archive_sha256'] ) ? strtolower( trim( (string) $authority['archive_sha256'] ) ) : '';
		$critical = isset( $authority['critical_files'] ) && is_array( $authority['critical_files'] ) ? $authority['critical_files'] : array();
		if ( '' === $plugin_file || '.' === dirname( $plugin_file ) || '' === dirname( $plugin_file ) || '' === $version || '' === $archive || ! preg_match( '/^[a-f0-9]{64}$/', $sha ) || empty( $critical ) ) {
			return new WP_Error( 'mad4b_plugin_package_authority_incomplete', 'Certified provider package authority is incomplete.' );
		}
		return array(
			'provider_id' => $provider,
			'component' => $component,
			'plugin_file' => ltrim( $plugin_file, '/' ),
			'version' => $version,
			'archive' => $archive,
			'archive_sha256' => $sha,
			'critical_files' => $critical,
		);
	}

	private static function source_state( $source, $plugin_file, array $authority ) {
		if ( 'certified_local_archive' === $source ) {
			$path = self::local_archive_path( $authority['archive'] );
			if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'certified_local_archive_unavailable', 'Certified local archive is unavailable.' );
			$sha = hash_file( 'sha256', $path );
			return array(
				'source' => $source,
				'available' => is_string( $sha ) && hash_equals( $authority['archive_sha256'], strtolower( $sha ) ),
				'package_sha256_verified' => is_string( $sha ) && hash_equals( $authority['archive_sha256'], strtolower( $sha ) ),
				'archive' => $authority['archive'],
				'caller_supplied_location_allowed' => false,
			);
		}
		if ( 'wordpress_update_offer' !== $source ) return new WP_Error( 'plugin_package_source_not_allowed', 'Only server-resolved certified_local_archive or wordpress_update_offer sources are allowed.' );
		$offer = self::update_offer( $plugin_file );
		if ( empty( $offer ) ) return new WP_Error( 'wordpress_update_offer_unavailable', 'WordPress has no update offer for the certified plugin file.' );
		$offered_version = isset( $offer->new_version ) ? trim( (string) $offer->new_version ) : '';
		$package = isset( $offer->package ) ? trim( (string) $offer->package ) : '';
		return array(
			'source' => $source,
			'available' => '' !== $package && hash_equals( $authority['version'], $offered_version ),
			'offered_version' => $offered_version,
			'target_version_match' => hash_equals( $authority['version'], $offered_version ),
			'package_download_available' => '' !== $package,
			'package_sha256_verified' => false,
			'package_sha256_verified_at_apply' => true,
			'caller_supplied_location_allowed' => false,
		);
	}

	private static function materialize_package( $source, $plugin_file, array $authority ) {
		if ( 'certified_local_archive' === $source ) {
			$path = self::local_archive_path( $authority['archive'] );
			if ( '' === $path || ! is_file( $path ) ) return new WP_Error( 'mad4b_certified_local_archive_missing', 'Certified local archive is missing.' );
			$sha = strtolower( (string) hash_file( 'sha256', $path ) );
			if ( ! hash_equals( $authority['archive_sha256'], $sha ) ) return new WP_Error( 'mad4b_certified_archive_hash_mismatch', 'Certified local archive SHA-256 does not match repository authority.' );
			return array( 'path' => $path, 'sha256' => $sha, 'temporary' => false );
		}

		$offer = self::update_offer( $plugin_file );
		if ( empty( $offer ) || empty( $offer->package ) || empty( $offer->new_version ) || ! hash_equals( $authority['version'], (string) $offer->new_version ) ) {
			return new WP_Error( 'mad4b_wordpress_update_offer_changed', 'WordPress update offer no longer matches the certified target version.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( (string) $offer->package, 300 );
		if ( is_wp_error( $tmp ) ) return new WP_Error( 'mad4b_plugin_package_download_failed', 'Server-resolved WordPress update package download failed.' );
		$sha = is_file( $tmp ) ? strtolower( (string) hash_file( 'sha256', $tmp ) ) : '';
		if ( '' === $sha || ! hash_equals( $authority['archive_sha256'], $sha ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_plugin_package_download_hash_mismatch', 'Downloaded update package does not match the exact certified archive SHA-256.' );
		}
		return array( 'path' => $tmp, 'sha256' => $sha, 'temporary' => true );
	}

	private static function update_offer( $plugin_file ) {
		$updates = get_site_transient( 'update_plugins' );
		return is_object( $updates ) && isset( $updates->response[ $plugin_file ] ) && is_object( $updates->response[ $plugin_file ] ) ? $updates->response[ $plugin_file ] : null;
	}

	private static function local_archive_path( $archive ) {
		$candidates = array(
			trailingslashit( WP_PLUGIN_DIR ) . basename( $archive ),
			trailingslashit( get_temp_dir() ) . 'mad4b-certified-plugin-packages/' . basename( $archive ),
		);
		$filtered = apply_filters( 'mad4b_scp_certified_plugin_package_paths', $candidates, basename( $archive ) );
		if ( is_array( $filtered ) ) $candidates = $filtered;
		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) continue;
			$real = realpath( $candidate );
			if ( false !== $real && is_file( $real ) && is_readable( $real ) ) return $real;
		}
		return '';
	}

	private static function backup_plugin( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$source = self::plugin_root( $plugin_file );
		if ( '' === $source || ! file_exists( $source ) ) return new WP_Error( 'mad4b_plugin_package_backup_source_missing', 'Installed plugin directory is unavailable for backup.' );
		$root = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $root ) ) return $root;
		$backup_id = 'plugin-package-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$destination = trailingslashit( $root ) . $backup_id;
		if ( ! WP_Filesystem() ) return new WP_Error( 'mad4b_plugin_package_filesystem_unavailable', 'WordPress filesystem abstraction is unavailable.' );
		$result = copy_dir( $source, $destination );
		if ( is_wp_error( $result ) ) return $result;
		return array( 'backup_id' => $backup_id, 'backup_path' => $destination, 'created' => true );
	}

	private static function install_package( $path ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = $upgrader->install( $path, array( 'overwrite_package' => true ) );
		if ( is_wp_error( $result ) ) return $result;
		if ( true !== $result ) {
			$errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
			return is_wp_error( $errors ) && $errors->has_errors() ? $errors : new WP_Error( 'mad4b_plugin_package_install_failed', 'WordPress Plugin_Upgrader did not complete the certified package install.' );
		}
		wp_clean_plugins_cache( true );
		return true;
	}

	private static function verify_disk_readback( array $authority ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_clean_plugins_cache( true );
		$plugins = get_plugins();
		$file = $authority['plugin_file'];
		if ( ! isset( $plugins[ $file ] ) ) return new WP_Error( 'mad4b_plugin_package_readback_plugin_missing', 'Plugin main file is missing after install/replace.' );
		$version = isset( $plugins[ $file ]['Version'] ) ? trim( (string) $plugins[ $file ]['Version'] ) : '';
		if ( ! hash_equals( $authority['version'], $version ) ) return new WP_Error( 'mad4b_plugin_package_readback_version_mismatch', 'Installed plugin version does not match certified target.', array( 'installed_version' => $version, 'target_version' => $authority['version'] ) );
		$root = self::plugin_root( $file );
		$mismatched = array();
		foreach ( $authority['critical_files'] as $relative => $expected ) {
			$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '..' ) ) { $mismatched[ $relative ] = 'invalid_relative_path'; continue; }
			$path = trailingslashit( $root ) . $relative;
			$actual = is_file( $path ) && is_readable( $path ) ? strtolower( (string) hash_file( 'sha256', $path ) ) : '';
			if ( '' === $actual || ! hash_equals( strtolower( (string) $expected ), $actual ) ) $mismatched[ $relative ] = $actual;
		}
		if ( ! empty( $mismatched ) ) return new WP_Error( 'mad4b_plugin_package_readback_integrity_mismatch', 'Installed plugin critical files do not match certified authority.', array( 'mismatched' => $mismatched ) );
		return array( 'plugin_file' => $file, 'version' => $version, 'critical_files_verified' => count( $authority['critical_files'] ) );
	}

	private static function disk_state( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) return new WP_Error( 'mad4b_plugin_package_plugin_not_installed', 'Plugin is not currently installed.' );
		return array(
			'plugin_file' => $plugin_file,
			'version' => isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '',
			'active' => is_plugin_active( $plugin_file ),
			'network_active' => is_multisite() ? is_plugin_active_for_network( $plugin_file ) : false,
		);
	}

	private static function restore_activation_state( $plugin_file, array $before ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$should_active = ! empty( $before['active'] );
		$should_network = ! empty( $before['network_active'] );
		$current = is_plugin_active( $plugin_file );
		if ( $should_active && ! $current ) {
			$result = activate_plugin( $plugin_file, '', $should_network );
			if ( is_wp_error( $result ) ) return $result;
		} elseif ( ! $should_active && $current ) {
			deactivate_plugins( $plugin_file, false, $should_network );
		}
		return true;
	}

	private static function rollback( $plugin_file, array $backup, $before ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! WP_Filesystem() ) return new WP_Error( 'mad4b_plugin_package_rollback_filesystem_unavailable', 'WordPress filesystem abstraction is unavailable for rollback.' );
		global $wp_filesystem;
		$root = self::plugin_root( $plugin_file );
		if ( is_plugin_active( $plugin_file ) ) deactivate_plugins( $plugin_file, true, is_multisite() && is_plugin_active_for_network( $plugin_file ) );
		if ( '' !== $root && file_exists( $root ) ) $wp_filesystem->delete( $root, true );
		if ( ! empty( $backup['created'] ) && ! empty( $backup['backup_path'] ) && is_dir( $backup['backup_path'] ) ) {
			$restored = copy_dir( $backup['backup_path'], $root );
			if ( is_wp_error( $restored ) ) return $restored;
			wp_clean_plugins_cache( true );
			if ( is_array( $before ) ) {
				$activation = self::restore_activation_state( $plugin_file, $before );
				if ( is_wp_error( $activation ) ) return $activation;
			}
		}
		return true;
	}

	private static function plugin_root( $plugin_file ) {
		$plugin_file = ltrim( wp_normalize_path( (string) $plugin_file ), '/' );
		if ( '' === $plugin_file || false !== strpos( $plugin_file, '..' ) ) return '';
		$dir = dirname( $plugin_file );
		if ( '.' === $dir || '' === $dir ) return wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file );
		return wp_normalize_path( WP_PLUGIN_DIR . '/' . $dir );
	}

	private static function protected_plugin( $plugin_file ) {
		$self = plugin_basename( MAD4B_SCP_FILE );
		return $plugin_file === $self || 0 === strpos( strtolower( $plugin_file ), 'mcp-adapter/' ) || MAD4B_SCP_Policy::plugin_lifecycle_protected( $plugin_file );
	}

	private static function cleanup_package( array $package ) {
		if ( ! empty( $package['temporary'] ) && ! empty( $package['path'] ) && is_file( $package['path'] ) ) @unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	private static function plan_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'provider_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ),
				'component' => array( 'type' => 'string', 'maxLength' => 80, 'default' => '' ),
				'source' => array( 'type' => 'string', 'enum' => array( 'wordpress_update_offer', 'certified_local_archive' ), 'default' => 'wordpress_update_offer' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			'required' => array( 'provider_id', 'reason' ),
			'additionalProperties' => false,
		);
	}

	private static function apply_schema() {
		$schema = self::plan_schema();
		$schema['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 );
		$schema['required'][] = 'expected_plan_sha256';
		return $schema;
	}

	private static function digest( $value ) {
		$encoded = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
