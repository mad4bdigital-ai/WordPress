<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Makes the exported snapshot identity observable to the installed external
 * ChatGPT Plugin without changing the snapshot that the token identifies.
 *
 * The token is injected only after MAD4B_SCP_Skill_Exporter has finished its
 * race-closed identity proof. The external client must still present this token
 * back through mad4b/snapshot-verify from a verified OAuth/MCP session before
 * Live Acceptance records external snapshot evidence.
 */
final class MAD4B_SCP_Portable_Snapshot_Attestation {
	const CONTRACT = 'mad4b.portable-external-snapshot.v1';
	const PAGE_SLUG = 'mad4b-control-plane-skills';

	private static $booted = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_init', array( __CLASS__, 'intercept_export' ), 0 );
	}

	public static function intercept_export() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_POST['mad4b_skill_action'] ) || 'export' !== sanitize_key( wp_unslash( $_POST['mad4b_skill_action'] ) ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		if ( self::PAGE_SLUG !== $page ) return;

		check_admin_referer( 'mad4b_skill_export', 'mad4b_skill_export_nonce' );
		if ( ! class_exists( 'MAD4B_SCP_Skill_Exporter' ) ) self::redirect_error( 'mad4b_skill_exporter_unavailable' );
		$export = MAD4B_SCP_Skill_Exporter::build_temp_zip();
		if ( is_wp_error( $export ) ) self::redirect_error( $export->get_error_code() );

		$path = isset( $export['path'] ) ? (string) $export['path'] : '';
		$token = isset( $export['identity_token'] ) ? trim( (string) $export['identity_token'] ) : '';
		if ( '' === $path || ! is_file( $path ) || 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $token ) ) {
			if ( $path && is_file( $path ) ) @unlink( $path );
			self::redirect_error( 'mad4b_external_snapshot_metadata_unavailable' );
		}

		$enriched = self::enrich_zip( $path, $token );
		if ( is_wp_error( $enriched ) ) { @unlink( $path ); self::redirect_error( $enriched->get_error_code() ); }
		if ( headers_sent() ) { @unlink( $path ); self::redirect_error( 'mad4b_skill_export_headers_sent' ); }

		$filename = isset( $export['filename'] ) ? (string) $export['filename'] : 'mad4b-wordpress-skills.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', basename( $filename ) ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-MAD4B-SHA256: ' . hash_file( 'sha256', $path ) );
		header( 'X-MAD4B-Snapshot-Identity: ' . $token );
		header( 'X-MAD4B-Snapshot-Attestation-Contract: ' . self::CONTRACT );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $path );
		exit;
	}

	public static function enrich_zip( $path, $token ) {
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_external_snapshot_zip_unavailable', 'PHP ZipArchive is required to enrich portable snapshot metadata.' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) return new WP_Error( 'mad4b_external_snapshot_zip_open_failed', 'Portable Plugin ZIP could not be reopened for external snapshot metadata.' );
		$raw = $zip->getFromName( 'plugin.json' );
		$manifest = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $manifest ) ) { $zip->close(); return new WP_Error( 'mad4b_external_snapshot_manifest_invalid', 'Portable plugin.json is missing or invalid.' ); }

		$line = 'MAD4B external snapshot identity ' . $token . '. Present this exact package token to mad4b/snapshot-verify after Scan Tools.';
		$description = isset( $manifest['description'] ) ? trim( (string) $manifest['description'] ) : '';
		$manifest['description'] = trim( $description . ' ' . $line );
		if ( ! isset( $manifest['extensions']['com.openai']['interface'] ) || ! is_array( $manifest['extensions']['com.openai']['interface'] ) ) {
			$zip->close();
			return new WP_Error( 'mad4b_external_snapshot_openai_interface_missing', 'Portable OpenAI interface metadata is missing.' );
		}
		$long = isset( $manifest['extensions']['com.openai']['interface']['longDescription'] ) ? trim( (string) $manifest['extensions']['com.openai']['interface']['longDescription'] ) : '';
		$manifest['extensions']['com.openai']['interface']['longDescription'] = trim( $long . ' ' . $line );

		$attestation = array(
			'contract' => self::CONTRACT,
			'snapshot_identity_token' => $token,
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			'usage' => 'Return snapshot_identity_token through mad4b/snapshot-verify from the installed external ChatGPT OAuth/MCP session.',
		);
		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		$attestation_json = wp_json_encode( $attestation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		if ( false === $zip->addFromString( 'plugin.json', $manifest_json ) || false === $zip->addFromString( 'MAD4B-EXTERNAL-SNAPSHOT-ATTESTATION.json', $attestation_json ) ) {
			$zip->close();
			return new WP_Error( 'mad4b_external_snapshot_metadata_write_failed', 'Unable to persist external snapshot metadata into the portable package.' );
		}
		$zip->close();
		return array( 'contract' => self::CONTRACT, 'snapshot_identity_token' => $token );
	}

	private static function redirect_error( $code ) {
		$url = add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'mad4b_skill_notice' => 'export_error', 'mad4b_skill_error' => sanitize_key( (string) $code ) ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}

MAD4B_SCP_Portable_Snapshot_Attestation::boot_early();
