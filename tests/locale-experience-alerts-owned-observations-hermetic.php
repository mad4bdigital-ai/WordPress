<?php
/**
 * Original Locale Experience Alerts: bounded, site-scoped WordPress observation
 * import/readback, no posting/translation/SEO/media/currency mutations.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['local_fixture_options']=[];
$GLOBALS['local_fixture_admin']=false;
$GLOBALS['local_fixture_policy']=false;
$GLOBALS['local_fixture_writes']=0;
$GLOBALS['local_fixture_source_site']='11111111-1111-4111-8111-111111111111';
class WP_Error{
 private $code;public function __construct($code){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($cap){return $cap==='manage_options'&&$GLOBALS['local_fixture_admin'];}
function get_current_blog_id(){return 1;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function home_url($path=''){return 'https://tours.example.invalid'.$path;}
function wp_parse_url($s){return parse_url((string)$s);}
function has_filter($name){return $name==='wpml_active_languages';}
function apply_filters($name,$value,$args=null){
 if($name==='wpml_active_languages')return ['en'=>['language_code'=>'en'],'es'=>['language_code'=>'es']];
 if($name==='wpml_default_language')return 'en';
 return $value;
}
function get_option($name,$default=null){return $GLOBALS['local_fixture_options'][$name]??$default;}
function update_option($name,$value,$autoload=false){
 ++$GLOBALS['local_fixture_writes'];$GLOBALS['local_fixture_options'][$name]=$value;return true;
}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class MAD4B_SCP_Policy{
 public static function can_mutate(){return $GLOBALS['local_fixture_policy'];}
}
class MAD4B_SCP_Site_Profile{
 public static function site_uuid(){return $GLOBALS['local_fixture_source_site'];}
}
function la_check($ok,$reason){if(!$ok)throw new RuntimeException('FAIL LOCALE ALERTS: '.$reason);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-locale-experience-alerts.php';
$c='MAD4B_SCP_Locale_Experience_Alerts';
$now=gmdate('c');
$screens=[];
foreach(['home','archive'] as $surface)foreach(['en','es'] as $lang)
foreach(['desktop','mobile'] as $device){
 $key="$surface|$lang|$device";
 $screens[$key]=[
  'language'=>$lang,'device'=>$device,'status'=>200,
  'url'=>"https://tours.example.invalid/$lang/$surface/",
  'sliders'=>[$lang==='en'?5:2],
  'cross_locale_urls'=>$lang==='es'?['https://tours.example.invalid/en/tour/']:[],
  'asset_errors'=>$lang==='es'?[['status'=>404,'url'=>'https://tours.example.invalid/es/asset.webp']]:[],
  'suspect_labels'=>$lang==='es'?['Untranslated label']:[],
  'visible_filters'=>$surface==='archive'?($lang==='en'?['date','price']:['price']):[]
 ];
}
$input=['contract'=>'mad4b.locale-browser-observation.v1',
 'site_uuid'=>$GLOBALS['local_fixture_source_site'],'captured_at'=>$now,
 'surfaces'=>['home','archive'],'screens'=>$screens];
$denied=$c::import_observations($input);
la_check(is_wp_error($denied) &&
 $denied->get_error_code()==='locale_browser_observation_authority_required'
 && $GLOBALS['local_fixture_writes']===0,
 'Low-privilege actors may not store even apparently read-only browser evidence');
$GLOBALS['local_fixture_admin']=true;
$denied=$c::import_observations($input);
la_check(is_wp_error($denied)&&$GLOBALS['local_fixture_writes']===0,
 'Admin alone cannot bypass mutation policy for the evidence inventory');
$GLOBALS['local_fixture_policy']=true;
$invalid=$input;$invalid['site_uuid']='another-company';
$denied=$c::import_observations($invalid);
la_check(is_wp_error($denied)&&$GLOBALS['local_fixture_writes']===0,
 'Foreign site UUID cannot be imported into another company snapshot');
$invalid=$input;
$invalid['screens']['home|es|mobile']['url']='https://other.example.invalid/';
$denied=$c::import_observations($invalid);
la_check(is_wp_error($denied)&&$GLOBALS['local_fixture_writes']===0,
 'Foreign-domain page evidence must never be accepted');
$invalid=$input;$invalid['captured_at']=gmdate('c',time()-86400);
$denied=$c::import_observations($invalid);
la_check(is_wp_error($denied)&&$GLOBALS['local_fixture_writes']===0,
 'Stale browser evidence cannot overwrite current observations');
$ok=$c::import_observations($input);
la_check(is_array($ok)&&$ok['readback_verified']===true
 && $ok['screens']===8 && $GLOBALS['local_fixture_writes']===1,
 'One owner-admitted snapshot must persist exactly once');
$verified=$c::current();
la_check(is_array($verified)&&$verified['sha256']===$ok['sha256'],
 'Canonical readback must verify content hash');
$alerts=$c::discover();
$kinds=[];
foreach($alerts['issues'] as $issue){
 $kinds[$issue['code']]=true;
 la_check(($issue['source_evidence']['remediation_auto_authorized']??null)===false,
  'Visibility and browser observations cannot become automatic WPML fixes');
}
la_check($alerts['complete']===true && $alerts['fresh']===true
 && isset($kinds['lexp_visible_term_parity_es'])
 && isset($kinds['lexp_filter_visibility_drift_es'])
 && isset($kinds['lexp_asset_http_error_es'])
 && isset($kinds['lexp_cross_locale_link_es'])
 && isset($kinds['lexp_untranslated_ui_candidate_es']),
 'Observed locale drift must produce bounded review candidates, not translation changes');
$stored=$GLOBALS['local_fixture_options'][$c::OPTION];
$stored['screens']['home|es|mobile']['url']='https://unsafe.example.invalid/';
$GLOBALS['local_fixture_options'][$c::OPTION]=$stored;
la_check($c::current()===null && $c::discover()['fresh']===false,
 'Tampered option must never be treated as current signed-like evidence');
la_check($GLOBALS['local_fixture_writes']===1,
 'No issue classification or readback may modify WordPress options');
echo "PASS: LOCALE_EXPERIENCE_ALERTS_NATIVE site, owner, freshness, hash, WPML candidates, no auto-fix\n";
