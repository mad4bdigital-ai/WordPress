<?php
/**
 * MAD4B Unified Date UI V1 — presentation-only native adapters.
 * Can be enabled per company; no checkout, inventory or price writes.
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/unified-date-intent-v1.php';

function mad4b_unified_date_enabled_v1(): bool {
    if (get_option('mad4b_unified_date_enabled_v1', 'off') === 'on') return true;
    // Private source preview before rollout; never enable for public users by URL.
    return is_user_logged_in() && current_user_can('manage_options')
        && isset($_GET['mad4b_date_preview']) && $_GET['mad4b_date_preview'] === '1';
}

function mad4b_unified_date_request_v1(): array {
    // A cleared date in a JetSmartFilters AJAX request must not re-inherit
    // a stale date from the referrer URL.
    if (wp_doing_ajax() && isset($_POST['query']) && is_array($_POST['query'])
        && isset($_POST['query']['mad4b_date_clear'])
        && $_POST['query']['mad4b_date_clear'] === '1') {
        return ['status'=>'absent'];
    }
    $known = ['mad4b_date_mode','mad4b_date_from','mad4b_date_to'];
    $candidates = [];
    // JetSmartFilters may include stale GET/referrer dates while its POST
    // query contains the newly applied Date Intent. POST wins on Ajax only.
    if (wp_doing_ajax() && isset($_POST['query']) && is_array($_POST['query'])) $candidates[] = $_POST['query'];
    if ($_GET) $candidates[] = $_GET;
    if (wp_doing_ajax() && isset($_POST['referrer']['uri']) && is_string($_POST['referrer']['uri'])) {
        $refPath=wp_unslash($_POST['referrer']['uri']);
        if (str_starts_with($refPath,'/') && !str_starts_with($refPath,'//')) {
            parse_str((string)wp_parse_url($refPath,PHP_URL_QUERY),$refParams);
            if(is_array($refParams)) $candidates[] = $refParams;
        }
    }
    if (wp_doing_ajax()) {
        foreach (['url','current_url'] as $key) {
            if (!empty($_POST[$key]) && is_string($_POST[$key])) {
                $url = wp_unslash($_POST[$key]);
                if (wp_parse_url($url, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST)) {
                    parse_str((string)wp_parse_url($url,PHP_URL_QUERY), $params);
                    $candidates[] = $params;
                }
            }
        }
        $ref = wp_get_referer();
        if ($ref && wp_parse_url($ref,PHP_URL_HOST) === wp_parse_url(home_url(),PHP_URL_HOST)) {
            parse_str((string)wp_parse_url($ref,PHP_URL_QUERY), $params);
            $candidates[] = $params;
        }
    }
    foreach ($candidates as $candidate) {
        if (!isset($candidate['mad4b_date_mode'])) continue;
        $input = [];
        foreach ($known as $key) {
            if (!isset($candidate[$key])) continue;
            if (!is_scalar($candidate[$key])) return ['status'=>'invalid','reason'=>'invalid_input_shape'];
            $input[$key] = sanitize_text_field(wp_unslash((string)$candidate[$key]));
        }
        $normalized = MAD4B_Unified_Date_Intent_V1::normalize($input,true,is_singular('tours-and-activities')?(int)get_queried_object_id():0);
        return is_wp_error($normalized)
            ? ['status'=>'invalid', 'reason'=>$normalized->get_error_code()]
            : (is_array($normalized) ? ['status'=>'valid','intent'=>$normalized] : ['status'=>'absent']);
    }
    if (isset($_GET['travel_date']) && is_scalar($_GET['travel_date'])) {
        $legacyDate=mad4b_shared_travel_date_parse_v1(wp_unslash((string)$_GET['travel_date']));
        if ($legacyDate) {
            $candidate=MAD4B_Unified_Date_Intent_V1::normalize(['mad4b_date_mode'=>'fixed','mad4b_date_from'=>$legacyDate],true,is_singular('tours-and-activities')?(int)get_queried_object_id():0);
            if(is_array($candidate))return ['status'=>'valid','intent'=>$candidate];
        }
    }
    return ['status'=>'absent'];
}

/**
 * Apply date eligibility as an AND constraint to existing native post filters.
 *
 * An upstream post__in=[0] is a deny-all sentinel, not an empty list. Never
 * broaden this into date-matching IDs. Empty/missing post__in means no earlier
 * explicit post selection and is allowed to be narrowed by travel date.
 */
