<?php
/**
 * Synthetic isolation regression. No WordPress database or external API access.
 * Execute: php tests/trusted-brand-scope-runtime.php
 */
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action() {}
function get_current_blog_id() { return $GLOBALS['mock_blog_id'] ?? 1; }
function get_current_network_id() { return 1; }
function get_current_user_id() { return $GLOBALS['mock_actor'] ?? 7; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function current_user_can( $cap ) { return 'manage_options' === (string) $cap; }
function absint( $value ) { return abs( (int) $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_generate_uuid4() { return 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) {
    if ( array_key_exists( $key, $GLOBALS['mock_options'] ) ) return false;
    $GLOBALS['mock_options'][ $key ] = $value;
    return true;
}
function update_option( $key, $value, $autoload = false ) {
    $GLOBALS['mock_options'][ $key ] = $value;
    return true;
}
function delete_option( $key ) {
    if ( ! array_key_exists( $key, $GLOBALS['mock_options'] ) ) return false;
    unset( $GLOBALS['mock_options'][ $key ] );
    return true;
}
function wp_cache_delete() { return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_option( $key, $default = null ) {
    return array_key_exists( $key, $GLOBALS['mock_options'] ) ? $GLOBALS['mock_options'][ $key ] : $default;
}
function check( $pass, $label ) {
    if ( ! $pass ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
class MAD4B_SCP_Audit {
    public static function storage_status() { return array( 'ready' => true ); }
    public static function record( $event, $summary = array(), $status = 'ok' ) { return true; }
}
class MAD4B_SCP_Google_Drive_Context {
    public static $file_exists = true;
    public static function get_folder( $folder ) {
        return self::$file_exists
            ? array( 'id' => $folder, 'mimeType' => 'application/vnd.google-apps.folder' )
            : new WP_Error( 'mad4b_provider_folder_missing' );
    }
}
class MAD4B_SCP_Site_Profile {
    public static function status() { return $GLOBALS['site_status']; }
}
class MAD4B_SCP_Deployment_Mode_Resolver {
    public static function resolve() { return $GLOBALS['mode_resolution']; }
}
class MAD4B_SCP_Schema {
    public static function critical_ready() { return true; }
    public static function transactional_storage_status($keys=array(),$refresh=false){return array('ready'=>!empty($GLOBALS['transactional_ok'])||!array_key_exists('transactional_ok',$GLOBALS),'read_your_writes'=>true);}
    public static function tables() { return array( 'content_jobs' => 'wp_jobs', 'content_job_events' => 'wp_events' ); }
}
class Mock_DB {
    public $calls = array();
    public function prepare( $query, $args ) { $this->calls[] = array( $query, $args ); return $query; }
    public function get_results( $sql, $mode ) { return array(); }
    public function get_row( $sql, $mode ) { return null; }
}
$wpdb = new Mock_DB();
$uuid = '11111111-2222-4333-8444-555555555555';
$a = str_repeat( 'a', 32 );
$b = str_repeat( 'b', 32 );
$GLOBALS['site_status'] = array(
    'configured' => true, 'origin_match' => true,
    'environment_match' => true, 'site_uuid' => $uuid, 'environment' => 'staging',
);
$GLOBALS['mode_resolution'] = array(
    'status' => 'RESOLVED_FOR_REVIEW_ONLY',
    'scope' => array( 'deployment_mode' => 'wordpress_dedicated', 'site_uuid' => $uuid, 'brand_ref' => $a, 'tenant_ref' => 'wp-site:' . $uuid, 'blog_id' => 1, 'network_id' => 1, 'environment' => 'staging' ),
    'dependency_revision' => array( 'site_profile' => 2, 'brand_profile' => 2 ),
);
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-operational-integrity.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-operational-scope-guard.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-content-jobs.php';
$GLOBALS['mock_options'] = array(
    MAD4B_SCP_Context_Authority::PROFILE_OPTION => array(
        'contract' => MAD4B_SCP_Context_Authority::PROFILE_CONTRACT,
        'site_uuid' => $uuid, 'brand_id' => $a, 'brand_name' => 'Before rename', 'revision' => 2,
    ),
    MAD4B_SCP_Context_Authority::SOURCES_OPTION => array(
        'source-a' => array( 'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT, 'source_id' => 'source-a', 'site_uuid' => $uuid, 'brand_id' => $a, 'provider' => 'google_drive', 'mode' => 'governed', 'external_root_id' => 'folder_a' ),
        'source-b' => array( 'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT, 'source_id' => 'source-b', 'site_uuid' => $uuid, 'brand_id' => $b, 'provider' => 'google_drive', 'mode' => 'governed', 'external_root_id' => 'folder_b' ),
        'source-legacy' => array( 'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT, 'source_id' => 'source-legacy', 'site_uuid' => $uuid, 'provider' => 'google_drive', 'mode' => 'governed', 'external_root_id' => 'folder_legacy' ),
    ),
    MAD4B_SCP_Context_Authority::ASSETS_OPTION => array(
        'asset-a' => array( 'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT, 'asset_id' => 'asset-a', 'site_uuid' => $uuid, 'brand_id' => $a, 'source_id' => 'source-a', 'source_mode' => 'governed', 'file_id' => 'file_a' ),
        'asset-b' => array( 'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT, 'asset_id' => 'asset-b', 'site_uuid' => $uuid, 'brand_id' => $b, 'source_id' => 'source-b', 'source_mode' => 'governed', 'file_id' => 'file_b' ),
        'asset-spoof' => array( 'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT, 'asset_id' => 'asset-spoof', 'site_uuid' => $uuid, 'brand_id' => $b, 'source_id' => 'source-a', 'source_mode' => 'governed', 'file_id' => 'file_spoof' ),
        'asset-unbound' => array( 'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT, 'asset_id' => 'asset-unbound', 'site_uuid' => $uuid, 'source_id' => 'source-a', 'source_mode' => 'governed', 'file_id' => 'file_unbound' ),
    ),
);
// Legacy source-key collision must never rewrite another brand's record.
$colliding_source_id = hash( 'sha256', $uuid . '|google_drive|governed|collision_folder|' );
$GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION][ $colliding_source_id ] = array(
    'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
    'source_id' => $colliding_source_id, 'site_uuid' => $uuid, 'brand_id' => $b,
    'mode' => 'governed', 'provider' => 'google_drive', 'external_root_id' => 'collision_folder',
);
$collision = MAD4B_SCP_Context_Authority::upsert_source( array( 'provider' => 'google_drive', 'mode' => 'governed', 'external_root_id' => 'collision_folder' ) );
check( is_wp_error( $collision ) && 'mad4b_context_source_brand_collision' === $collision->get_error_code(), 'source ID collision fails closed' );
check( $GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION][ $colliding_source_id ]['brand_id'] === $b, 'collision cannot take over existing source' );
unset( $GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION][ $colliding_source_id ] );
$sources = MAD4B_SCP_Context_Authority::sources();
$assets = MAD4B_SCP_Context_Authority::assets();
check( array_keys( $sources ) === array( 'source-a' ), 'only current brand source visible' );
check( array_keys( $assets ) === array( 'asset-a' ), 'other-brand and mismatched assets invisible' );
$scan_denied = MAD4B_SCP_Context_Authority::replace_source_assets( 'source-a', array() );
check( is_wp_error( $scan_denied ) && 'mad4b_context_scan_foreign_asset_quarantined' === $scan_denied->get_error_code(), 'mixed-brand scan cannot reuse old approval or mark foreign assets absent' );
check( isset( $GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION]['asset-spoof'] ), 'scan rejection keeps foreign asset record untouched' );
$remove_denied = MAD4B_SCP_Context_Authority::remove_source( 'source-a' );
check( is_wp_error( $remove_denied ) && 'mad4b_context_remove_foreign_asset_quarantined' === $remove_denied->get_error_code(), 'source removal cannot erase mismatched or unbound assets' );
check( isset( $GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION]['source-a'] ), 'denied removal leaves source intact' );
$GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::PROFILE_OPTION]['brand_name'] = 'After rename';
check( array_keys( MAD4B_SCP_Context_Authority::sources() ) === array( 'source-a' ), 'display-name edit retains source ownership' );
$GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::PROFILE_OPTION]['brand_id'] = $b;
// Simulate a legitimate verified host re-enrollment to the new Brand.
$GLOBALS['mode_resolution']['scope']['brand_ref'] = $b;
check( array_keys( MAD4B_SCP_Context_Authority::sources() ) === array( 'source-b' ), 'brand switch prevents old source exposure' );
$GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::PROFILE_OPTION]['brand_id'] = $a;
$GLOBALS['mode_resolution']['scope']['brand_ref'] = $a;

$rejected = MAD4B_SCP_Content_Jobs::create_job( array(
    'brand_id' => $b, 'subject' => 'Test', 'language' => 'en', 'country' => 'eg',
    'content_type' => 'article', 'reason' => 'synthetic',
) );
check( is_wp_error( $rejected ) && 'mad4b_content_job_brand_mismatch' === $rejected->get_error_code(), 'reject spoofed job brand' );
$listed = MAD4B_SCP_Content_Jobs::list_jobs( array( 'limit' => 10 ) );
check( ! is_wp_error( $listed ) && count( $wpdb->calls ) === 1, 'list uses scoped database query' );
check( strpos( $wpdb->calls[0][0], 'site_uuid=%s' ) !== false && strpos( $wpdb->calls[0][0], 'brand_id=%s' ) !== false && strpos( $wpdb->calls[0][0], 'tenant_id=%s' ) !== false, 'query requires site/brand/tenant identities' );
check( $wpdb->calls[0][1][0] === $uuid && $wpdb->calls[0][1][1] === $a, 'scoped query parameters from resolver' );
// A saved checkpoint is never reusable after profile, actor or blog drift.
$checkpoint = MAD4B_SCP_Operational_Integrity::capture();
check( ! is_wp_error( $checkpoint ) && ! $checkpoint['execution_authorized'], 'trusted checkpoint is non-authorizing' );
check( true === MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint ), 'same exact scope and revision remains valid' );
$GLOBALS['mode_resolution']['dependency_revision']['brand_profile'] = 3;
$stale = MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint );
check( is_wp_error( $stale ) && 'mad4b_integrity_checkpoint_stale' === $stale->get_error_code(), 'brand revision changed before commit' );
$GLOBALS['mode_resolution']['dependency_revision']['brand_profile'] = 2;
$GLOBALS['mock_actor'] = 8;
check( is_wp_error( MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint ) ), 'actor switch denied at checkpoint' );
$GLOBALS['mock_actor'] = 7;
$GLOBALS['mock_blog_id'] = 2;
$blog_drift = MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint );
check( is_wp_error( $blog_drift ) && 'mad4b_integrity_blog_context_drift' === $blog_drift->get_error_code(), 'cross-blog change fails closed' );
$GLOBALS['mock_blog_id'] = 1;
$saved_resolution = $GLOBALS['mode_resolution'];
$GLOBALS['mode_resolution'] = array( 'status' => 'BLOCKED', 'reason' => 'SITE_DEPLOYMENT_BINDING_NOT_ENROLLED', 'scope' => null );
$before = count( $wpdb->calls );
check( is_wp_error( MAD4B_SCP_Content_Jobs::list_jobs( array() ) ), 'blocked deployment fails closed' );
check( count( $wpdb->calls ) === $before, 'blocked deployment cannot query data' );
$GLOBALS['mode_resolution'] = $saved_resolution;
// Actual save path: a rename must be deliberate, exact-brand and revision bound.
$GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::PROFILE_OPTION]['brand_name'] = 'Before rename';
$denied_rename = MAD4B_SCP_Context_Authority::save_profile( 'After rename' );
check( is_wp_error( $denied_rename ) && 'mad4b_brand_rename_confirmation_required' === $denied_rename->get_error_code(), 'unguarded name change denied without changing sources' );
check( MAD4B_SCP_Context_Authority::profile()['brand_name'] === 'Before rename', 'denied rename preserves stored profile' );
$renamed = MAD4B_SCP_Context_Authority::save_profile( 'After rename', array(
    'expected_brand_id' => $a, 'expected_revision' => 2, 'confirm_identity_preserving_rename' => true,
) );
check( ! is_wp_error( $renamed ) && $renamed['brand_id'] === $a && $renamed['revision'] === 3, 'explicit rename preserves opaque brand identity' );
$GLOBALS['mode_resolution']['dependency_revision']['brand_profile'] = 3;
check( array_keys( MAD4B_SCP_Context_Authority::sources() ) === array( 'source-a' ), 'approved same-brand rename keeps current sources' );
$stale = MAD4B_SCP_Context_Authority::save_profile( 'Another name', array(
    'expected_brand_id' => $a, 'expected_revision' => 2, 'confirm_identity_preserving_rename' => true,
) );
check( is_wp_error( $stale ) && 'mad4b_brand_rename_revision_conflict' === $stale->get_error_code(), 'stale rename CAS rejected' );

