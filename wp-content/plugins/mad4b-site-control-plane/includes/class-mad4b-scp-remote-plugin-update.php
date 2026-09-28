<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed remote update surface for any installed WordPress plugin that has a
 * live server-resolved update offer.
 *
 * Callers select only the installed plugin_file and reason. They cannot supply
 * a URL, filesystem path, target version, package hash, or package bytes.
 * Planning downloads the current WordPress update offer into temporary storage,
 * validates HTTPS/safe-URL policy, exact plugin layout/version, archive limits
 * and file hashes, then binds the resulting package SHA-256 + manifest digest
 * into a deterministic plan. Apply repeats that verification, requires the exact
 * reviewed plan, preserves activation state, creates a protected backup and
 * rolls back on install or exact readback failure.
 */
final class MAD4B_SCP_Remote_Plugin_Update {
	const PLAN_CONTRACT = 'mad4b.plugin-remote-update-plan.v1';
	const APPLY_CONTRACT = 'mad4b.plugin-remote-update-apply.v1';
	const MAX_PACKAGE_BYTES = 67108864; // 64 MiB.
	const MAX_ARCHIVE_FILES = 25000;
	const MAX_UNCOMPRESSED_BYTES = 536870912; // 512 MiB.

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/plugin-remote-update-plan' ) ) {
			wp_register_ability(
				'mad4b/plugin-remote-update-plan',
				array(
					'label' => 'Plan Governed Remote Plugin Update',
					'description' => 'Read-only exact-artifact plan for updating any installed plugin from its live WordPress update offer.',
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

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/plugin-remote-update-apply' ) ) {
			wp_register_ability(
				'mad4b/plugin-remote-update-apply',
				array(
					'label' => 'Apply Governed Remote Plugin Update',
					'description' => 'Governed Staging update of an installed plugin from its exact server-resolved WordPress update offer with backup, rollback and exact readback.',
					'category' => 'mad4b-admin',
					'execute_callback' => array( __CLASS__, 'apply' ),
					'permission_callback' => array( __CLASS__, 'can_apply' ),
					'input_schema' => self::apply_schema(),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
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
		return MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/plugin-remote-update-apply', 'mad4b-admin', 'core', is_array( $input ) ? $input : array() );
	}

	public static function plan( $input ) {
		$built = self::build_plan( $input, false );
		return is_wp_error( $built ) ? $built : $built['plan'];
	}

	public static function apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected_plan = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_plan ) ) {
			return new WP_Error( 'mad4b_remote_plugin_update_plan_digest_required', 'expected_plan_sha256 from a reviewed remote plugin update plan is required.' );
		}

		$built = self::build_plan( $input, true );
		if ( is_wp_error( $built ) ) return $built;
		$plan = $built['plan'];
		$package = isset( $built['package'] ) && is_array( $built['package'] ) ? $built['package'] : array();

		if ( ! hash_equals( $plan['plan_sha256'], $expected_plan ) ) {
			self::cleanup_package( $package );
			return new WP_Error(
				'mad4b_remote_plugin_update_plan_changed',
				'Remote plugin update plan changed since review.',
				array( 'current_plan_sha256' => $plan['plan_sha256'], 'expected_plan_sha256' => $expected_plan )
			);
		}
		if ( empty( $plan['eligible'] ) || empty( $package['path'] ) ) {
			self::cleanup_package( $package );
			return new WP_Error( 'mad4b_remote_plugin_update_preflight_blocked', 'Remote plugin update preflight blocked the mutation.', array( 'blockers' => $plan['blockers'] ) );
		}

		$plugin_file = (string) $plan['plugin_file'];
		$before = self::disk_state( $plugin_file );
		if ( is_wp_error( $before ) ) {
			self::cleanup_package( $package );
			return $before;
		}

		$backup = self::backup_plugin( $plugin_file );
		if ( is_wp_error( $backup ) ) {
			self::cleanup_package( $package );
			return $backup;
		}

		$install = self::install_package( $package['path'] );
		self::cleanup_package( $package );
		if ( is_wp_error( $install ) ) {
			$rollback = self::rollback( $plugin_file, $backup, $before );
			$rollback_ok = ! is_wp_error( $rollback );
			MAD4B_SCP_Audit::record( 'mad4b/plugin-remote-update-apply', array(
				'plugin_file' => $plugin_file,
				'before_version' => $before['version'],
				'target_version' => $plan['target_version'],
				'plan_sha256' => $expected_plan,
				'package_sha256' => $plan['package_sha256'],
				'backup_id' => $backup['backup_id'],
				'failure_phase' => 'install',
				'failure_code' => $install->get_error_code(),
				'rollback_attempted' => true,
				'rollback_ok' => $rollback_ok,
				'readback_verified' => false,
			), 'failure' );
			return new WP_Error(
				'mad4b_remote_plugin_update_install_failed_with_rollback_status',
				'Remote plugin update failed; rollback status is attached.',
				array(
					'cause_code' => $install->get_error_code(),
					'cause_message' => $install->get_error_message(),
					'rollback_ok' => $rollback_ok,
					'rollback_error_code' => is_wp_error( $rollback ) ? $rollback->get_error_code() : '',
				)
			);
		}

		$activation = self::restore_activation_state( $plugin_file, $before );
		if ( is_wp_error( $activation ) ) {
			$rollback = self::rollback( $plugin_file, $backup, $before );
			return new WP_Error(
				'mad4b_remote_plugin_update_activation_restore_failed_with_rollback_status',
				'Plugin activation-state restoration failed; rollback status is attached.',
				array(
					'cause_code' => $activation->get_error_code(),
					'cause_message' => $activation->get_error_message(),
					'rollback_ok' => ! is_wp_error( $rollback ),
					'rollback_error_code' => is_wp_error( $rollback ) ? $rollback->get_error_code() : '',
				)
			);
		}

		$readback = self::verify_installed_readback( $plugin_file, $plan['target_version'], $package['file_manifest'] );
		if ( is_wp_error( $readback ) ) {
			$rollback = self::rollback( $plugin_file, $backup, $before );
			MAD4B_SCP_Audit::record( 'mad4b/plugin-remote-update-apply', array(
				'plugin_file' => $plugin_file,
				'before_version' => $before['version'],
				'target_version' => $plan['target_version'],
				'plan_sha256' => $expected_plan,
				'package_sha256' => $plan['package_sha256'],
				'archive_manifest_sha256' => $plan['archive_manifest_sha256'],
				'backup_id' => $backup['backup_id'],
				'failure_phase' => 'readback',
				'failure_code' => $readback->get_error_code(),
				'rollback_attempted' => true,
				'rollback_ok' => ! is_wp_error( $rollback ),
				'readback_verified' => false,
			), 'failure' );
			return new WP_Error(
				'mad4b_remote_plugin_update_readback_failed_rolled_back',
				'Remote plugin update exact readback failed; previous plugin files were restored when available.',
				array(
					'readback_error' => $readback->get_error_code(),
					'rollback_ok' => ! is_wp_error( $rollback ),
					'rollback_error_code' => is_wp_error( $rollback ) ? $rollback->get_error_code() : '',
				)
			);
		}

		MAD4B_SCP_Audit::record( 'mad4b/plugin-remote-update-apply', array(
			'plugin_file' => $plugin_file,
			'before_version' => $before['version'],
			'after_version' => $readback['version'],
			'plan_sha256' => $expected_plan,
			'package_sha256' => $plan['package_sha256'],
			'archive_manifest_sha256' => $plan['archive_manifest_sha256'],
			'archive_file_count' => $plan['archive_file_count'],
			'backup_id' => $backup['backup_id'],
			'readback_verified' => true,
			'activation_state_preserved' => true,
			'production_mutation' => false,
		) );

		return array(
			'contract' => self::APPLY_CONTRACT,
			'plugin_file' => $plugin_file,
			'operation' => 'remote_update',
			'before_version' => $before['version'],
			'after_version' => $readback['version'],
			'package_sha256' => $plan['package_sha256'],
			'archive_manifest_sha256' => $plan['archive_manifest_sha256'],
			'archive_file_count' => $plan['archive_file_count'],
			'plan_sha256' => $expected_plan,
			'backup_id' => $backup['backup_id'],
			'readback_verified' => true,
			'activation_state_preserved' => true,
			'production_mutation' => false,
			'runtime_reboot_required' => true,
			'next_readback' => array( 'mad4b/list-plugins', 'mad4b/runtime-self-test' ),
			'authorizing' => false,
			'authority_created' => false,
		);
	}

