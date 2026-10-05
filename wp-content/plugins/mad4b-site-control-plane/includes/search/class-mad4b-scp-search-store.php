<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Portable CAS store. Backends must provide atomic create/compare-exchange. */
interface MAD4B_SCP_Search_Store_Backend {
	public function read( $key );
	public function compare_exchange( $key, $expected, array $next );
	public function scan( $prefix, $after, $limit );
}

final class MAD4B_SCP_Search_WordPress_Store implements MAD4B_SCP_Search_Store_Backend {
	public function read( $key ) {
		global $wpdb;
		// Fresh database reads avoid persistent option-cache races during worker fencing.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		if ( null === $raw ) return null;
		$value = maybe_unserialize( $raw );
		return is_array( $value ) ? $value : MAD4B_SCP_Search_Contracts::error( 'store_corrupt' );
	}
	public function compare_exchange( $key, $expected, array $next ) {
		global $wpdb;
		if ( null === $expected ) return add_option( $key, $next, '', false );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $next ), $key, maybe_serialize( $expected ) ) );
		wp_cache_delete( $key, 'options' );
		return 1 === $changed;
	}
	public function scan( $prefix, $after, $limit ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT option_name AS storage_key, option_value AS storage_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT %d", $wpdb->esc_like( $prefix ) . '%', $after, $limit ), ARRAY_A );
	}
}

final class MAD4B_SCP_Search_Store {
	private static $backend;

	public static function backend() {
		if ( null === self::$backend ) self::$backend = apply_filters( 'mad4b_scp_search_store_backend', new MAD4B_SCP_Search_WordPress_Store() );
		return self::$backend instanceof MAD4B_SCP_Search_Store_Backend ? self::$backend : null;
	}
	public static function reset() { self::$backend = null; }

