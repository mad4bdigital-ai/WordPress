<?php
/** Native Elementor component selection manifests. Discovery preferences only. */
defined('ABSPATH') || exit;

/** A Draft Tour may be viewed by its own editor only. Presentation authority,
 * never commercial booking readiness or public-discovery authority. */
function mad4b_native_extra_editor_preview($tour):bool {
 global $wp_the_query;
 $tour=absint($tour);
 return $tour>0 && !is_admin() && !wp_doing_ajax()
  && (!defined('REST_REQUEST') || !REST_REQUEST)
  &&is_user_logged_in() && current_user_can('edit_post',$tour)
  &&get_post_type($tour)==='tours-and-activities'
  &&get_post_status($tour)==='draft'
  &&($wp_the_query instanceof WP_Query)
  &&$wp_the_query->is_preview() && $wp_the_query->is_singular('tours-and-activities')
  &&(int)$wp_the_query->get_queried_object_id()===$tour;
}
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
 $editorPreview=mad4b_native_extra_editor_preview($tour);
 if(!native_component_selection_public($tour,'tours-and-activities')&&!$editorPreview)return $plan;
 if($component==='extras'){
  if((string)get_post_meta($tour,'tour_extras_status',true)!=='1')return $plan;
  $ids=native_component_selection_ids(get_post_meta($tour,'tour_optional_extras',true),'tours-and-activities');
  $ids=array_values(array_filter($ids,static function($id)use($tour,$editorPreview){return $id!==$tour
    &&in_array(get_post_meta($id,'tour_mode',true),['activity','extra_trip'],true)
    &&($editorPreview||(string)get_post_meta($id,'tour_public_status',true)!=='0');}));
  $plan['groups'][]=['key'=>'extras','type'=>'extra','mode'=>'multiple','ids'=>$ids,
    'label'=>__('Optional activities','woocommerce'),'required'=>false];
  $plan['selectionPurpose']=$editorPreview?'editor_preview_preference_only_not_sale':'preference_only';
  $plan['status']=$ids?'ready':'empty';return $plan;
 }
 // Binding and candidate eligibility are resolved once by the MU Presentation
 // Registry. Elementor and JetSmartFilters keep their existing native Queries.
 if(!class_exists('Shared_Product_Presentation_Transport_Context_v1'))return $plan;
 $descriptors=Shared_Product_Presentation_Transport_Context_v1::categories();
 if(!isset($descriptors[$component]))return $plan;
 $field=$descriptors[$component]['source'];
 $raw=get_post_meta($tour,$field,true);
 $links=is_array($raw)?$raw:($raw===''?[]:array_map('trim',explode(',',(string)$raw)));
 $resolved=Shared_Product_Presentation_Transport_Context_v1::resolve($tour,$component,$links,$editorPreview);
 $plan['presentationSlot']=$descriptors[$component]['slot'];
 $plan['presentationBindingCode']=$resolved['binding']['code']??'UNAVAILABLE';
 $plan['presentationRejections']=$resolved['rejections'];
 $plan['selectionPurpose']='preference_only_not_booking_authority';
 if($resolved['status']!=='ready'){$plan['status']=$resolved['status'];return $plan;}
 $ids=$resolved['ids'];
 // Tour-scoped preference is available before a Rate, including direct
 // per-person, simulated and inquiry pricing. Pricing is never authorized
 // by the presence of these option IDs or the client's selected state.
 $policies=array_fill_keys(array_map('strval',$ids),'inquiry');
 $plan['groups']=[['key'=>'transport-'.$component,'type'=>'transport',
   'mode'=>'multiple','ids'=>$ids,'lockedIds'=>[],'policies'=>$policies,
   'label'=>__('Transportation preferences','woocommerce'),'required'=>false]];
 $plan['pricingMode']=class_exists('Shared_Tour_Pricing_Entry_v1')
  ?(Shared_Tour_Pricing_Entry_v1::inspect($tour)['effective_mode']??'inquiry')
  :'inquiry';
 $rates=get_posts(['post_type'=>'tour-rates',
  'post_status'=>$editorPreview?['draft','publish']:'publish',
  'posts_per_page'=>81,'fields'=>'ids','meta_key'=>'related_tour_id',
  'meta_value'=>$tour,'orderby'=>'ID','order'=>'ASC']);
 if(count($rates)>80){$plan['status']='rate_limit';return $plan;}
 foreach($rates as$rate){
  // A private editor preview may choose its own draft Rate for UI testing.
  // Never relax a public Rate, pricing, booking or payment admission rule.
  $previewRate=$editorPreview&&get_post_status($rate)==='draft'
   &&(int)get_post_meta($rate,'related_tour_id',true)===$tour
   &&current_user_can('edit_post',$rate);
  if(!native_component_selection_public($rate,'tour-rates')&&!$previewRate)continue;
  $locked=[];$policies=[];
  foreach($ids as$id){$policy=function_exists('BookingExperience\\tour_stay_transport_policy')?BookingExperience\tour_stay_transport_policy($rate,$id):'inquiry';$policies[(string)$id]=$policy;if($policy==='included')$locked[]=$id;}
  $plan['groupsByRate'][(string)$rate]=[['key'=>'transport-'.$component,'type'=>'transport','mode'=>'multiple','ids'=>$ids,'lockedIds'=>$locked,'policies'=>$policies,'label'=>__('Transportation preferences','woocommerce'),'required'=>false]];
 }
 $plan['requiresRate']=false;
 $plan['rateContextSupported']=!empty($plan['groupsByRate']);
 $plan['selectionPurpose']=$editorPreview
  ?'editor_preview_preference_only_not_sale':'preference_only_not_booking_authority';
 $plan['previewOnly']=$editorPreview;
 $plan['status']='ready';return $plan;
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
 foreach(['js','css']as$extension){$file=$extension==='js'?'components.lifecycle-2.0.0-20261111.js':'components.css';if(!is_file($dir.$file))continue;$version=(string)filemtime($dir.$file);
 if($extension==='js')wp_enqueue_script('native-component-selection',$base.$file,[],$version,true);else wp_enqueue_style('native-component-selection',$base.$file,[],$version);}
 // Native Elementor button text follows the WPML locale and is never used
 // to authorize a transport preference or a price.
 $defaults=[
  'en'=>['select'=>'Select','remove'=>'Remove selected transport','selected'=>'Selected','included'=>'Included','choose_rate'=>'Choose rate first','unavailable'=>'Unavailable'],
  'ar'=>['select'=>'اختيار','remove'=>'إزالة وسيلة الانتقال المختارة','selected'=>'تم الاختيار','included'=>'مشمول','choose_rate'=>'اختر السعر أولاً','unavailable'=>'غير متاح'],
  'fr'=>['select'=>'Sélectionner','remove'=>'Retirer le transport sélectionné','selected'=>'Sélectionné','included'=>'Inclus','choose_rate'=>'Choisissez un tarif','unavailable'=>'Indisponible'],
  'de'=>['select'=>'Auswählen','remove'=>'Transportauswahl entfernen','selected'=>'Ausgewählt','included'=>'Inklusive','choose_rate'=>'Zuerst Preis wählen','unavailable'=>'Nicht verfügbar'],
  'es'=>['select'=>'Seleccionar','remove'=>'Quitar transporte seleccionado','selected'=>'Seleccionado','included'=>'Incluido','choose_rate'=>'Elige una tarifa','unavailable'=>'No disponible'],
  'it'=>['select'=>'Seleziona','remove'=>'Rimuovi trasporto selezionato','selected'=>'Selezionato','included'=>'Incluso','choose_rate'=>'Scegli prima una tariffa','unavailable'=>'Non disponibile']
 ];
 $lang=(string)apply_filters('wpml_current_language','en');
 $localized=$defaults[$lang]??$defaults['en'];
 foreach($defaults['en'] as $key=>$english){
  $translated=apply_filters('wpml_translate_single_string',$english,'MAD4B Transport Selection',$key);
  if(is_string($translated)&&$translated!==''&&$translated!==$english)$localized[$key]=$translated;
 }
 wp_add_inline_script('native-component-selection',
  'window.MAD4BTransportSelectLabelsV1='.wp_json_encode($localized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';','before');
 // Elementor/JetEngine author the image and its visibility conditions.
 // Selection plans carry only option identity, never synthetic media assets.

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
