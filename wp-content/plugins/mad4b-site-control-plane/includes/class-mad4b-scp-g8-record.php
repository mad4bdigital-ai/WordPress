<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Internal single-record CAS for non-authorizing observation documents. */
final class MAD4B_SCP_G8_Record {
	const PREFIX = 'mad4b_scp_g8_';

	public static function digest( $value ) { return hash( 'sha256', serialize( $value ) ); }

	public static function read( $option ) {
		if ( ! self::owned( $option ) ) return new WP_Error( 'mad4b_g8_record_namespace', 'Record is outside the observation namespace.' );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' ); wp_cache_delete( 'notoptions', 'options' );
		}
		return get_option( $option, null );
	}

	public static function replace( $option, $expected, array $next ) {
		$nodes = 0;
		if ( ! self::owned( $option ) || ! self::plain_data( $next, 0, $nodes )
			|| strlen( serialize( $next ) ) > 262144 )
			return new WP_Error( 'mad4b_g8_record_invalid', 'Observation record is invalid or exceeds its bound.' );
		$nodes = 0;
		if ( null !== $expected && ! self::plain_data( $expected, 0, $nodes ) )
			return new WP_Error( 'mad4b_g8_record_invalid', 'Expected observation prestate is not inert data.' );
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) || ! function_exists( 'maybe_serialize' ) ) return new WP_Error( 'mad4b_g8_atomic_storage_required', 'Atomic observation storage is unavailable.' );
		$current = self::read( $option );
		$nodes = 0;
		if ( null !== $current && ! self::plain_data( $current, 0, $nodes ) )
			return new WP_Error( 'mad4b_g8_record_invalid', 'Stored observation is not inert data.' );
		if ( serialize( $current ) !== serialize( $expected ) ) return new WP_Error( 'mad4b_g8_record_conflict', 'Observation record changed before cutover.' );
		if ( serialize( $expected ) === serialize( $next ) ) return true;
		if ( null === $expected ) $ok = add_option( $option, $next, '', false );
		else $ok = 1 === (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
			maybe_serialize( $next ), $option, maybe_serialize( $expected )
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- one owned row, exact prestate CAS.
		if ( ! $ok ) return new WP_Error( 'mad4b_g8_record_conflict', 'Atomic observation cutover lost its prestate.' );
		return serialize( self::read( $option ) ) === serialize( $next ) ? true : new WP_Error( 'mad4b_g8_record_readback_uncertain', 'Observation cutover requires reconciliation.' );
	}

	public static function seal( array $record ) {
		unset( $record['seal'] );
		return hash_hmac( 'sha256', serialize( $record ), wp_salt( 'auth' ) );
	}

	/** Only passive, bounded scalars may be interpreted as a stored record. */
	private static function plain_data( $value, $depth, &$nodes ) {
		if ( ++$nodes > 8192 || $depth > 16 || is_object( $value ) || is_resource( $value ) ) return false;
		if ( is_array( $value ) ) {
			if ( count( $value ) > 512 ) return false;
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && strlen( $key ) > 255 ) return false;
				if ( ! self::plain_data( $item, $depth + 1, $nodes ) ) return false;
			}
			return true;
		}
		return null === $value || is_bool( $value ) || is_int( $value )
			|| ( is_float( $value ) && is_finite( $value ) )
			|| ( is_string( $value ) && strlen( $value ) <= 65536 );
	}

	public static function valid( $record, $contract ) {
		if ( ! is_array( $record ) || $contract !== ( $record['contract'] ?? '' )
			|| false !== ( $record['authorizing'] ?? null )
			|| ! is_string( $record['seal'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record['seal'] ) ) return false;
		// Before HMAC verification, reject PHP objects and over-sized trees:
		// serialize() could otherwise invoke a provider-controlled __serialize.
		$nodes = 0;
		if ( ! self::plain_data( $record, 0, $nodes ) || strlen( serialize( $record ) ) > 262144 ) return false;
		return hash_equals( self::seal( $record ), $record['seal'] );
	}

	public static function profile() {
		return class_exists( 'MAD4B_SCP_Site_Profile', false ) ? (string) MAD4B_SCP_Site_Profile::profile_digest() : '';
	}

	/** Read the external restore anchor; never initializes an epoch or changes authority. */
	public static function binding() {
		$epoch = class_exists( 'MAD4B_SCP_Restore_Epoch', false ) ? MAD4B_SCP_Restore_Epoch::status( false, true ) : array();
		if ( ! is_array( $epoch ) || true !== ( $epoch['ready'] ?? false ) || ! is_int( $epoch['epoch'] ?? null ) || $epoch['epoch'] < 1
			|| ! is_string( $epoch['external_record_sha256'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $epoch['external_record_sha256'] ) ) return new WP_Error( 'mad4b_g8_restore_anchor_unavailable', 'Current external restore anchor is required for automatic work.' );
		return array( 'profile_digest' => self::profile(), 'site_uuid' => (string) $epoch['site_uuid'], 'epoch' => $epoch['epoch'], 'external_record_sha256' => $epoch['external_record_sha256'] );
	}

	public static function runtime_binding() {
		$identity = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer', false ) && method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' )
			? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status() : array();
		if ( ! is_array( $identity ) || true !== ( $identity['identity_ready'] ?? false ) ) return new WP_Error( 'mad4b_g8_runtime_identity_unavailable', 'Current runtime identity is required.' );
		$material = array();
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $key ) {
			if ( ! is_string( $identity[ $key ] ?? null ) || '' === $identity[ $key ] ) return new WP_Error( 'mad4b_g8_runtime_identity_incomplete', 'Runtime identity is incomplete.' );
			$material[ $key ] = $identity[ $key ];
		}
		return self::digest( $material );
	}

	public static function staging() {
		return class_exists( 'MAD4B_SCP_Site_Profile', false ) && MAD4B_SCP_Site_Profile::configured()
			&& 'staging' === MAD4B_SCP_Site_Profile::current_environment() && MAD4B_SCP_Site_Profile::origin_enrolled()
			&& MAD4B_SCP_Site_Profile::site_urls_match_enrollment() && MAD4B_SCP_Site_Profile::managed_runtime_enabled()
			&& ! ( defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === MAD4B_MCP_BREAKGLASS_ENABLED );
	}

	private static function owned( $option ) {
		return is_string( $option ) && 1 === preg_match( '/^mad4b_scp_g8_[a-z0-9_]{1,100}$/D', $option );
	}
}
