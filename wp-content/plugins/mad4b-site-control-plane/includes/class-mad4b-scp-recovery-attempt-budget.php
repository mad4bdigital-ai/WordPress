<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Persistent atomic slots for governed Context write retries.
 * add_option is a unique-key insert in wp_options: two concurrent writers
 * cannot acquire the same ordinal attempt. No client attempt counter is read.
 */
final class MAD4B_SCP_Recovery_Attempt_Budget {
    const CONTRACT = 'mad4b.recovery-attempt-budget.v1';
    const PREFIX = 'mad4b_scp_retry_slot_v1_';
    const LIMIT = 3;
    const LANES = array( 'brand_draft_create', 'materialize_brand_draft', 'reconcile_brand_materialization' );

    private static function error( $code, $message ) { return new WP_Error( $code, $message ); }

    private static function basis( $lane, $input ) {
        $lane = sanitize_key( (string) $lane );
        if ( ! in_array( $lane, self::LANES, true ) || ! is_array( $input ) ) return self::error( 'mad4b_retry_lane_invalid', 'Recovery retry lane is invalid.' );
        $category = isset( $input['category'] ) ? sanitize_key( (string) $input['category'] ) : '';
        $artifact = isset( $input['artifact_id'] ) ? (string) $input['artifact_id'] : '';
        $plan = isset( $input['expected_plan_sha256'] ) ? (string) $input['expected_plan_sha256'] : '';
        $source = isset( $input['source_id'] ) ? (string) $input['source_id'] : '';
        if ( 'brand_draft_create' === $lane ) {
            if ( ! in_array( $category, array( 'tone_of_voice', 'editorial_guidelines' ), true ) || ! preg_match( '/^[a-f0-9]{64}$/', $plan ) )
                return self::error( 'mad4b_retry_binding_missing', 'Draft retry requires exact category and source plan fingerprint.' );
        } elseif ( ! preg_match( '/^[a-f0-9-]{36}$/i', $artifact ) ) {
            return self::error( 'mad4b_retry_binding_missing', 'Materialization retry requires an exact artifact identity.' );
        }
        $site = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
        $environment = class_exists( 'MAD4B_SCP_Environment' ) ? (string) MAD4B_SCP_Environment::effective()
            : ( function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '' );
        if ( '' === $site || '' === $environment ) return self::error( 'mad4b_retry_site_identity_unavailable', 'Site and environment identity are required for persistent retries.' );
        return hash( 'sha256', wp_json_encode( array(
            'contract' => self::CONTRACT, 'site' => $site, 'environment' => $environment,
            'lane' => $lane, 'category' => $category, 'source' => $source,
            'artifact' => $artifact, 'expected_plan_sha256' => $plan,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    private static function key( $hash, $ordinal ) { return self::PREFIX . $hash . '_' . (int) $ordinal; }

    public static function status( $lane, $input ) {
        $basis = self::basis( $lane, $input );
        if ( is_wp_error( $basis ) ) return $basis;
        $rows = array();
        for ( $i = 1; $i <= self::LIMIT; ++$i ) {
            $row = get_option( self::key( $basis, $i ), false );
            if ( false === $row ) break;
            if ( ! is_array( $row ) || ! isset( $row['scope_sha256'] )
                || ! hash_equals( $basis, (string) $row['scope_sha256'] ) )
                return self::error( 'mad4b_retry_journal_corrupt', 'Persistent retry journal cannot be trusted.' );
            $rows[] = array( 'attempt' => $i, 'reserved_at' => isset( $row['reserved_at'] ) ? $row['reserved_at'] : '', 'outcome' => isset( $row['outcome'] ) ? $row['outcome'] : 'unknown' );
        }
        // A discontinuous journal must fail closed; a transient read miss
        // is not evidence that a higher attempt has never been reserved.
        for ( $i = count( $rows ) + 1; $i <= self::LIMIT; ++$i ) {
            if ( false !== get_option( self::key( $basis, $i ), false ) )
                return self::error( 'mad4b_retry_journal_gap', 'Recovery retry journal has an unexpected gap.' );
        }
        $count = count( $rows );
        $sha = hash( 'sha256', wp_json_encode( array( $basis, $rows ), JSON_UNESCAPED_SLASHES ) );
        return array( 'contract' => self::CONTRACT,
            'scope_sha256' => $basis, 'attempts_reserved' => $count,
            'max_attempts' => self::LIMIT, 'remaining' => self::LIMIT - $count,
            'circuit_open' => $count >= self::LIMIT,
            'last_outcome' => $count ? $rows[ $count - 1 ]['outcome'] : 'none',
            'retry_eligible' => $count < self::LIMIT && ( 0 === $count || 'failed' === $rows[ $count - 1 ]['outcome'] ),
            'journal_sha256' => $sha, 'attempts' => $rows,
            'persisted' => true, 'count_authoritative' => true,
            'read_only' => true, 'mutation_performed' => false );
    }

    /** Used by existing independently authorized Context write endpoints. */
    public static function reserve( $lane, $input ) {
        $status = self::status( $lane, $input );
        if ( is_wp_error( $status ) ) return $status;
        if ( $status['circuit_open'] ) return self::error( 'mad4b_retry_circuit_open', 'Retry limit reached; operator reconciliation required.' );
        $hash = $status['scope_sha256'];
        $at = (int) $status['attempts_reserved'] + 1;
        if ( $at > 1 ) {
            $prior = get_option( self::key( $hash, $at - 1 ), false );
            if ( ! is_array( $prior ) || ! isset( $prior['outcome'] ) || 'failed' !== $prior['outcome'] )
                return self::error( 'mad4b_retry_prior_not_reconciled', 'Previous operation succeeded or has an unknown outcome; independently reconcile before retry.' );
        }
        $value = array( 'scope_sha256' => $hash, 'reserved_at' => gmdate( 'c' ), 'outcome' => 'pending' );
        if ( ! add_option( self::key( $hash, $at ), $value, '', false ) )
            return self::error( 'mad4b_retry_concurrent_reservation', 'Another worker reserved this retry; re-read authoritative status.' );
        $readback = get_option( self::key( $hash, $at ), false );
        if ( ! is_array( $readback ) || ! isset( $readback['scope_sha256'] )
            || ! hash_equals( $hash, (string) $readback['scope_sha256'] ) )
            return self::error( 'mad4b_retry_write_readback_failed', 'Reservation cannot be independently verified; write is blocked.' );
        return array( 'attempt' => $at, 'scope_sha256' => $hash,
            'reserved_at' => $value['reserved_at'], 'persisted' => true,
            'authorizes_context_write' => false );
    }

    public static function finish( $reservation, $outcome ) {
        if ( ! is_array( $reservation ) || ! in_array( $outcome, array( 'failed', 'succeeded', 'uncertain' ), true ) )
            return self::error( 'mad4b_retry_outcome_invalid', 'Retry completion requires exact reservation and outcome.' );
        $hash = isset( $reservation['scope_sha256'] ) ? $reservation['scope_sha256'] : '';
        $at = isset( $reservation['attempt'] ) ? (int) $reservation['attempt'] : 0;
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || $at < 1 || $at > self::LIMIT )
            return self::error( 'mad4b_retry_reservation_invalid', 'Retry reservation is invalid.' );
        $key = self::key( $hash, $at );
        $prior = get_option( $key, false );
        if ( ! is_array( $prior ) || ! isset( $prior['scope_sha256'], $prior['outcome'] )
            || ! hash_equals( $hash, (string) $prior['scope_sha256'] ) || 'pending' !== $prior['outcome'] )
            return self::error( 'mad4b_retry_reservation_drift', 'Retry record is no longer pending.' );
        $prior['outcome'] = $outcome;
        $prior['finished_at'] = gmdate( 'c' );
        if ( ! update_option( $key, $prior, false ) ) return self::error( 'mad4b_retry_finish_failed', 'Retry completion could not persist.' );
        $read = get_option( $key, false );
        if ( ! is_array( $read ) || ! isset( $read['outcome'] ) || $read['outcome'] !== $outcome )
            return self::error( 'mad4b_retry_finish_readback_failed', 'Retry outcome readback failed.' );
        return true;
    }

    /** Human recovery requires exact journal hash; no background auto-reset. */
    public static function reset( $lane, $input, $expected_journal_sha256, $confirmed = false ) {
        if ( ! $confirmed || ! current_user_can( 'manage_options' ) )
            return self::error( 'mad4b_retry_reset_authority_required', 'Confirmed administrator approval is required to reset retries.' );
        $status = self::status( $lane, $input );
        if ( is_wp_error( $status ) ) return $status;
        if ( 'pending' === $status['last_outcome'] )
            return self::error( 'mad4b_retry_reset_in_flight', 'Do not reset while the remote write outcome is unknown; complete independent reconciliation first.' );
        if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $expected_journal_sha256 ) ||
            ! hash_equals( $status['journal_sha256'], (string) $expected_journal_sha256 ) )
            return self::error( 'mad4b_retry_reset_stale', 'Retry journal changed; refresh exact state before reset.' );
        $lock = self::PREFIX . 'reset_' . $status['scope_sha256'];
        if ( ! add_option( $lock, gmdate( 'c' ), '', false ) )
            return self::error( 'mad4b_retry_reset_busy', 'A retry reset is already running.' );
        try {
            $fresh = self::status( $lane, $input );
            if ( is_wp_error( $fresh ) || ! hash_equals( $status['journal_sha256'], $fresh['journal_sha256'] ) )
                return self::error( 'mad4b_retry_reset_raced', 'Retry journal changed before reset.' );
            for ( $i = 1; $i <= self::LIMIT; ++$i ) {
                if ( false !== get_option( self::key( $status['scope_sha256'], $i ), false )
                    && ! delete_option( self::key( $status['scope_sha256'], $i ) ) )
                    return self::error( 'mad4b_retry_reset_failed', 'Retry slot could not be removed; reconcile manually.' );
            }
            $read = self::status( $lane, $input );
            if ( is_wp_error( $read ) || 0 !== $read['attempts_reserved'] )
                return self::error( 'mad4b_retry_reset_readback_failed', 'Retry reset did not pass readback.' );
            return array( 'contract' => self::CONTRACT, 'reset' => true,
                'scope_sha256' => $status['scope_sha256'], 'operator_confirmation' => true,
                'mutation_performed' => true, 'automatic_retry_authorized' => false );
        } finally {
            delete_option( $lock );
        }
    }
}
