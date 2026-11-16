<?php

define( 'ABSPATH', '/tmp/mad4b-context-registry-atomicity/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['mad4b_context_options'] = array();
$GLOBALS['mad4b_fail_option'] = '';

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap ) { return $cap === 'manage_options'; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
// A cold Options API read returns scalar database values as strings. Arrays
// retain their serialized types; an in-memory-only stub hid revision failures.
function get_option( $name, $default = false ) {
	if ( ! array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return $default;
	$value = $GLOBALS['mad4b_context_options'][ $name ];
	return is_scalar( $value ) || null === $value ? (string) $value : $value;
}
function add_option( $name, $value ) {
	if ( $GLOBALS['mad4b_fail_option'] === $name ) return false;
	if ( array_key_exists( $name, $GLOBALS['mad4b_context_options'] ) ) return false;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value ) {
	if ( $GLOBALS['mad4b_fail_option'] === $name ) return false;
	$GLOBALS['mad4b_context_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['mad4b_context_options'][ $name ] ); return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_trim_words( $value, $num_words = 55, $more = null ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }

class MAD4B_SCP_Audit {
	public static $ready = true;
	public static $fail_append = false;
	public static $events = array();
	public static function storage_status() { return array( 'ready' => self::$ready ); }
	public static function record( $ability, $summary = array(), $status = 'ok' ) {
		if ( self::$fail_append ) return new WP_Error( 'fixture_audit_append_failed', 'fixture audit append failed' );
		self::$events[] = array( 'ability' => (string) $ability, 'summary' => $summary, 'status' => (string) $status );
		return true;
	}
}

class MAD4B_SCP_Site_Profile {
	public static function status() {
		return array(
			'configured' => true,
			'origin_match' => true,
			'environment_match' => true,
			'site_uuid' => '11111111-1111-4111-8111-111111111111',
			'environment' => 'staging',
		);
	}
}
// Local fixture: a bounded, verified Site/Brand scope is required before
// any registry write. Do not bypass the production Integrity fence.
final class MAD4B_SCP_Operational_Integrity {
    public static $trusted = true;
    public static $drift = false;
    public static function capture() {
        return self::$trusted
            ? array( 'site_uuid' => '11111111-1111-4111-8111-111111111111',
                'revision' => 7, 'brand_id' => 'ffffffffffffffffffffffffffffffff',
                'scope_digest' => str_repeat('c',64) )
            : new WP_Error( 'mad4b_integrity_scope_blocked', 'Fixture Site Profile binding absent.' );
    }
    public static function assert_unchanged( $checkpoint ) {
        return ! self::$drift && self::$trusted
            ? true : new WP_Error( 'mad4b_integrity_scope_changed', 'Trusted scope changed during registry mutation.' );
    }
}
final class MAD4B_SCP_Operational_Scope_Guard {
    public static function require_current() {
        if ( ! MAD4B_SCP_Operational_Integrity::$trusted )
            return new WP_Error( 'mad4b_integrity_scope_blocked', 'Fixture scope unavailable.' );
        return array( 'site_uuid' => '11111111-1111-4111-8111-111111111111',
            'brand_ref' => 'ffffffffffffffffffffffffffffffff',
            'tenant_ref' => 'wp-site:11111111-1111-4111-8111-111111111111',
            'blog_id' => 1, 'network_id' => 1, 'environment' => 'staging',
            'deployment_mode' => 'wordpress_dedicated' );
    }
    public static function record_metadata_matches( $record, $scope ) {
        foreach ( array( 'tenant_ref', 'blog_id', 'network_id', 'environment', 'deployment_mode' ) as $k )
            if ( isset( $record[$k] ) && (string)$record[$k] !== (string)$scope[$k] ) return false;
        return true;
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';

function mad4b_atomic_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$source_id = str_repeat( 'a', 64 );
$asset_id = str_repeat( 'b', 64 );
$site_uuid = '11111111-1111-4111-8111-111111111111';
$source = array(
	'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT,
	'source_id' => $source_id,
	'site_uuid' => $site_uuid,
	'brand_id' => 'ffffffffffffffffffffffffffffffff',
	'provider' => 'google_drive',
	'mode' => 'governed',
	'external_root_id' => 'folder-fixture',
	'label' => 'Brand Core',
	'task_scope' => '',
	'write_policy' => 'repair_only',
	'recursive' => true,
	'status' => 'ready',
	'last_scan_complete' => true,
	'last_scan_generation' => str_repeat( '1', 64 ),
	'last_complete_scan_generation' => str_repeat( '1', 64 ),
	'last_complete_scan_at' => gmdate( 'c' ),
	'last_scan_truncation_reasons' => array(),
	'last_synced_at' => gmdate( 'c' ),
	'asset_count' => 1,
);
$asset = array(
	'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT,
	'asset_id' => $asset_id,
	'site_uuid' => $site_uuid,
	'brand_id' => 'ffffffffffffffffffffffffffffffff',
	'source_id' => $source_id,
	'source_mode' => 'governed',
	'provider' => 'google_drive',
	'file_id' => 'file-fixture',
	'parent_folder_id' => 'folder-fixture',
	'title' => 'Tone',
	'mime_type' => 'text/plain',
	'content_hash' => str_repeat( 'c', 64 ),
	'content_complete' => true,
	'category' => 'tone_of_voice',
	'authority_class' => 'brand_authority',
	'required' => true,
	'priority' => 100,
	'review_status' => 'approved',
	'status' => 'ready',
);

$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ] = array(
	'contract' => MAD4B_SCP_Context_Authority::PROFILE_CONTRACT,
	'site_uuid' => $site_uuid,
	'brand_id' => 'ffffffffffffffffffffffffffffffff',
	'brand_name' => 'Fixture Brand',
	'revision' => 1,
	'status' => 'configured',
	'context_policy' => 'site_bound_governed_plus_task_sources',
	'context_fingerprint' => str_repeat( '0', 64 ),
	'last_verified_at' => '',
);
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ] = array( $source_id => $source );
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ] = array( $asset_id => $asset );
$GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ] = 7;

$baseline_sources = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ];
$baseline_assets = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ];
mad4b_atomic_assert( isset( MAD4B_SCP_Context_Authority::sources()[ $source_id ] ),
    'Trusted exact scoped source must be visible before testing atomic registry writes.' );

