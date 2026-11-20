<?php
/** First-party Authorization extracted methods. Never a new execution boundary. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait MAD4B_SCP_Authorization_Observation {
	private static $remote_permission_denial_codes = array();

	public static function begin_execution_callback_observation( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( '' !== $ability_name ) {
			self::$execution_callback_started[ $ability_name ] = false;
			unset( self::$remote_permission_denial_codes[ $ability_name ] );
		}
	}

	public static function mark_execution_callback_started( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( '' !== $ability_name ) self::$execution_callback_started[ $ability_name ] = true;
	}

	public static function execution_callback_started( $ability_name ) {
		$ability_name = (string) $ability_name;
		return '' !== $ability_name && ! empty( self::$execution_callback_started[ $ability_name ] );
	}

	public static function remote_permission_denial_code( $ability_name ) {
		$ability_name = (string) $ability_name;
		// Request-local, ability-scoped diagnostic only. No ticket or OAuth evidence is exposed.
		return array_key_exists( $ability_name, self::$execution_callback_started )
			? ( isset( self::$remote_permission_denial_codes[ $ability_name ] ) ? (string) self::$remote_permission_denial_codes[ $ability_name ] : '' ) : '';
	}

	public static function clear_execution_callback_observation( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( '' !== $ability_name ) {
			unset( self::$execution_callback_started[ $ability_name ] );
			unset( self::$remote_permission_denial_codes[ $ability_name ] );
		}
	}

}
