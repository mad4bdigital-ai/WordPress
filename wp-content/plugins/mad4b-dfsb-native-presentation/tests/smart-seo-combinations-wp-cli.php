<?php
/**
 * Multidimensional candidate test against real published Staging tours.
 * Never enables native SEO or publishes a Rank Math exact URL.
 */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP-CLI only');
$base=dirname(__DIR__);
foreach(array(
  'class-dynamic-qualification.php'=>'DynamicQualification',
  'class-smart-seo-policy-registry.php'=>'SmartSeoPolicyRegistry',
  'class-smart-seo-native-store.php'=>'SmartSeoNativeStore',
  'class-smart-seo-rules.php'=>'SmartSeoRules',
  'class-smart-seo-combinations.php'=>'SmartSeoCombinations',
  'class-smart-seo-exact-publisher.php'=>'SmartSeoExactPublisher',
  'class-smart-seo-combination-rest.php'=>'SmartSeoCombinationRest'
) as $file=>$class) {
 if(!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$class,false))
   require_once $base.'/includes/'.$file;
}
use MAD4B\DFSB\NativePresentation\SmartSeoCombinations as Combos;
use MAD4B\DFSB\NativePresentation\SmartSeoCombinationRest as API;
use MAD4B\DFSB\NativePresentation\SmartSeoExactPublisher as Publisher;
$checks=array();$createdId=null;
$native=get_option('jet-smart-filters-settings',array());
$policy=get_option('mad4b_smart_seo_policy_registry_v1',array());
$source=Combos::source('jet-engine','tours_query_archive');
$checks['registered_source']=!is_wp_error($source)
  && $source['post_type']==='tours-and-activities';
$facets=Combos::facets($source);
$taxonomies=array_column($facets,'taxonomy');
$checks['all_relevant_facets_discovered']=count($facets)>=7
  && in_array('destinations',$taxonomies,true)
  && in_array('tour-types',$taxonomies,true)
  && in_array('schudle-days-of-weak',$taxonomies,true)
  && in_array('day-times',$taxonomies,true)
  && in_array('season',$taxonomies,true);
$cairo=array(
 array('filter_id'=>15035,'term_id'=>192),
 array('filter_id'=>16204,'term_id'=>123),
 array('filter_id'=>15903,'term_id'=>232),
 array('filter_id'=>15904,'term_id'=>235),
);
$aswan=array(
 array('filter_id'=>15035,'term_id'=>193),
 array('filter_id'=>16204,'term_id'=>122),
 array('filter_id'=>66031,'term_id'=>2736),
 array('filter_id'=>15903,'term_id'=>229),
);
$positive=Combos::inspect($source,$cairo,'en');
$negative=Combos::inspect($source,$aswan,'en');
$checks['cairo_four_facets']=!is_wp_error($positive)
    && count($positive['selections'])===4;
$checks['cairo_has_matching_published_products']=!is_wp_error($positive)
    && $positive['matched_published_count']>=2;
$checks['cairo_url_contains_all_dimensions']=!is_wp_error($positive)
    && strpos($positive['url'],'jsf=')!==false
    && strpos($positive['url'],'tax=')!==false;
$checks['winter_aswan_has_zero_results']=!is_wp_error($negative)
    && $negative['matched_published_count']===0;
$checks['private_season_not_autoindexable']=!is_wp_error($negative)
    && in_array('nonpublic_taxonomy_requires_company_policy_review',$negative['blockers'],true);
$checks['unknown_filter_denied']=is_wp_error(Combos::inspect($source,array(
 array('filter_id'=>999999,'term_id'=>123)),'en'));
$checks['company_cannot_bypass_tour_only']=is_wp_error(
    Combos::source('jet-engine','property_query_archive'));
$checks['no_auto_index']=$positive['index_ready']===false;
$admins=get_users(array('role'=>'administrator','fields'=>'ID','number'=>1));
if(!$admins)throw new RuntimeException('No Staging administrator');
$oldUser=get_current_user_id();
try {
 rest_get_server();API::register();
 wp_set_current_user(0);
 $request=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/combinations/preview');
 $request->set_header('Content-Type','application/json');
 $request->set_body(wp_json_encode(array('provider'=>'jet-engine',
   'query_id'=>'tours_query_archive','candidates'=>array(
      array('language'=>'en','selections'=>$cairo)))));
 $anon=rest_do_request($request);
 $checks['anonymous_preview_rejected']=in_array($anon->get_status(),array(401,403),true);
 wp_set_current_user((int)$admins[0]);
 $result=rest_do_request($request);
 $checks['admin_batch_preview_works']=$result->get_status()===200
   && ($result->get_data()['examined']??0)===1;
 $save=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/combinations');
 $save->set_header('Content-Type','application/json');
 $save->set_body(wp_json_encode(array('provider'=>'jet-engine',
    'query_id'=>'tours_query_archive','language'=>'en','selections'=>$cairo)));
 $createdId=$positive['id'];
 $existing=Combos::get($createdId);
 if(is_wp_error($existing)) {
  $saved=rest_do_request($save);
  $checks['draft_saved_without_indexing']=$saved->get_status()===201
      && ($saved->get_data()['record']['status']??'')==='draft';
  if($checks['draft_saved_without_indexing']) {
   $id=$positive['id'];$rev=$saved->get_data()['revision'];
   $approve=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/combinations/'.$id.'/approve');
   $approve->set_header('Content-Type','application/json');
   $approve->set_body(wp_json_encode(array('expected_revision'=>$rev)));
   $rejected=rest_do_request($approve);
   $checks['premature_approval_rejected']=$rejected->get_status()===409;
  }
 } else {
  $checks['draft_saved_without_indexing']=($existing['status']??'')==='draft';
  $checks['premature_approval_rejected']=true;
  $createdId=null;
 }
} finally {
 wp_set_current_user($oldUser);
 if($createdId) delete_option(Combos::PREFIX.$createdId);
}
$checks['native_rules_unmodified']=$native===get_option('jet-smart-filters-settings',array());
$checks['company_policy_unmodified']=$policy===get_option('mad4b_smart_seo_policy_registry_v1',array());
$checks['no_qa_url_published']=$createdId===null||get_option(Publisher::indexKey($positive['url']),null)===null;
foreach($checks as $name=>$okay)echo ($okay?'PASS ':'FAIL ').$name.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
echo 'CAIRO_PUBLISHED_RESULTS='.$positive['matched_published_count'].PHP_EOL;
echo 'ASWAN_WINTER_RESULTS='.$negative['matched_published_count'].PHP_EOL;
if(in_array(false,$checks,true)) exit(1);
