<?php
/**
 * Shared Product Core inventory signal v1 — READ-ONLY validated date-scoped snapshot.
 * Only a trusted server-side provider may register a callback for
 * mad4b/shared_inventory/snapshot_v1. No request/Cookie/browser stock values.
 * Never creates an inventory lock, booking, order, supplier request or cart.
 */
defined('ABSPATH') || exit;
final class MAD4B_Shared_Inventory_Signal_V1 {
    public const CONTRACT='mad4b.inventory-snapshot.v1';
    public const PROVIDER_HOOK='mad4b/shared_inventory/snapshot_v1';
    public const MAX_AGE_SECONDS=900;
    private static function rejected(string $reason): array {
        return ['status'=>'unknown','reason'=>$reason,'authoritative_for_booking'=>false,
            'eligible_for_dynamic_pricing'=>false,'checkout_ready'=>false];
    }
    private static function integer($value,int $max=1000000): ?int {
        if(!is_int($value)&&!(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D',$value)))return null;
        $v=filter_var($value,FILTER_VALIDATE_INT);
        return $v!==false&&$v>=0&&$v<=$max?(int)$v:null;
    }
    private static function stamp($value): ?int {
        if(!is_string($value)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|\+00:00)$/D',$value))return null;
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$value);
        if(!$parsed && str_ends_with($value,'Z'))$parsed=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
        return $parsed?$parsed->getTimestamp():null;
    }
    /**
     * Required snapshot:
     * contract; product_id; rate_id; program_term_id; travel_date; capacity; sold; held;
     * source_id; source_revision; resource_key; as_of_utc; expires_at_utc; status='current'.
     * Capacity is sellable allotment, not physical seats or a tour's group size tier.
     */
    public static function snapshot(array $config): array {
        $tour=(int)($config['tour_id']??0);
        $rate=(int)($config['rate_id']??0);
        $date=$config['travel_date']??null;
        $program=(int)($config['selections']['program_term_id']??$config['program_term_id']??0);
        if($tour<1 || get_post_type($tour)!=='tours-and-activities'
           || get_post_status($tour)!=='publish'
           || !$date || !class_exists('MAD4B_Shared_Last_Minute_V1')
           || !MAD4B_Shared_Last_Minute_V1::parse($date))
            return self::rejected('invalid_product_or_date');
        if($rate!==0 && (get_post_type($rate)!=='tour-rates'
            || get_post_status($rate)!=='publish'
            || (int)get_post_meta($rate,'related_tour_id',true)!==$tour))
            return self::rejected('rate_not_bound_to_product');
        $qty=self::integer($config['quantities']['persons']??1,1000);
        if($qty===null||$qty<1)return self::rejected('quantity_invalid');
        $context=['contract'=>self::CONTRACT,'product_id'=>$tour,'rate_id'=>$rate,
            'program_term_id'=>$program,'travel_date'=>$date,'requested_quantity'=>$qty,
            'read_only'=>true];
        try{$raw=apply_filters(self::PROVIDER_HOOK,null,$context);}
        catch(Throwable $error){return self::rejected('provider_failed');}
        if($raw===null)return self::rejected('no_date_scoped_provider');
        if(!is_array($raw)||($raw['contract']??null)!==self::CONTRACT
            || ($raw['status']??null)!=='current'
            || !is_int($raw['product_id']??null) || $raw['product_id']!==$tour
            || !is_int($raw['rate_id']??null) || $raw['rate_id']!==$rate
            || !is_int($raw['program_term_id']??null) || $raw['program_term_id']!==$program
            || ($raw['travel_date']??null)!==$date)return self::rejected('snapshot_identity_mismatch');
        $capacity=self::integer($raw['capacity']??null);
        $sold=self::integer($raw['sold']??null);
        $held=self::integer($raw['held']??null);
        if($capacity===null||$sold===null||$held===null||$capacity===0
            ||$sold+$held>$capacity)return self::rejected('snapshot_quantity_invalid');
        $source=$raw['source_id']??null;$revision=$raw['source_revision']??null;
        $resource=$raw['resource_key']??null;
        if(!is_string($source)||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$source)
           || !is_string($revision)||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$revision)
           || !is_string($resource)||!preg_match('/^[A-Za-z0-9._:-]{3,150}$/D',$resource))
            return self::rejected('snapshot_provenance_missing');
        $observed=self::stamp($raw['as_of_utc']??null);
        $expires=self::stamp($raw['expires_at_utc']??null);
        $now=time();
        if(!$observed||!$expires||$observed>$now+30||$observed<$now-self::MAX_AGE_SECONDS
            ||$expires<=$now||$expires-$observed>self::MAX_AGE_SECONDS)
            return self::rejected('snapshot_stale_or_expired');
        // Explicit stop sale overrides every revenue optimizer.
        if(!empty($raw['stop_sale']))return self::rejected('snapshot_stop_sale');
        $remaining=$capacity-$sold-$held;
        if($remaining<$qty) return [
            'status'=>'sold_out','reason'=>'insufficient_unheld_allotment',
            'remaining'=>$remaining,'requested_quantity'=>$qty,
            'eligible_for_dynamic_pricing'=>false,'authoritative_for_booking'=>false,
            'checkout_ready'=>false];
        $ratio=round($remaining*100/$capacity,4);
        return ['contract'=>self::CONTRACT,'status'=>'current',
            'resource_key'=>$resource,'source_id'=>$source,'source_revision'=>$revision,
            'snapshot_sha256'=>hash('sha256',wp_json_encode([$source,$revision,$resource,$tour,$rate,$program,$date,
                $capacity,$sold,$held,$observed,$expires])),
            'product_id'=>$tour,'rate_id'=>$rate,'program_term_id'=>$program,
            'travel_date'=>$date,'capacity'=>$capacity,'sold'=>$sold,'held'=>$held,
            'available'=>$remaining,'requested_quantity'=>$qty,'available_percent'=>$ratio,
            'as_of_utc'=>gmdate('c',$observed),'expires_at_utc'=>gmdate('c',$expires),
            'eligible_for_dynamic_pricing'=>true,'authoritative_for_booking'=>false,
            'checkout_ready'=>false];
    }
}
function shared_product_inventory_snapshot_v1(array $config): array {
    return MAD4B_Shared_Inventory_Signal_V1::snapshot($config);
}
