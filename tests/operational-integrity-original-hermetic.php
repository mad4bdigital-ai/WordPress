<?php
/** First-party original deployment/actor scope, no DB or WordPress writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function get_current_blog_id(){return $GLOBALS['blog_id'];}
function get_current_network_id(){return $GLOBALS['network_id'];}
function get_current_user_id(){return $GLOBALS['actor_id'];}
function integrity_check($condition,$reason){
 if(!$condition){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Deployment_Mode_Resolver {
 static $status;
 static function resolve(){return self::$status;}
}
class MAD4B_SCP_Policy {
 static $can=true;
 static function can_mutate(){return self::$can;}
}
$scope=[
 'tenant_ref'=>'wp-site:11111111-1111-4111-8111-111111111111',
 'brand_ref'=>str_repeat('a',32),
 'site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'blog_id'=>2,'network_id'=>1,'environment'=>'staging',
 'deployment_mode'=>'wordpress_dedicated'
];
$base=['status'=>'RESOLVED_FOR_REVIEW_ONLY','scope'=>$scope,
 'dependency_revision'=>['site_profile'=>7,'brand_profile'=>3]];
MAD4B_SCP_Deployment_Mode_Resolver::$status=$base;
$GLOBALS['blog_id']=2;$GLOBALS['network_id']=1;$GLOBALS['actor_id']=17;
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operational-integrity.php';
$c='MAD4B_SCP_Operational_Integrity';
$good=$c::capture();
integrity_check(is_array($good)
 &&$good['execution_authorized']===false
 &&$good['publication_authorized']===false
 &&$good['operational_acceptance']===false,
 'Source-owned operational scope is only nonauthorizing observation');
integrity_check($c::assert_unchanged($good,true)===true,
 'Original exact Site Profile, Brand revision and WP actor allow unchanged governed mutation fence');
foreach([
 ['brand_ref'=>new WP_Error('brand_unknown')],
 ['site_uuid'=>new WP_Error('site_unknown')],
 ['tenant_ref'=>new WP_Error('tenant_unknown')],
 ['blog_id'=>new WP_Error('blog_unknown')],
 ['network_id'=>new WP_Error('network_unknown')],
 ['blog_id'=>'2junk'],['blog_id'=>true],
 ['network_id'=>'1junk'],
 ['environment'=>new WP_Error('env_unknown')]
] as $different){
 MAD4B_SCP_Deployment_Mode_Resolver::$status=array_replace($base,
  ['scope'=>array_replace($scope,$different)]);
 integrity_check(is_wp_error($c::capture()),
  'Malformed or error-shaped original scope cannot crash or become an authorized tenant');
}
MAD4B_SCP_Deployment_Mode_Resolver::$status=$base;
foreach([
 ['site_profile'=>new WP_Error('revision_unknown')],
 ['site_profile'=>'7junk'],
 ['brand_profile'=>new WP_Error('revision_unknown')],
 ['brand_profile'=>'3junk']
] as $different){
 MAD4B_SCP_Deployment_Mode_Resolver::$status=array_replace($base,
  ['dependency_revision'=>array_replace($base['dependency_revision'],$different)]);
 integrity_check(is_wp_error($c::capture()),
  'Unknown or malformed original Site/Brand revision cannot satisfy a trusted CAS checkpoint');
}
MAD4B_SCP_Deployment_Mode_Resolver::$status=$base;
foreach([new WP_Error('blog_unknown'),null,'2',true,0] as $untrusted){
 $GLOBALS['blog_id']=$untrusted;
 integrity_check(is_wp_error($c::capture()),
  'Unknown runtime blog ID cannot be cast into same-tenant WordPress scope');
}
$GLOBALS['blog_id']=2;
foreach([new WP_Error('network_unknown'),null,'1',true,0] as $untrusted){
 $GLOBALS['network_id']=$untrusted;
 integrity_check(is_wp_error($c::capture()),
  'Unknown runtime network ID cannot be cast into same-site network scope');
}
$GLOBALS['network_id']=1;
foreach([new WP_Error('actor_unknown'),null,'17',true,-1] as $untrusted){
 $GLOBALS['actor_id']=$untrusted;
 integrity_check(is_wp_error($c::capture()),
  'Unknown actor ID cannot impersonate original WordPress user or NHI operator');
}
$GLOBALS['actor_id']=17;
integrity_check($c::assert_unchanged($good,false)===true,
 'Exact original fingerprint remains stable after restored site/user data');
MAD4B_SCP_Policy::$can=new WP_Error('grant_unknown');
integrity_check(is_wp_error($c::assert_unchanged($good,true)),
 'Unknown original mutation policy cannot re-open write through cached scope');
MAD4B_SCP_Policy::$can=true;
$GLOBALS['actor_id']=18;
integrity_check(is_wp_error($c::assert_unchanged($good,true)),
 'Changed signed WordPress user must stop at the immutable operational checkpoint');
$GLOBALS['actor_id']=17;
MAD4B_SCP_Deployment_Mode_Resolver::$status=['status'=>'HOLD','reason'=>'UNKNOWN_PROVIDER'];
$hold=$c::capture();
integrity_check(is_wp_error($hold)&&$hold->get_error_code()==='mad4b_integrity_scope_blocked',
 'Unresolved original tenant scope denies checkpoint creation');
echo 'PASS: OPERATIONAL_INTEGRITY_ORIGINAL '.($GLOBALS['checks']??0).
 ' exact typed Site, Brand, actor and CAS checkpoint cases'.PHP_EOL;
