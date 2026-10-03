<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cross-layer canonicalization policy.
 *
 * Security identities are canonical ASCII only; Unicode lookalikes are never
 * silently folded into another identity. Human/free-text values remain exact
 * UTF-8 codepoints, but hidden bidi/zero-width format controls are denied from
 * canonical hash material.
 */
final class MAD4B_SCP_Canonicalization {
	const CONTRACT = 'mad4b.dynamic-canonicalization.v1';
	const POLICY_CONTRACT = 'mad4b.canonicalization-policy.v1';

	public static function policy() {
		return array(
			'contract' => self::POLICY_CONTRACT,
			'unicode_mode' => 'exact_utf8_no_implicit_normalization',
			'security_identifier_mode' => 'canonical_ascii_only',
			'confusable_policy' => 'deny_non_ascii_security_identifiers',
			'hidden_format_controls' => 'deny',
			'url_userinfo' => 'deny',
			'url_fragments' => 'deny',
			'path_aliases' => 'deny',
			'header_names' => 'rfc_token_lowercase',
			'canonical_json_floats' => 'deny_without_explicit_field_contract',
			'authorizing' => false,
		);
	}

	public static function ability_name( $value ) {
		return self::ascii_identifier(
			$value,
			'ability_name',
			'#^[a-z0-9][a-z0-9._-]{0,95}/[a-z0-9][a-z0-9._-]{0,95}$#D'
		);
	}

	public static function semantic_operation_id( $value ) {
		return self::ascii_identifier(
			$value,
			'semantic_operation_id',
			'/^[a-z0-9][a-z0-9._-]{0,95}$/D'
		);
	}

