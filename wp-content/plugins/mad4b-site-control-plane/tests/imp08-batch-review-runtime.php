<?php
/** IMP08 isolated PHP fixture: >500-row-capable multi-part encrypted review. */
require __DIR__ . '/imp01-import-preview-runtime.php';
// SQL option store simulation for the atomic batch lock only; source
// snapshot fixtures still use their independent fake WordPress options.
$GLOBALS['imp14_sql_options'] = array();
class IMP14_SQL_Mutex_DB {
    public $options = 'wp_options';
    public function prepare( $sql, ...$params ) {
        return array( 'sql' => $sql, 'params' => $params );
    }
    public function get_var( $query ) {
        $key = $query['params'][0];
        return array_key_exists( $key, $GLOBALS['imp14_sql_options'] ) ?
            $GLOBALS['imp14_sql_options'][ $key ] : null;
    }
    public function query( $query ) {
        $sql = $query['sql'];
        $parts = $query['params'];
        if ( strpos( $sql, 'INSERT IGNORE INTO' ) !== false ) {
            if ( isset( $GLOBALS['imp14_sql_options'][ $parts[0] ] ) ) return 0;
            $GLOBALS['imp14_sql_options'][ $parts[0] ] = $parts[1];
            return 1;
        }
        if ( strpos( $sql, 'DELETE FROM' ) !== false ) {
            if ( ! isset( $GLOBALS['imp14_sql_options'][ $parts[0] ] ) ||
                $GLOBALS['imp14_sql_options'][ $parts[0] ] !== $parts[1] )
                return 0;
            unset( $GLOBALS['imp14_sql_options'][ $parts[0] ] );
            return 1;
        }
        throw new RuntimeException( 'Unexpected SQL mutation in isolated fixture.' );
    }
}
$GLOBALS['wpdb'] = new IMP14_SQL_Mutex_DB();
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-batches.php';

function imp08_part( $start, $group ) {
    $headers = array( 'ID', 'base_currency', 'single_price', 'double_price',
        '_wpml_import_translation_group', '_wpml_import_language_code',
        '_wpml_import_after_process_post_status' );
    return array( 'profile_slug' => 'pricing',
        'headers' => $headers,
        'rows' => array(
            array( 'ID' => $start, 'base_currency' => 'USD',
                'single_price' => 100, 'double_price' => 80,
                '_wpml_import_translation_group' => $group,
                '_wpml_import_language_code' => 'en',
                '_wpml_import_after_process_post_status' => 'draft' ),
            array( 'ID' => $start + 1, 'base_currency' => 'EUR',
                'single_price' => 110, 'double_price' => 90,
                '_wpml_import_translation_group' => $group,
                '_wpml_import_language_code' => 'fr',
                '_wpml_import_after_process_post_status' => 'draft' )
        ) );
}
function imp08_many( $start, $groups, $prefix ) {
    $first = imp08_part( $start, $prefix . '-0' );
    $result = array( 'profile_slug' => 'pricing', 'headers' => $first['headers'],
        'rows' => array() );
    for ( $n = 0; $n < $groups; $n++ ) {
        $one = imp08_part( $start + $n * 2, $prefix . '-' . $n );
        foreach ( $one['rows'] as $row ) $result['rows'][] = $row;
    }
    return $result;
}
// A held per-Profile mutex denies mutation, rather than trusting an
// application-level read that could race another PHP worker.
$mutex_key = 'mad4b_batch_mutex_' . hash( 'sha256',
    MAD4B_SCP_Site_Profile::site_uuid() . '|pricing' );
$GLOBALS['imp14_sql_options'][ $mutex_key ] =
    str_repeat( 'f', 32 ) . '|append|' . time();
$mutex_readback = MAD4B_SCP_Activity_Import_Batches::mutation_status(
    array( 'profile_slug' => 'pricing' ) );
ck( ! is_wp_error( $mutex_readback ) &&
    $mutex_readback['mutation_lock_held'] &&
    !$mutex_readback['safe_for_new_mutation'] &&
    !$mutex_readback['automatic_lock_takeover_allowed'],
    'Stale or concurrent source lock was silently stolen or not observable' );
$locked = MAD4B_SCP_Activity_Import_Batches::begin( array(
    'profile_slug' => 'pricing', 'expected_chunks' => 2,
    'confirmed' => true ) );
ck( is_wp_error( $locked ) &&
    $locked->get_error_code() === 'mad4b_batch_mutation_locked',
    'Concurrent batch mutation bypassed the exact Profile mutex.' );
