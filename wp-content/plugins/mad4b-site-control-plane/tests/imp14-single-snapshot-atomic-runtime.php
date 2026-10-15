<?php
/** IMP14 source-only isolated snapshot/approval/archive/nonce concurrency. */
require __DIR__ . '/imp01-import-preview-runtime.php';

$active = get_option( $storeKey, false );
ck( is_array( $active ) &&
    isset( $active['snapshot_sha256'], $active['payload_sha256'] ),
    'Fixture has no exact staged commercial snapshot' );
$slug = 'pricing';
$sha = $active['snapshot_sha256'];
$payload = $active['payload_sha256'];
$key = MAD4B_SCP_Batch_Atomic_Mutex::key( $slug );
$lease = MAD4B_SCP_Batch_Atomic_Mutex::acquire( $slug, 'archive' );
ck( !is_wp_error( $lease ), 'Could not acquire single-source archive mutex' );
$blocked = MAD4B_SCP_Activity_Import_Snapshot::export_approved_csv( $slug, $sha );
ck( is_wp_error( $blocked ) &&
    $blocked->get_error_code() === 'mad4b_batch_mutation_locked',
    'CSV download bypassed a held source archival lock' );
$blocked_archive = MAD4B_SCP_Activity_Import_Snapshot::archive_exact_review(
    $slug, $payload, $sha );
ck( is_wp_error( $blocked_archive ) &&
    $blocked_archive->get_error_code() === 'mad4b_batch_mutation_locked',
    'Single-source archived an in-flight export/approval' );
ck( true === MAD4B_SCP_Batch_Atomic_Mutex::release( $lease ),
    'Original archive lock owner could not release' );
$archived = MAD4B_SCP_Activity_Import_Snapshot::archive_exact_review(
    $slug, $payload, $sha );
ck( !is_wp_error( $archived ) &&
    $archived['archived'] && $archived['audit_recorded'] &&
    false === get_option( $storeKey, false ),
    'Archived single snapshot not independently cleared' );
$blocked_approval = MAD4B_SCP_Activity_Import_Snapshot::approval( $slug, $sha );
ck( is_wp_error( $blocked_approval ),
    'Archived snapshot approval became reusable' );
$second = MAD4B_SCP_Activity_Import_Snapshot::archive_exact_review(
    $slug, $payload, $sha );
ck( !is_wp_error( $second ) && $second['already_cleaned'],
    'Identical source archive could not be idempotently retried' );
$wrong = MAD4B_SCP_Activity_Import_Snapshot::archive_exact_review(
    $slug, str_repeat( 'f', 64 ), $sha );
ck( is_wp_error( $wrong ) &&
    $wrong->get_error_code() === 'mad4b_import_archive_audit_mismatch',
    'Existing archival identity could be replaced by another source' );
$nonceKey = 'mad4b_import_nonce_' . hash( 'sha256', 'imp14-test-unique-nonce' );
$first = MAD4B_SCP_Batch_Atomic_Mutex::reserve_signed_nonce(
    $nonceKey, time() );
$duplicate = MAD4B_SCP_Batch_Atomic_Mutex::reserve_signed_nonce(
    $nonceKey, time() );
ck( true === $first && is_wp_error( $duplicate ) &&
    $duplicate->get_error_code() === 'mad4b_import_webhook_replay',
    'Concurrent-like duplicate signed nonce overwritten the first marker' );
echo "PASS IMP14 single-source archive vs export, replay-safe source approvals and immutable HMAC nonce reservation (ISOLATED PHP)\n";
