<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governs the approval-plan mutation used to bootstrap a one-time exact ticket.
 *
 * approval-plan is itself a database mutation (it creates a pending ticket), so
 * on the exact Staging Plugin it must require the same NHI + mad4b-write grant +
 * budget path. It is the one intentional exception to "write requires an
 * already-approved ticket", because requiring a ticket to create the ticket
 * would deadlock the approval workflow. It can only create PENDING tickets;
 * approval remains a separate human-administrator action.
 */
final class MAD4B_SCP_Staging_Write_Planning_Guard {
	const CONTRACT = 'mad4b.staging-write-planning-guard.v1';
	const ABILITY = 'mad4b/approval-plan';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'govern_registration' ), 85, 2 );
	}

	public static function govern_registration( $args, $name ) {
		if ( ! is_array( $args ) || self::ABILITY !== (string) $name ) return $args;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::eligible() ) return $args;
		if ( ! isset( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) return $args;

		$original = $args['permission_callback'];
		$args['permission_callback'] = static function ( $input = null ) use ( $original ) {
			$granted = call_user_func( $original, $input );
			if ( is_wp_error( $granted ) || ! $granted ) return $granted;
			if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'Governed Staging mutation authority is required to create an approval plan.' );
			if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
			$decision = MAD4B_SCP_Authorization::authorize_mutation( self::ABILITY, 'mad4b-admin', 'core', $input );
			return is_wp_error( $decision ) ? $decision : true;
		};
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_governed_write_authority'] = MAD4B_SCP_Staging_Write_Authority::CONTRACT;
		$args['meta']['mcp']['mad4b_approval_bootstrap_operation'] = true;
		$args['meta']['mcp']['mad4b_creates_pending_ticket_only'] = true;
		return $args;
	}

	public static function remote_bootstrap_scope_allowed( array $identity, $server_id, $ability_name ) {
		if ( self::ABILITY !== (string) $ability_name || 'mad4b-write' !== sanitize_key( (string) $server_id ) ) return false;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return false;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		return in_array( 'mad4b:read', $scopes, true );
	}

	public static function approval_required( $ability_name, $current ) {
		if ( self::ABILITY !== (string) $ability_name ) return (bool) $current;
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! MAD4B_SCP_Staging_Write_Authority::effective() ) return (bool) $current;
		return false;
	}
}