// Denied scope must fail before acquiring a registry lock or modifying options.
MAD4B_SCP_Operational_Integrity::$trusted = false;
$denied_scope = MAD4B_SCP_Context_Authority::replace_source_assets( $source_id, array() );
mad4b_atomic_assert( is_wp_error( $denied_scope )
    && 'mad4b_integrity_scope_blocked' === $denied_scope->get_error_code(), 'Untrusted scope must be rejected.' );
mad4b_atomic_assert( $baseline_assets === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ]
    && $baseline_sources === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ], 'Denied scope cannot mutate registry.' );
MAD4B_SCP_Operational_Integrity::$trusted = true;

$GLOBALS['mad4b_fail_option'] = MAD4B_SCP_Context_Authority::SOURCES_OPTION;
$failed_second_write = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '2', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_atomic_assert( is_wp_error( $failed_second_write ), 'Second option write failure must fail the registry mutation.', $failed_second_write );
mad4b_atomic_assert( $baseline_assets === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ], 'Applied asset option must be compensated when source option persistence fails.' );
mad4b_atomic_assert( $baseline_sources === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ], 'Failed source option must remain unchanged.' );
mad4b_atomic_assert( 7 === (int) $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ], 'Failed mutation must not advance registry revision.' );

$GLOBALS['mad4b_fail_option'] = MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION;
$failed_revision = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '3', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_atomic_assert( is_wp_error( $failed_revision ), 'Revision persistence failure must fail the mutation.', $failed_revision );
mad4b_atomic_assert( 'mad4b_context_registry_revision_write_failed' === $failed_revision->get_error_code(), 'Revision failure must expose canonical error.', $failed_revision->get_error_code() );
mad4b_atomic_assert( $baseline_assets === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::ASSETS_OPTION ], 'Revision failure must restore asset snapshot.' );
mad4b_atomic_assert( $baseline_sources === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ], 'Revision failure must restore source snapshot.' );
mad4b_atomic_assert( 7 === (int) $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ], 'Revision failure compensation must preserve previous revision.' );

