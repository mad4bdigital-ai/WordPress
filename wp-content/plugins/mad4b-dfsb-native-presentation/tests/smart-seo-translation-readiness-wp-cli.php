<?php
/** Safe Staging WPML translation QA; cleans temporary task; NEVER edits term text. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI only');
$root=dirname(__DIR__);
foreach(['class-smart-seo-translation.php'=>'SmartSeoTranslation',
 'class-smart-seo-translation-rest.php'=>'SmartSeoTranslationRest',
 'class-smart-seo-content-graph.php'=>'SmartSeoContentGraph',
 'class-smart-seo-native-store.php'=>'SmartSeoNativeStore']as $f=>$class) {
 if(!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$class,false))
  require_once $root.'/includes/'.$f;
}
use MAD4B\DFSB\NativePresentation\SmartSeoTranslation as T;
use MAD4B\DFSB\NativePresentation\SmartSeoTranslationRest as API;
use MAD4B\DFSB\NativePresentation\SmartSeoContentGraph as G;
$checks=[];$before=get_option('jet-smart-filters-settings',[]);
$policy=get_option('mad4b_smart_seo_policy_registry_v1',[]);
$current=apply_filters('wpml_current_language',null);
$expected=['en'=>'source','ar'=>'missing_description','fr'=>'missing_description',
 'de'=>'missing_description','it'=>'candidate_for_editorial_review',
 'es'=>'candidate_for_editorial_review'];
foreach($expected as $lang=>$status){
 $info=T::inspect('destinations',123,$lang);
 $checks['cairo_'.$lang]=!is_wp_error($info)&&$info['status']===$status;
 $graph=G::term(123,'destinations',$lang);
 $checks['graph_'.$lang]=!is_wp_error($graph)
  && ($graph['wpml_read_language']??'')===$lang
  && ($graph['term_language']??'')===$lang
  && (($lang==='en'||$lang==='it'||$lang==='es')
       ?$graph['classification']==='editorial_hub_candidate'
       :$graph['classification']==='missing_translation');
}
$day=T::inspect('tour-types',192,'fr');
$checks['day_tours_fr_missing']=!is_wp_error($day)&&$day['status']==='missing_description';
$checks['no_wpml_language_leak']=$current===apply_filters('wpml_current_language',null);
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if(!$admins)throw new RuntimeException('Administrator missing');
$id=T::taskId('destinations',123,'fr');
if(!is_wp_error(T::get($id)))throw new RuntimeException('QA task already exists; cannot overwrite');
$oldUser=get_current_user_id();
try {
 rest_get_server();API::register();
 wp_set_current_user(0);
 $req=new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/translations/inspect');
 $req->set_param('taxonomy','destinations');$req->set_param('source_id',123);
 $anon=rest_do_request($req);
 $checks['anonymous_rejected']=in_array($anon->get_status(),[401,403],true);
 wp_set_current_user((int)$admins[0]);
 $admin=rest_do_request($req);
 $checks['admin_inspect']=$admin->get_status()===200
   && ($admin->get_data()['locales']['fr']['status']??'')==='missing_description';
 $prepare=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/translations/tasks');
 $prepare->set_header('Content-Type','application/json');
 $prepare->set_body(wp_json_encode(['taxonomy'=>'destinations','source_id'=>123,'language'=>'fr']));
 $saved=rest_do_request($prepare);
 $checks['task_prepared']=$saved->get_status()===201
   && ($saved->get_data()['record']['status']??'')==='pending_translation';
 $rev=(string)($saved->get_data()['revision']??'');
 $apply=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/translations/tasks/'.$id.'/apply');
 $apply->set_header('Content-Type','application/json');
 $apply->set_body(wp_json_encode(['expected_revision'=>$rev]));
 $denied=rest_do_request($apply);
 $checks['unreviewed_apply_blocked']=$denied->get_status()===409;
 $reviews=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/translations/tasks/'.$id.'/review');
 $reviews->set_header('Content-Type','application/json');
 $reviews->set_body(wp_json_encode(['expected_revision'=>$rev,'approved'=>true]));
 $bad=rest_do_request($reviews);
 $checks['no_candidate_review_denied']=$bad->get_status()===409;
 $preserve=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/translations/tasks');
 $preserve->set_header('Content-Type','application/json');
 $preserve->set_body(wp_json_encode(['taxonomy'=>'destinations','source_id'=>123,'language'=>'it']));
 $guard=rest_do_request($preserve);
 $checks['existing_italian_description_protected']=$guard->get_status()===409
  && ($guard->get_data()['code']??'')==='translation_existing_content';
 $duplicate=rest_do_request($prepare);
 $checks['duplicate_prepare_denied']=$duplicate->get_status()===409;
} finally {
 wp_set_current_user($oldUser);
 delete_option(T::recordKey($id));
}
$checks['task_cleaned']=is_wp_error(T::get($id));
$checks['native_settings_unchanged']=$before===get_option('jet-smart-filters-settings',[]);
$checks['policy_unchanged']=$policy===get_option('mad4b_smart_seo_policy_registry_v1',[]);
$checks['language_restored']=$current===apply_filters('wpml_current_language',null);
foreach($checks as $k=>$v)echo ($v?'PASS ':'FAIL ').$k.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
