<?php
/** First-party Authorization extracted methods. Never a new execution boundary. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait MAD4B_SCP_Authorization_Claimed_Callback {
    /** Ephemeral proof minted ONLY inside the existing governed callback. */
    private static $operation_claim_in_callback = array();

    public static function native_binding_callback_is_claimed( $input ) {
        return self::operation_callback_is_claimed( 'mad4b/deployment-binding-native-apply', $input );
    }

    public static function google_revocation_callback_is_claimed( $input ) {
        return self::operation_callback_is_claimed( 'mad4b/google-revocation-retry', $input );
    }

    private static function operation_callback_is_claimed( $name, $input ) {
        if ( ! is_array( $input )
            || ! is_array( self::$operation_claim_in_callback )
            || $name !== ( self::$operation_claim_in_callback['ability'] ?? '' )
            || empty( self::$operation_claim_in_callback['claim_sha256'] )
            || empty( self::$operation_claim_in_callback['approval_ticket_id'] ) )
            return false;
        $ticket_id = (string) ( $input['_mad4b_approval_ticket_id'] ?? '' );
        if ( ! hash_equals( (string) self::$operation_claim_in_callback['approval_ticket_id'], $ticket_id ) )
            return false;
        $encoded = wp_json_encode( $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) && hash_equals(
            (string) self::$operation_claim_in_callback['claim_sha256'],
            hash( 'sha256', $encoded ) );
    }


    /**
     * Called solely by the immutable wrapper after the first and only
     * claim_mutation and provider-entry durable checkpoint. The receipt is
     * scoped to one exact ticket + input, erased even on PHP exceptions.
     */
    private static function invoke_claimed_callback( $name, array $claim, $input, $callback ) {
        // An unrelated/nested callback must not borrow the outer operation's
        // proof. These two operations alone consume this ephemeral receipt.
        self::$operation_claim_in_callback = array();
        if ( in_array( (string) $name, array( 'mad4b/deployment-binding-native-apply', 'mad4b/google-revocation-retry' ), true )
            && true === ( $claim['allowed'] ?? false )
            && 'execution_claimed' === (string) ( $claim['reason_code'] ?? '' )
            && ! empty( $claim['approval_required'] )
            && ! empty( $claim['approval_claimed'] )
            && ! empty( $claim['approval_ticket_id'] )
            && is_array( $input )
            && hash_equals( (string) $claim['approval_ticket_id'],
                (string) ( $input['_mad4b_approval_ticket_id'] ?? '' ) ) ) {
            $encoded = wp_json_encode( $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( is_string( $encoded ) )
                self::$operation_claim_in_callback = array(
                    'ability' => (string) $name,
                    'approval_ticket_id' => (string) $claim['approval_ticket_id'],
                    'claim_sha256' => hash( 'sha256', $encoded ) );
        }
        try {
            return call_user_func( $callback, $input );
        } finally {
            self::$operation_claim_in_callback = array();
        }
    }

}