	public static function scope() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '';
		if ( ! is_string( $uuid ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $uuid ) ) return MAD4B_SCP_Search_Contracts::error( 'site_identity_missing' );
		return 'mad4b_asi_' . substr( hash( 'sha256', $uuid . '|' . home_url( '/' ) ), 0, 24 ) . '_';
	}
	public static function key( $kind, $id ) {
		$scope = self::scope();
		if ( is_wp_error( $scope ) ) return $scope;
		if ( ! MAD4B_SCP_Search_Contracts::id( $kind ) || ! is_string( $id ) || '' === $id || strlen( $id ) > 200 ) return MAD4B_SCP_Search_Contracts::error( 'store_key_invalid' );
		return $scope . $kind . '_' . hash( 'sha256', $id );
	}
	public static function read( $kind, $id ) {
		$key = self::key( $kind, $id );
		if ( is_wp_error( $key ) ) return $key;
		$backend = self::backend();
		return $backend ? $backend->read( $key ) : MAD4B_SCP_Search_Contracts::error( 'store_unavailable' );
	}
	public static function cas( $kind, $id, $expected, array $next, $event ) {
		$key = self::key( $kind, $id );
		if ( is_wp_error( $key ) ) return $key;
		if ( is_wp_error( $expected ) ) return $expected;
		if ( ! MAD4B_SCP_Search_Contracts::bounded( $next ) ) return MAD4B_SCP_Search_Contracts::error( 'store_bound_exceeded' );
		$next['_revision'] = null === $expected ? 1 : (int) $expected['_revision'] + 1;
		// Audit outbox and aggregate state share one CAS; audit failure cannot hide a write.
		$next['_event'] = array( 'type' => $event, 'at' => time(), 'actor' => get_current_user_id(), 'previous' => isset( $expected['_event_sha256'] ) ? $expected['_event_sha256'] : '', 'payload_sha256' => MAD4B_SCP_Search_Contracts::digest( $next ) );
		$next['_event_sha256'] = MAD4B_SCP_Search_Contracts::digest( $next['_event'] );
		$backend = self::backend();
		if ( $backend && is_array( $expected ) && isset( $expected['_event_sha256'] ) ) {
			$pending_key = self::key( 'event', $expected['_event_sha256'] );
			$pending = array( 'event' => $expected['_event'], 'aggregate_key' => $key, 'aggregate_revision' => $expected['_revision'], 'event_sha256' => $expected['_event_sha256'] );
			$saved = $backend->read( $pending_key );
			if ( null === $saved ) { if ( ! $backend->compare_exchange( $pending_key, null, $pending ) ) return MAD4B_SCP_Search_Contracts::error( 'audit_outbox_pending' ); }
			elseif ( ! is_array( $saved ) || MAD4B_SCP_Search_Contracts::digest( $saved ) !== MAD4B_SCP_Search_Contracts::digest( $pending ) ) return MAD4B_SCP_Search_Contracts::error( 'audit_event_tampered' );
		}
		if ( ! $backend || ! $backend->compare_exchange( $key, $expected, $next ) ) return MAD4B_SCP_Search_Contracts::error( 'compare_exchange_conflict' );
		$event_key = self::key( 'event', $next['_event_sha256'] );
		$backend->compare_exchange( $event_key, null, array( 'event' => $next['_event'], 'aggregate_key' => $key, 'aggregate_revision' => $next['_revision'], 'event_sha256' => $next['_event_sha256'] ) );
		return $next;
	}
	public static function immutable( $kind, $id, array $payload ) {
		$row = array( 'payload' => $payload, 'digest' => MAD4B_SCP_Search_Contracts::digest( $payload ) );
		$existing = self::read( $kind, $id );
		if ( is_wp_error( $existing ) ) return $existing;
		if ( null !== $existing ) return isset( $existing['digest'], $existing['payload'] ) && hash_equals( $row['digest'], (string) $existing['digest'] ) && hash_equals( $row['digest'], MAD4B_SCP_Search_Contracts::digest( $existing['payload'] ) ) ? $existing : MAD4B_SCP_Search_Contracts::error( 'immutable_conflict' );
		$created = self::cas( $kind, $id, null, $row, 'EVIDENCE_COMMITTED' );
		if ( ! is_wp_error( $created ) ) return $created;
		$existing = self::read( $kind, $id );
		return is_array( $existing ) && isset( $existing['digest'], $existing['payload'] ) && hash_equals( $row['digest'], (string) $existing['digest'] ) && hash_equals( $row['digest'], MAD4B_SCP_Search_Contracts::digest( $existing['payload'] ) ) ? $existing : $created;
	}
	public static function evidence( $kind, $id ) {
		$row = self::read( $kind, $id );
		if ( ! is_array( $row ) || ! isset( $row['digest'], $row['payload'] ) || ! hash_equals( (string) $row['digest'], MAD4B_SCP_Search_Contracts::digest( $row['payload'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'evidence_integrity_failed' );
		if ( isset( $row['payload']['valid_until'] ) && $row['payload']['valid_until'] <= time() ) return MAD4B_SCP_Search_Contracts::error( 'evidence_retention_expired' );
		return $row['payload'];
	}
	public static function list_rows( $kind, $after = '', $limit = 100 ) {
		$scope = self::scope();
		if ( is_wp_error( $scope ) ) return $scope;
		$prefix = $scope . $kind . '_';
		if ( '' !== $after && 0 !== strpos( $after, $prefix ) ) return MAD4B_SCP_Search_Contracts::error( 'cursor_invalid' );
		$backend = self::backend();
		if ( ! $backend ) return MAD4B_SCP_Search_Contracts::error( 'store_unavailable' );
		$rows = $backend->scan( $prefix, $after, max( 1, min( 200, (int) $limit ) ) );
		$items = array(); $cursor = $after;
		foreach ( (array) $rows as $row ) { $items[] = maybe_unserialize( $row['storage_value'] ); $cursor = $row['storage_key']; }
		return array( 'items' => $items, 'cursor' => $cursor, 'bounded' => true );
	}
}