// A separate SHA-bound legacy source remains quarantined until a real
// independently approved owner transfer. No foreign source is ever adopted.
$disabled_transfer = MAD4B_SCP_Context_Authority::legacy_owner_transfer_apply( array() );
check( is_wp_error($disabled_transfer) && 'mad4b_legacy_transfer_rollback_certification_required' === $disabled_transfer->get_error_code(),
    'Uncertified legacy transfer was executed instead of failing closed' );
define( 'MAD4B_SCP_CONTEXT_LEGACY_TRANSFER_ROLLBACK_CERTIFIED', true );
$legacy_source_id = hash( 'sha256', 'one-unbound-source' );
$old_sources = $GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ];
$old_assets = $GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ];
$GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ][ $legacy_source_id ] = array(
    'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
    'source_id' => $legacy_source_id, 'site_uuid' => $uuid, 'provider' => 'google_drive',
    'mode' => 'governed', 'external_root_id' => 'folder_legacy_verified', 'write_policy' => 'managed',
);
$GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ]['legacy-test-one'] = array(
    'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
    'asset_id' => 'legacy-test-one', 'site_uuid' => $uuid, 'source_id' => $legacy_source_id,
    'source_mode' => 'governed', 'file_id' => 'file_one', 'review_status' => 'approved',
    'reviewed_content_hash' => str_repeat( 'e', 64 ), 'status' => 'ready',
);
$legacy_plan = MAD4B_SCP_Context_Authority::legacy_owner_transfer_plan( array( 'source_id' => $legacy_source_id ) );
check( is_array( $legacy_plan ) && $legacy_plan['asset_count'] === 1 &&
    empty( $legacy_plan['mutation_performed'] ), 'Unbound source plan not independently read-only' );
