<?php
/** Isolated candidate-record REST lifecycle; cleans up its QA option. */
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
) as $file=>$cls) {
 if(!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$cls,false))
  require_once $base.'/includes/'.$file;
}
use MAD4B\DFSB\NativePresentation\SmartSeoCombinations as C;
use MAD4B\DFSB\NativePresentation\SmartSeoCombinationRest as API;
use MAD4B\DFSB\NativePresentation\SmartSeoExactPublisher as Publisher;
$sample=array(
 array('filter_id'=>15035,'term_id'=>192),
 array('filter_id'=>16204,'term_id'=>123),
 array('filter_id'=>15903,'term_id'=>232),
 array('filter_id'=>15904,'term_id'=>235),
);
$source=C::source('jet-engine','tours_query_archive');
$preview=C::inspect($source,$sample,'en');
if(is_wp_error($preview))throw new RuntimeException($preview->get_error_code());
$id=$preview['id'];$savedBefore=C::get($id);
if(!is_wp_error($savedBefore))throw new RuntimeException('Test candidate exists; refusing to overwrite');
$nativeBefore=get_option('jet-smart-filters-settings',array());
$oldUser=get_current_user_id();$checks=array();
$admin=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));
if(!$admin)throw new RuntimeException('Administrator unavailable');
try {
  rest_get_server();API::register();wp_set_current_user((int)$admin[0]);
  $save=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/combinations');
  $save->set_header('Content-Type','application/json');
  $save->set_body(wp_json_encode(array('provider'=>'jet-engine','query_id'=>'tours_query_archive',
      'language'=>'en','selections'=>$sample)));
  $response=rest_do_request($save);
  $checks['save_draft']=$response->get_status()===201;
  $rev=$response->get_data()['revision']??'';
  $locale=new WP_REST_Request('PUT','/mad4b/v1/seo-intelligence/combinations/'.$id.'/localize');
  $locale->set_header('Content-Type','application/json');
  $locale->set_body(wp_json_encode(array('expected_revision'=>$rev,'language'=>'en',
    'title'=>'Day Tours in Cairo on Wednesday Mornings | Wo Vacation',
    'description'=>'Browse published day tours in Cairo scheduled for Wednesday mornings, with available experiences to compare before booking.')));
  $response=rest_do_request($locale);
  $checks['localize_draft']=$response->get_status()===200;
  $rev=$response->get_data()['revision']??'';
  $stale=rest_do_request($locale);
  $checks['stale_revision_denied']=$stale->get_status()===409;
  $verify=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/combinations/'.$id.'/verify');
  $verify->set_header('Content-Type','application/json');
  $verify->set_body(wp_json_encode(array('expected_revision'=>$rev)));
  $response=rest_do_request($verify);
  $checks['real_url_verification_completed']=$response->get_status()===200;
  $record=$response->get_data()['record']??array();
  echo 'HTTP_CHECK='.(int)($record['seo_probe']['http_status']??0).PHP_EOL;
  echo 'VERIFIED_STATE='.(string)($record['status']??'unknown').PHP_EOL;
  $checks['evidence_recorded']=array_key_exists('matched_published_count',$record['result_evidence']??array())
     && isset($record['seo_probe']['http_status']);
  $checks['no_implicit_index']=$record['status']!=='published';
  $checks['no_native_seo_changes']=$nativeBefore===get_option('jet-smart-filters-settings',array());
  $checks['unapproved_rankmath_not_published']=get_option(Publisher::indexKey($preview['url']),null)===null;
} finally {
  delete_option(C::PREFIX.$id);
  wp_set_current_user($oldUser);
}
$checks['qa_record_removed']=is_wp_error(C::get($id));
foreach($checks as $n=>$yes)echo($yes?'PASS ':'FAIL ').$n.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
