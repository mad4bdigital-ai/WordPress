<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-mcp-connection-stage-diagnostics.php';
function check( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); }
$class = 'MAD4B_SCP_MCP_Connection_Stage_Diagnostics';
$empty = $class::project( array() );
check( $empty['root_cause_determined'] === false
    && $empty['first_observed_local_blocker'] === ''
    && $empty['stages']['oauth_configuration']['status'] === 'not_checked'
    && $empty['stages']['catalog_materialization']['status'] === 'not_checked'
    && $empty['stages']['mcp_initialize']['status'] === 'not_checked',
    'Unobserved stages must never appear ready or proven broken' );
$inputs = array(
    'bootstrap'=>array( 'ready'=>true ),
    'oauth'=>array( 'environment_ready'=>true, 'local_oauth_effective'=>true,
        'bridge_effective'=>true, 'cimd_ready'=>true ),
    'registration'=>array( 'actual_registered'=>true, 'deep_registration_deferred'=>false ),
    'catalog'=>array( 'ready'=>true ),
);
$ready = $class::project($inputs);
foreach ( array('bootstrap','oauth_configuration','server_registration','catalog_materialization') as $key )
    check($ready['stages'][$key]['status']==='ready_local', 'Local stage not observed as locally ready: '.$key);
foreach ( array('external_bearer_validation','mcp_initialize','mcp_tools_list','mcp_read_call','mcp_write_call') as $key )
    check($ready['stages'][$key]['status']==='not_checked', 'External call falsely certified: '.$key);
check( !$ready['authorizing'] && !$ready['mutating'] && !$ready['external_connection_certified']
    && !$ready['profile_comparison_proven'], 'Diagnostic may not grant or certify anything' );
$registration_deferred = $inputs;
$registration_deferred['registration'] = array( 'actual_registered'=>false, 'deep_registration_deferred'=>true );
$reg = $class::project( $registration_deferred );
check( $reg['stages']['server_registration']['status']==='not_checked',
    'Deferred registration must not be falsely classified as a route failure' );
$no_catalog = $inputs;
$no_catalog['catalog'] = array( 'ready'=>false );
check( $class::project($no_catalog)['first_observed_local_blocker']==='catalog_materialization',
    'A local catalog blocker must be distinct from OAuth and transport' );
$failed_oauth = $inputs;
$failed_oauth['oauth']['bridge_effective'] = false;
$fail = $class::project($failed_oauth);
check( $fail['first_observed_local_blocker']==='oauth_configuration'
    && $fail['stages']['catalog_materialization']['status']==='ready_local'
    && !$fail['root_cause_determined'], 'OAuth configuration failure must not masquerade as catalog proof' );
$failed_boot = $failed_oauth;
$failed_boot['bootstrap']['ready'] = false;
check( $class::project($failed_boot)['first_observed_local_blocker']==='bootstrap',
    'Earlier bootstrap blockage must have priority' );
$false_reg = $inputs;
$false_reg['registration'] = array('actual_registered'=>false);
check( $class::project($false_reg)['first_observed_local_blocker']==='server_registration',
    'Explicit registration failure must be visible' );
$invalid_reg = $inputs;
$invalid_reg['registration'] = array('actual_registered'=>'no');
check( $class::project($invalid_reg)['stages']['server_registration']['status']==='not_checked',
    'Untrusted evidence type must not be treated as boolean verification' );
echo "MCP_CONNECTION_STAGES: PASS (bootstrap/OAuth/registration/catalog separation and external not-checked)\n";
