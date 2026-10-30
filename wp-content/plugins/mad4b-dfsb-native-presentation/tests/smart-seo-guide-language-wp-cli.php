<?php
/** Read-only five-facet guide-language and WPML separation QA. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP-CLI required');
$root=dirname(__DIR__);
foreach(array(
 'class-dynamic-qualification.php'=>'DynamicQualification',
 'class-smart-seo-policy-registry.php'=>'SmartSeoPolicyRegistry',
 'class-smart-seo-native-store.php'=>'SmartSeoNativeStore',
 'class-smart-seo-rules.php'=>'SmartSeoRules',
 'class-smart-seo-combinations.php'=>'SmartSeoCombinations',
 'class-smart-seo-exact-publisher.php'=>'SmartSeoExactPublisher',
 'class-smart-seo-combination-rest.php'=>'SmartSeoCombinationRest',
)as $file=>$cls)
 if(!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$cls,false))
    require_once $root.'/includes/'.$file;
use MAD4B\DFSB\NativePresentation\SmartSeoCombinations as C;
use MAD4B\DFSB\NativePresentation\SmartSeoCombinationRest as API;
$checks=array();$nativeBefore=get_option('jet-smart-filters-settings',array());
$policyBefore=get_option('mad4b_smart_seo_policy_registry_v1',array());
$source=C::source('jet-engine','tours_query_archive');
$checks['source_uses_existing_mu_registry']=!is_wp_error($source)
 && !empty($source['facet_filter_ids']['guide-languages']);
$guide=C::guideLanguages($source);
$checks['native_guide_filter_discovered']=($guide['filter_id']??0)===15034
 && count($guide['terms'])===5
 && ($guide['separate_from_wpml_page_language']??false)===true;
$checks['qa_term_is_not_a_filter_choice']=!in_array(2860,
 array_column($guide['terms'],'term_id'),true);
$four=array(
 array('filter_id'=>15035,'term_id'=>192),
 array('filter_id'=>16204,'term_id'=>123),
 array('filter_id'=>15903,'term_id'=>232),
 array('filter_id'=>15904,'term_id'=>235),
);
$five=C::withGuideLanguage($source,$four,'french');
$checks['native_guide_language_expands_dynamically']=is_array($five)
 && count($five)===5 && $five[4]['filter_id']===15034 && $five[4]['term_id']===144;
$frGuide=C::inspect($source,$five,'en');
$checks['five_facet_guide_match']=!is_wp_error($frGuide)
 && count($frGuide['selections'])===5 && $frGuide['matched_published_count']===4;
$checks['guide_language_independent_of_wpml']=($frGuide['site_language']??'')==='en'
 && ($frGuide['guide_language']['slug']??'')==='french'
 && $frGuide['guide_language']['date_specific_availability_verified']===false;
$checks['guide_is_in_native_filter_url']=strpos($frGuide['url'],'guide-languages')!==false;
$arSite=C::inspect($source,$five,'ar');
$checks['guide_term_translate_independently']=!is_wp_error($arSite)
 && ($arSite['site_language']??'')==='ar'
 && ($arSite['guide_language']['slug']??'')==='french'
 && $arSite['matched_published_count']===4;
$checks['localized_archive_not_published_without_route']=!is_wp_error($arSite)
 && in_array('localized_archive_url_not_distinct',$arSite['blockers'],true)
 && $arSite['index_ready']===false;
$arabic=C::withGuideLanguage($source,$four,'arabic');
$noGuide=C::inspect($source,$arabic,'en');
$checks['arabic_zero_match_blocks_index']=!is_wp_error($noGuide)
 && $noGuide['matched_published_count']===0
 && in_array('not_enough_published_matching_products',$noGuide['blockers'],true);
$checks['guide_slug_unknown_rejected']=is_wp_error(
 C::withGuideLanguage($source,$four,'invalid-language'));
$checks['qa_guide_term_rejected']=is_wp_error(
 C::withGuideLanguage($source,$four,2860));
$checks['duplicate_guide_facet_rejected']=is_wp_error(
 C::withGuideLanguage($source,$five,'french'));
$checks['no_automatic_publication']=$frGuide['index_ready']===false;
$admin=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));
if(!$admin)throw new RuntimeException('No staging admin');
$oldUser=get_current_user_id();
try {
 rest_get_server();API::register();wp_set_current_user(0);
 $unauthorized=new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/combinations/guide-languages');
 $unauthorized->set_param('provider','jet-engine');
 $unauthorized->set_param('query_id','tours_query_archive');
 $res=rest_do_request($unauthorized);
 $checks['anonymous_language_choices_denied']=in_array($res->get_status(),array(401,403),true);
 wp_set_current_user((int)$admin[0]);
 $res=rest_do_request($unauthorized);
 $checks['admin_guide_choices_available']=$res->get_status()===200
   && ($res->get_data()['guide_languages']['filter_id']??0)===15034;
 $body=array('provider'=>'jet-engine','query_id'=>'tours_query_archive',
    'candidates'=>array(array('language'=>'en','guide_language'=>'french',
      'selections'=>$four)));
 $req=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/combinations/preview');
 $req->set_header('Content-Type','application/json');$req->set_body(wp_json_encode($body));
 $res=rest_do_request($req);$data=$res->get_data();
 $checks['rest_fifth_dimension_preview']=$res->get_status()===200
    && ($data['results'][0]['guide_language']['slug']??'')==='french'
    && ($data['results'][0]['matched_published_count']??-1)===4;
} finally {wp_set_current_user($oldUser);}
$checks['native_seo_settings_unchanged']=$nativeBefore===get_option('jet-smart-filters-settings',array());
$checks['policy_unchanged']=$policyBefore===get_option('mad4b_smart_seo_policy_registry_v1',array());
foreach($checks as $name=>$ok)echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
echo 'FRENCH_GUIDE_MATCHING_TOURS='.($frGuide['matched_published_count']??-1).PHP_EOL;
echo 'ARABIC_GUIDE_MATCHING_TOURS='.($noGuide['matched_published_count']??-1).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
