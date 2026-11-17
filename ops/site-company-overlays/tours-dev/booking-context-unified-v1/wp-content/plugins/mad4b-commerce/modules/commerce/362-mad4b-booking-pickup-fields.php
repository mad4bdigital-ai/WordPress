<?php
/**
 * Plugin Name: MAD4B Booking Pickup Location
 * Description: Source-aware Fluent Form #6 and WooCommerce per-booking hotel pickup fields.
 * Version: 0.3.0
 */
defined('ABSPATH') || exit;
// The ordered-mu original loader may include this file before plugins_loaded.
// Make the policy provider available in THAT lane, not only in commerce boot.
$pickupPolicyFile=__DIR__.'/362-mad4b-booking-pickup-policy.php';
if(!is_readable($pickupPolicyFile))return;
require_once $pickupPolicyFile;
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
  static function pickup_policy($tour,$rate=0,$program=''):array {
    if(!self::enabled() || !class_exists('MAD4B_Booking_Pickup_Policy',false))
      return ['mode'=>'none','eligible'=>false,'source'=>'disabled_or_missing_policy'];
    return MAD4B_Booking_Pickup_Policy::resolve(absint($tour),absint($rate),
      sanitize_title((string)$program));
  }
  static function selected_rate($tour,$fields=[]):int {
    $posted=[];
    if(is_string($_POST['data']??null) && strlen($_POST['data'])<90000)
      parse_str(wp_unslash($_POST['data']),$posted);
    $raw=$posted['shared_tour_selection_v2']??
         $_POST['shared_tour_selection_v2']??
         $fields['shared_tour_selection_v2']??null;
    if(!is_string($raw)||strlen($raw)>4096)return 0;
    $selection=json_decode(wp_unslash($raw),true);
    if(!is_array($selection) || ($selection['source']??'')!=='rate' ||
       (int)($selection['tour_id']??0)!==absint($tour))return 0;
    $rate=absint($selection['rate_id']??0);
    return MAD4B_Booking_Pickup_Policy::rateFor(absint($tour),$rate);
  }
  static function stay_context_for_rate($tour,$rate):string {
    $tour=absint($tour);$rate=absint($rate);
    if(!$rate||!class_exists('MAD4B_Booking_Pickup_Policy',false)||
       !MAD4B_Booking_Pickup_Policy::rateFor($tour,$rate)||
       !function_exists('BookingExperience\\tour_stay_rate_plan'))return '';
    $plan=BookingExperience\tour_stay_rate_plan($rate);
    if(is_wp_error($plan)||!is_array($plan))return '';
    if(($plan['mode']??'')==='simple_or'&&!empty($plan['properties']))
      return 'rate_stay_options';
    if(($plan['mode']??'')==='multi_stage'&&!empty($plan['stages']))
      return 'multi_stage_accommodation';
    return '';
  }
  static function requires_hotel($tour) {
    // Compatibility facade for existing Fluent #6 and Woo native hooks.
    // Hotel-only terminology is legacy; eligible now includes pickup and
    // meeting-point coordination from the same authoritative policy.
    return (bool)(self::pickup_policy($tour)['eligible']??false);
  }
  /**
   * Accommodation coordination is NOT Woo physical shipping and does NOT
   * assert that a hotel is included in the price. Resolve from published
   * cruise programs or the original BookingExperience Rate stay-policy owner.
   * Ambiguous/inquiry Rate accommodation remains subject to agreement.
   */
  static function stay_context($tour):string {
    static $cache=[];
    $tour=absint($tour);
    if(array_key_exists($tour,$cache))return $cache[$tour];
    $cache[$tour]='';
    if(!self::enabled() || get_post_type($tour)!=='tours-and-activities' ||
       get_post_status($tour)!=='publish')return '';
    $mode=(string)get_post_meta($tour,'tour_mode',true);
    $programs=wp_get_post_terms($tour,'programs',['fields'=>'slugs']);
    if($mode==='cruise_tour' && is_array($programs)){
      foreach($programs as $program)
        if(preg_match('/(?:^|[-_])(?:[1-9][0-9]?)[-_]?nights?\b/i',(string)$program))
          return $cache[$tour]='cruise_overnight_program';
    }
    if(function_exists('BookingExperience\\tour_stay_rate_plan')){
      $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish',
        'posts_per_page'=>16,'fields'=>'ids','suppress_filters'=>true,
        'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$tour]]]);
      foreach($rates as $rate){
        $plan=\BookingExperience\tour_stay_rate_plan((int)$rate);
        if(is_wp_error($plan)||!is_array($plan))continue;
        if(($plan['mode']??'')==='simple_or' && !empty($plan['properties']))
          return $cache[$tour]='rate_stay_options';
        foreach((array)($plan['stages']??[]) as $stage)
          if(!empty($stage['properties']) || !empty($stage['property_id']))
            return $cache[$tour]='multi_stage_accommodation';
      }
    }
    return $cache[$tour];
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
    if(is_array($terms) && !$terms && class_exists('MAD4B_Booking_Pickup_Policy',false)){
      $canonical=MAD4B_Booking_Pickup_Policy::canonical($tour);
      if($canonical!==$tour)$terms=wp_get_object_terms($canonical,'destinations');
    }
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
  static function effective_location_country($input=[],$tour=0){
    $detected=self::location_country();
    if($detected!=='')return $detected;
    $choice=strtoupper((string)($input['mad4b_pickup_current_country']??''));
    $pickup=self::pickup_country($tour);
    if($pickup!=='' && $choice===$pickup)return $pickup;
    if($choice==='OTHER')return 'OTHER';
    // Backward compatibility for the original Egypt-only standalone form.
    if(!$tour && $choice==='EG')return 'EG';
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
    $rate=$id?self::selected_rate($id,$data):0;
    if(!(self::pickup_policy($id,$rate)['eligible']??false))return 0;
    // When a tour selection is supplied, it must refer to exactly this tour.
    $rawSelection=$posted['shared_tour_selection_v2']??$_POST['shared_tour_selection_v2']??null;
    if(is_string($rawSelection)){
      $decoded=json_decode(wp_unslash($rawSelection),true);
      if(!is_array($decoded)||absint($decoded['tour_id']??0)!==$id)return 0;
    }
    return $id;
  }
  static function clean($input,$tour,$rate=0) {
    if(!(self::pickup_policy($tour,$rate)['eligible']??false))
      return new WP_Error('pickup_tour_not_eligible');
    $policy=self::pickup_policy($tour,$rate);
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
      'status'=>$status==='booked'?
        (in_array($policy['mode']??'', ['hotel_pickup','hotel_or_cruise'],true)?
          'HOTEL_SUPPLIED':'LOCATION_SUPPLIED'):'PICKUP_LOCATION_PENDING',
      'hotel_name'=>$name,'hotel_city'=>$city,'hotel_address'=>$address,
      'tour_origin_post_meta_untouched'=>true];
  }
  static function validate($errors,$data,$form,$fields){
    if(!self::enabled()||(int)($form->id??0)!==self::FORM_ID)return $errors;
    $tour=self::submission_tour(is_array($data)?$data:[]);
    if(!$tour)return $errors;
    $rate=self::selected_rate($tour,is_array($data)?$data:[]);
    if(!(self::pickup_policy($tour,$rate)['eligible']??false))return $errors;
    // Booking country is the destination; current physical location is not
    // an eligibility requirement. Only an explicitly entered meeting/hotel
    // location must pass source-bound validation.
    $status=sanitize_key((string)($data['mad4b_pickup_booking_status']??''));
    if($status==='')return $errors;
    $result=self::clean((array)$data,$tour,$rate);
    if(!is_wp_error($result))return $errors;
    $key=$result->get_error_code()==='pickup_booking_status_required'
       ?'mad4b_pickup_booking_status':'mad4b_pickup_hotel_name';
    $errors[$key]=[apply_filters('mad4b/booking_pickup/validation_message',
      'Please provide a valid pickup location and city, or request coordination.',
      'location_details_required')];
    return $errors;
  }
  static function persist($insert){
    if(!self::enabled() || !is_array($insert) ||
       (int)($insert['form_id']??0)!==self::FORM_ID)return $insert;
    $response=json_decode((string)($insert['response']??''),true);
    if(!is_array($response))return $insert;
    $tour=self::submission_tour($response);
    $rate=$tour?self::selected_rate($tour,$response):0;
    $stay=$tour?self::stay_context_for_rate($tour,$rate):'';
    $policy=$tour?self::pickup_policy($tour,$rate):['eligible'=>false];
    if(!$tour || !($policy['eligible']??false)){
      // Explicitly disabled route, invalid Tour or no service evidence.
      foreach(self::FIELDS as $field)unset($response[$field]);
      unset($response['_mad4b_booking_pickup_v1']);
      $insert['response']=wp_json_encode($response,JSON_UNESCAPED_UNICODE);
      return $insert;
    }
    $intent=$response['_shared_tour_booking_intent_v2']??null;
    if(is_array($intent) && (int)($intent['tour_id']??0)!==$tour)return $insert;
    $status=sanitize_key((string)($response['mad4b_pickup_booking_status']??''));
    if($status===''){
      $location=['contract'=>self::CONTRACT,'tour_id'=>$tour,
        'status'=>$stay?'ACCOMMODATION_COORDINATION_PENDING':'PICKUP_LOCATION_PENDING',
        'hotel_name'=>'','hotel_city'=>'','hotel_address'=>'',
        'tour_origin_post_meta_untouched'=>true];
    }else{
      $location=self::clean($response,$tour,$rate);
      if(is_wp_error($location))return $insert;
    }
    $location['rate_id']=$rate;
    $location['policy_mode']=$policy['mode']??'none';
    $location['policy_source']=$policy['source']??'unverified';
    $location['service_included']=$policy['included']??null;
    $location['scope']=$stay?'stay_coordination':
      (in_array($policy['mode']??'', ['hotel_pickup','hotel_or_cruise'],true)?
       'hotel_pickup':'pickup_coordination');
    $location['stay_context']=$stay;
    $location['form_id']=self::FORM_ID;
    $location['visitor_location_country']='not_used_for_pickup_eligibility';
    $location['pickup_destination_country']=self::pickup_country($tour);
    $location['country_context_source']='tour_destination_policy';
    $location['source']='fluent_forms_native_submission';
    $location['recorded_at']=gmdate('c');
    $response['_mad4b_booking_pickup_v1']=$location;
    $insert['response']=wp_json_encode($response,JSON_UNESCAPED_UNICODE);
    return $insert;
  }
  /**
   * Source-verified accommodation preference for the same original Fluent
   * Form #6 record. A preferred hotel is not a payable line, room allocation,
   * inventory reservation, or supplier confirmation. This read-only
   * preference is independent of whether Pickup happens to be included.
   */
  static function persist_stay_review($insert){
    if(!self::enabled()||!is_array($insert)||
       (int)($insert['form_id']??0)!==self::FORM_ID||
       !function_exists('shared_form6_review_stays'))return $insert;
    $response=json_decode((string)($insert['response']??''),true);
    if(!is_array($response))return $insert;
    $tour=self::submission_tour($response);
    if(!$tour)return $insert;
    $posted=[];
    if(is_string($_POST['data']??null) && strlen($_POST['data'])<90000)
      parse_str(wp_unslash($_POST['data']),$posted);
    $raw=$posted['shared_tour_selection_v2']??$response['shared_tour_selection_v2']??null;
    if(!is_string($raw)||strlen($raw)>4096)return $insert;
    $selection=json_decode(wp_unslash($raw),true);
    if(!is_array($selection)||($selection['source']??null)!=='rate'||
       (int)($selection['tour_id']??0)!==$tour ||
       !is_array($selection['stay_stages']??null)||
       !array_is_list($selection['stay_stages'])||
       count($selection['stay_stages'])>20 ||
       !$selection['stay_stages'])return $insert;
    $rate=absint($selection['rate_id']??0);
    if(!MAD4B_Booking_Pickup_Policy::rateFor($tour,$rate))return $insert;
    $adults=absint($selection['adults']??0);
    if($adults<1||$adults>100 ||
       !isset($response['no_of_adults'])||
       (string)$response['no_of_adults']!==(string)$adults)return $insert;
    $date=(string)($selection['travel_date']??'');
    if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date))return $insert;
    $when=(string)($response['datetime']??'');
    $matched=false;
    foreach(['Y-m-d','d-M-Y','d/m/Y','m/d/Y'] as $fmt){
      $parsed=DateTimeImmutable::createFromFormat('!'.$fmt,$when);
      if($parsed && $parsed->format($fmt)===$when && $parsed->format('Y-m-d')===$date){
        $matched=true;break;
      }
    }
    if(!$matched)return $insert;
    $review=shared_form6_review_stays($tour,$rate,$date,$adults,$selection['stay_stages']);
    if(is_wp_error($review)||!is_array($review)||!$review['rows'])return $insert;
    $response['_mad4b_stay_preference_review_v1']=[
      'contract'=>'mad4b.form6-source-stay-preference.v1',
      'tour_id'=>$tour,'rate_id'=>$rate,'price_source'=>'rate',
      'travel_date'=>$date,'status'=>'staff_review_only',
      'preferences'=>$review['rows'],
      'pending_count'=>(int)($review['pending_count']??0),
      'additional_amount_authorized'=>false,
      'supplier_confirmation'=>false,
      'inventory_checked'=>false,
      'not_shipping'=>true
    ];
    $insert['response']=wp_json_encode($response,JSON_UNESCAPED_UNICODE);
    return $insert;
  }
  /**
   * Trust a persisted Fluent Forms record only when its source matches the
   * Woo request's already verified source. No shipping or price mutations.
   */
  static function order_payload($raw,$source,$rate=0):array {
    $source=absint($source);
    if(!is_array($raw)||$source<1 || (int)($raw['tour_id']??0)!==$source ||
       ($raw['contract']??'')!==self::CONTRACT || (int)($raw['form_id']??0)!==self::FORM_ID ||
       !in_array((string)($raw['status']??''),[
         'HOTEL_SUPPLIED','LOCATION_SUPPLIED','PICKUP_LOCATION_PENDING',
         'ACCOMMODATION_COORDINATION_PENDING'],true))
      return [];
    $rate=MAD4B_Booking_Pickup_Policy::rateFor($source,absint($rate));
    $originalRate=absint($raw['rate_id']??0);
    if($originalRate!==$rate)return [];
    $scope=(string)($raw['scope']??'');
    $stay=self::stay_context_for_rate($source,$rate);
    $policy=self::pickup_policy($source,$rate);
    if($scope==='stay_coordination' && (!$stay || !($policy['eligible']??false)))
      return [];
    if(in_array($scope,['hotel_pickup','pickup_coordination'],true)
       && !($policy['eligible']??false))return [];
    if(!in_array($scope,['stay_coordination','hotel_pickup','pickup_coordination'],true))
        return [];
    $name=sanitize_text_field((string)($raw['hotel_name']??''));
    $city=sanitize_text_field((string)($raw['hotel_city']??''));
    $address=sanitize_text_field((string)($raw['hotel_address']??''));
    if(mb_strlen($name)>180 || mb_strlen($city)>100 || mb_strlen($address)>500)return [];
    $hasLocation=in_array($raw['status'],['HOTEL_SUPPLIED','LOCATION_SUPPLIED'],true);
    if($hasLocation && (mb_strlen($name)<2 || mb_strlen($city)<2))return [];
    if(!$hasLocation)$name=$city=$address='';
    return [
      'contract'=>self::CONTRACT,'tour_id'=>$source,
      'scope'=>$scope,'stay_context'=>$stay,
      'rate_id'=>$rate,'policy_mode'=>$policy['mode']??'none',
      'policy_source'=>$policy['source']??'unknown',
      'service_included'=>$policy['included']??null,
      'status'=>$raw['status'],
      'hotel_name'=>$name,'hotel_city'=>$city,'hotel_address'=>$address,
      'source'=>'verified_fluent_form6',
      'not_shipping'=>true,'not_booking_confirmation'=>true,
    ];
  }
  static function frontend(){
    $tour=get_queried_object_id();
    $policy=self::pickup_policy($tour);
    $pickup=self::pickup_country($tour);
    $stay=''; // The selected Rate, not another Rate, owns accommodation copy.
    if(!self::enabled() || get_post_type($tour)!=='tours-and-activities')return;
    $ratePolicies=[];
    // Reuse linked published Rates, not another rate/pricing provider. Every
    // frontend decision is a lightweight source-backed option, not a quote.
    $siblings=class_exists('ETG_Tour_Rate_Display',false)
      ?ETG_Tour_Rate_Display::language_ids($tour):[$tour];
    $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish',
      'posts_per_page'=>100,'fields'=>'ids','suppress_filters'=>true,
      'meta_query'=>[['key'=>'related_tour_id','value'=>array_map('strval',(array)$siblings),'compare'=>'IN']]]);
    foreach($rates as $rate){
      $rate=(int)$rate;
      if(MAD4B_Booking_Pickup_Policy::rateFor($tour,$rate)!==$rate)continue;
      $specific=self::pickup_policy($tour,$rate);
      $ratePolicies[(string)$rate]=[
        'mode'=>$specific['mode']??'none',
        'source'=>$specific['source']??'unknown',
        'included'=>$specific['included']??null,
        'stay_context'=>self::stay_context_for_rate($tour,$rate),
      ];
    }
    $anyRateEligible=false;
    foreach($ratePolicies as $row)if(($row['mode']??'none')!=='none')$anyRateEligible=true;
    if(!($policy['eligible']??false) && !$anyRateEligible)return;
    $endpoint=admin_url('admin-ajax.php?action=mad4b_pickup_location_state');
    $countries=function_exists('WC') && WC()->countries ? WC()->countries->get_countries():[];
    $destinationName=sanitize_text_field((string)($countries[$pickup]??$pickup));
    ?>
