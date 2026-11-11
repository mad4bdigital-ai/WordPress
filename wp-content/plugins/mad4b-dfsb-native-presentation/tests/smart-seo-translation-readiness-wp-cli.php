<?php
/** Site-safe WPML translation QA: uses an unused missing-description term, never touches real tasks or term text. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI only');
$root=dirname(__DIR__);
foreach(['class-smart-seo-translation.php'=>'SmartSeoTranslation',
 'class-smart-seo-translation-rest.php'=>'SmartSeoTranslationRest',
 'class-smart-seo-content-graph.php'=>'SmartSeoContentGraph',
 'class-smart-seo-native-store.php'=>'SmartSeoNativeStore']as $file=>$class) {
 if(!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$class,false))
  require_once $root.'/includes/'.$file;
}
use MAD4B\DFSB\NativePresentation\SmartSeoTranslation as T;
use MAD4B\DFSB\NativePresentation\SmartSeoTranslationRest as API;
use MAD4B\DFSB\NativePresentation\SmartSeoContentGraph as G;
$checks=[];$nativeBefore=get_option('jet-smart-filters-settings',[]);
$policyBefore=get_option('mad4b_smart_seo_policy_registry_v1',[]);
$current=apply_filters('wpml_current_language',null);
foreach(['en','ar','fr','de','it','es']as $lang) {
 $i=T::inspect('destinations',123,$lang);
 $graph=G::term(123,'destinations',$lang);
 $expected='source';
 if($lang!=='en' && !is_wp_error($i)) {
  $len=(int)($i['target']['description_chars']??0);
  $expected=$len===0?'missing_description':
   (($i['target']['content_sha256']??'')===$i['source']['content_sha256']
   ?'source_text_copied':($len<180?'short_content_review':'candidate_for_editorial_review'));
 }
 $checks['cairo_'.$lang]=!is_wp_error($i)&&$i['status']===$expected&&$i['target_id']>0;
 $rich=$lang==='en'||($i['target']['description_chars']??0)>=180;
 $checks['graph_'.$lang]=!is_wp_error($graph)
  && ($graph['wpml_read_language']??'')===$lang
  && ($graph['term_language']??'')===$lang
  && ($rich ? ($graph['classification']==='editorial_hub_candidate') :
    ($graph['classification']==='missing_translation'||$graph['classification']==='thin_term'));
}
$day=T::inspect('tour-types',192,'fr');
$checks['day_tours_fr_inspect']=!is_wp_error($day)
 && $day['target_id']>0
 && ($day['target_language']??'')==='fr';
$checks['no_wpml_language_leak']=$current===apply_filters('wpml_current_language',null);
$qaSource=0;$qaLang='';$id='';$created=false;
foreach([[122,'fr'],[122,'ar'],[125,'fr'],[560,'fr'],[722,'fr'],[124,'fr'],[665,'fr']] as [$source,$locale]) {
 $i=T::inspect('destinations',$source,$locale);
 if(is_wp_error($i)||$i['status']!=='missing_description'||!$i['can_apply_to_existing_term'])continue;
 $candidateId=T::taskId('destinations',$source,$locale);
 if(is_wp_error(T::get($candidateId))){
  $qaSource=$source;$qaLang=$locale;$id=$candidateId;break;
 }
}
if(!$qaSource)throw new RuntimeException('No unused missing-description WPML term is available for safe QA');
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if(!$admins)throw new RuntimeException('Administrator missing');
$oldUser=get_current_user_id();
try {
 rest_get_server();API::register();
 wp_set_current_user(0);
 $path='/mad4b/v1/seo-intelligence/translations/inspect';
 $req=new WP_REST_Request('GET',$path);
 $req->set_param('taxonomy','destinations');$req->set_param('source_id',$qaSource);
 $anon=rest_do_request($req);
 $checks['anonymous_rejected']=in_array($anon->get_status(),[401,403],true);
 wp_set_current_user((int)$admins[0]);
 $admin=rest_do_request($req);
 $checks['admin_inspect']=$admin->get_status()===200
  && ($admin->get_data()['locales'][$qaLang]['status']??'')==='missing_description';
 $prep=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/translations/tasks');
 $prep->set_header('Content-Type','application/json');
 $prep->set_body(wp_json_encode(['taxonomy'=>'destinations','source_id'=>$qaSource,'language'=>$qaLang]));
 $saved=rest_do_request($prep);
 $created=$saved->get_status()===201;
 $checks['task_prepared']=$created && ($saved->get_data()['record']['status']??'')==='pending_translation';
 if(!$created)throw new RuntimeException('Temporary QA task creation failed; existing tasks untouched');
 $rev=(string)($saved->get_data()['revision']??'');
 $apply=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/translations/tasks/'.$id.'/apply');
 $apply->set_url_params(['id'=>$id]);
 $apply->set_header('Content-Type','application/json');
 $apply->set_body(wp_json_encode(['expected_revision'=>$rev]));
 $denied=rest_do_request($apply);
 $checks['unreviewed_apply_blocked']=$denied->get_status()===409;
 $review=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/translations/tasks/'.$id.'/review');
 $review->set_url_params(['id'=>$id]);
 $review->set_header('Content-Type','application/json');
 $review->set_body(wp_json_encode(['expected_revision'=>$rev,'approved'=>true]));
 $bad=rest_do_request($review);
 $checks['no_candidate_review_denied']=$bad->get_status()===409;
 $preserve=new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/translations/tasks');
 $preserve->set_header('Content-Type','application/json');
 $preserve->set_body(wp_json_encode(['taxonomy'=>'destinations','source_id'=>123,'language'=>'it']));
 $guard=rest_do_request($preserve);
 $checks['existing_italian_description_protected']=$guard->get_status()===409
  && ($guard->get_data()['code']??'')==='translation_existing_content';
 $duplicate=rest_do_request($prep);
 $checks['duplicate_prepare_denied']=$duplicate->get_status()===409;
} finally {
 wp_set_current_user($oldUser);
 if($created)delete_option(T::recordKey($id));
}
$checks['task_cleaned']=is_wp_error(T::get($id));
$checks['native_settings_unchanged']=$nativeBefore===get_option('jet-smart-filters-settings',[]);
$checks['policy_unchanged']=$policyBefore===get_option('mad4b_smart_seo_policy_registry_v1',[]);
$checks['language_restored']=$current===apply_filters('wpml_current_language',null);
foreach($checks as $k=>$ok)echo ($ok?'PASS ':'FAIL ').$k.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
echo 'QA_SOURCE='.$qaSource.' QA_LANG='.$qaLang.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
