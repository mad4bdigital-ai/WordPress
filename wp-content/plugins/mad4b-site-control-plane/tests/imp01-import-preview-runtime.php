<?php
/** IMP01 isolated PHP contract. Does not write WordPress posts or call Google. */
define( 'ABSPATH', __DIR__ );
define( 'MAD4B_ACTIVITY_IMPORT_DATA_KEY', str_repeat( 'K', 48 ) );
define( 'MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS', array(
    'rates_appscript' => array( 'mode' => 'google_apps_script',
        'secret' => str_repeat( 'x', 48 ), 'enabled' => true,
        'site_uuid' => '10000000-2000-4000-8000-000000000000',
        'profile_slugs' => array( 'pricing' ) )
) );
$GLOBALS['imp01_options'] = array();
function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['imp01_options'] ) ? $GLOBALS['imp01_options'][ $key ] : $default;
}
function add_option( $key, $value, $ignored = '', $autoload = false ) {
    if ( array_key_exists( $key, $GLOBALS['imp01_options'] ) ) return false;
    $GLOBALS['imp01_options'][ $key ] = $value;
    return true;
}
function delete_option( $key ) { unset( $GLOBALS['imp01_options'][ $key ] ); return true; }
class IMP01_Test_Request {
    private $body; private $signature;
    function __construct( $body, $signature ) { $this->body = $body; $this->signature = $signature; }
    function get_body() { return $this->body; }
    function get_header( $key ) {
        return 'x-mad4b-signature' === $key ? $this->signature :
            ( 'x-mad4b-key-id' === $key ? 'rates_appscript' : '' );
    }
}

