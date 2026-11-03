<?php
/**
 * Native JetSmartFilters AJAX date-window projection for Tour Rate Query #310.
 * Intersects the original shared-price eligibility IDs; never creates a price
 * or makes an availability/inventory commitment.
 */
defined('ABSPATH') || exit;
if (!defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_presentation_tour_rate_window_v1($raw): ?array {
    if (!is_scalar($raw) || !preg_match(
        '/^([0-9]{4}\.[0-9]{1,2}\.[0-9]{1,2})-([0-9]{4}\.[0-9]{1,2}\.[0-9]{1,2})$/D',
        (string)$raw,$m
    )) return null;
    $result=[];
    foreach ([$m[1],$m[2]] as $s) {
        $date=DateTimeImmutable::createFromFormat('!Y.n.j',$s,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if (!$date || ($errors!==false&&($errors['warning_count']||$errors['error_count']))
           || $date->format('Y.n.j')!==$s) return null;
        $result[]=$date->getTimestamp();
    }
    if ($result[0]>$result[1] || ($result[1]-$result[0])>730*DAY_IN_SECONDS) return null;
    return ['from'=>$result[0],'to'=>$result[1]+DAY_IN_SECONDS-1];
}

add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
    if (!is_array($args) || (int)($query->id??0)!==310
       || ($query->query_id??'')!=='tour_rates'
       || !wp_doing_ajax() || ($_POST['action']??'')!=='jet_smart_filters'
       || ($_POST['provider']??'')!=='jet-engine/tour_rates_accordion') return $args;
    $params=$_POST['query']??null;
    if (!is_array($params)) return $args;
    $key='_meta_query_advanced_date__any__tour_rate_start_date__tour_rate_end_date|date';
    if (!array_key_exists($key,$params)) return $args;
    $window=shared_presentation_tour_rate_window_v1(wp_unslash($params[$key]));
    if (!$window) { $args['post__in']=[0];return $args; }
    $allowed=array_values(array_filter(array_map('absint',(array)($args['post__in']??[]))));
    if (!$allowed || $allowed===[0]) { $args['post__in']=[0];return $args; }
    $match=[];
    foreach($allowed as $rate){
        if (get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish')continue;
        $from=(int)get_post_meta($rate,'tour_rate_start_date',true);
        $to=(int)get_post_meta($rate,'tour_rate_end_date',true);
        if ($from<=0||$to<$from) continue;
        if ($from<=$window['to'] && $to>=$window['from'])$match[]=$rate;
    }
    $args['post__in']=$match?:[0];
    return $args;
},55,2);
