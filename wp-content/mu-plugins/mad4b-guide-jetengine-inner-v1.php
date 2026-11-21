<?php
/**
 * Plugin Name: MAD4B Guide JetEngine Inner Repeater
 * Description: Adds Guide Service draft controls to the real JetEngine Tour and Program itinerary repeater schema.
 * Version: 1.0.0
 *
 * Important: these nested values are suggestions only. The auditable
 * _mad4b_guide_day_coverage_v1 sidecar is the sole approved cost input.
 */
defined('ABSPATH') || exit;

final class MAD4B_Guide_JetEngine_Inner_V1 {
    const FIELD='mad4b_guide_service_draft';
    const STATES=['unknown','full_day','half_day','none','optional'];

    /**
     * JetEngine CPT and taxonomy managers call this hook on meta field
     * registration. Injection is non-destructive and does not mutate the
     * JetEngine registry options or the existing itinerary rows.
     *
     * Only the precise two registered repeater schemas qualify.
     */
    public static function fields($fields,$manager=null) {
        if (!is_array($fields))return $fields;
        foreach ($fields as &$outer) {
            if (!is_array($outer)||($outer['type']??'')!=='repeater'||
                !is_array($outer['repeater-fields']??null))continue;
            $name=$outer['name']??'';
            if(!in_array($name,['itinerary_item','program_itinerary'],true))continue;
            $nested=array_column($outer['repeater-fields'],'name');
            if(!in_array('title',$nested,true)||!in_array('description',$nested,true))continue;
            if(in_array(self::FIELD,$nested,true))continue;
            $outer['repeater-fields'][]=[
                'title'=>'Guide Service — draft for review',
                'name'=>self::FIELD,
                'type'=>'select',
                'options_source'=>'manual',
                'default_val'=>'unknown',
                'id'=>$name==='itinerary_item'?907613:907614,
                'width'=>'100%',
                'description'=>'Internal draft suggestion. Final pricing certification requires review in MAD4B Guide Days Bulk Review; this field does not change booking totals.',
                'options'=>[
                    ['key'=>'unknown','value'=>'Not Reviewed — no price inference'],
                    ['key'=>'full_day','value'=>'Full Guided Day — review pending'],
                    ['key'=>'half_day','value'=>'Half Guided Day — factor review pending'],
                    ['key'=>'none','value'=>'No Guide — review pending'],
                    ['key'=>'optional','value'=>'Optional Guide — not included in base'],
                ],
            ];
        }
        unset($outer);
        return $fields;
    }

    /** Read only; drafts never make the internal cost quote checkout-ready. */
    public static function draftForSource(array $source): array {
        $raw=($source['source_kind']??'')==='term'
            ?get_term_meta((int)$source['source_id'],'program_itinerary',true)
            :get_post_meta((int)$source['source_id'],'itinerary_item',true);
        if(!is_array($raw))return [];
        $statuses=[];
        if(in_array(($source['day_unit_basis']??''),
            ['explicit_one_day_aggregated_stages','explicit_single_day_duration_taxonomy'],true)) {
            // Activities can contain many hours/stops in ONE chargeable
            // guide day, even when JetEngine presents an inner field on
            // each stop. Never multiply the cost by stage count.
            $selected=[];
            foreach($raw as $row) {
                if(!is_array($row))continue;
                $status=$row[self::FIELD]??'unknown';
                if(is_string($status)&&in_array($status,self::STATES,true)
                    &&$status!=='unknown')$selected[$status]=true;
            }
            if(count($selected)>1)return ['day-1'=>'conflict'];
            return ['day-1'=>$selected?array_key_first($selected):'unknown'];
        }
        foreach((array)($source['days']??[]) as $day=>$meta) {
            if(!isset($raw[$day])||!is_array($raw[$day]))continue;
            $value=$raw[$day][self::FIELD]??'unknown';
            if(!is_string($value)||!in_array($value,self::STATES,true))continue;
            $statuses[$day]=$value;
        }
        return $statuses;
    }
}
add_filter('jet-engine/meta-boxes/raw-fields',
 [MAD4B_Guide_JetEngine_Inner_V1::class,'fields'],45,2);