class WP_Error {
    private $code;
    function __construct( $code, $message = '' ) { $this->code = $code; }
    function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_user_can( $cap ) { return true; }
function get_current_user_id() { return 9; }
function apply_filters( $hook, $value ) {
    if ( 'mad4b_activity_import_mode_manifests' === $hook &&
        ! empty( $GLOBALS['imp02_override_builtin'] ) ) {
        $value[0]['detected'] = !$value[0]['detected'];
    }
    return $value;
}
function wp_json_encode( $v, $opts = 0 ) { return json_encode( $v, $opts ); }
class MAD4B_SCP_Site_Profile {
    static function configured() { return true; }
    static function origin_enrolled() { return true; }
    static function site_urls_match_enrollment() { return true; }
    static function site_uuid() { return '10000000-2000-4000-8000-000000000000'; }
    static function environment_allowed( $environments, $feature = '' ) { return in_array( 'staging', $environments, true ); }
}
class MAD4B_SCP_Content_Experience_Profiles {
    static function profile( $slug ) {
        if ( 'pricing' !== $slug ) return new WP_Error( 'unknown_profile' );
        return array( 'enabled' => true, 'revision' => 5,
            'authority_sha256' => str_repeat( 'a', 64 ),
            'meta_keys' => array( 'base_currency', 'single_price', 'double_price' ),
            'activity_contract' => array( 'enabled' => true ),
            'import_contract' => array( 'enabled' => true,
                'enabled_modes' => isset( $GLOBALS['imp02_enabled_modes'] ) ?
                    $GLOBALS['imp02_enabled_modes'] : array(),
                'preferred_mode' => 'admin_csv_upload',
                'fallback_modes' => array( 'signed_generic_webhook' ),
                'manual_review_required' => true, 'auto_execute' => false,
                'validation' => array(
                    'identity_field' => 'ID',
                    'required_columns' => array( 'ID', 'base_currency',
                        'single_price', 'double_price' ),
                    'allowed_currencies' => array( 'USD', 'EUR' ),
                    'field_mapping' => array( 'single_price' => 'single_price' ),
                    'price_tier_policy' => ! empty( $GLOBALS['imp03_price_rule'] ) ?
                        'review_monotonic' : 'none',
                    'review_past_intervals' => false,
                    'wpml_languages' => array(), 'require_complete_wpml_groups' => false,
                    'required_relationships' => array(), 'max_rows' => 500
                ) ) );
    }
}
class MAD4B_SCP_Context_Authority {
    static function brand_core_coverage() {
        return array( 'ready' => false,
            'missing_required_context_sets' => array( 'tone_of_voice', 'editorial_guidelines' ) );
    }
    static function review_queue() { return array( array( 'category' => 'tone_of_voice' ) ); }
}
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-authority.php';
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-snapshot.php';
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-review.php';
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-modes.php';
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
$commercial = $input;
$GLOBALS['imp03_price_rule'] = true;
unset( $commercial['price_tier_policy'] );
$commercial['rows'][0]['single_price'] = 60;
$commercialPreview = MAD4B_SCP_Activity_Import_Review::plan( $commercial );
ck( !is_wp_error( $commercialPreview ) &&
    isset( $commercialPreview['issue_counts_by_reason']['price_tier_order_requires_commercial_review'] ) &&
    $commercialPreview['issue_counts_by_reason']['price_tier_order_requires_commercial_review'] === 1,
    'Configured commercial price review policy was ignored' );
unset( $GLOBALS['imp03_price_rule'] );
$duplicated = $input;
$duplicated['rows'][1]['ID'] = 1;
$d = MAD4B_SCP_Activity_Import_Review::plan( $duplicated );
ck( !is_wp_error( $d ) && $d['issue_count_observed'] >= 3, 'Duplicate ID not denied by review' );
$wrong = $input;
$wrong['field_mapping']['single_price'] = 'unapproved_private_key';
$denied = MAD4B_SCP_Activity_Import_Review::plan( $wrong );
ck( is_wp_error( $denied ) &&
    'mad4b_import_source_cannot_override_policy' === $denied->get_error_code(),
    'Profile meta allowlist bypassed' );
$overflow = $input;
$overflow['rows'] = array_fill( 0, 501, $input['rows'][0] );
$bounds = MAD4B_SCP_Activity_Import_Review::plan( $overflow );
ck( is_wp_error( $bounds ) &&
    'mad4b_import_bounds' === $bounds->get_error_code(), 'Oversized preview admitted' );
$brand = MAD4B_SCP_Activity_Import_Review::brand_core_plan();
ck( !is_wp_error( $brand ) && !$brand['ready'] &&
    !$brand['automated_approval_performed'], 'Brand Core autoapproved without authority' );
$job=MAD4B_SCP_Activity_Import_Review::wp_all_import_plan( array(
    'profile_slug' => 'pricing', 'import_id' => 12,
    'unique_identifier' => 'ID', 'mode' => 'match_existing',
    'update_fields' => array( 'single_price' ),
    'provider_options' => array( 'wpml' => array( 'require_linking' => true ) ),
    'delete_missing' => true, 'publish_immediately' => true,
) );
ck( !is_wp_error( $job ) && !$job['ready_for_import_execution'] &&
    count( $job['blocked_effects'] ) === 2,
    'Dangerous import options were silently accepted for execution' );
$badJob=MAD4B_SCP_Activity_Import_Review::wp_all_import_plan( array(
    'profile_slug' => 'pricing', 'import_id' => 12,
    'unique_identifier' => 'ID', 'mode' => 'update_existing',
    'update_fields' => array( 'unknown_internal_field' )
) );
ck( is_wp_error( $badJob ) && $badJob->get_error_code() === 'mad4b_wpai_update_not_allowed',
    'WP All Import target field escaped profile allowlist' );

$packet = array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
    'source_mode' => 'google_apps_script',
    'issued_at' => time(), 'nonce' => 'signednonce_202610101234567',
    'input' => $input );
$raw = json_encode( $packet );
$sig = hash_hmac( 'sha256', $raw, MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS['rates_appscript']['secret'] );
$request = new IMP01_Test_Request( $raw, $sig );
$permission = MAD4B_SCP_Activity_Import_Review::authorize_signed( $request );
ck( true === $permission, 'Signed review intake signature unexpectedly refused' );
$accepted = MAD4B_SCP_Activity_Import_Review::receive_signed( $request );
ck( !is_wp_error( $accepted ) && $accepted['staged'] &&
    $accepted['post_writes'] === 0, 'Verified Apps Script inbox must stage review only' );
