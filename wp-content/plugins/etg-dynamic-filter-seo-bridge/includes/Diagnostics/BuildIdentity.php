<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

final class BuildIdentity {
	const CONTRACT = 'etg.dfsb.embedded-build-identity.v1';
	const PROVENANCE_CONTRACT = 'etg.dfsb.release-provenance.v6';
	const INSTALLED_CONTENT_CONTRACT = 'etg.dfsb.installed-content-manifest.v1';
	const INSTALLED_CONTENT_MANIFEST = 'installed-content-manifest.json';
	const MAX_BYTES = 4096;
	const MAX_PROVENANCE_BYTES = 16384;
	const MAX_MANIFEST_BYTES = 262144;

	public static function collect(): array {
		$root = defined( 'ETG_DFSB_DIR' ) ? (string) ETG_DFSB_DIR : dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR;
		$root = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
		$version = defined( 'ETG_DFSB_VERSION' ) ? (string) ETG_DFSB_VERSION : '';
		$identity = self::inspectFile( $root . 'build-identity.json', $version );
		$installed = self::inspectInstalledContentManifest( $root, $root . self::INSTALLED_CONTENT_MANIFEST );

		$provenancePath = self::provenancePath( $identity );
		$provenance = self::inspectProvenanceFile( $provenancePath, $identity );
		$provenance['package_provenance_source'] = self::provenanceSource( $provenancePath, $identity );
		$provenance['package_provenance_required'] = defined( 'ETG_DFSB_PACKAGE_PROVENANCE_PATH' );
		if ( empty( $provenance['package_provenance_present'] ) && empty( $provenance['package_provenance_required'] ) ) {
			$provenance['package_provenance_reason'] = 'optional_detached_receipt_missing';
		}

		$identity = array_merge( $identity, $installed, $provenance );
		$detachedOkay = empty( $identity['package_provenance_present'] )
			? empty( $identity['package_provenance_required'] )
			: ! empty( $identity['package_provenance_valid'] );
		$identity['provenance_mode'] = 'self_contained_installed_manifest';
		$identity['provenance_complete'] = ! empty( $identity['valid'] )
			&& ! empty( $identity['installed_content_valid'] )
			&& $detachedOkay;
		$identity['exact_build_reason'] = self::exactBuildReason( $identity, $detachedOkay );
		return $identity;
	}

	public static function bootBuild( string $fallback = '' ): string {
		$identity = self::collect();
		if ( ! empty( $identity['valid'] ) ) {
			return 'identity:' . (string) $identity['git_sha'] . ':' . (string) $identity['tree_sha'];
		}
		if ( ! empty( $identity['embedded'] ) ) {
			$fingerprint = ! empty( $identity['embedded_identity_sha256'] )
				? (string) $identity['embedded_identity_sha256']
				: 'unreadable';
			return 'identity-invalid:' . (string) $identity['reason'] . ':' . $fingerprint;
		}
		$fallback = trim( $fallback );
		return 'fallback:' . ( '' !== $fallback ? $fallback : 'unknown' );
	}

