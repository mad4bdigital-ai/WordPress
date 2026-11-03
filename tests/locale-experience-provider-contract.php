<?php
/* Hermetic runtime regressions: neither real WP nor network access. */
error_reporting(E_ALL);
define('ABSPATH','/offline/');
final class WP_Error {private $code; function __construct($c){$this->code=$c;} function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
final class MAD4B_SCP_Site_Profile {
    static function site_uuid(){return $GLOBALS['site'];}
}
final class MAD4B_SCP_Policy {
    static function can_mutate(){return $GLOBALS['authorized'];}
}
$GLOBALS['site']='test-company-site-7ab299b3-a272-4df2-9f9a-86a848062231';
$GLOBALS['authorized']=true;
$GLOBALS['cap']=true;
$GLOBALS['options']=[];
$GLOBALS['langs']=['en','fr','de','es','it','ar'];
function get_current_blog_id(){return 1;}
function current_user_can($cap){return $GLOBALS['cap'] && $cap==='manage_options';}
function get_option($k,$default=null){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function has_filter($tag){return in_array($tag,['wpml_active_languages','wpml_default_language'],true);}
function apply_filters($tag,$initial,...$extra){
 if($tag==='wpml_default_language')return 'en';
 if($tag==='wpml_active_languages') {
     $x=[];foreach($GLOBALS['langs'] as $lang)$x[$lang]=['language_code'=>$lang];
     return $x;
 }
 return $initial;
}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
function sanitize_text_field($s){return strip_tags((string)$s);}
function wp_parse_url($u){return parse_url($u);}
function home_url($p='/'){return 'https://example.test'.(string)$p;}
function wp_json_encode($data,$flags=0){return json_encode($data,$flags|JSON_THROW_ON_ERROR);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-locale-experience-alerts.php';
$checks=[];
function test_case($key,$ok){global $checks;$checks[$key]=$ok; if(!$ok)echo "FAIL $key\n";}
$site=$GLOBALS['site'];$langs=$GLOBALS['langs'];
$screens=[];
$counts=['en'=>7,'fr'=>3,'de'=>3,'es'=>6,'it'=>6,'ar'=>5];
foreach(['home','archive'] as $surface) foreach($langs as $lang) foreach(['desktop','mobile'] as $device) {
  $prefix=$lang==='en'?'':'/'.$lang;
  $data=[
    'language'=>$lang,'device'=>$device,'status'=>200,
    'url'=>'https://example.test'.$prefix.($surface==='home'?'/':'/tours-and-activities/'),
    'sliders'=>$surface==='home'?[$counts[$lang],6]:[],
    'cross_locale_urls'=>$surface==='home'?[
      $lang==='en'?'https://example.test/ar/destinations/':'https://example.test/destinations/'
    ]:[],
    'asset_errors'=>($surface==='home' && $lang!=='en')?[
      ['url'=>'https://example.test'.$prefix.'/wp-content/uploads/hero.webp','status'=>404]
    ]:[],
    'suspect_labels'=>($surface==='home'&&$lang==='ar')
      ||($surface==='archive'&&in_array($lang,['fr','de'],true))
      ?['English label']:[],
    'visible_filters'=>$surface==='archive'?
      (in_array($lang,['ar','fr','es','it'],true)?
       ['start_price','destinations','tour-types']:['start_price','destinations']):[]
  ];
  $screens[$surface.'|'.$lang.'|'.$device]=$data;
}
$raw=[
 'contract'=>'mad4b.locale-browser-observation.v1',
 'site_uuid'=>$site,'captured_at'=>gmdate('c'),
 'surfaces'=>['home','archive'],'screens'=>$screens
];
$r=MAD4B_SCP_Locale_Experience_Alerts::import_observations($raw);
test_case('import_24_complete_first_party_screens',!is_wp_error($r)&&
 ($r['screens']??0)===24 && $r['readback_verified']===true);
$loaded=MAD4B_SCP_Locale_Experience_Alerts::current();
test_case('sha256_readback_and_site_identity',is_array($loaded)&&
 $loaded['sha256']===$r['sha256']&&$loaded['site_uuid']===$site);
$result=MAD4B_SCP_Locale_Experience_Alerts::discover();
$issues=$result['issues'];
test_case('expected_23_deduplicated_localized_alerts',
 count($issues)===23&&count(array_unique(array_column($issues,'id')))===23);
$codes=array_column($issues,'code');
foreach(['cross_locale_link'=>6,'asset_http_error'=>5,'visible_term_parity'=>5,
          'untranslated_ui_candidate'=>3,'filter_visibility_drift'=>4] as $kind=>$n){
 $actual=count(array_filter($codes,static fn($x)=>str_starts_with($x,'lexp_'.$kind.'_')));
 test_case('category_'.$kind.'_'.$n,$actual===$n);
}
test_case('browser_counts_are_unverified_candidates',
 count(array_filter($issues,static fn($x)=>str_starts_with($x['code'],'lexp_visible_term_parity_')
 && $x['severity']==='medium'
 && $x['source_evidence']['remediation_auto_authorized']===false))===5);
$before=get_option(MAD4B_SCP_Locale_Experience_Alerts::OPTION);
$bad=$raw;$bad['site_uuid']='another-company-site';
test_case('reject_cross_tenant',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad)));
$bad=$raw;$bad['captured_at']='2020-01-01T00:00:00Z';
test_case('reject_stale_observations',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad)));
$bad=$raw;$bad['screens']['archive|ar|mobile']['status']=504;
test_case('reject_unavailable_locale_page',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad)));
$bad=$raw;unset($bad['screens']['home|fr|desktop']);
test_case('reject_partial_browser_sample',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad)));
$bad=$raw;$bad['screens']['home|fr|desktop']['cross_locale_urls']=['https://outside.example/destination'];
test_case('reject_nonmatching_site_hostname',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad)));
$bad=$raw;$bad['screens']['home|fr|desktop']['cross_locale_urls']=['https://admin@example.test/foo'];
test_case('reject_credentials_in_url',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad)));
$bad=$raw;$bad['screens']['home|fr|desktop']['sliders']=[999999];
test_case('reject_unbounded_listing_card_counts',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad)));
$GLOBALS['cap']=false;
test_case('reject_unauthenticated_operator',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($raw)));
$GLOBALS['cap']=true;
$GLOBALS['authorized']=false;
test_case('reject_original_write_policy_denial',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($raw)));
$GLOBALS['authorized']=true;
test_case('failed_imports_did_not_mutate_original_option',
 get_option(MAD4B_SCP_Locale_Experience_Alerts::OPTION)===$before);