$review = MAD4B_SCP_Activity_Import_Review::review( array( 'profile_slug' => 'pricing' ) );
ck( $review['state'] === 'requires_review' && $review['plan']['issue_count_observed'] === 2,
    'Signed intake review was not persisted with exact redacted evidence' );
$replayed = MAD4B_SCP_Activity_Import_Review::receive_signed( $request );
ck( is_wp_error( $replayed ) && $replayed->get_error_code() === 'mad4b_import_webhook_replay',
    'Replayed webhook was admitted' );
$badSig = MAD4B_SCP_Activity_Import_Review::authorize_signed(
    new IMP01_Test_Request( $raw . ' ', $sig ) );
ck( is_wp_error( $badSig ) &&
    $badSig->get_error_code() === 'mad4b_import_webhook_signature',
    'Tampered body inherited trusted HMAC signature' );
echo "PASS IMP01 signed REST review intake, redacted persistence, replay and HMAC tamper denials\n";
$modes = MAD4B_SCP_Activity_Import_Modes::catalog();
ck( !is_wp_error( $modes ) && $modes['mode_count'] >= 20, 'Multi-mode registry did not load' );
$ids = array_column( $modes['modes'], 'id' );
foreach ( array( 'admin_csv_upload', 'google_apps_script', 'signed_generic_webhook',
    'wp_all_import_wizard', 'wp_all_import_cron', 'wp_all_import_wpcli',
    'google_sheets_oauth', 'sftp_ftp_pull', 'action_scheduler_worker' ) as $id )
    ck( in_array( $id, $ids, true ), 'Missing alternative mode: ' . $id );
$mode = MAD4B_SCP_Activity_Import_Modes::plan( array( 'profile_slug' => 'pricing',
    'mode_id' => 'admin_csv_upload' ) );
ck( !is_wp_error( $mode ) && $mode['eligible_for_staging_review'] &&
    !$mode['ready_to_mutate_posts'] && $mode['no_automatic_fallback_for_import_writes'],
    'Mode plan leaked post mutation or auto-fallback authority' );
$badMode = MAD4B_SCP_Activity_Import_Modes::plan( array( 'profile_slug' => 'pricing',
    'mode_id' => 'user_invented_exec' ) );
ck( is_wp_error( $badMode ) && $badMode->get_error_code() === 'mad4b_import_mode_unknown',
    'Unregistered arbitrary execution mode accepted' );
$GLOBALS['imp02_enabled_modes'] = array( 'admin_csv_upload', 'signed_generic_webhook' );
$notAllowed = MAD4B_SCP_Activity_Import_Modes::plan( array(
    'profile_slug' => 'pricing', 'mode_id' => 'wp_all_import_cron' ) );
ck( is_wp_error( $notAllowed ) &&
    $notAllowed->get_error_code() === 'mad4b_import_mode_disabled_for_profile',
    'Profile-specific allowlist did not suppress nonapproved transport' );
$allowed = MAD4B_SCP_Activity_Import_Modes::plan( array(
    'profile_slug' => 'pricing', 'mode_id' => 'admin_csv_upload' ) );
ck( !is_wp_error( $allowed ) &&
    $allowed['profile_preferred_mode'] === 'admin_csv_upload' &&
    $allowed['profile_fallback_modes'] === array( 'signed_generic_webhook' ),
    'Versioned fallback mode preferences not read back' );
unset( $GLOBALS['imp02_enabled_modes'] );
$GLOBALS['imp02_override_builtin'] = true;
$spoofed = MAD4B_SCP_Activity_Import_Modes::catalog();
unset( $GLOBALS['imp02_override_builtin'] );
ck( is_wp_error( $spoofed ) &&
    $spoofed->get_error_code() === 'mad4b_import_builtin_override_denied',
    'Extension changed native Mode certification claims' );
echo "PASS IMP02 dynamic mode registry, exact profile authorization and write-safe selection\n";
echo "PASS IMP01 bounded dynamic meta mapping, currency/status exceptions, duplicate IDs, preview limits and governed Brand Core review\n";
