<?php
/** Read-only self-provider PDF operations readiness. No sale/stock inference. */
defined('ABSPATH')||exit;
final class MAD4B_Transport_Source_Operations_Readiness_V1 {
    public const CONTRACT='mad4b.transport.self-operations-readiness.v1';
    public const SHA='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
    public static function inspect(int $id,array $rateIds=[]):array {
        $source=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_sha256',true);
        $trusted=$id>0&&get_post_type($id)==='transportations'&&
            hash_equals(self::SHA,$source)&&
            get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)==='self';
        if(!$trusted)return [
            'contract'=>self::CONTRACT,'source_trusted'=>false,
            'booking_ready'=>false,'reason_codes'=>['not_trusted_self_pdf_source']
        ];
        $policy=class_exists('MAD4B_Transport_Self_Pricing_Controls_V1')?
            MAD4B_Transport_Self_Pricing_Controls_V1::current():[];
        $origin=(int)get_post_meta($id,'_mad4b_pdf_route_origin_term_id_v1',true);
        $dest=(int)get_post_meta($id,'_mad4b_pdf_route_destination_term_id_v1',true);
        $pickup=get_post_meta($id,'_mad4b_pdf_route_pickup_point_verified_v1',true)==='1';
        $dropoff=get_post_meta($id,'_mad4b_pdf_route_dropoff_point_verified_v1',true)==='1';
        $available=(string)get_post_meta($id,'trans_available_status',true);
        $cost=(string)($policy['currency']??'');
        $role=(string)($policy['price_role']??'reference_only');
        $priceValid=$cost!==''&&in_array($role,['self_cost','self_selling'],true);
        $rateApproved=false;$rateCount=0;
        foreach($rateIds as $rateId){
            $rateId=(int)$rateId;
            if(get_post_type($rateId)!=='transport-rates')continue;
            $rateCount++;
            if(get_post_status($rateId)==='publish'&&
                function_exists('BookingExperience\\transport_rate_approval_verified')&&
                BookingExperience\transport_rate_approval_verified($rateId))$rateApproved=true;
        }
        $reasons=[];
        if(!$origin)$reasons[]='origin_city_missing';
        if(!$dest)$reasons[]='destination_or_stage_details_need_confirmation';
        if(!$pickup)$reasons[]='pickup_point_unconfirmed';
        if(!$dropoff)$reasons[]='dropoff_point_unconfirmed';
        if($available!=='1')$reasons[]='supplier_date_availability_not_confirmed';
        if(!$priceValid)$reasons[]='pdf_currency_or_price_role_not_verified';
        if(!$rateApproved)$reasons[]='native_transport_rate_not_approved';
        $reasons[]='fleet_capacity_not_supplier_verified';
        return [
            'contract'=>self::CONTRACT,'provider'=>'self','source_trusted'=>true,
            'source_post_id'=>$id,
            'tour_component_opt_in'=>get_post_meta($id,
                'trans_use_in_tour_rate_generation',true)==='1',
            'origin_city_known'=>$origin>0,
            'destination_city_known'=>$dest>0,
            'pickup_point_verified'=>$pickup,'dropoff_point_verified'=>$dropoff,
            'supplier_availability'=>'on_request_unconfirmed',
            'source_price_role'=>$role,'source_currency'=>$cost,
            'source_price_certified'=>false,
            'rate_count'=>$rateCount,'approved_native_rate_present'=>$rateApproved,
            'capacity_supplier_confirmed'=>false,
            'form6_relationship'=>'traveler_request_context_not_supplier_point',
            'booking_ready'=>false,'checkout_authorized'=>false,
            'standalone_publication_required'=>false,
            'reason_codes'=>array_values(array_unique($reasons))
        ];
    }
    /** Batch source IDs and rate references; no N+1 post queries. */
    public static function map(array $postIds):array {
        $valid=[];
        foreach($postIds as $id){$id=(int)$id;if($id>0)$valid[$id]=true;}
        if(!$valid)return [];
        // Transport Rate may point to the canonical EN source even while the
        // admin catalog displays a different WPML language. Source key is
        // immutable per 2026 PDF operation, not locale-specific post ID.
        $byKey=[];
        foreach(array_keys($valid) as $postId){
            $key=(string)get_post_meta($postId,
                '_mad4b_transfer_pdf_2026_source_key',true);
            if($key!=='')$byKey[$key][]=$postId;
        }
        $refs=[];
        $rates=get_posts(['post_type'=>'transport-rates',
            'post_status'=>['publish','draft','pending'],
            'posts_per_page'=>1000,'fields'=>'ids','suppress_filters'=>true]);
        foreach($rates as $rate){
            $parent=(int)get_post_meta($rate,'source_post_id',true);
            $key=(string)get_post_meta($parent,
                '_mad4b_transfer_pdf_2026_source_key',true);
            foreach($byKey[$key]??[] as $localePost)
                $refs[$localePost][]=$rate;
        }
        $result=[];
        foreach(array_keys($valid) as $id)
            $result[$id]=self::inspect($id,$refs[$id]??[]);
        return $result;
    }
}