	public static function resource_identifier( $value, $max_bytes = 191 ) {
		$value = trim( (string) $value );
		$max_bytes = max( 1, min( 1024, (int) $max_bytes ) );
		$valid = self::visible_ascii( $value, $max_bytes );
		if ( is_wp_error( $valid ) ) return new WP_Error(
			'mad4b_canonical_resource_identifier_invalid',
			'Resource identifier must be exact bounded visible ASCII without hidden/confusable Unicode.'
		);
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:@#$-]*$/D', $value ) ) {
			return new WP_Error( 'mad4b_canonical_resource_identifier_invalid', 'Resource identifier contains unsupported alias or delimiter characters.' );
		}
		return $value;
	}

	public static function relative_path( $value, $max_bytes = 1024 ) {
		$path = trim( (string) $value );
		$max_bytes = max( 1, min( 8192, (int) $max_bytes ) );
		$visible = self::visible_ascii( $path, $max_bytes );
		if ( is_wp_error( $visible ) ) return new WP_Error( 'mad4b_canonical_path_invalid', 'Relative path must be exact bounded visible ASCII.' );
		if ( false !== strpos( $path, '\\' )
			|| 0 === strpos( $path, '/' )
			|| false !== strpos( $path, '//' )
			|| preg_match( '#(^|/)\.\.?(/|$)#', $path )
			|| preg_match( '/%(?:2e|2f|5c)/i', $path ) ) {
			return new WP_Error( 'mad4b_canonical_path_alias_denied', 'Relative path traversal, slash aliases and encoded aliases are denied.' );
		}
		return $path;
	}

	public static function header_name( $value ) {
		$value = (string) $value;
		$visible = self::visible_ascii( $value, 128 );
		if ( is_wp_error( $visible ) || 1 !== preg_match( "/^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$/D", $value ) ) {
			return new WP_Error( 'mad4b_canonical_header_name_invalid', 'HTTP header name is not a bounded RFC token.' );
		}
		return strtolower( $value );
	}

	public static function url( $value ) {
		$url = trim( (string) $value );
		$utf = self::safe_utf8( $url, true );
		if ( is_wp_error( $utf ) || '' === $url || strlen( $url ) > 4096 ) return new WP_Error( 'mad4b_canonical_url_invalid', 'URL is invalid or unbounded.' );
		if ( preg_match( '/[\x00-\x20\x7F]/', $url ) || false !== strpos( $url, '\\' ) ) return new WP_Error( 'mad4b_canonical_url_invalid', 'URL contains control, whitespace or backslash aliases.' );
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return new WP_Error( 'mad4b_canonical_url_invalid', 'URL requires an explicit scheme and host.' );
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) return new WP_Error( 'mad4b_canonical_url_scheme_denied', 'URL scheme is not canonical for governed HTTP transport.' );
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) return new WP_Error( 'mad4b_canonical_url_userinfo_denied', 'URL userinfo is denied.' );
		if ( isset( $parts['fragment'] ) ) return new WP_Error( 'mad4b_canonical_url_fragment_denied', 'URL fragments are denied from canonical transport identity.' );
		$host = strtolower( (string) $parts['host'] );
		if ( preg_match( '/[^\x21-\x7E]/', $host ) ) return new WP_Error( 'mad4b_canonical_url_host_unicode_denied', 'Unicode/IDN hosts require an explicit external IDNA contract and are denied here.' );
		$is_ipv6 = strlen( $host ) >= 2 && '[' === $host[0] && ']' === substr( $host, -1 );
		if ( ! $is_ipv6 && 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $host ) ) {
			return new WP_Error( 'mad4b_canonical_url_host_invalid', 'URL host is not a canonical ASCII hostname.' );
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( $port < 0 || $port > 65535 ) return new WP_Error( 'mad4b_canonical_url_port_invalid', 'URL port is invalid.' );
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) $port = 0;
		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
		$path_utf = self::safe_utf8( $path, true );
		if ( is_wp_error( $path_utf )
			|| '/' !== substr( $path, 0, 1 )
			|| false !== strpos( $path, '\\' )
			|| false !== strpos( $path, '//' )
			|| preg_match( '#(^|/)\.\.?(/|$)#', $path )
			|| preg_match( '/%(?:2e|2f|5c)/i', $path ) ) {
			return new WP_Error( 'mad4b_canonical_url_path_alias_denied', 'URL path aliases/traversal are denied.' );
		}
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		if ( '' !== $query ) {
			$q = self::safe_utf8( $query, true );
			if ( is_wp_error( $q ) || preg_match( '/[\x00-\x1F\x7F]/', $query ) ) return new WP_Error( 'mad4b_canonical_url_query_invalid', 'URL query contains hidden/control characters.' );
		}
		return $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' ) . $path . ( '' !== $query ? '?' . $query : '' );
	}

	public static function canonical_json( $value ) {
		$normalized = self::normalize( $value );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? new WP_Error( 'mad4b_canonical_json_failed', 'Unable to encode canonical JSON.' ) : (string) $json;
	}

	public static function digest( $contract, $value ) {
		$contract = trim( (string) $contract );
		if ( '' === $contract || strlen( $contract ) > 191 || 1 !== preg_match( '/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $contract ) ) {
			return new WP_Error( 'mad4b_canonical_contract_invalid', 'Canonical hash contract is invalid.' );
		}
		$json = self::canonical_json( $value );
		if ( is_wp_error( $json ) ) return $json;
		return hash( 'sha256', 'mad4b:' . $contract . "\n" . $json );
	}

	private static function ascii_identifier( $value, $kind, $pattern ) {
		$raw = (string) $value;
		if ( $raw !== trim( $raw ) || '' === $raw || preg_match( '/[^\x21-\x7E]/', $raw ) || 1 !== preg_match( $pattern, $raw ) ) {
			return new WP_Error(
				'mad4b_canonical_' . sanitize_key( $kind ) . '_invalid',
				'Security identifier is not exact canonical ASCII; Unicode/confusable aliases are denied.',
				array( 'kind'=>$kind, 'canonicalization_policy'=>self::POLICY_CONTRACT, 'alias_folding'=>false )
			);
		}
		return $raw;
	}

	private static function visible_ascii( $value, $max_bytes ) {
		if ( '' === $value || strlen( $value ) > $max_bytes || preg_match( '/[^\x21-\x7E]/', $value ) ) {
			return new WP_Error( 'mad4b_canonical_visible_ascii_required', 'Value must be bounded visible ASCII.' );
		}
		return $value;
	}

	private static function safe_utf8( $value, $deny_hidden_controls ) {
		$value = (string) $value;
		if ( 1 !== preg_match( '//u', $value ) ) return new WP_Error( 'mad4b_canonical_utf8_invalid', 'Canonical text is not valid UTF-8.' );
		if ( $deny_hidden_controls && preg_match( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', $value ) ) {
			return new WP_Error( 'mad4b_canonical_hidden_unicode_denied', 'Hidden bidi/zero-width Unicode controls are denied from canonical identity/hash material.' );
		}
		return $value;
	}

	private static function normalize( $value ) {
		if ( is_float( $value ) ) return new WP_Error( 'mad4b_canonical_float_denied', 'Floating-point values require an explicit field normalization contract.' );
		if ( is_string( $value ) ) {
			$valid = self::safe_utf8( $value, true );
			return is_wp_error( $valid ) ? $valid : $value;
		}
		if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) ) return $value;
		if ( is_object( $value ) ) $value = get_object_vars( $value );
		if ( ! is_array( $value ) ) return new WP_Error( 'mad4b_canonical_type_unsupported', 'Canonicalization supports JSON-compatible values only.' );
		if ( self::is_list( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$normalized = self::normalize( $item );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out[] = $normalized;
			}
			return $out;
		}
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys, SORT_STRING );
		$out = array();
		foreach ( $keys as $key ) {
			$key_valid = self::safe_utf8( $key, true );
			if ( is_wp_error( $key_valid ) || preg_match( '/[\x00-\x1F\x7F]/', $key ) ) return new WP_Error( 'mad4b_canonical_object_key_invalid', 'Canonical JSON object key contains invalid/hidden characters.' );
			$normalized = self::normalize( $value[ $key ] );
			if ( is_wp_error( $normalized ) ) return $normalized;
			$out[ $key ] = $normalized;
		}
		return $out;
	}

	private static function is_list( array $value ) {
		$expected = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $expected ) return false;
			$expected++;
		}
		return true;
	}
}
