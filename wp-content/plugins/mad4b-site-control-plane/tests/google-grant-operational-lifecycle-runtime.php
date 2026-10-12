<?php
/** A healthy Google grant must not be mistaken for authority to write Context. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php';
$check = static function ( $value, $description ) {
    if ( ! $value ) { fwrite( STDERR, 'FAIL: ' . $description . PHP_EOL ); exit( 1 ); }
};
$healthy = array( 'connected' => true, 'write_available' => true,
    'credential_fresh' => true, 'refresh_required' => false );
$zero = array( 'selected' => 0, 'create' => 0, 'update' => 0, 'recreate' => 0 );
$result = MAD4B_SCP_Google_Drive_Context::grant_operational_lifecycle( $healthy, $zero );
$check( $result['oauth']['state'] === 'healthy'
    && $result['context_source']['state'] === 'source_not_selected'
    && $result['operations']['update']['source_policy_eligible'] === false
    && $result['execution_authorized'] === false, 'Google full grant does not create source or mutation authority' );
$with_source = $zero;
$with_source['selected'] = 1;
$with_source['update'] = 1;
$with_source['recreate'] = 1;
$result = MAD4B_SCP_Google_Drive_Context::grant_operational_lifecycle( $healthy, $with_source );
$check( $result['operations']['update']['source_policy_eligible'] === true
    && $result['operations']['update']['provider_certification_still_required'] === true
    && $result['operations']['recreate']['rollback_receipt_still_required'] === true
    && $result['operations']['create']['source_policy_eligible'] === false
    && $result['execution_authorized'] === false, 'Source policy is necessary but not sufficient' );
$stale = $healthy;
$stale['refresh_required'] = true;
$result = MAD4B_SCP_Google_Drive_Context::grant_operational_lifecycle( $stale, $zero );
$check( $result['oauth']['state'] === 'refresh_on_next_provider_request'
    && ! $result['oauth']['reconnect_required'], 'Expired access token does not force reconnect' );
$denied = $healthy;
$denied['write_available'] = false;
$result = MAD4B_SCP_Google_Drive_Context::grant_operational_lifecycle( $denied, $with_source );
$check( $result['oauth']['state'] === 'grant_unavailable'
    && $result['operations']['update']['source_policy_eligible'] === false
    && $result['operations']['recreate']['source_policy_eligible'] === false, 'OAuth scopes cannot be inferred from folder grants' );
echo "PASS: Google grant lifecycle, token refresh state, source boundary and create fail-closed\n";
