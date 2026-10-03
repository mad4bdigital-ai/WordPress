<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-specific semantic content-field classification.
 *
 * Exact contracts are authoritative for field semantics. Name/value heuristics
 * are evidence only and can require review, but can never grant mutation
 * eligibility or substitute for a provider contract.
 */
final class MAD4B_SCP_Semantic_Content_Field_Contracts {
	const CONTRACT = 'mad4b.semantic-content-field-contracts.v1';
	const CLASSIFICATION_CONTRACT = 'mad4b.semantic-content-field-classification.v1';
	private static $catalog = null;

	public static function clear_cache() { self::$catalog = null; }

	public static function declared_abilities() {
		$catalog = self::catalog();
		$abilities = isset( $catalog['abilities'] ) && is_array( $catalog['abilities'] ) ? array_keys( $catalog['abilities'] ) : array();
		$abilities = array_values( array_unique( array_map( 'strval', $abilities ) ) );
		sort( $abilities, SORT_STRING );
		return $abilities;
	}

	public static function classify( $ability_name, $input ) {
		$ability_name = trim( (string) $ability_name );
		$input = is_array( $input ) ? $input : array();
		$catalog = self::catalog();
		$contracts = isset( $catalog['abilities'] ) && is_array( $catalog['abilities'] ) ? $catalog['abilities'] : array();
		$row = isset( $contracts[ $ability_name ] ) && is_array( $contracts[ $ability_name ] ) ? $contracts[ $ability_name ] : array();
		$matched = array();
		$fallback = array();

		if ( empty( $row ) ) {
			foreach ( self::flatten( $input ) as $path => $value ) {
				if ( self::governance_path( $path ) ) continue;
				if ( self::fallback_brand_evidence( $path, $value ) ) $fallback[] = $path;
			}
			return self::result( $ability_name, '', false, $matched, $fallback, 'unregistered_provider_semantics', array() );
		}

		$mode = isset( $row['mode'] ) ? sanitize_key( (string) $row['mode'] ) : '';
		if ( 'keyed_value' === $mode ) {
			$selector_path = isset( $row['selector_path'] ) ? (string) $row['selector_path'] : '';
			$value_path = isset( $row['value_path'] ) ? (string) $row['value_path'] : '';
			$selector = (string) self::value_at_path( $input, $selector_path );
			$value = self::value_at_path( $input, $value_path );
			if ( self::regex_match( isset( $row['brand_key_regex'] ) ? (string) $row['brand_key_regex'] : '', $selector ) ) {
				$matched[] = $selector_path . ':' . $selector;
			} elseif ( self::regex_match( isset( $row['operational_key_regex'] ) ? (string) $row['operational_key_regex'] : '', $selector ) ) {
				// Explicitly classified operational field.
			} elseif ( self::fallback_brand_evidence( $selector, $value ) ) {
				$fallback[] = $selector_path . ':' . $selector;
			}
		} elseif ( 'object_fields' === $mode ) {
			$container = isset( $row['container_path'] ) ? (string) $row['container_path'] : '';
			$value = self::value_at_path( $input, $container );
			$value = is_array( $value ) ? $value : array();
			foreach ( self::flatten( $value ) as $relative => $leaf ) {
				$segments = array_values( array_filter( explode( '.', $relative ), static function( $segment ) { return '' !== $segment && ! ctype_digit( $segment ); } ) );
				$brand = self::segment_list_match( $segments, isset( $row['brand_fields'] ) && is_array( $row['brand_fields'] ) ? $row['brand_fields'] : array() )
					|| self::segments_regex_match( $segments, isset( $row['brand_key_regex'] ) ? (string) $row['brand_key_regex'] : '' );
				$operational = self::segment_list_match( $segments, isset( $row['operational_fields'] ) && is_array( $row['operational_fields'] ) ? $row['operational_fields'] : array() )
					|| self::segments_regex_match( $segments, isset( $row['operational_key_regex'] ) ? (string) $row['operational_key_regex'] : '' );
				$full = '' === $container ? $relative : $container . ( '' === $relative ? '' : '.' . $relative );
				if ( $brand ) $matched[] = $full;
				elseif ( ! $operational && self::fallback_brand_evidence( $relative, $leaf ) ) $fallback[] = $full;
			}
			foreach ( self::flatten( $input ) as $path => $leaf ) {
				if ( '' !== $container && ( $path === $container || 0 === strpos( $path, $container . '.' ) ) ) continue;
				if ( self::matches_any_path( $path, isset( $row['root_operational_paths'] ) && is_array( $row['root_operational_paths'] ) ? $row['root_operational_paths'] : array() ) || self::governance_path( $path ) ) continue;
				if ( self::fallback_brand_evidence( $path, $leaf ) ) $fallback[] = $path;
			}
		} else {
			foreach ( self::flatten( $input ) as $path => $value ) {
				if ( self::matches_any_path( $path, isset( $row['brand_paths'] ) && is_array( $row['brand_paths'] ) ? $row['brand_paths'] : array() ) ) {
					$matched[] = $path;
				} elseif ( self::matches_any_path( $path, isset( $row['operational_paths'] ) && is_array( $row['operational_paths'] ) ? $row['operational_paths'] : array() ) || self::governance_path( $path ) ) {
					continue;
				} elseif ( self::fallback_brand_evidence( $path, $value ) ) {
					$fallback[] = $path;
				}
			}
		}

		return self::result(
			$ability_name,
			isset( $row['provider'] ) ? sanitize_key( (string) $row['provider'] ) : '',
			true,
			$matched,
			$fallback,
			'provider_semantic_contract',
			$row
		);
	}

