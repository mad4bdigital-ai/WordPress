<?php
defined('ABSPATH')||define('ABSPATH','/');
class MAD4B_Hub_Nile_Publisher_V1 {}
$GLOBALS['blog']=1;
$GLOBALS['reads']=0;
function wp_get_environment_type(){return $GLOBALS['env']??'staging';}
function get_current_blog_id(){return $GLOBALS['blog'];}
function get_option($key,$default=null){
 $GLOBALS['reads']++;
 if($key!=='home')throw new Exception('Unexpected option');
 return $GLOBALS['homes'][$GLOBALS['blog']]??$default;
}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function home_url($path='/'){throw new Exception('Forbidden heavy WPML home_url evaluation');}
function add_action($tag,$handler,$priority=10){return true;}
function ok($name,$test){if(!$test)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$GLOBALS['homes']=[1=>'https://tours.dev.mad4b.com',2=>'https://other-company.example'];
require getenv('MAD4B_TOURISM_SOURCE_FILE');
ok('canonical company enabled',MAD4B_Tourism_Source_View_V1::enabled());
ok('repeat call memoized',MAD4B_Tourism_Source_View_V1::enabled() && $GLOBALS['reads']===1);
$GLOBALS['blog']=2;
ok('different company isolated',!MAD4B_Tourism_Source_View_V1::enabled());
ok('new blog read', $GLOBALS['reads']===2);
$GLOBALS['blog']=1;
ok('switching back preserves context',MAD4B_Tourism_Source_View_V1::enabled() && $GLOBALS['reads']===2);
$GLOBALS['env']='production';
ok('production never enabled',!MAD4B_Tourism_Source_View_V1::enabled());
echo "PASS TOURISM SOURCE HOST MEMO\n";
