<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Independently verify the VPS worker's signed Operator receipt.
 * A JSON file alone is never accepted as evidence of execution.
 */
final class MAD4B_SCP_Operator_Receipt_Verifier {
    const CONTRACT = 'mad4b.operator-host-receipt.v1';

    private static function worker_public_key() {
        if ( ! defined( 'MAD4B_OPERATOR_WORKER_PUBLIC_KEY_PATH' ) ) {
            return new WP_Error( 'mad4b_operator_worker_key_unconfigured', 'Worker identity not pinned.' );
        }
        $configured = (string) constant( 'MAD4B_OPERATOR_WORKER_PUBLIC_KEY_PATH' );
        if ( is_link( $configured ) || ! is_file( $configured ) )
            return new WP_Error( 'mad4b_operator_worker_key_missing', 'Worker public key unavailable.' );
        $resolved = realpath( $configured );
        if ( false === $resolved || 0 === strpos( $resolved, realpath( ABSPATH ) . DIRECTORY_SEPARATOR ) )
            return new WP_Error( 'mad4b_operator_worker_key_location_denied', 'Worker public key must be outside WordPress.' );
        $pem = file_get_contents( $resolved );
        if ( ! is_string( $pem ) || strlen( $pem ) > 8192 )
            return new WP_Error( 'mad4b_operator_worker_key_invalid', 'Pinned public key invalid.' );
        $key = openssl_pkey_get_public( $pem );
        if ( false === $key ) return new WP_Error( 'mad4b_operator_worker_key_invalid', 'Worker public key invalid.' );
        $details = openssl_pkey_get_details( $key );
        if ( ! is_array( $details ) || OPENSSL_KEYTYPE_RSA !== $details['type'] || $details['bits'] < 3072 )
            return new WP_Error( 'mad4b_operator_worker_key_algorithm_denied', 'RSA-3072 minimum required.' );
        return array( 'key' => $key, 'sha256' => hash( 'sha256', $pem ) );
    }

