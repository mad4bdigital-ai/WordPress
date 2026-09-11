<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Makes the exported snapshot independently provable by the installed external
 * ChatGPT Plugin without exposing WordPress-local expected evidence through MCP.
 *
 * MAD4B-SNAPSHOT-ID.txt remains the raw deterministic Skill snapshot identity.
 * The external client receives a separate candidate-bound package token derived
 * from that identity + exact source SHA + build fingerprint + Control Plane
 * version. Only possession of the exported package reveals the external token.
 */
final class MAD4B_SCP_Portable_Snapshot_Attestation {
	const CONTRACT = 'mad4b.portable-external-snapshot.v2';
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
		$snapshot_token = isset( $export['identity_token'] ) ? trim( (string) $export['identity_token'] ) : '';
		if ( '' === $path || ! is_file( $path ) || 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $snapshot_token ) ) {
			if ( $path && is_file( $path ) ) @unlink( $path );
			self::redirect_error( 'mad4b_external_snapshot_metadata_unavailable' );
		}

		$enriched = self::enrich_zip( $path, $snapshot_token );
		if ( is_wp_error( $enriched ) ) { @unlink( $path ); self::redirect_error( $enriched->get_error_code() ); }
		if ( headers_sent() ) { @unlink( $path ); self::redirect_error( 'mad4b_skill_export_headers_sent' ); }

		$filename = isset( $export['filename'] ) ? (string) $export['filename'] : 'mad4b-wordpress-skills.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', basename( $filename ) ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-MAD4B-SHA256: ' . hash_file( 'sha256', $path ) );
		header( 'X-MAD4B-Snapshot-Identity: ' . $snapshot_token );
		header( 'X-MAD4B-External-Snapshot-Token: ' . (string) $enriched['external_snapshot_token'] );
		header( 'X-MAD4B-Snapshot-Attestation-Contract: ' . self::CONTRACT );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $path );
		exit;
	}

	/**
	 * Deterministic external package token. The raw Skill snapshot identity is an
	 * input, but the external token is additionally exact-candidate/build bound.
	 * This prevents an older portable package with identical Skills from proving
	 * that a newer release candidate was actually refreshed in ChatGPT.
	 */
	public static function external_token( $snapshot_token, $candidate_sha, $build_fingerprint, $version = '' ) {
		$snapshot_token = strtolower( trim( (string) $snapshot_token ) );
		$candidate_sha = strtolower( trim( (string) $candidate_sha ) );
		$build_fingerprint = strtolower( trim( (string) $build_fingerprint ) );
		$version = '' !== (string) $version ? (string) $version : ( defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '' );
		if ( 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $snapshot_token ) ) return '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $candidate_sha ) ) return '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $build_fingerprint ) ) return '';
		if ( '' === $version ) return '';
		$payload = array(
			'contract' => self::CONTRACT,
			'snapshot_identity_token' => $snapshot_token,
			'candidate_sha' => $candidate_sha,
			'build_fingerprint' => $build_fingerprint,
			'control_plane_version' => $version,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : 'sha256:' . hash( 'sha256', $json );
	}

	public static function current_candidate() {
		$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$sha = isset( $provenance['source_commit_sha'] ) ? strtolower( trim( (string) $provenance['source_commit_sha'] ) ) : '';
		$fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( trim( (string) $provenance['build_fingerprint'] ) ) : '';
		$ready = ! empty( $provenance['runtime_manifest_match'] ) && 1 === preg_match( '/^[a-f0-9]{40}$/', $sha ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint );
		return array( 'ready' => (bool) $ready, 'candidate_sha' => $sha, 'build_fingerprint' => $fingerprint );
	}

	public static function enrich_zip( $path, $snapshot_token ) {
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_external_snapshot_zip_unavailable', 'PHP ZipArchive is required to enrich portable snapshot metadata.' );
		$candidate = self::current_candidate();
		if ( empty( $candidate['ready'] ) ) return new WP_Error( 'mad4b_external_snapshot_candidate_unavailable', 'Exact candidate provenance is required before portable external snapshot metadata can be produced.' );
		$external_token = self::external_token( $snapshot_token, $candidate['candidate_sha'], $candidate['build_fingerprint'] );
		if ( '' === $external_token ) return new WP_Error( 'mad4b_external_snapshot_token_unavailable', 'Candidate-bound external snapshot token could not be computed.' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) return new WP_Error( 'mad4b_external_snapshot_zip_open_failed', 'Portable Plugin ZIP could not be reopened for external snapshot metadata.' );
		$raw = $zip->getFromName( 'plugin.json' );
		$manifest = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $manifest ) ) { $zip->close(); return new WP_Error( 'mad4b_external_snapshot_manifest_invalid', 'Portable plugin.json is missing or invalid.' ); }

		$line = 'MAD4B external package attestation ' . $external_token . '. Present this exact package token to mad4b/snapshot-verify after Scan Tools.';
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
			'external_snapshot_token' => $external_token,
			'snapshot_identity_token' => strtolower( trim( (string) $snapshot_token ) ),
			'candidate_sha' => $candidate['candidate_sha'],
			'build_fingerprint' => $candidate['build_fingerprint'],
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			'usage' => 'Return external_snapshot_token through mad4b/snapshot-verify from the installed external ChatGPT OAuth/MCP session after Scan Tools.',
		);
		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		$attestation_json = wp_json_encode( $attestation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		if ( false === $zip->addFromString( 'plugin.json', $manifest_json )
			|| false === $zip->addFromString( 'MAD4B-EXTERNAL-SNAPSHOT-ATTESTATION.json', $attestation_json )
			|| false === $zip->addFromString( 'MAD4B-EXTERNAL-SNAPSHOT-ID.txt', $external_token . "\n" ) ) {
			$zip->close();
			return new WP_Error( 'mad4b_external_snapshot_metadata_write_failed', 'Unable to persist external snapshot metadata into the portable package.' );
		}
		$zip->close();
		return array(
			'contract' => self::CONTRACT,
			'external_snapshot_token' => $external_token,
			'candidate_sha' => $candidate['candidate_sha'],
			'build_fingerprint' => $candidate['build_fingerprint'],
		);
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