function mad4b_unified_date_intersect_post_ids_v1(array $matching,array $args):array {
    $ids=array_values(array_unique(array_filter(array_map(
        static fn($value):int => is_scalar($value) && is_numeric($value) && (int)$value>0 ? (int)$value : 0,
        $matching
    ))));
    if(array_key_exists('post__in',$args)){
        $raw=(array)$args['post__in'];
        if($raw){
            // Query 310 and archive may receive post__in=[0] (or other
            // invalid ID markers) from prior AND filters. Do not discard
            // the sentinel and re-include date-eligible Rate IDs.
            foreach($raw as $value) {
                if(!is_scalar($value) || !is_numeric($value)
                   || (int)$value<=0) return [0];
            }
            $existing=array_values(array_unique(array_map('intval',$raw)));
            if(!$existing)return [0];
            $ids=array_values(array_intersect($ids,$existing));
        }
    }
    return $ids?:[0];
}

/** JSF and JetEngine retain their own query providers. We narrow only their tour archive listing. */
add_filter('jet-engine/query-builder/types/posts-query/args', static function($args,$query) {
    if (!mad4b_unified_date_enabled_v1() || !is_array($args)) return $args;
    if (!is_object($query) || !in_array((string)($query->query_id??''),['tours_query_archive'],true)) return $args;
    $request = mad4b_unified_date_request_v1();
    if (($request['status']??'absent')==='absent') return $args;
    if ($request['status']==='invalid') {
        $args['post__in']=[0];
        return $args;
    }
    static $memo = [];
    $intent=$request['intent'];
    $memoKey=hash('sha256',wp_json_encode([$intent['date_from'],$intent['date_to'],$intent['mode']]));
    if (!isset($memo[$memoKey])) $memo[$memoKey]=MAD4B_Unified_Date_Intent_V1::matching_tour_ids($intent);
    $matching=$memo[$memoKey];
    if (has_filter('wpml_object_id')) {
        $lang=apply_filters('wpml_current_language',null);
        if (is_string($lang) && $lang !== '') {
            $matching=array_values(array_unique(array_map(
                static fn($id):int => (int)apply_filters('wpml_object_id',$id,'tours-and-activities',true,$lang),
                $matching
            )));
        }
    }
    $args['post__in']=mad4b_unified_date_intersect_post_ids_v1($matching,$args);
    return $args;
}, 65, 2);


/** JetSearch REST suggestions: filter on the server, not by hiding DOM cards. */
add_filter('jet-search/ajax-search/query-args',static function($args,$handler){
    if(!mad4b_unified_date_enabled_v1()||!is_array($args))return $args;
    $url=(string)($_SERVER['REQUEST_URI']??'');
    if(!str_contains($url,'/jet-search/v1/search-posts')
       && !(wp_doing_ajax() && ($_REQUEST['action']??'')==='jet_ajax_search'))return $args;
    $types=(array)($args['post_type']??[]);
    if(!in_array('tours-and-activities',$types,true))return $args;
    if(!isset($_GET['mad4b_date_mode']))return $args;
    $keys=['mad4b_date_mode','mad4b_date_from','mad4b_date_to'];$data=[];
    foreach($keys as $key){
        if(!isset($_GET[$key]))continue;
        if(!is_scalar($_GET[$key])){$args['post__in']=[0];return $args;}
        $data[$key]=sanitize_text_field(wp_unslash((string)$_GET[$key]));
    }
    $parsed=MAD4B_Unified_Date_Intent_V1::normalize($data,true,is_singular('tours-and-activities')?(int)get_queried_object_id():0);
    if(!is_array($parsed) || is_wp_error($parsed)){$args['post__in']=[0];return $args;}
    $ids=MAD4B_Unified_Date_Intent_V1::matching_tour_ids($parsed);
    $args['post__in']=mad4b_unified_date_intersect_post_ids_v1($ids,$args);
    return $args;
},75,2);

/**
 * Restore the native WCML display currency for JetSmartFilters Ajax only.
 * It never overrides rate currency, FX tables, checkout or payments.
 */
