<?php
define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_current_user_id() { return 7; }
function apply_filters( $name, $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ); }
function wp_generate_uuid4() { return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; }

require __DIR__ . '/../includes/class-mad4b-scp-identifiers.php';
require __DIR__ . '/../includes/class-mad4b-scp-identity-context.php';

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};
$ticket = '11111111-1111-4111-8111-111111111111';
$other = '22222222-2222-4222-8222-222222222222';

$before = MAD4B_SCP_Identity_Context::current();
$check( is_array( $before ) && '' === $before['approval_ticket_id'], 'Approval identity must start empty.' );

$result = MAD4B_SCP_Identity_Context::with_approval_ticket_for_request(
	$ticket,
	static function () use ( $check, $ticket, $other ) {
		$current = MAD4B_SCP_Identity_Context::current();
		$check( $ticket === $current['approval_ticket_id'], 'Scoped approval ticket is not visible during execution.' );

		$same = MAD4B_SCP_Identity_Context::with_approval_ticket_for_request(
			$ticket,
			static function () use ( $ticket ) {
				return MAD4B_SCP_Identity_Context::current()['approval_ticket_id'] === $ticket ? 'same-ticket-ok' : 'same-ticket-missing';
			}
		);
		$check( 'same-ticket-ok' === $same, 'Nested same-ticket scope should preserve exact identity.' );

		$different = MAD4B_SCP_Identity_Context::with_approval_ticket_for_request( $other, static function () { return 'unexpected'; } );
		$check( is_wp_error( $different ) && 'mad4b_identity_approval_rebind_conflict' === $different->get_error_code(), 'Different approval ticket rebound an active execution scope.' );
		$check( $ticket === MAD4B_SCP_Identity_Context::current()['approval_ticket_id'], 'Rejected nested rebind corrupted the active ticket.' );
		return 'ok';
	}
);
$check( 'ok' === $result, 'Scoped approval callback failed.' );
$check( '' === MAD4B_SCP_Identity_Context::current()['approval_ticket_id'], 'Approval ticket leaked after successful execution.' );

try {
	MAD4B_SCP_Identity_Context::with_approval_ticket_for_request(
		$ticket,
		static function () {
			throw new RuntimeException( 'fixture exception' );
		}
	);
	throw new RuntimeException( 'Expected scoped exception was swallowed.' );
} catch ( RuntimeException $error ) {
	$check( 'fixture exception' === $error->getMessage(), 'Unexpected scoped exception result.' );
}
$check( '' === MAD4B_SCP_Identity_Context::current()['approval_ticket_id'], 'Approval ticket leaked after exceptional execution.' );

$invalid = MAD4B_SCP_Identity_Context::with_approval_ticket_for_request( 'not-a-ticket', static function () { return true; } );
$check( is_wp_error( $invalid ) && 'mad4b_identity_approval_invalid' === $invalid->get_error_code(), 'Malformed approval ticket entered execution scope.' );
$wrong_version = '11111111-1111-1111-8111-111111111111';
$check( ! MAD4B_SCP_Identifiers::valid_approval_ticket_id( $wrong_version ), 'Non-v4 UUID was accepted as approval ticket identity.' );
$wrong_version_result = MAD4B_SCP_Identity_Context::with_approval_ticket_for_request( $wrong_version, static function () { return true; } );
$check( is_wp_error( $wrong_version_result ) && 'mad4b_identity_approval_invalid' === $wrong_version_result->get_error_code(), 'Structurally valid non-v4 UUID entered approval scope.' );
$upper = strtoupper( $ticket );
$check( $ticket === MAD4B_SCP_Identifiers::approval_ticket_id( $upper ), 'Canonical ticket normalization did not lower-case a valid UUIDv4.' );

echo "PASS approval identity scope: exact ticket, nested conflict denial, success/exception restoration\n";