$GLOBALS['mad4b_fail_option'] = '';
$success = MAD4B_SCP_Context_Authority::replace_source_assets(
	$source_id,
	array(),
	array(
		'complete' => true,
		'scan_generation' => str_repeat( '4', 64 ),
		'started_at' => gmdate( 'c' ),
		'completed_at' => gmdate( 'c' ),
		'truncation_reasons' => array(),
	)
);
mad4b_atomic_assert( ! is_wp_error( $success ), 'Healthy registry mutation must commit.' );
mad4b_atomic_assert( 8 === (int) $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::REGISTRY_REVISION_OPTION ], 'Healthy mutation must advance registry revision exactly once.' );
$after = MAD4B_SCP_Context_Authority::assets();
mad4b_atomic_assert( 'unavailable' === $after[ $asset_id ]['status'], 'Committed complete scan must expose its intended asset state.' );

$before_upsert_sources = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ];
$before_upsert_revision = MAD4B_SCP_Context_Authority::registry_revision();
MAD4B_SCP_Audit::$fail_append = true;
$failed_upsert = MAD4B_SCP_Context_Authority::upsert_source(
	array(
		'provider' => 'google_drive',
		'mode' => 'governed',
		'external_root_id' => 'folder-audit-compensation',
		'label' => 'Audit Compensation Folder',
		'write_policy' => 'repair_only',
		'write_policy_confirmed' => true,
	)
);
mad4b_atomic_assert( is_wp_error( $failed_upsert ), 'Source upsert must fail when append-only audit commit fails.', $failed_upsert );
mad4b_atomic_assert( 'mad4b_context_registry_audit_commit_failed' === $failed_upsert->get_error_code(), 'Source upsert audit failure must expose compensated audit error.', $failed_upsert->get_error_code() );
mad4b_atomic_assert( $before_upsert_sources === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::SOURCES_OPTION ], 'Source upsert audit failure must restore the exact pre-upsert source registry.' );
mad4b_atomic_assert( $before_upsert_revision === MAD4B_SCP_Context_Authority::registry_revision(), 'Source upsert audit failure must restore the pre-upsert registry revision.' );

MAD4B_SCP_Audit::$fail_append = false;
$successful_upsert = MAD4B_SCP_Context_Authority::upsert_source(
	array(
		'provider' => 'google_drive',
		'mode' => 'governed',
		'external_root_id' => 'folder-audited-success',
		'label' => 'Audited Source Folder',
		'write_policy' => 'repair_only',
		'write_policy_confirmed' => true,
	)
);
mad4b_atomic_assert( ! is_wp_error( $successful_upsert ), 'Healthy source upsert must commit with audit evidence.', $successful_upsert );
mad4b_atomic_assert( $before_upsert_revision + 1 === MAD4B_SCP_Context_Authority::registry_revision(), 'Healthy audited source upsert must advance registry revision exactly once.' );
$source_events = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $row ) { return 'mad4b/context-source-upsert' === $row['ability']; } ) );
mad4b_atomic_assert( 1 === count( $source_events ), 'Healthy source upsert must append exactly one governed audit event.', $source_events );
mad4b_atomic_assert( 'google_drive' === $source_events[0]['summary']['provider'] && 'repair_only' === $source_events[0]['summary']['write_policy'], 'Source upsert audit must bind provider and write policy.', $source_events[0] );

$before_profile = $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ];
$before_profile_revision = MAD4B_SCP_Context_Authority::registry_revision();
MAD4B_SCP_Audit::$fail_append = true;
// Exercise audit compensation AFTER satisfying the separately required
// identity-preserving rename CAS. An omitted consent must fail earlier and
// cannot be counted as an audit persistence/compensation test.
$failed_profile = MAD4B_SCP_Context_Authority::save_profile( 'Changed Fixture Brand', array(
    'expected_brand_id' => (string) $before_profile['brand_id'],
    'expected_revision' => (int) $before_profile['revision'],
    'confirm_identity_preserving_rename' => true,
) );
mad4b_atomic_assert( is_wp_error( $failed_profile ), 'Brand Context Profile save must fail when append-only audit commit fails.', $failed_profile );
mad4b_atomic_assert( 'mad4b_context_registry_audit_commit_failed' === $failed_profile->get_error_code(), 'Profile audit failure must expose compensated audit error.', $failed_profile->get_error_code() );
mad4b_atomic_assert( $before_profile === $GLOBALS['mad4b_context_options'][ MAD4B_SCP_Context_Authority::PROFILE_OPTION ], 'Profile audit failure must restore exact prior Brand Context Profile.' );
mad4b_atomic_assert( $before_profile_revision === MAD4B_SCP_Context_Authority::registry_revision(), 'Profile audit failure must restore pre-save registry revision.' );

