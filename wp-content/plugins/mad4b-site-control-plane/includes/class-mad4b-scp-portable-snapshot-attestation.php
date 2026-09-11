<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Adds a non-derivable external package proof to each portable Plugin export.
 *
 * The deterministic MAD4B-SNAPSHOT-ID.txt remains useful for local/admin
 * diagnostics, but it is deliberately NOT sufficient for external acceptance.
 * Each successful wp-admin export receives a fresh cryptographic nonce that is
 * embedded only in the exported package. WordPress stores only its SHA-256
 * digest, exact candidate/build binding, snapshot binding and expiry.
 */
final class MAD4B_SCP_Portable_Snapshot_Attestation {
	const CONTRACT = 'mad4b.portable-external-snapshot.v3';
	const PAGE_SLUG = 'mad4b-control-plane-skills';
	const TOKEN_LEDGER_OPTION = 'mad4b_scp_external_snapshot_export_tokens_v3';
	const TOKEN_TTL = 21600; // Six hours from authenticated wp-admin export.
	const MAX_TOKENS = 5;

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
		$snapshot_token = isset( $export['identity_token'] ) ? strtolower( trim( (string) $export['identity_token'] ) ) : '';
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
	 * Generate one export-only proof. The plaintext nonce is returned only to the
	 * ZIP builder; persistent WordPress state receives its digest, never the nonce.
	 */
	public static function issue_external_token( $snapshot_token, array $candidate ) {
		$snapshot_token = strtolower( trim( (string) $snapshot_token ) );
		if ( 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $snapshot_token ) ) return new WP_Error( 'mad4b_external_snapshot_identity_invalid', 'Snapshot identity is invalid.' );
		if ( empty( $candidate['ready'] ) || 1 !== preg_match( '/^[a-f0-9]{40}$/', isset( $candidate['candidate_sha'] ) ? (string) $candidate['candidate_sha'] : '' ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', isset( $candidate['build_fingerprint'] ) ? (string) $candidate['build_fingerprint'] : '' ) ) {
			return new WP_Error( 'mad4b_external_snapshot_candidate_unavailable', 'Exact candidate provenance is required before an external package proof can be issued.' );
		}
		try {
			$secret = random_bytes( 32 );
		} catch ( Throwable $e ) {
			return new WP_Error( 'mad4b_external_snapshot_random_unavailable', 'Cryptographic randomness is unavailable; portable external proof cannot be issued.' );
		}
		$token = 'mad4bext_' . bin2hex( $secret );
		unset( $secret );
		$issued = time();
		$record = array(
			'contract' => self::CONTRACT,
			'token_digest' => hash( 'sha256', $token ),
			'candidate_sha' => strtolower( (string) $candidate['candidate_sha'] ),
			'build_fingerprint' => strtolower( (string) $candidate['build_fingerprint'] ),
			'snapshot_identity_digest' => hash( 'sha256', $snapshot_token ),
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'issued_at' => gmdate( 'c', $issued ),
			'expires_at' => gmdate( 'c', $issued + self::TOKEN_TTL ),
		);
		return array( 'external_snapshot_token' => $token, 'record' => $record );
	}

	public static function validate_external_token( $token, array $candidate ) {
		$token = strtolower( trim( (string) $token ) );
		if ( 1 !== preg_match( '/^mad4bext_[a-f0-9]{64}$/', $token ) ) return array( 'valid' => false, 'reason' => 'external_package_token_invalid' );
		return self::validate_token_digest( hash( 'sha256', $token ), $candidate );
	}