unset( $GLOBALS['imp14_sql_options'][ $mutex_key ] );
$first_lock = MAD4B_SCP_Batch_Atomic_Mutex::acquire( 'pricing', 'export' );
ck( !is_wp_error( $first_lock ), 'Atomic export reservation not obtained.' );
$second_lock = MAD4B_SCP_Batch_Atomic_Mutex::acquire( 'pricing', 'archive' );
ck( is_wp_error( $second_lock ) &&
    $second_lock->get_error_code() === 'mad4b_batch_mutation_locked',
    'Archive stole an existing CSV export lock.' );
$bad_owner = $first_lock;
$bad_owner['value'] = str_repeat( 'a', 32 ) . '|export|' . time();
$denied_release = MAD4B_SCP_Batch_Atomic_Mutex::release( $bad_owner );
ck( is_wp_error( $denied_release ) &&
    isset( $GLOBALS['imp14_sql_options'][ $mutex_key ] ),
    'Wrong owner released another PHP worker\'s SQL mutex.' );
ck( true === MAD4B_SCP_Batch_Atomic_Mutex::release( $first_lock ) &&
    !isset( $GLOBALS['imp14_sql_options'][ $mutex_key ] ),
    'Original mutex owner could not release exact SQL reservation.' );
$incomplete = MAD4B_SCP_Activity_Import_Batches::begin(
    array( 'profile_slug' => 'pricing', 'expected_chunks' => 2,
        'confirmed' => false ) );
ck( is_wp_error( $incomplete ), 'Unconfirmed batch started.' );

$b = MAD4B_SCP_Activity_Import_Batches::begin(
    array( 'profile_slug' => 'pricing', 'expected_chunks' => 2,
        'confirmed' => true ) );
ck( !is_wp_error( $b ) && strlen( $b['batch_id'] ) === 32,
    'Encrypted multi-part source inbox not created.' );
$id = $b['batch_id'];
ck( !isset( $GLOBALS['imp14_sql_options'][ $mutex_key ] ),
    'Successfully persisted batch did not release its own mutex.' );
$mutex_released = MAD4B_SCP_Activity_Import_Batches::mutation_status(
    array( 'profile_slug' => 'pricing' ) );
ck( !is_wp_error( $mutex_released ) &&
    !$mutex_released['mutation_lock_held'] &&
    $mutex_released['safe_for_new_mutation'],
    'Completed batch mutation retained a false lock after readback' );
$base = array( 'profile_slug' => 'pricing', 'batch_id' => $id );
$part1 = MAD4B_SCP_Activity_Import_Batches::append( array_merge( $base,
    array( 'chunk_index' => 0, 'source' => imp08_many( 1, 250, 'grp' ) ) ) );
ck( !is_wp_error( $part1 ) && $part1['post_writes'] === 0,
    'First encrypted page did not persist without business writes.' );
$missing = MAD4B_SCP_Activity_Import_Batches::verify( $base );
ck( !is_wp_error( $missing ) && !$missing['all_chunks_present'] &&
    !$missing['ready_for_manual_batch_review'],
    'Unfinished manifest was authorized.' );
$duplicate_index = MAD4B_SCP_Activity_Import_Batches::append(
    array_merge( $base, array( 'chunk_index' => 0,
        'source' => imp08_many( 1, 250, 'grp' ) ) ) );
ck( is_wp_error( $duplicate_index ) &&
    $duplicate_index->get_error_code() === 'mad4b_batch_chunk_already_staged',
    'Existing source chunk overwritten.' );
$part2 = MAD4B_SCP_Activity_Import_Batches::append(
    array_merge( $base, array( 'chunk_index' => 1,
        'source' => imp08_part( 501, 'grp-B' ) ) ) );
ck( !is_wp_error( $part2 ), 'Second encrypted source chunk refused.' );
$complete = MAD4B_SCP_Activity_Import_Batches::verify( $base );
ck( !is_wp_error( $complete ) &&
    $complete['all_chunks_present'] &&
    $complete['total_rows'] === 502 &&
    $complete['blocks'] === 0 &&
    $complete['cross_chunk_duplicate_id_count'] === 0 &&
    $complete['cross_chunk_wpml_group_split_count'] === 0 &&
    $complete['ready_for_manual_batch_review'] &&
    !$complete['automatic_execution_allowed'],
    'Complete batch plan lacks safe uniqueness/approval gating.' );
$badApproval = MAD4B_SCP_Activity_Import_Batches::approve( array_merge(
    $base, array( 'plan_sha256' => str_repeat( '0', 64 ),
        'confirmed' => true, 'acknowledged_warning_count' => 0 ) ) );
