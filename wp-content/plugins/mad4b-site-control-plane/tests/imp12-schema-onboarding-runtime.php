<?php
/** IMP12 no-Profile bootstrap: names only, never creates WordPress content. */
define( 'ABSPATH', '/' );
class WP_Error {
    private $code;
    function __construct( $code, $message = '' ) { $this->code = $code; }
    function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function ck( $v, $m ) { if ( !$v ) throw new RuntimeException( $m ); }
function current_user_can( $cap ) { return true; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
class MAD4B_SCP_Site_Profile {
    static function configured() { return true; }
    static function origin_enrolled() { return true; }
    static function site_urls_match_enrollment() { return true; }
    static function environment_allowed( $where ) {
        return in_array( 'staging', $where, true );
    }
}
function get_post_types( $args, $mode ) {
    return array(
        'post' => (object) array( 'label' => 'Posts', 'public' => true,
            'cap' => (object) array( 'edit_posts' => 'edit_posts' ) ),
        'tour_rate' => (object) array( 'label' => 'Rates', 'public' => false,
            'cap' => (object) array( 'edit_posts' => 'edit_posts' ) ),
        'attachment' => (object) array( 'label' => 'Attachments' ) );
}
function post_type_exists( $name ) { return $name === 'tour_rate'; }
function get_post_type_object( $name ) {
    return get_post_types( array(), 'objects' )[ $name ];
}
function get_registered_meta_keys( $kind, $subtype ) {
    if ( $kind !== 'post' || $subtype !== 'tour_rate' )
        throw new RuntimeException( 'Registry lookup escaped scoped CPT' );
    return array(
        'single_price' => array( 'show_in_rest' => true, 'type' => 'number' ),
        'source_record_key' => array( 'show_in_rest' => true, 'type' => 'string' ),
        'private_token' => array( 'show_in_rest' => false, 'type' => 'string' ),
        '_internal' => array( 'show_in_rest' => true, 'type' => 'string' )
    );
}
function is_protected_meta( $name, $kind ) { return $name[0] === '_'; }
function get_object_taxonomies( $kind, $mode ) {
    return array( 'region' => (object) array(
        'hierarchical' => true,
        'cap' => (object) array( 'assign_terms' => 'edit_posts' ) ) );
}
require __DIR__ . '/../includes/class-mad4b-scp-import-schema-onboarding.php';
$all = MAD4B_SCP_Import_Schema_Onboarding::plan( array() );
ck( !is_wp_error( $all ) && $all['mode'] === 'discover_post_types' &&
    $all['total_returned'] === 2 &&
    !$all['mutation_performed'], 'CPT bootstrap mutated data or exposed internal types' );
$one = MAD4B_SCP_Import_Schema_Onboarding::plan( array(
    'post_type' => 'tour_rate',
    'observed_headers' => array( 'sourceRecordKey', 'single_price' ) ) );
ck( !is_wp_error( $one ) &&
    count( $one['registered_rest_meta_candidates'] ) === 2 &&
    $one['excluded_meta_key_count'] === 2 &&
    count( $one['lexical_only_candidates'] ) === 2 &&
    !$one['field_mapping_approved'] &&
    !$one['wordPress_profile_created'],
    'Schema preflight approved unsafe fields or failed exact REST meta discovery' );
$invalid = MAD4B_SCP_Import_Schema_Onboarding::plan( array(
    'post_type' => 'tour_rate', 'observed_headers' => array(
        'single_price','single_price' ) ) );
ck( is_wp_error( $invalid ),
    'Duplicate source columns accepted in Profile bootstrap' );
$wrong = MAD4B_SCP_Import_Schema_Onboarding::plan( array(
    'post_type' => 'attachment' ) );
ck( is_wp_error( $wrong ), 'Internal WordPress post type was exposed' );
echo "PASS IMP12 safe zero-Profile CPT/REST meta/taxonomy discovery and lexical-only hints (ISOLATED PHP)\n";
