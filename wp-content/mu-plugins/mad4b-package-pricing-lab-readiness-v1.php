<?php
/**
 * Plugin Name: MAD4B Package Pricing Lab — Cost Readiness
 * Version: 1.0.0
 * Description: Conditional read-only per-Package/tier hotel, unit and supplier cost gap inventory alongside existing Pricing Lab strategy simulator.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Package_Pricing_Lab_Readiness_V1',false)){
final class MAD4B_Package_Pricing_Lab_Readiness_V1 {
    const AJAX='mad4b_package_pricing_lab_read_v1';
    const SOURCE='_mad4b_package_accommodation_source_plan_v1';
    const BINDINGS='_mad4b_package_stay_verified_room_program_links_v1';
    const TIERS=['silver-package'=>'Silver','gold-package'=>'Gold','diamond-package'=>'Diamond'];
    private static function allowed():bool {
        return current_user_can('commerce_manage_pricing') &&
            (current_user_can('manage_woocommerce') ||
            (current_user_can('mad4b_manage_company_operations') &&
            current_user_can('mad4b_work_company_tasks')));
    }
    public static function canonical(int $tour):int {
        if($tour<=0||get_post_type($tour)!=='tours-and-activities')return 0;
        $en=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,'en');
        if($en>0 && get_post_type($en)==='tours-and-activities')return $en;
        return $tour;
    }
    private static function isPackage(int $tour):bool {
        $terms=wp_get_object_terms($tour,'tour-types',['fields'=>'slugs']);
        return is_array($terms)&&in_array('packages',$terms,true);
    }
    public static function packages():array {
        $ids=get_posts(['post_type'=>'tours-and-activities',
            'post_status'=>['publish','draft','pending'],
            'posts_per_page'=>160,'fields'=>'ids','suppress_filters'=>true,
            'meta_key'=>self::SOURCE,'meta_compare'=>'EXISTS',
            'orderby'=>'ID','order'=>'ASC']);
        $out=[];
        foreach($ids as $id){
            $tour=self::canonical((int)$id);
            if(!$tour||!self::isPackage($tour)||
                !current_user_can('edit_post',$tour)||isset($out[$tour]))continue;
            $plan=get_post_meta($tour,self::SOURCE,true);
            if(!is_array($plan)||!is_array($plan['stages']??null))continue;
            $out[$tour]=['id'=>$tour,
                'title'=>wp_strip_all_tags(html_entity_decode(get_the_title($tour),ENT_QUOTES,'UTF-8')),
                'day_count'=>(int)($plan['tour_days']??0),
                'night_count'=>(int)($plan['tour_nights']??0)];
        }
        return array_values($out);
    }
    public static function tierIds():array {
        $out=[];
        foreach(self::TIERS as $slug=>$name){
            $term=get_term_by('slug',$slug,'package_levels');
            if($term instanceof WP_Term)$out[]=['id'=>(int)$term->term_id,'label'=>$name,'slug'=>$slug];
        }
        return $out;
    }
    private static function editLink(int $post):?string {
        if($post<=0||!current_user_can('edit_post',$post))return null;
        $link=get_edit_post_link($post,'raw');
        return is_string($link)&&$link!==''?$link:null;
    }
    private static function publishedRateIndex(array $properties):array {
        if(!$properties)return [];
        global $wpdb;
        $ids=array_map('absint',array_keys($properties));
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $sql=$wpdb->prepare(
            "SELECT p.ID rate_id, CAST(pm.meta_value AS UNSIGNED) property_id
            FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
            WHERE p.post_type='property-rates' AND p.post_status='publish'
            AND pm.meta_key='prop_related_property_id'
            AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders})",
            $ids
        );
        $out=[];
        foreach((array)$wpdb->get_results($sql,ARRAY_A) as $r){
            $pid=(int)$r['property_id'];$rate=(int)$r['rate_id'];
            if(!get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true) &&
                !get_post_meta($rate,'_qa_disallow_public_checkout',true))
                $out[$pid]['published'] = ($out[$pid]['published']??0)+1;
            $amount=get_post_meta($rate,'base_amount',true);
            if(!get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true) &&
                !get_post_meta($rate,'_qa_disallow_public_checkout',true) &&
                is_numeric($amount)&&(float)$amount>0 &&
                (string)get_post_meta($rate,'mad4b_rate_price_kind_v1',true)==='cost')
                $out[$pid]['cost_entries']=($out[$pid]['cost_entries']??0)+1;
        }
        return $out;
    }
    private static function nativeRelationUnits(int $property,int $relId):array {
        global $wpdb;
        if($relId<=0)return [];
        $table=$wpdb->prefix.'jet_rel_default';
        $rows=$wpdb->get_col($wpdb->prepare(
            "SELECT child_object_id FROM {$table} WHERE rel_id=%d AND parent_object_id=%d",
            $relId,$property
        ));
        return array_fill_keys(array_map('intval',(array)$rows),true);
    }
    public static function inspect(int $tour,int $tierId) {
        $tour=self::canonical($tour);
        if(!$tour||!self::isPackage($tour)||!current_user_can('edit_post',$tour))
            return new WP_Error('package_lab_tour_unavailable');
        $tiers=self::tierIds();
        if(!in_array($tierId,array_column($tiers,'id'),true))
            return new WP_Error('package_lab_tier_invalid');
        $plan=get_post_meta($tour,self::SOURCE,true);
        $bindings=get_post_meta($tour,self::BINDINGS,true);
        if(!is_array($plan)||!is_array($plan['stages']??null))
            return new WP_Error('package_lab_plan_unavailable');
        $raw=is_array($bindings)?($bindings['bindings']??[]):[];
        if(!is_array($raw))$raw=[];
        $byKey=[];
        foreach($raw as $b)if(is_array($b)&&!empty($b['stage_key']))
            $byKey[(string)$b['stage_key']]=$b;
        $stages=[];$allProps=[];
        $stats=['stages'=>0,'hotel_or_resort_stages'=>0,
            'stages_without_candidates'=>0,'candidate_mentions'=>0,
            'units_referenced'=>0,'unit_occupancy_recorded'=>0,
            'unit_direct_positive_prices'=>0,'unit_cost_role_positive_prices'=>0,
            'unit_available'=>0,'published_property_rates'=>0,
            'approved_supplier_stage_receipts'=>0,'native_rate_stages_written'=>0,
            'selected_stage_properties'=>0,'selected_stage_units'=>0,
            'unpriced_stages'=>0];
        foreach(array_slice($plan['stages'],0,40) as $p){
            if(!is_array($p))continue;
            $key=(string)($p['stage_key']??'');
            $b=$byKey[$key]??[];
            $kind=sanitize_key((string)($p['lodging_kind']??$b['lodging_kind']??'unknown'));
            $rows=(array)($b['room_candidate_refs'][(string)$tierId]??[]);
            $fallback=(array)($p['hotel_alternatives_or_by_tier'][(string)$tierId]??[]);
            $candidates=[];$candidateIds=[];
            foreach(array_slice($rows,0,60) as $entry){
                if(!is_array($entry))continue;
                $pid=(int)($entry['property_id_en']??0);
                if(!$pid||isset($candidateIds[$pid]))continue;
                $candidateIds[$pid]=true;
                $allProps[$pid]=$pid;
                $units=array_values(array_unique(array_filter(array_map(
                    'absint',(array)($entry['unit_ids_en']??[])))));
                $candidates[]=['property_id'=>$pid,
                    'unit_ids'=>$units,
                    'rel_id'=>(int)($entry['source_relation_id']??0),
                    'review_approved_unit_ids'=>array_values(array_map('absint',
                        (array)($entry['commercially_approved_unit_ids']??[])))];
            }
            // Preserve plan-only hotel alternatives as *unverified* candidates,
            // not as inventory or cost-bearing units.
            foreach(array_slice($fallback,0,60) as $rawId){
                $pid=(int)$rawId;
                if(!$pid||isset($candidateIds[$pid]))continue;
                $candidateIds[$pid]=true;
                $allProps[$pid]=$pid;
                $candidates[]=['property_id'=>$pid,'unit_ids'=>[],
                    'rel_id'=>0,'review_approved_unit_ids'=>[]];
            }
            $isStay=in_array($kind,['hotel','resort','cruise'],true);
            $hasHotel=in_array($kind,['hotel','resort'],true);
            $stats['stages']++;
            if($hasHotel){
                $stats['hotel_or_resort_stages']++;
                if(!$candidates)$stats['stages_without_candidates']++;
            }
            $receipt=$b['supplier_cost_receipt']??null;
            if(is_array($receipt)&&($receipt['status']??'')==='approved')
                $stats['approved_supplier_stage_receipts']++;
            if(!empty($b['native_rate_stage_written']))
                $stats['native_rate_stages_written']++;
            if((int)($b['selected_property_id']??0)>0)
                $stats['selected_stage_properties']++;
            if(!empty($b['bookable_unit_ids']))
                $stats['selected_stage_units']++;
            if($isStay && ($b['price_status']??$p['price_status']??'')!=='priced')
                $stats['unpriced_stages']++;
            $stats['candidate_mentions']+=count($candidates);
            $program=$b['cruise_program']??null;
            $stages[]=[
                'key'=>$key,'kind'=>$kind,'day_start'=>(int)($p['source_day_start']??0),
                'nights'=>max(0,(int)($p['nights']??0)),
                'destination'=>sanitize_key((string)($p['destination_key']??'')),
                'board'=>sanitize_key((string)($p['board']??'')),
                'price_status'=>(string)($b['price_status']??$p['price_status']??'pending_quote'),
                'selected_property_id'=>(int)($b['selected_property_id']??0),
                'selected_unit_count'=>count((array)($b['bookable_unit_ids']??[])),
                'supplier_receipt_approved'=>is_array($receipt)&&($receipt['status']??'')==='approved',
                'native_rate_stage_written'=>!empty($b['native_rate_stage_written']),
                'cruise_program_term'=>(int)(is_array($program)?($program['program_term_id_en']??0):0),
                'candidates'=>$candidates
            ];
        }
        $rateIndex=self::publishedRateIndex($allProps);
        $distinctUnits=[];$detailed=[];
        foreach($stages as $stage){
            $out=$stage;$out['candidates']=[];
            foreach($stage['candidates'] as $candidate){
                $pid=$candidate['property_id'];$linked=$candidate['unit_ids'];
                $relations=self::nativeRelationUnits($pid,$candidate['rel_id']);
                $unitRows=[];$unitNum=0;$capNum=0;$availableNum=0;$costNum=0;$directNum=0;
                foreach(array_slice($linked,0,150) as $id){
                    $id=(int)$id;$unitNum++;
                    $type=get_post_type($id);$status=(string)get_post_status($id);
                    $amount=get_post_meta($id,'unit_start_price',true);
                    $direct=is_numeric($amount)&&(float)$amount>0;
                    $kind=(string)get_post_meta($id,'unit_direct_price_kind',true);
                    $currency=(string)get_post_meta($id,'unit_base_currency',true);
                    $basis=(string)get_post_meta($id,'unit_direct_price_unit',true);
                    $availability=in_array((string)get_post_meta($id,'unit_available',true),
                        ['1','true','yes','on'],true);
                    $capacity=get_post_meta($id,'unit_max_occupancy',true);
                    $capacityOk=is_numeric($capacity)&&(int)$capacity>0;
                    $receipt=get_post_meta($id,'_mad4b_room_source_receipt_v1',true);
                    $propertyMatch=($type==='units' && 
                        is_array($receipt) && (int)($receipt['property_id']??0)===$pid &&
                        isset($relations[$id]));
                    $cost=$direct && $kind==='cost' && $currency!=='' && $basis!=='';
                    if($direct)$directNum++;
                    if($cost)$costNum++;
                    if($availability)$availableNum++;
                    if($capacityOk)$capNum++;
                    if(!isset($distinctUnits[$id])){
                        $distinctUnits[$id]=true;
                        $stats['units_referenced']++;
                        if($direct)$stats['unit_direct_positive_prices']++;
                        if($cost)$stats['unit_cost_role_positive_prices']++;
                        if($availability)$stats['unit_available']++;
                        if($capacityOk)$stats['unit_occupancy_recorded']++;
                    }
                    $unitRows[]=[
                        'id'=>$id,'title'=>wp_strip_all_tags(html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8')),
                        'status'=>$status,'available'=>$availability,'capacity'=>$capacityOk?(int)$capacity:null,
                        'source_relation_match'=>$propertyMatch,
                        // A cost-kind direct amount is a recorded field, NOT an approved supplier receipt.
                        'indicative_direct_price_entered'=>$direct,
                        'declared_unit_cost_field'=>$cost,
                        'edit_url'=>self::editLink($id)
                    ];
                }
                $rates=$rateIndex[$pid]??[];
                $stats['published_property_rates']+=(int)($rates['published']??0);
                $out['candidates'][]=[
                    'id'=>$pid,
                    'title'=>wp_strip_all_tags(html_entity_decode(get_the_title($pid),ENT_QUOTES,'UTF-8')),
                    'status'=>(string)get_post_status($pid),
                    'native_units_linked'=>$unitNum,
                    'unit_capacity_known'=>$capNum,
                    'unit_available'=>$availableNum,
                    'unit_direct_prices_entered'=>$directNum,
                    'unit_cost_fields'=>$costNum,
                    'published_property_rate_count'=>(int)($rates['published']??0),
                    'published_property_cost_rate_count'=>(int)($rates['cost_entries']??0),
                    'approved_unit_refs_count'=>count($candidate['review_approved_unit_ids']),
                    'property_direct_cost_role'=>(string)get_post_meta($pid,'prop_direct_price_kind',true)==='cost' &&
                        is_numeric(get_post_meta($pid,'prop_start_price',true)) &&
                        (float)get_post_meta($pid,'prop_start_price',true)>0,
                    'edit_url'=>self::editLink($pid),
                    'units'=>$unitRows
                ];
            }
            $detailed[]=$out;
        }
        $nativeRates=get_posts(['post_type'=>'tour-rates','fields'=>'ids',
            'post_status'=>['publish','draft','pending'],
            'posts_per_page'=>80,'suppress_filters'=>true,
            'meta_key'=>'related_tour_id','meta_value'=>(string)$tour]);
        $statuses=[];foreach($nativeRates as $r){$s=(string)get_post_status($r);$statuses[$s]=($statuses[$s]??0)+1;}
        $context=class_exists('MAD4B_Package_Components_Editor_Context_V1',false)
            ?MAD4B_Package_Components_Editor_Context_V1::inspect($tour):[];
        return [
            'contract'=>'mad4b.package-cost-readiness.v1',
            'tour_id'=>$tour,'tour_title'=>wp_strip_all_tags(html_entity_decode(get_the_title($tour),ENT_QUOTES,'UTF-8')),
            'tour_edit_url'=>self::editLink($tour),
            'tier_id'=>$tierId,'tier'=>array_values(array_filter($tiers,
                static fn($t)=>(int)$t['id']===$tierId))[0]['label'],
            'day_count'=>(int)($plan['tour_days']??0),
            'night_count'=>(int)($plan['tour_nights']??0),
            'plan_status'=>(string)($plan['source_review_status']??'not_certified'),
            'plan_recorded_at'=>(string)($plan['recorded_at']??''),
            'source_plan_warnings'=>array_values(array_filter(array_map('sanitize_key',
                (array)($plan['source_issues']??[])))),
            'source_plan_policy_error'=>sanitize_key((string)($plan['company_policy_error']??'')),
            'sources'=>['plan'=>self::SOURCE,'stay_bindings'=>self::BINDINGS,
                'property'=>'properties','unit'=>'units','property_rates'=>'property-rates',
                'tour_rate_relation'=>'related_tour_id'],
            'summary'=>$stats,
            'unit_sources_are_editorial_only'=>true,
            'supplier_cost_total'=>null,
            'sale_price'=>null,
            'margin'=>null,
            'generated_rate_ready'=>false,
            'checkout_ready'=>false,
            'linked_original_rate_count'=>count($nativeRates),
            'linked_original_rate_statuses'=>$statuses,
            'guide_review'=>$context['guide']??null,
            'transport_review'=>$context['transportation']??null,
            'stages'=>$detailed,
            'note'=>'Read-only candidate and unit inventory. No cost is calculated without approved room/cabin, dates, provider cost receipts, currency and occupancy. No QA Rate or legacy Tour Rate price is used.'
        ];
    }
    public static function ajax():void {
        if(!self::allowed())wp_send_json_error(['code'=>'package_lab_forbidden'],403);
        if(!check_ajax_referer(self::AJAX,'nonce',false))
            wp_send_json_error(['code'=>'package_lab_nonce_invalid'],403);
        $tour=filter_var($_POST['tour_id']??null,FILTER_VALIDATE_INT);
        $tier=filter_var($_POST['tier_id']??null,FILTER_VALIDATE_INT);
        if(!$tour||!$tier)wp_send_json_error(['code'=>'package_lab_invalid_selection'],422);
        $data=self::inspect((int)$tour,(int)$tier);
        if(is_wp_error($data))wp_send_json_error(['code'=>$data->get_error_code()],422);
        wp_send_json_success(['result'=>$data,'saved'=>false,'write_performed'=>false]);
    }
    public static function assets():void {
        if(!is_admin()||sanitize_key((string)($_GET['page']??''))!=='mad4b-pricing-lab'||
            !self::allowed())return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-package-pricing-lab-readiness-v1.js';
        $style=WPMU_PLUGIN_DIR.'/assets/mad4b-package-pricing-lab-readiness-v1.css';
        if(!is_readable($file)||!is_readable($style))return;
        wp_enqueue_style('mad4b-package-pricing-lab-readiness-v1',
            content_url('mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.css'),
            [],(string)filemtime($style));
        wp_enqueue_script('mad4b-package-pricing-lab-readiness-v1',
            content_url('mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.js'),
            [],(string)filemtime($file),true);
        wp_add_inline_script('mad4b-package-pricing-lab-readiness-v1',
            'window.MAD4BPackageCostLab='.wp_json_encode([
                'endpoint'=>admin_url('admin-ajax.php'),'action'=>self::AJAX,
                'nonce'=>wp_create_nonce(self::AJAX),'packages'=>self::packages(),
                'tiers'=>self::tierIds(),
                'property_pricing_url'=>admin_url('admin.php?page=mad4b-stay-price-layers'),
            ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';',
            'before');
    }
    public static function container():void {
        if(!is_admin()||sanitize_key((string)($_GET['page']??''))!=='mad4b-pricing-lab'||
            !self::allowed())return;
        echo '<section id="mad4b-package-cost-lab-v1" class="mad4b-package-cost-lab-v1" aria-label="Package Cost & Unit Readiness">';
        echo '<div class="mad4b-pcl-heading"><h2>Package Cost &amp; Unit Readiness</h2>';
        echo '<p>Choose a Package and tier to inspect native hotel choices, linked rooms, occupancy and supplier-cost gaps. Read-only — not a quote, strategy preset, new Rate or checkout approval.</p></div>';
        echo '<div id="mad4b-package-cost-lab-inner" aria-live="polite"><p>Preparing Package inventory…</p></div>';
        echo '</section>';
    }
}
add_action('wp_ajax_'.MAD4B_Package_Pricing_Lab_Readiness_V1::AJAX,
    [MAD4B_Package_Pricing_Lab_Readiness_V1::class,'ajax']);
add_action('admin_enqueue_scripts',
    [MAD4B_Package_Pricing_Lab_Readiness_V1::class,'assets'],87);
add_action('admin_notices',
    [MAD4B_Package_Pricing_Lab_Readiness_V1::class,'container'],100);
}
