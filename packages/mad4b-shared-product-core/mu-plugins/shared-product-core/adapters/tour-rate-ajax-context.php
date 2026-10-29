<?php
/** Lab-only signed JetSmartFilters Query Builder parent-tour context restoration. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
 if(!wp_doing_ajax() || ($_REQUEST['action']??'')!=='jet_smart_filters')return $args;
 if(!in_array($query->query_id??'', ['tour_rate_single_tour','tour_rates'],true))return $args;
 $req=jet_smart_filters()->data->get_request();
 $provider=(string)($req['provider']??'');
 if(!in_array($provider,['jet-engine/tour_rates','jet-engine/tour_rates_accordion'],true))return $args;
 $settings=$req['settings']??[];
 if(!is_array($settings) || empty($settings['jsf_signature']))return $args;
 $sig=(string)$settings['jsf_signature'];unset($settings['jsf_signature']);
 $render=jet_smart_filters()->render;
 if(!hash_equals($render->create_signature($render->prepare_provider_settings_for_signature($settings,'jet-engine')),$sig))return $args;
 $id=absint($settings['queried_id']??0);
 $ref=wp_get_referer();
 if(!class_exists('Shared_Product_Presentation_Context_v1')||!$ref)return $args;
 // This follows the unchanged vendor signature verification above.
 $verified=Shared_Product_Presentation_Context_v1::resolve_referer(
  $id,(string)$ref,(string)($_SERVER['HTTP_HOST']??''));
 if(empty($verified['ok']))return $args;
 if($provider!=='jet-engine/'.$verified['provider_id'])return $args;
 if((int)($settings['lisitng_id']??0)!==(int)$verified['listing_id'])return $args;
 // Emit once per request even when JetEngine repeats its Query Builder pass.
 // The event is observational; query authority stays in this signed handler.
 static $emitted=[];
 $key=$id.'|'.$provider.'|'.(int)$verified['listing_id'];
 if(!isset($emitted[$key])){
  $emitted[$key]=true;
  do_action('shared_product/presentation/signed_context_accepted',$id,$provider,(int)$verified['listing_id']);
 }
 $args['queried_object_id']=$id;
 if(!empty($args['meta_query'])&&is_array($args['meta_query']))foreach($args['meta_query'] as &$condition){
  if(is_array($condition)&&($condition['key']??'')==='related_tour_id')$condition['value']=(string)$id;
 }unset($condition);
 if(($query->query_id??'')==='tour_rates'){
  $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>false,'meta_query'=>[
   ['key'=>'related_tour_id','value'=>(string)$id,'compare'=>'='],
   ['key'=>'avilabile_status','value'=>'1','compare'=>'='],
   ['key'=>'tour_rate_end_date','value'=>(string)strtotime('today'),'compare'=>'>','type'=>'NUMERIC']]]);
  $args['post__in']=$ids?:[0];
 }
 return $args;
},25,2);
