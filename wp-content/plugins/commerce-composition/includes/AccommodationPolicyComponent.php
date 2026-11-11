<?php
/** Public accommodation projection. Native relations and quote engine own all calculations. */
defined('ABSPATH') || exit;
final class MAD4B_Accommodation_Policy_Component {
 public static function context(int $tour,int $rate):array|WP_Error {
  if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')return new WP_Error('invalid_tour');
  if(get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish')return new WP_Error('invalid_rate');
  $parent=(int)get_post_meta($rate,'related_tour_id',true);
  $canonical=static fn($id)=>(int)apply_filters('wpml_object_id',$id,'tours-and-activities',true,apply_filters('wpml_default_language',null));
  if(!$parent||!$canonical($parent)||!$canonical($tour)||$canonical($parent)!==$canonical($tour))return new WP_Error('rate_not_related');
  if((string)get_post_meta($rate,'tour_public_status',true)==='0')return new WP_Error('rate_not_public');
  if(!function_exists('BookingExperience\\tour_stay_options'))return new WP_Error('accommodation_provider_missing');
  return \BookingExperience\tour_stay_options($rate);
 }
 public static function labels():array {
  $lang=apply_filters('wpml_current_language',null)?:substr(get_locale(),0,2);
  return match($lang){
   'ar'=>['اختر فئة السعر لعرض الإقامة المرتبطة بها.','تفاصيل الإقامة غير متاحة لهذا الاختيار.','مشمول في فئة سعر الرحلة؛ لا يضاف مرة أخرى','تكلفة إضافية تتطلب عرض سعر موثوق','يحتاج إلى اتفاق وتسعير','ليالٍ','خيارات بديلة؛ الاختيار لا يؤكد التوفر أو الحجز.','تفاصيل الغرف وخطط الوجبات','لم يتم التحقق من التوفر. هذا ليس إجمالي شراء نهائيًا.'],
   'es'=>['Selecciona una tarifa para ver el alojamiento.','Detalles de alojamiento no disponibles.','Incluido en la tarifa; no se suma de nuevo','Cargo adicional sujeto a cotización verificada','Sujeto a acuerdo y cotización','noches','Opciones alternativas; la selección no confirma disponibilidad ni reserva.','Habitaciones y planes de comidas','Disponibilidad sin verificar. No es el precio final del viaje.'],
   default=>['Select a tour rate to view its accommodation.','Accommodation details are unavailable for this selection.','Included in the tour rate; never added twice','Additional charge requires a verified quote','Subject to agreement and quotation','nights','Alternative options; selection does not confirm availability or booking.','Rooms and meal plans','Availability has not been checked. This is not the final trip purchase total.']};
 }
 public static function html(int $tour,int $rate,string $view='summary'):string {
  $l=self::labels();if(!$rate)return '<p>'.esc_html($l[0]).'</p>';
  $c=self::context($tour,$rate);if(is_wp_error($c))return '<p>'.esc_html($l[1]).'</p>';
  if(empty($c['properties']))return '<p>'.esc_html($l[1]).'</p>';
  $plan=$c['plan'];$stages=$plan['mode']==='multi_stage'?$plan['stages']:[['label'=>get_the_title($rate),'properties'=>$plan['properties']??[],'price_policy'=>$plan['price_policy']??'inquiry']];
  $h='<div class="mad4b-stay-levels">';
  foreach($stages as $stage){$h.='<section><h4>'.esc_html($stage['label']).'</h4>';
   if(isset($stage['nights']))$h.='<p>'.(int)$stage['nights'].' '.esc_html($l[5]).'</p>';
   $h.='<p>'.esc_html($l[match($stage['price_policy']){'included'=>2,'additional'=>3,default=>4}]).'</p><ul>';
   foreach($stage['properties'] as $id){$property=$c['properties'][$id]??null;if(!$property)continue;
    $h.='<li>'.esc_html($property['name']);
    if(in_array($view,['units','full'],true)){$h.='<details><summary>'.esc_html($l[7]).'</summary><ul>';
     foreach($property['units'] as $unit){$h.='<li>'.esc_html($unit['name']).'<ul>';foreach($unit['rates'] as $r){$h.='<li>'.esc_html($r['title']);$terms=[];foreach($r['meal_options'] as $meal){$term=get_term((int)$meal['term_id'],'board-type');if($term instanceof WP_Term)$terms[]=esc_html($term->name).' — '.esc_html($l[$meal['additional_cost']?3:2]);}$h.=$terms?' ('.implode('; ',$terms).')':'';$h.='</li>';}$h.='</ul></li>';}$h.='</ul></details>';}
    $h.='</li>';
   }$h.='</ul><p>'.esc_html($l[6]).'</p></section>';
  }return $h.'<p>'.esc_html($l[8]).'</p></div>';
 }
 /** Only public retail aggregation leaves the native provider; never return its private financial ledger. */
 public static function quote(int $tour,int $rate,array $input):array|WP_Error {
  $c=self::context($tour,$rate);if(is_wp_error($c))return $c;
  if(array_diff(array_keys($input),['travel_date','stays','transportations']))return new WP_Error('unexpected_quote_fields');
  $q=\BookingExperience\tour_stay_overlay_quote(['tour_rate_id'=>$rate]+$input);if(is_wp_error($q))return $q;
  return array_intersect_key($q,array_flip(['status','additional_by_currency','included_reference_by_currency','inquiry_reference_by_currency','tour_rate_base_not_included','checkout_ready','availability_status']));
 }
 public static function shortcode($atts):string {
  $a=shortcode_atts(['tour_id'=>0,'rate_id'=>0,'view'=>'summary'],$atts);$tour=absint($a['tour_id'])?:get_queried_object_id();if(get_post_type($tour)!=='tours-and-activities')$tour=get_the_ID();
  $view=in_array($a['view'],['summary','units','full'],true)?$a['view']:'summary';
  return '<div class="mad4b-accommodation-policy" data-tour="'.absint($tour).'" data-rate="'.absint($a['rate_id']).'" data-view="'.esc_attr($view).'">'.self::html((int)$tour,absint($a['rate_id']),$view).'</div>';
 }
 public static function boot():void {
  add_shortcode('mad4b_accommodation',[self::class,'shortcode']);
  add_action('rest_api_init',static function(){register_rest_route('commerce-composition/v1','/accommodation-policy',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function($r){$tour=absint($r['tour_id']);$rate=absint($r['rate_id']);$c=self::context($tour,$rate);if(is_wp_error($c))return new WP_Error('accommodation_unavailable','Accommodation unavailable',['status'=>404]);$response=rest_ensure_response(['html'=>self::html($tour,$rate,sanitize_key($r['view']??'summary')),'booking_confirmed'=>false,'final_trip_total'=>null]);$response->header('Cache-Control','no-store');return $response;}]);});
  add_action('wp_enqueue_scripts',static function(){wp_enqueue_script('mad4b-accommodation-policy',plugins_url('../assets/accommodation-policy.js',__FILE__),[], '1.0.1',true);wp_localize_script('mad4b-accommodation-policy','mad4bAccommodationPolicy',['url'=>rest_url('commerce-composition/v1/accommodation-policy'),'lang'=>apply_filters('wpml_current_language',null),'select'=>self::labels()[0],'unavailable'=>self::labels()[1]]);});
 }
}
MAD4B_Accommodation_Policy_Component::boot();
