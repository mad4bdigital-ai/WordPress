<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Multi-channel, fail-closed self update for MAD4B Site Control Plane.
 *
 * Channel A: WordPress-native manual plugin update from a repository-owned,
 * Release-Verdict-gated immutable package.
 *
 * Channel B: governed file upload + apply ability. The caller supplies package
 * bytes only (base64) plus exact identity metadata. Caller URLs and filesystem
 * paths are never accepted.
 *
 * Channel C: governed native release pull. The caller supplies no package URL,
 * filesystem path, archive bytes, target SHA, or version. The target is derived
 * exclusively from the fixed repository release manifest, revalidated at apply,
 * downloaded into protected MAD4B storage, then passed through the same exact
 * archive/provenance verifier.
 *
 * All channels share the same backup/readback/rollback semantics.
 * Automatic plugin updates remain disabled.
 */
final class MAD4B_SCP_Self_Update {
	const CONTRACT              = 'mad4b.control-plane-self-update.v1';
	const PLAN_CONTRACT         = 'mad4b.control-plane-upload-plan.v1';
	const APPLY_CONTRACT        = 'mad4b.control-plane-upload-apply.v1';
	const NATIVE_PLAN_CONTRACT  = 'mad4b.control-plane-native-plan.v1';
	const NATIVE_APPLY_CONTRACT = 'mad4b.control-plane-native-apply.v1';
	const MANIFEST_CONTRACT     = 'mad4b.control-plane-update-channel.v1';
	const RELEASE_TAG           = 'mad4b-site-control-plane-update-channel';
	const MANIFEST_URL          = 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-update.json';
	const MAX_UPLOAD_BYTES      = 16777216; // 16 MiB decoded.
	const MANIFEST_CACHE_TTL    = 300;
	const MANIFEST_TRANSIENT    = 'mad4b_scp_update_manifest_v1';

	private static $booted = false;
	private static $managed_apply = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
		add_filter( 'mad4b_scp_authorization_input', array( __CLASS__, 'authorization_input' ), 20, 3 );

		// WordPress-admin update channel. This deliberately does not alter
		// WordPress core update transients or automatic-update routines.
		$plugin = plugin_basename( MAD4B_SCP_FILE );
		add_filter( 'plugin_action_links_' . $plugin, array( __CLASS__, 'plugin_action_links' ), 20, 1 );
		add_action( 'after_plugin_row_' . $plugin, array( __CLASS__, 'render_update_row' ), 10, 3 );
		add_action( 'admin_post_mad4b_control_plane_native_update', array( __CLASS__, 'handle_native_update' ) );
		add_action( 'admin_notices', array( __CLASS__, 'native_update_notice' ) );
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

		self::register_ability(
			'mad4b/control-plane-native-plan',
			'Plan Governed Native MAD4B Control Plane Update',
			'native_plan',
			self::native_plan_schema(),
			true,
			array( 'MAD4B_SCP_Policy', 'can_read' )
		);