	public static function validate_token_digest( $digest, array $candidate ) {
		$digest = strtolower( trim( (string) $digest ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) || empty( $candidate['ready'] ) ) return array( 'valid' => false, 'reason' => 'external_package_token_invalid' );
		$live = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		$live_token = isset( $live['identity_token'] ) ? strtolower( trim( (string) $live['identity_token'] ) ) : '';
		if ( 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $live_token ) ) return array( 'valid' => false, 'reason' => 'local_snapshot_identity_unavailable' );
		$live_digest = hash( 'sha256', $live_token );
		$ledger = get_option( self::TOKEN_LEDGER_OPTION, array() );
		if ( ! is_array( $ledger ) ) $ledger = array();
		$now = time();
		foreach ( array_reverse( $ledger ) as $record ) {
			if ( ! is_array( $record ) || self::CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) continue;
			if ( empty( $record['token_digest'] ) || ! hash_equals( $digest, strtolower( (string) $record['token_digest'] ) ) ) continue;
			$expires = ! empty( $record['expires_at'] ) ? strtotime( (string) $record['expires_at'] ) : false;
			if ( false === $expires || $expires < $now ) return array( 'valid' => false, 'reason' => 'external_package_token_expired' );
			if ( empty( $record['candidate_sha'] ) || ! hash_equals( (string) $candidate['candidate_sha'], (string) $record['candidate_sha'] ) ) return array( 'valid' => false, 'reason' => 'candidate_mismatch' );
			if ( empty( $record['build_fingerprint'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], (string) $record['build_fingerprint'] ) ) return array( 'valid' => false, 'reason' => 'build_fingerprint_mismatch' );
			if ( empty( $record['snapshot_identity_digest'] ) || ! hash_equals( $live_digest, (string) $record['snapshot_identity_digest'] ) ) return array( 'valid' => false, 'reason' => 'snapshot_changed_after_export' );
			if ( defined( 'MAD4B_SCP_VERSION' ) && ! empty( $record['control_plane_version'] ) && ! hash_equals( (string) MAD4B_SCP_VERSION, (string) $record['control_plane_version'] ) ) return array( 'valid' => false, 'reason' => 'control_plane_version_mismatch' );
			return array(
				'valid' => true,
				'token_digest' => $digest,
				'candidate_sha' => (string) $record['candidate_sha'],
				'build_fingerprint' => (string) $record['build_fingerprint'],
				'snapshot_identity_digest' => (string) $record['snapshot_identity_digest'],
				'issued_at' => isset( $record['issued_at'] ) ? (string) $record['issued_at'] : '',
				'expires_at' => isset( $record['expires_at'] ) ? (string) $record['expires_at'] : '',
			);
		}
		return array( 'valid' => false, 'reason' => 'external_package_token_unknown' );
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
		$issued = self::issue_external_token( $snapshot_token, $candidate );
		if ( is_wp_error( $issued ) ) return $issued;
		$external_token = (string) $issued['external_snapshot_token'];
		$record = $issued['record'];

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) return new WP_Error( 'mad4b_external_snapshot_zip_open_failed', 'Portable Plugin ZIP could not be reopened for external snapshot metadata.' );
		$raw = $zip->getFromName( 'plugin.json' );
		$manifest = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $manifest ) ) { $zip->close(); return new WP_Error( 'mad4b_external_snapshot_manifest_invalid', 'Portable plugin.json is missing or invalid.' ); }

		$line = 'MAD4B external package proof ' . $external_token . '. Present this exact export-only token to mad4b/snapshot-verify after Scan Tools.';
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
			'candidate_sha' => $candidate['candidate_sha'],
			'build_fingerprint' => $candidate['build_fingerprint'],
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			'expires_at' => isset( $record['expires_at'] ) ? (string) $record['expires_at'] : '',
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

		$persisted = self::persist_record( $record );
		if ( is_wp_error( $persisted ) ) return $persisted;
		return array(
			'contract' => self::CONTRACT,
			'external_snapshot_token' => $external_token,
			'candidate_sha' => $candidate['candidate_sha'],
			'build_fingerprint' => $candidate['build_fingerprint'],
			'expires_at' => (string) $record['expires_at'],
		);
	}

	private static function persist_record( array $record ) {
		$ledger = get_option( self::TOKEN_LEDGER_OPTION, array() );
		if ( ! is_array( $ledger ) ) $ledger = array();
		$now = time();
		$kept = array();
		foreach ( $ledger as $item ) {
			if ( ! is_array( $item ) || self::CONTRACT !== ( isset( $item['contract'] ) ? (string) $item['contract'] : '' ) ) continue;
			$expires = ! empty( $item['expires_at'] ) ? strtotime( (string) $item['expires_at'] ) : false;
			if ( false !== $expires && $expires >= $now ) $kept[] = $item;
		}
		$kept[] = $record;
		if ( count( $kept ) > self::MAX_TOKENS ) $kept = array_slice( $kept, -1 * self::MAX_TOKENS );
		if ( false === update_option( self::TOKEN_LEDGER_OPTION, array_values( $kept ), false ) ) {
			return new WP_Error( 'mad4b_external_snapshot_token_persist_failed', 'External package proof digest could not be persisted; export is denied.' );
		}
		return true;
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
