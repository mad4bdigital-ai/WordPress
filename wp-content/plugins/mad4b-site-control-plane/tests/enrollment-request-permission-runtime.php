<?php
/* Read-only native PHP 7.4+ permission projection regression; no WordPress runtime. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-enrollment-dispatch.php';
function ensure_permission( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
$cls = 'MAD4B_SCP_Enrollment_Dispatch';
$ok = $cls::managed_skills_permission_model( 'mad4b-chatgpt', true, true );
ensure_permission( 'request_permission_observed' === $ok['state'], 'Same request permission observation.' );
ensure_permission( ! $ok['execution_authorized'] && ! $ok['authority_granted']
	&& ! $ok['execution_performed'] && ! $ok['mutation_performed']
	&& ! $ok['production_mutation_allowed'], 'Observed permission never creates authority or execution.' );
$scope = $cls::managed_skills_permission_model( 'mad4b-chatgpt', true, false, 'mad4b_remote_operation_step_up_scope_required' );
ensure_permission( 'request_permission_blocked' === $scope['state'] && 'reauthorize_chatgpt_authority_step_up_scope' === $scope['next_safe_action'], 'Scope must be diagnosed precisely.' );
$client = $cls::managed_skills_permission_model( 'mad4b-chatgpt', true, false, 'mad4b_remote_operation_chatgpt_client_required' );
ensure_permission( 'review_exact_chatgpt_cimd_oauth_client_attribution' === $client['next_safe_action'], 'Client attribution must be separate from scope.' );
$enrollment = $cls::managed_skills_permission_model( 'mad4b-chatgpt', true, false, 'mad4b_remote_operation_subject_not_enrolled' );
ensure_permission( 'review_site_profile_subject_enrollment' === $enrollment['next_safe_action'], 'Subject enrollment is not an OAuth scope problem.' );
$missing = $cls::managed_skills_permission_model( '', true, true );
ensure_permission( 'not_evaluated' === $missing['state'] && ! $missing['permission_observed'], 'Absent transport must fail closed.' );
$wrong = $cls::managed_skills_permission_model( 'mad4b-enrollment', true, true );
ensure_permission( 'not_evaluated' === $wrong['state'] && ! $wrong['permission_observed'], 'Non-ChatGPT transport cannot certify direct step-up.' );
$unknown = $cls::managed_skills_permission_model( 'mad4b-chatgpt', true, false, '<Unsafe Secret 123>' );
ensure_permission( 'request_permission_denied_unclassified' === $unknown['blocker_code'], 'Unknown errors cannot echo third-party secrets.' );
ensure_permission( ! $unknown['blind_retry_allowed'] && $unknown['same_request_only'], 'No blind retry and no reuse as future authority.' );
echo "PASS: request-local Skills permission model 9 native assertions\n";