ck( is_wp_error( $badApproval ), 'Stale reviewed plan was approved.' );
$approved = MAD4B_SCP_Activity_Import_Batches::approve( array_merge(
    $base, array( 'plan_sha256' => $complete['plan_sha256'],
        'confirmed' => true, 'acknowledged_warning_count' => 0 ) ) );
ck( !is_wp_error( $approved ) &&
    $approved['authorization_scope'] === 'manual_csv_chunk_export_only' &&
    $approved['post_writes'] === 0,
    'Approved full source inherited provider import authority.' );
$status = MAD4B_SCP_Activity_Import_Batches::status( $base );
ck( !is_wp_error( $status ) && $status['approved_for_manual_chunk_export'],
    'Manual-only batch approval could not be independently read back.' );
// Business policy was disabled after staging; cleanup must remain possible,
// but all NEW ingestion continues to be denied.
$GLOBALS['imp02_enabled_modes'] = array();
$archived = MAD4B_SCP_Activity_Import_Batches::archive( array_merge(
    $base, array( 'confirmed' => true ) ) );
unset( $GLOBALS['imp02_enabled_modes'] );
ck( !is_wp_error( $archived ) && $archived['audit_recorded'],
    'Batch archival did not persist immutable audit.' );
$stale = MAD4B_SCP_Activity_Import_Batches::status( $base );
ck( is_wp_error( $stale ), 'Archived batch is still usable.' );

$b2 = MAD4B_SCP_Activity_Import_Batches::begin(
    array( 'profile_slug' => 'pricing', 'expected_chunks' => 2,
        'confirmed' => true ) );
ck( !is_wp_error( $b2 ), 'Archived batch retained locked Profile.' );
$base2 = array( 'profile_slug' => 'pricing', 'batch_id' => $b2['batch_id'] );
MAD4B_SCP_Activity_Import_Batches::append( array_merge( $base2,
    array( 'chunk_index' => 0, 'source' => imp08_many( 1, 250, 'grp' ) ) ) );
MAD4B_SCP_Activity_Import_Batches::append( array_merge( $base2,
    array( 'chunk_index' => 1, 'source' => imp08_part( 1, 'grp-B' ) ) ) );
$collision = MAD4B_SCP_Activity_Import_Batches::verify( $base2 );
ck( !is_wp_error( $collision ) &&
    $collision['cross_chunk_duplicate_id_count'] >= 2 &&
    !$collision['ready_for_manual_batch_review'],
    'Cross-chunk stable source IDs collided without rejection.' );
// Simulate a crash just after the manifest disappeared but before the
// Profile's active slot was released. Only the exact immutable tombstone
// may authorize bounded cleanup recovery.
$site_id = MAD4B_SCP_Site_Profile::site_uuid();
$manifest_base = hash( 'sha256', $site_id . '|pricing' );
$manifest_key = 'mad4b_batch_manifest_' . hash( 'sha256',
    $manifest_base . '|' . $b2['batch_id'] );
$tombstone = 'mad4b_batch_archive_' . hash( 'sha256',
    $site_id . '|pricing|' . $b2['batch_id'] );
add_option( $tombstone, array(
    'batch_id_sha256' => hash( 'sha256', $b2['batch_id'] ),
    'expected_chunks' => 2, 'manifest_sha256' => str_repeat( 'a', 64 )
), '', false );
$archiving = MAD4B_SCP_Activity_Import_Batches::verify( $base2 );
ck( is_wp_error( $archiving ) &&
    $archiving->get_error_code() === 'mad4b_batch_archival_in_progress',
    'Archived intent still permits ordinary source reads/approvals' );
$injected = MAD4B_SCP_Activity_Import_Batches::append(
    array_merge( $base2, array( 'chunk_index' => 1,
        'source' => imp08_part( 9999, 'injected' ) ) ) );
ck( is_wp_error( $injected ) &&
    $injected->get_error_code() === 'mad4b_batch_archival_in_progress',
    'Archived source manifest allowed a new chunk to race cleanup' );
delete_option( $manifest_key );
$closed = MAD4B_SCP_Activity_Import_Batches::archive(
    array_merge( $base2, array( 'confirmed' => true ) ) );
ck( !is_wp_error( $closed ) &&
    $closed['state'] === 'archived_after_interrupted_cleanup',
    'Interrupted archive could not recover exact tombstoned batch.' );
echo "PASS IMP08 encrypted bounded chunk intake, replay denial, exact complete plan, manual approval, archival and cross-chunk identity collision (ISOLATED PHP)\n";
