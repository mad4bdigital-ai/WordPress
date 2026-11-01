<?php
/** Hermetic original identity + exact-grant admission tests; no WordPress site writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;function __construct($code,$message=''){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
$GLOBALS['identity']=[
 'authenticated'=>true,'auth_method'=>'wp_application_password',
 'subject_type'=>'wp_app_password','subject_fingerprint'=>str_repeat('a',64)
];
$GLOBALS['agent']=['id'=>17,'public_id'=>'237e4567-e89b-12d3-a456-426614174000','status'=>'enabled'];
$GLOBALS['env']='staging';
$GLOBALS['site']='123e4567-e89b-12d3-a456-426614174000';
$GLOBALS['grant']=['effect'=>'allow','environment'=>'staging'];
$GLOBALS['grant_calls']=[];
class MAD4B_SCP_Identity_Context{
 static function current(){return $GLOBALS['identity'];}
}
class MAD4B_SCP_Site_Profile{
 static function site_uuid(){return $GLOBALS['site'];}
 static function current_environment(){return $GLOBALS['env'];}
}
class MAD4B_SCP_Agent_Registry{
 static function resolve_agent($identity){return $GLOBALS['agent'];}
 static function exact_grant($agent,$server,$ability,$provider='core'){
  $GLOBALS['grant_calls'][]=[$agent,$server,$ability,$provider];return $GLOBALS['grant'];
 }
}
class MAD4B_SCP_Unified_Approval_Workflow{
 const DOMAINS=['content_job','product_catalog','seo_metadata','localization','design_template'];
}
require dirname(__DIR__).'/includes/class-mad4b-scp-background-authorization.php';
$n=0;
function ok($name,$valid){global $n;if(!$valid){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}$n++;}
$background='MAD4B_SCP_Background_Authorization';$browser='MAD4B_SCP_Browser_Auth_Broker';
$who=$background::current();
ok('original app password accepted after upstream verification',
 is_array($who)&&$who['method']==='wp_application_password'&&$who['agent_id']===17);
ok('verified site and hashed subject only', $who['site_uuid']===$GLOBALS['site']
 && strlen($who['subject_fingerprint'])===64&&!isset($who['authorization'])
 && $who['credential_exposed']===false && $who['mutation_authorized']===false);
$grant=$background::check_qa_grant('product_catalog');
ok('exact domain staging QA only',is_array($grant)&&$grant['allowed']===true
 && $grant['review_only']===true && $grant['publication_authorized']===false);
ok('exact original grant scope',end($GLOBALS['grant_calls'])===[17,'mad4b-write','mad4b/ai-product-catalog-review','core']);
$GLOBALS['grant']=['effect'=>'deny','environment'=>'staging'];
ok('explicit deny overrides QA',is_wp_error($background::check_qa_grant('product_catalog')));
$GLOBALS['grant']=['effect'=>'allow','environment'=>'production'];
ok('cross-environment grant denied',is_wp_error($background::check_qa_grant('product_catalog')));
$GLOBALS['grant']=new WP_Error('original_grant_denied');
ok('original denial preserved',is_wp_error($background::check_qa_grant('product_catalog')));
$GLOBALS['grant']=['effect'=>'allow','environment'=>'staging'];
ok('unknown domain denied',is_wp_error($background::check_qa_grant('provider_admin')));
ok('array domain denied safely',is_wp_error($background::check_qa_grant(['product_catalog'])));
$GLOBALS['identity']['authenticated']=false;
ok('unauthenticated identity denied',is_wp_error($background::current()));
$GLOBALS['identity']['authenticated']=true;
$GLOBALS['identity']['auth_method']='api_key';
ok('unverified API key type denied',is_wp_error($background::current()));
$GLOBALS['identity']['auth_method']='oauth2_bearer';
ok('verified OAuth mechanism admitted',is_array($background::current()));
$GLOBALS['identity']['subject_fingerprint']='not-a-hash';
ok('malformed identity fingerprint rejected',is_wp_error($background::current()));
$GLOBALS['identity']['subject_fingerprint']=['bad'];
ok('nonstring fingerprint rejected safely',is_wp_error($background::current()));
$GLOBALS['identity']['subject_fingerprint']=str_repeat('a',64);
$GLOBALS['agent']['status']='disabled';
ok('disabled Agent rejected',is_wp_error($background::current()));
$GLOBALS['agent']['status']='enabled';$GLOBALS['agent']['id']=0;
ok('unbound invalid Agent ID rejected',is_wp_error($background::current()));
$GLOBALS['agent']['id']=17;
$GLOBALS['site']='';
ok('unbound Site Profile rejected',is_wp_error($background::current()));
$GLOBALS['site']='123e4567-e89b-12d3-a456-426614174000';
$GLOBALS['identity']['auth_method']='mad4b_passwordless_bearer';
ok('verified passwordless identity accepted',is_array($background::current()));
$methods=$background::methods();
ok('unverified provider and MFA not treated as ready',
 in_array('mTLS',$methods['needs_verified_provider'],true)
 && in_array('webauthn',$methods['human_step_up_required'],true)
 && $methods['secrets_in_model_context']===false);
$plan=$browser::plan(['provider'=>'trusted_browser','operation'=>'read_profile','origin'=>'https://example.com']);
ok('browser handoff requires human and no session',is_array($plan)
 && $plan['state']==='WAITING_AUTH' && $plan['human_interaction_required']===true
 && $plan['session_secrets_stored_in_wordpress']===false
 && $plan['agent_may_bypass_mfa']===false
 && $plan['execution_authorized']===false);
ok('model cannot mint browser bearer',is_wp_error($browser::issue_session_from_model([])));
ok('HTTP origin rejected',is_wp_error($browser::plan(['provider'=>'trusted_browser','operation'=>'read_profile','origin'=>'http://example.com'])));
ok('userinfo origin rejected',is_wp_error($browser::plan(['provider'=>'trusted_browser','operation'=>'read_profile','origin'=>'https://user@example.com'])));
echo 'PASS: '.$n.' Background Authorization and browser broker safety cases'.PHP_EOL;
