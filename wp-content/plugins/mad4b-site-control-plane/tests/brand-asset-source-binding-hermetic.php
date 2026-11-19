<?php
/**
 * Read-only source/company lineage contract. The document author supplies no
 * company identifier, fingerprint, approval stamp or special document header.
 */
define( 'ABSPATH', '/' );
class WP_Error {
    public function __construct( $code = '' ) {}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
function get_option( $name, $default = false ) { return $GLOBALS['brand_source_options'][$name] ?? $default; }
function assert_contract( $yes, $description ) {
    if ( ! $yes ) { fwrite( STDERR, 'FAIL BRAND_SOURCE_BINDING: ' . $description . "\n" ); exit( 1 ); }
}
class MAD4B_SCP_Site_Profile {
    public static function status() {
        return array(
            'configured' => true,
            'origin_match' => true,
            'environment_match' => true,
            'site_uuid' => $GLOBALS['fixture_site'],
            'environment' => 'staging',
        );
    }
}
class MAD4B_SCP_Operational_Scope_Guard {
    public static function require_current() {
        return array( 'site_uuid' => $GLOBALS['fixture_site'], 'brand_ref' => $GLOBALS['fixture_brand'] );
    }
    public static function record_metadata_matches( $record, $scope ) {
        return (string) ( $record['site_uuid'] ?? '' ) === $scope['site_uuid']
            && (string) ( $record['brand_id'] ?? '' ) === $scope['brand_ref'];
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-context-builder.php';

$GLOBALS['fixture_site'] = '123e4567-e89b-12d3-a456-426614174099';
$GLOBALS['fixture_brand'] = str_repeat( 'a', 32 );
$foreign_brand = str_repeat( 'b', 32 );
$source_id = str_repeat( 'c', 64 );
$asset_id = str_repeat( 'd', 64 );
$hash = str_repeat( 'e', 64 );
$GLOBALS['brand_source_options'] = array(
    MAD4B_SCP_Context_Authority::PROFILE_OPTION => array(
        'contract' => MAD4B_SCP_Context_Authority::PROFILE_CONTRACT,
        'site_uuid' => $GLOBALS['fixture_site'],
        'brand_id' => $GLOBALS['fixture_brand'],
        'brand_name' => 'Wayfatours',
        'revision' => 1,
    ),
    MAD4B_SCP_Context_Authority::SOURCES_OPTION => array(
        $source_id => array(
            'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
            'source_id' => $source_id,
            'site_uuid' => $GLOBALS['fixture_site'],
            'brand_id' => $GLOBALS['fixture_brand'],
            'external_root_id' => 'registered-company-drive-folder',
            'mode' => 'governed',
            'write_policy' => 'read_only',
        ),
    ),
);
$asset = array(
    'asset_id' => $asset_id,
    'source_id' => $source_id,
    'file_id' => 'provider-file-id-automatic',
    'source_mode' => 'governed',
    'site_uuid' => $GLOBALS['fixture_site'],
    'brand_id' => $GLOBALS['fixture_brand'],
    'title' => 'Brand guidelines',
    'content_excerpt' => 'Guidance about voice, positioning and guest care.',
    'status' => 'ready',
    'authority_class' => 'brand_authority',
    'review_status' => 'approved',
    'content_hash' => $hash,
    'reviewed_content_hash' => $hash,
);
$owner = static function( $record ) { return MAD4B_SCP_Context_Authority::brand_asset_binding_evidence( $record ); };
$approved = new ReflectionMethod( 'MAD4B_SCP_Brand_Context_Builder', 'approved_brand_asset' );
// PHP 7.4 needs explicit private-method reflection access; PHP 8.1+
// makes that call unnecessary and PHP 8.5 deprecates it.
if ( PHP_VERSION_ID < 80100 ) $approved->setAccessible( true );

assert_contract( 'bound' === $owner( $asset )['status'], 'registered site+source should establish lineage' );
assert_contract( 'unknown' === MAD4B_SCP_Context_Authority::brand_owner_evidence( $asset )['status'],
    'generic document title must not need a prescribed company prefix' );
assert_contract( $approved->invoke( null, $asset ), 'valid reviewed generic-titled source must be usable' );

$changed = $asset;
$changed['content_hash'] = str_repeat( 'f', 64 );
assert_contract( ! $approved->invoke( null, $changed ), 'content change must invalidate exact approval' );

$other = $asset;
$other['brand_id'] = $foreign_brand;
assert_contract( 'foreign' === $owner( $other )['status'] && ! $approved->invoke( null, $other ),
    'foreign-brand asset must never inherit another company approval' );

$other = $asset;
$other['site_uuid'] = 'f23e4567-e89b-12d3-a456-426614174099';
assert_contract( 'foreign' === $owner( $other )['status'] && ! $approved->invoke( null, $other ),
    'other site must never inherit source approval' );

$other = $asset;
$other['source_id'] = str_repeat( '1', 64 );
assert_contract( 'unverified' === $owner( $other )['status'] && ! $approved->invoke( null, $other ),
    'unregistered source is not a company provenance' );

$other = $asset;
$other['source_mode'] = 'task_attachment';
assert_contract( 'unverified' === $owner( $other )['status'] && ! $approved->invoke( null, $other ),
    'task-local attachment cannot self-promote into Brand Authority' );

$other = $asset;
$other['file_id'] = '';
assert_contract( 'unverified' === $owner( $other )['status'] && ! $approved->invoke( null, $other ),
    'source must have a provider-supplied file ID' );

$other = $asset;
$other['title'] = 'All Royal Egypt — Brand Strategy';
assert_contract( 'foreign' === MAD4B_SCP_Context_Authority::brand_owner_evidence( $other )['status']
    && ! $approved->invoke( null, $other ), 'explicit foreign-brand claim must reject' );

$other = $asset;
$other['title'] = 'Wayfatours — Brand Strategy';
$other['content_excerpt'] = 'All Royal Egypt — Editorial Guidelines';
assert_contract( 'foreign' === MAD4B_SCP_Context_Authority::brand_owner_evidence( $other )['status']
    && ! $approved->invoke( null, $other ), 'foreign header must outweigh apparently owned title' );

$GLOBALS['fixture_site'] = 'f23e4567-e89b-12d3-a456-426614174099';
assert_contract( 'unverified' === $owner( $asset )['status'] && ! $approved->invoke( null, $asset ),
    'changing the active Site Profile must not make old source transferable' );

echo "BRAND_ASSET_SOURCE_BINDING: PASS (auto provenance, creator-neutral, exact review, foreign-deny)\n";

echo "BRAND_ASSET_SOURCE_BINDING: PASS\n";
