<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Connection-owned, nonblocking catalog mutex. Never grants execution authority. */
final class MAD4B_SCP_Distributed_Lock {
	private static $held = array();
	public static function catalog_name( $scope ) {
		global $wpdb;
		$database = defined( 'DB_NAME' ) ? DB_NAME : ( $wpdb->dbname ?? '' );
		// Advisory lock namespaces are server-wide, including across databases.
		return 'mad4b-catalog-' . substr( hash( 'sha256', $database . ':' . $wpdb->options . ':' . $scope ), 0, 48 );
	}
	public static function acquire( $name ) {
		global $wpdb;
		if ( isset( self::$held[$name] ) ) return new WP_Error( 'mad4b_catalog_build_in_progress', 'Catalog build already active.', array( 'status' => 409 ) );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( null === $acquired ) return new WP_Error( 'mad4b_catalog_build_lock_unavailable', 'Catalog build mutex unavailable.', array( 'status' => 503 ) );
		if ( 1 !== (int) $acquired ) return new WP_Error( 'mad4b_catalog_build_in_progress', 'Catalog build already active.', array( 'status' => 409 ) );
		self::$held[$name] = true;
		return true;
	}
	public static function owns( $name ) {
		global $wpdb;
		return isset( self::$held[$name] ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name ) );
	}
	public static function release( $name ) {
		global $wpdb;
		if ( ! isset( self::$held[$name] ) ) return;
		unset( self::$held[$name] );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