function mad4b_unified_date_currency_context_ajax_v1():void {
    if(!mad4b_unified_date_enabled_v1())return;
    if(!in_array((string)($_POST['provider']??''),[
      'jet-engine/tour_rates_accordion','jet-engine/tours_query_archive'],true))return;
    $params=$_POST['query']??null;
    if(!is_array($params)||!is_string($params['mad4b_currency']??null))return;
    $currency=strtoupper(sanitize_text_field(wp_unslash($params['mad4b_currency'])));
    $settings=get_option('_wcml_settings',[]);
    if(!in_array($currency,['USD','EUR','EGP','GBP'],true)
       ||!isset($settings['currency_options'][$currency]))return;
    $wcml=$GLOBALS['woocommerce_wpml']->multi_currency??null;
    if(is_object($wcml)&&method_exists($wcml,'set_client_currency')
       &&function_exists('WCML\functions\getSitePress')) {
       $wcml->set_client_currency($currency);
    }
}
add_action('wp_ajax_jet_smart_filters','mad4b_unified_date_currency_context_ajax_v1',0);
add_action('wp_ajax_nopriv_jet_smart_filters','mad4b_unified_date_currency_context_ajax_v1',0);
/** Get read-only date coverage for the Rates section; never adjust amounts. */
function mad4b_unified_tour_rates_for_date_v1(int $tourId,array $intent):array {
    if($tourId<1 || ($intent['contract']??'')!==MAD4B_Unified_Date_Intent_V1::CONTRACT)return [];
    $allowed=function_exists('shared_product_eligible_tour_rate_ids')
        ? shared_product_eligible_tour_rate_ids($tourId):[];
    if(!is_array($allowed))return [];
    $result=[];
    foreach($allowed as $rateId){
        $rateId=absint($rateId);
        if(!$rateId || get_post_type($rateId)!=='tour-rates' || get_post_status($rateId)!=='publish')continue;
        $rateFrom=get_post_meta($rateId,'tour_rate_start_date',true);
        $rateTo=get_post_meta($rateId,'tour_rate_end_date',true);
        if(!is_numeric($rateFrom)||!is_numeric($rateTo))continue;
        if((int)$rateFrom <= (int)$intent['window_to_ts'] &&
           (int)$rateTo >= (int)$intent['window_from_ts'])$result[]=$rateId;
    }
    return array_values(array_unique($result));
}
/**
 * Tour Rate Date Intent read-only projection.
 * Preserves JetEngine query 310, existing package/season constraints and
 * the underlying native JetSmartFilters Date Range widget.
 * UI writes a canonical URL; this server filter is the source of truth.
 */
add_filter('jet-engine/query-builder/types/posts-query/args', static function($args,$query){
    if(!mad4b_unified_date_enabled_v1() || !is_array($args) || !is_object($query)) return $args;
    if((int)($query->id??0)!==310
       || !in_array((string)($query->query_id??''),['tour_rates','tour_rates_accordion'],true))return $args;
    if(is_admin() && !wp_doing_ajax())return $args;
    $request=mad4b_unified_date_request_v1();
    if(($request['status']??'absent')==='absent')return $args;
    if(($request['status']??'')!=='valid'){
        $args['post__in']=[0];
        return $args;
    }
    $tourId=0;
    if(function_exists('shared_product_verified_presentation_tour_id'))
        $tourId=(int)shared_product_verified_presentation_tour_id();
    if(!$tourId && !wp_doing_ajax() && is_singular('tours-and-activities'))
        $tourId=(int)get_queried_object_id();
    if(!$tourId || get_post_type($tourId)!=='tours-and-activities'){
        $args['post__in']=[0];
        return $args;
    }
    $matching=mad4b_unified_tour_rates_for_date_v1($tourId,$request['intent']);
    $args['post__in']=mad4b_unified_date_intersect_post_ids_v1($matching,$args);
    return $args;
},85,2);
add_action('init', static function():void {
    add_shortcode('mad4b_unified_date_picker', static function($attrs):string {
        if (!mad4b_unified_date_enabled_v1()) return '';
        $a=shortcode_atts(['context'=>'archive'],$attrs,'mad4b_unified_date_picker');
        $context=in_array((string)$a['context'],['home','archive'],true)?$a['context']:'archive';
        return '<div class="mad4b-ud-root" data-mad4b-date-context="'.esc_attr($context).'"></div>';
    });
}, 15);

