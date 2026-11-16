<?php
declare(strict_types=1);
define('ABSPATH','/mock/');
$GLOBALS['opts']=['woocommerce_default_customer_address'=>'base','woocommerce_currency'=>'USD'];
$GLOBALS['wcml']=true;
$GLOBALS['currency']='EUR';
$GLOBALS['country']='DE';
function add_action(...$args){}
function add_filter(...$args){}
function get_option($k,$default=false){return $GLOBALS['opts'][$k]??$default;}
function get_woocommerce_currency(){return $GLOBALS['currency'];}
function wcml_is_multi_currency_on(){return $GLOBALS['wcml'];}
function mad4b_render_query_market_identity(){return [
  'country'=>$GLOBALS['country'],'currency'=>$GLOBALS['market_currency']??$GLOBALS['currency']
];}
require $argv[1];
function chk($pass,$message){if(!$pass){fwrite(STDERR,"FAIL $message\n");exit(1);}echo "PASS $message\n";}
$c='MAD4B_Company_Browser_HTML_Policy';
chk(!$c::market_scope_verified(),'EUR geo market denied with no trusted edge isolation');
$_SERVER['HTTP_X_MAD4B_IP_MARKET_CACHE_GATE']='v1';
chk(!$c::market_scope_verified(),'forged request header cannot enable trusted FastCGI admission');
$_SERVER['MAD4B_IP_MARKET_CACHE_GATE']='v1';
chk($c::market_scope_verified(),'EUR geo market with trusted per-IP FastCGI gate admitted');
$GLOBALS['market_currency']='USD';
chk(!$c::market_scope_verified(),'mismatched WCML currency forbidden');
unset($GLOBALS['market_currency']);
$GLOBALS['country']='base';
chk(!$c::market_scope_verified(),'unknown base country denied with multi currency');
$GLOBALS['wcml']=false;$GLOBALS['currency']='USD';
chk($c::market_scope_verified(),'single currency base market admitted');
unset($_SERVER['MAD4B_IP_MARKET_CACHE_GATE']);
chk($c::market_scope_verified(),'single currency base mode independent of multi-market gate');
$GLOBALS['wcml']=true;$GLOBALS['country']='US';
chk(!$c::market_scope_verified(),'multi currency no trusted gate denied, even USD');
$GLOBALS['country']='U$';$_SERVER['MAD4B_IP_MARKET_CACHE_GATE']='v1';
chk(!$c::market_scope_verified(),'untrusted country format denied');
$GLOBALS['country']='US';$GLOBALS['opts']['woocommerce_default_customer_address']='geolocation';
chk(!$c::market_scope_verified(),'visitor-specific geolocated address remains denied');
echo "PASS 10/10 market identity tests\n";
