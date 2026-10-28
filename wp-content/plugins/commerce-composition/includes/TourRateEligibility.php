<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Read-only preflight for a source Tour and published Tour Rate.
 * Eligibility is not a certified booking quotation or supplier hold.
 */
final class TourRateEligibility
{
    private static function date(string $value):?\DateTimeImmutable
    {
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value))return null;
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        return $day && $day->format('Y-m-d')===$value ? $day : null;
    }
    public static function withinWindow(string $date,int $start,int $end):bool
    {
        $day=self::date($date);
        if(!$day||$start<946684800||$end>4102444799||$start>$end)
            return false;
        // Stored JetEngine bounds are Unix seconds, sometimes ending 23:59:59.
        // Compare the whole requested calendar day with the stored interval.
        $from=$day->getTimestamp();
        return $end >= $from && $start < ($from+86400);
    }
    public static function withinSeason(string $date,int $termId):bool
    {
        $day=self::date($date);
        $term=get_term($termId,'season');
        if(!$day || !$term || is_wp_error($term) ||
            get_term_meta($termId,'season_enabled',true)!=='1')return false;
        $m1=(int)get_term_meta($termId,'season_start_month',true);
        $d1=(int)get_term_meta($termId,'season_start_day',true);
        $m2=(int)get_term_meta($termId,'season_end_month',true);
        $d2=(int)get_term_meta($termId,'season_end_day',true);
        $cross=get_term_meta($termId,'season_cross_year',true)==='1';
        // Validate month/day without normalizing impossible seasonal dates.
        if(!checkdate($m1,$d1,2000)||!checkdate($m2,$d2,2000))return false;
        $start=sprintf('%02d-%02d',$m1,$d1);
        $end=sprintf('%02d-%02d',$m2,$d2);
        if(($cross && $start<=$end)||(!$cross && $start>$end))return false;
        $monthDay=$day->format('m-d');
        return $cross ? ($monthDay>=$start||$monthDay<=$end)
                      : ($monthDay>=$start&&$monthDay<=$end);
    }
    public static function inspect(int $tourId,int $rateId,?string $travelDate):array|\WP_Error
    {
        if(!current_user_can('manage_options')||
           !current_user_can('manage_woocommerce'))
            return new \WP_Error('rate_operator_required');
        $service=ServiceProductIdentity::inspect($tourId);
        if(is_wp_error($service))return $service;
        if($service['source_type']!=='tours-and-activities')
            return new \WP_Error('rate_source_not_tour');

        $rate=get_post($rateId);
        if(!$rate||$rate->post_type!=='tour-rates'||$rate->post_status!=='publish')
            return new \WP_Error('rate_not_published');
        if((int)get_post_meta($rateId,'related_tour_id',true)!==$tourId||
           (int)get_post_meta($rateId,'_etg_last_synced_tour_id',true)!==$tourId)
            return new \WP_Error('rate_tour_relation_mismatch');
        $start=get_post_meta($rateId,'tour_rate_start_date',true);
        $end=get_post_meta($rateId,'tour_rate_end_date',true);
        if(!is_scalar($start)||!is_scalar($end)||
           !ctype_digit((string)$start)||!ctype_digit((string)$end)||
           (int)$start<946684800||(int)$end>4102444799||
           (int)$start>(int)$end)
            return new \WP_Error('rate_window_invalid');
        $season=wp_get_object_terms($rateId,'season',['fields'=>'ids']);
        if(is_wp_error($season)||count($season)!==1)
            return new \WP_Error('rate_season_not_unique');
        $seasonId=(int)$season[0];
        $term=get_term($seasonId,'season');
        if(!$term||is_wp_error($term)||
           get_term_meta($seasonId,'season_enabled',true)!=='1')
            return new \WP_Error('rate_season_not_enabled');
        $currency=get_post_meta($rateId,'base_currency',true);
        if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
            return new \WP_Error('rate_currency_invalid');
        $blocked=[];
        if(get_post_meta($rateId,'_booking_experience_qa_pricing_fixture',true)!=='')
            $blocked[]='qa_fixture_not_for_sale';
        if(in_array(get_post_meta($rateId,'stop_sale',true),['1',1,true],true))
            $blocked[]='stop_sale';
        if(in_array(get_post_meta($rateId,'avilabile_status',true),['0',0,false],true))
            $blocked[]='availability_disabled';
        if($travelDate===null) {
            $blocked[]='travel_date_required';
        } else {
            if(!self::date($travelDate))
                return new \WP_Error('rate_travel_date_invalid');
            if(!self::withinWindow($travelDate,(int)$start,(int)$end))
                $blocked[]='outside_rate_window';
            if(!self::withinSeason($travelDate,$seasonId))
                $blocked[]='outside_season_window';
        }
        return [
            'tour_id'=>$tourId,
            'woocommerce_product_id'=>$service['woocommerce_product_id'],
            'rate_id'=>$rateId,
            // The local JetEngine runtime has no active Tour -> Rate
            // relation object. Validate both source mirror meta fields;
            // commercial approval remains a separate pricing authority.
            'relationship_evidence'=>'related_tour_id+_etg_last_synced_tour_id',
            'relationship_commercially_certified'=>false,
            'currency'=>$currency,
            'season_id'=>$seasonId,
            'season_slug'=>$term->slug,
            'period_start_utc'=>gmdate('Y-m-d',(int)$start),
            'period_end_utc'=>gmdate('Y-m-d',(int)$end),
            'travel_date'=>$travelDate,
            'rate_selection_eligible'=>count($blocked)===0,
            'blockers'=>$blocked,
            'price_approved'=>false,
            'quote_approved'=>false,
            'order_item_creation_authorized'=>false,
            'supplier_booking_authorized'=>false
        ];
    }
}
