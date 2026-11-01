<?php
/** Read-only local regression against an installed WPML + MAD4B Lab runtime. */
$root=getenv('WP_ROOT')?:dirname(__DIR__,4);
require_once rtrim($root,'/').'/wp-load.php';
if(wp_get_environment_type()!=='local'||!class_exists('MAD4B_SCP_Market_Profiles')||!function_exists('shared_offer_current_visible_language')){
 echo "SKIP local WPML + seasonal integration required\n";exit(0);
}
$c='MAD4B_SCP_Market_Profiles';$uuid=$c::site_uuid();
$settings=get_option('icl_sitepress_settings',[]);
if(!is_array($settings)||!in_array('es',(array)($settings['active_languages']??[]),true)){
 echo "SKIP installed WPML Spanish required\n";exit(0);
}
$fail=0;
$check=static function($name,$condition)use(&$fail){echo $name.'='.($condition?'PASS':'FAIL')."\n";if(!$condition)$fail++;};
$record=['contract'=>$c::CONTRACT,'profile_id'=>'test_us_es','site_uuid'=>$uuid,'environment'=>'local',
 'label'=>'Spanish US test','country'=>'US','language'=>'es','status'=>'approved',
 'revision'=>1,'approved_revision'=>1,'approved_at'=>'2026-10-24 12:00:00'];
$pre_records=static fn()=>['test_us_es'=>$record];
add_filter('pre_option_'.$c::OPTION,$pre_records);
$es_current=static fn()=> 'es';
add_filter('wpml_current_language',$es_current,9999);
$check('SITE_HAS_SPANISH',isset($c::enabled_languages()['es']));
$check('US_ES_APPROVED',$c::by_country('US','es')!==[]);
$check('US_EN_NOT_APPROVED',$c::by_country('US','en')===[]);
$check('ES_ES_NOT_APPROVED',$c::by_country('ES','es')===[]);
$check('CURRENT_VISIBLE_ES',shared_offer_current_visible_language()==='es');
$hidden=$settings;$hidden['hidden_languages']=array_values(array_unique(array_merge((array)($settings['hidden_languages']??[]),['es'])));
$mock_hidden=static fn()=> $hidden;
add_filter('pre_option_icl_sitepress_settings',$mock_hidden);
$check('HIDDEN_LANGUAGE_EXCLUDED',!isset($c::enabled_languages()['es']));
$check('HIDDEN_LANGUAGE_NOT_CURRENT',shared_offer_current_visible_language()==='');
$check('HIDDEN_MARKET_NO_APPROVAL',$c::approved('test_us_es')===[]);
$check('HIDDEN_LANGUAGE_NO_SUGGESTIONS',$c::suggestions('es')===[]);
$check('HIDDEN_COUNTRY_MARKET',$c::by_country('US','es')===[]);
$check('HIDDEN_NOT_IN_READ_ABILITY',empty($c::read_ability(['country'=>'US'])['profiles']));
$check('HIDDEN_SEASONAL_SELECTION',shared_offer_seasonal_selection('2026-10-24','US','')===null);
remove_filter('pre_option_icl_sitepress_settings',$mock_hidden);
$check('UNHIDE_RESUMES_ELIGIBILITY',$c::approved('test_us_es')!==[]);
remove_filter('pre_option_'.$c::OPTION,$pre_records);
remove_filter('wpml_current_language',$es_current,9999);
$check('STORED_PROFILES_UNMODIFIED',count($c::records())===0);
exit($fail?1:0);
