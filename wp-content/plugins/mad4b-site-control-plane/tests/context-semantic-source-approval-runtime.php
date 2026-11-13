<?php
/**
 * Feature 007: provider-specific semantic classification for governed Google Drive.
 * PHP 7.4+; isolated and read-only; no WordPress, Drive, approvals or writes.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $value ) ); }
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-semantic-content-field-contracts.php';
function assert_semantic( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$c = 'MAD4B_SCP_Semantic_Content_Field_Contracts';
// Decision semantics depend on an exact persisted pending ticket, not a
// standalone field whitelist. This fixture is strictly read-only.
class MAD4B_SCP_Approval_Tickets {
    public static $ticket = array();
    public static function get( $id ) { return self::$ticket; }
}
MAD4B_SCP_Approval_Tickets::$ticket = array(
    'status' => 'pending', 'ticket_class' => 'mutation',
    'ability_name' => 'mad4b/content-job-create', 'server_id' => 'mad4b-write',
    'provider' => 'core', 'payload_sha256' => str_repeat( 'a', 64 ),
);
$approval_input = array(
    'ticket_id' => '30f924f8-dcdd-4420-8cfa-d7407c1136aa',
    'decision' => 'approve',
    'expected_payload_sha256' => str_repeat( 'a', 64 ),
    'expected_classification_sha256' => str_repeat( 'b', 64 ),
    'operation_input' => array(
        'brand_id' => 'allroyalegypt_wp', 'subject' => 'Royal Lily staging test intake',
        'language' => 'en', 'country' => 'eg', 'content_type' => 'tour',
        'target_post_type' => 'tours-and-activities',
        'reason' => 'Preserve task while Brand Core is reviewed',
    ),
    'review_note' => 'Reviewed exact-source Staging-only Cron build; one-time approval ticket required.',
);
$approval = $c::classify( 'mad4b/approval-ai-decide', $approval_input );
if ( empty( $approval['field_contract_known'] ) || ! empty( $approval['review_required'] )
    || ! empty( $approval['required'] ) )
    throw new RuntimeException( 'Native-build approval metadata was misclassified as brand-bearing content' );
$approval_input['operation_input']['post_content'] = 'Unreviewed brand text.';
$unsafe_approval = $c::classify( 'mad4b/approval-ai-decide', $approval_input );
if ( empty( $unsafe_approval['review_required'] ) || ! empty( $unsafe_approval['mutation_classification_ready'] ) )
    throw new RuntimeException( 'Nested unreviewed brand content bypassed semantic field protection' );

$abilities = $c::declared_abilities();
$examples = array(
    'context/governed-source-upsert' => array(
        'external_root_id' => 'drive-folder-123',
        'label' => 'AllRoyalEgypt Brand — Original reviewed folder',
        'recursive' => false, 'recursive_scope_confirmed' => false,
        'write_policy' => 'read_only', 'write_policy_confirmed' => false,
        'expected_registry_revision' => 6,
        'expected_authority_manifest_fingerprint' => str_repeat( 'a', 64 ),
    ),
    'context/legacy-owner-transfer-apply' => array(
        'source_id' => str_repeat( 'b', 64 ),
        'expected_plan_sha256' => str_repeat( 'c', 64 ),
        'expected_brand_id' => str_repeat( 'd', 32 ),
        'reviewed_external_root_id' => 'drive-folder-123',
        'owner_evidence_reference' => 'Owner-provided Google Drive metadata, exact source ID, and original administrator audit evidence reference.',
        'expected_provider_proof_sha256' => str_repeat( 'e', 64 ),
        'confirmation' => 'APPROVE EXACT UNBOUND BRAND TRANSFER',
    ),
    'context/remote-owner-review-apply' => array(
        'asset_id' => str_repeat( 'f', 64 ),
        'expected_plan_sha256' => str_repeat( '1', 64 ),
        'decision' => 'approve',
        'confirmation' => 'I REVIEWED EXACT BRAND SOURCE',
        'review_note' => 'The original owner reviewed exact source provenance and approves this reviewed asset only, with its version-bound audit trail.',
    ),
);
foreach ( $examples as $name => $input ) {
    assert_semantic( in_array( $name, $abilities, true ), $name . ' missing from registered semantic contracts' );
    $r = $c::classify( $name, $input );
    assert_semantic( ! empty( $r['field_contract_known'] ) && ! empty( $r['mutation_classification_ready'] ), $name . ' was not explicitly classified' );
    assert_semantic( empty( $r['required'] ) && empty( $r['review_required'] ) && empty( $r['matched_fields'] ), $name . ' source metadata must not masquerade as brand body copy' );
    assert_semantic( 'google_drive_context' === $r['provider_id'], $name . ' wrong provider' );
    assert_semantic( empty( $r['fallback_evidence_authorizing'] ) && 'none' === $r['authority_effect'], $name . ' attempted to mint authority' );
    $unknown = $input;
    $unknown['unreviewed_page_content'] = str_repeat( 'A new unreviewed brand promise. ', 6 );
    $guard = $c::classify( $name, $unknown );
    assert_semantic( ! empty( $guard['review_required'] ) && empty( $guard['mutation_classification_ready'] ), $name . ' accepted unclassified page content' );
    assert_semantic( ! empty( $guard['fallback_evidence_fields'] ), $name . ' failed to surface unknown content field' );
}
// Content text remains brand-bearing even in draft-only or provider-managed flows.
$text_ops = array(
    'context/brand-draft-create' => array( 'category'=>'tone_of_voice', 'content'=>'A reviewed, sourced draft.', 'evidence_digest'=>str_repeat('b',64), 'expected_plan_sha256'=>str_repeat('a',64) ),
    'context/brand-draft-append' => array( 'category'=>'editorial_guidelines', 'content'=>'A review-only addition.', 'draft_preflight_sha256'=>str_repeat('a',64), 'evidence_digest'=>str_repeat('b',64), 'expected_plan_sha256'=>str_repeat('c',64) ),
    'context/brand-profile-save' => array( 'brand_name'=>'All Royal Egypt', 'expected_brand_id'=>str_repeat('a',32), 'expected_revision'=>4 ),
    'context/create-drive-asset' => array( 'source_id'=>str_repeat('b',64), 'name'=>'All Royal Egypt editorial notes', 'content'=>'This file is still subject to exact source review.', 'format'=>'markdown' ),
    'context/update-drive-asset' => array( 'asset_id'=>str_repeat('b',64), 'content'=>'An updated text requiring provenance.', 'expected_content_hash'=>str_repeat('c',64) ),
    'context/recreate-drive-asset' => array( 'asset_id'=>str_repeat('b',64), 'content'=>'Recreated material requires original evidence.', 'format'=>'markdown' ),
);
foreach ( $text_ops as $name => $input ) {
    $r = $c::classify( $name, $input );
    assert_semantic( ! empty( $r['field_contract_known'] ) && ! empty( $r['required'] ) && ! empty( $r['mutation_classification_ready'] ), $name . ' must retain explicit brand text classification' );
    assert_semantic( empty( $r['review_required'] ) && 'google_drive_context' === $r['provider_id'], $name . ' must use its exact provider contract' );
    assert_semantic( ! empty( $r['matched_fields'] ) && empty( $r['fallback_evidence_authorizing'] ), $name . ' unexpectedly authorized fallback brand text' );
}
foreach ( array( 'context/materialize-brand-draft', 'context/reconcile-brand-materialization' ) as $name ) {
    $input = array( 'artifact_id'=>str_repeat('b',64), 'source_id'=>str_repeat('c',64), 'format'=>'markdown', 'expected_draft_content_sha256'=>str_repeat('d',64) );
    $r = $c::classify( $name, $input );
    assert_semantic( ! empty( $r['field_contract_known'] ) && ! empty( $r['mutation_classification_ready'] ) && empty( $r['required'] ), $name . ' must classify only exact indirect artifact identifiers' );
    $input['unreviewed_body_content'] = str_repeat( 'Unreviewed content is never accepted. ', 4 );
    $unsafe = $c::classify( $name, $input );
    assert_semantic( ! empty( $unsafe['review_required'] ) && empty( $unsafe['mutation_classification_ready'] ), $name . ' must reject injected unreviewed content' );
}
$brand = $c::classify( 'mad4b/content-create-post', array( 'post_type'=>'tours-and-activities', 'post_title'=>'Unreviewed Tour Draft' ) );
assert_semantic( ! empty( $brand['required'] ) && ! empty( $brand['mutation_classification_ready'] ), 'Content create must retain brand-text context requirement' );
$unknown = $c::classify( 'provider/unregistered-operation', array( 'content'=>'unreviewed' ) );
assert_semantic( empty( $unknown['field_contract_known'] ) && ! empty( $unknown['review_required'] ) && empty( $unknown['mutation_classification_ready'] ), 'Unknown provider remains fail-closed' );
echo "PASS: Drive source registration, exact owner transfer and asset review semantic metadata classified; brand text and unknown fields remain guarded\n";
