<?php
// Hermetic, pure in-memory test; never performs real HTTP requests.
define('ABSPATH','/mock/');define('WP_ENVIRONMENT_TYPE','staging');
class WP_Error{public function __construct(private $code,public $message=''){}public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_get_environment_type(){return 'staging';}
function add_filter($hook,$fn,$priority=10,$accepted_args=1){$GLOBALS['hooks'][$hook][]=$fn;}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$x));}
function wp_parse_url($s){return parse_url($s);}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
class MAD4B_SCP_Environment{public static function effective(){return $GLOBALS['effective']??'staging';}}
class MAD4B_SCP_Site_Profile{public static function environment_resolution(){return ['profile_environment_authoritative'=>true,'wordpress_profile_mismatch'=>false,'effective_environment'=>$GLOBALS['effective']??'staging'];}}
class MAD4B_SCP_Egress_Policy{public static $checks=0;public static function status(){return ['ready'=>true];}public static function enforce($pre,$args,$url){self::$checks++;return $pre;}}
class MAD4B_SCP_Provider_Transport_Registry{public static function descriptors($p){return $p==='test_provider'?[['provider_id'=>$p]]:[];}}
class MAD4B_SCP_Provider_Compatibility_Certification{
 public static function supports_provider($p){return $p==='test_provider';}
 public static function capability_certification($args){return ['compatibility_state'=>$GLOBALS['certification_state']??'certified','capabilities'=>['provider.read'=>['read_eligible'=>true]]];}
 public static function certification_generation_sha256($p){return str_repeat('a',64);}
}
function expect($ok,$name){if(!$ok){fwrite(STDERR,"FAILED $name\n");exit(7);}echo "PASS $name\n";}
class FakeRequest {public function __construct(private $method,private $route){}public function get_method(){return $this->method;}public function get_route(){return $this->route;}}
require __DIR__.'/015-mad4b-staging-runtime-admission.php';
$c='MAD4B_ETG_Runtime_Admission';$args=['mad4b_egress_purpose'=>'oauth_jwks','mad4b_egress_authority'=>'https://provider.example'];
expect($c::environment_status()['verified'],'converged staging');
expect($c::rest_write(null,null,new FakeRequest('PATCH','/mad4b/v1/setting'))===null,'normal governed REST writes preserve existing auth');
$mail=$GLOBALS['hooks']['pre_wp_mail'][0];expect($mail(null,[])===null,'matching runtime environment does not grant email');
expect($c::http(false,[],'https://provider.example')===false,'unmarked request unchanged');
$denied=new WP_Error('earlier_denial');expect($c::http($denied,$args,'https://provider.example')===$denied,'earlier denial stays');
expect($c::http(false,$args,'https://provider.example')===false&&MAD4B_SCP_Egress_Policy::$checks===1,'existing egress policy reused');
$GLOBALS['effective']='production';expect(is_wp_error($c::http(false,$args,'https://provider.example')),'staging/profile drift fail closed');
expect($mail(true,[])===false,'mail denied if profile diverges');
expect(is_wp_error($c::rest_write(null,null,new FakeRequest('POST','/mad4b/v1/setting'))),'MAD4B writes blocked on divergence');
expect($c::rest_write(null,null,new FakeRequest('GET','/mad4b/v1/setting'))===null,'read-only REST unaffected');
expect($c::rest_write(null,null,new FakeRequest('POST','/wp/v2/posts'))===null,'non-MAD4B REST outside scope');
$GLOBALS['effective']='staging';$args['mad4b_provider_id']='unknown_provider';expect(is_wp_error($c::http(false,$args,'https://provider.example')),'unknown external provider blocked');
$args['mad4b_provider_id']='test_provider';$GLOBALS['options'][$c::OPTION]=['test_provider'=>['state'=>'draft','certificate_sha256'=>str_repeat('a',64),'purpose'=>'oauth_jwks','origin'=>'https://provider.example']];
expect(is_wp_error($c::http(false,$args,'https://provider.example/jwks')),'draft certification blocked');
$GLOBALS['options'][$c::OPTION]['test_provider']['state']='certified';
$GLOBALS['certification_state']='compatible_unattested';expect(is_wp_error($c::http(false,$args,'https://provider.example/jwks')),'compatible but unattested provider blocked');
$GLOBALS['certification_state']='certified';expect($c::http(false,$args,'https://provider.example/jwks')===false,'exact native certified descriptor admitted');
$GLOBALS['options'][$c::OPTION]['test_provider']['certificate_sha256']=str_repeat('b',64);expect(is_wp_error($c::http(false,$args,'https://provider.example/jwks')),'stale certification digest blocked');
$GLOBALS['options'][$c::OPTION]['test_provider']['certificate_sha256']=str_repeat('a',64);expect(is_wp_error($c::http(false,$args,'https://other.example/jwks')),'authority host drift blocked');
echo "STAGING_RUNTIME_ADMISSION: PASS\n";
