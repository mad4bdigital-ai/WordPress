<?php
/** Feature007 remote owner Context review routing; PHP 7.4+. No WP boot or writes. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_current_user_id() { return 0; }
function current_user_can( $cap ) { return false; }
class MAD4B_SCP_Identity_Context {
    public static function current() {
        return array( 'authenticated' => true, 'auth_method' => 'oauth2_bearer',
            'wp_user_id' => 0, 'subject_fingerprint' => str_repeat( '1', 64 ) );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';
function remote_context_expect( $ok, $reason ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
$bad = MAD4B_SCP_Context_Authority::remote_owner_review_plan( array( 'asset_id' => 'not-an-id' ) );
remote_context_expect( is_wp_error( $bad ) && 'mad4b_remote_context_review_asset_id_invalid' === $bad->get_error_code(), 'Untrusted asset IDs must fail before site lookup' );
$denied = MAD4B_SCP_Context_Authority::remote_owner_review_apply( array(
    'asset_id' => str_repeat( 'a', 64 ), 'expected_plan_sha256' => str_repeat( 'b', 64 ),
    'decision' => 'approve', 'confirmation' => 'I REVIEWED EXACT BRAND SOURCE',
) );
remote_context_expect( is_wp_error( $denied ) && 'mad4b_remote_context_review_owner_oauth_required' === $denied->get_error_code(), 'AI-only bearer is never treated as a WordPress owner' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php' );
$adapter = file_get_contents( dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-context-adapter.php' );
$loop = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-core-control-loop.php' );
remote_context_expect( is_string( $source ) && is_string( $adapter ) && is_string( $loop ), 'All remote context source files must be available' );
foreach ( array(
    'remote_owner_review_plan(', 'remote_owner_review_apply(', 'get_current_user_id()', 'current_user_can( \'manage_options\' )',
    "empty( \$identity['approval_ticket_id'] )", 'review_asset_with_actor(', 'reviewed_content_hash',
) as $needle ) {
    remote_context_expect( false !== strpos( $source, $needle ), 'Remote owner cannot bypass exact verified review: ' . $needle );
}
remote_context_expect(
    false !== strpos( $adapter, "'context/remote-owner-review-plan'" )
    && false !== strpos( $adapter, "'context/remote-owner-review-apply'" )
    && false !== strpos( $adapter, "'remote_owner_review_plan'," )
    && false !== strpos( $adapter, "'remote_owner_review_apply'," )
    && false !== strpos( $loop, "'exact_asset_plan_ability' => 'context/remote-owner-review-plan'" ),
    'Both MCP abilities must be registered and discoverable from Brand Core recovery'
);
echo "PASS: remote owner Context review is governed, OAuth-bound, and rejects AI-only identity\n";
