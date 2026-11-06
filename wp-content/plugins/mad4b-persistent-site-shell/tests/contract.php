<?php
error_reporting(E_ALL);
define('ABSPATH','/tmp/mad4b-test/');
$GLOBALS['env']='staging';
$GLOBALS['lang']='en';
$GLOBALS['opt']=[
 'home'=>'https://brand-one.example',
 'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'11111111-1111-4111-8111-111111111111']
];
function add_action(...$args){return true;}
function add_filter(...$args){return true;}
function untrailingslashit($s){return rtrim($s,'/');}
function get_option($k,$v=false){return $GLOBALS['opt'][$k]??$v;}
function wp_parse_url($s,$p){return parse_url($s,$p);}
function wp_is_uuid($s){return (bool)preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$s);}
function wp_get_environment_type(){return $GLOBALS['env'];}
function apply_filters($k,$v){return $k==='wpml_current_language'?$GLOBALS['lang']:$v;}
function get_locale(){return 'en_US';}
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL $m\n");exit(5);}echo "PASS $m\n";}
require dirname(__DIR__).'/mad4b-persistent-site-shell.php';
$plugin='MAD4B_Persistent_Site_Shell';
ok($plugin::identity()['origin']==='https://brand-one.example','Site A canonical identity');
ok($plugin::identity()['language']==='en','Site A language');
ok(empty($plugin::settings()['enabled']),'Default no partial navigation');
ok($plugin::sanitize(['enabled'=>1])['enabled']===1,'Explicit enable accepted');
$GLOBALS['opt']['home']='https://brand-two.example/tenant';
$GLOBALS['opt']['mad4b_scp_brand_context_profile_v1']=['site_uuid'=>'22222222-2222-4222-8222-222222222222'];
$GLOBALS['env']='production';$GLOBALS['lang']='pt-br';
ok($plugin::identity()['origin']==='https://brand-two.example/tenant','Site B subpath');
ok($plugin::identity()['language']==='pt-br','Site B language not hardcoded');
$GLOBALS['opt']['home']='http://brand-two.example/tenant';
ok($plugin::identity()===[],'Insecure production origin denied');
$GLOBALS['opt']['home']='https://brand-two.example/tenant';
$GLOBALS['opt']['mad4b_scp_brand_context_profile_v1']=['site_uuid'=>'bad-id'];
ok($plugin::identity()===[],'Invalid company UUID denied');
echo "portable-shell QA PASSED\n";
