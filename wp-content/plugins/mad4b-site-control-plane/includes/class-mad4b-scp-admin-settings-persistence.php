<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared AJAX persistence UX for ordinary MAD4B wp-admin settings.
 *
 * This layer never turns governance actions into auto-save. Only forms that
 * explicitly opt in with the mad4b-settings-ajax-form class are handled here.
 * Server handlers remain authoritative for capability, nonce, revision and
 * persisted-readback validation.
 */
final class MAD4B_SCP_Admin_Settings_Persistence {
	const HANDLE = 'mad4b-admin-settings-persistence';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	public static function enqueue() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- route selection only.
		if ( '' === $page || 0 !== strpos( $page, 'mad4b-control-plane' ) ) return;

		$asset_path = MAD4B_SCP_DIR . 'assets/admin-settings-persistence.js';
		$asset_version = MAD4B_SCP_VERSION;
		if ( is_readable( $asset_path ) ) {
			$content_sha = hash_file( 'sha256', $asset_path );
			if ( is_string( $content_sha ) && preg_match( '/^[a-f0-9]{64}$/', $content_sha ) ) {
				$asset_version .= '-' . substr( $content_sha, 0, 12 );
			} else {
				$mtime = filemtime( $asset_path );
				if ( false !== $mtime ) $asset_version .= '-' . (string) $mtime;
			}
		}
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/admin-settings-persistence.js', MAD4B_SCP_FILE ),
			array(),
			$asset_version,
			true
		);
		wp_localize_script(
			self::HANDLE,
			'MAD4BAdminSettingsPersistence',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'saving' => __( 'Saving…', 'mad4b-site-control-plane' ),
				'saved' => __( 'Saved and verified.', 'mad4b-site-control-plane' ),
				'failed' => __( 'Settings could not be persisted.', 'mad4b-site-control-plane' ),
				'refreshFailed' => __( 'Settings were saved, but the persisted view could not be refreshed.', 'mad4b-site-control-plane' ),
			)
		);
	}
}

MAD4B_SCP_Admin_Settings_Persistence::boot();