$tampered=$before;$tampered['screens']['home|fr|mobile']['sliders'][0]=12;
$GLOBALS['options'][MAD4B_SCP_Locale_Experience_Alerts::OPTION]=$tampered;
test_case('reject_snapshot_integrity_tamper',MAD4B_SCP_Locale_Experience_Alerts::current()===null);
$GLOBALS['options'][MAD4B_SCP_Locale_Experience_Alerts::OPTION]=$before;
$GLOBALS['site']='wrong-site';
test_case('reject_replay_across_current_site',MAD4B_SCP_Locale_Experience_Alerts::current()===null);
$GLOBALS['site']='test-company-site-7ab299b3-a272-4df2-9f9a-86a848062231';
$GLOBALS['langs']=['en','fr'];
$bad=$raw;
test_case('reject_wrong_wpml_active_languages',is_wp_error(MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad)));
$GLOBALS['langs']=$langs;
test_case('recovered_exact_snapshot',MAD4B_SCP_Locale_Experience_Alerts::current()!==null);
$failed=array_keys(array_filter($checks,static fn($pass)=>!$pass));
echo json_encode(['contract'=>'mad4b.locale-experience.offline.v1',
'success'=>count($failed)===0,'passed'=>count($checks)-count($failed),
'total'=>count($checks),'failures'=>$failed],JSON_UNESCAPED_SLASHES),PHP_EOL;
echo 'SUMMARY checks='.count($checks).' failures='.count($failed),PHP_EOL;
if($failed)exit(1);
