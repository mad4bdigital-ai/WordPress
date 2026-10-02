<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical validators for cross-layer MAD4B identifiers. */
final class MAD4B_SCP_Identifiers {
	const CONTRACT = 'mad4b.identifiers.v1';
	const APPROVAL_TICKET_SCHEMA_PATTERN = '^[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-4[A-Fa-f0-9]{3}-[89ABab][A-Fa-f0-9]{3}-[A-Fa-f0-9]{12}$';

	public static function approval_ticket_id( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value )
			? $value
			: '';
	}

	public static function valid_approval_ticket_id( $value ) {
		return '' !== self::approval_ticket_id( $value );
	}
}
