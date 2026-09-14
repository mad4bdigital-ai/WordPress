<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

final class BuildIdentity {
	const CONTRACT = 'etg.dfsb.embedded-build-identity.v1';
	const PROVENANCE_CONTRACT = 'etg.dfsb.release-provenance.v6';
	const MAX_BYTES = 4096;
	const MAX_PROVENANCE_BYTES = 16384;

	public static function collect(): array {
		$root = defined( 'ETG_DFSB_DIR' ) ? (string) ETG_DFSB_DIR : dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR;
		$version = defined( 'ETG_DFSB_VERSION' ) ? (string) ETG_DFSB_VERSION : '';
		$identity = self::inspectFile( rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . 'build-identity.json', $version );
		$provenance = self::inspectProvenanceFile( self::provenancePath(), $identity );
		$identity = array_merge( $identity, $provenance );
		$identity['provenance_complete'] = ! empty( $identity['valid'] ) && ! empty( $identity['package_provenance_valid'] );
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

	private static function identityPath(): string {
		$root = defined( 'ETG_DFSB_DIR' ) ? (string) ETG_DFSB_DIR : dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR;
		return rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . 'build-identity.json';
	}

	private static function provenancePath(): string {
		if ( defined( 'ETG_DFSB_PACKAGE_PROVENANCE_PATH' ) ) {
			return (string) ETG_DFSB_PACKAGE_PROVENANCE_PATH;
		}
		$root = defined( 'ETG_DFSB_DIR' ) ? (string) ETG_DFSB_DIR : dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR;
		return rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . 'etg-dfsb-provenance.txt';
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

	private static function provenanceBaseResult(): array {
		return array(
			'package_provenance_present' => false,
			'package_provenance_valid' => false,
			'package_provenance_reason' => 'package_provenance_file_missing',
			'package_provenance_contract' => self::PROVENANCE_CONTRACT,
			'package_sha256' => '',
		);
	}
}
