<?php
/** Pure three-transport authorization contract. No WP, DB, Shell or HTTP. */
declare(strict_types=1);
if ( ! defined('ABSPATH') ) define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    public function __construct($c,$m=''){$this->code=$c;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function check($pass,$message){
    if(!$pass){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-unified-operation-authority.php';
$c='MAD4B_SCP_Unified_Operation_Authority';
$agent='11111111-2222-4333-8444-555555555555';
$site='22222222-3333-4444-8555-666666666666';
$principal=array(
  'authenticated'=>true,
  'transport_verified'=>true,
  'subject_fingerprint'=>str_repeat('a',64),
  'agent_public_id'=>$agent,
  'site_uuid'=>$site,
  'target_environment'=>'production'
);
$operation=array(
  'site_uuid'=>$site,
  'target_environment'=>'production',
  'server_id'=>'mad4b-wordpress',
  'provider'=>'core',
  'ability_name'=>'mad4b/plugin-remote-update-apply',
  'command_sha256'=>str_repeat('b',64)
);
$grant=array(
  'verified_by_registry'=>true,
  'effect'=>'allow',
  'status'=>'enabled',
  'agent_public_id'=>$agent,
  'site_uuid'=>$site,
  'environment'=>'production',
  'server_id'=>'mad4b-wordpress',
  'provider'=>'core',
  'ability_name'=>'mad4b/plugin-remote-update-apply',
  'command_sha256'=>str_repeat('b',64),
  'valid_from'=>time()-100,
  'valid_until'=>time()+3600,
  'company_delegation_verified'=>true,
  'revocation_checked'=>true,
  'deny_override_checked'=>true,
  'production_runtime_gate_verified'=>true
);
$channels=array(
 'oauth_mcp'=>'identity_context_agent_registry',
 'vps_ssh_wpcli'=>'host_verified_ssh_wpcli_identity',
 'signed_broker'=>'central_authority_verifier_312'
);
foreach($channels as $channel=>$verifier){
    $p=$principal;
    $p['auth_channel']=$channel;
    $p['verified_by']=$verifier;
    $r=$c::assess_trusted_boundary($p,$operation,$grant);
    check($r['eligible']===true && !$r['blockers'],'same exact grant works for '.$channel);
    check($r['execution_authorized']===false && $r['dispatch_eligible']===false &&
          $r['mutation_performed']===false,'authority assessment cannot execute Shell');
    check($r['same_policy_across_auth_channels']===true,'single consistent policy');

    $wrong=$p;$wrong['verified_by']='oauth_token_from_client';
    check(in_array('transport_identity_unverified',$c::assess_trusted_boundary($wrong,$operation,$grant)['blockers'],true),
          'forged verifier for '.$channel.' refused');
    $wrong=$p;$wrong['transport_verified']=false;
    check(in_array('transport_identity_unverified',$c::assess_trusted_boundary($wrong,$operation,$grant)['blockers'],true),
          'unauthenticated transport for '.$channel.' refused');
    $g=$grant;$g['effect']='deny';
    check(in_array('exact_active_grant_not_verified',$c::assess_trusted_boundary($p,$operation,$g)['blockers'],true),
          'exact deny must override allow '.$channel);
    $g=$grant;$g['revocation_checked']=false;
    check(in_array('delegation_or_deny_first_state_unverified',$c::assess_trusted_boundary($p,$operation,$g)['blockers'],true),
          'stale grant not eligible '.$channel);
    $o=$operation;$o['site_uuid']='33333333-4444-4555-8666-777777777777';
    check(in_array('company_or_environment_mismatch',$c::assess_trusted_boundary($p,$o,$grant)['blockers'],true),
          'cross-company attempt refused '.$channel);
    $o=$operation;$o['command_sha256']=str_repeat('c',64);
    check(in_array('exact_active_grant_not_verified',$c::assess_trusted_boundary($p,$o,$grant)['blockers'],true),
          'changed command digest refused '.$channel);
}
$p=$principal;$p['auth_channel']='signed_broker';$p['verified_by']='central_authority_verifier_312';
$g=$grant;$g['valid_until']=time()-1;
check(in_array('grant_expired_or_unbounded',$c::assess_trusted_boundary($p,$operation,$g)['blockers'],true),'expired grant rejected');
$g=$grant;$g['valid_until']=null;$g['standing_grant']=true;
check($c::assess_trusted_boundary($p,$operation,$g)['eligible']===true,'revocable standing grant supported');
$g['revocation_checked']=false;
check(!$c::assess_trusted_boundary($p,$operation,$g)['eligible'],'standing grant revoked state cannot be skipped');
$g=$grant;$g['production_runtime_gate_verified']=false;
check(in_array('production_runtime_authority_not_verified',$c::assess_trusted_boundary($p,$operation,$g)['blockers'],true),
'Production requires environment-specific runtime gate');
$o=$operation;$o['target_environment']='staging';
$p['target_environment']='staging';
$g['environment']='staging';
check($c::assess_trusted_boundary($p,$o,$g)['eligible'],'Staging and Production use same operation policy after exact environment binding');
$p=$principal;$p['auth_channel']='wp_cli';$p['verified_by']='host_verified_ssh_wpcli_identity';
check(!$c::assess_trusted_boundary($p,$operation,$grant)['eligible'],'user supplied WP CLI claim is not proof');

$r=$c::current_mcp_identity_plan($operation);
check(is_wp_error($r) && $r->get_error_code()==='mad4b_unified_authority_backend_missing','missing server identity backend rejected');

echo 'MAD4B_UNIFIED_OPERATION_AUTHORITY: PASS'.PHP_EOL;
