<?php
/** Pure calendar-day eligibility for Extra Rate preview and booking validation. */
if(!defined('ABSPATH'))return;
/** Inclusive calendar-day Rate eligibility; dates are UTC timestamps in the existing CPT. */
function shared_product_booking_v2_rate_covers_date($rate_id,$travel_date) {
 if(!is_string($travel_date)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$travel_date))return false;
 $start=(int)get_post_meta($rate_id,'tour_rate_start_date',true);
 $end=(int)get_post_meta($rate_id,'tour_rate_end_date',true);
 if(!$start||!$end||$end<$start)return false;
 $day=strtotime($travel_date.' 12:00:00 UTC');
 if(!$day)return false;
 return gmdate('Y-m-d',$start)<=$travel_date && gmdate('Y-m-d',$end)>=$travel_date;
}
