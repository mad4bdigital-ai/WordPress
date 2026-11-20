<?php
/** First-party Authorization extracted methods. Never a new execution boundary. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait MAD4B_SCP_Authorization_Remote_Denial {
	public static function audit_remote_permission_denial( $ability_name, $error, $input = null ) {
		if ( ! is_wp_error( $error ) ) return false;
		$server_id = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		if ( ! in_array( $server_id, array( 'mad4b-chatgpt', 'mad4b-write', 'mad4b-developer', 'mad4b-developer-breakglass' ), true ) ) return false;
		$ability_name = (string) $ability_name;
		if ( array_key_exists( $ability_name, self::$execution_callback_started ) ) {
			self::$remote_permission_denial_codes[ $ability_name ] = sanitize_key( (string) $error->get_error_code() );
		}
		self::audit_execution_denial( $ability_name, $error, $input );
		return true;
	}

	private static function audit_execution_denial( $ability_name, $error, $input = null ) {
		if ( ! is_wp_error( $error ) ) return;
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		$ticket_id = is_array( $identity ) && isset( $identity['approval_ticket_id'] ) && preg_match( '/^[a-f0-9-]{36}$/', (string) $identity['approval_ticket_id'] )
			? strtolower( (string) $identity['approval_ticket_id'] )
			: '';

		if ( '' === $ticket_id
			&& 'mad4b_approval_replay_denied' === (string) $error->get_error_code()
			&& class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) {
			$input_ticket = strtolower( trim( (string) MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input ) ) );
			if ( 1 === preg_match( '/^[a-f0-9-]{36}$/', $input_ticket ) ) $ticket_id = $input_ticket;
		}

		self::audit( $ability_name, array(
			'allowed' => false,
			'reason_code' => $error->get_error_code(),
			'request_id' => is_array( $identity ) && isset( $identity['request_id'] ) ? (string) $identity['request_id'] : '',
			'approval_ticket_id' => $ticket_id,
		), 'denied' );
	}

}
