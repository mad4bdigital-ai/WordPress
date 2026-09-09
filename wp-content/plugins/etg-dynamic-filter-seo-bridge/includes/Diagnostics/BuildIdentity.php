<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

final class BuildIdentity {
	const CONTRACT = 'etg.dfsb.embedded-build-identity.v1';
	const MAX_BYTES = 4096;

	public static function collect(): array {
		$root = defined( 'ETG_DFSB_DIR' ) ? (string) ETG_DFSB_DIR : dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR;
		$version = defined( 'ETG_DFSB_VERSION' ) ? (string) ETG_DFSB_VERSION : '';
		return self::inspectFile( rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . 'build-identity.json', $version );
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
		);
	}
}
