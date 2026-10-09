<?php
/** IMP01 isolated PHP contract. Does not write WordPress posts or call Google. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    function __construct( $code, $message = '' ) { $this->code = $code; }
    function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_user_can( $cap ) { return true; }
function wp_json_encode( $v, $opts = 0 ) { return json_encode( $v, $opts ); }
class MAD4B_SCP_Site_Profile {
    static function configured() { return true; }
    static function origin_enrolled() { return true; }
    static function site_urls_match_enrollment() { return true; }
    static function site_uuid() { return '10000000-2000-4000-8000-000000000000'; }
}
class MAD4B_SCP_Content_Experience_Profiles {
    static function profile( $slug ) {
        if ( 'pricing' !== $slug ) return new WP_Error( 'unknown_profile' );
        return array( 'enabled' => true, 'revision' => 5,
            'authority_sha256' => str_repeat( 'a', 64 ),
            'meta_keys' => array( 'base_currency', 'single_price', 'double_price' ),
            'activity_contract' => array( 'enabled' => true ) );
    }
}
class MAD4B_SCP_Context_Authority {
    static function brand_core_coverage() {
        return array( 'ready' => false,
            'missing_required_context_sets' => array( 'tone_of_voice', 'editorial_guidelines' ) );
    }
    static function review_queue() { return array( array( 'category' => 'tone_of_voice' ) ); }
}
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-review.php';
function ck( $condition, $description ) {
    if ( !$condition ) throw new RuntimeException( $description );
}
$input = array(
    'profile_slug' => 'pricing', 'identity_field' => 'ID',
    'headers' => array( 'ID', 'base_currency', 'single_price', 'double_price',
        '_wpml_import_translation_group', '_wpml_import_language_code',
        '_wpml_import_after_process_post_status' ),
    'field_mapping' => array( 'single_price' => 'single_price' ),
    'allowed_currencies' => array( 'USD', 'EUR' ),
    'rows' => array(
        array( 'ID' => 1, 'base_currency' => 'USD',
            'single_price' => 100, 'double_price' => 80,
            '_wpml_import_translation_group' => 1,
            '_wpml_import_language_code' => 'en',
            '_wpml_import_after_process_post_status' => 'draft' ),
        array( 'ID' => 2, 'base_currency' => 'ERU',
            'single_price' => 105, 'double_price' => 80,
            '_wpml_import_translation_group' => 1,
            '_wpml_import_language_code' => 'fr',
            '_wpml_import_after_process_post_status' => 'puplished' ),
    ),
);
$p = MAD4B_SCP_Activity_Import_Review::plan( $input );
ck( !is_wp_error( $p ) && $p['row_count'] === 2, 'Bounded preview failed' );
ck( $p['issue_count_observed'] === 2 &&
    $p['ready_for_import_execution'] === false &&
    $p['source_values_persisted'] === false, 'Currency/status issues were autoaccepted' );
$duplicated = $input;
$duplicated['rows'][1]['ID'] = 1;
$d = MAD4B_SCP_Activity_Import_Review::plan( $duplicated );
ck( !is_wp_error( $d ) && $d['issue_count_observed'] >= 3, 'Duplicate ID not denied by review' );
$wrong = $input;
$wrong['field_mapping']['single_price'] = 'unapproved_private_key';
$denied = MAD4B_SCP_Activity_Import_Review::plan( $wrong );
ck( is_wp_error( $denied ) &&
    'mad4b_import_field_not_allowed' === $denied->get_error_code(),
    'Profile meta allowlist bypassed' );
$overflow = $input;
$overflow['rows'] = array_fill( 0, 501, $input['rows'][0] );
$bounds = MAD4B_SCP_Activity_Import_Review::plan( $overflow );
ck( is_wp_error( $bounds ) &&
    'mad4b_import_bounds' === $bounds->get_error_code(), 'Oversized preview admitted' );
$brand = MAD4B_SCP_Activity_Import_Review::brand_core_plan();
ck( !is_wp_error( $brand ) && !$brand['ready'] &&
    !$brand['automated_approval_performed'], 'Brand Core autoapproved without authority' );
echo "PASS IMP01 bounded dynamic meta mapping, currency/status exceptions, duplicate IDs, preview limits and governed Brand Core review\n";
