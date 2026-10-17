<?php
/** Synthetic CSO deploy opt-in, actor/site binding and forged-secret refusal. */
define( 'ABSPATH', '/' );
class WP_Error { private $code; function __construct($c,$m='',$d=array()){$this->code=$c;} function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function wp_parse_url($v){return parse_url($v);}
function get_current_blog_id(){return $GLOBALS['blog_id'];}
function get_current_network_id(){return $GLOBALS['network_id'];}
function get_current_user_id(){return $GLOBALS['actor_id'];}
function current_user_can($v){return $GLOBALS['authorized'];}
function get_user_locale($id){return 'ar';}
function wp_salt($k){return str_repeat('s',48);}
$GLOBALS['blog_id']=1;$GLOBALS['network_id']=1;$GLOBALS['actor_id']=7;$GLOBALS['authorized']=true;
class MAD4B_SCP_Policy {
 static function can_read(){return $GLOBALS['authorized'];}
 static function can_connect_user($id){return $GLOBALS['authorized']&&$id===7;}
}
class MAD4B_SCP_Site_Profile { static function current_origin(){return $GLOBALS['origin'];} }
class MAD4B_SCP_Operational_Integrity {
 static function capture(){
  if($GLOBALS['revoked'])return new WP_Error('revoked');
  $scope=array('site_uuid'=>'11111111-2222-4333-8444-555555555555','tenant_ref'=>'wp-site:11111111-2222-4333-8444-555555555555',
   'brand_ref'=>str_repeat('a',32),'environment'=>'staging',
   'deployment_mode'=>'wordpress_dedicated','blog_id'=>1,'network_id'=>1);
  $fingerprint=hash('sha256',serialize(array($scope,$GLOBALS['actor_id'],$GLOBALS['revision'])));
  return array('scope'=>$scope,'fingerprint'=>$fingerprint,'dependency_revision'=>array('site_profile'=>2,'brand_profile'=>$GLOBALS['revision']));
 }
}
$GLOBALS['revoked']=false;$GLOBALS['revision']=2;$GLOBALS['origin']='https://site.example';
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-scope.php';
function ck($truth,$message){if(!$truth){fwrite(STDERR,'FAIL '.$message."\n");exit(1);}}
ck(!MAD4B_SCP_CSO_Scope::enabled('discovery'),'default OFF until explicit deployment opt-in');
define('MAD4B_CSO_ENABLED_COMPONENTS',array('discovery'=>true,'forms'=>true,'secrets'=>false));
ck(MAD4B_SCP_CSO_Scope::enabled('forms'),'deploy-time approved forms enabled');
ck(!MAD4B_SCP_CSO_Scope::enabled('secrets'),'secrets remain separately disabled');
$s=MAD4B_SCP_CSO_Scope::current();
ck(!is_wp_error($s)&&$s['blog_id']===1,'site bound Staging scope');
ck(true===MAD4B_SCP_CSO_Scope::assert_current($s),'exact actor/current scope');
$GLOBALS['actor_id']=9;ck(is_wp_error(MAD4B_SCP_CSO_Scope::assert_current($s)),'actor change rejected');
$GLOBALS['actor_id']=7;$GLOBALS['revision']=3;
ck(is_wp_error(MAD4B_SCP_CSO_Scope::assert_current($s)),'brand revision drift rejected');
$GLOBALS['revision']=2;$GLOBALS['blog_id']=2;
ck(is_wp_error(MAD4B_SCP_CSO_Scope::current()),'Multisite switch blocked');
$GLOBALS['blog_id']=1;
foreach(array('api_key'=>'value','nested'=>array('access_token'=>'value'),'secret'=>'value')as$key=>$value)
 ck(!MAD4B_SCP_CSO_Scope::safe_data(array($key=>$value)),'credential field denied');
ck(!MAD4B_SCP_CSO_Scope::safe_data(array('body'=>'Bearer token01234567890123456789')),'Bearer material denied');
ck(MAD4B_SCP_CSO_Scope::safe_data(array('body'=>'ordinary text','count'=>3)),'ordinary typed values accepted');
$sealed=MAD4B_SCP_CSO_Scope::seal(array('state'=>'PREPARED'),'test-purpose');
ck(!is_wp_error($sealed)&&MAD4B_SCP_CSO_Scope::unseal($sealed,'test-purpose')['state']==='PREPARED','HMAC sealed state');
$sealed['material']['state']='VERIFIED';
ck(is_wp_error(MAD4B_SCP_CSO_Scope::unseal($sealed,'test-purpose')),'tampered state refused');
$GLOBALS['authorized']=false;ck(is_wp_error(MAD4B_SCP_CSO_Scope::current()),'policy revoked');
echo "PASS CSO opt-in scope/actor/blog/seal/safe-data synthetic acceptance\n";
