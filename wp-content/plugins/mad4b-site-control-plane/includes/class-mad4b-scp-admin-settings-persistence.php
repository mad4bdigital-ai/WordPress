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

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/admin-settings-persistence.js', MAD4B_SCP_FILE ),
			array(),
			MAD4B_SCP_VERSION,
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
