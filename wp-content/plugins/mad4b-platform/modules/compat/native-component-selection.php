<?php
/** Native Elementor component selection manifests. Discovery preferences only. */
defined('ABSPATH') || exit;

function native_component_selection_public($id,$type){
 $post=get_post(absint($id));
 if(!$post||$post->post_type!==$type||$post->post_status!=='publish')return false;
 $qa_preview=function_exists('lab_qa_public_tour_view_only_allowed')
  &&lab_qa_public_tour_view_only_allowed($post->ID);
 if(!current_user_can('manage_woocommerce')&&!$qa_preview
  &&($post->post_password!==''||get_post_meta($post->ID,'_booking_experience_qa_pricing_fixture',true)!==''))return false;
 return true;
}
function native_component_selection_ids($raw,$type,$limit=100){
 if(is_string($raw))$raw=maybe_unserialize($raw);
 if(is_string($raw))$raw=$raw===''?[]:array_map('trim',explode(',',$raw));
 if(!is_array($raw)||count($raw)>$limit)return [];
 $ids=[];foreach($raw as$value){
  if(!is_scalar($value)||!ctype_digit((string)$value))return [];
  $id=absint($value);if($id&&native_component_selection_public($id,$type))$ids[$id]=$id;
 }return array_values($ids);
}
function native_component_selection_plan($tour,$component){
 $tour=absint($tour);$plan=['contextTourId'=>$tour,'contextRateId'=>0,'groups'=>[],'groupsByRate'=>[], 'status'=>'unavailable','selectionPurpose'=>'preference_only'];
 if(!native_component_selection_public($tour,'tours-and-activities'))return $plan;
 if($component==='extras'){
  if((string)get_post_meta($tour,'tour_extras_status',true)!=='1')return $plan;
  $ids=native_component_selection_ids(get_post_meta($tour,'tour_optional_extras',true),'tours-and-activities');
  $ids=array_values(array_filter($ids,static function($id)use($tour){return $id!==$tour&&in_array(get_post_meta($id,'tour_mode',true),['activity','extra_trip'],true)&&(string)get_post_meta($id,'tour_public_status',true)!=='0';}));
  $plan['groups'][]=['key'=>'extras','type'=>'extra','mode'=>'multiple','ids'=>$ids,'label'=>__('Optional activities','woocommerce'),'required'=>false];$plan['status']=$ids?'ready':'empty';return $plan;
 }
 // Binding and candidate eligibility are resolved once by the MU Presentation
 // Registry. Elementor and JetSmartFilters keep their existing native Queries.
 if(!class_exists('Shared_Product_Presentation_Transport_Context_v1'))return $plan;
 $descriptors=Shared_Product_Presentation_Transport_Context_v1::categories();
 if(!isset($descriptors[$component]))return $plan;
 $field=$descriptors[$component]['source'];
 $raw=get_post_meta($tour,$field,true);
 $links=is_array($raw)?$raw:($raw===''?[]:array_map('trim',explode(',',(string)$raw)));
 $resolved=Shared_Product_Presentation_Transport_Context_v1::resolve($tour,$component,$links);
 $plan['presentationSlot']=$descriptors[$component]['slot'];
 $plan['presentationBindingCode']=$resolved['binding']['code']??'UNAVAILABLE';
 $plan['presentationRejections']=$resolved['rejections'];
 $plan['selectionPurpose']='preference_only_not_booking_authority';
 if($resolved['status']!=='ready'){$plan['status']=$resolved['status'];return $plan;}
 $ids=$resolved['ids'];
 $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>81,'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>$tour,'orderby'=>'ID','order'=>'ASC']);
 if(count($rates)>80){$plan['status']='rate_limit';return $plan;}
 foreach($rates as$rate){
  if(!native_component_selection_public($rate,'tour-rates'))continue;
  $locked=[];$policies=[];
  foreach($ids as$id){$policy=function_exists('BookingExperience\\tour_stay_transport_policy')?BookingExperience\tour_stay_transport_policy($rate,$id):'inquiry';$policies[(string)$id]=$policy;if($policy==='included')$locked[]=$id;}
  $plan['groupsByRate'][(string)$rate]=[['key'=>'transport-'.$component,'type'=>'transport','mode'=>'multiple','ids'=>$ids,'lockedIds'=>$locked,'policies'=>$policies,'label'=>__('Transportation preferences','woocommerce'),'required'=>false]];
 }
 $plan['requiresRate']=true;$plan['status']='choose_rate';return $plan;
}
/** Validate the native provider's signed context; an arbitrary referer/queried ID is insufficient. */
function native_component_selection_signed_context(array $request,$provider,$referer,$component){
 // Query/Listing IDs are sourced from the same Presentation Registry as
 // server eligibility. The native JSF signature is still mandatory.
 $allowed=['extras'=>['current_tour_extras',176,[53516,33747]]];
 if(class_exists('Shared_Product_Presentation_Transport_Context_v1')){
  foreach(Shared_Product_Presentation_Transport_Context_v1::categories()as$key=>$config){
   $binding=Shared_Product_Presentation_Transport_Context_v1::binding($key);
   if(!empty($binding['ok']))$allowed[$key]=[$config['provider_id'],$config['query_id'],[40299]];
  }
 }
 if(!isset($allowed[$component])||!function_exists('jet_smart_filters'))return 0;
 [$query,$custom,$listings]=$allowed[$component];
 if($provider!=='jet-engine/'.$query)return 0;
 $settings=$request['settings']??null;
 if(!is_array($settings)||!is_string($settings['jsf_signature']??null)||$settings['jsf_signature']==='')return 0;
 if((int)($settings['custom_query_id']??0)!==$custom||!in_array((int)($settings['lisitng_id']??0),$listings,true))return 0;
 $signature=$settings['jsf_signature'];unset($settings['jsf_signature']);
 $render=jet_smart_filters()->render;
 $expected=$render->create_signature($render->prepare_provider_settings_for_signature($settings,'jet-engine'));
 if(!hash_equals($expected,$signature))return 0;
 $tour=absint($settings['queried_id']??0);
 if(!$tour||!is_string($referer)||$referer===''
   ||get_post_type($tour)!=='tours-and-activities'
   ||get_post_status($tour)!=='publish')return 0;
 // The external public Dev hostname differs from WordPress' internal host.
 // Reuse the centrally validated same-origin Referer resolver; never trust
 // query args, currency, or an unsigned queried_id as proof of Tour identity.
 if(!class_exists('Shared_Product_Presentation_Context_v1'))return 0;
 $host=(string)($_SERVER['HTTP_HOST']??'');
 $referer_context=Shared_Product_Presentation_Context_v1::resolve_referer($tour,$referer,$host);
 if(empty($referer_context['ok']))return 0;
 // An approved scoped presentation may opt into READ-ONLY discovery.
 // The component Registry itself must not know QA tour IDs or grant writes.
 do_action('shared_product/presentation/component_signed_context_accepted',
   $tour,$component,$provider,(int)$custom);
 if(!native_component_selection_public($tour,'tours-and-activities'))return 0;
 return $tour;
}
function native_component_selection_tour_context($component){
 global $wp_the_query;
 if($wp_the_query instanceof WP_Query&&$wp_the_query->is_singular('tours-and-activities'))return (int)$wp_the_query->get_queried_object_id();
 if(!wp_doing_ajax()||($_REQUEST['action']??'')!=='jet_smart_filters'||!function_exists('jet_smart_filters'))return 0;
 return native_component_selection_signed_context(jet_smart_filters()->data->get_request(),$_REQUEST['provider']??'',wp_get_referer(),$component);
}
add_action('wp_enqueue_scripts',static function(){
 if(!is_singular('tours-and-activities'))return;
 $dir=WPMU_PLUGIN_DIR.'/listing-option-selection-assets/';$base=content_url('mu-plugins/listing-option-selection-assets/');
 foreach(['js','css']as$extension){$file='components.'.$extension;if(!is_file($dir.$file))continue;$version=(string)filemtime($dir.$file);
 if($extension==='js')wp_enqueue_script('native-component-selection',$base.$file,[],$version,true);else wp_enqueue_style('native-component-selection',$base.$file,[],$version);}
 // Card imagery is the same approved Extra CPT data used by the native
 // selection plan; Elementor may hide its legacy 'image_vertical' container
 // even when the Extra has a real Featured Image.
 $tour=(int)get_queried_object_id();
 $media=[];
 if($tour&&function_exists('native_component_selection_plan')){
  $plan=native_component_selection_plan($tour,'extras');
  foreach($plan['groups']??[] as $group){
   if(($group['type']??'')!=='extra')continue;
   foreach((array)($group['ids']??[]) as $candidate){
    $extra=absint($candidate);
    if(!$extra||get_post_type($extra)!=='tours-and-activities'||
       get_post_status($extra)!=='publish')continue;
    $src=get_the_post_thumbnail_url($extra,'medium');
    if(!is_string($src)||!wp_http_validate_url($src))continue;
    $site=wp_parse_url(home_url('/'),PHP_URL_HOST);
    $host=wp_parse_url($src,PHP_URL_HOST);
    if($host&&$site&&strcasecmp($host,$site)===0){
     $path=wp_parse_url($src,PHP_URL_PATH);
     $query=wp_parse_url($src,PHP_URL_QUERY);
     if(is_string($path)&&str_starts_with($path,'/'))
      $src=$path.($query?'?'.$query:'');
    }
    $media[(string)$extra]=['image'=>esc_url_raw($src),
       'alt'=>wp_strip_all_tags(get_the_title($extra))];
   }
  }
 }
 wp_add_inline_script('native-component-selection',
  'window.NativeExtraCardMediaV1='.wp_json_encode($media,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG).';','before');
});
add_action('elementor/frontend/widget/before_render',static function($widget){
 if($widget->get_name()!=='jet-listing-grid')return;
 $classes=preg_split('/\s+/',trim((string)$widget->get_settings('_css_classes')));
 if(!in_array('booking-component-list',$classes,true))return;
 $component=null;foreach(['extras','vehicles','train','air']as$key)if(in_array('booking-component-'.$key,$classes,true))$component=$key;
 if(!$component)return;
 $tour=native_component_selection_tour_context($component);
 $widget->add_render_attribute('_wrapper','data-booking-component-plan',wp_json_encode(native_component_selection_plan($tour,$component)));
});
