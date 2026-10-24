<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Post-update grant recovery coordinator. It does not mint an OAuth identity,
 * auto-enroll a new agent, bypass step-up, or accept client-supplied plans.
 * Reconciliation's existing audited rollback transaction remains the only
 * mutation path. Cron without live verified authority is explicitly DEFERRED.
 */
final class MAD4B_SCP_Post_Update_Approved_Grant_Recovery {
    const CONTRACT = 'mad4b.post-update-approved-grant-recovery.v1';
    public static function attempt() {
        $response = array(
            'contract' => self::CONTRACT,
            'state' => 'DEFER',
            'reason' => 'not_checked',
            'mutation_performed' => false,
            'production_allowed' => false,
        );
        if ( ! class_exists( 'MAD4B_SCP_Environment_Convergence_Policy' ) )
            require_once __DIR__ . '/class-mad4b-scp-environment-convergence-policy.php';
        $policy = MAD4B_SCP_Environment_Convergence_Policy::current();
        if ( empty( $policy['auto_convergence_eligible'] ) || 'staging' !== ( $policy['environment'] ?? '' ) ) {
            $response['state'] = 'BLOCKED';
            $response['reason'] = 'trusted_staging_profile_required';
            return $response;
        }
        if ( ! class_exists( 'MAD4B_SCP_Post_Update_Continuation' )
            || ! method_exists( 'MAD4B_SCP_Post_Update_Continuation', 'approved_grant_baseline' )
            || empty( MAD4B_SCP_Post_Update_Continuation::approved_grant_baseline() ) ) {
            $response['reason'] = 'sealed_previous_baseline_or_permit_required';
            return $response;
        }
        if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation' )
            || ! class_exists( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan' ) ) {
            $response['reason'] = 'grant_reconciliation_unavailable';
            return $response;
        }
        // A scheduled Cron never borrows the owner OAuth authority from an
        // earlier HTTP request. Defer and let a verified request resume.
        $access = MAD4B_SCP_Staging_Write_Grant_Reconciliation::can_execute();
        if ( is_wp_error( $access ) || ! $access ) {
            $response['reason'] = 'verified_oauth_step_up_required';
            return $response;
        }
        $plan = MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::plan();
        if ( is_wp_error( $plan ) || ! is_array( $plan ) || empty( $plan['eligible'] ) || ! empty( $plan['blockers'] )
            || empty( $plan['write_binding'] ) || ! is_array( $plan['write_binding'] ) ) {
            $response['state'] = 'BLOCKED';
            $response['reason'] = 'exact_plan_not_eligible';
            return $response;
        }
        $input = $plan['write_binding'];
        if ( ! empty( $input['expected_stale_grant_ids'] ) ) {
            $response['state'] = 'BLOCKED';
            $response['reason'] = 'stale_grants_require_review';
            return $response;
        }
        unset( $input['confirmation'] );
        $input['nonproduction_approved_auto'] = true;
        $result = MAD4B_SCP_Staging_Write_Grant_Reconciliation::reconcile( $input );
        if ( is_wp_error( $result ) ) {
            $response['state'] = 'BLOCKED';
            $response['reason'] = sanitize_key( (string) $result->get_error_code() );
            return $response;
        }
        $response['state'] = 'COMPLETED';
        $response['reason'] = 'verified_audited_reconciliation';
        $response['mutation_performed'] = ! empty( $result['mutation_performed'] );
        $response['result'] = array( 'candidate_rebound' => ! empty( $result['candidate_rebound_without_grant_changes'] ) );
        return $response;
    }
}
