<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Dual-channel, fail-closed self update for MAD4B Site Control Plane.
 *
 * Channel A: WordPress-native manual plugin update from a repository-owned,
 * Release-Verdict-gated immutable package.
 *
 * Channel B: governed file upload + apply ability. The caller supplies package
 * bytes only (base64) plus exact identity metadata. Caller URLs and filesystem
 * paths are never accepted.
 *
 * Both channels share the same archive/provenance verifier and the same
 * backup/readback/rollback semantics. Automatic plugin updates are disabled.
 */
final class MAD4B_SCP_Self_Update {
	const CONTRACT              = 'mad4b.control-plane-self-update.v1';
	const PLAN_CONTRACT         = 'mad4b.control-plane-upload-plan.v1';
	const APPLY_CONTRACT        = 'mad4b.control-plane-upload-apply.v1';
	const MANIFEST_CONTRACT     = 'mad4b.control-plane-update-channel.v1';
	const RELEASE_TAG           = 'mad4b-site-control-plane-update-channel';
	const MANIFEST_URL          = 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-update.json';
	const MAX_UPLOAD_BYTES      = 16777216; // 16 MiB decoded.
	const MANIFEST_CACHE_TTL    = 300;
	const MANIFEST_TRANSIENT    = 'mad4b_scp_update_manifest_v1';