	private static function result( $ability_name, $provider, $known, array $matched, array $fallback, $source, array $row ) {
		$matched = array_values( array_unique( array_filter( array_map( 'strval', $matched ) ) ) );
		$fallback = array_values( array_unique( array_filter( array_map( 'strval', $fallback ) ) ) );
		sort( $matched, SORT_STRING );
		sort( $fallback, SORT_STRING );
		$review_required = ! empty( $fallback );
		$result = array(
			'contract' => self::CLASSIFICATION_CONTRACT,
			'registry_contract' => self::CONTRACT,
			'ability_name' => (string) $ability_name,
			'provider_id' => (string) $provider,
			'field_contract_known' => (bool) $known,
			'required' => ! empty( $matched ),
			'reason' => $review_required ? 'semantic_field_review_required' : ( ! empty( $matched ) ? 'provider_semantic_content_fields' : 'not_content_bearing' ),
			'matched_fields' => $matched,
			'fallback_evidence_fields' => $fallback,
			'review_required' => $review_required,
			'mutation_classification_ready' => ! $review_required,
			'fallback_evidence_authorizing' => false,
			'authority_effect' => 'none',
			'contract_sha256' => self::digest( $row ),
		);
		$result['classification_sha256'] = self::digest( $result );
		return $result;
	}

	private static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/semantic-content-field-contracts.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) {
			return self::$catalog = array( 'contract' => self::CONTRACT, 'abilities' => array() );
		}
		$raw = file_get_contents( $path );
		$data = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || self::CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) ) {
			return self::$catalog = array( 'contract' => self::CONTRACT, 'abilities' => array() );
		}
		return self::$catalog = $data;
	}

	private static function flatten( $value, $path = '', $depth = 0 ) {
		if ( $depth > 10 ) return array();
		if ( ! is_array( $value ) ) return array( (string) $path => $value );
		$out = array();
		foreach ( $value as $key => $item ) {
			$child = '' === $path ? (string) $key : $path . '.' . (string) $key;
			if ( is_array( $item ) ) $out = array_merge( $out, self::flatten( $item, $child, $depth + 1 ) );
			else $out[ $child ] = $item;
		}
		return $out;
	}

	private static function value_at_path( array $input, $path ) {
		$current = $input;
		foreach ( array_filter( explode( '.', (string) $path ), 'strlen' ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) return null;
			$current = $current[ $segment ];
		}
		return $current;
	}

	private static function matches_any_path( $path, array $patterns ) {
		foreach ( $patterns as $pattern ) if ( self::path_matches( (string) $pattern, (string) $path ) ) return true;
		return false;
	}

	private static function path_matches( $pattern, $path ) {
		$quoted = preg_quote( (string) $pattern, '/' );
		$regex = '/^' . str_replace( '\\*', '[^.]+', $quoted ) . '$/';
		return 1 === preg_match( $regex, (string) $path );
	}

	private static function segment_list_match( array $segments, array $allowed ) {
		$allowed = array_map( 'strval', $allowed );
		foreach ( $segments as $segment ) if ( in_array( (string) $segment, $allowed, true ) ) return true;
		return false;
	}

	private static function segments_regex_match( array $segments, $pattern ) {
		foreach ( $segments as $segment ) if ( self::regex_match( $pattern, $segment ) ) return true;
		return false;
	}

	private static function regex_match( $pattern, $value ) {
		if ( '' === trim( (string) $pattern ) ) return false;
		return 1 === @preg_match( '/' . str_replace( '/', '\\/', (string) $pattern ) . '/i', (string) $value );
	}

	private static function governance_path( $path ) {
		foreach ( explode( '.', (string) $path ) as $segment ) {
			$segment = strtolower( (string) $segment );
			if ( 0 === strpos( $segment, '_mad4b_' ) || 0 === strpos( $segment, 'expected_' ) || in_array( $segment, array( 'approval_ticket_id', 'confirmation', 'idempotency_key', 'request_id', 'plan_sha256', 'receipt_sha256' ), true ) ) return true;
		}
		return false;
	}

	private static function fallback_brand_evidence( $path, $value ) {
		$key = strtolower( trim( (string) preg_replace( '/^.*\./', '', (string) $path ) ) );
		$name_signal = '' !== $key && 1 === preg_match( '/(^|[_\-])(title|headline|heading|subtitle|content|body|description|excerpt|summary|text|copy|caption|label|tagline|slogan|bio|about|intro|overview|details|message|note|notes|question|answer|faq|cta|button_text|placeholder|keyword|keywords|editor|html|wysiwyg)([_\-]|$)/', $key );
		if ( $name_signal ) return true;
		if ( is_string( $value ) ) {
			$text = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value ) );
			return strlen( $text ) >= 80 && 1 === preg_match( '/\s/u', $text ) && 1 === preg_match( '/[\p{L}]/u', $text );
		}
		return false;
	}

	private static function digest( $value ) {
		$value = self::sort_value( $value );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$keys = array_keys( $value );
		$is_list = $keys === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}