	private static function build_plan( $input, $retain_package ) {
		$input = is_array( $input ) ? $input : array();
		$plugin_file = self::normalize_plugin_file( isset( $input['plugin_file'] ) ? $input['plugin_file'] : '' );
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		if ( is_wp_error( $plugin_file ) ) return $plugin_file;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$installed = isset( $plugins[ $plugin_file ] );
		$current_version = $installed && isset( $plugins[ $plugin_file ]['Version'] ) ? trim( (string) $plugins[ $plugin_file ]['Version'] ) : '';
		$current_active = false;
		$current_site_active = false;
		$current_network_active = false;
		if ( $installed ) {
			$activation_state = class_exists( 'MAD4B_SCP_Plugin_Activation_State' ) ? MAD4B_SCP_Plugin_Activation_State::snapshot( $plugin_file ) : new WP_Error( 'mad4b_plugin_activation_state_unavailable', 'Shared plugin activation-state service is unavailable.' );
			if ( is_wp_error( $activation_state ) ) return $activation_state;
			$current_active = ! empty( $activation_state['effective_active'] );
			$current_site_active = ! empty( $activation_state['site_active'] );
			$current_network_active = ! empty( $activation_state['network_active'] );
		}
		$blockers = array();

		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' ) ) {
			$blockers[] = 'staging_enrolled_write_profile_required';
		}
		if ( ! $installed ) $blockers[] = 'plugin_not_installed';
		if ( self::protected_plugin( $plugin_file ) ) $blockers[] = 'protected_plugin_remote_update_denied';
		if ( ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';

		$offer = $installed ? self::update_offer( $plugin_file ) : null;
		$target_version = is_object( $offer ) && isset( $offer->new_version ) ? trim( (string) $offer->new_version ) : '';
		$package_url = is_object( $offer ) && isset( $offer->package ) ? trim( (string) $offer->package ) : '';

		if ( empty( $offer ) ) $blockers[] = 'wordpress_update_offer_missing';
		if ( '' === $target_version ) $blockers[] = 'wordpress_update_target_version_missing';
		if ( '' === $package_url ) $blockers[] = 'wordpress_update_package_missing';
		if ( '' !== $current_version && '' !== $target_version && ! version_compare( $target_version, $current_version, '>' ) ) {
			$blockers[] = 'wordpress_update_target_not_newer';
		}
		if ( '' !== $package_url && ! self::safe_https_url( $package_url ) ) {
			$blockers[] = 'wordpress_update_package_url_unsafe';
		}

		$package = array();
		if ( empty( $blockers ) ) {
			$package = self::snapshot_package( $plugin_file, $target_version, $package_url, (bool) $retain_package );
			if ( is_wp_error( $package ) ) {
				$blockers[] = $package->get_error_code();
				$package = array();
			}
		}

		$source = array(
			'source' => 'wordpress_update_offer',
			'available' => ! empty( $package ),
			'server_resolved_update_offer' => true,
			'caller_supplied_url_allowed' => false,
			'caller_supplied_path_allowed' => false,
			'caller_supplied_package_allowed' => false,
			'remote_request_performed' => ! empty( $package ),
			'package_origin' => ! empty( $package['package_origin'] ) ? $package['package_origin'] : '',
			'package_sha256_verified' => ! empty( $package['sha256'] ),
			'archive_identity_verified' => ! empty( $package['archive_identity_verified'] ),
			'temporary_artifact_retained' => (bool) ( $retain_package && ! empty( $package['path'] ) ),
		);

		$state_payload = array(
			'plugin_file' => $plugin_file,
			'installed' => $installed,
			'current_version' => $current_version,
			'current_active' => (bool) $current_active,
			'current_site_active' => (bool) $current_site_active,
			'current_network_active' => (bool) $current_network_active,
			'update_offer_target_version' => $target_version,
		);

		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'plugin_file' => $plugin_file,
			'operation' => 'remote_update',
			'current_version' => $current_version,
			'target_version' => $target_version,
			'current_active' => (bool) $current_active,
			'current_site_active' => (bool) $current_site_active,
			'current_network_active' => (bool) $current_network_active,
			'state_sha256' => self::digest( $state_payload ),
			'package_sha256' => ! empty( $package['sha256'] ) ? $package['sha256'] : '',
			'package_bytes' => ! empty( $package['bytes'] ) ? (int) $package['bytes'] : 0,
			'archive_manifest_sha256' => ! empty( $package['manifest_sha256'] ) ? $package['manifest_sha256'] : '',
			'archive_file_count' => ! empty( $package['file_count'] ) ? (int) $package['file_count'] : 0,
			'source' => $source,
			'production_allowed' => false,
			'backup_required' => true,
			'activation_state_preserved' => true,
			'rollback_on_failed_install' => true,
			'rollback_on_failed_readback' => true,
			'exact_package_revalidation_at_apply' => true,
			'eligible' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'reason' => $reason,
			'mutation_performed' => false,
			'authority_created' => false,
			'authorizing' => false,
		);
		sort( $plan['blockers'], SORT_STRING );
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['write_binding'] = array(
			'ability' => 'mad4b/plugin-remote-update-apply',
			'plugin_file' => $plugin_file,
			'expected_plan_sha256' => $plan['plan_sha256'],
		);

