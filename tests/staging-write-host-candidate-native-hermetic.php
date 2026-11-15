<?php
/** Original governed candidate audit normalization; hermetic, no writes. */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
class WP_Error {
    private $code;
    public function __construct($code,$message='',$extra=array()){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$x));}
function sanitize_text_field($x){return trim(strip_tags((string)$x));}
function get_current_user_id(){return 0;}
final class MAD4B_SCP_CLI_Execution_Context {
    public static $facts=array('local_operation_authorized'=>true,'host_site_binding_verified'=>true,'blockers'=>array());
    public static function resolve($ability,$requires_write){return self::$facts;}
}
final class MAD4B_SCP_Execution_Authority {
    public static $principal=array();
    public static function resolve($ability){return self::$principal;}
}
final class MAD4B_SCP_Site_Profile {
    public static $ids=array(128);
    public static function oauth_user_ids(){return self::$ids;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-authority.php';
function host_assert($condition,$reason){if(!$condition){fwrite(STDERR,"FAIL: $reason\n");exit(1);}}
$sha=str_repeat('a',40);
$current=array(
    'source_commit_sha'=>$sha,
    'build_fingerprint'=>str_repeat('b',64),
    'package_manifest_digest'=>str_repeat('c',64),
    'artifact_identity'=>'mad4b-site-control-plane-general-kit-'.$sha,
);
$confirm='BIND EXACT CURRENT STAGING WRITE CANDIDATE';
$subject=str_repeat('e',64);
MAD4B_SCP_Execution_Authority::$principal=array(
    'authenticated'=>true,'registry_bound'=>true,
    'transport'=>'host_cli','subject_type'=>'host_operator',
    'environment'=>'staging','subject_fingerprint'=>$subject,
    'wp_user_id'=>0, 'registered_host_agent_id'=>7,
    'registered_host_agent_public_id'=>'11111111-1111-4111-8111-111111111111'
);
$clean=array(
    'write_tool_count'=>1,
    'write_inventory_fingerprint'=>str_repeat('f',64),
    'grant_rows_fingerprint'=>str_repeat('d',64),
);
foreach (array(
    'exact_grants_missing_count','stale_allow_grants_count',
    'unreviewed_stale_allow_grants_count','broad_environment_grants_count',
    'duplicate_exact_allow_grants_count','current_agent_wildcard_grants',
    'global_registry_wildcard_grants') as $field) $clean[$field]=0;
$context=array(
    'contract'=>'mad4b.staging-write-candidate-binding.v2',
    'operation_id'=>'11111111-1111-4111-8111-111111111111',
    'correlation_id'=>'host-candidate-fixture',
    'actor'=>array(
        'wp_user_id'=>0,'identity_method'=>'registered_host_wpcli',
        'registered_host_agent_id'=>7,
        'registered_host_agent_public_id'=>'11111111-1111-4111-8111-111111111111',
        'actor_type'=>'host_operator',
        'transport_server_id'=>'local_root_ssh_host_operator',
        'subject_fingerprint'=>$subject,
    ),
    'operation_basis'=>array(
        'source'=>'binding_only_host',
        'contract'=>'mad4b.staging-write-candidate-binding.v2',
        'confirmation'=>$confirm,
    ),
    'target_binding'=>$current,
    'write_snapshot'=>$clean,
    'confirmation'=>$confirm,
);
$ref=new ReflectionMethod('MAD4B_SCP_Staging_Write_Authority','normalize_candidate_binding_context');
if(PHP_VERSION_ID<80100)$ref->setAccessible(true);
$run=static function($input)use($ref,$current){
  return $ref->invoke(null,$input,$current);
};
$root_execution=function_exists('posix_geteuid') && posix_geteuid()===0;
if($root_execution){
    $result=$run($context);
    host_assert(is_array($result) && $result['operation_id']===$context['operation_id'],
       'Exact Host Operator bound to original candidate audit is accepted only for context normalization.');
} else {
    host_assert(is_wp_error($run($context)),
       'Non-root runtime cannot use original Host authority candidate path.');
}
// The host registry identity has no dependence on the enrolled OAuth user list.
MAD4B_SCP_Site_Profile::$ids=array();
if($root_execution)host_assert(is_array($run($context)),
    'Verified Host NHI remains eligible even without a WordPress owner');
MAD4B_SCP_Site_Profile::$ids=array(128);
$changes=array(
 'not_registry_bound'=>array('principal'=>array('registry_bound'=>false)),
 'host_wp_user_impersonation'=>array('principal'=>array('wp_user_id'=>128)),
 'actor_wp_user_impersonation'=>array('context'=>array('actor'=>array_replace(
     $context['actor'],array('wp_user_id'=>128)))),
 'host_agent_drift'=>array('principal'=>array('registered_host_agent_id'=>8)),
 'host_agent_public_id_drift'=>array('principal'=>array(
     'registered_host_agent_public_id'=>'22222222-2222-4222-8222-222222222222')),
 'wrong_transport'=>array('principal'=>array('transport'=>'oauth')),
 'wrong_environment'=>array('principal'=>array('environment'=>'production')),
 'wrong_subject'=>array('principal'=>array('subject_fingerprint'=>str_repeat('a',64))),
 'wrong_authorized_user'=>array('principal'=>array('wp_user_id'=>129)),
 'bad_local_evidence'=>array('facts'=>array('local_operation_authorized'=>false,'host_site_binding_verified'=>true,'blockers'=>array())),
 'unverified_site'=>array('facts'=>array('local_operation_authorized'=>true,'host_site_binding_verified'=>false,'blockers'=>array())),
 'blocking_host_facts'=>array('facts'=>array('local_operation_authorized'=>true,'host_site_binding_verified'=>true,'blockers'=>array('not_verified'))),
 'host_actor_context_mismatch'=>array('context'=>array('actor'=>array_replace($context['actor'],array('subject_fingerprint'=>str_repeat('a',64))))),
 'target_drift'=>array('context'=>array('target_binding'=>array_replace($current,array('source_commit_sha'=>str_repeat('d',40))))),
 'grant_drift'=>array('context'=>array('write_snapshot'=>array_replace($clean,array('exact_grants_missing_count'=>1)))),
 'confirmation_drift'=>array('context'=>array('confirmation'=>'not-reviewed')),
);
foreach($changes as $name=>$change) {
    $saved_principal=MAD4B_SCP_Execution_Authority::$principal;
    $saved_facts=MAD4B_SCP_CLI_Execution_Context::$facts;
    $saved_ids=MAD4B_SCP_Site_Profile::$ids;
    if(isset($change['principal']))MAD4B_SCP_Execution_Authority::$principal=array_replace(
        $saved_principal,$change['principal']);
    if(isset($change['facts']))MAD4B_SCP_CLI_Execution_Context::$facts=$change['facts'];
    if(isset($change['ids']))MAD4B_SCP_Site_Profile::$ids=$change['ids'];
    $attempt=isset($change['context'])?array_replace($context,$change['context']):$context;
    host_assert(is_wp_error($run($attempt)),'Fail closed Host candidate audit: '.$name);
    MAD4B_SCP_Execution_Authority::$principal=$saved_principal;
    MAD4B_SCP_CLI_Execution_Context::$facts=$saved_facts;
    MAD4B_SCP_Site_Profile::$ids=$saved_ids;
}
echo 'PASS: STAGING_HOST_CANDIDATE_CONTEXT 14 native boundary cases, root-positive='
   .($root_execution?'yes':'non-root-denied')."\n";
