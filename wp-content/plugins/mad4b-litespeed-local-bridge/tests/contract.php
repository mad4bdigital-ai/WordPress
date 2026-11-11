<?php
error_reporting(E_ALL);
define('ABSPATH','/tmp/mad4b-contract/');
define('WP_CONTENT_DIR','/tmp/mad4b-contract/wp-content');
define('WPMU_PLUGIN_DIR',WP_CONTENT_DIR.'/mu-plugins');
define('LSCWP_V','7.9.2');
define('ELEMENTOR_VERSION','4.3.5');
$GLOBALS['env']='staging';
$GLOBALS['lang']='en';
$GLOBALS['opts']=[
  'home'=>'https://company-a.example',
  'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'11111111-1111-4111-8111-111111111111'],
  'litespeed.conf.optm-css_comb'=>'1',
  'litespeed.conf.cache-mobile'=>'1',
  'litespeed.conf.optm-css_async'=>'0',
  'litespeed.conf.optm-ucss'=>'0',
  'litespeed.conf.guest_optm'=>'0'
];
function add_action(...$args){return true;}
function add_filter(...$args){return true;}
function untrailingslashit($v){return rtrim($v,'/');}
function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_parse_url($s,$c){return parse_url($s,$c);}
function wp_is_uuid($v){return (bool)preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$v);}
function get_option($key,$default=false){return $GLOBALS['opts'][$key]??$default;}
function apply_filters($h,$v,...$args){if($h==='wpml_current_language')return $GLOBALS['lang']; if($h==='wpml_active_languages')return array_fill_keys($GLOBALS['langs']??['en','es'],[]);return $v;}
function get_locale(){return 'en_US';}
function did_action($a){return 0;}
function is_admin(){return false;}
function is_user_logged_in(){return false;}
function wp_doing_ajax(){return false;}
function is_preview(){return false;}
function ok($v,$label){if(!$v){fwrite(STDERR,"FAIL $label\n");exit(9);}echo "PASS $label\n";}
require dirname(__DIR__,2).'/mad4b-platform/modules/integrations/336-mad4b-litespeed-local-provider.php';
require dirname(__DIR__,2).'/mad4b-platform/modules/presentation/334-mad4b-page-css-frame-priority.php';
require dirname(__DIR__,2).'/mad4b-platform/modules/compat/335-mad4b-self-governing-css.php';
require dirname(__DIR__).'/mad4b-litespeed-local-bridge.php';
$p='MAD4B_LSC_Local_Provider_V1'; $b='MAD4B_LiteSpeed_Local_Bridge';$c='MAD4B_Autonomous_CSS_V1';
ok($p::identity()['host']==='company-a.example','Company A identity');
ok($p::local_css_eligible(),'Company A CSS eligible');
ok($b::active(),'Company A bridge active');
ok($p::source_version()==='4.3.5/7.9.2','Version fingerprint built from runtime');
ok($p::safe_cookie_header('wp-wpml_current_language=en; _ga=123','en'),'WPML analytics safe cookies');
ok(!$p::safe_cookie_header('wordpress_logged_in_x=y','en'),'Auth cookie prevents optm');
ok(!$p::safe_cookie_header('wp-wpml_current_language=es','en'),'Language mismatch fails');
ok(!$p::legacy_css_allowed(),'Legacy MU334 disabled');
ok(!$c::policy_valid(['contract'=>'mad4b.dynamic-css.local.v1','site_uuid'=>'11111111-1111-4111-8111-111111111111','site_host'=>'other.example','routes'=>[]]),'Cross-company manifest blocked');
ok($c::policy_valid(['contract'=>'mad4b.dynamic-css.local.v1','site_uuid'=>'11111111-1111-4111-8111-111111111111','site_host'=>'company-a.example','routes'=>[]]),'Company A manifest matches');
ok(!$b::vpi_queue(true,'https://company-a.example/'),'Remote VPI jobs disabled');
$GLOBALS['env']='production';
$GLOBALS['opts']['home']='https://company-b.example';
$GLOBALS['opts']['mad4b_scp_brand_context_profile_v1']=['site_uuid'=>'22222222-2222-4222-8222-222222222222'];
$GLOBALS['lang']='pt-br';
ok($p::identity()['host']==='company-b.example','Company B production identity');
ok($p::local_css_eligible() && $b::active(),'Company B eligible');
$_SERVER['REQUEST_URI']='/pt-br/packages/';
ok($b::route()===['pt-br','/pt-br/packages/'],'Language and route not hardcoded');
ok($c::policy_valid(['contract'=>'mad4b.dynamic-css.local.v1','site_uuid'=>'22222222-2222-4222-8222-222222222222','site_host'=>'company-b.example','routes'=>[]]),'Company B own manifest');
ok(!$c::policy_valid(['contract'=>'mad4b.dynamic-css.local.v1','site_uuid'=>'11111111-1111-4111-8111-111111111111','site_host'=>'company-a.example','routes'=>[]]),'Company A manifest rejected by B');
$GLOBALS['opts']['mad4b_scp_brand_context_profile_v1']=['site_uuid'=>'wrong'];
ok(!$p::scope()&&!$b::active(),'Invalid company identity denies runtime');
$GLOBALS['opts']['mad4b_scp_brand_context_profile_v1']=['site_uuid'=>'22222222-2222-4222-8222-222222222222'];
$GLOBALS['opts']['home']='http://company-b.example';
ok(!$p::scope(),'Production requires HTTPS');
$GLOBALS['opts']['home']='https://company-b.example';
$GLOBALS['opts']['litespeed.conf.optm-css_async']='1';
ok(!$p::local_css_eligible(),'Competing CSS async fails closed');
echo "PASS all portability checks\n";
