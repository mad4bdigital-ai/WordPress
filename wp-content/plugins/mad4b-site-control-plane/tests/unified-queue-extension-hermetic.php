<?php
/** Original queue and plugin extension authority checks without real WordPress. */
$root=sys_get_temp_dir().'/mad4b-approval-extension-'.bin2hex(random_bytes(5));
$plugins=$root.'/plugins';
mkdir($plugins,0700,true);
define('ABSPATH',$root.'/');
define('WP_PLUGIN_DIR',$plugins);
$owned=$plugins.'/certified-identity.php';
$bytes="<?php /* synthetic identity provider: descriptor only */";
file_put_contents($owned,$bytes);chmod($owned,0600);
$pin=hash_file('sha256',$owned);
define('MAD4B_APPROVAL_CERTIFIED_EXTENSIONS',[
 'service_identity'=>['certified_agent'=>$pin],
 'browser_authorization'=>['signed_browser'=>$pin]
]);
$GLOBALS['review']=null;$GLOBALS['reads']=0;
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
class WP_Error{private $code;function __construct($c,$message=''){$this->code=$c;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Unified_Approval_Workflow{
 static function inspect($id){$GLOBALS['reads']++;return $GLOBALS['review']??new WP_Error('original_review_missing');}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-unified-approval-queue-bridge.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-unified-approval-extensions.php';
$count=0;
function chk($label,$ok){global $count;if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}$count++;}
$queue='MAD4B_SCP_Unified_Approval_Queue_Bridge';$ext='MAD4B_SCP_Unified_Approval_Extensions';
chk('unknown queue review rejected',is_wp_error($queue::gate(str_repeat('1',64))));
chk('invalid queue id never inspected',is_wp_error($queue::gate('../wrong'))&&$GLOBALS['reads']===1);
$GLOBALS['review']=[
 'review_id'=>str_repeat('a',64),'state'=>'PENDING_AI',
 'content_hash'=>str_repeat('b',64),'policy_sha256'=>str_repeat('c',64)
];
$pending=$queue::gate(str_repeat('a',64));
chk('pending review not completed',$pending['review_completed']===false&&$pending['next_step']==='review_pending_or_rejected');
$GLOBALS['review']['state']='WAITING_HUMAN';
$waiting=$queue::gate(str_repeat('a',64));
chk('human handoff is not authorization',$waiting['next_step']==='wait_for_human_review'&&!$waiting['provider_execution_authorized']);
$GLOBALS['review']['state']='REJECTED';
$rejected=$queue::gate(str_repeat('a',64));
chk('rejected review never authorizes',$rejected['review_completed']===false&&$rejected['next_step']==='review_pending_or_rejected');
$GLOBALS['review']['state']='REVIEW_APPROVED';
$approved=$queue::gate(str_repeat('a',64));
chk('approved review still needs original owner',$approved['review_completed']&&$approved['next_step']==='original_owner_authorization_required');
chk('approved review keeps source policy fingerprints',$approved['source_sha256']===str_repeat('b',64)&&$approved['approval_policy_sha256']===str_repeat('c',64));
chk('approved review not execution or publication',!$approved['provider_execution_authorized']&&!$approved['original_mutation_ticket_issued']&&!$approved['publication_authorized']&&!$approved['mutation_performed']&&$approved['read_only']);
$descriptor=['title'=>'Certified only','certification_sha256'=>$pin,'plugin_file'=>$owned,
 'supported_methods'=>['oauth2_bearer'],'capabilities'=>['verified_subject']];
chk('unlisted kind refused',is_wp_error($ext::register('unreviewed','certified_agent',$descriptor)));
chk('unknown ID refused',is_wp_error($ext::register('service_identity','bad domain',$descriptor)));
$fake=$descriptor;$fake['certification_sha256']=str_repeat('f',64);
chk('false certification hash denied',is_wp_error($ext::register('service_identity','certified_agent',$fake)));
$fake=$descriptor;$fake['plugin_file']=$root.'/../wp-load.php';
chk('external plugin path denied',is_wp_error($ext::register('service_identity','certified_agent',$fake)));
$fake=$descriptor;$fake['arbitrary_auth_callback']='trust_me';
chk('unsafe authentication callback field denied',is_wp_error($ext::register('service_identity','certified_agent',$fake)));
chk('listed plugin source may be described',$ext::register('service_identity','certified_agent',$descriptor)===true);
chk('duplicate provider denied',is_wp_error($ext::register('service_identity','certified_agent',$descriptor)));
chk('browser descriptor may be described',$ext::register('browser_authorization','signed_browser',$descriptor)===true);
$inventory=$ext::inventory();
chk('exact two described-only providers',count($inventory['additional_providers'])===2&&
  $inventory['additional_providers'][0]['status']==='described_only'&&
  $inventory['additional_providers'][1]['status']==='described_only');
chk('descriptor registration grants no identity or browser rights',
 !$inventory['additional_providers'][0]['authentication_authorized']&&
 !$inventory['additional_providers'][1]['browser_execution_authorized']);
chk('browser MFA and service verification remain separate',
 $inventory['browser_human_step_up_required']===true&&$inventory['unverified_api_key_and_mtls_disabled']===true);
chk('inventory never contains secret or mutates',
 !$inventory['credentials_exposed']&&!$inventory['mutation_authorized']&&$inventory['read_only']);
unlink($owned);rmdir($plugins);rmdir($root);
echo 'PASS: '.$count.' Queue Bridge and certified descriptor tests'.PHP_EOL;
