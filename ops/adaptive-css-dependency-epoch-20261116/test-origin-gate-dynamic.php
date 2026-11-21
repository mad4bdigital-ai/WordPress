<?php
declare(strict_types=1);
define('ABSPATH','/test');
$GLOBALS['siteBound']=true;
$GLOBALS['policyBound']=true;
$GLOBALS['policyOn']=true;
$GLOBALS['eligible']=true;
$GLOBALS['env']='production';
class MAD4B_Experience_Site_Scope_V1{public static function bound():bool{return $GLOBALS['siteBound'];}}
class MAD4B_Company_Browser_HTML_Policy {
 public static function company_bound():bool{return $GLOBALS['policyBound'];}
 public static function enabled():bool{return $GLOBALS['policyOn'];}
 public static function eligible():bool{return $GLOBALS['eligible'];}
}
function wp_get_environment_type(){return $GLOBALS['env'];}
function home_url($p='/'){return 'https://another-company.example'.$p;}
function wp_parse_url($str,$part=-1){return parse_url($str,$part);}
function add_action(...$args){}
function is_woocommerce(){return false;}function is_search(){return false;}
function is_404(){return false;}function is_feed(){return false;}
function is_trackback(){return false;}function is_wc_endpoint_url(){return false;}
function is_page(){return false;}
require $argv[1];
function assert_gate($expected,$label){
 $value=MAD4B_General_Public_Page_Origin_Cache::ready();
 if($value!==$expected){fwrite(STDERR,"FAIL $label actual=".json_encode($value)."\n");exit(1);}
 echo "PASS $label\n";
}
assert_gate(true,'registered production company is eligible without hostname assumption');
$GLOBALS['siteBound']=false;assert_gate(false,'unknown site denied');
$GLOBALS['siteBound']=true;$GLOBALS['policyBound']=false;assert_gate(false,'unbound policy denied');
$GLOBALS['policyBound']=true;$GLOBALS['policyOn']=false;assert_gate(false,'company policy disabled');
$GLOBALS['policyOn']=true;$GLOBALS['eligible']=false;assert_gate(false,'private/foreign currency context denied');
echo "PASS 5/5 dynamic admission tests\n";