    private static function verify_site_seal( $seal ) {
        if ( ! defined( 'MAD4B_OPERATOR_SIGNER_PUBLIC_KEY_PATH' ) )
            return new WP_Error( 'mad4b_operator_site_pubkey_not_pinned', 'Staging signer public key unavailable.' );
        $path = (string) constant( 'MAD4B_OPERATOR_SIGNER_PUBLIC_KEY_PATH' );
        if ( is_link( $path ) || ! is_file( $path ) )
            return new WP_Error( 'mad4b_operator_site_pubkey_invalid', 'Staging signer public key invalid.' );
        $public = file_get_contents( $path );
        if ( ! is_string( $public ) || strlen( $public ) > 8192 ||
             ! hash_equals( hash( 'sha256', $public ), (string) ( $seal['public_key_sha256'] ?? '' ) ) )
            return new WP_Error( 'mad4b_operator_site_pubkey_mismatch', 'Staging signing key does not match pin.' );
        $body = $seal['body'] ?? null;
        if ( ! is_array( $body ) || 'mad4b.operator-queue-attestation.v1' !== (string) ( $seal['contract'] ?? '' ) ||
             'RSA-SHA256' !== (string) ( $seal['algorithm'] ?? '' ) )
            return new WP_Error( 'mad4b_operator_site_seal_contract_invalid', 'Staging queue seal contract invalid.' );
        $sig = base64_decode( (string) ( $seal['signature_b64'] ?? '' ), true );
        $encoded = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $sig ) || ! is_string( $encoded ) ||
             1 !== openssl_verify( $encoded, $sig, $public, OPENSSL_ALGO_SHA256 ) )
            return new WP_Error( 'mad4b_operator_site_seal_signature_invalid', 'Staging queue signature invalid.' );
        return true;
    }

    public static function verify( $receipt, $seal, $job_id, $current_target ) {
        if ( ! is_array( $receipt ) || ! is_array( $seal ) || ! is_array( $current_target ) ||
             'staging' !== wp_get_environment_type() || 'https://staging.allroyalegypt.com' !== home_url() )
            return new WP_Error( 'mad4b_operator_receipt_environment_denied', 'Exact Staging receipt required.' );
        $site_seal = self::verify_site_seal( $seal );
        if ( is_wp_error( $site_seal ) ) return $site_seal;
        $body = $receipt['body'] ?? null;
        $signed = $seal['body'] ?? null;
        if ( ! is_array( $body ) || ! is_array( $signed ) ||
             self::CONTRACT !== (string) ( $receipt['contract'] ?? '' ) ||
             self::CONTRACT !== (string) ( $body['contract'] ?? '' ) ||
             'RSA-SHA256' !== (string) ( $receipt['algorithm'] ?? '' ) )
            return new WP_Error( 'mad4b_operator_receipt_contract_invalid', 'Receipt signature contract malformed.' );
        foreach ( array( 'job_id', 'plan_sha256', 'submission_sha256', 'operation_id',
                         'session_id', 'target_fingerprint', 'approval_ticket_id' ) as $field ) {
            if ( ! isset( $body[$field] ) || ! isset( $signed[$field] ) ||
                 ! is_string( $body[$field] ) || ! hash_equals( $signed[$field], $body[$field] ) )
                return new WP_Error( 'mad4b_operator_receipt_binding_mismatch', 'Receipt is not bound to the queued signed job.' );
        }
        if ( ! hash_equals( $job_id, $body['job_id'] ) ||
             ! hash_equals( (string) ( $current_target['target_fingerprint'] ?? '' ), $body['target_fingerprint'] ) ||
             'staging' !== (string) ( $body['environment'] ?? '' ) ||
             empty( $body['worker_live_session_verified'] ) ||
             ( 'operator.sql.read' !== $body['operation_id'] && empty( $body['worker_live_approval_verified'] ) ) ||
             ! in_array( (string) ( $body['verdict'] ?? '' ), array( 'PASS', 'FAIL' ), true ) )
            return new WP_Error( 'mad4b_operator_receipt_verification_denied', 'Worker receipt is not a verified Staging execution.' );
        if ( ! is_int( $body['completed_at_epoch'] ?? null ) ||
             (int) $body['completed_at_epoch'] < (int) ( $signed['issued_at_epoch'] ?? 0 ) ||
             (int) $body['completed_at_epoch'] > time() + 30 )
            return new WP_Error( 'mad4b_operator_receipt_time_invalid', 'Worker receipt time is invalid.' );
        $key = self::worker_public_key();
        if ( is_wp_error( $key ) ) return $key;
        if ( ! is_string( $receipt['public_key_sha256'] ?? null ) ||
             ! hash_equals( $key['sha256'], (string) $receipt['public_key_sha256'] ) )
            return new WP_Error( 'mad4b_operator_receipt_worker_identity_invalid', 'Worker signing identity mismatch.' );
        $sig = base64_decode( (string) ( $receipt['signature_b64'] ?? '' ), true );
        $payload = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $sig ) || ! is_string( $payload ) || 1 !== openssl_verify( $payload, $sig, $key['key'], OPENSSL_ALGO_SHA256 ) )
            return new WP_Error( 'mad4b_operator_receipt_bad_signature', 'Worker RSA signature invalid.' );
        return array(
            'contract' => 'mad4b.operator-receipt-verified.v1',
            'verified' => true,
            'job_id' => $job_id,
            'operation_id' => $body['operation_id'],
            'verdict' => $body['verdict'],
            'exit_code' => $body['exit_code'] ?? null,
            'output_sha256' => $body['output_sha256'] ?? '',
            'worker_live_session_verified' => true,
            'worker_live_approval_verified' => ! empty( $body['worker_live_approval_verified'] ),
            'production_authorized' => false,
            'mutation_performed' => false,
        );
    }
}
