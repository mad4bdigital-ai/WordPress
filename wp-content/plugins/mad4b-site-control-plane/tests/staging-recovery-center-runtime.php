<?php
/** Read-only recovery journey; hermetic, no WordPress / network / filesystem writes. */
define( 'ABSPATH', __DIR__ );
function assert_recovery( $ok, $name ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: $name\n" ); exit( 1 ); } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-recovery-center.php';
$klass = 'MAD4B_SCP_Staging_Recovery_Center';
$site = array(
    'configured_environment' => 'staging', 'wordpress_environment' => 'staging',
    'origin_match' => true, 'environment_match' => true, 'authority_ready' => true,
    'mutation_pending_audit' => false, 'site_uuid' => 'e70d1e1a-d60f-426a-b250-484e24fc5d6d',
    'revision' => 9,
);
$bound = array( 'state' => 'ALREADY_HAS_PROFILE_BINDING', 'eligible' => false );
$verified = array( 'functional_scope_ready_for_census' => true,
    'site_identity_preserved' => true, 'site_uuid' => $site['site_uuid'],
    'profile_revision' => 9, 'state' => 'WORDPRESS_BINDING_READBACK_PASS' );
$current_write = array( 'write_ready' => true, 'candidate_binding' => array('match' => true) );
$state = $klass::decide( $site, $bound, $verified, $current_write, array( 'connected' => true, 'read_available' => true ) );
assert_recovery( 'IDENTITY_AND_WRITE_CURRENT' === $state['state'], 'existing binding + write reused' );
$pending = $klass::decide( $site, $bound, $verified, $current_write, array(
    'connected' => true, 'revocation_pending' => true, 'read_available' => false ) );
assert_recovery( 'GOOGLE_REVOCATION_PENDING' === $pending['state'] && !$pending['binding_recreated'],
    'pending external provider revoke kept isolated and blocked' );
$legacy = $klass::decide( $site, $bound, $verified, $current_write, array(
    'connected' => true, 'read_available' => true, 'quarantined_asset_record_count' => 4 ) );
assert_recovery( 'LEGACY_CONTEXT_OWNERSHIP_REVIEW' === $legacy['state'], 'legacy ownership not auto-assigned' );
assert_recovery( ! empty( $state['binding_reused'] ) && ! $state['binding_recreated']
    && ! $state['binding_approval_generated'], 'no repeated ticket or host key rotation' );
assert_recovery( false === $state['production_allowed'] && false === $state['breakglass_allowed'],
    'production and breakglass denied' );
$state = $klass::decide( $site, $bound, $verified, array(
    'write_ready' => false, 'candidate_binding' => array('match' => false) ));
assert_recovery( 'WRITE_REVIEW_REQUIRED' === $state['state']
    && 'mad4b/staging-write-authority-convergence-handshake' === $state['next_ability']
    && 'tools.php?page=mad4b-staging-write-owner-review' === $state['next_url'],
    'upgrade offers single GET-only existing review without re-enrolling host identity' );
assert_recovery( ! $state['binding_recreated'] && ! $state['binding_approval_generated']
    && ! $state['mutation_performed'] && ! $state['authorizing'],
    'simplified recovery cannot silently approve or mutate' );
$state = $klass::decide( $site, $bound, array(), $current_write );
assert_recovery( 'BINDING_READBACK_REQUIRED' === $state['state'],
    'binding must have independent readback' );
$state = $klass::decide( $site, $bound, array_merge( $verified,
    array('site_uuid' => 'other-site') ), $current_write );
assert_recovery( 'BINDING_READBACK_REQUIRED' === $state['state'],
    'wrong site readback rejected' );
$state = $klass::decide( $site, array('state' => 'READY_FOR_OWNER_APPROVAL', 'eligible' => true),
    array(), $current_write );
assert_recovery( 'BINDING_OWNER_APPROVAL_REQUIRED' === $state['state']
    && $state['binding_approval_generated'] === false
    && 'mad4b/deployment-binding-native-plan' === $state['next_ability'],
    'fresh binding uses existing ticket process, no ticket created' );
foreach ( array('STORED_HOST_BINDING_MISMATCH', 'LOCAL_KEY_EXISTS_NOT_REUSABLE',
    'PRIVATE_DIRECTORY_ALREADY_EXISTS', 'HOST_PARENT_NOT_WRITABLE', 'SAFE_LOCATION_UNAVAILABLE')
    as $blocked ) {
    $state = $klass::decide($site, array('state'=>$blocked), array(), $current_write);
    assert_recovery( 'BINDING_RECOVERY_BLOCKED' === $state['state'], 'fail closed: '.$blocked );
}
$prod = $site; $prod['configured_environment'] = 'production';
$state = $klass::decide( $prod, array('state' => 'READY_FOR_OWNER_APPROVAL', 'eligible'=>true), array(), $current_write );
assert_recovery( 'SITE_NOT_EXACT_STAGING' === $state['state'] && !$state['binding_approval_generated'], 'production never eligible' );
$stale = $site; $stale['origin_match'] = false;
assert_recovery( 'SITE_NOT_EXACT_STAGING' === $klass::decide( $stale, $bound, $verified, $current_write )['state'],
    'foreign origin never eligible' );
echo "STAGING_RECOVERY_CENTER: PASS (no mutation, no repeated binding approval)\n";
