<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical identifier policy registry for cross-layer MAD4B identities.
 *
 * New security-sensitive identities are canonical-only. Historical operation
 * identifiers may be read in an explicit legacy-read-only lane, preserving the
 * exact stored bytes; they are never rewritten into a new identity.
 */
final class MAD4B_SCP_Identifiers {
	const CONTRACT = 'mad4b.identifiers.v1';
	const POLICY_CONTRACT = 'mad4b.identifier-policies.v1';
	const APPROVAL_TICKET_SCHEMA_PATTERN = '^[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-4[A-Fa-f0-9]{3}-[89ABab][A-Fa-f0-9]{3}-[A-Fa-f0-9]{12}$';
	private static $policies = null;

	public static function clear_cache() { self::$policies = null; }

	public static function policy( $kind ) {
		$kind = sanitize_key( (string) $kind );
		$c = self::policies();
		if ( is_wp_error( $c ) ) return $c;
		if ( '' === $kind || empty( $c['policies'][ $kind ] ) || ! is_array( $c['policies'][ $kind ] ) ) {
			return new WP_Error( 'mad4b_identifier_policy_unknown', 'Identifier policy is not registered.' );
		}
		$out = $c['policies'][ $kind ];
		$out['kind'] = $kind;
		$out['contract'] = self::POLICY_CONTRACT;
		$out['authorizing'] = false;
		return $out;
	}

