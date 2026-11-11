<?php
/** Plugin Name: MAD4B Shared Travel Date Presentation / Search / JSF Archive */
defined('ABSPATH') || exit;
if (!((defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED===true)
    ||(defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')&&MAD4B_PRESENTATION_RUNTIME_ENABLED===true)))return;

function mad4b_shared_travel_date_parse_v1($raw): ?string {
    if (!is_scalar($raw))return null;
    $raw=trim((string)$raw);if(strlen($raw)>90)return null;
    if(preg_match('/^\d{4}\.\d{1,2}\.\d{1,2}$/D',$raw)) {
        [$y,$m,$d]=array_map('intval',explode('.',$raw));
        $raw=sprintf('%04d-%02d-%02d',$y,$m,$d);
    }
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$raw))return null;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,wp_timezone());
    return $d&&$d->format('Y-m-d')===$raw?$raw:null;
}
function mad4b_shared_travel_date_window_v1($raw): ?array {
    if(!is_scalar($raw))return null;
    $text=(string)$raw;if(strlen($text)>180)return null;
    if(preg_match('/^(\d{4}\.\d{1,2}\.\d{1,2})(?:-(\d{4}\.\d{1,2}\.\d{1,2}))?$/D',$text,$m))
        $range=[$m[1],$m[2]??$m[1]];
    elseif(preg_match('/^(\d{4}-\d{2}-\d{2})$/D',$text))
        $range=[$text,$text];
    else return null;
    $from=mad4b_shared_travel_date_parse_v1($range[0]);
    $to=mad4b_shared_travel_date_parse_v1($range[1]);
    $today=current_time('Y-m-d');
    if(!$from||!$to||$from>$to||$from<$today)return null;
    $a=new DateTimeImmutable($from,wp_timezone());$b=new DateTimeImmutable($to,wp_timezone());
    if($a->diff($b)->days>30)return null;
    return ['from'=>$from,'to'=>$to];
}
function mad4b_shared_travel_date_request_v1(): ?array {
    $key='_meta_query_advanced_date__any__mad4b_tour_date_start__mad4b_tour_date_end|date';
    if(wp_doing_ajax()){
        if(($_POST['action']??'')!=='jet_smart_filters'
            ||($_POST['provider']??'')!=='jet-engine/tours_query_archive')return null;
        if(function_exists('shared_presentation_catalog_jsf_request_v1')
            && !shared_presentation_catalog_jsf_request_v1())return null;
        $data=$_POST['query']??null;
        if(!is_array($data)||!array_key_exists($key,$data))return null;
        return mad4b_shared_travel_date_window_v1(wp_unslash($data[$key]));
    }
    if(is_admin()||!preg_match('~^/(?:ar/|fr/|de/|es/|it/)?tours-and-activities(?:/|$)~D',
        (string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)))return null;
    if(isset($_GET['travel_date']))return mad4b_shared_travel_date_window_v1(wp_unslash($_GET['travel_date']));
    if(isset($_GET[$key]))return mad4b_shared_travel_date_window_v1(wp_unslash($_GET[$key]));
    return null;
}
/** Whitelist only this synthetic JSF date range; do not remove other native facet clauses. */
function mad4b_shared_date_strip_native_meta_v1(array $clauses): array {
    foreach($clauses as $key=>$row){
        if(!is_array($row))continue;
        if(isset($row['key'])&&in_array($row['key'],[
            'advanced_date__any__mad4b_tour_date_start__mad4b_tour_date_end',
            'mad4b_tour_date_start','mad4b_tour_date_end'],true)) {
            unset($clauses[$key]);continue;
        }
        if(!isset($row['key'])){
            $children=array_values(array_filter($row,'is_array'));
            if(strtoupper((string)($row['relation']??''))==='OR'&&count($children)===2){
                $keys=array_column($children,'key');
                if(count(array_intersect($keys,['mad4b_tour_date_start','mad4b_tour_date_end']))===2){
                    unset($clauses[$key]);continue;
                }
            }
            $clauses[$key]=mad4b_shared_date_strip_native_meta_v1($row);
        }
    }
    return $clauses;
}
/** Date coverage is a display facet, NOT confirmation of supplier inventory or a booking. */
function mad4b_shared_tours_covering_window_v1(array $window, string $language): array {
    global $wpdb;
    $from=(new DateTimeImmutable($window['from'],new DateTimeZone('UTC')))->getTimestamp();
    $to=(new DateTimeImmutable($window['to'],new DateTimeZone('UTC')))->getTimestamp()+DAY_IN_SECONDS-1;
    $ids=$wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT a.post_id FROM {$wpdb->postmeta} a
         INNER JOIN {$wpdb->postmeta} b ON b.post_id=a.post_id AND b.meta_key='tour_rate_end_date'
         INNER JOIN {$wpdb->posts} p ON p.ID=a.post_id
         WHERE a.meta_key='tour_rate_start_date' AND p.post_type='tour-rates' AND p.post_status='publish'
         AND CAST(a.meta_value AS UNSIGNED)<=%d AND CAST(b.meta_value AS UNSIGNED)>=%d
         LIMIT 5001", $to,$from));
    if(count($ids)>5000)return [0];
    $allowed=[];
    foreach($ids as $rateId){
        $rateId=(int)$rateId;
        if(in_array(strtolower((string)get_post_meta($rateId,'stop_sale',true)),['1','yes','true','on'],true)
          ||(string)get_post_meta($rateId,'avilabile_status',true)==='0')continue;
        $tour=absint(get_post_meta($rateId,'related_tour_id',true));
        if(!$tour||get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')continue;
        if(function_exists('shared_product_cruise_rate_program_valid_v1')
          &&!shared_product_cruise_rate_program_valid_v1($tour,$rateId))continue;
        $translated=$language!=='' ? (int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$language) : $tour;
        if($translated && get_post_status($translated)==='publish')$allowed[$translated]=$translated;
    }
    // Direct tour candidates use their native source fields. No Rate is invented.
    // Reject missing / stop-sold direct sources and do not claim confirmed capacity.
    $direct=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='tours-and-activities' AND post_status='publish' LIMIT 1201");
    if(count($direct)<=1200){
        foreach($direct as $tourId) {
            $tourId=(int)$tourId;
            if($language!==''){
                $details=apply_filters('wpml_post_language_details',null,$tourId);
                if(is_array($details) && ($details['language_code']??'')!==$language)continue;
            }
            if(isset($allowed[$tourId]) || in_array(strtolower((string)get_post_meta($tourId,'stop_sale',true)),['1','yes','true','on'],true))continue;
            // Only direct-source Tours with no published valid rate for the date.
            $price=get_post_meta($tourId,'start_price',true);
            $currency=get_post_meta($tourId,'tour_base_currency',true);
            $unit=get_post_meta($tourId,'tour_price_unit',true);
            if(!is_numeric($price)||(float)$price<=0||!preg_match('/^[A-Z]{3}$/D',(string)$currency)
                ||!in_array($unit,['per_person','per_trip','per_day','per_room','per_vehicle','per_cabin','table'],true))continue;
            $allowed[$tourId]=$tourId;
        }
    }
    return $allowed?array_values($allowed):[0];
}
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
    if(!is_array($args)||(int)($query->id??0)!==5||
        (string)($query->query_id??'')!=='tours_query_archive')return $args;
    $window=mad4b_shared_travel_date_request_v1();
    if(!$window){
        // Invalid explicit date must never turn into an unfiltered result.
        if(!wp_doing_ajax()&&isset($_GET['travel_date']))$args['post__in']=[0];
        return $args;
    }
    $context=wp_doing_ajax() && function_exists('shared_presentation_catalog_jsf_request_v1')
       ? shared_presentation_catalog_jsf_request_v1():null;
    $lang=is_array($context)?(string)($context['language']??''):
       (string)apply_filters('wpml_current_language',null);
    $qualified=mad4b_shared_tours_covering_window_v1($window,$lang);
    $existing=array_values(array_filter(array_map('absint',(array)($args['post__in']??[]))));
    if($existing) $qualified=array_values(array_intersect($qualified,$existing));
    if(is_array($args['meta_query']??null))
       $args['meta_query']=mad4b_shared_date_strip_native_meta_v1($args['meta_query']);
    $args['post__in']=$qualified?:[0];
    $args['ignore_sticky_posts']=true;
    return $args;
},75,2);
add_action('elementor/element/jet-ajax-search/section_search_form_settings/before_section_end',static function($element) {
    if(!class_exists('Elementor\Controls_Manager'))return;
    $element->add_control('mad4b_travel_date_enabled',[
        'label'=>__('Enable travel date','mad4b-commerce'),
        'type'=>\Elementor\Controls_Manager::SWITCHER,'label_on'=>'Yes','label_off'=>'No',
        'return_value'=>'yes','default'=>'',
        'description'=>'Share the selected travel date with Tour Search, Archive Filters and booking enquiry.'
    ]);
},50);
add_action('elementor/frontend/widget/before_render',static function($element) {
    if(!is_object($element)||$element->get_name()!=='jet-ajax-search')return;
    $settings=$element->get_settings_for_display();
    if(($settings['mad4b_travel_date_enabled']??'')==='yes')
        $element->add_render_attribute('_wrapper','data-mad4b-travel-date-search','1');
},20);
add_action('wp_enqueue_scripts',static function(){
    if(is_admin())return;
    // Unified picker owns the visible date state. Keep the legacy server-side
    // travel_date facet handler for backwards-compatible URLs only.
    if(function_exists('mad4b_unified_date_enabled_v1') && mad4b_unified_date_enabled_v1())return;
    $js=WPMU_PLUGIN_DIR.'/assets/mad4b-shared-travel-date-v1.js';
    $css=WPMU_PLUGIN_DIR.'/assets/mad4b-shared-travel-date-v1.css';
    if(is_readable($js))wp_enqueue_script('mad4b-shared-travel-date-v1',
        content_url('mu-plugins/assets/mad4b-shared-travel-date-v1.js'),[],filemtime($js),true);
    if(is_readable($css))wp_enqueue_style('mad4b-shared-travel-date-v1',
        content_url('mu-plugins/assets/mad4b-shared-travel-date-v1.css'),[],filemtime($css));
},35);