$discover = MAD4B_SCP_Context_Authority::legacy_owner_transfer_discover();
check( is_array( $discover ) && $discover['candidate_count'] === 1 &&
    $discover['eligible_unbound_candidates'][0]['source_id'] === $legacy_source_id,
    'Unbound source ID cannot be discovered without adopting foreign records' );
$payload = array(
    'source_id' => $legacy_source_id, 'expected_plan_sha256' => $legacy_plan['plan_sha256'],
    'expected_brand_id' => $a, 'reviewed_external_root_id' => 'folder_legacy_verified',
    'owner_evidence_reference' => 'reviewed-google-drive-folder-proof-v1',
    'confirmation' => 'APPROVE EXACT UNBOUND BRAND TRANSFER',
);
$no_consent = $payload; $no_consent['confirmation'] = 'YES';
$denied = MAD4B_SCP_Context_Authority::legacy_owner_transfer_apply( $no_consent );
check( is_wp_error( $denied ) && 'mad4b_legacy_transfer_owner_confirmation_required' === $denied->get_error_code(),
    'Unapproved brand transfer mutated old records' );
$stale_claim = $payload; $stale_claim['expected_plan_sha256'] = str_repeat( '0', 64 );
$denied = MAD4B_SCP_Context_Authority::legacy_owner_transfer_apply( $stale_claim );
check( is_wp_error( $denied ) && 'mad4b_legacy_transfer_exact_plan_stale' === $denied->get_error_code(),
    'Stale transfer accepted' );