	public static function inspect( $kind, $value, $mode = 'write' ) {
		$kind = sanitize_key( (string) $kind );
		$mode = sanitize_key( (string) $mode );
		$raw = trim( (string) $value );
		$policy = self::policy( $kind );
		if ( is_wp_error( $policy ) ) return $policy;
		if ( 'uuidv4' === $policy['format'] ) {
			$canonical = self::uuidv4( $raw );
			if ( '' !== $canonical ) return array(
				'contract' => self::POLICY_CONTRACT,
				'kind' => $kind,
				'value' => $canonical,
				'historical_value' => $raw,
				'identity_class' => 'canonical',
				'read_eligible' => true,
				'write_eligible' => true,
				'rewrite_allowed' => false,
				'normalized' => ! hash_equals( $raw, $canonical ),
				'authorizing' => false,
			);
			if ( 'operation_id' === $kind && 'read' === $mode ) return self::legacy_operation( $raw, $policy );
			return new WP_Error( 'mad4b_identifier_invalid', 'Identifier does not satisfy its canonical UUIDv4 policy.', array( 'kind' => $kind ) );
		}
		if ( 'provider_slug_v1' === $policy['format'] ) {
			$canonical = strtolower( $raw );
			if ( strlen( $canonical ) < (int) $policy['min_length'] || strlen( $canonical ) > (int) $policy['max_length'] || 1 !== preg_match( '/' . $policy['pattern'] . '/D', $canonical ) ) {
				return new WP_Error( 'mad4b_identifier_invalid', 'Provider identifier does not satisfy provider_slug_v1.', array( 'kind' => $kind ) );
			}
			return array( 'contract'=>self::POLICY_CONTRACT,'kind'=>$kind,'value'=>$canonical,'historical_value'=>$raw,'identity_class'=>'canonical','read_eligible'=>true,'write_eligible'=>true,'rewrite_allowed'=>false,'normalized'=>!hash_equals($raw,$canonical),'authorizing'=>false );
		}
		if ( 'receipt_v1' === $policy['format'] ) {
			$prefix = (string) $policy['prefix'];
			$lower = strtolower( $raw );
			if ( 0 !== strpos( $lower, $prefix ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', substr( $lower, strlen( $prefix ) ) ) ) {
				return new WP_Error( 'mad4b_identifier_invalid', 'Execution receipt identifier does not satisfy receipt_v1.', array( 'kind' => $kind ) );
			}
			return array( 'contract'=>self::POLICY_CONTRACT,'kind'=>$kind,'value'=>$lower,'historical_value'=>$raw,'identity_class'=>'canonical','read_eligible'=>true,'write_eligible'=>true,'rewrite_allowed'=>false,'normalized'=>!hash_equals($raw,$lower),'authorizing'=>false );
		}
		return new WP_Error( 'mad4b_identifier_policy_invalid', 'Identifier policy format is unsupported.', array( 'kind' => $kind ) );
	}

	public static function approval_ticket_id( $value ) {
		// Approval-ticket identity must remain available in isolated runtime
		// guards before the policy catalog is materialized. The registry records
		// the policy; this primitive enforces the same immutable UUIDv4 shape.
		return self::uuidv4( $value );
	}
	public static function valid_approval_ticket_id( $value ) { return '' !== self::approval_ticket_id( $value ); }

	public static function operation_id_for_write( $value ) {
		$identity = self::inspect( 'operation_id', $value, 'write' );
		return is_wp_error( $identity ) ? '' : (string) $identity['value'];
	}

	public static function operation_lookup( $value ) { return self::inspect( 'operation_id', $value, 'read' ); }

	public static function job_id( $value ) {
		$identity = self::inspect( 'job_id', $value, 'write' );
		return is_wp_error( $identity ) ? '' : (string) $identity['value'];
	}

	public static function provider_id( $value ) {
		$identity = self::inspect( 'provider_id', $value, 'write' );
		return is_wp_error( $identity ) ? '' : (string) $identity['value'];
	}

	public static function receipt_id( $value ) {
		$identity = self::inspect( 'receipt_id', $value, 'write' );
		return is_wp_error( $identity ) ? '' : (string) $identity['value'];
	}

	public static function receipt_id_from_sha256( $sha256 ) {
		$sha256 = strtolower( trim( (string) $sha256 ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) return '';
		return self::receipt_id( 'receipt:v1:' . $sha256 );
	}

	private static function legacy_operation( $raw, array $policy ) {
		// UUID-shaped-but-not-v4 values are invalid, not a legacy escape hatch.
		if ( 1 === preg_match( '/^[A-Fa-f0-9-]{36}$/D', $raw ) ) {
			return new WP_Error( 'mad4b_operation_legacy_uuid_alias_denied', 'Malformed/non-v4 UUID-shaped operation identity is not eligible for legacy lookup.' );
		}
		$legacy = isset( $policy['legacy_read'] ) && is_array( $policy['legacy_read'] ) ? $policy['legacy_read'] : array();
		$min = isset( $legacy['min_length'] ) ? (int) $legacy['min_length'] : 0;
		$max = isset( $legacy['max_length'] ) ? (int) $legacy['max_length'] : 0;
		$pattern = isset( $legacy['pattern'] ) ? (string) $legacy['pattern'] : '';
		if ( empty( $legacy ) || strlen( $raw ) < $min || strlen( $raw ) > $max || '' === $pattern || 1 !== preg_match( '/' . $pattern . '/D', $raw ) ) {
			return new WP_Error( 'mad4b_operation_id_invalid', 'Operation id is neither canonical UUIDv4 nor an admitted legacy read-only identity.' );
		}
		return array(
			'contract' => self::POLICY_CONTRACT,
			'kind' => 'operation_id',
			'value' => $raw,
			'historical_value' => $raw,
			'identity_class' => 'legacy_read_only',
			'legacy_format' => isset( $legacy['format'] ) ? (string) $legacy['format'] : 'legacy_opaque_v1',
			'read_eligible' => true,
			'write_eligible' => false,
			'rewrite_allowed' => false,
			'historical_identity_preserved' => true,
			'normalized' => false,
			'authorizing' => false,
		);
	}

	private static function uuidv4( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value ) ? $value : '';
	}

	private static function policies() {
		if ( null !== self::$policies ) return self::$policies;
		$path = dirname( __DIR__ ) . '/config/identifier-policies.json';
		if ( ! is_readable( $path ) ) return self::$policies = new WP_Error( 'mad4b_identifier_policies_missing', 'Identifier policy registry is unavailable.' );
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || self::POLICY_CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) || empty( $data['policies'] ) || ! is_array( $data['policies'] ) ) {
			return self::$policies = new WP_Error( 'mad4b_identifier_policies_invalid', 'Identifier policy registry is invalid.' );
		}
		foreach ( array( 'approval_ticket_id','operation_id','job_id','receipt_id','provider_id' ) as $required ) {
			if ( empty( $data['policies'][ $required ] ) || ! is_array( $data['policies'][ $required ] ) ) return self::$policies = new WP_Error( 'mad4b_identifier_policies_invalid', 'Identifier policy registry is incomplete.' );
		}
		return self::$policies = $data;
	}
}