	public static function inspectFile( string $path, string $expectedVersion = '' ): array {
		$result = self::baseResult();
		if ( '' === trim( $path ) || ! is_file( $path ) ) {
			return $result;
		}
		$result['embedded'] = true;
		if ( ! is_readable( $path ) ) {
			$result['reason'] = 'identity_file_unreadable';
			return $result;
		}
		$size = filesize( $path );
		if ( false === $size || $size < 2 || $size > self::MAX_BYTES ) {
			$result['reason'] = 'identity_file_size_invalid';
			return $result;
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			$result['reason'] = 'identity_file_read_failed';
			return $result;
		}
		$result['embedded_identity_sha256'] = hash( 'sha256', $raw );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			$result['reason'] = 'identity_json_invalid';
			return $result;
		}
		$allowed = array( 'contract', 'git_sha', 'tree_sha', 'plugin_version' );
		if ( array_diff( array_keys( $decoded ), $allowed ) || array_diff( $allowed, array_keys( $decoded ) ) ) {
			$result['reason'] = 'identity_fields_invalid';
			return $result;
		}
		if ( self::CONTRACT !== (string) $decoded['contract'] ) {
			$result['reason'] = 'identity_contract_mismatch';
			return $result;
		}
		$gitSha = strtolower( trim( (string) $decoded['git_sha'] ) );
		$treeSha = strtolower( trim( (string) $decoded['tree_sha'] ) );
		$version = trim( (string) $decoded['plugin_version'] );
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $gitSha ) || 1 !== preg_match( '/^[0-9a-f]{40}$/', $treeSha ) ) {
			$result['reason'] = 'identity_sha_invalid';
			return $result;
		}
		if ( '' === $version ) {
			$result['reason'] = 'identity_version_missing';
			return $result;
		}
		if ( '' !== $expectedVersion && $version !== $expectedVersion ) {
			$result['reason'] = 'identity_version_mismatch';
			$result['git_sha'] = $gitSha;
			$result['tree_sha'] = $treeSha;
			$result['plugin_version'] = $version;
			return $result;
		}
		$result['valid'] = true;
		$result['reason'] = 'ok';
		$result['git_sha'] = $gitSha;
		$result['tree_sha'] = $treeSha;
		$result['plugin_version'] = $version;
		return $result;
	}

	public static function inspectInstalledContentManifest( string $root, string $manifestPath = '' ): array {
		$result = self::installedContentBaseResult();
		$root = rtrim( trim( $root ), '/\\' );
		if ( '' === $root || ! is_dir( $root ) ) {
			$result['installed_content_reason'] = 'installed_content_root_missing';
			return $result;
		}
		if ( '' === $manifestPath ) {
			$manifestPath = $root . DIRECTORY_SEPARATOR . self::INSTALLED_CONTENT_MANIFEST;
		}
		if ( ! is_file( $manifestPath ) ) {
			return $result;
		}
		$result['installed_content_present'] = true;
		if ( ! is_readable( $manifestPath ) ) {
			$result['installed_content_reason'] = 'installed_content_manifest_unreadable';
			return $result;
		}
		$size = filesize( $manifestPath );
		if ( false === $size || $size < 2 || $size > self::MAX_MANIFEST_BYTES ) {
			$result['installed_content_reason'] = 'installed_content_manifest_size_invalid';
			return $result;
		}
		$raw = file_get_contents( $manifestPath );
		if ( ! is_string( $raw ) ) {
			$result['installed_content_reason'] = 'installed_content_manifest_read_failed';
			return $result;
		}
		$result['installed_content_manifest_sha256'] = hash( 'sha256', $raw );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			$result['installed_content_reason'] = 'installed_content_manifest_json_invalid';
			return $result;
		}
		$allowed = array( 'contract', 'algorithm', 'files' );
		if ( array_diff( array_keys( $decoded ), $allowed ) || array_diff( $allowed, array_keys( $decoded ) ) ) {
			$result['installed_content_reason'] = 'installed_content_manifest_fields_invalid';
			return $result;
		}
		if ( self::INSTALLED_CONTENT_CONTRACT !== (string) $decoded['contract'] || 'sha256' !== (string) $decoded['algorithm'] ) {
			$result['installed_content_reason'] = 'installed_content_manifest_contract_mismatch';
			return $result;
		}
		if ( ! is_array( $decoded['files'] ) || empty( $decoded['files'] ) ) {
			$result['installed_content_reason'] = 'installed_content_manifest_files_invalid';
			return $result;
		}

		$expected = array();
		foreach ( $decoded['files'] as $relative => $digest ) {
			$relative = (string) $relative;
			$digest = strtolower( trim( (string) $digest ) );
			if ( ! self::validRelativePath( $relative ) || self::isManifestExcludedPath( $relative ) ) {
				$result['installed_content_reason'] = 'installed_content_manifest_path_invalid';
				return $result;
			}
			if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $digest ) || array_key_exists( $relative, $expected ) ) {
				$result['installed_content_reason'] = 'installed_content_manifest_digest_invalid';
				return $result;
			}
			$expected[ $relative ] = $digest;
		}
		ksort( $expected, SORT_STRING );

		$actual = self::installedFiles( $root );
		if ( ! $actual['ok'] ) {
			$result['installed_content_reason'] = $actual['reason'];
			return $result;
		}
		$paths = $actual['files'];
		if ( array_keys( $expected ) !== array_keys( $paths ) ) {
			$result['installed_content_reason'] = 'installed_content_file_set_mismatch';
			$result['installed_content_expected_file_count'] = count( $expected );
			$result['installed_content_actual_file_count'] = count( $paths );
			return $result;
		}

		$context = hash_init( 'sha256' );
		foreach ( $expected as $relative => $wanted ) {
			$got = hash_file( 'sha256', $paths[ $relative ] );
			if ( false === $got ) {
				$result['installed_content_reason'] = 'installed_content_file_hash_failed:' . $relative;
				return $result;
			}
			$got = strtolower( $got );
			if ( $got !== $wanted ) {
				$result['installed_content_reason'] = 'installed_content_file_hash_mismatch:' . $relative;
				return $result;
			}
			hash_update( $context, $relative . "\0" . $got . "\n" );
		}

		$result['installed_content_valid'] = true;
		$result['installed_content_reason'] = 'ok';
		$result['installed_content_contract'] = self::INSTALLED_CONTENT_CONTRACT;
		$result['installed_content_file_count'] = count( $expected );
		$result['installed_content_sha256'] = hash_final( $context );
		return $result;
	}

	public static function inspectProvenanceFile( string $path, array $identity ): array {
		$result = self::provenanceBaseResult();
		if ( '' === trim( $path ) || ! is_file( $path ) ) {
			return $result;
		}
		$result['package_provenance_present'] = true;
		if ( ! is_readable( $path ) ) {
			$result['package_provenance_reason'] = 'package_provenance_file_unreadable';
			return $result;
		}
		$size = filesize( $path );
		if ( false === $size || $size < 2 || $size > self::MAX_PROVENANCE_BYTES ) {
			$result['package_provenance_reason'] = 'package_provenance_file_size_invalid';
			return $result;
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			$result['package_provenance_reason'] = 'package_provenance_file_read_failed';
			return $result;
		}

		$values = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			if ( false === strpos( $line, '=' ) ) {
				$result['package_provenance_reason'] = 'package_provenance_line_invalid';
				return $result;
			}
			list( $key, $value ) = explode( '=', $line, 2 );
			$key = trim( $key );
			if ( '' === $key ) {
				$result['package_provenance_reason'] = 'package_provenance_key_invalid';
				return $result;
			}
			if ( array_key_exists( $key, $values ) ) {
				$result['package_provenance_reason'] = 'package_provenance_duplicate_key';
				return $result;
			}
			$values[ $key ] = trim( $value );
		}

		$required = array(
			'contract',
			'git_sha',
			'tree_sha',
			'plugin_version',
			'package_sha256',
			'embedded_identity_contract',
			'embedded_identity_sha256',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $values ) || '' === $values[ $key ] ) {
				$result['package_provenance_reason'] = 'package_provenance_field_missing:' . $key;
				return $result;
			}
		}
		if ( self::PROVENANCE_CONTRACT !== $values['contract'] ) {
			$result['package_provenance_reason'] = 'package_provenance_contract_mismatch';
			return $result;
		}
		if ( self::CONTRACT !== $values['embedded_identity_contract'] ) {
			$result['package_provenance_reason'] = 'package_provenance_identity_contract_mismatch';
			return $result;
		}

		$gitSha = strtolower( $values['git_sha'] );
		$treeSha = strtolower( $values['tree_sha'] );
		$packageSha = strtolower( $values['package_sha256'] );
		$identitySha = strtolower( $values['embedded_identity_sha256'] );
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $gitSha ) || 1 !== preg_match( '/^[0-9a-f]{40}$/', $treeSha ) ) {
			$result['package_provenance_reason'] = 'package_provenance_source_sha_invalid';
			return $result;
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $packageSha ) ) {
			$result['package_provenance_reason'] = 'package_provenance_package_sha_invalid';
			return $result;
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $identitySha ) ) {
			$result['package_provenance_reason'] = 'package_provenance_identity_sha_invalid';
			return $result;
		}
		if ( empty( $identity['valid'] ) ) {
			$result['package_provenance_reason'] = 'package_provenance_identity_invalid';
			return $result;
		}
		if ( $gitSha !== strtolower( (string) $identity['git_sha'] ) ) {
			$result['package_provenance_reason'] = 'package_provenance_git_sha_mismatch';
			return $result;
		}
		if ( $treeSha !== strtolower( (string) $identity['tree_sha'] ) ) {
			$result['package_provenance_reason'] = 'package_provenance_tree_sha_mismatch';
			return $result;
		}
		if ( $values['plugin_version'] !== (string) $identity['plugin_version'] ) {
			$result['package_provenance_reason'] = 'package_provenance_version_mismatch';
			return $result;
		}
		if ( empty( $identity['embedded_identity_sha256'] ) || $identitySha !== strtolower( (string) $identity['embedded_identity_sha256'] ) ) {
			$result['package_provenance_reason'] = 'package_provenance_identity_sha_mismatch';
			return $result;
		}

		$result['package_provenance_valid'] = true;
		$result['package_provenance_reason'] = 'ok';
		$result['package_provenance_contract'] = self::PROVENANCE_CONTRACT;
		$result['package_sha256'] = $packageSha;
		return $result;
	}

	private static function installedFiles( string $root ): array {
		$root = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
		$files = array();
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $entry ) {
				if ( $entry->isLink() ) {
					return array( 'ok' => false, 'reason' => 'installed_content_symlink_refused', 'files' => array() );
				}
				if ( ! $entry->isFile() ) {
					continue;
				}
				$path = $entry->getPathname();
				$relative = substr( $path, strlen( $root ) );
				$relative = str_replace( '\\', '/', (string) $relative );
				if ( self::isManifestExcludedPath( $relative ) ) {
					continue;
				}
				if ( ! self::validRelativePath( $relative ) || ! is_readable( $path ) ) {
					return array( 'ok' => false, 'reason' => 'installed_content_file_unreadable', 'files' => array() );
				}
				$files[ $relative ] = $path;
			}
		} catch ( \UnexpectedValueException $e ) {
			return array( 'ok' => false, 'reason' => 'installed_content_scan_failed', 'files' => array() );
		}
		ksort( $files, SORT_STRING );
		return array( 'ok' => true, 'reason' => 'ok', 'files' => $files );
	}

	private static function validRelativePath( string $relative ): bool {
		if ( '' === $relative || '/' === $relative[0] || '\\' === $relative[0] || false !== strpos( $relative, "\0" ) ) {
			return false;
		}
		$parts = explode( '/', str_replace( '\\', '/', $relative ) );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	private static function isManifestExcludedPath( string $relative ): bool {
		$relative = str_replace( '\\', '/', $relative );
		return in_array(
			$relative,
			array( 'build-identity.json', self::INSTALLED_CONTENT_MANIFEST, 'etg-dfsb-provenance.txt' ),
			true
		);
	}

	private static function provenancePath( array $identity = array() ): string {
		if ( defined( 'ETG_DFSB_PACKAGE_PROVENANCE_PATH' ) ) {
			return (string) ETG_DFSB_PACKAGE_PROVENANCE_PATH;
		}

		$persistent = self::persistentProvenancePath( $identity );
		if ( '' !== $persistent && is_file( $persistent ) ) {
			return $persistent;
		}

		$root = defined( 'ETG_DFSB_DIR' ) ? (string) ETG_DFSB_DIR : dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR;
		$fallback = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . 'etg-dfsb-provenance.txt';
		return is_file( $fallback ) ? $fallback : '';
	}

	private static function persistentProvenancePath( array $identity ): string {
		if ( empty( $identity['valid'] ) || empty( $identity['git_sha'] ) || ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}
		$contentRoot = trim( (string) WP_CONTENT_DIR );
		$gitSha = strtolower( trim( (string) $identity['git_sha'] ) );
		if ( '' === $contentRoot || 1 !== preg_match( '/^[0-9a-f]{40}$/', $gitSha ) ) {
			return '';
		}
		return rtrim( $contentRoot, '/\\' )
			. DIRECTORY_SEPARATOR . 'mad4b'
			. DIRECTORY_SEPARATOR . 'provenance'
			. DIRECTORY_SEPARATOR . 'etg-dfsb'
			. DIRECTORY_SEPARATOR . $gitSha
			. DIRECTORY_SEPARATOR . 'etg-dfsb-provenance.txt';
	}

	private static function provenanceSource( string $path, array $identity ): string {
		if ( defined( 'ETG_DFSB_PACKAGE_PROVENANCE_PATH' ) ) {
			return 'explicit_override';
		}
		if ( '' === $path ) {
			return 'none';
		}
		$persistent = self::persistentProvenancePath( $identity );
		if ( '' !== $persistent && $path === $persistent ) {
			return 'persistent_sha_store';
		}
		return 'plugin_root_fallback';
	}

	private static function exactBuildReason( array $identity, bool $detachedOkay ): string {
		if ( empty( $identity['valid'] ) ) {
			return 'embedded_identity_invalid';
		}
		if ( empty( $identity['installed_content_valid'] ) ) {
			return (string) ( $identity['installed_content_reason'] ?? 'installed_content_invalid' );
		}
		if ( ! $detachedOkay ) {
			return (string) ( $identity['package_provenance_reason'] ?? 'detached_provenance_conflict' );
		}
		return 'ok';
	}

	private static function baseResult(): array {
		return array(
			'contract' => self::CONTRACT,
			'authorizing' => false,
			'read_only' => true,
			'embedded' => false,
			'valid' => false,
			'reason' => 'identity_file_missing',
			'git_sha' => '',
			'tree_sha' => '',
			'plugin_version' => '',
			'embedded_identity_sha256' => '',
		);
	}

	private static function installedContentBaseResult(): array {
		return array(
			'installed_content_present' => false,
			'installed_content_valid' => false,
			'installed_content_reason' => 'installed_content_manifest_missing',
			'installed_content_contract' => self::INSTALLED_CONTENT_CONTRACT,
			'installed_content_manifest_sha256' => '',
			'installed_content_sha256' => '',
			'installed_content_file_count' => 0,
			'installed_content_expected_file_count' => 0,
			'installed_content_actual_file_count' => 0,
		);
	}

	private static function provenanceBaseResult(): array {
		return array(
			'package_provenance_present' => false,
			'package_provenance_valid' => false,
			'package_provenance_reason' => 'package_provenance_file_missing',
			'package_provenance_contract' => self::PROVENANCE_CONTRACT,
			'package_sha256' => '',
			'package_provenance_source' => '',
			'package_provenance_required' => false,
		);
	}
}
