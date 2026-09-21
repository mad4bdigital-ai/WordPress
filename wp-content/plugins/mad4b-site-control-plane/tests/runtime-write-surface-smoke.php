<?php
/** Runtime proof that mad4b-write is a governed server, not a grant alias. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$check( current_user_can( 'manage_options' ), 'Write-surface smoke requires an administrator.' );
$check( class_exists( 'MAD4B_SCP_Transport_Context' ), 'Transport context class unavailable.' );
$check( in_array( 'mad4b-write', MAD4B_SCP_Servers::expected_server_ids(), true ), 'mad4b-write is not a governed server.' );
$registration = MAD4B_SCP_Servers::registration_status();
$check( ! empty( $registration['mad4b-write']['registered'] ), 'mad4b-write failed to register.' );

$write_tools = MAD4B_SCP_Servers::write_tools();
$check( is_array( $write_tools ) && count( $write_tools ) > 0, 'mad4b-write has no projected write tools.' );
$check( in_array( 'mad4b/content-update-post', $write_tools, true ), 'Reversible content mutation is missing from mad4b-write.' );
$check( in_array( 'mad4b/database-update', $write_tools, true ), 'Structured DB mutation is missing from mad4b-write.' );
$check( in_array( 'mad4b/mutation-undo', $write_tools, true ), 'Governed undo is missing from mad4b-write.' );
$check( ! in_array( 'mad4b/content-get-post', $write_tools, true ), 'Read-only content lookup leaked into mad4b-write.' );
$check( ! in_array( 'mad4b/audit-tail', $write_tools, true ), 'Read-only audit lookup leaked into mad4b-write.' );
$check( ! in_array( 'mad4b/agent-list', $write_tools, true ), 'Read-only governance listing leaked into mad4b-write.' );

foreach ( $write_tools as $ability_name ) {
    $ability = wp_get_ability( $ability_name );
    $check( is_object( $ability ) && method_exists( $ability, 'get_meta' ), 'Projected write ability metadata is unavailable: ' . $ability_name );
    $meta = $ability->get_meta();
    $annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
    $check( array_key_exists( 'readonly', $annotations ) && false === $annotations['readonly'], 'mad4b-write projected an ability without explicit readonly=false: ' . $ability_name );
}

$write_request = new WP_REST_Request( 'POST', '/mcp/mad4b-write' );
$bound = MAD4B_SCP_Servers::can_write_transport( $write_request );
$check( true === $bound, 'Administrator could not bind the mad4b-write transport.' );
$check( 'mad4b-write' === MAD4B_SCP_Transport_Context::current_server_id(), 'Write transport did not bind exact server identity.' );
$resolved = MAD4B_SCP_Transport_Context::resolve_server_for_ability( 'mad4b-content', 'mad4b/content-update-post' );
$check( 'mad4b-write' === $resolved, 'Active write transport did not override the ability-declared content server.' );

MAD4B_SCP_Transport_Context::clear();
$mismatch = MAD4B_SCP_Servers::can_write_transport( new WP_REST_Request( 'POST', '/mcp/mad4b-content' ) );
$check( is_wp_error( $mismatch ) && 'mad4b_transport_route_mismatch' === $mismatch->get_error_code(), 'Write transport accepted a mismatched REST route.' );
$check( '' === MAD4B_SCP_Transport_Context::current_server_id(), 'Route mismatch left stale transport authority bound.' );

$subject_type = 'ci-write-surface';
$subject_identifier = 'ci-write-surface-subject';
$fingerprint = hash( 'sha256', $subject_type . "\0" . $subject_identifier );
$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
$agent = MAD4B_SCP_Agent_Registry::create_agent(
    array(
        'slug' => 'ci-write-surface-agent',
        'label' => 'CI Write Surface Agent',
        'status' => 'enabled',
        'environment' => $environment,
        'wp_user_id' => get_current_user_id(),
    )
);
$check( is_array( $agent ) && ! empty( $agent['public_id'] ), 'Unable to create disposable write-surface NHI.' );
$check( true === MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], $subject_type, $fingerprint, 'CI write transport' ), 'Unable to bind disposable write-surface subject.' );
$check(
    true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-content', 'mad4b/content-update-post', 'core', array(), 'allow', 'all' ),
    'Unable to create the source content-server grant.'
);

add_filter(
    'mad4b_scp_authenticated_subject_context',
    static function ( $context ) use ( $subject_type, $subject_identifier ) {
        return array(
            'authenticated' => true,
            'subject_type' => $subject_type,
            'subject_identifier' => $subject_identifier,
            'token_scopes' => array(),
            'approval_ticket_id' => '',
            'auth_method' => 'ci',
            'wp_user_id' => get_current_user_id(),
            'origin' => 'ci',
        );
    },
    999
);
if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
$check( MAD4B_SCP_Policy::can_mutate(), 'Disposable NHI did not satisfy the global mutation/NHI gate.' );

$check( true === MAD4B_SCP_Servers::can_write_transport( $write_request ), 'Could not rebind mad4b-write for exact-grant isolation proof.' );
$denied = MAD4B_SCP_Authorization::authorize_mutation(
    'mad4b/content-update-post',
    'mad4b-content',
    'core',
    array( 'post_id' => 1, 'expected_modified_gmt' => 'ci' )
);
$check( is_wp_error( $denied ) && 'mad4b_nhi_grant_missing' === $denied->get_error_code(), 'A mad4b-content grant incorrectly authorized the same ability through mad4b-write.' );

$check(
    true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', 'mad4b/content-update-post', 'core', array(), 'allow', 'all' ),
    'Unable to create exact mad4b-write grant for a mounted core write ability.'
);
$write_grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', 'mad4b/content-update-post', 'core' );
$check( is_array( $write_grant ) && 'allow' === $write_grant['effect'], 'Exact mad4b-write grant is not independently resolvable.' );
$content_grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-content', 'mad4b/content-update-post', 'core' );
$check( is_array( $content_grant ) && (int) $content_grant['id'] !== (int) $write_grant['id'], 'Write and content grants collapsed into one authority record.' );

MAD4B_SCP_Transport_Context::clear();
echo "mad4b.site-control-plane.runtime-write-surface.v1: PASS\n";
