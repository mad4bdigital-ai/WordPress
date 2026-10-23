<?php
/**
 * Synthetic staging RSA acceptance fixture. Not a production Operator session.
 * Never writes to WordPress DB or invokes a shell. Requires RSA private key path
 * supplied out-of-band on the enrolled Staging host.
 */
define( 'ABSPATH', '/home/u948953904/domains/allroyalegypt.com/public_html/staging/' );
define( 'MAD4B_OPERATOR_SIGNING_KEY_PATH',
    '/home/u948953904/.mad4b-release-validation/are-staging-remediation-20261023/operator-asymmetric/private.pem' );
class WP_Error {
    public function __construct( public $code ) {}
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_get_environment_type() { return 'staging'; }
function home_url() { return 'https://staging.allroyalegypt.com'; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
class MAD4B_SCP_Operator_Bridge {
    public static function request_authority_matches( $op, $args ) {
        return 'operator.sql.read' === $op && 'sql.read' === ( $args['operation'] ?? '' );
    }
}
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-attestation.php';
function ordered( $v ) {
    if ( ! is_array( $v ) ) return $v;
    $is_list = empty( $v ) || array_keys( $v ) === range( 0, count( $v ) - 1 );
    if ( ! $is_list ) ksort( $v, SORT_STRING );
    foreach ( $v as $key => $val ) $v[$key] = ordered( $val );
    return $v;
}
function digest( $v ) {
    foreach ( array( 'plan_sha256', 'repair_plan_sha256', 'submission_sha256',
                     'authorizing', 'mutation_performed', 'queued', 'replayed' ) as $k ) unset( $v[$k] );
    return hash( 'sha256', json_encode( ordered( $v ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
}
$now = time();
$fp = str_repeat( 'a', 64 );
$session_id = '88888888-8888-4888-8888-888888888888';
$payload = array( 'sql' => 'SELECT 1' );
$args = array(
    'session_id' => $session_id,
    'session_expires_at_epoch' => $now + 600,
    'payload_sha256' => hash( 'sha256', json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
    'operation' => 'sql.read',
    'payload' => $payload,
    'risk' => 'read_only_db_role_required',
    'site_uuid' => '88888888-8888-4888-8888-888888888881',
    'client_fingerprint' => str_repeat( 'b', 64 ),
);
$plan = array(
    'contract' => 'mad4b.host-operation-plan.v1',
    'operation_id' => 'operator.sql.read',
    'operation_version' => 1,
    'risk' => 'read_only',
    'approval_required' => false,
    'runner_profile_id' => 'are-staging-unified-v1',
    'target' => array( 'target_fingerprint' => $fp, 'environment' => 'staging' ),
    'arguments' => $args,
    'submission_location' => 'wordpress_request',
    'execution_location' => 'host_runner',
    'commit_location' => 'host_runner',
    'production_authorized' => false,
    'created_at' => gmdate( 'c' ),
);
$plan['plan_sha256'] = digest( $plan );
$submission = array(
    'contract' => 'mad4b.host-bridge-submission.v1',
    'job_id' => '77777777-7777-4777-8777-777777777777',
    'idempotency_key' => 'staging-synthetic-rsa-20261023',
    'plan' => $plan,
    'plan_sha256' => $plan['plan_sha256'],
    'approval_ref' => '',
    'authority' => array( 'policy_decision_sha256' => '', 'agent_public_id' => '', 'approval_ticket_id' => '' ),
    'submission_location' => 'wordpress_request',
    'execution_location' => 'host_runner',
    'commit_location' => 'host_runner',
    'created_at' => $plan['created_at'],
    'production_authorized' => false,
);
$submission['submission_sha256'] = digest( $submission );
$seal = MAD4B_SCP_Operator_Attestation::seal( $submission );
if ( is_wp_error( $seal ) ) {
    fwrite( STDERR, 'SIGNATURE_FIXTURE_FAILED:' . $seal->get_error_code() . PHP_EOL );
    exit( 5 );
}
echo json_encode( array( 'submission' => $submission, 'seal' => $seal ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), PHP_EOL;
