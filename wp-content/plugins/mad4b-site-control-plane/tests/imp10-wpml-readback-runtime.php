<?php
/** IMP10 isolated native WPML filter simulation; zero WordPress writes. */
define( 'ABSPATH', '/' );
define( 'ICL_SITEPRESS_VERSION', 'test-provider-detected' );
class WP_Error {
    private $code;
    function __construct( $code, $text = '' ) { $this->code = $code; }
    function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_user_can( $name ) { return true; }
function ck( $condition, $description ) {
    if ( !$condition ) throw new RuntimeException( $description );
}
function has_filter( $name ) {
    return in_array( $name, array( 'wpml_element_trid',
        'wpml_element_language_details',
        'wpml_get_element_translations' ), true );
}
function get_posts( $args ) {
    if ( $args['post_type'] !== 'tour_rate' ||
        $args['numberposts'] !== 2 ||
        $args['meta_key'] !== 'external_source_identity' ||
        $args['suppress_filters'] !== true )
        throw new RuntimeException( 'Unscoped WPML database identity query' );
    if ( (string) $args['meta_value'] === 'A-EN' ) return array( 101 );
    if ( (string) $args['meta_value'] === 'A-FR' ) return array( 102 );
    return array();
}
function get_post_meta( $id, $key, $one = true ) {
    if ( $key !== 'external_source_identity' ) throw new RuntimeException( 'Meta read not scoped' );
    return $id === 101 ? 'A-EN' : 'A-FR';
}
function apply_filters( $name, $value, $arg1 = null, $arg2 = null ) {
    if ( $name === 'wpml_element_trid' )
        return ! empty( $GLOBALS['imp10_mismatched_trid'] ) && $arg1 === 102 ? 502 : 501;
    if ( $name === 'wpml_element_language_details' )
        return (object) array(
            'trid' => ! empty( $GLOBALS['imp10_mismatched_trid'] ) &&
                $arg1['element_id'] === 102 ? 502 : 501,
            'language_code' => $arg1['element_id'] === 101 ? 'en' : 'fr' );
    if ( $name === 'wpml_get_element_translations' )
        return array( 'en' => (object) array( 'element_id' => 101 ),
            'fr' => (object) array( 'element_id' => 102 ) );
    return $value;
}
class MAD4B_SCP_Activity_Import_Snapshot {
    static function raw_snapshot( $slug, $sha ) {
        return array(
            'receipt' => array( 'plan' => array(
                'plan_sha256' => str_repeat( 'b', 64 ),
                'profile_revision' => 5,
                'authority_sha256' => str_repeat( 'a', 64 ) ) ),
            'input' => array(
                'headers' => array( 'source_id',
                    '_wpml_import_translation_group',
                    '_wpml_import_language_code' ),
                'rows' => array(
                    array( 'source_id' => 'A-EN',
                        '_wpml_import_translation_group' => 'external-123',
                        '_wpml_import_language_code' => 'en' ),
                    array( 'source_id' => 'A-FR',
                        '_wpml_import_translation_group' => 'external-123',
                        '_wpml_import_language_code' => 'fr' ) )
            ) );
    }
}
class MAD4B_SCP_Content_Experience_Profiles {
    static function profile( $slug ) {
        return array( 'enabled' => true, 'post_type' => 'tour_rate',
            'revision' => 5, 'authority_sha256' => str_repeat( 'a', 64 ),
            'meta_keys' => array( 'external_source_identity' ) );
    }
}
class MAD4B_SCP_Activity_Import_Authority {
    static function profile_contract( $profile ) {
        return array( 'validation' => array(
            'identity_field' => 'source_id',
            'destination_identity_meta_key' => 'external_source_identity',
            'require_complete_wpml_groups' => true,
            'wpml_languages' => array( 'en', 'fr' ) ) );
    }
}
class MAD4B_SCP_Activity_Import_Review {
    static function plan( $data ) {
        return array( 'plan_sha256' => str_repeat( 'b', 64 ) );
    }
}
require __DIR__ . '/../includes/class-mad4b-scp-import-wpml-readback.php';
$input = array( 'profile_slug' => 'rates',
    'snapshot_sha256' => str_repeat( 'c', 64 ), 'group_index' => 0 );
$pass = MAD4B_SCP_Import_WPML_Readback::plan( $input );
ck( !is_wp_error( $pass ) &&
    $pass['independent_wpml_link_audit_passed_for_this_group'] &&
    $pass['resolved_posts'] === 2 && $pass['all_groups_audited'] &&
    !$pass['source_provenance_verified'] &&
    !$pass['production_promotion_authorized'],
    'Exact source links not independently verified or scope overstated' );
$GLOBALS['imp10_mismatched_trid'] = true;
$split = MAD4B_SCP_Import_WPML_Readback::plan( $input );
ck( !is_wp_error( $split ) &&
    !$split['independent_wpml_link_audit_passed_for_this_group'] &&
    in_array( 'source_translations_not_in_same_trid', $split['issues'], true ),
    'Different WPML internal translation groups were wrongly certified' );
$invalid = $input;
$invalid['group_index'] = 9999;
ck( is_wp_error( MAD4B_SCP_Import_WPML_Readback::plan( $invalid ) ),
    'Unbounded group readback accepted' );
echo "PASS IMP10 exact external source identity/real WPML trid, incorrect links refusal and no provider authority (ISOLATED PHP)\n";