		self::register_ability(
			'mad4b/control-plane-native-apply',
			'Apply Governed Native MAD4B Control Plane Update',
			'native_apply',
			self::native_apply_schema(),
			false,
			array( __CLASS__, 'can_native_apply' )
		);
	}

	private static function register_ability( $name, $label, $method, $schema, $readonly, $permission ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the fail-closed multi-channel MAD4B self-update coordinator.',
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

	public static function authorization_input( $clean, $ability_name, $original = null ) {
		unset( $original );
		if ( 'mad4b/control-plane-upload-apply' !== (string) $ability_name || ! is_array( $clean ) ) return $clean;
		unset( $clean['package_base64'] );
		$clean['package_transport'] = 'bounded_base64_zip';
		return $clean;
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

	public static function can_native_apply( $input = null ) {
		$admin = MAD4B_SCP_Policy::can_admin();
		if ( is_wp_error( $admin ) || ! $admin ) return $admin;
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled.' );
		if ( ! self::environment_allowed( true ) ) return new WP_Error( 'mad4b_self_update_staging_only', 'Remote Control Plane native release pull is Staging-only.' );
		if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
		return MAD4B_SCP_Authorization::authorize_mutation(
			'mad4b/control-plane-native-apply',
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
				'surface' => 'wp_admin_plugins_page',
				'manual_only' => true,
				'modifies_core_update_transients' => false,
				'automatic_update_enabled' => false,
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
			'governed_native_release_pull' => array(
				'ready' => (bool) ( $remote_ready && ! is_wp_error( $manifest ) ),
				'staging_only' => true,
				'fixed_manifest_url' => self::MANIFEST_URL,
				'caller_url_allowed' => false,
				'caller_path_allowed' => false,
				'caller_package_bytes_allowed' => false,
				'target_derived_from_release_manifest' => true,
				'exact_plan_required' => true,
				'exact_approval_required' => true,
				'archive_integrity_required' => true,
				'embedded_provenance_required' => true,
				'rollback_required' => true,
				'release_channel_bound' => true,
			),
			'production_remote_upload_allowed' => false,
			'production_remote_native_pull_allowed' => false,
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
		$release_manifest = self::fetch_manifest( true );

		if ( ! self::environment_allowed( true ) ) $blockers[] = 'staging_enrolled_write_profile_required';
		if ( ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';
		if ( $identity['size_bytes'] < 1 || $identity['size_bytes'] > self::MAX_UPLOAD_BYTES ) $blockers[] = 'archive_size_out_of_bounds';
		if ( ! empty( $current['source_commit_sha'] ) && hash_equals( $current['source_commit_sha'], $identity['source_commit_sha'] ) ) $blockers[] = 'already_on_exact_source_commit';
		if ( ! empty( $current['version'] ) && version_compare( $current['version'], $identity['version'], '>' ) ) $blockers[] = 'target_version_older_than_runtime';
		if ( is_wp_error( $release_manifest ) ) {
			$blockers[] = 'governed_release_manifest_unavailable';
		} else {
			foreach ( array( 'version', 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
				if ( ! isset( $release_manifest[ $field ] ) || ! hash_equals( (string) $release_manifest[ $field ], (string) $identity[ $field ] ) ) {
					$blockers[] = 'target_not_current_governed_release:' . $field;
				}
			}
			if ( (int) $release_manifest['size_bytes'] !== (int) $identity['size_bytes'] ) $blockers[] = 'target_not_current_governed_release:size_bytes';
		}

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
			'release_channel_bound' => true,
			'release_channel' => is_wp_error( $release_manifest ) ? array() : self::public_manifest( $release_manifest ),
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

	public static function native_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$current = self::installed_identity();
		$manifest = self::fetch_manifest( true );
		$blockers = array();

		if ( ! self::environment_allowed( true ) ) $blockers[] = 'staging_enrolled_write_profile_required';
		if ( ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';
		if ( is_wp_error( $manifest ) ) {
			$blockers[] = $manifest->get_error_code();
			$target = array();
		} else {
			$target = self::public_manifest( $manifest );
			if ( ! empty( $current['source_commit_sha'] ) && hash_equals( $current['source_commit_sha'], $manifest['source_commit_sha'] ) ) $blockers[] = 'already_on_exact_source_commit';
			if ( ! empty( $current['version'] ) && version_compare( $current['version'], $manifest['version'], '>' ) ) $blockers[] = 'target_version_older_than_runtime';
		}

		$plan = array(
			'contract' => self::NATIVE_PLAN_CONTRACT,
			'plugin' => plugin_basename( MAD4B_SCP_FILE ),
			'operation' => 'replace',
			'channel' => 'governed_native_release_pull',
			'current' => $current,
			'target' => $target,
			'fixed_manifest_url' => self::MANIFEST_URL,
			'caller_url_allowed' => false,
			'caller_path_allowed' => false,
			'caller_package_bytes_allowed' => false,
			'target_derived_from_release_manifest' => true,
			'backup_required' => true,
			'activation_state_preserved' => true,
			'archive_integrity_required' => true,
			'embedded_provenance_required' => true,
			'rollback_on_failed_readback' => true,
			'release_channel_bound' => true,
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

	public static function native_apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/' , $expected ) ) return new WP_Error( 'mad4b_self_update_plan_digest_required', 'expected_plan_sha256 from the reviewed native release plan is required.' );

		$plan_input = array( 'reason' => isset( $input['reason'] ) ? (string) $input['reason'] : '' );
		$plan = self::native_plan( $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( ! hash_equals( $plan['plan_sha256'], $expected ) ) {
			return new WP_Error( 'mad4b_self_update_plan_changed', 'Control Plane native release plan changed since review.', array( 'current_plan_sha256' => $plan['plan_sha256'], 'expected_plan_sha256' => $expected ) );
		}
		if ( empty( $plan['eligible'] ) ) return new WP_Error( 'mad4b_self_update_preflight_blocked', 'Control Plane native release preflight blocked the mutation.', array( 'blockers' => $plan['blockers'] ) );

		$manifest = self::fetch_manifest( true );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$current_target = self::public_manifest( $manifest );
		foreach ( array( 'version', 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest', 'size_bytes' ) as $field ) {
			if ( ! array_key_exists( $field, $plan['target'] ) || ! array_key_exists( $field, $current_target ) || (string) $plan['target'][ $field ] !== (string) $current_target[ $field ] ) {
				return new WP_Error( 'mad4b_self_update_native_release_drift', 'Governed release channel changed after native update planning.', array( 'field' => $field ) );
			}
		}

		$tmp = self::download_governed_release_to_protected_storage( $manifest );
		if ( is_wp_error( $tmp ) ) return $tmp;

		$verified = self::verify_archive( $tmp, $manifest );
		if ( is_wp_error( $verified ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $verified;
		}

		$result = self::apply_verified_archive( $tmp, $manifest, 'governed_native_release_pull', $expected );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $result;
	}

	public static function plugin_action_links( $links ) {
		$links = is_array( $links ) ? $links : array();
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) || ! self::environment_allowed( false ) ) return $links;

		$manifest = self::fetch_manifest();
		if ( is_wp_error( $manifest ) || ! self::update_available( $manifest ) ) return $links;

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mad4b_control_plane_native_update' ),
			'mad4b_control_plane_native_update'
		);
		$label = sprintf(
			/* translators: %s: target version. */
			__( 'Update MAD4B to %s', 'mad4b-site-control-plane' ),
			$manifest['display_version']
		);
		$links['mad4b_update'] = '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</a>';
		return $links;
	}

	public static function render_update_row( $plugin_file, $plugin_data, $status ) {
		unset( $plugin_data, $status );
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) || ! self::environment_allowed( false ) ) return;
		if ( plugin_basename( MAD4B_SCP_FILE ) !== (string) $plugin_file ) return;

		$manifest = self::fetch_manifest();
		if ( is_wp_error( $manifest ) || ! self::update_available( $manifest ) ) return;

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mad4b_control_plane_native_update' ),
			'mad4b_control_plane_native_update'
		);
		$message = sprintf(
			/* translators: 1: target version, 2: short source commit. */
			__( 'A governed MAD4B update is available: %1$s (build %2$s).', 'mad4b-site-control-plane' ),
			$manifest['version'],
			substr( $manifest['source_commit_sha'], 0, 12 )
		);
		echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>'
			. esc_html( $message ) . ' <a href="' . esc_url( $url ) . '">'
			. esc_html__( 'Update now', 'mad4b-site-control-plane' ) . '</a></p></div></td></tr>';
	}

	public static function handle_native_update() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) wp_die( esc_html__( 'You are not allowed to update plugins.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( 'mad4b_control_plane_native_update' );
		if ( ! self::environment_allowed( false ) ) wp_die( esc_html__( 'MAD4B self-update is not enabled for this environment.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );

		$manifest = self::fetch_manifest( true );
		if ( is_wp_error( $manifest ) ) self::redirect_native_result( 'manifest_error', $manifest->get_error_code() );
		if ( ! self::update_available( $manifest ) ) self::redirect_native_result( 'current', '' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $manifest['package_url'], 30 );
		if ( is_wp_error( $tmp ) ) self::redirect_native_result( 'download_error', $tmp->get_error_code() );

		$verified = self::verify_archive( $tmp, $manifest );
		if ( is_wp_error( $verified ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::redirect_native_result( 'verify_error', $verified->get_error_code() );
		}

		$result = self::apply_verified_archive( $tmp, $manifest, 'wordpress_admin_plugin_update', '' );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_wp_error( $result ) ) self::redirect_native_result( 'apply_error', $result->get_error_code() );
		self::redirect_native_result( 'success', '' );
	}

	public static function native_update_notice() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) return;
		$state = isset( $_GET['mad4b_control_plane_update'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_control_plane_update'] ) ) : '';
		if ( '' === $state ) return;
		$code = isset( $_GET['mad4b_update_code'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_update_code'] ) ) : '';
		if ( 'success' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'MAD4B Site Control Plane updated and exact-build readback passed.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}
		if ( 'current' === $state ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'MAD4B Site Control Plane is already on the current governed build.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}
		$message = __( 'MAD4B Site Control Plane update did not complete.', 'mad4b-site-control-plane' );
		if ( '' !== $code ) $message .= ' ' . sprintf( __( 'Reason: %s', 'mad4b-site-control-plane' ), $code );
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function redirect_native_result( $state, $code ) {
		$args = array( 'mad4b_control_plane_update' => sanitize_key( (string) $state ) );
		if ( '' !== (string) $code ) $args['mad4b_update_code'] = sanitize_key( (string) $code );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'plugins.php' ) ) );
		exit;
	}

	private static function update_available( array $manifest ) {
		$current = self::installed_identity();
		if ( ! empty( $current['source_commit_sha'] ) && hash_equals( $current['source_commit_sha'], $manifest['source_commit_sha'] ) ) return false;
		if ( ! empty( $current['version'] ) && version_compare( $current['version'], $manifest['version'], '>' ) ) return false;
		return true;
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
			'contract' => 'governed_native_release_pull' === (string) $channel ? self::NATIVE_APPLY_CONTRACT : self::APPLY_CONTRACT,
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

	private static function download_governed_release_to_protected_storage( array $manifest ) {
		$tmp = self::temp_archive_path();
		if ( is_wp_error( $tmp ) ) return $tmp;

		$response = wp_safe_remote_get(
			$manifest['package_url'],
			array(
				'timeout' => 30,
				'redirection' => 3,
				'stream' => true,
				'filename' => $tmp,
				'limit_response_size' => self::MAX_UPLOAD_BYTES + 1,
				'user-agent' => 'MAD4B-Site-Control-Plane/' . MAD4B_SCP_VERSION,
				'headers' => array( 'Accept' => 'application/zip' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_native_download_failed', 'Unable to download the exact governed Control Plane release.', array( 'cause' => $response->get_error_code() ) );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_native_download_http_error', 'Governed Control Plane release returned a non-200 response.' );
		}

		$size = @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || (int) $size !== (int) $manifest['size_bytes'] || (int) $size > self::MAX_UPLOAD_BYTES ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_native_size_mismatch', 'Downloaded governed release size does not match the exact manifest.' );
		}
		@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $tmp;
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
		if ( empty( $manifest['published_from_master'] ) ) return new WP_Error( 'mad4b_self_update_master_publication_missing', 'Update manifest is not bound to an exact master publication.' );
		if ( empty( $manifest['release_root_trust_verified'] ) ) return new WP_Error( 'mad4b_self_update_release_root_trust_missing', 'Update manifest is not bound to verified release-root trust.' );
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

	private static function installed_plugin_version_from_disk() {
		$path = defined( 'MAD4B_SCP_FILE' ) ? (string) MAD4B_SCP_FILE : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) return '';

		if ( function_exists( 'get_file_data' ) ) {
			$headers = get_file_data( $path, array( 'Version' => 'Version' ), 'plugin' );
			$version = isset( $headers['Version'] ) ? trim( (string) $headers['Version'] ) : '';
			if ( '' !== $version ) return $version;
		}

		$raw = file_get_contents( $path, false, null, 0, 8192 );
		if ( ! is_string( $raw ) ) return '';
		if ( 1 !== preg_match( '/^[ \\t\\/*#@]*Version:\\s*(.+)$/mi', $raw, $matches ) ) return '';
		return trim( (string) $matches[1] );
	}

	private static function installed_provenance() {
		$path = rtrim( (string) MAD4B_SCP_DIR, "/\\\\" ) . '/MAD4B-BUILD-PROVENANCE.json';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return array();
		$raw = file_get_contents( $path );
		$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $row ) ? $row : array();
	}

	private static function installed_identity() {
		$disk_version = self::installed_plugin_version_from_disk();
		$identity = array(
			'version' => '' !== $disk_version ? $disk_version : ( defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '' ),
			'source_commit_sha' => '',
			'build_fingerprint' => '',
			'package_manifest_digest' => '',
		);
		$row = self::installed_provenance();
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( isset( $row[ $field ] ) ) $identity[ $field ] = strtolower( trim( (string) $row[ $field ] ) );
		}
		return $identity;
	}

	private static function verify_installed_identity( array $target ) {
		$current = self::installed_identity();
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( empty( $current[ $field ] ) || ! hash_equals( $target[ $field ], $current[ $field ] ) ) return new WP_Error( 'mad4b_self_update_installed_identity_mismatch', 'Installed Control Plane identity does not match the target after replacement.', array( 'field' => $field, 'current' => isset( $current[ $field ] ) ? $current[ $field ] : '' ) );
		}

		$target_version = isset( $target['version'] ) ? trim( (string) $target['version'] ) : '';
		$disk_version = isset( $current['version'] ) ? trim( (string) $current['version'] ) : '';
		$provenance = self::installed_provenance();
		$provenance_version = isset( $provenance['control_plane_version'] ) ? trim( (string) $provenance['control_plane_version'] ) : '';
		$loaded_version = defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '';

		if ( '' === $disk_version || ( '' !== $target_version && ! hash_equals( $target_version, $disk_version ) ) ) {
			return new WP_Error( 'mad4b_self_update_installed_version_mismatch', 'Installed Control Plane plugin header version does not match the target after replacement.', array(
				'target_version' => $target_version,
				'installed_disk_version' => $disk_version,
				'loaded_runtime_version' => $loaded_version,
			) );
		}
		if ( '' === $provenance_version || ( '' !== $target_version && ! hash_equals( $target_version, $provenance_version ) ) ) {
			return new WP_Error( 'mad4b_self_update_installed_provenance_version_mismatch', 'Installed Control Plane provenance version does not match the target after replacement.', array(
				'target_version' => $target_version,
				'installed_disk_version' => $disk_version,
				'provenance_version' => $provenance_version,
				'loaded_runtime_version' => $loaded_version,
			) );
		}
		if ( ! hash_equals( $disk_version, $provenance_version ) ) {
			return new WP_Error( 'mad4b_self_update_disk_provenance_version_mismatch', 'Installed Control Plane plugin header and provenance versions disagree after replacement.', array(
				'installed_disk_version' => $disk_version,
				'provenance_version' => $provenance_version,
				'loaded_runtime_version' => $loaded_version,
			) );
		}
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
		if ( ! class_exists( 'MAD4B_SCP_Policy' ) ) return new WP_Error( 'mad4b_self_update_protected_storage_unavailable', 'Protected MAD4B storage policy is unavailable.' );
		$root = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $root ) ) return $root;
		$base = trailingslashit( $root ) . 'control-plane-upload-staging';
		if ( is_link( $base ) ) return new WP_Error( 'mad4b_self_update_temp_symlink_forbidden', 'Self-update staging directory cannot be a symlink.' );
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_self_update_temp_dir_failed', 'Unable to prepare protected self-update staging directory.' );
		@chmod( $base, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_writable( $base ) ) return new WP_Error( 'mad4b_self_update_temp_dir_not_writable', 'Protected self-update staging directory is not writable.' );
		return trailingslashit( $base ) . wp_generate_uuid4() . '.zip';
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
			&& true === MAD4B_SCP_PRODUCTION_SELF_UPDATE_ENABLED
			&& class_exists( 'MAD4B_SCP_Site_Profile' )
			&& MAD4B_SCP_Site_Profile::environment_allowed( array( 'production' ), 'write' );
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

	private static function native_plan_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			'required' => array( 'reason' ),
			'additionalProperties' => false,
		);
	}

	private static function native_apply_schema() {
		$schema = self::native_plan_schema();
		$schema['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$schema['required'][] = 'expected_plan_sha256';
		return $schema;
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
