<?php
/** Hermetic consent projection UI denials. No WordPress boot, OAuth mutation or host probe. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-oauth-consent-projection-view.php';
function t( $name, $ok ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $name . PHP_EOL ); exit( 1 ); } echo 'PASS ' . $name . PHP_EOL; }
$good = array(
    array( 'ability' => 'mad4b/content-create-post', 'provider' => 'core' ),
    array( 'ability' => 'mad4b/health-check', 'provider' => 'site-profile' ),
);
$r = MAD4B_SCP_OAuth_Consent_Projection_View::safe_list( $good );
t( 'names preserved in order', count( $r['rows'] ) === 2 && 'mad4b/content-create-post' === $r['rows'][0]['ability'] );
t( 'providers preserved', $r['rows'][1]['provider'] === 'site-profile' );
t( 'valid rows no false invalid', 0 === $r['invalid'] && 0 === $r['omitted'] );
$r = MAD4B_SCP_OAuth_Consent_Projection_View::safe_list( array_merge( $good, array(
    array( 'ability' => '', 'provider' => 'core' ),
    array( 'ability' => "mad4b/test\n", 'provider' => 'core' ),
    array( 'ability' => 'mad4b/bad', 'provider' => '' ),
    array( 'provider' => 'core' ),
    'not a row',
) ) );
t( 'malformed names never yield blank entries', count( $r['rows'] ) === 2 && 5 === $r['invalid'] );
$r = MAD4B_SCP_OAuth_Consent_Projection_View::safe_list( $good, 1 );
t( 'presentation budget shows omitted count', 1 === count( $r['rows'] ) && 1 === $r['omitted'] );
$r = MAD4B_SCP_OAuth_Consent_Projection_View::safe_list( false );
t( 'nonarray projection fails safe', 0 === count( $r['rows'] ) && 1 === $r['invalid'] );
$ready = array( 'process_backend_ready' => true, 'normal_no_network_execution_ready' => true,
    'process_backend_blockers' => array(), 'normal_no_network_execution_blockers' => array() );
$good_host = MAD4B_SCP_OAuth_Consent_Projection_View::developer_execution( true, $ready );
t( 'host prerequisite presence is not execution certification', true === $good_host['host_prerequisites_ready'] && false === $good_host['host_execution_ready'] && false === $good_host['operational_ready'] );
t( 'uncertified host forces independent canary', 'NOT_CERTIFIED' === $good_host['host_execution_certification'] && in_array( 'host_behavior_uncertified', $good_host['blockers'], true ) );
t( 'view cannot confer authority', false === $good_host['authorizing'] && false === $good_host['mutation_performed'] && false === $good_host['host_installation_performed'] );
$unauthorized = MAD4B_SCP_OAuth_Consent_Projection_View::developer_execution( false, $ready );
t( 'ready host is not OAuth authority', false === $unauthorized['operational_ready'] && true === $unauthorized['host_prerequisites_ready'] && false === $unauthorized['authority_ready'] );
$bad_host = array( 'process_backend_ready' => false, 'normal_no_network_execution_ready' => false,
    'process_backend_blockers' => array( 'resource_limiter_unavailable' ),
    'normal_no_network_execution_blockers' => array( 'resource_limiter_unavailable', 'network_isolation_unavailable', "<script>" ) );
$blocked = MAD4B_SCP_OAuth_Consent_Projection_View::developer_execution( true, $bad_host );
t( 'authorized host remains operationally blocked', true === $blocked['authority_ready'] && false === $blocked['operational_ready'] );
t( 'host blockers deduplicate and sanitize', $blocked['blockers'] === array( 'resource_limiter_unavailable', 'network_isolation_unavailable' ) );
$unknown = MAD4B_SCP_OAuth_Consent_Projection_View::developer_execution( true, array() );
t( 'missing host evidence fails closed', false === $unknown['operational_ready'] && in_array( 'host_execution_not_certified', $unknown['blockers'], true ) );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-server.php' );
$consent = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-consent-ui.php' );
t( 'live host execution blockers exposed', is_string( $source ) && false !== strpos( $source, "'developer_execution_blockers'" ) );
t( 'stale readback explicitly blocks operational display', is_string( $source ) && false !== strpos( $source, 'Readback unavailable' ) );
t( 'stale fetch timestamp updates on unchanged fingerprint', is_string( $source ) && false !== strpos( $source, 'stamp.textContent=x.data.projection.observed_at' ) );
t( 'grant list always reports missing labels', is_string( $source ) && false !== strpos( $source, 'No displayable grant names' ) );
t( 'OAuth consent labels executable host separately', is_string( $consent ) && false !== strpos( $consent, 'Developer host execution' ) );
t( 'consent remains OAuth server POST, unchanged submission', is_string( $source ) && false !== strpos( $source, 'name="decision" value="approve"' ) );
echo 'OAUTH_CONSENT_PROJECTION_VIEW: PASS' . PHP_EOL;
