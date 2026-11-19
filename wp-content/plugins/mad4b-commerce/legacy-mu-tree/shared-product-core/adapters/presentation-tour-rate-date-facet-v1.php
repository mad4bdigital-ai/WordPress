<?php
/** Native Tour Rate date-window projection; keeps original price/parent eligibility. */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || MAD4B_COMMERCE_RUNTIME_ENABLED!==true)) return;

function shared_presentation_tour_rate_window_v1($raw): ?array {
    if (!is_scalar($raw) || !preg_match(
        '/^([0-9]{4}\.[0-9]{1,2}\.[0-9]{1,2})(?:-([0-9]{4}\.[0-9]{1,2}\.[0-9]{1,2}))?$/D',
        (string)$raw,$m
    )) return null;
    $result=[];
    foreach ([$m[1],$m[2]??$m[1]] as $s) {
        [$year,$month,$day]=array_map('intval',explode('.',$s));
        if(!checkdate($month,$day,$year))return null;
        $date=DateTimeImmutable::createFromFormat('!Y.n.j',sprintf('%04d.%d.%d',$year,$month,$day),new DateTimeZone('UTC'));
        if(!$date)return null;
        $result[]=$date->getTimestamp();
    }
    if ($result[0]>$result[1] || ($result[1]-$result[0])>730*DAY_IN_SECONDS) return null;
    return ['from'=>$result[0],'to'=>$result[1]+DAY_IN_SECONDS-1];
}
/** Remove only the native endpoint-BETWEEN projection of this exact date facet.
 * A Rate covering the whole chosen window has neither endpoint BETWEEN it;
 * the existing intended overlap rule is applied to eligible Rate IDs below.
 */
function shared_presentation_tour_rate_date_meta_v1(array $clauses,array $window): array {
    foreach($clauses as $key=>$row){
        if(!is_array($row))continue;
        $children=array_values(array_filter($row,'is_array'));
        $owned=false;
        if(strtoupper((string)($row['relation']??''))==='OR' && count($children)===2){
            $fields=[];$owned=true;
            foreach($children as $child){
                $values=$child['value']??null;
                if(!in_array($child['key']??'',['tour_rate_start_date','tour_rate_end_date'],true)
                    ||strtoupper((string)($child['compare']??''))!=='BETWEEN'
                    ||!is_array($values)||count($values)!==2
                    ||(int)$values[0]!==$window['from']||(int)$values[1]!==$window['to']){$owned=false;break;}
                $fields[]=$child['key'];
            }
            $owned=$owned && count(array_unique($fields))===2;
        }
        if($owned){unset($clauses[$key]);continue;}
        if(($row['key']??'')==='advanced_date__any__tour_rate_start_date__tour_rate_end_date'){
            $values=$row['value']??null;
            if(is_array($values)&&count($values)===2&&(int)$values[0]===$window['from']&&(int)$values[1]===$window['to']){
                unset($clauses[$key]);continue;
            }
        }
        if(!isset($row['key']))$clauses[$key]=shared_presentation_tour_rate_date_meta_v1($row,$window);
    }
    return $clauses;
}
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
    if(function_exists('shared_product_is_rate_facet_query') && shared_product_is_rate_facet_query($query))return $args;
    if(!is_array($args)||(int)($query->id??0)!==310||($query->query_id??'')!=='tour_rates')return $args;
    $providers=['jet-engine/tour_rates','jet-engine/tour_rates_accordion'];
    if(wp_doing_ajax()){
        if(($_POST['action']??'')!=='jet_smart_filters'||!in_array($_POST['provider']??'',$providers,true))return $args;
        $params=$_POST['query']??null;
    }else{
        if(is_admin()||!in_array($_REQUEST['jsf']??'',['jet-engine:tour_rates','jet-engine:tour_rates_accordion'],true))return $args;
        $params=$_REQUEST;
    }
    if(!is_array($params))return $args;
    $key='_meta_query_advanced_date__any__tour_rate_start_date__tour_rate_end_date|date';
    if(!array_key_exists($key,$params))return $args;
    $tour=function_exists('shared_product_verified_presentation_tour_id')
        ?shared_product_verified_presentation_tour_id():shared_product_rate_facet_tour_id();
    // JetSmartFilters' signed provider settings omit queried_id on its
    // native Elementor request. Our verified AJAX-language bridge has
    // already checked the vendor signature, original Tour permalink,
    // authored Listing, WPML parent and same-origin referrer.
    // Do not widen the query if this authority is absent.
    if(!$tour && wp_doing_ajax()
        &&class_exists('MAD4B_Rate_Season_Calendar_Integrity_V1',false))
        $tour=MAD4B_Rate_Season_Calendar_Integrity_V1::request_tour($args);
    $window=shared_presentation_tour_rate_window_v1(wp_unslash($params[$key]));
    if(!$tour||!$window){$args['post__in']=[0];return $args;}
    $allowed=array_values(array_filter(array_map('absint',(array)($args['post__in']??[]))));
    if(!$allowed||$allowed===[0]){$args['post__in']=[0];return $args;}
    $match=[];
    foreach($allowed as $rate){
        if(get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'
            ||(int)get_post_meta($rate,'related_tour_id',true)!==$tour)continue;
        $from=(int)get_post_meta($rate,'tour_rate_start_date',true);
        $to=(int)get_post_meta($rate,'tour_rate_end_date',true);
        if($from>0&&$to>=$from&&$from<=$window['to']&&$to>=$window['from'])$match[]=$rate;
    }
    if(is_array($args['meta_query']??null))$args['meta_query']=shared_presentation_tour_rate_date_meta_v1($args['meta_query'],$window);
    $args['post__in']=$match?:[0];
    return $args;
},55,2);
