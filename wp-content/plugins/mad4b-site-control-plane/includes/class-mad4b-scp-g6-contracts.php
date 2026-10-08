<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared bounded, non-authorizing contracts for optional G6 services. */
final class MAD4B_SCP_G6_Contracts {
	public static function digest( $value ) {
		$json = wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}
	public static function canonical( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonical( $item );
		return $value;
	}
	public static function sha( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
	public static function id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_.:-]{0,190}$/D', $value ); }
	public static function error( $suffix, $message, $data = array() ) {
		return new WP_Error( 'mad4b_g6_' . $suffix, $message, array_merge( array( 'authorizing' => false, 'blind_retry_allowed' => false ), $data ) );
	}
	public static function bounded( $value, $depth = 0, &$items = 0 ) {
		if ( $depth > 12 || ++$items > 4096 || is_object( $value ) || is_resource( $value ) ) return false;
		if ( is_float( $value ) && ! is_finite( $value ) ) return false;
		if ( is_string( $value ) ) return strlen( $value ) <= 65536;
		if ( ! is_array( $value ) ) return is_scalar( $value ) || null === $value;
		foreach ( $value as $key => $item ) {
			if ( strlen( (string) $key ) > 191 || ! self::bounded( $item, $depth + 1, $items ) ) return false;
		}
		return true;
	}
	public static function data( $value ) {
		$items = 0;
		if ( ! self::bounded( $value, 0, $items ) ) return self::error( 'input_budget', 'Input exceeds bounded JSON limits.' );
		$json = wp_json_encode( $value );
		if ( ! is_string( $json ) || strlen( $json ) > 262144 ) return self::error( 'input_budget', 'Input exceeds canonical byte limits.' );
		return $value;
	}
	/** Control keys cannot travel inside generated/retrieved/model inputs. */
	public static function untrusted_arguments( $value ) {
		$bounded = self::data( $value );
		if ( is_wp_error( $bounded ) ) return $bounded;
		if ( is_array( $value ) ) foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && ( 0 === strpos( $key, '_mad4b' ) || preg_match( '/(^|_)(approval|authority|grant|credential|token|secret|password|callback|executor|php|shell|sql|endpoint)(_|$)/i', $key ) ) ) return self::error( 'untrusted_control_key', 'Untrusted data contains an authority, secret or executable control key.' );
			$child = self::untrusted_arguments( $item );
			if ( is_wp_error( $child ) ) return $child;
		}
		return $value;
	}
	public static function owner() {
		$id = (int) get_current_user_id();
		if ( $id <= 0 || ! current_user_can( 'manage_options' ) ) return self::error( 'owner_required', 'An authenticated administrator owns this optional workspace.' );
		return $id;
	}
	public static function site() {
		$site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '';
		return 1 === preg_match( '/^[a-f0-9-]{36}$/D', $site ) ? $site : self::error( 'site_missing', 'Exact Site Profile identity is unavailable.' );
	}
	public static function binding( $profile_digest = '' ) {
		$site = self::site();
		if ( is_wp_error( $site ) ) return $site;
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Generation_Fence' ) || ! class_exists( 'MAD4B_SCP_Restore_Epoch' ) ) return self::error( 'generation_missing', 'Runtime generation and restore epoch are required.' );
		$generation = MAD4B_SCP_Runtime_Generation_Fence::capture();
		$epoch = MAD4B_SCP_Restore_Epoch::material();
		if ( is_wp_error( $generation ) ) return $generation;
		if ( is_wp_error( $epoch ) ) return $epoch;
		return array( 'site_uuid' => $site, 'environment' => wp_get_environment_type(), 'generation_sha256' => $generation['generation_sha256'], 'artifact_sha256' => self::digest( array( 'runtime' => $generation['material'], 'compiler' => hash_file( 'sha256', __DIR__ . '/class-mad4b-scp-g6-operation-compiler.php' ) ) ), 'restore_epoch' => (int) $epoch['epoch'], 'profile_digest' => (string) $profile_digest );
	}
	public static function assert_digest( array $record, $key, $contract ) {
		$sha = isset( $record[ $key ] ) ? $record[ $key ] : '';
		unset( $record[ $key ] );
		return isset( $record['contract'] ) && $contract === $record['contract'] && self::sha( $sha ) && hash_equals( $sha, self::digest( $record ) );
	}
	/** Structured diff values are digests; private content never enters generic evidence. */
	public static function diff( $before, $after, $path = '' ) {
		if ( self::digest( $before ) === self::digest( $after ) ) return array();
		if ( is_array( $before ) && is_array( $after ) ) {
			$out = array(); $keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ); sort( $keys, SORT_STRING );
			foreach ( $keys as $key ) {
				$had = array_key_exists( $key, $before ); $has = array_key_exists( $key, $after );
				if ( $had !== $has ) $out[] = array( 'path' => $path . '/' . (string) $key, 'change_type' => $has ? 'added' : 'removed', 'before_sha256' => self::digest( array( 'present' => $had, 'value' => $had ? $before[ $key ] : null ) ), 'after_sha256' => self::digest( array( 'present' => $has, 'value' => $has ? $after[ $key ] : null ) ), 'values_redacted' => true );
				else $out = array_merge( $out, self::diff( $before[ $key ], $after[ $key ], $path . '/' . (string) $key ) );
			}
			return $out;
		}
		return array( array( 'path' => $path, 'before_sha256' => self::digest( $before ), 'after_sha256' => self::digest( $after ), 'values_redacted' => true ) );
	}
	/** CAS storage is private to a user and site; no authority records are stored here. */
	public static function load( $kind, $owner ) {
		$actor = self::owner(); if ( is_wp_error( $actor ) ) return $actor;
		if ( ! is_int( $owner ) || $owner !== $actor || ! self::id( $kind ) )
			return self::error( 'store_owner_scope', 'Private G6 storage must be accessed by its authenticated owner.' );
		$site = self::site(); if ( is_wp_error( $site ) ) return $site;
		$key = '_mad4b_g6_' . $kind . '_' . hash( 'sha256', $site );
		// Multiple user-meta rows are ambiguous; single-row reads must not
		// quietly accept the first while writes reject the same registry.
		$rows = get_user_meta( $owner, $key, false );
		if ( ! is_array( $rows ) ) return self::error( 'store_corrupt', 'Private workspace reader returned an invalid shape.' );
		if ( count( $rows ) > 1 ) return self::error( 'store_duplicate', 'Duplicate private storage rows require reconciliation.' );
		if ( ! $rows ) return array( 'revision' => 0, 'items' => array() );
		$record = $rows[0];
		if ( ! is_array( $record ) || ! isset( $record['revision'], $record['items'] )
			|| ! is_int( $record['revision'] ) || $record['revision'] < 1
			|| $record['revision'] >= PHP_INT_MAX || ! is_array( $record['items'] ) )
			return self::error( 'store_corrupt', 'Private workspace revision or registry shape is invalid.' );
		return $record;
	}
	public static function save( $kind, $owner, array $before, array $after ) {
		$actor = self::owner(); if ( is_wp_error( $actor ) ) return $actor;
		if ( ! is_int( $owner ) || $owner !== $actor || ! self::id( $kind ) )
			return self::error( 'store_owner_scope', 'Private G6 storage must be updated only by its authenticated owner.' );
		$site = self::site(); if ( is_wp_error( $site ) ) return $site;
		$key = '_mad4b_g6_' . $kind . '_' . hash( 'sha256', $site );
		// CAS must never coerce untrusted revisions or wrap an integer counter.
		if ( ! isset( $before['revision'], $before['items'], $after['revision'], $after['items'] )
			|| ! is_int( $before['revision'] ) || $before['revision'] < 0
			|| $before['revision'] >= PHP_INT_MAX
			|| ! is_int( $after['revision'] ) || $after['revision'] !== $before['revision']
			|| ! is_array( $before['items'] ) || ! is_array( $after['items'] ) )
			return self::error( 'store_revision_invalid', 'An exact non-overflowing registry revision and item list are required.' );
		$guard = self::data( $after ); if ( is_wp_error( $guard ) ) return $guard;
		if ( ! class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) return self::error( 'store_mutex_missing', 'Private workspace requires the existing distributed mutex.' );
		$lock = MAD4B_SCP_Distributed_Lock::catalog_name( 'g6-private-store:' . $owner . ':' . $key );
		$acquired = MAD4B_SCP_Distributed_Lock::acquire( $lock ); if ( is_wp_error( $acquired ) ) return $acquired;
		try {
			wp_cache_delete( $owner, 'user_meta' );
			$rows = get_user_meta( $owner, $key, false );
			if ( count( $rows ) > 1 ) return self::error( 'store_duplicate', 'Duplicate private storage rows require reconciliation.' );
			$current = $rows ? $rows[0] : array( 'revision' => 0, 'items' => array() );
			if ( $current !== $before || ! MAD4B_SCP_Distributed_Lock::owns( $lock ) ) return self::error( 'revision_conflict', 'Workspace changed; reload before editing.' );
			$after['revision'] = (int) $before['revision'] + 1;
			$ok = ! $rows ? add_user_meta( $owner, $key, $after, true ) : update_user_meta( $owner, $key, $after, $before );
			if ( ! $ok ) return self::error( 'revision_conflict', 'Workspace changed; reload before editing.' );
			wp_cache_delete( $owner, 'user_meta' );
			$readback = get_user_meta( $owner, $key, false );
			if ( 1 !== count( $readback ) || $readback[0] !== $after || ! MAD4B_SCP_Distributed_Lock::owns( $lock ) ) return self::error( 'readback_failed', 'Workspace save requires reconciliation.' );
			return $after;
		} finally { MAD4B_SCP_Distributed_Lock::release( $lock ); }
	}
}
