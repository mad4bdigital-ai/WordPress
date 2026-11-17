<?php
/** Plugin Name: Visitor Country + Passport Preferences (Local Lab) */
if(!defined('ABSPATH'))exit;
add_action('rest_api_init',static function(){
 // A fresh nonce pair is specific to the current WordPress identity,
 // unlike cached Elementor header HTML which may carry a guest nonce.
 register_rest_route('visitor-preferences/v1','/state',[
  'methods'=>'GET','permission_callback'=>'__return_true',
  'callback'=>static function(){
   $response=new WP_REST_Response([
    'contract'=>'visitor-preferences.state.v1',
    'nonces'=>[
      'country'=>wp_create_nonce('mad4b_country_choose'),
      'passport'=>wp_create_nonce('travel_booking_save_nationality')
    ]
   ],200);
   $response->header('Cache-Control','private, no-cache, no-store, max-age=0');
   $response->header('Vary','Cookie');
   return $response;
  }
 ]);
 register_rest_route('visitor-preferences/v1','/apply',[
  'methods'=>'POST','permission_callback'=>'__return_true',
  'callback'=>static function(WP_REST_Request $request){
   // A WordPress guest nonce is not session-specific by default. Enforce
   // exact same-origin JSON POST to prevent CSRF preference writes from
   // cross-origin forms; state reads remain same-origin no-store.
   $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
   $source=wp_parse_url($origin);
   $target=wp_parse_url(home_url('/'));
   if (!is_array($source) || !is_array($target)
      || ($source['scheme']??'')!==($target['scheme']??'')
      || strcasecmp((string)($source['host']??''),(string)($target['host']??''))!==0
      || (int)($source['port']??($source['scheme']==='https'?443:80))
         !==(int)($target['port']??($target['scheme']==='https'?443:80)))
     return new WP_Error('invalid_origin','Invalid preference request origin',['status'=>403]);
   if (!preg_match('~^application/json(?:\s*;|$)~i',(string)($_SERVER['CONTENT_TYPE']??'')))
     return new WP_Error('invalid_content_type','JSON required',['status'=>415]);
   // The Site Control Plane deliberately stays zero-touch on unrelated REST
   // paths. Load ONLY the read-only country/market classes for this audited
   // visitor preference route, not the Control Plane full runtime.
   if(defined('MAD4B_SCP_DIR')){
    if(!class_exists('MAD4B_SCP_Market_Profiles'))
     require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-market-profiles.php';
    if(!class_exists('MAD4B_SCP_Switcher_Sources'))
     require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-switcher-sources.php';
   }
   if(!class_exists('MAD4B_Country_Switcher')||!class_exists('Travel_Booking_Traveller_Nationality_Switcher'))
    return new WP_Error('preferences_unavailable','Preference modules unavailable',['status'=>503]);
   $action=(string)$request->get_param('action');
   $code=strtoupper((string)$request->get_param('country'));
   $nonce=(string)$request->get_param('nonce');
   if(!in_array($action,['country','manual_passport','auto_passport'],true)||
      strlen($nonce)<8||strlen($nonce)>120)
    return new WP_Error('invalid_request','Invalid preference operation',['status'=>400]);
   $nonceAction=$action==='country'?'mad4b_country_choose':'travel_booking_save_nationality';
   if(!wp_verify_nonce($nonce,$nonceAction))
    return new WP_Error('invalid_nonce','Invalid preference nonce',['status'=>403]);
   if($action==='country'){
    $result=MAD4B_Country_Switcher::set_country($code);
    if(is_wp_error($result))return new WP_Error($result->get_error_code(),$result->get_error_message(),['status'=>400]);
    // Do not override an existing explicit Passport nationality.
    $manual=Travel_Booking_Traveller_Nationality_Switcher::selected_manual();
    $passport=$manual?:Travel_Booking_Traveller_Nationality_Switcher::valid($code);
    $mode=$manual?'manual':'auto';
   }elseif($action==='manual_passport'){
    $result=Travel_Booking_Traveller_Nationality_Switcher::choose($code);
    if(is_wp_error($result))return new WP_Error($result->get_error_code(),$result->get_error_message(),['status'=>400]);
    $passport=$result;$mode='manual';
   }else{
    Travel_Booking_Traveller_Nationality_Switcher::reset_manual();
    $state=MAD4B_Country_Switcher::context();
    $passport=Travel_Booking_Traveller_Nationality_Switcher::valid($state['country']??'');
    $mode='auto';
   }
   $state=MAD4B_Country_Switcher::context();
   $market=($action==='country'?$code:($state['country']??''));
   $profile=MAD4B_Country_Switcher::resolve($market);
   $response=new WP_REST_Response([
    'contract'=>'visitor-preferences.v1',
    'country'=>(string)$market,'passport'=>(string)$passport,'mode'=>$mode,
    'country_flag'=>$market?MAD4B_SCP_Switcher_Sources::country_flag($market):'',
    'passport_flag'=>$passport?Travel_Booking_Traveller_Nationality_Switcher::flag_url($passport):'',
    'market_profile_id'=>$profile['profile_id']??null,
    'requires_market_revalidation'=>$action==='country',
   ],200);
   $response->header('Cache-Control','private, no-store, max-age=0');
   $response->header('Vary','Cookie');
   return $response;
  },
  'args'=>[
   'action'=>['required'=>true,'type'=>'string'],
   'country'=>['type'=>'string','default'=>''],
   'nonce'=>['required'=>true,'type'=>'string'],
  ],
 ]);
});
