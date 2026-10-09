<?php
/**
 * Resolve a Staging candidate's mutable source selector to one immutable Git SHA.
 *
 * The selector is evidence, not authorization. It cannot select a download URL,
 * repository credentials, target environment, grants, or installation method.
 * Every lookup goes to a fixed GitHub API origin and a host-allowlisted repository.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Staging_Source_Selector {
	const CONTRACT = 'mad4b.staging-source-selector.v1';

	private static function allowed_repositories() {
		$allowed = array( 'mad4bdigital-ai/WordPress' );
		if ( defined( 'MAD4B_SCP_STAGING_SOURCE_REPOSITORIES' ) ) {
			$custom = constant( 'MAD4B_SCP_STAGING_SOURCE_REPOSITORIES' );
			if ( is_array( $custom ) ) $allowed = $custom;
		}
		return array_values( array_filter( $allowed, static function ( $value ) {
			if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,39}\/[A-Za-z0-9_.-]{1,100}$/D', $value ) ) return false;
			$segments = explode( '/', $value );
			return ! in_array( $segments[0], array( '.', '..' ), true )
				&& ! in_array( $segments[1], array( '.', '..' ), true );
		} ) );
	}

	private static function reject( $code, $message ) {
		return new WP_Error( 'mad4b_staging_source_' . $code, $message );
	}

	public static function normalize( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'repository', 'type', 'reference' ) ) ) {
			return self::reject( 'schema_invalid', 'Staging source must contain only repository, type, and reference.' );
		}
		if ( ! isset( $input['repository'], $input['type'], $input['reference'] )
			|| ! is_string( $input['repository'] ) || ! is_string( $input['type'] ) || ! is_string( $input['reference'] ) ) {
			return self::reject( 'schema_invalid', 'Staging source repository, type, and reference are required strings.' );
		}
		$repo = $input['repository'];
		$type = $input['type'];
		$reference = $input['reference'];
		if ( ! in_array( $repo, self::allowed_repositories(), true ) ) {
			return self::reject( 'repository_denied', 'Staging source repository is not host-allowlisted.' );
		}
		if ( ! in_array( $type, array( 'pull_request', 'branch', 'commit' ), true ) ) {
			return self::reject( 'type_invalid', 'Staging source type is unsupported.' );
		}
		if ( 'pull_request' === $type ) {
			if ( ! preg_match( '/^[1-9][0-9]{0,8}$/D', $reference ) ) return self::reject( 'reference_invalid', 'Invalid PR reference.' );
		} elseif ( 'commit' === $type ) {
			if ( ! preg_match( '/^[a-f0-9]{40}$/D', $reference ) ) return self::reject( 'reference_invalid', 'Commit reference must be exact lowercase SHA-1.' );
		} else {
			if ( strlen( $reference ) > 120 || ! preg_match( '/^[A-Za-z0-9_][A-Za-z0-9._\/-]*$/D', $reference )
				|| false !== strpos( $reference, '..' ) || false !== strpos( $reference, '//' )
				|| false !== strpos( $reference, '@{' ) || '.' === substr( $reference, -1 )
				|| '/' === substr( $reference, -1 ) || '.lock' === substr( $reference, -5 ) ) {
				return self::reject( 'reference_invalid', 'Unsafe or invalid branch reference.' );
			}
		}
		return array( 'repository' => $repo, 'type' => $type, 'reference' => $reference );
	}

	public static function resolve( $input ) {
		$source = self::normalize( $input );
		if ( is_wp_error( $source ) ) return $source;
		if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'wp_remote_retrieve_response_code' ) ||
			! function_exists( 'wp_remote_retrieve_body' ) ) {
			return self::reject( 'transport_unavailable', 'Bounded WordPress HTTPS client is unavailable.' );
		}

		$repo = $source['repository'];
		$reference = $source['reference'];
		$type = $source['type'];
		if ( 'pull_request' === $type ) {
			$path = '/pulls/' . $reference;
		} elseif ( 'branch' === $type ) {
			$path = '/branches/' . rawurlencode( $reference );
		} else {
			$path = '/commits/' . $reference;
		}
		$url = 'https://api.github.com/repos/' . $repo . $path;
		$response = wp_remote_get( $url, array(
			'timeout' => 8,
			'redirection' => 0,
			'limit_response_size' => 262144,
			'sslverify' => true,
			'headers' => array(
				'Accept' => 'application/vnd.github+json',
				'User-Agent' => 'MAD4B-Staging-Candidate-Resolver',
			),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::reject( 'lookup_failed', 'Repository source reference cannot be verified against GitHub.' );
		}
		$raw = wp_remote_retrieve_body( $response );
		if ( ! is_string( $raw ) || strlen( $raw ) > 262144 ) return self::reject( 'response_invalid', 'Source resolution response is invalid.' );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) return self::reject( 'response_invalid', 'Source resolution did not return a JSON object.' );
		if ( 'pull_request' === $type ) {
			$head = isset( $data['head'] ) && is_array( $data['head'] ) ? $data['head'] : array();
			$head_repo = isset( $head['repo']['full_name'] ) ? (string) $head['repo']['full_name'] : '';
			if ( 'open' !== ( isset( $data['state'] ) ? $data['state'] : '' )
				|| ! hash_equals( strtolower( $repo ), strtolower( $head_repo ) ) ) {
				return self::reject( 'foreign_or_closed_pr', 'Staging PR must be open and originate from the allowlisted repository.' );
			}
			$sha = isset( $head['sha'] ) ? (string) $head['sha'] : '';
		} elseif ( 'branch' === $type ) {
			$sha = isset( $data['commit']['sha'] ) ? (string) $data['commit']['sha'] : '';
		} else {
			$sha = isset( $data['sha'] ) ? (string) $data['sha'] : '';
		}
		if ( ! preg_match( '/^[a-f0-9]{40}$/D', $sha ) || ( 'commit' === $type && ! hash_equals( $reference, $sha ) ) ) {
			return self::reject( 'source_sha_invalid', 'Repository source does not resolve to the expected immutable SHA.' );
		}
		return array(
			'contract' => self::CONTRACT,
			'repository' => $repo,
			'type' => $type,
			'reference' => $reference,
			'resolved_sha' => $sha,
			'remote_verified' => true,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}
}