MAD4B_SCP_Audit::$fail_append = false;
$saved_profile = MAD4B_SCP_Context_Authority::save_profile( 'Changed Fixture Brand', array(
    'expected_brand_id' => (string) $before_profile['brand_id'],
    'expected_revision' => (int) $before_profile['revision'],
    'confirm_identity_preserving_rename' => true,
) );
mad4b_atomic_assert( ! is_wp_error( $saved_profile ), 'Healthy Brand Context Profile save must commit with audit evidence.', $saved_profile );
mad4b_atomic_assert( $before_profile_revision + 1 === MAD4B_SCP_Context_Authority::registry_revision(), 'Healthy audited profile save must advance registry revision exactly once.' );
$profile_events = array_values( array_filter( MAD4B_SCP_Audit::$events, static function ( $row ) { return 'mad4b/context-profile-save' === $row['ability']; } ) );
mad4b_atomic_assert( 1 === count( $profile_events ), 'Healthy Brand Context Profile save must append exactly one governed audit event.', $profile_events );
mad4b_atomic_assert( 2 === (int) $profile_events[0]['summary']['revision'] && empty( $profile_events[0]['summary']['created'] ), 'Profile save audit must bind exact revision and update/create state.', $profile_events[0] );

$write_scalar = new ReflectionMethod( 'MAD4B_SCP_Context_Authority', 'write_option' );
mad4b_atomic_assert( $write_scalar->isPrivate(), 'Context option persistence helper must remain private.' );
// PHP 7.4 requires an explicit accessibility override for test-only invocation
// of a private ReflectionMethod. This does not widen the runtime API surface.
if(PHP_VERSION_ID<80100)$write_scalar->setAccessible( true );
$GLOBALS['mad4b_fail_option'] = 'scalar_failure';
foreach ( array( false, '', null ) as $scalar ) if ( $write_scalar->invoke( null, 'scalar_failure', $scalar ) ) throw new RuntimeException( 'Missing option falsely verified as an empty scalar' );
$GLOBALS['mad4b_fail_option'] = '';
if ( ! $write_scalar->invoke( null, 'scalar_revision', 7 ) ) throw new RuntimeException( 'Cold string revision did not match the persisted integer' );

// A failed archive/cleanup MUST NOT leave the newly created foreign vault
// batch behind after the active sources/assets are restored. This is one
// exact Staging-only, no-external-file-mutation fixture.
$foreign_source_id=str_repeat('d',64);
$foreign_asset_id=str_repeat('e',64);
$foreign_site='22222222-2222-4222-8222-222222222222';
$foreign_brand=str_repeat('e',32);
$foreign_source=array_replace($source,array(
 'source_id'=>$foreign_source_id,'site_uuid'=>$foreign_site,
 'brand_id'=>$foreign_brand,'external_root_id'=>'foreign-owned-folder'));
$foreign_asset=array_replace($asset,array(
 'asset_id'=>$foreign_asset_id,'source_id'=>$foreign_source_id,
 'site_uuid'=>$foreign_site,'brand_id'=>$foreign_brand,
 'file_id'=>'foreign-owned-file','parent_folder_id'=>'foreign-owned-folder'));
$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION][$foreign_source_id]=$foreign_source;
$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION][$foreign_asset_id]=$foreign_asset;
$before_recycle_sources=$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION];
$before_recycle_assets=$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION];
$before_recycle_revision=MAD4B_SCP_Context_Authority::registry_revision();
$recycle_plan=MAD4B_SCP_Context_Authority::foreign_recycle_plan();
mad4b_atomic_assert(!is_wp_error($recycle_plan)
 && $recycle_plan['foreign_sources']===1 && $recycle_plan['foreign_assets']===1
 && $recycle_plan['archive_only']===true && $recycle_plan['external_files_deleted']===false,
 'Only fully foreign site+brand records may be proposed for bounded archive');
$recycle_input=array(
 'expected_registry_revision'=>$recycle_plan['expected_registry_revision'],
 'expected_inventory_sha256'=>$recycle_plan['expected_inventory_sha256']);
