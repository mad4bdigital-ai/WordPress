<?php
/** Hermetic test of risk classification; never boots or mutates WordPress. */
define( 'ABSPATH', '/tmp/' );
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $hook, $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function get_post_status( $id ) { return array( 101 => 'draft', 102 => 'publish', 103 => 'pending', 104 => 'private' )[(int) $id] ?? false; }
class MAD4B_SCP_Site_Profile { public static function current_environment() { return 'production'; } }
class MAD4B_SCP_Content_Experience_Profiles {
    public static function profile_for_ability( $name ) {
        $routes = array(
            'create_apply' => 'mad4b/tours-and-activities-r1-create-apply',
            'update_apply' => 'mad4b/tours-and-activities-r1-update-apply',
            'publish_apply' => 'mad4b/tours-and-activities-r1-publish-apply',
        );
        return in_array( $name, $routes, true ) ? array( 'enabled' => true, 'routes' => $routes ) : new WP_Error();
    }
}
require __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-impact-policy.php';
$cases = array(
    array( 'mad4b/tours-and-activities-r1-create-apply', array( 'post_status' => 'draft' ), 'low', 'content_change', false ),
    array( 'mad4b/tours-and-activities-r1-update-apply', array( 'post_id' => 101 ), 'low', 'content_change', false ),
    array( 'mad4b/tours-and-activities-r1-update-apply', array( 'post_id' => 103 ), 'low', 'content_change', false ),
    array( 'mad4b/tours-and-activities-r1-update-apply', array( 'post_id' => 102 ), 'high', 'content_change', true ),
    array( 'mad4b/tours-and-activities-r1-update-apply', array( 'post_id' => 104 ), 'high', 'content_change', true ),
    array( 'mad4b/tours-and-activities-r1-update-apply', array(), 'high', 'content_change', true ),
    array( 'mad4b/tours-and-activities-r1-publish-apply', array(), 'high', 'content_publish', true ),
    array( 'mad4b/content-set-meta', array( 'meta_key' => 'base_price' ), 'high', 'governed_mutation', true ),
    array( 'mad4b/content-set-meta', array( 'meta_key' => 'tour_sub_title' ), 'low', 'governed_mutation', false ),
    array( 'mad4b/agent-permission-apply', array(), 'high', 'governed_mutation', true ),
    array( 'mad4b/plugin-package-apply', array(), 'high', 'certified_package', true ),
    array( 'mad4b/database-raw-query', array(), 'exceptional', 'exceptional', true ),
);
foreach ( $cases as $index => $case ) {
    list( $ability, $input, $expected_impact, $expected_type, $expected_approval ) = $case;
    $result = MAD4B_SCP_Impact_Policy::classify( $ability, 'core', $input );
    if ( $result['impact'] !== $expected_impact || $result['operation_type'] !== $expected_type
        || $result['approval_required'] !== $expected_approval || true === ( $result['production_auto_approval'] ?? false ) ) {
        fwrite( STDERR, 'FAIL case ' . $index . ': ' . $ability . ' actual=' . json_encode( $result ) . "\n" );
        exit( 1 );
    }
}
echo 'PASS: ' . count( $cases ) . ' deterministic impact scenarios, including draft, publish, commercial metadata and system changes' . "\n";
