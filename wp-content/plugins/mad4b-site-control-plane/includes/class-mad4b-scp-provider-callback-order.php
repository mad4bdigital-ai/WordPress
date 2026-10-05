<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic ordered-callback reducer for provider adapters.
 *
 * No callback is executed here. Adapters persist the returned state and apply
 * ready_events through their own governed transaction. Gaps never auto-apply,
 * conflicting duplicates fail closed, and provider-binding drift is rejected.
 */
final class MAD4B_SCP_Provider_Callback_Order {
	const CONTRACT = 'mad4b.provider-callback-order.v1';
	const MAX_PENDING = 32;
	const MAX_HISTORY = 64;

	public static function initial_state( $provider_binding_sha256 ) {
		$binding = self::sha( $provider_binding_sha256 );
		if ( '' === $binding ) return new WP_Error( 'mad4b_provider_callback_binding_invalid', 'Provider callback ordering requires an exact provider binding SHA-256.' );
		return array(
			'contract' => self::CONTRACT,
			'provider_binding_sha256' => $binding,
			'last_sequence' => 0,
			'pending' => array(),
			'history' => array(),
			'reconciliation_required' => false,
		);
	}

	public static function reduce( $state, $event ) {
		$state = is_array( $state ) ? $state : array();
		$event = is_array( $event ) ? $event : array();
		if ( self::CONTRACT !== (string) ( isset( $state['contract'] ) ? $state['contract'] : '' ) ) return new WP_Error( 'mad4b_provider_callback_state_invalid', 'Provider callback ordering state contract is invalid.' );
		$binding = self::sha( isset( $event['provider_binding_sha256'] ) ? $event['provider_binding_sha256'] : '' );
		if ( '' === $binding || ! hash_equals( (string) $state['provider_binding_sha256'], $binding ) ) {
			return new WP_Error( 'mad4b_provider_callback_binding_drift', 'Provider callback binding changed; callback requires recertification/reconciliation.' );
		}
		$sequence = isset( $event['sequence'] ) ? (int) $event['sequence'] : 0;
		$event_id = trim( (string) ( isset( $event['event_id'] ) ? $event['event_id'] : '' ) );
		$payload_sha = self::sha( isset( $event['payload_sha256'] ) ? $event['payload_sha256'] : '' );
		if ( $sequence < 1 || '' === $event_id || strlen( $event_id ) > 191 || '' === $payload_sha ) {
			return new WP_Error( 'mad4b_provider_callback_event_invalid', 'Provider callback requires sequence, bounded event id and payload SHA-256.' );
		}
		$descriptor = array(
			'sequence' => $sequence,
			'event_id' => $event_id,
			'payload_sha256' => $payload_sha,
			'provider_binding_sha256' => $binding,
		);
		$event_sha = self::digest( $descriptor );
		$history = isset( $state['history'] ) && is_array( $state['history'] ) ? $state['history'] : array();
		$last = max( 0, (int) ( isset( $state['last_sequence'] ) ? $state['last_sequence'] : 0 ) );

		if ( $sequence <= $last ) {
			$key = (string) $sequence;
			if ( isset( $history[ $key ] ) && hash_equals( (string) $history[ $key ], $event_sha ) ) {
				return self::result( 'DUPLICATE_IGNORED', $state, array(), false );
			}
			return new WP_Error(
				'mad4b_provider_callback_stale_or_conflicting',
				'Provider callback sequence is stale or conflicts with already-applied history.',
				array( 'last_sequence' => $last, 'sequence' => $sequence, 'reconciliation_required' => true )
			);
		}

		$pending = isset( $state['pending'] ) && is_array( $state['pending'] ) ? $state['pending'] : array();
		$key = (string) $sequence;
		if ( isset( $pending[ $key ] ) ) {
			$pending_sha = isset( $pending[ $key ]['event_sha256'] ) ? (string) $pending[ $key ]['event_sha256'] : '';
			if ( hash_equals( $pending_sha, $event_sha ) ) return self::result( 'DUPLICATE_PENDING_IGNORED', $state, array(), false );
			return new WP_Error( 'mad4b_provider_callback_pending_conflict', 'Provider callback conflicts with an already-buffered sequence.', array( 'sequence' => $sequence, 'reconciliation_required' => true ) );
		}

		if ( $sequence > $last + 1 ) {
			if ( count( $pending ) >= self::MAX_PENDING ) return new WP_Error( 'mad4b_provider_callback_pending_capacity', 'Provider callback pending window is exhausted; authoritative reconciliation is required.' );
			$descriptor['event_sha256'] = $event_sha;
			$pending[ $key ] = $descriptor;
			ksort( $pending, SORT_NUMERIC );
			$next = $state;
			$next['pending'] = $pending;
			$next['reconciliation_required'] = true;
			$next['next_expected_sequence'] = $last + 1;
			return self::result( 'GAP_BUFFERED_RECONCILIATION_REQUIRED', $next, array(), true );
		}

		$ready = array();
		$descriptor['event_sha256'] = $event_sha;
		$ready[] = $descriptor;
		$last = $sequence;
		$history[ (string) $sequence ] = $event_sha;

		while ( isset( $pending[ (string) ( $last + 1 ) ] ) ) {
			$next_event = $pending[ (string) ( $last + 1 ) ];
			unset( $pending[ (string) ( $last + 1 ) ] );
			$last++;
			$ready[] = $next_event;
			$history[ (string) $last ] = (string) $next_event['event_sha256'];
		}
		if ( count( $history ) > self::MAX_HISTORY ) {
			ksort( $history, SORT_NUMERIC );
			$history = array_slice( $history, -self::MAX_HISTORY, null, true );
		}
		$next = $state;
		$next['last_sequence'] = $last;
		$next['pending'] = $pending;
		$next['history'] = $history;
		$next['reconciliation_required'] = ! empty( $pending );
		$next['next_expected_sequence'] = $last + 1;
		$verdict = count( $ready ) > 1 ? 'GAP_RECONCILED_READY_IN_ORDER' : 'READY_IN_ORDER';
		return self::result( $verdict, $next, $ready, ! empty( $pending ) );
	}

	private static function result( $verdict, array $state, array $ready, $reconciliation_required ) {
		return array(
			'contract' => self::CONTRACT,
			'verdict' => $verdict,
			'state' => $state,
			'ready_events' => $ready,
			'reconciliation_required' => (bool) $reconciliation_required,
			'provider_side_effect_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function sha( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function digest( $value ) {
		$json = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