MAD4B_SCP_Audit::$fail_append=true;
$recycle_audit_failed=MAD4B_SCP_Context_Authority::foreign_recycle_apply($recycle_input);
mad4b_atomic_assert(is_wp_error($recycle_audit_failed)
 && $before_recycle_sources===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION]
 && $before_recycle_assets===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION]
 && $before_recycle_revision===MAD4B_SCP_Context_Authority::registry_revision()
 && !array_key_exists(MAD4B_SCP_Context_Authority::RECYCLE_ARCHIVE_OPTION,$GLOBALS['mad4b_context_options']),
 'Audit failure must atomically roll back foreign vault batch together with active registry');
MAD4B_SCP_Audit::$fail_append=false;
$GLOBALS['mad4b_fail_option']=MAD4B_SCP_Context_Authority::SOURCES_OPTION;
$recycle_source_failed=MAD4B_SCP_Context_Authority::foreign_recycle_apply($recycle_input);
mad4b_atomic_assert(is_wp_error($recycle_source_failed)
 && $before_recycle_sources===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION]
 && $before_recycle_assets===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION]
 && !array_key_exists(MAD4B_SCP_Context_Authority::RECYCLE_ARCHIVE_OPTION,$GLOBALS['mad4b_context_options']),
 'When first registry save fails, archive-only change must still trigger compensation');
$GLOBALS['mad4b_fail_option']=MAD4B_SCP_Context_Authority::ASSETS_OPTION;
$recycle_cleanup_failed=MAD4B_SCP_Context_Authority::foreign_recycle_apply($recycle_input);
mad4b_atomic_assert(is_wp_error($recycle_cleanup_failed)
 && $before_recycle_sources===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION]
 && $before_recycle_assets===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION]
 && $before_recycle_revision===MAD4B_SCP_Context_Authority::registry_revision()
 && !array_key_exists(MAD4B_SCP_Context_Authority::RECYCLE_ARCHIVE_OPTION,$GLOBALS['mad4b_context_options']),
 'Registry cleanup failure must roll back vault and retain foreign records');
$GLOBALS['mad4b_fail_option']='';
$recycle_committed=MAD4B_SCP_Context_Authority::foreign_recycle_apply($recycle_input);
mad4b_atomic_assert(is_array($recycle_committed)
 && $recycle_committed['state']==='ARCHIVED_AND_ACTIVE_REGISTRY_CLEAN'
 && $recycle_committed['archive_verified']===true
 && $recycle_committed['external_files_deleted']===false
 && !isset($GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION][$foreign_source_id])
 && !isset($GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION][$foreign_asset_id]),
 'Healthy exact native recycle commits once without remote file deletion');
$archive_status=MAD4B_SCP_Context_Authority::foreign_recycle_archive_status();
mad4b_atomic_assert($archive_status['integrity_verified']===true
 && $archive_status['archived_sources']===1 && $archive_status['archived_assets']===1
 && $archive_status['owner_reassignment_authorized']===false,
 'Archive readback is immutable company metadata, never ownership reassignment');

// Strict vault bound: 32 independent historical snapshots are the maximum.
// A 33rd new exact plan must be denied with no cleanup, audit or external
// mutation; a malformed already-overfull vault must also fail readback.
$original_vault=$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::RECYCLE_ARCHIVE_OPTION];
$existing_batch=reset($original_vault['batches']);
$full_vault=$original_vault;
for ($i=1;$i<32;++$i) {
    $digest=hash('sha256','archived-snapshot-'.$i);
    $old=$existing_batch;
    $old['snapshot_sha256']=$digest;
    $full_vault['batches'][$digest]=$old;
}
mad4b_atomic_assert(count($full_vault['batches'])===32,
    'Exact archive capacity fixture must contain thirty-two distinct snapshots.');
$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::RECYCLE_ARCHIVE_OPTION]=$full_vault;
$full_status=MAD4B_SCP_Context_Authority::foreign_recycle_archive_status();
mad4b_atomic_assert(!empty($full_status['integrity_verified'])
    && $full_status['batches']===32,
    'Thirty-two valid exact archive batches must remain readable.');
$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION][$foreign_source_id]=$foreign_source;
$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION][$foreign_asset_id]=$foreign_asset;
$full_sources=$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION];
$full_assets=$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION];
$full_revision=MAD4B_SCP_Context_Authority::registry_revision();
$full_plan=MAD4B_SCP_Context_Authority::foreign_recycle_plan();
mad4b_atomic_assert(is_array($full_plan)&&$full_plan['foreign_sources']===1,
    'New foreign batch plan must still detect the exact registered foreign source.');
