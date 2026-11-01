<?php
define('ABSPATH', '/tmp/mad4b-scope-fixture/');
$GLOBALS['env']='staging';
$GLOBALS['site']='123e4567-e89b-12d3-a456-426614174000';
$GLOBALS['origin_ready']=true;
$GLOBALS['urls_match']=true;
$GLOBALS['local_bound']=false;
$GLOBALS['https_home']=true;
function wp_get_environment_type(){return $GLOBALS['env'];}
function home_url($path='/'){return ($GLOBALS['https_home']?'https://':'http://').'site.example'.$path;}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function add_action($name,$fn,$priority=10,$accepted=1){}
function add_filter($name,$fn,$priority=10,$accepted=1){}
class MAD4B_SCP_Site_Profile {
 public static function site_uuid(){return $GLOBALS['site'];}
 public static function origin_enrolled(){return $GLOBALS['origin_ready'];}
 public static function site_urls_match_enrollment(){return $GLOBALS['urls_match'];}
}
class MAD4B_SCP_Local_Client {public static function bound(){return $GLOBALS['local_bound'];}}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-only-local-activation.php';
$n=0;
function check($label,$valid){global $n;if(!$valid){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}++$n;}
check('staging disabled without optin',!MAD4B_SCP_AI_Only_Local_Activation::site_bound());
$GLOBALS['env']='production';
check('production disabled without optin',!MAD4B_SCP_AI_Only_Local_Activation::site_bound());
define('MAD4B_AI_REVIEW_CROSS_ENV_ENABLED',true);
define('MAD4B_AI_REVIEW_SITE_UUID','123e4567-e89b-12d3-a456-426614174000');
check('production allowed with exact site optin',MAD4B_SCP_AI_Only_Local_Activation::site_bound());
$GLOBALS['env']='staging';
check('staging allowed with exact site optin',MAD4B_SCP_AI_Only_Local_Activation::site_bound());
$GLOBALS['site']='00000000-0000-0000-0000-000000000000';
check('wrong UUID denied',!MAD4B_SCP_AI_Only_Local_Activation::site_bound());
$GLOBALS['site']='123e4567-e89b-12d3-a456-426614174000';
$GLOBALS['origin_ready']=false;
check('unenrolled site denied',!MAD4B_SCP_AI_Only_Local_Activation::site_bound());
$GLOBALS['origin_ready']=true;
$GLOBALS['urls_match']=false;
check('origin URL drift denied',!MAD4B_SCP_AI_Only_Local_Activation::site_bound());
$GLOBALS['urls_match']=true;
$GLOBALS['https_home']=false;
check('non TLS home denied',!MAD4B_SCP_AI_Only_Local_Activation::site_bound());
$GLOBALS['https_home']=true;
$GLOBALS['env']='local';
check('existing local binding still allowed optin',MAD4B_SCP_AI_Only_Local_Activation::site_bound());
echo 'PASS: '.$n.' environment-independent site scope cases'.PHP_EOL;
