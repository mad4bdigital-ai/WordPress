<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Review mode for new ContentJobs. No change to WordPress/MCP/tenant authority.
 * This decides when QA evidence is sufficient for Hub staging, NOT publication.
 */
final class MAD4B_SCP_Content_Workflow_Modes {
    const DEFAULT_MODE = 'autonomous';
    const CONTRACT = 'mad4b.content-review-mode.v1';

    public static function choices() {
        return array( 'autonomous', 'assisted', 'strict' );
    }

    public static function requested( $value ) {
        $mode = sanitize_key( (string) $value );
        // Existing callers and historical rows used review_gated.
        if ( 'review_gated' === $mode ) $mode = 'strict';
        if ( ! in_array( $mode, self::choices(), true ) )
            return new WP_Error( 'mad4b_content_review_mode_invalid', 'Unknown content review mode.' );
        return $mode;
    }

    public static function for_job( $job_id ) {
        if ( ! class_exists( 'MAD4B_SCP_Content_Jobs' ) )
            return new WP_Error( 'mad4b_content_jobs_unavailable', 'ContentJob service unavailable.' );
        $result = MAD4B_SCP_Content_Jobs::get_job( array( 'job_id' => $job_id ) );
        if ( is_wp_error( $result ) ) return $result;
        $job = isset( $result['job'] ) && is_array( $result['job'] ) ? $result['job'] : array();
        // Never assign an autonomous mode to a legacy row whose mode is blank.
        return self::requested( (string) ( $job['automation_level'] ?? 'review_gated' ) );
    }

    public static function for_input( $input ) {
        $job_id = is_array( $input ) ? trim( (string) ( $input['job_id'] ?? '' ) ) : '';
        return '' !== $job_id ? self::for_job( $job_id ) : self::DEFAULT_MODE;
    }

    public static function review_result( $mode, $hard_blockers, $qa_completed = false ) {
        $mode = self::requested( $mode );
        if ( is_wp_error( $mode ) ) return $mode;
        $blocked = ! empty( $hard_blockers );
        $passed = $qa_completed && ! $blocked;
        $auto = 'autonomous' === $mode;
        $strict = 'strict' === $mode;
        $status = $blocked ? 'QA_BLOCKED'
            : ( ! $qa_completed ? 'QA_PENDING'
            : ( $auto ? 'SELF_REVIEW_PASSED'
            : ( $strict ? 'NEEDS_INDEPENDENT_REVIEW' : 'NEEDS_REVIEW' ) ) );
        return array(
            'review_mode' => $mode,
            'quality_evidence_status' => $status,
            'qa_completed' => (bool) $qa_completed,
            'qa_passed' => (bool) $passed,
            'writer_can_self_review' => ! $strict,
            'independent_review_required' => $strict,
            'human_review_required' => 'assisted' === $mode || $strict,
            'self_review_accepted' => $passed && $auto,
            'can_store_in_hub' => $passed && $auto,
            // Publication, provider authority and tenant distribution are separate.
            'can_publish' => false,
            'publication_authorized' => false,
        );
    }

    public static function catalog( $input = array() ) {
        $mode = self::for_input( $input );
        if ( is_wp_error( $mode ) ) return $mode;
        return array( 'contract' => self::CONTRACT, 'default' => self::DEFAULT_MODE,
            'selected' => $mode, 'choices' => self::choices(),
            'writer_can_self_review' => 'strict' !== $mode,
            'independent_review_required' => 'strict' === $mode,
            'publication_authorized' => false, 'mutation_performed' => false );
    }
}