<script>
(function(){
  'use strict';
  var endpoint=<?php echo wp_json_encode($endpoint); ?>;
  var pickupCountry=<?php echo wp_json_encode($pickup); ?>;
  var pickupCountryName=<?php echo wp_json_encode($destinationName); ?>;
  var stayContext=<?php echo wp_json_encode($stay); ?>;
  var pickupMode=<?php echo wp_json_encode($policy['mode']??'none'); ?>;
  var serviceIncluded=<?php echo wp_json_encode($policy['included']??null); ?>;
  var tourId=<?php echo wp_json_encode((int)$tour); ?>;
  var ratePolicies=<?php echo wp_json_encode($ratePolicies); ?>;
  var language=<?php echo wp_json_encode(substr((string)apply_filters('wpml_current_language','en'),0,2)); ?>;
  var stayCopy={
    en:['Pickup arrangement (optional)','Pickup from another hotel I booked','Coordinate pickup with our team','Choose pickup arrangement'],
    es:['Recogida (opcional)','Recogida desde otro hotel reservado por mí','Coordinar recogida con el equipo','Elegir recogida'],
    fr:['Prise en charge (facultatif)','Depuis un autre hôtel que j’ai réservé','Coordonner la prise en charge','Choisir la prise en charge'],
    de:['Abholung (optional)','Abholung von meinem separat gebuchten Hotel','Abholung mit dem Team abstimmen','Abholung wählen'],
    it:['Prelievo (facoltativo)','Da un altro hotel prenotato da me','Coordinare il prelievo','Scegliere il prelievo'],
    ar:['تنسيق الاستقبال (اختياري)','الاستقبال من فندق آخر حجزته بنفسي','تنسيق الاستقبال مع فريق الرحلة','اختر طريقة الاستقبال']
  };
  var pickupCopy={
    en:['Pickup / meeting point (optional)','I have a hotel or pickup address','Please coordinate a pickup / meeting point','Choose pickup arrangement'],
    es:['Recogida / punto de encuentro (opcional)','Tengo hotel o dirección de recogida','Coordinar recogida / encuentro','Elegir recogida'],
    fr:['Prise en charge / rendez-vous (facultatif)','J’ai un hôtel ou une adresse','Organiser la prise en charge','Choisir la prise en charge'],
    de:['Abholung / Treffpunkt (optional)','Ich habe ein Hotel oder eine Adresse','Abholung / Treffpunkt abstimmen','Abholung wählen'],
    it:['Prelievo / incontro (facoltativo)','Ho un hotel o un indirizzo','Coordinare il prelievo','Scegliere il prelievo'],
    ar:['تنسيق الاستقبال أو نقطة اللقاء (اختياري)','لدي فندق أو عنوان للاستقبال','يرجى تنسيق نقطة الاستقبال','اختر طريقة الاستقبال']
  };
  var geoCountry='',geoLoaded=true,version=0,stopped=false;
  function selectedPolicy(form){
    var rate=0;
    var broker=window.SharedPresentationSelectionV1;
    var snapshot=broker&&typeof broker.getSnapshot==='function'?
      broker.getSnapshot():null;
    if(snapshot&&Number(snapshot.tourId)===tourId)
      rate=Number(snapshot.selectedRateId)||0;
    else {
      var input=form.querySelector('[name="mad4b_route_rate_id"]');
      if(input)rate=Number(input.value)||0;
    }
    if(!rate && !(snapshot&&Number(snapshot.tourId)===tourId)){
      var src=form.querySelector('[name="shared_tour_selection_v2"]');
      if(src && src.value){
        try {
          var item=JSON.parse(src.value);
          if(Number(item.tour_id)===tourId && item.source==='rate')
            rate=Number(item.rate_id)||0;
        }catch(e){}
      }
    }
    return (ratePolicies[String(rate)]||{mode:pickupMode,
      included:serviceIncluded,source:'tour_or_service_policy',stay_context:''});
  }
  function updateFields(){
    document.querySelectorAll('form.fluent_form_6').forEach(function(form){
      var location=form.querySelector('[name="mad4b_pickup_current_country"]');
      var status=form.querySelector('[name="mad4b_pickup_booking_status"]');
      if(!status)return;
      var currentPolicy=selectedPolicy(form);
      var currentMode=currentPolicy.mode||'none';
      var selectedStay=String(currentPolicy.stay_context||'');
      var copyMode=selectedStay?'stay':'pickup';
      if(status.dataset.mad4bCopyMode!==copyMode){
        var copy=selectedStay?(stayCopy[language]||stayCopy.en):
          (pickupCopy[language]||pickupCopy.en);
        status.setAttribute('aria-label',copy[0]);
        var row=status.closest('.mad4b-pickup-field');
        if(row){
          var prompt=row.querySelector('label');
          if(prompt)prompt.textContent=copy[0];
        }
        Array.prototype.forEach.call(status.options,function(option){
          if(option.value==='')option.textContent=copy[3]||'Choose pickup arrangement';
          else if(option.value==='booked')option.textContent=copy[1];
          else if(option.value==='not_booked')option.textContent=copy[2];
        });
        status.dataset.mad4bCopyMode=copyMode;
      }
      if(location){
        var locationRow=location.closest('.mad4b-pickup-country-field');
        if(locationRow)locationRow.style.display='none';
        location.disabled=true;
        location.required=false;
      }
      var eligible=currentMode!=='none';
      var statusRow=status.closest('.mad4b-pickup-field');
      if(statusRow)statusRow.style.display=eligible?'block':'none';
      status.disabled=!eligible;
      status.required=false;
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
    ++version;
    // Pickup is at the travel destination; current visitor country must
    // not hide the fields or be used as booking eligibility.
    geoCountry='';geoLoaded=true;updateFields();
  }
  if(document.readyState==='loading')
    document.addEventListener('DOMContentLoaded',refresh,{once:true});
  else refresh();
  document.addEventListener('change',function(event){
    if(event.target && event.target.matches &&
      event.target.matches('[name="mad4b_route_rate_id"],[name="shared_tour_selection_v2"]'))
      updateFields();
  },true);
  document.addEventListener('input',function(event){
    if(event.target && event.target.matches &&
      event.target.matches('[name="mad4b_route_rate_id"],[name="shared_tour_selection_v2"]'))
      updateFields();
  },true);
  document.addEventListener('shared-presentation-selection-changed',updateFields);
  document.addEventListener('mad4b:tour-rate-changed',updateFields);
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
      if($tour&&(self::stay_context($tour)||self::requires_hotel($tour)))
        $ids[$tour]=true;
    }
    return array_keys($ids);
  }
  static function checkout_fields($fields){
    if(!self::enabled())return $fields;
    foreach(self::checkout_tours() as $tour){
      $prefix='mad4b_pickup_'.$tour.'_';
      $fields['order'][$prefix.'status']=[
        'type'=>'select','label'=>sprintf(
          self::stay_context($tour)?'Accommodation / arrival coordination for %s':'Hotel pickup for %s',
          get_the_title($tour)),
        'required'=>false,
        'options'=>[''=>self::stay_context($tour)?
          'Optional: tell us about any independent hotel before the tour':'Select your hotel status',
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
      if(empty($raw['mad4b_pickup_booking_status']))continue;
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
      if(empty($raw['mad4b_pickup_booking_status'])){
        $v=['contract'=>self::CONTRACT,'tour_id'=>$tour,
          'status'=>'ACCOMMODATION_COORDINATION_PENDING',
          'hotel_name'=>'','hotel_city'=>'','hotel_address'=>''];
      }else{
        $v=self::clean($raw,$tour);
        if(is_wp_error($v))return;
      }
      $v['scope']=self::stay_context($tour)?'stay_coordination':'hotel_pickup';
      $v['not_shipping']=true;
      $locations[(string)$tour]=$v;
    }
    if($locations)$order->update_meta_data('_mad4b_booking_pickup_locations_v1',$locations);
  }
  static function boot(){
    add_action('wp_ajax_mad4b_pickup_location_state',[__CLASS__,'location_state']);
    add_action('wp_ajax_nopriv_mad4b_pickup_location_state',[__CLASS__,'location_state']);
    add_filter('fluentform/validation_errors',[__CLASS__,'validate'],30,4);
    add_filter('fluentform/filter_insert_data',[__CLASS__,'persist'],45,1);
    add_filter('fluentform/filter_insert_data',[__CLASS__,'persist_stay_review'],48,1);
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