	private static $booted = false;
	private static $native_backup = array();
	private static $managed_apply = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );

		// Native WordPress update channel.
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_native_update' ), 20, 1 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'disable_self_auto_update' ), 100, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_native_download' ), 20, 4 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'native_pre_install' ), 20, 2 );
		add_filter( 'upgrader_post_install', array( __CLASS__, 'native_post_install' ), 20, 3 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		self::register_ability(
			'mad4b/control-plane-update-status',
			'MAD4B Control Plane Update Status',
			'status',
			array( 'type' => 'object', 'additionalProperties' => false ),
			true,
			array( 'MAD4B_SCP_Policy', 'can_read' )
		);

		self::register_ability(
			'mad4b/control-plane-upload-plan',
			'Plan MAD4B Control Plane File Upload Update',
			'upload_plan',
			self::plan_schema(),
			true,
			array( 'MAD4B_SCP_Policy', 'can_read' )
		);

		self::register_ability(
			'mad4b/control-plane-upload-apply',
			'Upload and Apply MAD4B Control Plane ZIP',
			'upload_apply',
			self::apply_schema(),
			false,
			array( __CLASS__, 'can_upload_apply' )
		);
	}

	private static function register_ability( $name, $label, $method, $schema, $readonly, $permission ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the fail-closed dual-channel MAD4B self-update coordinator.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-admin',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $permission,
				'input_schema' => $schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'admin' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => (bool) $readonly,
					),
				),
			)
		);
	}

	public static function can_upload_apply( $input = null ) {
		$admin = MAD4B_SCP_Policy::can_admin();
		if ( is_wp_error( $admin ) || ! $admin ) return $admin;
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled.' );
		if ( ! self::environment_allowed( true ) ) return new WP_Error( 'mad4b_self_update_staging_only', 'Remote Control Plane file upload is Staging-only.' );
		if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
		return MAD4B_SCP_Authorization::authorize_mutation(
			'mad4b/control-plane-upload-apply',
			'mad4b-admin',
			'core',
			is_array( $input ) ? $input : array()
		);
	}

	public static function status( $input = array() ) {
		unset( $input );
		$current = self::installed_identity();
		$manifest = self::fetch_manifest();
		$manifest_error = is_wp_error( $manifest ) ? $manifest->get_error_code() : '';
		$native_ready = ! is_wp_error( $manifest ) && self::environment_allowed( false );
		$remote_ready = self::environment_allowed( true ) && current_user_can( 'update_plugins' );

		return array(
			'contract' => self::CONTRACT,
			'plugin' => plugin_basename( MAD4B_SCP_FILE ),
			'current' => $current,
			'native_wordpress_update' => array(
				'ready' => (bool) $native_ready,
				'manual_only' => true,
				'auto_update_disabled' => true,
				'manifest_url' => self::MANIFEST_URL,
				'manifest_state' => is_wp_error( $manifest ) ? 'unavailable' : 'ready',
				'manifest_error' => $manifest_error,
				'target' => is_wp_error( $manifest ) ? array() : self::public_manifest( $manifest ),
			),
			'governed_file_upload' => array(
				'ready' => (bool) $remote_ready,
				'staging_only' => true,
				'max_upload_bytes' => self::MAX_UPLOAD_BYTES,
				'caller_url_allowed' => false,
				'caller_path_allowed' => false,
				'exact_plan_required' => true,
				'exact_approval_required' => true,
				'rollback_required' => true,
			),
			'production_remote_upload_allowed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	public static function upload_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$identity = self::normalize_requested_identity( $input );
		if ( is_wp_error( $identity ) ) return $identity;

		$current = self::installed_identity();
		$blockers = array();

		if ( ! self::environment_allowed( true ) ) $blockers[] = 'staging_enrolled_write_profile_required';
		if ( ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';
		if ( $identity['size_bytes'] < 1 || $identity['size_bytes'] > self::MAX_UPLOAD_BYTES ) $blockers[] = 'archive_size_out_of_bounds';
		if ( ! empty( $current['source_commit_sha'] ) && hash_equals( $current['source_commit_sha'], $identity['source_commit_sha'] ) ) $blockers[] = 'already_on_exact_source_commit';
		if ( ! empty( $current['version'] ) && version_compare( $current['version'], $identity['version'], '>' ) ) $blockers[] = 'target_version_older_than_runtime';

		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'plugin' => plugin_basename( MAD4B_SCP_FILE ),
			'operation' => 'replace',
			'channel' => 'governed_file_upload',
			'current' => $current,
			'target' => $identity,
			'max_upload_bytes' => self::MAX_UPLOAD_BYTES,
			'caller_url_allowed' => false,
			'caller_path_allowed' => false,
			'backup_required' => true,
			'activation_state_preserved' => true,
			'archive_integrity_required' => true,
			'embedded_provenance_required' => true,
			'rollback_on_failed_readback' => true,
			'production_allowed' => false,
			'eligible' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'reason' => isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '',
			'mutation_performed' => false,
			'authorizing' => false,
		);
		sort( $plan['blockers'], SORT_STRING );
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['write_binding'] = array( 'expected_plan_sha256' => $plan['plan_sha256'] );
		return $plan;
	}

	public static function upload_apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) return new WP_Error( 'mad4b_self_update_plan_digest_required', 'expected_plan_sha256 from the reviewed upload plan is required.' );

		$plan_input = $input;
		unset( $plan_input['package_base64'], $plan_input['expected_plan_sha256'], $plan_input['_mad4b_approval_ticket_id'], $plan_input['_mad4b_context_receipt'] );
		$plan = self::upload_plan( $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( ! hash_equals( $plan['plan_sha256'], $expected ) ) {
			return new WP_Error( 'mad4b_self_update_plan_changed', 'Control Plane upload plan changed since review.', array( 'current_plan_sha256' => $plan['plan_sha256'], 'expected_plan_sha256' => $expected ) );
		}
		if ( empty( $plan['eligible'] ) ) return new WP_Error( 'mad4b_self_update_preflight_blocked', 'Control Plane upload preflight blocked the mutation.', array( 'blockers' => $plan['blockers'] ) );

		$encoded = isset( $input['package_base64'] ) ? preg_replace( '/\s+/', '', (string) $input['package_base64'] ) : '';
		if ( '' === $encoded ) return new WP_Error( 'mad4b_self_update_file_required', 'package_base64 is required.' );
		if ( strlen( $encoded ) > (int) ceil( self::MAX_UPLOAD_BYTES * 4 / 3 ) + 16 ) return new WP_Error( 'mad4b_self_update_encoded_payload_too_large', 'Encoded package exceeds the bounded upload budget.' );

		$bytes = base64_decode( $encoded, true );
		unset( $encoded );
		if ( false === $bytes ) return new WP_Error( 'mad4b_self_update_base64_invalid', 'Uploaded package is not valid strict base64.' );
		if ( strlen( $bytes ) !== (int) $plan['target']['size_bytes'] ) return new WP_Error( 'mad4b_self_update_size_mismatch', 'Uploaded package size does not match the reviewed plan.' );
		$actual_sha = hash( 'sha256', $bytes );
		if ( ! hash_equals( $plan['target']['archive_sha256'], $actual_sha ) ) return new WP_Error( 'mad4b_self_update_archive_hash_mismatch', 'Uploaded package SHA-256 does not match the reviewed plan.' );

		$tmp = self::temp_archive_path();
		if ( is_wp_error( $tmp ) ) return $tmp;
		if ( false === file_put_contents( $tmp, $bytes, LOCK_EX ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_temp_write_failed', 'Unable to stage uploaded package.' );
		}
		unset( $bytes );
		@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$verified = self::verify_archive( $tmp, $plan['target'] );
		if ( is_wp_error( $verified ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $verified;
		}

		$result = self::apply_verified_archive( $tmp, $plan['target'], 'governed_file_upload', $expected );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $result;
	}

	public static function inject_native_update( $transient ) {
		if ( ! is_object( $transient ) || ! self::environment_allowed( false ) ) return $transient;

		$manifest = self::fetch_manifest();
		if ( is_wp_error( $manifest ) ) return $transient;
		$current = self::installed_identity();
		if ( ! empty( $current['source_commit_sha'] ) && hash_equals( $current['source_commit_sha'], $manifest['source_commit_sha'] ) ) {
			unset( $transient->response[ plugin_basename( MAD4B_SCP_FILE ) ] );
			return $transient;
		}

		$plugin = plugin_basename( MAD4B_SCP_FILE );
		$offer = (object) array(
			'id' => 'mad4b-site-control-plane',
			'slug' => 'mad4b-site-control-plane',
			'plugin' => $plugin,
			'new_version' => $manifest['display_version'],
			'url' => 'https://github.com/mad4bdigital-ai/WordPress',
			'package' => $manifest['package_url'],
			'tested' => isset( $manifest['tested'] ) ? $manifest['tested'] : '',
			'requires' => isset( $manifest['requires'] ) ? $manifest['requires'] : '6.9',
			'requires_php' => isset( $manifest['requires_php'] ) ? $manifest['requires_php'] : '7.4',
			'icons' => array(),
			'banners' => array(),
			'banners_rtl' => array(),
		);
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) $transient->response = array();
		$transient->response[ $plugin ] = $offer;
		return $transient;
	}

	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || 'mad4b-site-control-plane' !== (string) $args->slug ) return $result;
		$manifest = self::fetch_manifest();
		if ( is_wp_error( $manifest ) ) return $result;

		return (object) array(
			'name' => 'MAD4B Site Control Plane',
			'slug' => 'mad4b-site-control-plane',
			'version' => $manifest['display_version'],
			'author' => '<a href="https://github.com/mad4bdigital-ai">MAD4B</a>',
			'homepage' => 'https://github.com/mad4bdigital-ai/WordPress',
			'requires' => isset( $manifest['requires'] ) ? $manifest['requires'] : '6.9',
			'requires_php' => isset( $manifest['requires_php'] ) ? $manifest['requires_php'] : '7.4',
			'tested' => isset( $manifest['tested'] ) ? $manifest['tested'] : '',
			'download_link' => $manifest['package_url'],
			'sections' => array(
				'description' => 'Governed MAD4B Control Plane update channel. The package is exact-source, SHA-256 and build-provenance bound.',
				'changelog' => 'Exact build ' . esc_html( $manifest['source_commit_sha'] ) . '.',
			),
		);
	}

	public static function disable_self_auto_update( $update, $item ) {
		$plugin = is_object( $item ) && isset( $item->plugin ) ? (string) $item->plugin : '';
		if ( plugin_basename( MAD4B_SCP_FILE ) === $plugin ) return false;
		return $update;
	}

	public static function verify_native_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader );
		if ( false !== $reply ) return $reply;
		if ( ! self::is_self_upgrade_hook( $hook_extra ) ) return $reply;

		$manifest = self::fetch_manifest( true );
		if ( is_wp_error( $manifest ) ) return $manifest;
		if ( ! hash_equals( $manifest['package_url'], (string) $package ) ) return new WP_Error( 'mad4b_self_update_package_url_drift', 'Native update package URL does not match the certified update manifest.' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $manifest['package_url'], 30 );
		if ( is_wp_error( $tmp ) ) return $tmp;
		$sha = strtolower( (string) hash_file( 'sha256', $tmp ) );
		if ( ! hash_equals( $manifest['archive_sha256'], $sha ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_native_hash_mismatch', 'Downloaded native update package failed SHA-256 verification.' );
		}
		$verified = self::verify_archive( $tmp, $manifest );
		if ( is_wp_error( $verified ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $verified;
		}
		return $tmp;
	}

	public static function native_pre_install( $response, $hook_extra ) {
		if ( self::$managed_apply || is_wp_error( $response ) || ! self::is_self_upgrade_hook( $hook_extra ) ) return $response;
		$manifest = self::fetch_manifest( true );
		if ( is_wp_error( $manifest ) ) return $manifest;

		$backup = self::backup_current();
		if ( is_wp_error( $backup ) ) return $backup;
		self::$native_backup = array(
			'backup' => $backup,
			'before' => self::activation_state(),
			'target' => $manifest,
		);
		return $response;
	}

	public static function native_post_install( $response, $hook_extra, $result ) {
		unset( $result );
		if ( self::$managed_apply || ! self::is_self_upgrade_hook( $hook_extra ) ) return $response;
		if ( empty( self::$native_backup['target'] ) ) return new WP_Error( 'mad4b_self_update_native_backup_missing', 'Native self-update backup context is missing.' );

		$target = self::$native_backup['target'];
		$readback = self::verify_installed_identity( $target );
		if ( is_wp_error( $readback ) ) {
			$rollback = self::rollback( self::$native_backup['backup'], self::$native_backup['before'] );
			self::audit( 'native_wordpress_update', $target, false, array(
				'failure_code' => $readback->get_error_code(),
				'rollback_ok' => ! is_wp_error( $rollback ),
			) );
			self::$native_backup = array();
			return new WP_Error( 'mad4b_self_update_native_readback_failed', 'Native Control Plane update failed exact build readback and was rolled back when possible.', array(
				'cause_code' => $readback->get_error_code(),
				'rollback_ok' => ! is_wp_error( $rollback ),
			) );
		}

		self::restore_activation_state( self::$native_backup['before'] );
		self::audit( 'native_wordpress_update', $target, true, array( 'readback' => $readback ) );
		self::$native_backup = array();
		delete_site_transient( 'update_plugins' );
		delete_transient( self::MANIFEST_TRANSIENT );
		return $response;
	}

	private static function apply_verified_archive( $path, array $target, $channel, $plan_sha256 ) {
		$before = self::activation_state();
		$backup = self::backup_current();
		if ( is_wp_error( $backup ) ) return $backup;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		self::$managed_apply = true;
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$installed = $upgrader->install( $path, array( 'overwrite_package' => true ) );
		self::$managed_apply = false;

		if ( is_wp_error( $installed ) || true !== $installed ) {
			$error = is_wp_error( $installed ) ? $installed : ( method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null );
			$rollback = self::rollback( $backup, $before );
			self::audit( $channel, $target, false, array(
				'plan_sha256' => $plan_sha256,
				'failure_phase' => 'install',
				'failure_code' => is_wp_error( $error ) ? $error->get_error_code() : 'plugin_upgrader_failed',
				'rollback_ok' => ! is_wp_error( $rollback ),
			) );
			return new WP_Error( 'mad4b_self_update_install_failed', 'Control Plane installation failed and rollback was attempted.', array( 'rollback_ok' => ! is_wp_error( $rollback ) ) );
		}

		$activation = self::restore_activation_state( $before );
		$readback = is_wp_error( $activation ) ? $activation : self::verify_installed_identity( $target );
		if ( is_wp_error( $readback ) ) {
			$rollback = self::rollback( $backup, $before );
			self::audit( $channel, $target, false, array(
				'plan_sha256' => $plan_sha256,
				'failure_phase' => 'readback',
				'failure_code' => $readback->get_error_code(),
				'rollback_ok' => ! is_wp_error( $rollback ),
			) );
			return new WP_Error( 'mad4b_self_update_readback_failed', 'Control Plane exact build readback failed and rollback was attempted.', array(
				'cause_code' => $readback->get_error_code(),
				'rollback_ok' => ! is_wp_error( $rollback ),
			) );
		}

		self::audit( $channel, $target, true, array( 'plan_sha256' => $plan_sha256, 'readback' => $readback ) );
		delete_site_transient( 'update_plugins' );
		delete_transient( self::MANIFEST_TRANSIENT );

		return array(
			'contract' => self::APPLY_CONTRACT,
			'channel' => $channel,
			'plugin' => plugin_basename( MAD4B_SCP_FILE ),
			'before' => $before,
			'after' => $readback,
			'archive_sha256' => $target['archive_sha256'],
			'source_commit_sha' => $target['source_commit_sha'],
			'build_fingerprint' => $target['build_fingerprint'],
			'package_manifest_digest' => $target['package_manifest_digest'],
			'plan_sha256' => $plan_sha256,
			'readback_verified' => true,
			'rollback_required' => false,
			'runtime_reboot_required' => true,
			'production_mutation_performed' => false,
			'authority_created' => false,
			'authorizing' => false,
		);
	}

	private static function fetch_manifest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::MANIFEST_TRANSIENT );
			if ( is_array( $cached ) ) return $cached;
		}

		$response = wp_safe_remote_get(
			self::MANIFEST_URL,
			array(
				'timeout' => 12,
				'redirection' => 3,
				'user-agent' => 'MAD4B-Site-Control-Plane/' . MAD4B_SCP_VERSION,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) return new WP_Error( 'mad4b_self_update_manifest_fetch_failed', 'Unable to fetch the MAD4B update manifest.', array( 'cause' => $response->get_error_code() ) );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return new WP_Error( 'mad4b_self_update_manifest_http_error', 'MAD4B update manifest returned a non-200 response.' );
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) > 65536 ) return new WP_Error( 'mad4b_self_update_manifest_invalid', 'MAD4B update manifest body is invalid.' );
		$manifest = json_decode( $body, true );
		if ( ! is_array( $manifest ) ) return new WP_Error( 'mad4b_self_update_manifest_invalid', 'MAD4B update manifest is not valid JSON.' );

		$valid = self::validate_manifest( $manifest );
		if ( is_wp_error( $valid ) ) return $valid;
		set_transient( self::MANIFEST_TRANSIENT, $manifest, self::MANIFEST_CACHE_TTL );
		return $manifest;
	}

	private static function validate_manifest( array &$manifest ) {
		if ( self::MANIFEST_CONTRACT !== ( isset( $manifest['contract'] ) ? (string) $manifest['contract'] : '' ) ) return new WP_Error( 'mad4b_self_update_manifest_contract_mismatch', 'Update manifest contract mismatch.' );
		if ( 'mad4bdigital-ai/WordPress' !== ( isset( $manifest['repository'] ) ? (string) $manifest['repository'] : '' ) ) return new WP_Error( 'mad4b_self_update_manifest_repository_mismatch', 'Update manifest repository mismatch.' );
		if ( self::RELEASE_TAG !== ( isset( $manifest['release_tag'] ) ? (string) $manifest['release_tag'] : '' ) ) return new WP_Error( 'mad4b_self_update_manifest_release_tag_mismatch', 'Update manifest release channel mismatch.' );

		foreach ( array( 'source_commit_sha' => 40, 'archive_sha256' => 64, 'build_fingerprint' => 64, 'package_manifest_digest' => 64 ) as $field => $length ) {
			$value = isset( $manifest[ $field ] ) ? strtolower( trim( (string) $manifest[ $field ] ) ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{' . (int) $length . '}$/', $value ) ) return new WP_Error( 'mad4b_self_update_manifest_identity_invalid', 'Update manifest exact identity is incomplete.', array( 'field' => $field ) );
			$manifest[ $field ] = $value;
		}

		$version = isset( $manifest['version'] ) ? trim( (string) $manifest['version'] ) : '';
		if ( '' === $version || strlen( $version ) > 64 ) return new WP_Error( 'mad4b_self_update_manifest_version_invalid', 'Update manifest version is invalid.' );
		$manifest['version'] = $version;
		$manifest['display_version'] = $version . '+build.' . substr( $manifest['source_commit_sha'], 0, 7 );

		$url = isset( $manifest['package_url'] ) ? trim( (string) $manifest['package_url'] ) : '';
		$expected_path = '/mad4bdigital-ai/WordPress/releases/download/' . self::RELEASE_TAG . '/mad4b-site-control-plane-' . $manifest['source_commit_sha'] . '.zip';
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '' )
			|| 'github.com' !== ( isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '' )
			|| $expected_path !== ( isset( $parts['path'] ) ? (string) $parts['path'] : '' )
			|| ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['fragment'] ) ) {
			return new WP_Error( 'mad4b_self_update_manifest_package_url_invalid', 'Update manifest package URL is outside the fixed repository release channel.' );
		}
		$manifest['package_url'] = $url;
		$manifest['size_bytes'] = isset( $manifest['size_bytes'] ) ? absint( $manifest['size_bytes'] ) : 0;
		if ( $manifest['size_bytes'] < 1 || $manifest['size_bytes'] > self::MAX_UPLOAD_BYTES ) return new WP_Error( 'mad4b_self_update_manifest_size_invalid', 'Update package size is outside the bounded self-update budget.' );
		if ( empty( $manifest['release_verdict_success'] ) ) return new WP_Error( 'mad4b_self_update_release_verdict_missing', 'Update manifest is not bound to a successful Release Verdict.' );
		return true;
	}

	private static function normalize_requested_identity( array $input ) {
		$identity = array(
			'version' => isset( $input['version'] ) ? trim( (string) $input['version'] ) : '',
			'source_commit_sha' => isset( $input['source_commit_sha'] ) ? strtolower( trim( (string) $input['source_commit_sha'] ) ) : '',
			'archive_sha256' => isset( $input['archive_sha256'] ) ? strtolower( trim( (string) $input['archive_sha256'] ) ) : '',
			'build_fingerprint' => isset( $input['build_fingerprint'] ) ? strtolower( trim( (string) $input['build_fingerprint'] ) ) : '',
			'package_manifest_digest' => isset( $input['package_manifest_digest'] ) ? strtolower( trim( (string) $input['package_manifest_digest'] ) ) : '',
			'size_bytes' => isset( $input['size_bytes'] ) ? absint( $input['size_bytes'] ) : 0,
		);
		if ( '' === $identity['version'] || strlen( $identity['version'] ) > 64 ) return new WP_Error( 'mad4b_self_update_target_version_invalid', 'Target version is invalid.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $identity['source_commit_sha'] ) ) return new WP_Error( 'mad4b_self_update_target_source_invalid', 'Target source_commit_sha is invalid.' );
		foreach ( array( 'archive_sha256', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $identity[ $field ] ) ) return new WP_Error( 'mad4b_self_update_target_identity_invalid', 'Target exact build identity is invalid.', array( 'field' => $field ) );
		}
		return $identity;
	}

	private static function verify_archive( $path, array $target ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_self_update_archive_missing', 'Control Plane archive is unavailable.' );
		$sha = strtolower( (string) hash_file( 'sha256', $path ) );
		if ( ! hash_equals( $target['archive_sha256'], $sha ) ) return new WP_Error( 'mad4b_self_update_archive_hash_mismatch', 'Control Plane archive SHA-256 mismatch.' );
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_self_update_zip_runtime_unavailable', 'ZipArchive is required for Control Plane archive validation.' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) return new WP_Error( 'mad4b_self_update_zip_invalid', 'Control Plane archive cannot be opened.' );
		$required = array(
			'mad4b-site-control-plane/mad4b-site-control-plane.php' => false,
			'mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json' => false,
		);
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( '' === $name || 0 === strpos( $name, '/' ) || false !== strpos( $name, '\\' ) || false !== strpos( '/' . $name, '/../' ) || 0 !== strpos( $name, 'mad4b-site-control-plane/' ) ) {
				$zip->close();
				return new WP_Error( 'mad4b_self_update_zip_path_invalid', 'Control Plane archive contains an unsafe or unexpected path.' );
			}
			if ( array_key_exists( $name, $required ) ) $required[ $name ] = true;
			if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
				$opsys = 0; $attr = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && 3 === (int) $opsys ) {
					$mode = ( $attr >> 16 ) & 0xF000;
					if ( 0xA000 === $mode ) {
						$zip->close();
						return new WP_Error( 'mad4b_self_update_zip_symlink_forbidden', 'Control Plane archive contains a symlink entry.' );
					}
				}
			}
		}
		foreach ( $required as $name => $present ) {
			if ( ! $present ) {
				$zip->close();
				return new WP_Error( 'mad4b_self_update_zip_required_file_missing', 'Control Plane archive is missing a required exact-build file.', array( 'file' => $name ) );
			}
		}

		$raw = $zip->getFromName( 'mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json' );
		$zip->close();
		if ( ! is_string( $raw ) || strlen( $raw ) > 4194304 ) return new WP_Error( 'mad4b_self_update_provenance_invalid', 'Embedded build provenance is invalid.' );
		$provenance = json_decode( $raw, true );
		if ( ! is_array( $provenance ) || 'mad4b.build-provenance.v1' !== ( isset( $provenance['contract'] ) ? (string) $provenance['contract'] : '' ) ) return new WP_Error( 'mad4b_self_update_provenance_contract_mismatch', 'Embedded build provenance contract mismatch.' );
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			$actual = isset( $provenance[ $field ] ) ? strtolower( trim( (string) $provenance[ $field ] ) ) : '';
			if ( ! hash_equals( $target[ $field ], $actual ) ) return new WP_Error( 'mad4b_self_update_provenance_identity_mismatch', 'Embedded build provenance does not match the reviewed target.', array( 'field' => $field ) );
		}
		if ( isset( $provenance['version'] ) && '' !== trim( (string) $provenance['version'] ) && ! hash_equals( $target['version'], trim( (string) $provenance['version'] ) ) ) {
			return new WP_Error( 'mad4b_self_update_provenance_version_mismatch', 'Embedded build version does not match the reviewed target.' );
		}
		return array( 'archive_sha256' => $sha, 'provenance' => $provenance );
	}

	private static function installed_identity() {
		$identity = array(
			'version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'source_commit_sha' => '',
			'build_fingerprint' => '',
			'package_manifest_digest' => '',
		);
		$path = trailingslashit( MAD4B_SCP_DIR ) . 'MAD4B-BUILD-PROVENANCE.json';
		if ( is_file( $path ) && is_readable( $path ) ) {
			$raw = file_get_contents( $path );
			$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $row ) ) {
				foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
					if ( isset( $row[ $field ] ) ) $identity[ $field ] = strtolower( trim( (string) $row[ $field ] ) );
				}
				if ( ! empty( $row['version'] ) ) $identity['version'] = trim( (string) $row['version'] );
			}
		}
		return $identity;
	}

	private static function verify_installed_identity( array $target ) {
		$current = self::installed_identity();
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( empty( $current[ $field ] ) || ! hash_equals( $target[ $field ], $current[ $field ] ) ) return new WP_Error( 'mad4b_self_update_installed_identity_mismatch', 'Installed Control Plane identity does not match the target after replacement.', array( 'field' => $field, 'current' => isset( $current[ $field ] ) ? $current[ $field ] : '' ) );
		}
		if ( ! empty( $target['version'] ) && ! hash_equals( $target['version'], $current['version'] ) ) return new WP_Error( 'mad4b_self_update_installed_version_mismatch', 'Installed Control Plane version does not match the target after replacement.' );
		return $current;
	}

	private static function backup_current() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$root = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $root ) ) return $root;
		$source = wp_normalize_path( MAD4B_SCP_DIR );
		if ( ! is_dir( $source ) || is_link( $source ) ) return new WP_Error( 'mad4b_self_update_backup_source_invalid', 'Installed Control Plane directory is unavailable for backup.' );
		$destination = trailingslashit( $root ) . 'control-plane-self-update-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		if ( ! WP_Filesystem() ) return new WP_Error( 'mad4b_self_update_filesystem_unavailable', 'WordPress filesystem abstraction is unavailable.' );
		$copy = copy_dir( $source, $destination );
		if ( is_wp_error( $copy ) ) return $copy;
		return array( 'created' => true, 'backup_path' => $destination, 'backup_id' => basename( $destination ) );
	}

	private static function rollback( array $backup, array $before ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( empty( $backup['backup_path'] ) || ! is_dir( $backup['backup_path'] ) ) return new WP_Error( 'mad4b_self_update_rollback_backup_missing', 'Control Plane rollback backup is missing.' );
		if ( ! WP_Filesystem() ) return new WP_Error( 'mad4b_self_update_rollback_filesystem_unavailable', 'WordPress filesystem abstraction is unavailable for rollback.' );
		global $wp_filesystem;
		$root = untrailingslashit( wp_normalize_path( MAD4B_SCP_DIR ) );
		if ( is_plugin_active( plugin_basename( MAD4B_SCP_FILE ) ) ) deactivate_plugins( plugin_basename( MAD4B_SCP_FILE ), true, is_multisite() && is_plugin_active_for_network( plugin_basename( MAD4B_SCP_FILE ) ) );
		if ( file_exists( $root ) && ! $wp_filesystem->delete( $root, true ) ) return new WP_Error( 'mad4b_self_update_rollback_delete_failed', 'Unable to remove failed Control Plane candidate before rollback.' );
		$copy = copy_dir( $backup['backup_path'], $root );
		if ( is_wp_error( $copy ) ) return $copy;
		wp_clean_plugins_cache( true );
		return self::restore_activation_state( $before );
	}

	private static function activation_state() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = plugin_basename( MAD4B_SCP_FILE );
		return array(
			'version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'active' => is_plugin_active( $plugin ),
			'network_active' => is_multisite() ? is_plugin_active_for_network( $plugin ) : false,
		);
	}

	private static function restore_activation_state( array $before ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = plugin_basename( MAD4B_SCP_FILE );
		$active = is_plugin_active( $plugin );
		if ( ! empty( $before['active'] ) && ! $active ) {
			$result = activate_plugin( $plugin, '', ! empty( $before['network_active'] ) );
			if ( is_wp_error( $result ) ) return $result;
		} elseif ( empty( $before['active'] ) && $active ) {
			deactivate_plugins( $plugin, false, ! empty( $before['network_active'] ) );
		}
		return true;
	}

	private static function temp_archive_path() {
		$base = trailingslashit( get_temp_dir() ) . 'mad4b-control-plane-upload';
		if ( is_link( $base ) ) return new WP_Error( 'mad4b_self_update_temp_symlink_forbidden', 'Self-update temporary directory cannot be a symlink.' );
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_self_update_temp_dir_failed', 'Unable to prepare self-update temporary directory.' );
		@chmod( $base, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return trailingslashit( $base ) . wp_generate_uuid4() . '.zip';
	}

	private static function is_self_upgrade_hook( $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) return false;
		$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
		if ( '' === $plugin && ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && 1 === count( $hook_extra['plugins'] ) ) $plugin = (string) reset( $hook_extra['plugins'] );
		return plugin_basename( MAD4B_SCP_FILE ) === $plugin;
	}

	private static function environment_allowed( $remote ) {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : '';
		if ( $remote ) {
			return 'staging' === $environment
				&& class_exists( 'MAD4B_SCP_Site_Profile' )
				&& MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' );
		}
		if ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) return true;
		return 'production' === $environment
			&& defined( 'MAD4B_SCP_PRODUCTION_SELF_UPDATE_ENABLED' )
			&& true === MAD4B_SCP_PRODUCTION_SELF_UPDATE_ENABLED;
	}

	private static function public_manifest( array $manifest ) {
		return array(
			'version' => $manifest['version'],
			'display_version' => $manifest['display_version'],
			'source_commit_sha' => $manifest['source_commit_sha'],
			'archive_sha256' => $manifest['archive_sha256'],
			'build_fingerprint' => $manifest['build_fingerprint'],
			'package_manifest_digest' => $manifest['package_manifest_digest'],
			'size_bytes' => $manifest['size_bytes'],
			'release_verdict_success' => true,
		);
	}

	private static function audit( $channel, array $target, $success, array $extra = array() ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return;
		$payload = array_merge(
			array(
				'channel' => $channel,
				'plugin' => plugin_basename( MAD4B_SCP_FILE ),
				'target_source_commit_sha' => isset( $target['source_commit_sha'] ) ? $target['source_commit_sha'] : '',
				'target_archive_sha256' => isset( $target['archive_sha256'] ) ? $target['archive_sha256'] : '',
				'target_build_fingerprint' => isset( $target['build_fingerprint'] ) ? $target['build_fingerprint'] : '',
				'target_package_manifest_digest' => isset( $target['package_manifest_digest'] ) ? $target['package_manifest_digest'] : '',
				'production_mutation_performed' => false,
			),
			$extra
		);
		MAD4B_SCP_Audit::record( 'mad4b/control-plane-self-update', $payload, $success ? 'success' : 'failure' );
	}

	private static function plan_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'version' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				'source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
				'archive_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'package_manifest_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'size_bytes' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_UPLOAD_BYTES ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			'required' => array( 'version', 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest', 'size_bytes', 'reason' ),
			'additionalProperties' => false,
		);
	}

	private static function apply_schema() {
		$schema = self::plan_schema();
		$schema['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$schema['properties']['package_base64'] = array( 'type' => 'string', 'minLength' => 16, 'maxLength' => (int) ceil( self::MAX_UPLOAD_BYTES * 4 / 3 ) + 16 );
		$schema['required'][] = 'expected_plan_sha256';
		$schema['required'][] = 'package_base64';
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
