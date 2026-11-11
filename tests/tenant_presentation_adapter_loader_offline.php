<?php
/** No WordPress database/network: identity-gate proof. */
define('ABSPATH','/');
define('MAD4B_TENANT_COMPANY_CODE','testcompany');
define('MAD4B_TENANT_SITE_UUID','a3a7cc36-db7e-4a14-8456-b41857def05c');
define('MAD4B_TENANT_ORIGIN','https://example.test');
define('MAD4B_TENANT_ENVIRONMENT','production');
$GLOBALS['site']='https://example.test/';
$GLOBALS['env']='production';
$GLOBALS['uuid']=MAD4B_TENANT_SITE_UUID;
$GLOBALS['configured']=true;
$GLOBALS['hooks']=[];
function add_action($name,$callback,$priority=10){$GLOBALS['hooks'][$name]=[$callback,$priority];}
function home_url($path=''){return $GLOBALS['site'];}
function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function wp_is_uuid($value){return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$value)===1;}
class MAD4B_SCP_Site_Profile {
 public static function configured(){return $GLOBALS['configured'];}
 public static function site_uuid(){return $GLOBALS['uuid'];}
}
function must($value,$name){if(!$value)throw new RuntimeException($name);}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-tenant-presentation-adapter-loader-v1.php';
$c=MAD4B_Tenant_Presentation_Adapter_Loader_V1::class;
must($c::eligible(),'verified tenant accepted');
must(($GLOBALS['hooks']['plugins_loaded'][1]??null)===5,'deferred before options init');
$GLOBALS['uuid']='9f441634-701a-420f-a81a-efab76ee1dbd';
must(!$c::eligible(),'cross-site UUID rejected');
$GLOBALS['uuid']=MAD4B_TENANT_SITE_UUID;
$GLOBALS['site']='https://other.test/';
must(!$c::eligible(),'cross-site origin rejected');
$GLOBALS['site']='https://example.test/';
$GLOBALS['env']='staging';
must(!$c::eligible(),'wrong environment rejected');
$GLOBALS['env']='production';
$GLOBALS['configured']=false;
must(!$c::eligible(),'unconfigured Site Profile rejected');
echo json_encode(['status'=>'PASS','identity_cases'=>6,'cross_site_activation'=>false,'no_remote_writes'=>true]).PHP_EOL;
