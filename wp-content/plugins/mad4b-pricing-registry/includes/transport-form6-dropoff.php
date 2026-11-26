<?php
/**
 * Fluent Form 6: traveler-requested final drop-off instructions, NON-financial.
 * Tour Rate owns scheduled route, provider approves capacity and price, and
 * Fluent Form collects preferred logistics without certifying any transfer.
 */
defined('ABSPATH')||exit;
final class MAD4B_Transport_Form6_Dropoff_V1 {
    public const CONTRACT='mad4b.form6.transport-dropoff.v1';
    public const RESPONSE='_mad4b_transport_dropoff_v1';
    public const FIELDS=['mad4b_dropoff_mode','mad4b_dropoff_place_name',
        'mad4b_dropoff_city','mad4b_dropoff_address'];
    /** Resolve the server-owned published booking template, not a host or request ID. */
    public static function booking_form(int $tour,int $rate=0):int {
        $router='Commerce\\Composition\\FormRouter';
        if(!class_exists($router)||!self::permitted_context($tour,$rate))return 0;
        $r=method_exists($router,'templateIdentity')?$router::templateIdentity($tour,$rate?:null):$router::describe($tour,$rate?:null);
        $id=is_array($r)?($r['booking_form_id']??null):null;
        return is_int($id)&&$id>0&&($r['checkout_authority']??null)===false?$id:0;
    }
    public static function modes():array {
        return ['same_as_pickup','hotel','airport','rail_station','port',
            'meeting_point','to_confirm'];
    }
    public static function normalize(array $submitted,array $pickup=[]):array {
        $mode=sanitize_key((string)($submitted['mad4b_dropoff_mode']??''));
        if($mode!==''&&!in_array($mode,self::modes(),true))
            return ['status'=>'invalid_mode','needs_review'=>true,'checkout_authorized'=>false];
        $place=sanitize_text_field((string)($submitted['mad4b_dropoff_place_name']??''));
        $city=sanitize_text_field((string)($submitted['mad4b_dropoff_city']??''));
        $address=sanitize_text_field((string)($submitted['mad4b_dropoff_address']??''));
        if(mb_strlen($place)>180||mb_strlen($city)>100||mb_strlen($address)>500)
            return ['status'=>'invalid_length','needs_review'=>true,'checkout_authorized'=>false];
        $status='DETAILS_PENDING';$source='traveler_form6';
        if($mode==='same_as_pickup'){
            $pickupStatus=(string)($pickup['status']??'');
            if(in_array($pickupStatus,['HOTEL_SUPPLIED','LOCATION_SUPPLIED'],true) &&
               ($pickup['hotel_name']??'')!=='' && ($pickup['hotel_city']??'')!==''){
                $place=(string)$pickup['hotel_name'];
                $city=(string)$pickup['hotel_city'];
                $address=(string)($pickup['hotel_address']??'');
                $status='POINT_REQUESTED_UNCONFIRMED';
                $source='existing_verified_form6_pickup_request';
            }else{
                $place=$city=$address='';
                $status='PICKUP_POINT_STILL_PENDING';
            }
        }elseif($mode==='to_confirm'||$mode===''){
            $place=$city=$address='';
        }elseif($place!==''&&$city!==''){
            $status='POINT_REQUESTED_UNCONFIRMED';
        }else{
            // Partial information should not invalidate a nonfinancial inquiry.
            $status='DETAILS_PENDING';
        }
        return ['contract'=>self::CONTRACT,'mode'=>$mode?:'to_confirm',
            'status'=>$status,'name'=>$place,'city'=>$city,'address'=>$address,
            'source'=>$source,'needs_review'=>true,
            'route_point_verified'=>false,'supplier_confirmed'=>false,
            'availability_checked'=>false,'transport_price_impact'=>'none',
            'checkout_authorized'=>false];
    }
    /** Source-bound Tour or published Rate proof, not a browser-supplied id. */
    public static function permitted_context(int $tour,int $rate):bool {
        if($tour<1||
           get_post_type($tour)!=='tours-and-activities' ||
           get_post_status($tour)!=='publish')return false;
        if(!class_exists('MAD4B_Booking_Pickup_Policy',false)||($rate>0&&MAD4B_Booking_Pickup_Policy::rateFor($tour,$rate)!==$rate))return false;
        $pickupEligible=class_exists('MAD4B_Booking_Pickup_Policy',false) &&
            !empty(MAD4B_Booking_Pickup_Policy::resolve($tour,$rate)['eligible']);
        $rateEligible=$rate>0 &&
            class_exists('MAD4B_Booking_Pickup_Policy',false) &&
            MAD4B_Booking_Pickup_Policy::rateFor($tour,$rate)===$rate &&
            get_post_meta($rate,'has_transportations',true)==='1' &&
            in_array(get_post_meta($rate,'transport_selection_mode',true),
                ['simple','stages'],true);
        return $pickupEligible||$rateEligible;
    }
    public static function stage_context(int $rate):array {
        $base=['transport_rate_stages_present'=>false,
            'last_stage_key'=>null,'route_point_supplier_verified'=>false];
        if($rate<1||get_post_type($rate)!=='tour-rates'||
            get_post_meta($rate,'has_transportations',true)!=='1')return $base;
        $stages=get_post_meta($rate,'_mad4b_transport_stages_v1',true);
        if(!is_array($stages)||!$stages||count($stages)>30)return $base;
        $valid=[];
        foreach($stages as $stage){
            if(!is_array($stage)||!preg_match('/^[a-z0-9_-]{2,60}$/D',
                (string)($stage['key']??'')))continue;
            $valid[]=['key'=>$stage['key'],
                'day_offset'=>max(0,(int)($stage['day_offset']??0))];
        }
        if(!$valid)return $base;
        usort($valid,static fn($a,$b)=>$a['day_offset']<=>$b['day_offset']);
        return ['transport_rate_stages_present'=>true,
            'last_stage_key'=>end($valid)['key'],
            'route_point_supplier_verified'=>false];
    }
    public static function persist($insert) {
        if(!is_array($insert)||($form=(int)($insert['form_id']??0))<1)return $insert;
        $response=json_decode((string)($insert['response']??''),true);
        if(!is_array($response))return $insert;
        $intent=$response['_shared_tour_booking_intent_v2']??null;
        if(!is_array($intent)||
           (int)($intent['version']??0)!==2||
           ($intent['checkout_ready']??null)!==false||
           ($intent['order_created']??null)!==false)return $insert;
        $tour=absint($intent['tour_id']??0);
        $rate=absint($intent['rate_id']??0);
        // Exact original embedded Tour ID must match the signed booking
        // intent; do not trust tour_id, Rate, price or location in JS payloads.
        $posted=[];
        if(is_string($_POST['data']??null)&&strlen($_POST['data'])<=90000)
            parse_str(wp_unslash($_POST['data']),$posted);
        $embed=absint($posted['__fluent_form_embded_post_id']??
            $_POST['__fluent_form_embded_post_id']??0);
        if(!$embed||$embed!==$tour ||
           self::booking_form($tour,$rate)!==$form)return $insert;
        $actual=[];
        foreach(self::FIELDS as $f){
            if(array_key_exists($f,$response)&&is_scalar($response[$f]))
                $actual[$f]=$response[$f];
        }
        $pickup=$response['_mad4b_booking_pickup_v1']??[];
        $pickup=is_array($pickup)?$pickup:[];
        $state=self::normalize($actual,$pickup);
        $state['form_id']=$form;
        $state['tour_id']=$tour;
        $state['tour_rate_id']=$rate?:null;
        $state['pickup_record_key']='_mad4b_booking_pickup_v1';
        $state['stage_reference']=self::stage_context($rate);
        $state['fluent_submission_is_source']=true;
        // The field represents a requested LAST destination. It can never
        // change the scheduled leg, supplier itinerary or commercial price.
        $response[self::RESPONSE]=$state;
        $insert['response']=wp_json_encode($response,JSON_UNESCAPED_UNICODE);
        return $insert;
    }
    private static function locale():string {
        $lang=(string)apply_filters('wpml_current_language',null);
        return in_array($lang,['en','ar','de','fr','es','it'],true)?$lang:'en';
    }
    public static function frontend():void {
        if(!is_singular('tours-and-activities'))return;
        $tour=(int)get_queried_object_id();
        if($tour<1||get_post_status($tour)!=='publish'||!class_exists('MAD4B_Booking_Pickup_Policy',false))return;
        $source=MAD4B_Booking_Pickup_Policy::canonical($tour);
        $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','suppress_filters'=>true,'meta_query'=>[['key'=>'related_tour_id','value'=>array_values(array_unique([$tour,$source])),'compare'=>'IN']]]);
        $forms=[];
        foreach($rates as $rate){$id=self::booking_form($tour,(int)$rate);if($id>0)$forms[(int)$rate]=$id;}
        $default=self::booking_form($tour,0);
        if(!$default&&!$forms)return;
        $data=['tourId'=>$tour,'defaultForm'=>$default,'rateForms'=>$forms,'lang'=>self::locale()];
        $js=dirname(__DIR__).'/assets/transport-form6-dropoff.js';
        if(!is_file($js))return;
        wp_register_script('mad4b-transport-form6-dropoff',
            set_url_scheme(plugins_url(
                'assets/transport-form6-dropoff.js',
                dirname(__DIR__).'/mad4b-pricing-registry.php'),'https'),[],
            (string)filemtime($js),true);
        wp_enqueue_script('mad4b-transport-form6-dropoff');
        wp_add_inline_script('mad4b-transport-form6-dropoff',
            'window.MAD4BTransportForm6Dropoff='.
            wp_json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';',
            'before');
    }
    public static function styles():void {
        if(is_singular('tours-and-activities'))echo '<style>form .mad4b-transport-dropoff-field{display:none}</style>';
    }
    public static function boot():void {
        add_filter('fluentform/filter_insert_data',[__CLASS__,'persist'],55,1);
        add_action('wp_head',[__CLASS__,'styles'],110);
        add_action('wp_enqueue_scripts',[__CLASS__,'frontend'],65);
    }
}
MAD4B_Transport_Form6_Dropoff_V1::boot();
