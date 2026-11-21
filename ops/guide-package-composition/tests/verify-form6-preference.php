<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
global $wp_filter;
$hook=$wp_filter['fluentform/filter_insert_data']??null;
$fn=null;
foreach(($hook->callbacks[40]??[]) as $r) {
 $callback=$r['function']??null;
 if($callback instanceof Closure) {
  $ref=new ReflectionFunction($callback);
  if(str_contains((string)$ref->getFileName(),'mad4b-guide-service-composition-v1.php'))$fn=$callback;
 }
}
if(!$fn)WP_CLI::error('Guide preference callback unavailable');
function invoke_guide_form($fn,$tour,$guide) {
 $_POST=['data'=>http_build_query(['__fluent_form_embded_post_id'=>$tour])];
 $data=['form_id'=>6,'response'=>wp_json_encode([
  'guide_language'=>$guide,
  '_shared_tour_booking_intent_v2'=>['tour_id'=>$tour,'checkout_ready'=>false]
 ])];
 return json_decode($fn($data)['response']??'',true)['_mad4b_guide_preference_v1']??null;
}
$ok=invoke_guide_form($fn,60433,'arabic');
if(!is_array($ok)||$ok['status']!=='validated_preference_not_price'||
   $ok['language_code']!=='ar'||$ok['commercial_price_applied']!==false||
   isset($ok['cost'])||isset($ok['amount']))WP_CLI::error('Arabic guide preference invalid');
WP_CLI::log('PASS validated_arabic_language_identity_preference_only');
$unsupported=invoke_guide_form($fn,60475,'arabic');
if(!is_array($unsupported)||$unsupported['reason']!=='guide_language_not_offered_by_tour')
 WP_CLI::error('Unsupported Arabic selected on tour without Arabic');
WP_CLI::log('PASS unsupported_tour_language_rejected');
$other=invoke_guide_form($fn,60433,'french');
if(!is_array($other)||$other['status']!=='validated_preference_not_price'||
   $other['language_code']!=='fr')WP_CLI::error('French preference invalid');
WP_CLI::log('PASS french_preference_validated');
unset($_POST);
WP_CLI::success('FORM6_GUIDE_PREFERENCE_ACCEPTANCE_PASSED');
