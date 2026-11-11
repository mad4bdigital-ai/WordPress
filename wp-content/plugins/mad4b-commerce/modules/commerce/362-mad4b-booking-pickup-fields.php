<?php
/**
 * Plugin Name: MAD4B Booking Pickup Location
 * Description: Source-aware Fluent Form #6 and WooCommerce per-booking hotel pickup fields.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Booking_Pickup_Fields {
  const CONTRACT='mad4b.booking-pickup.v1';
  const FORM_ID=6;
  const FIELDS=['mad4b_pickup_booking_status','mad4b_pickup_hotel_name',
     'mad4b_pickup_hotel_city','mad4b_pickup_hotel_address'];
  static function enabled() {
    // No environment or company allowlist. Installations can disable the
    // capability explicitly; source eligibility is checked per tour.
    return !defined('MAD4B_BOOKING_PICKUP_DISABLED') ||
      MAD4B_BOOKING_PICKUP_DISABLED!==true;
  }
  static function requires_hotel($tour) {
    $tour=absint($tour);
    if(!self::enabled() || get_post_type($tour)!=='tours-and-activities'
       || get_post_status($tour)!=='publish')return false;
    $explicit=(string)get_post_meta($tour,'tour_pickup_mode',true);
    if($explicit==='hotel_pickup')return true;
    if(in_array($explicit,['fixed','not_included','meeting_point','none'],true))return false;
    $h=get_post_meta($tour,'highlight_rows',true);
    if(is_string($h) &&
       preg_match('/\bhotel\s+pick[ -]?up\b/i',$h)===1 &&
       !preg_match('/\b(?:no|not|without|excluded|not included)\b.{0,36}\bhotel\s+pick[ -]?up\b/i',$h))
       return true;
    // English source is the default policy authority for translated tours,
    // unless a translated tour sets an explicit pickup mode override.
    if(has_filter('wpml_object_id')){
      $ref=(string)apply_filters('wpml_default_language','en');
      $canonical=absint(apply_filters('wpml_object_id',$tour,
         'tours-and-activities',false,$ref));
      if($canonical && $canonical!==$tour && get_post_status($canonical)==='publish')
        return self::requires_hotel($canonical);
    }
    return false;
  }
  /**
   * Geographical pickup country from the published tour taxonomy, NOT the
   * WooCommerce store home (which may be a different country).
   */
  static function pickup_country($tour){
    $tour=absint($tour);
    if(!self::requires_hotel($tour))return '';
    $manual=strtoupper((string)get_post_meta($tour,'tour_pickup_country_code',true));
    if(preg_match('/^[A-Z]{2}$/D',$manual))return $manual;
    $terms=wp_get_object_terms($tour,'destinations');
    if(is_wp_error($terms))return '';
    $mapping=['egypt'=>'EG','jordan'=>'JO','saudi-arabia'=>'SA',
      'saudi-arabia-ksa'=>'SA','united-arab-emirates'=>'AE','uae'=>'AE'];
    $matches=[];
    foreach($terms as $t)if((string)get_term_meta($t->term_id,'destination_type',true)==='country'){
      $iso=strtoupper((string)get_term_meta($t->term_id,'country_iso2',true));
      if(!preg_match('/^[A-Z]{2}$/D',$iso))
        $iso=$mapping[$t->slug]??'';
      if($iso)$matches[$iso]=true;
    }
    return count($matches)===1?(string)array_key_first($matches):'';
  }
  /**
   * Visitor-location country: read independent MaxMind/Woo GeoIP first,
   * then the signed country switcher preference if it actually exists.
   * Passport, nationality, store origin and billing address are excluded.
   */
  static function location_country(){
    if(class_exists('MAD4B_SCP_Market_Context') &&
       class_exists('WC_Geolocation')){
      $ip=(string)WC_Geolocation::get_ip_address();
      $country=$ip?MAD4B_SCP_Market_Context::woo_geo_hint($ip):'';
      if(preg_match('/^[A-Z]{2}$/D',(string)$country))return $country;
    }
    if(class_exists('MAD4B_Country_Switcher')){
      $context=MAD4B_Country_Switcher::context();
      if(is_array($context) && ($context['source']??'')==='manual_country'){
        $country=strtoupper((string)($context['country']??''));
        if(preg_match('/^[A-Z]{2}$/D',$country))return $country;
      }
    }
    return '';
  }
  static function effective_location_country($input=[]){
    $detected=self::location_country();
    if($detected!=='')return $detected;
    $choice=strtoupper((string)($input['mad4b_pickup_current_country']??''));
    if($choice==='EG')return 'EG';
    if($choice==='OTHER')return 'OTHER';
    return '';
  }
  static function visitor_requires_hotel($tour,$country=null){
    $country=$country===null?self::location_country():strtoupper((string)$country);
    $pickup=self::pickup_country($tour);
    return $pickup!=='' && $country===$pickup;
  }
  static function location_state(){
    if(!self::enabled())wp_send_json_error(['code'=>'disabled'],503);
    nocache_headers();
    $response=wp_send_json_success([
      'contract'=>'mad4b.pickup-location-state.v1',
      'country'=>self::location_country(),
      'source'=>'geoip_or_explicit_location_country',
      'passport_involved'=>false,
    ],200);
    return $response;
  }
  static function form_fields_exist(){
    if(!self::enabled())return false;
    global $wpdb;
    $raw=$wpdb->get_var($wpdb->prepare(
      "SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id=%d AND status=%s",
      self::FORM_ID,'published'));
    if(!is_string($raw))return false;
    $data=json_decode($raw,true);
    $found=[];
    $visit=function($x)use(&$visit,&$found){
      if(!is_array($x))return;
      if(!empty($x['attributes']['name']))$found[]=(string)$x['attributes']['name'];
      foreach($x as $v)if(is_array($v))$visit($v);
    };
    $visit($data);
    return count(array_intersect(self::FIELDS,$found))===count(self::FIELDS);
  }
  static function submission_tour($data=[]) {
    // Fluent Forms standard embedded post context, same as source-verified
    // price-intent pipeline; do not use an arbitrary URL as booking authority.
    $raw=$_POST['data']??null;
    $posted=[];
    if(is_string($raw)&&strlen($raw)<90000)parse_str(wp_unslash($raw),$posted);
    $id=absint($posted['__fluent_form_embded_post_id']??
        $_POST['__fluent_form_embded_post_id']??($data['__fluent_form_embded_post_id']??0));
    if(!self::requires_hotel($id))return 0;
    // When a tour selection is supplied, it must refer to exactly this tour.
    $rawSelection=$posted['shared_tour_selection_v2']??$_POST['shared_tour_selection_v2']??null;
    if(is_string($rawSelection)){
      $decoded=json_decode(wp_unslash($rawSelection),true);
      if(!is_array($decoded)||absint($decoded['tour_id']??0)!==$id)return 0;
    }
    return $id;
  }
  static function clean($input,$tour) {
    if(!self::requires_hotel($tour))return new WP_Error('pickup_tour_not_eligible');
    $status=sanitize_key((string)($input['mad4b_pickup_booking_status']??''));
    if(!in_array($status,['booked','not_booked'],true))
      return new WP_Error('pickup_booking_status_required');
    $name=sanitize_text_field((string)($input['mad4b_pickup_hotel_name']??''));
    $city=sanitize_text_field((string)($input['mad4b_pickup_hotel_city']??''));
    $address=sanitize_text_field((string)($input['mad4b_pickup_hotel_address']??''));
    if(mb_strlen($name)>180||mb_strlen($city)>100||mb_strlen($address)>500)
      return new WP_Error('pickup_fields_too_long');
    if($status==='booked' && (mb_strlen($name)<2||mb_strlen($city)<2))
      return new WP_Error('pickup_hotel_and_city_required');
    if($status==='not_booked')$name=$city=$address='';
    return ['contract'=>self::CONTRACT,'tour_id'=>(int)$tour,
      'status'=>$status==='booked'?'HOTEL_SUPPLIED':'PICKUP_LOCATION_PENDING',
      'hotel_name'=>$name,'hotel_city'=>$city,'hotel_address'=>$address,
      'tour_origin_post_meta_untouched'=>true];
  }
  static function validate($errors,$data,$form,$fields){
    if(!self::enabled()||(int)($form->id??0)!==self::FORM_ID)return $errors;
    $tour=self::submission_tour(is_array($data)?$data:[]);
    if(!$tour || !self::pickup_country($tour))return $errors;
    $country=self::effective_location_country((array)$data);
    if(!$country){
      $errors['mad4b_pickup_current_country']=[
        apply_filters('mad4b/booking_pickup/validation_message', 'Please select your current location country (this is not your nationality).', 'location_required')];
      return $errors;
    }
    if(!self::visitor_requires_hotel($tour,$country))return $errors;
    $result=self::clean((array)$data,$tour);
    if(!is_wp_error($result))return $errors;
    $key=$result->get_error_code()==='pickup_booking_status_required'
      ?'mad4b_pickup_booking_status':'mad4b_pickup_hotel_name';
    $errors[$key]=[apply_filters('mad4b/booking_pickup/validation_message', 'Please confirm hotel booking status and provide the hotel and city when booked.', 'hotel_required')];
    return $errors;
  }
  static function persist($insert){
    if(!self::enabled() || !is_array($insert) ||
       (int)($insert['form_id']??0)!==self::FORM_ID)return $insert;
    $response=json_decode((string)($insert['response']??''),true);
    if(!is_array($response))return $insert;
    $tour=self::submission_tour($response);
    $country=$tour?self::effective_location_country($response):'';
    if(!$tour || !self::visitor_requires_hotel($tour,$country)){
      // Global Form #6 can contain hidden hotel fields on other tours or
      // outside the destination country. Drop that stale guest data.
      foreach(self::FIELDS as $field)unset($response[$field]);
      unset($response['mad4b_pickup_current_country']);
      unset($response['_mad4b_booking_pickup_v1']);
      $insert['response']=wp_json_encode($response,JSON_UNESCAPED_UNICODE);
      return $insert;
    }
    $location=self::clean($response,$tour);
    if(is_wp_error($location))return $insert; // Native validator rejects invalid submissions.
    $intent=$response['_shared_tour_booking_intent_v2']??null;
    if(is_array($intent) && (int)($intent['tour_id']??0)!==$tour)return $insert;
    $location['form_id']=self::FORM_ID;
    $location['visitor_location_country']=$country;
    $location['pickup_destination_country']=self::pickup_country($tour);
    $location['country_context_source']=self::location_country()?
       'woo_geoip_or_signed_location_country':'guest_current_location_declaration';
    $location['source']='fluent_forms_native_submission';
    $location['recorded_at']=gmdate('c');
    $response['_mad4b_booking_pickup_v1']=$location;
    $insert['response']=wp_json_encode($response,JSON_UNESCAPED_UNICODE);
    return $insert;
  }
  static function frontend(){
    $tour=get_queried_object_id();
    $pickup=self::pickup_country($tour);
    if(!self::enabled()||!$pickup)return;
    $endpoint=admin_url('admin-ajax.php?action=mad4b_pickup_location_state');
    ?>
<script>
(function(){
  'use strict';
  var endpoint=<?php echo wp_json_encode($endpoint); ?>;
  var pickupCountry=<?php echo wp_json_encode($pickup); ?>;
  var geoCountry='',geoLoaded=false,version=0,stopped=false;
  function updateFields(){
    document.querySelectorAll('form.fluent_form_6').forEach(function(form){
      var location=form.querySelector('[name="mad4b_pickup_current_country"]');
      var status=form.querySelector('[name="mad4b_pickup_booking_status"]');
      if(!status)return;
      var unknown=geoLoaded && !geoCountry;
      if(location){
        var locationRow=location.closest('.mad4b-pickup-country-field');
        if(locationRow)locationRow.style.display=unknown?'block':'none';
        location.disabled=!unknown;
        location.required=unknown;
      }
      var choice=unknown && location ? location.value.toUpperCase() : geoCountry;
      var eligible=geoLoaded && choice===pickupCountry;
      var statusRow=status.closest('.mad4b-pickup-field');
      if(statusRow)statusRow.style.display=eligible?'block':'none';
      status.disabled=!eligible;
      status.required=eligible;
      var booked=eligible&&status.value==='booked';
      ['mad4b_pickup_hotel_name','mad4b_pickup_hotel_city',
        'mad4b_pickup_hotel_address'].forEach(function(key){
        var input=form.querySelector('[name="'+key+'"]');
        if(!input)return;
        var row=input.closest('.mad4b-pickup-field')||input.parentElement;
        if(row)row.style.display=booked?'block':'none';
        input.disabled=!booked;
        input.required=booked && key!=='mad4b_pickup_hotel_address';
      });
      if(!status.dataset.mad4bPickupBound){
        status.dataset.mad4bPickupBound='1';
        status.addEventListener('change',updateFields);
      }
      if(location&&!location.dataset.mad4bLocationBound){
        location.dataset.mad4bLocationBound='1';
        location.addEventListener('change',updateFields);
      }
    });
  }
  function refresh(){
    var seq=++version;
    geoLoaded=false;updateFields();
    fetch(endpoint,{credentials:'same-origin',cache:'no-store',
      headers:{Accept:'application/json'}}).then(function(response){
      if(!response.ok)throw Error('location_read_failed');
      return response.json();
    }).then(function(payload){
      if(seq!==version)return;
      geoCountry=payload && payload.success && payload.data &&
        payload.data.contract==='mad4b.pickup-location-state.v1'?
        String(payload.data.country||'').toUpperCase():'';
      geoLoaded=true;updateFields();
    }).catch(function(){
      if(seq!==version)return;
      geoCountry='';geoLoaded=true;updateFields();
    });
  }
  if(document.readyState==='loading')
    document.addEventListener('DOMContentLoaded',refresh,{once:true});
  else refresh();
  window.addEventListener('visitor-market:changed',refresh);
  document.addEventListener('header-preferences:state',refresh);
  window.addEventListener('pageshow',refresh);
  var observer=null;
  document.addEventListener('DOMContentLoaded',function(){
    observer=new MutationObserver(updateFields);
    observer.observe(document.body,{childList:true,subtree:true});
    setTimeout(function(){observer.disconnect();},12000);
  },{once:true});
})();
</script><?php
  }
  // Hide the field in Fluent's native markup, not just an optimizer-managed
  // CSS asset. Only the verified published-tour page explicitly reveals it.
  static function native_hide($html,$data,$form){
    if(!self::enabled()||(int)($form->id??0)!==self::FORM_ID ||
       !str_contains((string)($data['settings']['container_class']??''),'mad4b-pickup-field') &&
       !str_contains((string)($data['settings']['container_class']??''),'mad4b-pickup-country-field'))
       return $html;
    if(!is_string($html))return $html;
    return preg_replace('/(<div\b[^>]*class=[\x22\x27][^\x22\x27]*\bmad4b-pickup-field\b[^\x22\x27]*[\x22\x27])/',
      '$1 style="display:none"',$html,1);
  }
  static function global_css(){
    if(!self::enabled())return;
    echo '<style>form.fluent_form_6 .mad4b-pickup-field,form.fluent_form_6 .mad4b-pickup-country-field{display:none}</style>';
  }
  static function checkout_tours(){
    if(!self::enabled()||!function_exists('WC')||!WC()->cart)return [];
    $ids=[];
    foreach(WC()->cart->get_cart() as $line){
      $product=absint($line['product_id']??0);
      if(!$product)continue;
      $tour=0;
      if(get_post_type($product)==='tours-and-activities')$tour=$product;
      elseif(get_post_type($product)==='tour-rates')
         $tour=absint(get_post_meta($product,'related_tour_id',true));
      if($tour&&self::visitor_requires_hotel($tour))$ids[$tour]=true;
    }
    return array_keys($ids);
  }
  static function checkout_fields($fields){
    if(!self::enabled())return $fields;
    foreach(self::checkout_tours() as $tour){
      $prefix='mad4b_pickup_'.$tour.'_';
      $fields['order'][$prefix.'status']=[
        'type'=>'select','label'=>sprintf('Hotel pickup for %s',get_the_title($tour)),
        'required'=>true,'options'=>[''=>'Select your hotel status',
          'booked'=>'I have booked a hotel','not_booked'=>'Hotel not booked yet'],
        'priority'=>160+$tour%40
      ];
      foreach(['hotel_name'=>'Hotel name','hotel_city'=>'Hotel city / area',
        'hotel_address'=>'Hotel address or map details (optional)'] as $key=>$label)
        $fields['order'][$prefix.$key]=[
          'type'=>'text','label'=>$label,'required'=>false,
          'priority'=>161+$tour%40,'class'=>['form-row-wide']];
    }
    return $fields;
  }
  static function checkout_validation($data,$errors){
    foreach(self::checkout_tours() as $tour){
      $prefix='mad4b_pickup_'.$tour.'_';
      $raw=[];
      foreach(['status'=>'mad4b_pickup_booking_status',
        'hotel_name'=>'mad4b_pickup_hotel_name',
        'hotel_city'=>'mad4b_pickup_hotel_city',
        'hotel_address'=>'mad4b_pickup_hotel_address'] as $key=>$name)
        $raw[$name]=$data[$prefix.$key]??'';
      $check=self::clean($raw,$tour);
      if(is_wp_error($check))$errors->add($check->get_error_code(),
        'Please provide valid hotel pickup details for '.get_the_title($tour));
    }
  }
  static function checkout_order($order,$data){
    $locations=[];
    foreach(self::checkout_tours() as $tour){
      $prefix='mad4b_pickup_'.$tour.'_';
      $raw=[];
      foreach(['status'=>'mad4b_pickup_booking_status',
        'hotel_name'=>'mad4b_pickup_hotel_name',
        'hotel_city'=>'mad4b_pickup_hotel_city',
        'hotel_address'=>'mad4b_pickup_hotel_address'] as $key=>$name)
        $raw[$name]=$data[$prefix.$key]??'';
      $v=self::clean($raw,$tour);
      if(is_wp_error($v))return;
      $locations[(string)$tour]=$v;
    }
    if($locations)$order->update_meta_data('_mad4b_booking_pickup_locations_v1',$locations);
  }
  static function boot(){
    add_action('wp_ajax_mad4b_pickup_location_state',[__CLASS__,'location_state']);
    add_action('wp_ajax_nopriv_mad4b_pickup_location_state',[__CLASS__,'location_state']);
    add_filter('fluentform/validation_errors',[__CLASS__,'validate'],30,4);
    add_filter('fluentform/filter_insert_data',[__CLASS__,'persist'],45,1);
    add_filter('fluentform/rendering_field_html_select',[__CLASS__,'native_hide'],80,3);
    add_filter('fluentform/rendering_field_html_input_text',[__CLASS__,'native_hide'],80,3);
    add_action('wp_head',[__CLASS__,'global_css'],100);
    add_action('wp_footer',[__CLASS__,'frontend'],20);
    add_filter('woocommerce_checkout_fields',[__CLASS__,'checkout_fields'],45,1);
    add_action('woocommerce_after_checkout_validation',[__CLASS__,'checkout_validation'],45,2);
    add_action('woocommerce_checkout_create_order',[__CLASS__,'checkout_order'],45,2);
  }
}
MAD4B_Booking_Pickup_Fields::boot();
