<?php
// Hermetic runtime ownership contract for MAD4B MU336.
error_reporting(E_ALL);
define('ABSPATH', '/tmp/');
define('LSCWP_V', '7.9.2');
define('ELEMENTOR_VERSION', '4.3.5');
$GLOBALS['settings'] = [
    'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'],
    'litespeed.conf.optm-css_comb'=>'1',
    'litespeed.conf.cache-mobile'=>'1',
    'litespeed.conf.optm-ucss'=>'',
    'litespeed.conf.optm-css_async'=>'',
    'litespeed.conf.guest_optm'=>'',
    'mad4b_css_priority_staging_en_ar_v1'=>'0',
];
$GLOBALS['environment']='staging';
$GLOBALS['domain']='tours.dev.mad4b.com';
function wp_get_environment_type(){return $GLOBALS['environment'];}
function home_url(){return 'https://'.$GLOBALS['domain'].'/';}
function wp_parse_url($s,$part=null){return parse_url($s,$part);}
function get_option($name,$default=false){return $GLOBALS['settings'][$name]??$default;}
function add_action($hook,$cb,$priority=10){$GLOBALS['actions'][]=[$hook,$cb,$priority];}
function ensure($x,$message){if(!$x){fwrite(STDERR,'FAILED '.$message."\n");exit(5);}echo 'PASS '.$message."\n";}
$source=getenv('MAD4B_MU336_PATH') ?: dirname(__DIR__).'/../../site-copy/wp-content/mu-plugins/336-mad4b-litespeed-local-provider.php';
require $source;
$c='MAD4B_LSC_Local_Provider_V1';
ensure($c::scope() && $c::mode()==='local_first','staging default is local-first');
ensure($c::local_css_eligible(),'certified-only local overlay may be evaluated');
ensure(!$c::legacy_css_allowed(),'legacy parallel CSS overlay blocked');
foreach(['litespeed.conf.optm-ucss','litespeed.conf.optm-css_async','litespeed.conf.guest_optm'] as $name){
    $GLOBALS['settings'][$name]='1';
    ensure(!$c::local_css_eligible(),'LS service conflicts trigger native-only: '.$name);
    $GLOBALS['settings'][$name]='';
}
$GLOBALS['settings'][$c::OPT]='native';
ensure($c::mode()==='native' && !$c::local_css_eligible() && $c::legacy_css_allowed(),'explicit native handoff');
unset($GLOBALS['settings'][$c::OPT]);
$GLOBALS['settings']['litespeed.conf.cache-mobile']='0';
ensure(!$c::local_css_eligible(),'cache device partition required');
$GLOBALS['settings']['litespeed.conf.cache-mobile']='1';
$GLOBALS['domain']='foreign.example';
ensure(!$c::scope() && $c::mode()==='native','other company isolated');
$GLOBALS['domain']='tours.dev.mad4b.com';
$GLOBALS['environment']='production';
ensure(!$c::scope(),'production does not load local CSS owner');
echo "mad4b.lsc.local-first.v1 PASS\n";