add_action('wp_enqueue_scripts', static function():void {
    if (!mad4b_unified_date_enabled_v1()) return;
    if (!is_front_page() && !is_post_type_archive('tours-and-activities')
        && !is_singular('tours-and-activities')) return;
    if (!wp_script_is('flatpickr','registered')) {
        wp_register_script('flatpickr',plugins_url('assets/libs/flatpickr/flatpickr.min.js',WP_PLUGIN_DIR.'/fluentform/fluentform.php'),[],null,true);
    }
    if (!wp_style_is('flatpickr','registered')) {
        wp_register_style('flatpickr',plugins_url('assets/libs/flatpickr/flatpickr.min.css',WP_PLUGIN_DIR.'/fluentform/fluentform.php'),[],null);
    }
    wp_enqueue_script('flatpickr');
    wp_enqueue_style('flatpickr');
    wp_enqueue_style('mad4b-unified-date-v1',plugins_url('assets/unified-date-v0.3.18.css',__FILE__),['flatpickr'],'0.3.18');
    wp_enqueue_script('mad4b-unified-date-v1',plugins_url('assets/unified-date-v0.4.8.js',__FILE__),['flatpickr'],'0.4.8',true);
    $data=[
        'archiveUrl'=>home_url('/tours-and-activities/'),
        'policy'=>MAD4B_Unified_Date_Intent_V1::settings(is_singular('tours-and-activities')?(int)get_queried_object_id():0),
        'today'=>wp_date('Y-m-d'),
        'request'=>mad4b_unified_date_request_v1(),
        // Optional per-company Elementor search Popup. Disabled on unconfigured sites.
        'mobilePopupId'=>is_front_page()?max(0,(int)get_option('mad4b_unified_date_mobile_popup_id',0)):0,
        'context'=>is_front_page()?'home':(is_singular('tours-and-activities')?'single':'archive'),
    ];
    // One verified Tour / Program schedule, sourced from the ORIGINAL owner.
    // Never infer a combined Cruise rate program from unrelated Tour weekdays.
    if (is_singular('tours-and-activities') &&
        class_exists('Shared_Product_Program_Schedule',false) &&
        class_exists('MAD4B_Cruise_Program_Context_V1',false)) {
        $tourId=(int)get_queried_object_id();
        $cruise=MAD4B_Cruise_Program_Context_V1::resolve($tourId);
        $canonical=MAD4B_Cruise_Program_Context_V1::canonical($tourId);
        $scheduleTour=$canonical?:$tourId;
        $schedule=Shared_Product_Program_Schedule::schedule_decision($scheduleTour);
        $known=($schedule['status']??'')==='eligible' &&
            !empty($schedule['weekdays']);
        if(!empty($cruise['cruise']))
            $known=$known && ($cruise['selection_status']??'')==='selected' &&
              (int)($cruise['selected_program_id']??0)>0 &&
              count((array)($cruise['linked_program_ids']??[]))===1 &&
              empty($cruise['requires_supplier_confirmation']);
        if($known && class_exists('MAD4B_Signed_Schedule_Bridge_V1',false) &&
           MAD4B_Signed_Schedule_Bridge_V1::policy_mode()==='verified_only')
            $data['operatingSchedule']=[
                'status'=>'verified_weekday_source',
                'weekdays'=>array_values(array_map('intval',$schedule['weekdays'])),
                'include'=>array_values((array)$schedule['exceptions']['include']),
                'exclude'=>array_values((array)$schedule['exceptions']['exclude']),
                'programId'=>(int)($cruise['selected_program_id']??0),
                'bookingConfirmed'=>false,
            ];
    }
    if (is_singular('tours-and-activities') && ($data['request']['status']??'')==='valid') {
        $tourId=get_queried_object_id();
        $data['rateEligibleIds']=mad4b_unified_tour_rates_for_date_v1((int)$tourId,$data['request']['intent']);
        $data['rateContext']='date_window_coverage_only';
    }
    // Never include raw request values, checkout data or secrets in this public config.
    if (($data['request']['status']??'')==='valid') {
        $safe=$data['request']['intent'];
        $data['request']=['status'=>'valid','mode'=>$safe['mode'],'from'=>$safe['date_from'],'to'=>$safe['date_to']];
    }
    wp_add_inline_script('mad4b-unified-date-v1','window.MAD4BUnifiedDateV1='.wp_json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
}, 60);
