<?php
/** Read-only tenant environment gate; no remote actions. */
define('ABSPATH','/');
define('MAD4B_TENANT_COMPANY_CODE','testcompany');
define('MAD4B_TENANT_SITE_UUID','a3a7cc36-db7e-4a14-8456-b41857def05c');
define('MAD4B_TENANT_ORIGIN','https://example.test');
define('MAD4B_TENANT_ENVIRONMENT','production');
$GLOBALS['home']='https://example.test';
$GLOBALS['environment']='production';
$GLOBALS['uuid']=MAD4B_TENANT_SITE_UUID;
$GLOBALS['configured']=true;
function get_option($name,$fallback=''){return $name==='home'?$GLOBALS['home']:$fallback;}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function wp_is_uuid($value){return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$value)===1;}
class MAD4B_SCP_Site_Profile {
  public static function configured(){return $GLOBALS['configured'];}
  public static function site_uuid(){return $GLOBALS['uuid'];}
}
function assertTrue($value,$label){if(!$value)throw new RuntimeException('FAIL: '.$label);}
require dirname(__DIR__).'/wp-content/mu-plugins/001-mad4b-service-tenant-boundary-v1.php';
$c=MAD4B_Tenant_Service_Identity_Boundary_V1::class;
assertTrue($c::eligible(),'matching exact tenant identity');
$GLOBALS['home']='https://other.test';
assertTrue(!$c::eligible(),'wrong site origin denied');
$GLOBALS['home']='https://example.test';
$GLOBALS['environment']='staging';
assertTrue(!$c::eligible(),'wrong environment denied');
$GLOBALS['environment']='production';
$GLOBALS['uuid']='9f441634-701a-420f-a81a-efab76ee1dbd';
assertTrue(!$c::eligible(),'wrong site UUID denied');
$GLOBALS['uuid']=MAD4B_TENANT_SITE_UUID;
$GLOBALS['configured']=false;
assertTrue(!$c::eligible(),'unconfigured native profile denied');
echo json_encode(['status'=>'PASS','assertions'=>5,'writes'=>0,'cross_company_activation'=>false]).PHP_EOL;
