<?php
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-nonproduction-approved-grants.php';
$p = array( 'auto_convergence_eligible' => true, 'environment' => 'staging' );
$b = array( 'seal_verified' => true, 'captured_before_update' => true, 'audit_verified' => true,
    'site_uuid' => 'site', 'agent_public_id' => 'agent', 'profile_digest' => 'digest',
    'previous_package_sha' => 'previous', 'approved_grants' => array(
        array( 'server_id' => 'mad4b-write', 'ability' => 'media/update-metadata', 'provider' => 'media' ),
    ) );
$c = array( 'exact_profile_bound' => true, 'manifest_verified' => true, 'agent_verified' => true,
    'write_inventory_verified' => true, 'breakglass_enabled' => false, 'wildcard_grants' => false,
    'explicit_denies' => false, 'site_uuid' => 'site', 'agent_public_id' => 'agent',
    'profile_digest' => 'digest', 'source_commit_sha' => 'new', 'approved_update_from_sha' => 'previous' );
$m = $b['approved_grants'];
$run = static function ( $p, $b, $c, $m, $expected, $reason = '' ) {
    $r = MAD4B_SCP_Nonproduction_Approved_Grants::evaluate( $p, $b, $c, $m );
    if ( $r['eligible'] !== $expected || $r['reason'] !== $reason || $r['mutation_performed'] !== false )
        throw new RuntimeException( 'Unexpected grant gate: ' . json_encode( $r ) );
};
$run($p,$b,$c,$m,true);
$p['environment']='production';$run($p,$b,$c,$m,false,'nonproduction_authority_required');$p['environment']='staging';
$b['seal_verified']=false;$run($p,$b,$c,$m,false,'sealed_prior_approval_required');$b['seal_verified']=true;
$c['manifest_verified']=false;$run($p,$b,$c,$m,false,'current_authority_unverified');$c['manifest_verified']=true;
$c['site_uuid']='other';$run($p,$b,$c,$m,false,'source_identity_drift');$c['site_uuid']='site';
$m[]=array('server_id'=>'mad4b-write','ability'=>'content/new-ability','provider'=>'core');
$run($p,$b,$c,$m,false,'grant_not_previously_approved');array_pop($m);
$m[]=$m[0];$run($p,$b,$c,$m,false,'invalid_or_duplicate_grant_pairs');
echo "NONPRODUCTION_APPROVED_GRANTS: PASS (7 cases)\n";