MAD4B_SCP_Google_Drive_Context::$file_exists = false;
$denied = MAD4B_SCP_Context_Authority::legacy_owner_transfer_apply( $payload );
check( is_wp_error( $denied ) && 'mad4b_provider_folder_missing' === $denied->get_error_code(),
    'Transfer proceeded without independent provider folder read' );
MAD4B_SCP_Google_Drive_Context::$file_exists = true;
$GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ]['legacy-foreign'] = array(
    'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
    'asset_id' => 'legacy-foreign', 'site_uuid' => $uuid, 'brand_id' => $b,
    'source_id' => $legacy_source_id, 'source_mode' => 'governed', 'file_id' => 'foreign',
);
$denied = MAD4B_SCP_Context_Authority::legacy_owner_transfer_plan( array( 'source_id' => $legacy_source_id ) );
check( is_wp_error( $denied ) && 'mad4b_legacy_transfer_asset_scope_conflict' === $denied->get_error_code(),
    'Foreign Brand asset was included in transfer' );
unset( $GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ]['legacy-foreign'] );
$done = MAD4B_SCP_Context_Authority::legacy_owner_transfer_apply( $payload );
check( is_array( $done ) && 'transferred_unapproved_requires_fresh_scan' === $done['state'] &&
    1 === $done['asset_count'], 'Independently approved exact legacy transfer failed' );
