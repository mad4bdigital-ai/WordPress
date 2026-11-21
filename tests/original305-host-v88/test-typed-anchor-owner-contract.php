<?php
/** Source-level isolated first-party Host Anchor v2 Owner/Provider tests.
 * No WordPress bootstrap, keys, network, storage or actual Broker requests.
 */
error_reporting(E_ALL);
define('ABSPATH','/source/');
define('WP_PLUGIN_DIR','/source/wp-content/plugins');
define('WPMU_PLUGIN_DIR','/source/wp-content/mu-plugins');
define('MAD4B_MU_MODULAR_LOADER_BOOTED',true);
function add_action(...$args){return true;}
function do_action(...$args){return true;}
function get_option($key,$default=false){return $default;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
require_once '/source/wp-content/plugins/mad4b-platform/modules/integrations/344-mad4b-original-native-operation-providers.php';
require_once '/source/wp-content/plugins/mad4b-platform/modules/compat/305-mad4b-central-ssh-command-intake.php';
require_once '/source/wp-content/plugins/mad4b-platform/modules/compat/306-mad4b-central-vps-broker.php';
$checks=0;
function eq($got,$expected,$message) {
    global $checks;
    if($got!==$expected)throw new Exception($message.' got '.var_export($got,true));
    $checks++;
}
$names=['company.host-authority.anchor.issue','company.host-authority.anchor.revoke'];
foreach($names as $operation){
    $profile=MAD4B_Original_Native_Operation_Providers_V1::profile($operation);
    eq(is_array($profile),true,'Provider did not register');
    eq($profile['target_scope'],'host','Wrong target scope');
    eq($profile['command_format'],'host.anchor.ref.v2','Host format drift');
    $discovery=MAD4B_Original_Native_Operation_Providers_V1::discover($operation);
    eq($discovery['provider_count'],1,'Provider discovery');
    eq($discovery['operations'][0]['provider_capability_ready'],false,'Unsigned issuer must not be ready');
    eq(MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
        $operation,'host','host.anchor.ref.v2'),false,'Unsigned provider granted host access');
}
$op=$names[0];
$sha=str_repeat('a',64);
$grant=['site_uuid'=>'49c562d1-8f2f-456f-b454-26816c6ba4cb',
        'target_host_id'=>'hosting-2'];