		if ( ! $retain_package ) self::cleanup_package( $package );
		return array( 'plan' => $plan, 'package' => $package );
	}

	private static function snapshot_package( $plugin_file, $target_version, $url, $retain ) {
		if ( ! self::safe_https_url( $url ) ) return new WP_Error( 'mad4b_remote_plugin_update_url_unsafe', 'Server-resolved plugin update package URL is not a safe HTTPS URL.' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 300 );
		if ( is_wp_error( $tmp ) ) return new WP_Error( 'mad4b_remote_plugin_update_download_failed', 'Server-resolved plugin update package download failed.' );
		if ( ! is_file( $tmp ) || ! is_readable( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_remote_plugin_update_download_missing', 'Downloaded plugin update package is unavailable.' );
		}
		$bytes = (int) filesize( $tmp );
		if ( $bytes < 1 || $bytes > self::MAX_PACKAGE_BYTES ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_remote_plugin_update_package_size_denied', 'Remote plugin package size is outside the governed limit.' );
		}
		$sha = strtolower( (string) hash_file( 'sha256', $tmp ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_remote_plugin_update_package_hash_failed', 'Remote plugin package SHA-256 could not be computed.' );
		}

		$inspection = self::inspect_archive( $tmp, $plugin_file, $target_version );
		if ( is_wp_error( $inspection ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $inspection;
		}
		$origin = wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . wp_parse_url( $url, PHP_URL_HOST );
		$result = array_merge( $inspection, array(
			'path' => $tmp,
			'sha256' => $sha,
			'bytes' => $bytes,
			'temporary' => true,
			'package_origin' => $origin,
			'archive_identity_verified' => true,
		) );
		if ( ! $retain ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$result['path'] = '';
			$result['temporary'] = false;
		}
		return $result;
	}

	private static function inspect_archive( $path, $plugin_file, $target_version ) {
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_remote_plugin_update_zip_unavailable', 'ZipArchive is required for governed remote plugin updates.' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) return new WP_Error( 'mad4b_remote_plugin_update_zip_open_failed', 'Remote plugin update archive could not be opened.' );

		$manifest = array();
		$seen_paths = array();
		$total_uncompressed = 0;
		$file_count = 0;
		$plugin_dir = dirname( $plugin_file );
		$prefix = '.' === $plugin_dir ? '' : rtrim( $plugin_dir, '/' ) . '/';
		$main_seen = false;
		$main_contents = '';

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$raw_name = is_array( $stat ) && isset( $stat['name'] ) ? (string) $stat['name'] : '';
			if ( '' === $raw_name || false !== strpos( $raw_name, '\\' ) ) {
				$zip->close();
				return new WP_Error( 'mad4b_remote_plugin_update_zip_path_invalid', 'Plugin archive contains an invalid path.' );
			}
			$name = wp_normalize_path( $raw_name );
			if ( '/' === substr( $name, -1 ) ) continue;
			$collision_key = strtolower( $name );
			if ( isset( $seen_paths[ $collision_key ] ) ) {
				$zip->close();
				return new WP_Error( 'mad4b_remote_plugin_update_zip_duplicate_path', 'Plugin update archive contains duplicate or case-colliding file paths.' );
			}
			$seen_paths[ $collision_key ] = true;
			if ( '/' === substr( $name, 0, 1 ) || false !== strpos( $name, '../' ) || 0 === strpos( $name, './' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
				$zip->close();
				return new WP_Error( 'mad4b_remote_plugin_update_zip_path_escape', 'Plugin archive contains a path traversal or absolute path.' );
			}
			if ( '' !== $prefix ) {
				if ( 0 !== strpos( $name, $prefix ) ) {
					$zip->close();
					return new WP_Error( 'mad4b_remote_plugin_update_zip_root_mismatch', 'Plugin archive contains files outside the installed plugin root.' );
				}
			} elseif ( false !== strpos( $name, '/' ) ) {
				$zip->close();
				return new WP_Error( 'mad4b_remote_plugin_update_single_file_layout_mismatch', 'Single-file plugin updates must remain single-file packages.' );
			}

			$opsys = 0;
			$attr = 0;
			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) ) {
				$mode = ( $attr >> 16 ) & 0170000;
				if ( 0120000 === $mode ) {
					$zip->close();
					return new WP_Error( 'mad4b_remote_plugin_update_zip_symlink_forbidden', 'Plugin update archive contains a symbolic link.' );
				}
			}

			$file_count++;
			if ( $file_count > self::MAX_ARCHIVE_FILES ) {
				$zip->close();
				return new WP_Error( 'mad4b_remote_plugin_update_zip_file_count_denied', 'Plugin update archive exceeds the governed file-count limit.' );
			}
			$size = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$total_uncompressed += max( 0, $size );
			if ( $total_uncompressed > self::MAX_UNCOMPRESSED_BYTES ) {
				$zip->close();
				return new WP_Error( 'mad4b_remote_plugin_update_zip_uncompressed_size_denied', 'Plugin update archive exceeds the governed uncompressed-size limit.' );
			}

			$stream = $zip->getStream( $raw_name );
			if ( ! is_resource( $stream ) ) {
				$zip->close();
				return new WP_Error( 'mad4b_remote_plugin_update_zip_stream_failed', 'Plugin update archive file could not be read.' );
			}
			$hash = hash_init( 'sha256' );
			$captured = '';
			while ( ! feof( $stream ) ) {
				$chunk = fread( $stream, 1048576 );
				if ( false === $chunk ) {
					fclose( $stream );
					$zip->close();
					return new WP_Error( 'mad4b_remote_plugin_update_zip_read_failed', 'Plugin update archive file read failed.' );
				}
				hash_update( $hash, $chunk );
				if ( $name === $plugin_file && strlen( $captured ) < 1048576 ) {
					$captured .= substr( $chunk, 0, 1048576 - strlen( $captured ) );
				}
			}
			fclose( $stream );
			$manifest[ $name ] = hash_final( $hash );
			if ( $name === $plugin_file ) {
				$main_seen = true;
				$main_contents = $captured;
			}
		}
		$zip->close();

		if ( ! $main_seen ) return new WP_Error( 'mad4b_remote_plugin_update_main_file_missing', 'Plugin update archive does not contain the exact installed plugin main file.' );
		$header_version = self::plugin_header_value( $main_contents, 'Version' );
		$plugin_name = self::plugin_header_value( $main_contents, 'Plugin Name' );
		if ( '' === $plugin_name ) return new WP_Error( 'mad4b_remote_plugin_update_plugin_header_missing', 'Plugin update archive main file does not contain a Plugin Name header.' );
		if ( '' === $header_version || ! hash_equals( (string) $target_version, $header_version ) ) {
			return new WP_Error( 'mad4b_remote_plugin_update_archive_version_mismatch', 'Plugin update archive Version header does not match the server-resolved target version.' );
		}
		ksort( $manifest, SORT_STRING );
		return array(
			'file_manifest' => $manifest,
			'manifest_sha256' => self::digest( $manifest ),
			'file_count' => count( $manifest ),
			'uncompressed_bytes' => $total_uncompressed,
			'archive_plugin_name' => $plugin_name,
			'archive_version' => $header_version,
		);
	}

	private static function plugin_header_value( $contents, $field ) {
		if ( ! is_string( $contents ) || '' === $contents ) return '';
		$pattern = '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':[ \t]*(.+)$/mi';
		if ( ! preg_match( $pattern, $contents, $match ) ) return '';
		return trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', (string) $match[1] ) );
	}

	private static function verify_installed_readback( $plugin_file, $target_version, array $manifest ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_clean_plugins_cache( true );
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) return new WP_Error( 'mad4b_remote_plugin_update_readback_plugin_missing', 'Plugin main file is missing after remote update.' );
		$version = isset( $plugins[ $plugin_file ]['Version'] ) ? trim( (string) $plugins[ $plugin_file ]['Version'] ) : '';
		if ( ! hash_equals( (string) $target_version, $version ) ) {
			return new WP_Error( 'mad4b_remote_plugin_update_readback_version_mismatch', 'Installed plugin version does not match the exact remote update target.' );
		}
		$mismatched = array();
		foreach ( $manifest as $relative => $expected ) {
			$relative = wp_normalize_path( (string) $relative );
			if ( '' === $relative || '/' === substr( $relative, 0, 1 ) || false !== strpos( $relative, '../' ) ) {
				$mismatched[ $relative ] = 'invalid_manifest_path';
				continue;
			}
			$path = trailingslashit( WP_PLUGIN_DIR ) . $relative;
			$actual = is_file( $path ) && is_readable( $path ) ? strtolower( (string) hash_file( 'sha256', $path ) ) : '';
			if ( '' === $actual || ! hash_equals( strtolower( (string) $expected ), $actual ) ) $mismatched[ $relative ] = $actual;
		}
		if ( ! empty( $mismatched ) ) {
			return new WP_Error( 'mad4b_remote_plugin_update_readback_integrity_mismatch', 'Installed plugin files do not match the exact downloaded archive manifest.', array( 'mismatched' => $mismatched ) );
		}
		return array( 'plugin_file' => $plugin_file, 'version' => $version, 'files_verified' => count( $manifest ) );
	}

	private static function safe_https_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) return false;
		return function_exists( 'wp_http_validate_url' ) ? (bool) wp_http_validate_url( $url ) : false;
	}

	private static function normalize_plugin_file( $plugin_file ) {
		$plugin_file = ltrim( wp_normalize_path( (string) $plugin_file ), '/' );
		if ( '' === $plugin_file || false !== strpos( $plugin_file, '..' ) || false !== strpos( $plugin_file, "\0" ) || 0 !== validate_file( $plugin_file ) || '.php' !== strtolower( substr( $plugin_file, -4 ) ) ) {
			return new WP_Error( 'mad4b_remote_plugin_update_plugin_file_invalid', 'plugin_file must identify one installed WordPress plugin main PHP file.' );
		}
		return $plugin_file;
	}

	private static function protected_plugin( $plugin_file ) {
		$self = plugin_basename( MAD4B_SCP_FILE );
		return $plugin_file === $self
			|| 0 === strpos( strtolower( $plugin_file ), 'mcp-adapter/' )
			|| ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::plugin_lifecycle_protected( $plugin_file ) );
	}

	private static function update_offer( $plugin_file ) {
		$updates = get_site_transient( 'update_plugins' );
		return is_object( $updates ) && isset( $updates->response[ $plugin_file ] ) && is_object( $updates->response[ $plugin_file ] )
			? $updates->response[ $plugin_file ]
			: null;
	}

	private static function disk_state( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) return new WP_Error( 'mad4b_remote_plugin_update_plugin_not_installed', 'Plugin is not currently installed.' );
		$activation = class_exists( 'MAD4B_SCP_Plugin_Activation_State' ) ? MAD4B_SCP_Plugin_Activation_State::snapshot( $plugin_file ) : new WP_Error( 'mad4b_plugin_activation_state_unavailable', 'Shared plugin activation-state service is unavailable.' );
		if ( is_wp_error( $activation ) ) return $activation;
		return array(
			'plugin_file' => $plugin_file,
			'version' => isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '',
			'active' => ! empty( $activation['effective_active'] ),
			'site_active' => ! empty( $activation['site_active'] ),
			'network_active' => ! empty( $activation['network_active'] ),
		);
	}

	private static function plugin_target_path( $plugin_file ) {
		$plugin_file = ltrim( wp_normalize_path( (string) $plugin_file ), '/' );
		$dir = dirname( $plugin_file );
		return '.' === $dir ? wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $plugin_file ) : wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $dir );
	}

	private static function backup_plugin( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$source = self::plugin_target_path( $plugin_file );
		if ( '' === $source || ! file_exists( $source ) ) return new WP_Error( 'mad4b_remote_plugin_update_backup_source_missing', 'Installed plugin target is unavailable for backup.' );
		$root = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $root ) ) return $root;
		if ( ! WP_Filesystem() ) return new WP_Error( 'mad4b_remote_plugin_update_filesystem_unavailable', 'WordPress filesystem abstraction is unavailable.' );
		global $wp_filesystem;
		$backup_id = 'remote-plugin-update-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$destination = trailingslashit( $root ) . $backup_id;
		if ( is_dir( $source ) ) {
			$result = copy_dir( $source, $destination );
			if ( is_wp_error( $result ) ) return $result;
			return array( 'backup_id' => $backup_id, 'backup_path' => $destination, 'kind' => 'directory', 'created' => true );
		}
		if ( ! wp_mkdir_p( $destination ) ) return new WP_Error( 'mad4b_remote_plugin_update_backup_directory_failed', 'Protected backup directory could not be created.' );
		$file_destination = trailingslashit( $destination ) . basename( $source );
		if ( ! $wp_filesystem->copy( $source, $file_destination, true ) ) return new WP_Error( 'mad4b_remote_plugin_update_backup_file_failed', 'Installed plugin file could not be copied into protected backup storage.' );
		return array( 'backup_id' => $backup_id, 'backup_path' => $file_destination, 'kind' => 'file', 'created' => true );
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
			return is_wp_error( $errors ) && $errors->has_errors()
				? $errors
				: new WP_Error( 'mad4b_remote_plugin_update_install_failed', 'WordPress Plugin_Upgrader did not complete the governed remote plugin update.' );
		}
		wp_clean_plugins_cache( true );
		return true;
	}

	private static function restore_activation_state( $plugin_file, array $before ) {
		if ( ! class_exists( 'MAD4B_SCP_Plugin_Activation_State' ) ) return new WP_Error( 'mad4b_plugin_activation_state_unavailable', 'Shared plugin activation-state service is unavailable.' );
		$desired = array(
			'site_active' => ! empty( $before['site_active'] ),
			'network_active' => ! empty( $before['network_active'] ),
		);
		// Backward compatibility for pre-rc.82 snapshots that carried only
		// effective active + network_active.
		if ( ! array_key_exists( 'site_active', $before ) ) {
			$desired['site_active'] = ! empty( $before['active'] ) && empty( $before['network_active'] );
		}
		$result = MAD4B_SCP_Plugin_Activation_State::restore( $plugin_file, $desired );
		return is_wp_error( $result ) ? $result : true;
	}

	private static function rollback( $plugin_file, array $backup, $before ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! WP_Filesystem() ) return new WP_Error( 'mad4b_remote_plugin_update_rollback_filesystem_unavailable', 'WordPress filesystem abstraction is unavailable for rollback.' );
		global $wp_filesystem;
		$target = self::plugin_target_path( $plugin_file );
		if ( is_plugin_active( $plugin_file ) ) deactivate_plugins( $plugin_file, true, is_multisite() && is_plugin_active_for_network( $plugin_file ) );
		if ( '' !== $target && file_exists( $target ) ) $wp_filesystem->delete( $target, is_dir( $target ) );

		if ( empty( $backup['created'] ) || empty( $backup['backup_path'] ) ) return new WP_Error( 'mad4b_remote_plugin_update_rollback_backup_missing', 'Protected rollback backup is missing.' );
		if ( 'directory' === $backup['kind'] ) {
			$restored = copy_dir( $backup['backup_path'], $target );
			if ( is_wp_error( $restored ) ) return $restored;
		} elseif ( 'file' === $backup['kind'] ) {
			if ( ! $wp_filesystem->copy( $backup['backup_path'], $target, true ) ) return new WP_Error( 'mad4b_remote_plugin_update_rollback_file_failed', 'Plugin rollback file restore failed.' );
		} else {
			return new WP_Error( 'mad4b_remote_plugin_update_rollback_kind_invalid', 'Plugin rollback backup kind is invalid.' );
		}
		wp_clean_plugins_cache( true );
		return is_array( $before ) ? self::restore_activation_state( $plugin_file, $before ) : true;
	}

	private static function cleanup_package( array $package ) {
		if ( ! empty( $package['temporary'] ) && ! empty( $package['path'] ) && is_file( $package['path'] ) ) {
			@unlink( $package['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private static function plan_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'plugin_file' => array( 'type' => 'string', 'minLength' => 5, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9._+\\/-]+\\.php$' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			'required' => array( 'plugin_file', 'reason' ),
			'additionalProperties' => false,
		);
	}

	private static function apply_schema() {
		$schema = self::plan_schema();
		$schema['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
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
