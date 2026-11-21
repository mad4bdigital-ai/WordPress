<?php
declare(strict_types=1);
define('ABSPATH','/test/');
define('WP_CONTENT_DIR','/tmp/mad4b-wpml-test-content-missing/');
$GLOBALS['mock_host'] = 'tours.dev.mad4b.com';
$GLOBALS['mock_options'] = [
  'mad4b_perf_wpml_first_paint_all_languages_v1' => 'off',
  'icl_sitepress_settings'=>['active_languages'=>['en','es','de','fr','ar','it'],'default_language'=>'en']
];
$GLOBALS['hooks']=[];
function get_option($key,$default=false) {return $GLOBALS['mock_options'][$key]??$default;}
function home_url($route='/') {return 'https://'.$GLOBALS['mock_host'].$route;}
function wp_parse_url($url,$part=-1) {return parse_url($url,$part);}
function add_filter($hook,$callback,$priority=10,$argc=1) {$GLOBALS['hooks'][$hook]=$callback;}
function add_action($hook,$callback,$priority=10,$argc=1) {$GLOBALS['hooks'][$hook]=$callback;}
function wp_json_encode($value,$flags=0) {return json_encode($value,$flags);}
function update_option($key,$value,$autoload=false) {$GLOBALS['mock_options'][$key]=$value;return true;}
function delete_option($key) {unset($GLOBALS['mock_options'][$key]);return true;}
function wp_doing_cron() {return false;}

require '/tmp/mad4b-first-paint-wpml-inheritance-v2-lint.php';
function assertBridge($label,$actual,$expected):void {
 if($actual!==$expected)throw new RuntimeException($label.' mismatch '.json_encode($actual));
 echo "PASS $label\n";
}
$orig=['en','es'];
assertBridge('opt-in required before changing legacy site behavior',
 MAD4B_WPML_First_Paint_Languages_V1::inherit($orig),$orig);
$GLOBALS['mock_options']['mad4b_perf_wpml_first_paint_all_languages_v1']='on';
assertBridge('all six active WPML languages inherited',
 MAD4B_WPML_First_Paint_Languages_V1::inherit($orig),['en','es','de','fr','ar','it']);
assertBridge('both native hero option hooks registered',
 array_slice(array_keys($GLOBALS['hooks']),0,2),
 ['option_mad4b_perf_mobile_hero_priority_languages_v1','option_mad4b_perf_hero_responsive_languages_v1']);
assertBridge('both native hero options receive the same dynamic registry',
 ($GLOBALS['hooks']['option_mad4b_perf_hero_responsive_languages_v1'])($orig),
 ['en','es','de','fr','ar','it']);
$GLOBALS['mock_options']['icl_sitepress_settings']['active_languages'][]='nl';
assertBridge('a new two-letter WPML language is enrolled without code edits',
 MAD4B_WPML_First_Paint_Languages_V1::inherit($orig),['en','es','de','fr','ar','it','nl']);
$GLOBALS['mock_options']['icl_sitepress_settings']['active_languages'][]='pt-br';
assertBridge('unsupported custom-code registry fails closed',
 MAD4B_WPML_First_Paint_Languages_V1::inherit($orig),$orig);
$GLOBALS['mock_options']['icl_sitepress_settings']['active_languages']=['en','es'];
$GLOBALS['mock_options']['icl_sitepress_settings']['default_language']='fr';
assertBridge('invalid default registry fails closed',
 MAD4B_WPML_First_Paint_Languages_V1::inherit($orig),$orig);
$GLOBALS['mock_options']['icl_sitepress_settings']['default_language']='en';
$GLOBALS['mock_host']='example.org';
assertBridge('untrusted company host cannot change hero languages',
 MAD4B_WPML_First_Paint_Languages_V1::inherit($orig),$orig);
$GLOBALS['mock_host']='wayfatours.com';
$GLOBALS['mock_options']['mad4b_perf_wpml_first_paint_all_languages_v1']='off';
assertBridge('Wayfatours untouched until company opt-in',
 MAD4B_WPML_First_Paint_Languages_V1::inherit($orig),$orig);
$settings=['active_languages'=>['en','fr','de','it','ar','es'],'default_language'=>'en'];
assertBridge('automatic cache paths follow six active languages',
 MAD4B_WPML_First_Paint_Languages_V1::home_paths($settings),
 ['/','/fr/','/de/','/it/','/ar/','/es/']);
assertBridge('invalid WPML code prevents any cache purge',
 MAD4B_WPML_First_Paint_Languages_V1::home_paths(['active_languages'=>['en','pt-br'],'default_language'=>'en']),[]);
assertBridge('default language may change without hardcoded EN',
 MAD4B_WPML_First_Paint_Languages_V1::home_paths(['active_languages'=>['fr','de','en'],'default_language'=>'fr']),
 ['/','/de/','/en/']);
$GLOBALS['mock_host']='tours.dev.mad4b.com';
$GLOBALS['mock_options']['mad4b_perf_wpml_first_paint_all_languages_v1']='on';
($GLOBALS['hooks']['updated_option'])('icl_sitepress_settings',
 ['active_languages'=>['en','es'],'default_language'=>'en'],$settings);
assertBridge('failed selective origin purge queues safe retry',
 $GLOBALS['mock_options']['mad4b_wpml_firstpaint_purge_retry_v1']['paths'],
 ['/','/es/','/fr/','/de/','/it/','/ar/']);
assertBridge('auto-purge hooks registered for settings and idle retry',
 isset($GLOBALS['hooks']['updated_option'],$GLOBALS['hooks']['wp_loaded']),true);
assertBridge('new companies inherit WPML even when legacy option row missing',
 ($GLOBALS['hooks']['default_option_mad4b_perf_mobile_hero_priority_languages_v1'])($orig),
 ['en','es']);
assertBridge('responsive mode also supports missing legacy option row',
 ($GLOBALS['hooks']['default_option_mad4b_perf_hero_responsive_languages_v1'])($orig),
 ['en','es']);
echo "PASS 16/16 first-paint native WPML inheritance contracts\n";
