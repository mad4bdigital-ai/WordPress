<?php

define( 'ABSPATH', '/srv/wordpress/' );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function admin_url( $path = '' ) { return 'https://staging.client.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function add_action( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) {}

class MAD4B_SCP_Adapter_Base {
    protected function schema( $properties, $required = array() ) { return array( 'type' => 'object', 'properties' => $properties, 'required' => $required ); }
    protected function add_ability() {}
}

class MAD4B_SCP_Approval_Tickets {
    const CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v2';
    public static $ticket = array();
    public static $binding = array();
    public static function get( $ticket_id ) { return self::$ticket; }
    public static function candidate_binding( $ticket_id ) { return self::$binding; }
}

class MAD4B_SCP_Approval_Decision_Admin {
    const PAGE_SLUG = 'mad4b-approval-decisions';
    public static $candidate = array();
    public static function current_candidate() { return self::$candidate; }
}

class MAD4B_SCP_Servers {
    public static function provider_for_ability( $server, $ability ) {
        return 'mad4b-write' === $server && 'elementor/update-widget-settings' === $ability ? 'elementor' : null;
    }
}

class MAD4B_SCP_Staging_Write_Authority {
    public static $effective = true;
    public static function effective() { return self::$effective; }
}

require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-approval-handoff-adapter.php';

function mad4b_handoff_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$ticket_id = '11111111-1111-4111-8111-111111111111';
$payload = str_repeat( 'a', 64 );
$sha = str_repeat( 'b', 40 );
$build = str_repeat( 'c', 64 );
$site_uuid = '22222222-2222-4222-8222-222222222222';
$profile_digest = str_repeat( 'd', 64 );

MAD4B_SCP_Approval_Tickets::$ticket = array(
    'ticket_id' => $ticket_id,
    'status' => 'pending',
    'ticket_class' => 'mutation',
    'server_id' => 'mad4b-write',
    'ability_name' => 'elementor/update-widget-settings',
    'provider' => 'elementor',
    'payload_sha256' => $payload,
    'target_fingerprint' => str_repeat( 'e', 64 ),
    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
);

MAD4B_SCP_Approval_Decision_Admin::$candidate = array(
    'ready' => true,
    'source_commit_sha' => $sha,
    'build_fingerprint' => $build,
    'site_uuid' => $site_uuid,
    'site_profile_revision' => 7,
    'site_profile_digest' => $profile_digest,
    'environment' => 'staging',
    'host' => 'staging.client.test',
);

$exact_binding = array(
    'contract' => MAD4B_SCP_Approval_Tickets::CANDIDATE_BINDING_CONTRACT,
    'ticket_id' => $ticket_id,
    'payload_sha256' => $payload,
    'candidate_sha' => $sha,
    'build_fingerprint' => $build,
    'site_uuid' => $site_uuid,
    'profile_revision' => 7,
    'profile_digest' => $profile_digest,
    'environment' => 'staging',
    'host' => 'staging.client.test',
);
MAD4B_SCP_Approval_Tickets::$binding = $exact_binding;

$adapter = new MAD4B_SCP_Approval_Handoff_Adapter();
$result = $adapter->handoff( array( 'ticket_id' => $ticket_id ) );
mad4b_handoff_assert( is_array( $result ), 'Exact v2 handoff must return a read-only result.' );
mad4b_handoff_assert( ! empty( $result['candidate_binding_exact'] ), 'Exact v2 candidate binding must be accepted.' );
mad4b_handoff_assert( ! empty( $result['can_decide_in_admin'] ), 'Exact v2 binding must enable the human decision surface.' );
mad4b_handoff_assert( empty( $result['blockers'] ), 'Exact v2 handoff must have no blockers.' );
mad4b_handoff_assert( ! empty( $result['read_only'] ) && empty( $result['decision_exposed'] ) && empty( $result['target_execution_exposed'] ), 'Handoff must remain read-only.' );

$legacy = $exact_binding;
$legacy['contract'] = 'mad4b.approval-candidate-binding.v1';
MAD4B_SCP_Approval_Tickets::$binding = $legacy;
$result = $adapter->handoff( array( 'ticket_id' => $ticket_id ) );
mad4b_handoff_assert( empty( $result['candidate_binding_exact'] ), 'Legacy v1 candidate binding must fail closed.' );
mad4b_handoff_assert( in_array( 'candidate_binding_missing_or_stale', $result['blockers'], true ), 'Legacy binding must surface the candidate binding blocker.' );

$stale = $exact_binding;
$stale['profile_revision'] = 8;
MAD4B_SCP_Approval_Tickets::$binding = $stale;
$result = $adapter->handoff( array( 'ticket_id' => $ticket_id ) );
mad4b_handoff_assert( empty( $result['candidate_binding_exact'] ), 'Stale Site Profile revision must fail closed.' );
mad4b_handoff_assert( empty( $result['can_decide_in_admin'] ), 'Stale binding must not enable the human decision surface.' );

$stale = $exact_binding;
$stale['build_fingerprint'] = str_repeat( 'f', 64 );
MAD4B_SCP_Approval_Tickets::$binding = $stale;
$result = $adapter->handoff( array( 'ticket_id' => $ticket_id ) );
mad4b_handoff_assert( empty( $result['candidate_binding_exact'] ), 'Stale build fingerprint must fail closed.' );

echo "mad4b.approval-handoff.runtime.v1: PASS\n";
