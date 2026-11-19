<?php
/**
 * Transportation content and provenance audit.
 * Conservative: raises review flags; never changes content, photos or prices.
 */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Enrichment_Audit_V1 {
    public const CONTRACT='mad4b.transport-enrichment-audit.v1';
    private const ENR_REFERENCE=array(
        '2030'=>array('departure'=>'19:00','origin'=>'Cairo','source'=>'https://www.cairo.gov.eg/ar/news/2024/73a29d003b314643b6fbf05762fc81de',
            'note'=>'historical_public_reference_not_live_booking'),
        '2031'=>array('departure'=>'19:00','origin'=>'Aswan','source'=>'https://www.cairo.gov.eg/ar/news/2024/73a29d003b314643b6fbf05762fc81de',
            'note'=>'historical_public_reference_not_live_booking'),
    );
    public static function snapshot(): array {
        $out=array('contract'=>self::CONTRACT,'site'=>home_url('/'),
            'published_transport_count'=>0,'qa_drafts_excluded'=>0,
            'published_missing_featured'=>0,'media_quarantined'=>0,
            'published_galleries'=>0,'missing_media_provenance'=>0,
            'rail_schedule_review'=>0,'geography'=>array(),
            'items'=>array(),'review_only'=>true,'mutation_performed'=>false,
            'checkout_authorized'=>false);
        $ids=get_posts(array('post_type'=>'transportations','post_status'=>'publish',
            'posts_per_page'=>500,'fields'=>'ids','suppress_filters'=>true));
        $out['published_transport_count']=count($ids);
        $qa=get_posts(array('post_type'=>'transportations','post_status'=>'draft',
            'posts_per_page'=>500,'fields'=>'ids','suppress_filters'=>true));
        foreach ($qa as $id) if (get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)
          || stripos(get_the_title($id),'QA ONLY')!==false) $out['qa_drafts_excluded']++;
        foreach ($ids as $id) {
            $id=(int)$id;
            $mode=(int)get_post_meta($id,'trans_mode',true);
            $type=(int)get_post_meta($id,'trans_type',true);
            $title=get_the_title($id);
            $issues=array();
            $thumb=(int)get_post_thumbnail_id($id);
            if (!$thumb) {$issues[]='featured_missing_or_quarantined';$out['published_missing_featured']++;}
            if (get_post_meta($id,'_mad4b_transport_media_quarantine_v1',true)==='known_mismatched_media') {
                $issues[]='mismatched_gallery_quarantined';$out['media_quarantined']++;
            }
            $gallery=(string)get_post_meta($id,'trans_gellery',true);
            if ($gallery!=='') {
                $out['published_galleries']++;
                if (preg_match('/(?:vip(?:-\d)?\.webp|2372123_0\.jpg)/i',$gallery)
                     && ($type===2419 || $type===579)) $issues[]='gallery_rail_class_mismatch';
            }
            if ($thumb && !get_post_meta($thumb,'_mad4b_transport_media_license',true)) {
                $out['missing_media_provenance']++;
                $issues[]='featured_license_unverified';
            }
            if ($mode===568) {
                if (get_post_meta($id,'trans_no_passengers',true)==='')
                    $issues[]='vehicle_capacity_missing';
                if (!$gallery && !$thumb) $issues[]='vehicle_real_photo_required';
            }
            if ($mode===578) {
                $number=(string)get_post_meta($id,'trans_train_number',true);
                $departure=(string)get_post_meta($id,'trans_departure_time',true);
                $arrival=(string)get_post_meta($id,'trans_arrival_time',true);
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$departure) ||
                    !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$arrival))
                    $issues[]='train_schedule_time_missing_or_invalid';
                $source=self::ENR_REFERENCE[$number]??null;
                if ($source && $departure!==$source['departure']) {
                    $issues[]='train_departure_differs_from_public_reference';
                }
                // Clock-only arrival time cannot state overnight travel unambiguously.
                if ($arrival && $arrival<$departure && !get_post_meta($id,'trans_arrival_day_offset',true))
                    $issues[]='arrival_day_offset_unspecified';
                if (in_array('train_departure_differs_from_public_reference',$issues,true)
                    || in_array('train_schedule_time_missing_or_invalid',$issues,true)
                    || in_array('arrival_day_offset_unspecified',$issues,true))
                    $out['rail_schedule_review']++;
            }
            $out['items'][]=array('id'=>$id,'title'=>$title,'status'=>get_post_status($id),
                'mode_id'=>$mode,'type_id'=>$type,
                'featured_attachment'=>$thumb?:null,
                'issues'=>array_values(array_unique($issues)));
        }
        $stations=get_posts(array('post_type'=>'stations','post_status'=>'publish',
            'posts_per_page'=>500,'fields'=>'ids','suppress_filters'=>true));
        $locs=array();$valid=0;$bad=array();
        foreach ($stations as $id) {
            $loc=trim((string)get_post_meta($id,'station_map_location',true));
            if (!preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/D',$loc,$m) ||
                (float)$m[1]<22 || (float)$m[1]>32.8 ||
                (float)$m[2]<24 || (float)$m[2]>36.5) {
                $bad[]=$id;continue;
            }
            ++$valid;$locs[trim($m[1]).','.trim($m[2])]=true;
        }
        $out['geography']=array('station_records'=>count($stations),'valid_coordinate_format'=>$valid,
            'distinct_coordinate_pairs'=>count($locs),'invalid_station_ids'=>$bad,
            'transport_with_direct_map'=>count(array_filter($ids,static fn($id)=>
                (string)get_post_meta($id,'trans_location',true)!=='')));
        return $out;
    }
    public static function rest(): void {
        register_rest_route('mad4b-pricing/v1','/transport-enrichment-audit',array(
            'methods'=>'GET','callback'=>static fn()=>rest_ensure_response(self::snapshot()),
            'permission_callback'=>array('MAD4B_Transport_Pricing_Readiness_V1','permitted')));
    }
    public static function admin(): void {
        if (!MAD4B_Transport_Pricing_Readiness_V1::permitted()) return;
        $d=self::snapshot();
        echo '<h2>Transportation Media / Route / Schedule Audit</h2>';
        echo '<p>Published: '.esc_html((string)$d['published_transport_count']).
            ' | Missing featured image: '.esc_html((string)$d['published_missing_featured']).
            ' | Quarantined media: '.esc_html((string)$d['media_quarantined']).
            ' | Railway schedules for review: '.esc_html((string)$d['rail_schedule_review']).'</p>';
        echo '<p>Stations: '.esc_html((string)$d['geography']['station_records']).
            ' | Valid coordinate pairs: '.esc_html((string)$d['geography']['valid_coordinate_format']).
            ' | Unique locations: '.esc_html((string)$d['geography']['distinct_coordinate_pairs']).
            '. Schedule differences use historical public references only, not live ENR confirmation.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Transportation</th><th>Media / Schedule Issues</th></tr></thead><tbody>';
        foreach ($d['items'] as $item) {
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($item['id'])).
                '">'.esc_html($item['title']).'</a></td><td>'.
                esc_html(implode(', ',$item['issues'])).'</td></tr>';
        }
        echo '</tbody></table><p>Audit is read-only; no time, source, price, route or media is published by this page.</p>';
    }
}
add_action('rest_api_init',array('MAD4B_Transport_Enrichment_Audit_V1','rest'));
