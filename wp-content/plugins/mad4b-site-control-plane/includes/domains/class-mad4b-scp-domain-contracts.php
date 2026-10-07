<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared bounds for provider-owned domain preflight contracts. Never grants authority. */
final class MAD4B_SCP_Domain_Contracts {
	const CONTRACT = 'mad4b.wordpress-domain-preflight.v1';
	const MAX_BYTES = 131072;
	const MAX_NODES = 2048;
	const MAX_DEPTH = 12;

	public static function error( $code ) {
		return new WP_Error( 'mad4b_domain_' . $code, 'The exact provider-owned domain contract did not pass preflight.', array( 'reason'=>$code, 'authorizing'=>false, 'mutation_performed'=>false ) );
	}

	public static function keys( array $value, array $allowed, array $required = array() ) {
		if ( array_diff( array_keys( $value ), $allowed ) || array_diff( $required, array_keys( $value ) ) ) return self::error( 'fields_invalid' );
		return true;
	}

	public static function fact_types( array $facts, array $types ) {
		foreach ( $types as $key => $type ) if ( ! array_key_exists( $key, $facts ) || gettype( $facts[ $key ] ) !== $type ) return self::error( 'provider_fact_types' );
		return true;
	}

	public static function bounded( $value ) {
		$nodes = 0;
		$valid = self::walk( $value, 0, $nodes );
		if ( is_wp_error( $valid ) ) return $valid;
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) && strlen( $json ) <= self::MAX_BYTES ? true : self::error( 'payload_bound' );
	}

	private static function walk( $value, $depth, &$nodes ) {
		if ( ++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH ) return self::error( 'structure_bound' );
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 191 ) ) return self::error( 'key_invalid' );
				$valid = self::walk( $child, $depth + 1, $nodes );
				if ( is_wp_error( $valid ) ) return $valid;
			}
			return true;
		}
		if ( ! is_null( $value ) && ! is_bool( $value ) && ! is_int( $value ) && ! is_string( $value ) ) return self::error( 'non_json_value' );
		if ( is_string( $value ) && ( strlen( $value ) > 32768 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value ) || preg_match( '//u', $value ) !== 1 || preg_match( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', $value ) ) ) return self::error( 'string_invalid' );
		return true;
	}

	public static function digest( $value ) {
		if ( is_wp_error( self::bounded( $value ) ) ) return '';
		return hash( 'sha256', wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( ! self::is_list( $value ) ) ksort( $value, SORT_STRING );
		foreach ( $value as &$child ) $child = self::canonical( $child );
		unset( $child );
		return $value;
	}

	public static function is_list( array $value ) { return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ); }
	public static function identifier( $value ) { return is_string( $value ) && preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $value ) === 1; }
	public static function sha( $value ) { return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) === 1; }

	/** Each desired field must have its own provider schema, current field grant and effect class. */
	public static function fields( array $desired, array $facts, array $effects ) {
		if ( ! $desired || count( $desired ) > 64 ) return self::error( 'field_bound' );
		$schemas = $facts['field_contracts'] ?? array();
		if ( ! is_array( $schemas ) || ! function_exists( 'rest_validate_value_from_schema' ) ) return self::error( 'field_schema_unavailable' );
		foreach ( $desired as $field => $value ) {
			$contract = $schemas[ $field ] ?? null;
			if ( ! is_string( $field ) || ! is_array( $contract ) || true !== ( $contract['authorized'] ?? null ) || ! is_array( $contract['schema'] ?? null ) ) return self::error( 'field_authority' );
			if ( 'public' !== ( $contract['privacy'] ?? '' ) || ! in_array( $contract['effect'] ?? '', $effects, true ) ) return self::error( 'field_effect_or_privacy' );
			$check = rest_validate_value_from_schema( $value, $contract['schema'], $field );
			if ( is_wp_error( $check ) ) return self::error( 'field_schema' );
			if ( in_array( $contract['schema']['format'] ?? '', array( 'uri','url' ), true ) || 'media_url' === ( $contract['semantic'] ?? '' ) ) {
				if ( ! is_array( $facts['allowed_url_origins'] ?? null ) ) return self::error( 'media_url_scope' );
				$check = self::public_url( $value, $facts['allowed_url_origins'] ?? array() );
				if ( is_wp_error( $check ) ) return $check;
			}
		}
		return self::no_secrets( $desired );
	}

	public static function no_secrets( $value ) {
		$bounded = self::bounded( $value );
		if ( is_wp_error( $bounded ) ) return $bounded;
		if ( ! class_exists( 'MAD4B_SCP_Structural_Redaction' ) ) return self::error( 'privacy_runtime_unavailable' );
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				if ( MAD4B_SCP_Structural_Redaction::sensitive_key( $key ) ) return self::error( 'secret_denied' );
				$valid = self::no_secrets( $child );
				if ( is_wp_error( $valid ) ) return $valid;
			}
		} elseif ( MAD4B_SCP_Structural_Redaction::sensitive_scalar( $value ) ) return self::error( 'secret_denied' );
		return true;
	}

	public static function result( $profile, array $desired, array $checks, $risk, $reversible = false ) {
		return array( 'contract'=>self::CONTRACT, 'profile'=>$profile, 'desired_sha256'=>self::digest( $desired ), 'checks'=>$checks, 'risk'=>$risk, 'reversible_claimed'=>$reversible, 'auto_promotion_allowed'=>false, 'authorizing'=>false, 'mutation_performed'=>false );
	}

	/** Validate a bounded parent graph in linear time; no cycles, orphans or identity reuse. */
	public static function hierarchy( array $nodes, array $known_ids = array(), array $existing_parents = array() ) {
		if ( ! self::is_list( $nodes ) || ! $nodes || count( $nodes ) > 256 ) return self::error( 'hierarchy_bound' );
		$parents = array(); $changed = array();
		if ( count( $existing_parents ) > 256 || array_diff( $known_ids, array_keys( $existing_parents ) ) ) return self::error( 'hierarchy_existing_inventory' );
		foreach ( $existing_parents as $id => $parent ) {
			if ( ! self::identifier( (string) $id ) || ! is_string( $parent ) ) return self::error( 'hierarchy_existing_inventory' );
			$parents[ $id ] = $parent;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! self::identifier( $node['id'] ?? null ) || isset( $changed[ $node['id'] ] ) || ! is_string( $node['parent'] ?? null ) ) return self::error( 'hierarchy_identity' );
			$changed[ $node['id'] ] = true;
			$parents[ $node['id'] ] = $node['parent'];
		}
		if ( count( $parents ) > 256 ) return self::error( 'hierarchy_bound' );
		$visited = array();
		foreach ( $parents as $id => $parent ) {
			$path = array(); $cursor = $id;
			while ( '' !== $cursor && ! isset( $visited[ $cursor ] ) ) {
				if ( isset( $path[ $cursor ] ) ) return self::error( 'hierarchy_cycle' );
				if ( ! array_key_exists( $cursor, $parents ) ) return self::error( 'hierarchy_orphan' );
				$path[ $cursor ] = true; $cursor = $parents[ $cursor ];
			}
			foreach ( $path as $seen => $_ ) $visited[ $seen ] = true;
		}
		return true;
	}

	private static function public_url( $value, array $origins ) {
		if ( ! is_string( $value ) || preg_match( '/[^\x21-\x7E]/', $value ) || false !== strpos( $value, '\\' ) || false !== strpos( $value, '%' ) ) return self::error( 'media_url_scope' );
		$parts = parse_url( $value );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || ! is_string( $parts['host'] ?? null ) || preg_match( '/^[a-z0-9.-]+$/D', $parts['host'] ) !== 1 || filter_var( $parts['host'], FILTER_VALIDATE_IP ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) || ! in_array( 'https://' . $parts['host'], $origins, true ) ) return self::error( 'media_url_scope' );
		return true;
	}
}

/** Installed, reviewed PHP owns inspection/serialization. User data cannot choose callbacks. */
abstract class MAD4B_SCP_Domain_Provider {
	abstract public function id();
	abstract public function capabilities();
	abstract public function runtime();
	abstract public function authorize( $capability, array $target, array $desired );
	abstract public function inspect( $capability, array $target );
	abstract public function matches( $capability, array $desired, array $context );
}
