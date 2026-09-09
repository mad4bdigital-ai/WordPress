<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Audited wrapper for local wp-admin supporting-file writes.
 *
 * Every supporting-file mutation passes through this wrapper so append-only
 * audit evidence and verified rollback are part of the mutation contract.
 */
final class MAD4B_SCP_Skill_Resource_Writer {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_init', array( __CLASS__, 'intercept_admin_save' ), 0 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	public static function intercept_admin_save() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_POST['mad4b_skill_action'] ) || 'save_resource' !== sanitize_key( wp_unslash( $_POST['mad4b_skill_action'] ) ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		if ( MAD4B_SCP_Skills_Admin_UI::PAGE_SLUG !== $page ) return;

		check_admin_referer( 'mad4b_skill_resource_save', 'mad4b_skill_resource_nonce' );
		$level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_key( wp_unslash( $_POST['name'] ) ) : '';
		$relative = isset( $_POST['resource_path'] ) ? sanitize_text_field( wp_unslash( $_POST['resource_path'] ) ) : '';
		$content = isset( $_POST['resource_content'] ) ? wp_unslash( $_POST['resource_content'] ) : '';

		$result = self::save( $level, $target, $name, $relative, $content );
		$query = array(
			'page' => MAD4B_SCP_Skills_Admin_UI::PAGE_SLUG,
			'level' => $level,
			'target' => $target,
			'skill' => $name,
			'mad4b_skill_notice' => is_wp_error( $result ) ? 'resource_error' : 'resource_saved',
		);
		if ( is_wp_error( $result ) ) $query['mad4b_skill_error'] = sanitize_key( $result->get_error_code() );
		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save( $level, $target, $name, $relative, $content ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_skill_capability_denied', 'Administrator capability is required to author skill resources.' );
		if ( ! MAD4B_SCP_Skill_Registry::editor_enabled() ) return new WP_Error( 'mad4b_skill_editor_disabled', 'Skill resource authoring is disabled.' );

		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_skill_audit_unavailable', 'Append-only audit storage must be ready before a supporting skill file can be changed.' );

		$before = MAD4B_SCP_Skill_Resource_Reader::read( $level, $target, $name, $relative );
		$had_before = ! is_wp_error( $before );
		$before_sha = $had_before ? (string) $before['sha256'] : '';

		$written = MAD4B_SCP_Skill_Registry::save_text_resource( $level, $target, $name, $relative, $content );
		if ( is_wp_error( $written ) ) return $written;

		$audit = MAD4B_SCP_Audit::record(
			'mad4b/skill-resource-save',
			array(
				'logical_id' => isset( $written['logical_id'] ) ? (string) $written['logical_id'] : '',
				'resource' => isset( $written['resource'] ) ? (string) $written['resource'] : '',
				'before_sha256' => $before_sha,
				'after_sha256' => isset( $written['sha256'] ) ? (string) $written['sha256'] : '',
				'bytes' => isset( $written['bytes'] ) ? (int) $written['bytes'] : strlen( (string) $content ),
			),
			'ok'
		);
		if ( ! is_wp_error( $audit ) ) return $written;

		$rollback = self::rollback( $level, $target, $name, $relative, $before, $had_before );
		if ( is_wp_error( $rollback ) ) {
			return new WP_Error(
				'mad4b_skill_resource_rollback_failed',
				'Supporting Skill file audit failed and the previous resource state could not be verified after rollback.',
				array( 'audit_error' => $audit->get_error_code(), 'rollback_error' => $rollback->get_error_code() )
			);
		}
		return new WP_Error( 'mad4b_skill_resource_audit_failed', 'Supporting Skill file change was rolled back and verified because its audit event could not be committed.', array( 'audit_error' => $audit->get_error_code() ) );
	}

	private static function rollback( $level, $target, $name, $relative, $before, $had_before ) {
		if ( $had_before ) {
			$restored = MAD4B_SCP_Skill_Registry::save_text_resource( $level, $target, $name, $relative, (string) $before['content'] );
			if ( is_wp_error( $restored ) ) return $restored;
			$readback = MAD4B_SCP_Skill_Resource_Reader::read( $level, $target, $name, $relative );
			if ( is_wp_error( $readback ) || empty( $before['sha256'] ) || empty( $readback['sha256'] ) || ! hash_equals( (string) $before['sha256'], (string) $readback['sha256'] ) ) {
				return new WP_Error( 'mad4b_skill_resource_restore_mismatch', 'Restored resource does not match its pre-change digest.' );
			}
			return true;
		}

		if ( ! self::remove_new_resource( $level, $target, $name, $relative ) ) return new WP_Error( 'mad4b_skill_resource_remove_failed', 'New unaudited resource could not be removed.' );
		$readback = MAD4B_SCP_Skill_Resource_Reader::read( $level, $target, $name, $relative );
		if ( ! is_wp_error( $readback ) ) return new WP_Error( 'mad4b_skill_resource_remove_mismatch', 'New unaudited resource still exists after rollback.' );
		return true;
	}

	private static function remove_new_resource( $level, $target, $name, $relative ) {
		$level = sanitize_key( (string) $level );
		if ( ! in_array( $level, MAD4B_SCP_Skill_Registry::levels(), true ) ) return false;
		if ( 'site' === $level ) $target = '_site';
		else {
			$target = strtolower( trim( (string) $target ) );
			$target = preg_replace( '/[^a-z0-9._-]+/', '-', $target );
			$target = trim( (string) $target, '-._' );
		}
		$name = strtolower( trim( (string) $name ) );
		$relative = wp_normalize_path( ltrim( (string) $relative, '/' ) );
		if ( '' === $target || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) || '' === $relative || false !== strpos( $relative, '..' ) ) return false;
		$parts = explode( '/', $relative );
		if ( ! isset( $parts[0] ) || ! in_array( $parts[0], array( 'references', 'assets', 'scripts' ), true ) ) return false;
		foreach ( $parts as $part ) if ( '' === $part || $part !== sanitize_file_name( $part ) ) return false;

		$root = MAD4B_SCP_Skill_Registry::storage_root();
		$file = $root . '/' . $level . '/' . $target . '/' . $name . '/' . $relative;
		$root_real = is_dir( $root ) ? realpath( $root ) : false;
		$parent_real = is_dir( dirname( $file ) ) ? realpath( dirname( $file ) ) : false;
		if ( false === $root_real || false === $parent_real ) return false;
		$root_real = trailingslashit( wp_normalize_path( $root_real ) );
		$parent_real = trailingslashit( wp_normalize_path( $parent_real ) );
		if ( 0 !== strpos( $parent_real, $root_real ) || ! is_file( $file ) || is_link( $file ) ) return false;
		return @unlink( $file );
	}

	public static function render_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		if ( MAD4B_SCP_Skills_Admin_UI::PAGE_SLUG !== $page || empty( $_GET['mad4b_skill_notice'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$notice = sanitize_key( wp_unslash( $_GET['mad4b_skill_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'resource_saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Supporting Skill file saved with audit evidence.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}
		if ( 'resource_error' === $notice ) {
			$code = isset( $_GET['mad4b_skill_error'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_skill_error'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Supporting Skill file was not saved. Error: %s', 'mad4b-site-control-plane' ), $code ) ) . '</p></div>';
		}
	}
}