$proof=[
 'authority_mode'=>'bootstrap','operation_id'=>$op,'plan_sha256'=>$sha,
 'site_uuid'=>$grant['site_uuid'],'target_host_id'=>$grant['target_host_id']
];
$argv=['mad4b','host-anchor','issue','--plan_sha256='.$sha];
$method='MAD4B_Central_SSH_Command_Intake_V1';
$valid=$method::host_anchor_v2_exact_reference($op,$argv,$proof,$grant,null,'host');
eq(is_array($valid),true,'Valid host bootstrap reference');
eq($valid['format'],'host.anchor.ref.v2','Wrong format');
eq($valid['command_sha256'],hash('sha256',json_encode($argv,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'Canonical hash drift');
eq($valid['command_byte_length'],strlen(json_encode($argv,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'Canonical bytes drift');
$bad=[
 [$op,['mad4b','host-anchor','revoke','--plan_sha256='.$sha],$proof,$grant,null,'host'],
 [$op,['mad4b','host-anchor','issue','--plan_sha256='.$sha,'--user=root'],$proof,$grant,null,'host'],
 [$op,$argv,$proof,$grant,'rm -rf','host'],
 [$op,$argv,$proof,$grant,null,'wordpress'],
 [$op,$argv, array_merge($proof,['extra'=>'x']),$grant,null,'host'],
 [$op,$argv, array_replace($proof,['operation_id'=>$names[1]]),$grant,null,'host'],
 [$op,$argv, array_replace($proof,['site_uuid'=>str_repeat('f',36)]),$grant,null,'host'],
 [$op,$argv, array_replace($proof,['plan_sha256'=>'ff']),$grant,null,'host'],
 [$op,$argv,$proof,array_replace($grant,['target_host_id'=>'srv2030908']),null,'host'],
 [$names[1],['mad4b','host-anchor','revoke','--plan_sha256='.$sha],
    array_replace($proof,['operation_id'=>$names[1]]),$grant,null,'host'],
];
foreach($bad as $index=>$args){
    eq($method::host_anchor_v2_exact_reference(...$args),null,'Unsafe Host Anchor request admitted '.$index);
}
$revoke=array_replace($proof,['authority_mode'=>'operational','operation_id'=>$names[1]]);
$goodRevoke=$method::host_anchor_v2_exact_reference($names[1],
    ['mad4b','host-anchor','revoke','--plan_sha256='.$sha],$revoke,$grant,null,'host');
eq(is_array($goodRevoke),true,'Revoke contract lost');
$goodOperational=array_replace($proof,['authority_mode'=>'operational']);
eq(is_array($method::host_anchor_v2_exact_reference($op,$argv,$goodOperational,$grant,null,'host')),true,'Operational rotate contract');
// Original306 must deterministically project only the owner-approved
// original305 exact request with an independently matched command SHA.
$approvedSource=[
 'operation'=>$op,'site_uuid'=>$grant['site_uuid'],
 'canonical_origin'=>'https://staging.allroyalegypt.com',
 'environment'=>'staging','target_host_id'=>$grant['target_host_id'],
 'target_ssh_principal'=>'u948953904','target_scope'=>'host',
 'command_format'=>'host.anchor.ref.v2','grant_mode'=>'persistent',
 'command_sha256'=>$valid['command_sha256'],
 'host_anchor_identity'=>$proof,
];
$broker='MAD4B_Central_VPS_Broker_V1';
$projected=$broker::original_host_v2_job_projection($approvedSource);
eq(is_array($projected),true,'Signed host v2 original Job shape');
eq($projected['operation_id'],$op,'Operation drift in original Job');
eq($projected['principal_type'],'signed_host_principal_v2','Host v2 principal type');
eq($projected['origin'],$approvedSource['canonical_origin'],'Origin drift');
eq($projected['plan_sha256'],$sha,'Immutable root SHA not carried');
eq($projected['authority_mode'],'bootstrap','First Anchor bootstrap lost');
eq($projected['host_anchor_issuer_commissioned'],false,'Provider declaration cannot commission issuer');
eq($projected['original313_receipt_signed'],false,'Job cannot mint independent Original313');
$invalidSources=[
 array_replace($approvedSource,['command_sha256'=>str_repeat('f',64)]),
 array_replace($approvedSource,['target_scope'=>'wordpress']),
 array_replace($approvedSource,['command_format'=>'wpcli.argv.json.v1']),
 array_replace($approvedSource,['grant_mode'=>'one_time']),
 array_replace($approvedSource,['target_host_id'=>'srv2030908']),
 array_replace($approvedSource,['target_ssh_principal'=>'root']),
 array_replace($approvedSource,['canonical_origin'=>'http://staging.allroyalegypt.com']),
 array_replace($approvedSource,['host_anchor_identity'=>array_replace($proof,
     ['site_uuid'=>str_repeat('f',36)])]),
 array_replace($approvedSource,['operation'=>$names[1]]),
 array_replace($approvedSource,['host_anchor_identity'=>array_replace($proof,
     ['authority_mode'=>'invalid'])]),
 array_replace($approvedSource,['host_anchor_identity'=>array_merge($proof,
     ['unexpected_arg'=>'yes'])]),
];
foreach($invalidSources as $index=>$candidate)
    eq($broker::original_host_v2_job_projection($candidate),null,
       'Original306 accepted tampered 305 request '.$index);
$revokeSource=array_replace($approvedSource,[
  'operation'=>$names[1],
  'command_sha256'=>hash('sha256',json_encode(
      ['mad4b','host-anchor','revoke','--plan_sha256='.$sha],
       JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
  'host_anchor_identity'=>$revoke,
]);
$revokeFields=$broker::original_host_v2_job_projection($revokeSource);
eq(is_array($revokeFields),true,'Original306 revoke job projection');
eq($revokeFields['operation_id'],$names[1],'Revoke operation drift');

echo json_encode([
 'state'=>'PASS',
 'checks'=>$checks,
 'new_broker_jobs'=>0,
 'new_grants'=>0,
 'anchors_issued'=>0,
 'signed_original312'=>false,
 'original313_certified'=>false,
 'original305_host_v2_provider_ready'=>false,
],JSON_UNESCAPED_SLASHES).PHP_EOL;
