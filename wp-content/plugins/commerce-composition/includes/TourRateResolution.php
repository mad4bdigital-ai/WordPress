<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Read-only eligible-rate resolution. Never chooses the cheapest/first rate. */
final class TourRateResolution
{
    public static function inspect(int $tourId,string $date,?int $selectedRate=null):array|\WP_Error
    {
        if(!current_user_can('manage_options')||
           !current_user_can('manage_woocommerce'))
            return new \WP_Error('rate_resolution_operator_required');
        if($tourId<1)return new \WP_Error('rate_resolution_tour_required');
        if($selectedRate!==null&&$selectedRate<1)
            return new \WP_Error('rate_resolution_selected_rate_invalid');

        $candidates=get_posts([
            'post_type'=>'tour-rates','post_status'=>'publish',
            'posts_per_page'=>101,'fields'=>'ids',
            'meta_key'=>'related_tour_id','meta_value'=>(string)$tourId,
            'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true
        ]);
        if(count($candidates)>100)return new \WP_Error('rate_resolution_candidate_limit');
        $eligible=[];$blocked=[];
        foreach($candidates as $id){
            $id=(int)$id;
            $decision=TourRateEligibility::inspect($tourId,$id,$date);
            if(is_wp_error($decision)){
                $blocked[$id]=[$decision->get_error_code()];
                continue;
            }
            if($decision['rate_selection_eligible'])$eligible[]=$id;
            else $blocked[$id]=$decision['blockers'];
        }
        $explicit=$selectedRate!==null;
        $selected=null;$status='no_eligible_rate';
        if($explicit){
            if(!in_array($selectedRate,$candidates,true))
                return new \WP_Error('rate_resolution_selected_not_for_tour');
            if(!in_array($selectedRate,$eligible,true))
                return new \WP_Error('rate_resolution_selected_not_eligible');
            $selected=$selectedRate;$status='explicit_eligible_rate';
        }elseif(count($eligible)===1){
            $selected=$eligible[0];$status='unique_eligible_rate';
        }elseif(count($eligible)>1)$status='explicit_rate_selection_required';

        return [
            'tour_id'=>$tourId,'travel_date'=>$date,
            'selection_source'=>$explicit?'original_saved_request':'none',
            'selected_rate_id'=>$selected,
            'candidate_rate_ids'=>array_values(array_map('intval',$candidates)),
            'eligible_rate_ids'=>$eligible,
            'blocked_rate_reasons'=>$blocked,
            'status'=>$status,
            'selection_unambiguous'=>$selected!==null,
            'pricing_quote_certified'=>false,
            'order_item_creation_authorized'=>false,
            'supplier_booking_authorized'=>false
        ];
    }
}