$transferred_asset = $GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ]['legacy-test-one'];
check( $a === $transferred_asset['brand_id'] &&
    'unreviewed' === $transferred_asset['review_status'] &&
    '' === $transferred_asset['reviewed_content_hash'] &&
    'stale' === $transferred_asset['status'],
    'Legacy transfer reused previous authority or skipped mandatory rescan' );
check( 'read_only' === $GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ][ $legacy_source_id ]['write_policy'],
    'Transfer carried forward old managed-write policy' );
$verified_transfer = MAD4B_SCP_Context_Authority::legacy_owner_transfer_readback(array('source_id' => $legacy_source_id));
check(is_array($verified_transfer) && $verified_transfer['ready_for_fresh_source_scan']
    && 1 === $verified_transfer['unreviewed_asset_count']
    && empty($verified_transfer['content_ready_for_publication']),
    'Independent readback accepted earlier Brand authority');
check( is_wp_error( MAD4B_SCP_Context_Authority::legacy_owner_transfer_apply( $payload ) ),
    'Old transfer approval silently replayed' );
// Restore previous test data after completing the separate migration scenario.
$GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] = $old_sources;
$GLOBALS['mock_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = $old_assets;

unset( $GLOBALS['mock_options'][MAD4B_SCP_Context_Authority::PROFILE_OPTION] );
$recreated = MAD4B_SCP_Context_Authority::save_profile( 'After rename' );
check( ! is_wp_error( $recreated ) && $recreated['brand_id'] !== $a, 're-enrollment never reclaims name-derived legacy identity' );
$GLOBALS['mode_resolution']['scope']['brand_ref'] = $recreated['brand_id'];
$GLOBALS['mode_resolution']['dependency_revision']['brand_profile'] = (int) $recreated['revision'];
check( count( MAD4B_SCP_Context_Authority::sources() ) === 0, 'new verified brand cannot see abandoned old sources even with same name' );
echo "PASS: scoped Context, explicit rename, re-enrollment, ContentJob synthetic regressions" . PHP_EOL;