$no_space=MAD4B_SCP_Context_Authority::foreign_recycle_apply(array(
    'expected_registry_revision'=>$full_plan['expected_registry_revision'],
    'expected_inventory_sha256'=>$full_plan['expected_inventory_sha256']));
mad4b_atomic_assert(is_wp_error($no_space)
    && $no_space->get_error_code()==='mad4b_recycle_vault_capacity'
    && $full_vault===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::RECYCLE_ARCHIVE_OPTION]
    && $full_sources===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION]
    && $full_assets===$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION]
    && $full_revision===MAD4B_SCP_Context_Authority::registry_revision(),
    'Full archive must deny a thirty-third batch without changing existing site records.');
$overfull=$full_vault;
$digest=hash('sha256','archive-too-many');
$old=$existing_batch;$old['snapshot_sha256']=$digest;
$overfull['batches'][$digest]=$old;
$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::RECYCLE_ARCHIVE_OPTION]=$overfull;
$overfull_status=MAD4B_SCP_Context_Authority::foreign_recycle_archive_status();
mad4b_atomic_assert($overfull_status['integrity_verified']===false,
    'Overfull imported vault must never report verified archive integrity.');
// Restore the previous exact archived state and active registry fixture.
$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::RECYCLE_ARCHIVE_OPTION]=$original_vault;
unset($GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION][$foreign_source_id]);
unset($GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::ASSETS_OPTION][$foreign_asset_id]);
echo "PASS: CONTEXT_RECYCLE_VAULT_CAPACITY 3 bounded archive cases\n";

// Editing an existing enrolled Google folder must preserve identity and
// invalidate previous complete scan evidence when recursion changes.
$folder_before=$GLOBALS['mad4b_context_options'][MAD4B_SCP_Context_Authority::SOURCES_OPTION][$source_id];
$folder_revision=MAD4B_SCP_Context_Authority::registry_revision();
$folder_change=array(
 'expected_external_root_id'=>$folder_before['external_root_id'],
 'expected_source_mode'=>$folder_before['mode'],
 'expected_registry_revision'=>$folder_revision,
 'write_policy'=>$folder_before['write_policy'],
 'recursive'=>false);
$wrong_folder=MAD4B_SCP_Context_Authority::update_existing_google_folder(
 $source_id,array_replace($folder_change,array('expected_external_root_id'=>'foreign-folder')));
mad4b_atomic_assert(is_wp_error($wrong_folder)
 && $wrong_folder->get_error_code()==='mad4b_context_existing_folder_identity_stale'
 && $folder_revision===MAD4B_SCP_Context_Authority::registry_revision(),
 'A Google folder with a different provider ID must never inherit current registry entry');
$reduced_scope=MAD4B_SCP_Context_Authority::update_existing_google_folder(
 $source_id,$folder_change);
mad4b_atomic_assert(is_array($reduced_scope)
 && $reduced_scope['source_id']===$source_id
 && $reduced_scope['external_root_id']===$folder_before['external_root_id']
 && $reduced_scope['recursive']===false
 && $reduced_scope['last_scan_complete']===false
 && $reduced_scope['last_scan_generation']===''
 && $reduced_scope['status']==='selected'
 && MAD4B_SCP_Context_Authority::registry_revision()===$folder_revision+1,
 'Narrowing an existing folder must retain ownership and require a new complete scan');
$expand=array_replace($folder_change,array('expected_registry_revision'=>MAD4B_SCP_Context_Authority::registry_revision(),'recursive'=>true));
$unconfirmed_scope=MAD4B_SCP_Context_Authority::update_existing_google_folder($source_id,$expand);
mad4b_atomic_assert(is_wp_error($unconfirmed_scope)
 && $unconfirmed_scope->get_error_code()==='mad4b_context_source_recursive_confirmation_required',
 'Expanding the existing Google Drive folder must demand explicit scope confirmation');
$expanded_scope=MAD4B_SCP_Context_Authority::update_existing_google_folder(
 $source_id,array_replace($expand,array('recursive_scope_confirmed'=>true)));
mad4b_atomic_assert(is_array($expanded_scope)
 && $expanded_scope['recursive']===true
 && $expanded_scope['last_scan_complete']===false
 && $expanded_scope['source_id']===$source_id,
 'Confirmed wider recursion is allowed only on the existing source, with scan invalidated');

echo "mad4b.site-control-plane.context-registry-atomicity.runtime.v3: PASS\n";
