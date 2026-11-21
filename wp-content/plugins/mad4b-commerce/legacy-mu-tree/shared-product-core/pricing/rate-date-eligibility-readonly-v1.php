<?php
/**
 * Effective Tour Rate date window.
 * Explicit complete period wins; missing complete period inherits EXACTLY ONE
 * assigned native Season term. No date fields are persisted or overwritten.
 */
if (!defined('ABSPATH')) return;
function shared_product_booking_v2_rate_iso($value):?string {
 if(!is_scalar($value)||is_bool($value))return null;
 $raw=trim((string)$value);
 if(ctype_digit($raw)&&(int)$raw>0&&(int)$raw<4102444800)
  return gmdate('Y-m-d',(int)$raw);
 if(!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$raw))return null;
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('UTC'));
 return $d&&$d->format('Y-m-d')===$raw?$raw:null;
}
function shared_product_booking_v2_rate_effective_window($rate_id,$travel_date):array {
 $blocked=['eligible'=>false,'source'=>'none','reason'=>'unresolved',
  'start'=>null,'end'=>null,'season_term_id'=>null];
 $rate_id=absint($rate_id);
 if(!$rate_id||get_post_type($rate_id)!=='tour-rates')
  return array_merge($blocked,['reason'=>'invalid_tour_rate']);
 $travel=shared_product_booking_v2_rate_iso($travel_date);
 if(!$travel||$travel!==$travel_date)
  return array_merge($blocked,['reason'=>'invalid_travel_date']);
 $rs=get_post_meta($rate_id,'tour_rate_start_date',true);
 $re=get_post_meta($rate_id,'tour_rate_end_date',true);
 $s=(is_scalar($rs)&&trim((string)$rs)!=='');
 $e=(is_scalar($re)&&trim((string)$re)!=='');
 if($s||$e){
  if(!$s||!$e)return array_merge($blocked,['source'=>'explicit','reason'=>'partial_custom_period']);
  $a=shared_product_booking_v2_rate_iso($rs);
  $b=shared_product_booking_v2_rate_iso($re);
  if(!$a||!$b||$a>$b)
   return array_merge($blocked,['source'=>'explicit','reason'=>'invalid_custom_period']);
  return ['eligible'=>$a<=$travel&&$travel<=$b,'source'=>'explicit',
   'reason'=>'custom_period','start'=>$a,'end'=>$b,'season_term_id'=>null];
 }
 // Never guess a season from the parent Tour, mixed WPML labels or multiple terms.
 $terms=wp_get_post_terms($rate_id,'season',['fields'=>'all']);
 if(is_wp_error($terms)||count((array)$terms)!==1||!($terms[0] instanceof WP_Term))
  return array_merge($blocked,['reason'=>'season_assignment_missing_or_ambiguous']);
 $term=$terms[0];
 if(!class_exists('MAD4B_JetEngine_Rate_Season_Term_Matcher_V1',false))
  return array_merge($blocked,['reason'=>'season_policy_reader_unavailable']);
 $matched=MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::window($term,$travel,$travel);
 if(!is_array($matched)||empty($matched['window_start'])||empty($matched['window_end']))
  return array_merge($blocked,['source'=>'term_meta','reason'=>'outside_enabled_season']);
 return ['eligible'=>true,'source'=>'season_term_meta_inherited',
  'reason'=>'single_assigned_enabled_season','start'=>$matched['window_start'],
  'end'=>$matched['window_end'],'season_term_id'=>(int)$term->term_id,
  'policy_term_id'=>(int)$matched['policy_term_id']];
}
/** Inclusive calendar-day eligibility, season inheritance only when dates absent. */
function shared_product_booking_v2_rate_covers_date($rate_id,$travel_date) {
 $effective=shared_product_booking_v2_rate_effective_window($rate_id,$travel_date);
 return !empty($effective['eligible']);
}
/**
 * A Rate is valid for a booking interval only when ONE effective native window
 * covers every day. Testing endpoints separately permits different winter
 * instances of one Season term with a summer gap between; that is unsafe.
 */
function shared_product_booking_v2_rate_covers_window($rate_id,$from,$to):bool {
 if(!is_string($from)||!is_string($to)||$from>$to||
    shared_product_booking_v2_rate_iso($to)!==$to)return false;
 $start=shared_product_booking_v2_rate_effective_window($rate_id,$from);
 return !empty($start['eligible'])&&is_string($start['start'])&&
        is_string($start['end'])&&$start['start']<=$from&&$start['end']>=$to;
}
