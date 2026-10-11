<?php
/**
 * Feature007 mandatory publication/Brand Core gate regression (PHP 7.4+).
 * Uses isolated, fake FIRST-PARTY coverage. No WordPress boot or real writes.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code; private $data;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
class MAD4B_SCP_Context_Authority {
    public static $coverage = array();
    public static $source_count = 1;
    public static $asset_count = 12;
    public static function brand_core_coverage() { return self::$coverage; }
    public static function status() {
        return array(
            'brand_id' => 'fixture-brand', 'profile_revision' => 4, 'registry_revision' => 6,
            'context_fingerprint' => str_repeat( 'a', 64 ),
            'authority_manifest_fingerprint' => str_repeat( 'b', 64 ),
            'quarantined_source_record_count' => self::$source_count,
            'quarantined_asset_record_count' => self::$asset_count,
            'review_policy' => array( 'ready' => true ),
        );
    }
    public static function legacy_reconciliation_census() {
        return array( 'source_quarantine_count' => self::$source_count, 'asset_quarantine_count' => self::$asset_count );
    }
}
class MAD4B_SCP_Brand_Context_Builder {
    public static function convergence_plan( $input = array() ) {
        return array( 'registry_revision' => 6, 'authority_manifest_fingerprint' => str_repeat( 'b', 64 ), 'actions' => array(), 'writable_sources' => array() );
    }
}
class MAD4B_SCP_Google_Drive_Context {
    public static function connection_status() { return array( 'connected' => true, 'write_available' => true ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-core-control-loop.php';
function expect_brand( $condition, $reason ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit( 1 ); }
}
$categories = MAD4B_SCP_Brand_Core_Control_Loop::REQUIRED;
$rows = array();
foreach ( $categories as $name ) $rows[ $name ] = array( 'ready' => false, 'conflict' => false, 'eligible_assets' => array(), 'observed_assets' => array() );
$base = array(
    'ready' => false, 'coverage' => $rows, 'registry_revision' => 6,
    'context_fingerprint' => str_repeat( 'a', 64 ),
    'authority_manifest_fingerprint' => str_repeat( 'b', 64 ),
);
MAD4B_SCP_Context_Authority::$coverage = $base;
$blocked = MAD4B_SCP_Brand_Core_Control_Loop::publication_preflight( array( 'operation' => 'publish', 'post_type' => 'tours-and-activities' ) );
expect_brand( is_wp_error( $blocked ) && 'mad4b_publication_brand_core_recovery_required' === $blocked->get_error_code(), 'Unapproved Brand Core must block publication' );
$data = $blocked->get_error_data();
expect_brand( 'BLOCKED_BRAND_CORE_RECOVERY' === $data['state']
    && 'BLOCKED_OWNERSHIP_QUARANTINE' === $data['recovery_state']
    && 'context/legacy-reconciliation-census' === $data['next_ability']
    && $categories === $data['missing_categories'], 'Must route original quarantined assets before recreating them' );
expect_brand( true === $data['approval_required_per_mutation']
    && false === $data['ownership_auto_approval_allowed']
    && false === $data['production_auto_approval_allowed']
    && false === $data['mutation_performed'], 'Preflight cannot fabricate approval or mutate' );

MAD4B_SCP_Context_Authority::$source_count = 0;
MAD4B_SCP_Context_Authority::$asset_count = 0;
$approved = $base;
$approved['ready'] = true;
foreach ( $categories as $i => $name ) {
    $approved['coverage'][ $name ] = array(
        'ready' => true, 'conflict' => false, 'observed_assets' => array(),
        'eligible_assets' => array( array(
            'asset_id' => 'asset-' . $i,
            'content_hash' => str_repeat( (string) ( $i + 1 ), 64 ),
            'review_binding_exact' => true,
            'review_status' => 'approved',
        ) ),
    );
}
MAD4B_SCP_Context_Authority::$coverage = $approved;
$ok = MAD4B_SCP_Brand_Core_Control_Loop::publication_preflight( array( 'post_type' => 'tours-and-activities' ) );
expect_brand( is_array( $ok ) && true === $ok['ready'] && false === $ok['mutation_performed']
    && 64 === strlen( $ok['reviewed_asset_fingerprint'] ), 'All three exact reviewed assets permit a bound plan' );
$new = $approved; $new['coverage']['tone_of_voice']['eligible_assets'][0]['content_hash'] = str_repeat( 'f', 64 );
MAD4B_SCP_Context_Authority::$coverage = $new;
$changed = MAD4B_SCP_Brand_Core_Control_Loop::publication_preflight( array() );
expect_brand( is_array( $changed ) && ! hash_equals( $ok['reviewed_asset_fingerprint'], $changed['reviewed_asset_fingerprint'] ), 'Changed approved content invalidates the prior binding' );
$unreviewed = $approved; $unreviewed['coverage']['editorial_guidelines']['eligible_assets'][0]['review_binding_exact'] = false;
MAD4B_SCP_Context_Authority::$coverage = $unreviewed;
expect_brand( is_wp_error( MAD4B_SCP_Brand_Core_Control_Loop::publication_preflight( array() ) ), 'Non-exact approval fails even with optimistic coverage flags' );
$conflict = $approved; $conflict['coverage']['brand_strategy']['conflict'] = true;
MAD4B_SCP_Context_Authority::$coverage = $conflict;
expect_brand( is_wp_error( MAD4B_SCP_Brand_Core_Control_Loop::publication_preflight( array() ) ), 'Conflicting Brand Strategies fail closed' );

$root = dirname( __DIR__ );
$sites = array(
    'includes/class-mad4b-scp-content-experience-runtime.php' => array( 'publication_brand_core', 'publication_preflight(', 'wp_update_post(' ),
    'includes/class-mad4b-scp-mutation-manager.php' => array( 'publication_preflight(', 'wp_update_post( wp_slash( $update )' ),
    'includes/adapters/class-mad4b-scp-core-content-modeling-adapter.php' => array( 'publication_preflight(', 'wp_insert_post( wp_slash( $postarr )' ),
);
foreach ( $sites as $file => $tokens ) {
    $src = file_get_contents( $root . '/' . $file );
    expect_brand( is_string( $src ), 'Runtime entrypoint must exist: ' . $file );
    foreach ( $tokens as $token ) expect_brand( false !== strpos( $src, $token ), 'Missing mandatory publication guard: ' . $file . ' => ' . $token );
}
// Already-public edits are a separate mutation path, not a publish op.
$experience = file_get_contents( $root . '/includes/class-mad4b-scp-content-experience-runtime.php' );
expect_brand(
    is_string( $experience )
    && false !== strpos( $experience, "'update' === \$operation && in_array( (string) \$post->post_status" )
    && false !== strpos( $experience, "'publication_brand_core' => \$brand_preflight" )
    && false !== strpos( $experience, "'mad4b_publication_brand_core_drift', 'Reviewed Brand Core changed before live content update.'" )
    && false !== strpos( $experience, "'mad4b_publication_brand_core_drift', 'Reviewed Brand Core changed during live content update.'" ),
    'Already-live Content Experience edits must recheck reviewed Brand Core before and after mutation'
);
echo "PASS: governed publication blocks quarantined/missing/conflicted Brand Core; approval and drift remain fail-closed\n";
