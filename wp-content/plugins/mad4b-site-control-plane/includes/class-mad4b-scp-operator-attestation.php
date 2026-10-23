<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact signed Operator queue attestation, never an execution grant on its own.
 * The private RSA key is supplied by the enrolled Staging host configuration;
 * the pinned public key is held independently by the external worker.
 */
final class MAD4B_SCP_Operator_Attestation {
    const CONTRACT = 'mad4b.operator-queue-attestation.v1';

    private static function key() {
        if ( ! defined( 'MAD4B_OPERATOR_SIGNING_KEY_PATH' ) ) {
            return new WP_Error( 'mad4b_operator_signing_key_not_configured', 'Staging signer is not enrolled.' );
        }
        $configured = (string) constant( 'MAD4B_OPERATOR_SIGNING_KEY_PATH' );
        if ( is_link( $configured ) || ! is_file( $configured ) ) {
            return new WP_Error( 'mad4b_operator_signing_key_missing', 'Staging signer is unavailable.' );
        }
        $path = realpath( $configured );
        $root = realpath( ABSPATH );
        if ( false === $path || false === $root || 0 === strpos( $path, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
            return new WP_Error( 'mad4b_operator_signing_key_location_denied', 'Signer must be outside WordPress.' );
        }
        $mode = fileperms( $path );
        if ( false === $mode || 0 !== ( $mode & 0077 ) ) {
            return new WP_Error( 'mad4b_operator_signing_key_permissions_denied', 'Signer must be private.' );
        }
        if ( ! function_exists( 'openssl_sign' ) || ! function_exists( 'openssl_pkey_get_private' ) ) {
            return new WP_Error( 'mad4b_operator_signing_extension_unavailable', 'OpenSSL signing unavailable.' );
        }
        $pem = file_get_contents( $path );
        if ( ! is_string( $pem ) || strlen( $pem ) > 8192 ) {
            return new WP_Error( 'mad4b_operator_signing_key_invalid', 'Signer PEM invalid.' );
        }
        $key = openssl_pkey_get_private( $pem );
        if ( false === $key ) return new WP_Error( 'mad4b_operator_signing_key_invalid', 'Signer PEM invalid.' );
        $details = openssl_pkey_get_details( $key );
        if ( ! is_array( $details ) || OPENSSL_KEYTYPE_RSA !== $details['type'] || $details['bits'] < 3072 ) {
            return new WP_Error( 'mad4b_operator_signing_algorithm_invalid', 'RSA-3072 or stronger required.' );
        }
        return array( 'key' => $key, 'public_sha256' => hash( 'sha256', $details['key'] ) );
    }

    public static function seal( $submission ) {
        if ( ! is_array( $submission ) ||
             ! class_exists( 'MAD4B_SCP_Operator_Bridge' ) ||
             'staging' !== wp_get_environment_type() ||
             ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
             ! MAD4B_SCP_Site_Profile::configured() ||
             ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
             ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) {
            return new WP_Error( 'mad4b_operator_signing_staging_only', 'Exact ARE Staging environment required.' );
        }
        $plan = $submission['plan'] ?? null;
        $arguments = is_array( $plan ) ? ( $plan['arguments'] ?? null ) : null;
        $op = is_array( $plan ) ? (string) ( $plan['operation_id'] ?? '' ) : '';
        if ( ! is_array( $arguments ) || 0 !== strpos( $op, 'operator.' ) ||
             ! MAD4B_SCP_Operator_Bridge::request_authority_matches( $op, $arguments ) ) {
            return new WP_Error( 'mad4b_operator_signing_context_denied', 'Signed submission requires exact authenticated request context.' );
        }
        $now = time();
        $expiry = (int) ( $arguments['session_expires_at_epoch'] ?? 0 );
        if ( $expiry <= $now || $expiry > $now + 900 ) {
            return new WP_Error( 'mad4b_operator_signing_expired', 'Operator session expired or exceeds maximum duration.' );
        }
        $key = self::key();
        if ( is_wp_error( $key ) ) return $key;
        $body = array(
            'contract' => self::CONTRACT,
            'job_id' => (string) ( $submission['job_id'] ?? '' ),
            'plan_sha256' => (string) ( $submission['plan_sha256'] ?? '' ),
            'submission_sha256' => (string) ( $submission['submission_sha256'] ?? '' ),
            'operation_id' => $op,
            'session_id' => (string) ( $arguments['session_id'] ?? '' ),
            'session_expires_at_epoch' => $expiry,
            'target_fingerprint' => (string) ( $plan['target']['target_fingerprint'] ?? '' ),
            'approval_ticket_id' => (string) ( $submission['authority']['approval_ticket_id'] ?? '' ),
            'policy_decision_sha256' => (string) ( $submission['authority']['policy_decision_sha256'] ?? '' ),
            'environment' => 'staging',
            'issued_at_epoch' => $now,
        );
        $payload = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $payload ) ) return new WP_Error( 'mad4b_operator_signing_encode_failed', 'Cannot serialize signed job.' );
        $signature = '';
        if ( ! openssl_sign( $payload, $signature, $key['key'], OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'mad4b_operator_signing_failed', 'Signature failure.' );
        }
        return array(
            'contract' => self::CONTRACT,
            'body' => $body,
            'algorithm' => 'RSA-SHA256',
            'public_key_sha256' => $key['public_sha256'],
            'signature_b64' => base64_encode( $signature ),
        );
    }
}
