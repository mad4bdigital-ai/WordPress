<?php
/**
 * Real WPML archive / Rank Math canonical cross-language QA.
 * Staging only. No native SEO activation or WordPress option writes.
 */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP-CLI only');
$root=dirname(__DIR__);
foreach(array(
 'class-dynamic-qualification.php'=>'DynamicQualification',
 'class-smart-seo-policy-registry.php'=>'SmartSeoPolicyRegistry',
 'class-smart-seo-native-store.php'=>'SmartSeoNativeStore',
 'class-smart-seo-rules.php'=>'SmartSeoRules',
 'class-smart-seo-combinations.php'=>'SmartSeoCombinations',
 'class-smart-seo-exact-publisher.php'=>'SmartSeoExactPublisher',
 'class-smart-seo-combination-rest.php'=>'SmartSeoCombinationRest'
)as $file=>$class) {
 if(!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$class,false))
   require_once $root.'/includes/'.$file;
}
use MAD4B\DFSB\NativePresentation\SmartSeoCombinations as C;
use MAD4B\DFSB\NativePresentation\SmartSeoCombinationRest as API;
$checks=array();
$settingsBefore=get_option('jet-smart-filters-settings',array());
$policyBefore=get_option('mad4b_smart_seo_policy_registry_v1',array());
$source=C::source('jet-engine','tours_query_archive');
$checks['company_registered_archive']=!is_wp_error($source) &&
 $source['post_type']==='tours-and-activities';
if(is_wp_error($source))throw new RuntimeException($source->get_error_message());
$languages=array('en','ar','fr','de','it','es');
foreach($languages as $lang) {
 $route=C::archiveRoute($source,$lang);
 $expected=home_url(($lang==='en'?'':'/'.$lang).'/tours-and-activities/');
 $checks['route_'.$lang.'_correct']=$route['url']===$expected
   && $route['same_site_host']===true && $route['route_distinct_from_english']===true;
 $response=wp_safe_remote_get($route['url'],array(
   'timeout'=>16,'redirection'=>0,'limit_response_size'=>650000,
   'headers'=>array('Accept'=>'text/html')));
 $http=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
 $body=is_wp_error($response)?'':(string)wp_remote_retrieve_body($response);
 preg_match('/<html\\b[^>]*\\blang=["\x27]([^"\x27]+)/i',$body,$htmlLang);
 preg_match('/<link\\s+rel=["\x27]canonical["\x27]\\s+href=["\x27]([^"\x27]+)/i',$body,$canonical);
 $prefix=$lang==='en'?'en':$lang;
 $checks['route_'.$lang.'_real_http_and_html_lang']=$http===200
   && str_starts_with(strtolower((string)($htmlLang[1]??'')),$prefix);
 $checks['route_'.$lang.'_real_canonical']=$http===200
   && rtrim(html_entity_decode((string)($canonical[1]??''),ENT_QUOTES,'UTF-8'),'/')
   ===rtrim($route['url'],'/');
 echo 'WPML_ROUTE '.$lang.' STATUS='.$http.' HTML_LANG='.
   ($htmlLang[1]??'missing').' URL='.$route['url'].PHP_EOL;
}
$four=array(
 array('filter_id'=>15035,'term_id'=>192),
 array('filter_id'=>16204,'term_id'=>123),
 array('filter_id'=>15903,'term_id'=>232),
 array('filter_id'=>15904,'term_id'=>235),
);
$withGuide=C::withGuideLanguage($source,$four,'french');
$checks['guide_language_source_registered']=is_array($withGuide)
  && count($withGuide)===5;
foreach(array('en','ar','fr')as $lang) {
 $candidate=C::inspect($source,$withGuide,$lang);
 $route=C::archiveRoute($source,$lang);
 $checks['filtered_'.$lang.'_correct_url']=!is_wp_error($candidate)
   && str_starts_with($candidate['url'],$route['url'].'?')
   && !in_array('localized_archive_url_not_distinct',$candidate['blockers'],true)
   && ($candidate['guide_language']['slug']??'')==='french'
   && $candidate['site_language']===$lang
   && $candidate['index_ready']===false;
 if(!is_wp_error($candidate))
   echo 'FILTERED '.$lang.' COUNT='.$candidate['matched_published_count'].
     ' URL='.$candidate['url'].PHP_EOL;
}
$admins=get_users(array('role'=>'administrator','fields'=>'ID','number'=>1));
if(!$admins)throw new RuntimeException('No WordPress administrator');
$oldUser=get_current_user_id();
try {
 rest_get_server();API::register();wp_set_current_user(0);
 $req=new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/combinations/archive-routes');
 $req->set_param('provider','jet-engine');$req->set_param('query_id','tours_query_archive');
 $result=rest_do_request($req);
 $checks['anonymous_routes_denied']=in_array($result->get_status(),array(401,403),true);
 wp_set_current_user((int)$admins[0]);
 $result=rest_do_request($req);
 $routes=(array)($result->get_data()['routes']??array());
 $checks['admin_routes_read_only']=$result->get_status()===200
   && count($routes)===6
   && ($routes['ar']['route_distinct_from_english']??false)
   && ($result->get_data()['seo_publication_authorized']??true)===false;
} finally {
 wp_set_current_user($oldUser);
}
$checks['jet_smartfilters_unchanged']=$settingsBefore===get_option('jet-smart-filters-settings',array());
$checks['seo_company_policy_unchanged']=$policyBefore===get_option('mad4b_smart_seo_policy_registry_v1',array());
foreach($checks as $name=>$ok)echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
