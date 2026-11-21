<?php
/**
 * Plugin Name: MAD4B Package Components — Conditional Tour Editor Context
 * Description: Read-only, source-backed package authoring directions; no mirrored JetEngine fields, meta writes, or new Relations.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Package_Components_Editor_Context_V1',false)) {
final class MAD4B_Package_Components_Editor_Context_V1 {
    public const BOX='mad4b-package-components-context-v1';
    public const LINK_META='_mad4b_package_stay_verified_room_program_links_v1';

    /**
     * Strictly use the canonical Tour Types taxonomy. WPML source-language
     * fallback covers translated Tours missing locally replicated term slugs.
     * No tour-title keyword detection and no tour_mode=cruise_tour shortcut.
     */
    public static function canonical(int $tour):int {
        if($tour<=0 || get_post_type($tour)!=='tours-and-activities')return 0;
        $en=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,'en');
        return $en>0&&get_post_type($en)==='tours-and-activities'?$en:$tour;
    }
    public static function isPackage(int $tour):bool {
        if($tour<=0||get_post_type($tour)!=='tours-and-activities')return false;
        $source=self::canonical($tour);
        foreach(array_unique([$tour,$source]) as $id){
            $slugs=wp_get_object_terms($id,'tour-types',['fields'=>'slugs']);
            if(is_array($slugs)&&in_array('packages',$slugs,true))return true;
            // WPML may localize the term slug. Canonicalize each term identity
            // to the English Packages term; never map by title similarity.
            $base=get_term_by('slug','packages','tour-types');
            if(!($base instanceof WP_Term))continue;
            $ids=wp_get_object_terms($id,'tour-types',['fields'=>'ids']);
            if(is_wp_error($ids)||!is_array($ids))continue;
            foreach($ids as $term){
                $original=(int)apply_filters('wpml_object_id',
                    (int)$term,'tour-types',false,'en');
                if($original===(int)$base->term_id)return true;
            }
        }
        return false;
    }
    private static function rateIds(int $tour):array {
        // The already-existing tour_queried_rates Query 313 displays Rate
        // inventory in the Tour editor. DO NOT recreate that UI here.
        // This is an internal count and first-edit-link lookup ONLY, using
        // the authoritative, scalar Tour Rate related_tour_id meta field.
        $ids=get_posts([
            'post_type'=>'tour-rates',
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>250,'fields'=>'ids','no_found_rows'=>true,
            'suppress_filters'=>true,'meta_key'=>'related_tour_id',
            'meta_value'=>(string)$tour,'orderby'=>'ID','order'=>'ASC',
        ]);
        $valid=[];
        foreach((array)$ids as $raw){
            $id=(int)$raw;
            if($id<=0||get_post_type($id)!=='tour-rates'||
                (int)get_post_meta($id,'related_tour_id',true)!==$tour)continue;
            $valid[$id]=$id;
        }
        return array_values($valid);
    }
    private static function stageRows(int $rate,string $meta):array {
        $raw=get_post_meta($rate,$meta,true);
        if(is_array($raw)&&is_array($raw['stages']??null))$raw=$raw['stages'];
        if(!is_array($raw))return [];
        return array_values(array_filter($raw,
            static fn($stage)=>is_array($stage)&&
                (trim((string)($stage['key']??''))!==''||
                 trim((string)($stage['label']??''))!=='')));
    }
    public static function inspect(int $tour):array {
        $eligible=self::isPackage($tour);
        $base=['contract'=>'mad4b.package-components-editor-context.v1',
            'tour_id'=>$tour,'show'=>$eligible,
            'context_only'=>true,'saved'=>false,'price_modified'=>false,
            'related_tour_id_authority'=>'tour-rates.postmeta',
            'tour_query_313_inventory_only'=>true,
            'new_relation_created'=>false,'new_meta_fields_created'=>false];
        if(!$eligible)return $base;
        $source=self::canonical($tour);
        $rates=self::rateIds($tour);
        $stayStages=0;$routeStages=0;$rateWithStay=0;$rateWithRoutes=0;
        foreach($rates as $rate){
            $stay=count(self::stageRows($rate,'_booking_experience_stay_stages_v1'));
            $routes=count(self::stageRows($rate,'_mad4b_transport_stages_v1'));
            $stayStages+=$stay;
            $routeStages+=$routes;
            if($stay)$rateWithStay++;
            if($routes)$rateWithRoutes++;
        }
        $stored=get_post_meta($source,self::LINK_META,true);
        $bindings=is_array($stored)&&is_array($stored['bindings']??null)
            ?$stored['bindings']:[];
        $lodging=[];$needsUnits=0;$needsReceipts=0;
        foreach($bindings as $b){
            if(!is_array($b))continue;
            $kind=sanitize_key((string)($b['lodging_kind']??''));
            if($kind==='')$kind='unknown';
            $lodging[$kind]=($lodging[$kind]??0)+1;
            if((int)($b['selected_property_id']??0)<=0 ||
               empty($b['bookable_unit_ids']))$needsUnits++;
            $receipt=$b['supplier_cost_receipt']??null;
            if(!is_array($receipt)||($receipt['status']??'')!=='approved')
                $needsReceipts++;
        }
        $guide=['day_count'=>0,'reviewed_days'=>0,'unknown_days'=>0,
            'status'=>'source_unavailable','source_kind'=>'none',
            'source_id'=>0];
        if(class_exists('MAD4B_Guide_Service_Composition_V1',false)){
            $sourceInfo=MAD4B_Guide_Service_Composition_V1::source($source);
            if(is_array($sourceInfo)){
                $days=(array)($sourceInfo['days']??[]);
                $guide['source_kind']=(string)($sourceInfo['source_kind']??'none');
                $guide['source_id']=(int)($sourceInfo['source_id']??0);
                $guide['day_count']=count($days);
                $saved=MAD4B_Guide_Service_Composition_V1::saved($sourceInfo);
                if(is_wp_error($saved)){
                    $guide['status']=$saved->get_error_code();
                    $guide['unknown_days']=count($days);
                } else {
                    $statuses=(array)($saved['statuses']??[]);
                    foreach(array_keys($days) as $day){
                        if(($statuses[$day]??'unknown')==='unknown')
                            $guide['unknown_days']++;
                        else $guide['reviewed_days']++;
                    }
                    $guide['status']=$guide['unknown_days']
                        ?'partially_reviewed':'days_reviewed';
                }
            }elseif(is_wp_error($sourceInfo)){
                $guide['status']=$sourceInfo->get_error_code();
            }
        }
        // The cruise subsection appears only for a real serialized cruise
        // lodging stage (or explicit Cruise Tour mode). Titles and
        // destination keywords are not evidence of a Nile cruise.
        $cruiseStages=(int)($lodging['cruise']??0);
        $isCruiseMode=(string)get_post_meta($tour,'tour_mode',true)==='cruise_tour';
        return $base+[
            'canonical_tour_id'=>$source,
            'tour_mode'=>(string)get_post_meta($tour,'tour_mode',true),
            'linked_rate_count'=>count($rates),
            'first_editable_rate_id'=>$rates?(int)$rates[0]:0,
            'guide'=>$guide,
            'accommodation'=>[
                'editorial_stage_count'=>count($bindings),
                'lodging_kinds'=>$lodging,
                'needs_unit_selection'=>$needsUnits,
                'supplier_cost_receipts_missing'=>$needsReceipts,
                'native_rate_stage_count'=>$stayStages,
                'rate_count_with_native_stages'=>$rateWithStay,
            ],
            'transportation'=>[
                'native_rate_stage_count'=>$routeStages,
                'rate_count_with_native_stages'=>$rateWithRoutes,
            ],
            'cruise'=>[
                'show'=>$cruiseStages>0||$isCruiseMode,
                'editorial_stage_count'=>$cruiseStages,
                'supplier_program_verified'=>false,
            ],
            // A source-linked editorial stage and a Rate price are NOT
            // equivalent to an approved supplier COST or checkout-ready Rate.
            'cost'=>['evaluated'=>false,'verified'=>null,
                'known_guide_days_needing_review'=>$guide['unknown_days'],
                'known_stay_units_needing_selection'=>$needsUnits,
                'known_stay_supplier_receipts_missing'=>$needsReceipts,
                'amount_shown'=>false,'checkout_activation'=>false,
                'message'=>'This editorial panel does not issue cost certification; use the original provider review'],
        ];
    }
    private static function editUrl(int $post):string {
        if($post<=0||!current_user_can('edit_post',$post))return '';
        $url=get_edit_post_link($post,'raw');
        return is_string($url)?$url:'';
    }
    private static function link(string $url,string $label):string {
        return $url!==''?'<a href="'.esc_url($url).'">'.esc_html($label).'</a>':'';
    }
    private static function line(string $title,string $state,string $desc,
        string $url='',string $linkLabel='Open original editor'):void {
        echo '<li class="mad4b-pkg-context__item">';
        echo '<div><strong>'.esc_html($title).'</strong>';
        echo '<span class="mad4b-pkg-context__status">'.esc_html($state).'</span></div>';
        echo '<p>'.esc_html($desc).'</p>';
        if($url!=='')echo '<p class="mad4b-pkg-context__link">'.
            self::link($url,$linkLabel).'</p>';
        echo '</li>';
    }
    public static function render(WP_Post $post):void {
        if(!current_user_can('edit_post',$post->ID))return;
        $v=self::inspect((int)$post->ID);
        if(!$v['show'])return;
        $canonical=(int)$v['canonical_tour_id'];
        $sourceTour=self::editUrl($canonical);
        $guideUrl=$sourceTour!==''?$sourceTour.'#mad4b-guide-itinerary-service':'';
        $rate=(int)$v['first_editable_rate_id'];
        $rateUrl=self::editUrl($rate);
        $rateNew=current_user_can('edit_posts')
            ?admin_url('post-new.php?post_type=tour-rates'):'';
        $rateAction=$rateUrl?:$rateNew;
        $rateActionLabel=$rateUrl?'Open first linked Tour Rate · select correct tier':'Create Tour Rate · select Tour first';
        $guide=$v['guide'];$stay=$v['accommodation'];$transport=$v['transportation'];
        echo '<div class="mad4b-pkg-context">';
        echo '<p class="description">Shown only for tours classified as <strong>Packages</strong>. This is a read-only preparation checklist, not a second Tour Rate or cost editor. The existing <strong>Queried Rates</strong> field remains the one Rate inventory in the Tour.</p>';
        if($canonical!==(int)$post->ID)
            echo '<p class="description">Shared source-language component evidence is read from the original Tour. This translation retains its own Tour Rate link.</p>';
        echo '<ol class="mad4b-pkg-context__list">';
        $dayCount=(int)$guide['day_count'];$unknown=(int)$guide['unknown_days'];
        $dayStatus=$dayCount>0
            ?($unknown===0?'Reviewed days · '.$dayCount:
                'Review needed · '.$unknown.' of '.$dayCount.' days')
            :'Source itinerary needed';
        self::line('1. Itinerary & guide days',$dayStatus,
            'Confirm service per original itinerary day. The guide costs are per guide/day; do not add them again to an existing selling Rate.',
            $guideUrl,'Open original Guide Days editor');
        $stages=(int)$stay['editorial_stage_count'];
        $stayStatus=$stages>0
            ?$stages.' editorial stay stage(s) · '.(int)$stay['needs_unit_selection'].' without units'
            :'Stay stages not linked';
        self::line('2. Accommodation',$stayStatus,
            'Property, room/cabin and night assignments live in the original Tour Rate and Property. Editorial stay links are not supplier cost receipts.',
            $rateAction,$rateActionLabel);
        $routeCount=(int)$transport['native_rate_stage_count'];
        $transportStatus=$routeCount>0
            ?$routeCount.' native Rate transport stage(s)'
            :'Transport stages not yet assigned';
        self::line('3. Transportation',$transportStatus,
            'Set journey legs, vehicle and stage pricing only in the existing Tour Rate Transportation tab. A city-level PDF match is not an approved route cost.',
            $rateAction,$rateActionLabel);
        if($v['cruise']['show']){
            self::line('4. Cruise program & cabin',
                (int)$v['cruise']['editorial_stage_count'].' explicit cruise stage(s)',
                'Review actual sailing program, ship, cabin unit, departure and supplier terms. Shown because a saved cruise stage or Cruise Tour mode exists.',
                $rateAction,$rateActionLabel);
        }
        $costUrl=current_user_can('commerce_manage_pricing')&&
            current_user_can('manage_woocommerce')
            ?admin_url('admin.php?page=dynamic-pricing-controls'):'';
        self::line('5. Cost & financial readiness','Not certified by this panel',
            'Check the original provider cost receipts, children/infants, FX, taxes, margin, and customer price inclusion before generation or checkout. This read-only view does not certify or change a selling Rate.',
            $costUrl,'Review existing Pricing Controls');
        echo '</ol>';
        echo '<p class="description mad4b-pkg-context__footer">For other Rate variants, use the Tour\'s existing Queried Rates inventory. This panel does not save, select, or synchronize any additional Rate IDs.</p>';
        echo '</div>';
    }
    public static function register($post=null):void {
        if(!($post instanceof WP_Post)||$post->post_type!=='tours-and-activities'||
            !current_user_can('edit_post',$post->ID)||!self::isPackage((int)$post->ID))
            return;
        // The Nile Cruise standalone preflight boxes currently display on
        // every Tour, telling Package Tour authors to change Tour Mode.
        // Hide only that irrelevant UI for Package (tour_mode=tour) edits.
        // Standalone cruise tours and their original verification remain intact.
        if((string)get_post_meta($post->ID,'tour_mode',true)!=='cruise_tour'){
            remove_meta_box('mad4b-nile-program-first-readiness',
                'tours-and-activities','normal');
            remove_meta_box('mad4b-nile-native-publish-readiness',
                'tours-and-activities','normal');
        }
        add_meta_box(self::BOX,'Package Components · Review & Original Editors',
            [self::class,'render'],'tours-and-activities','normal','high');
    }
    public static function assets(string $hook):void {
        if($hook!=='post.php'||!is_admin())return;
        $tour=absint($_GET['post']??0);
        if(!$tour||!self::isPackage($tour)||!current_user_can('edit_post',$tour))
            return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-package-components-editor-context-v1.css';
        if(is_readable($file))wp_enqueue_style(
            'mad4b-package-components-editor-context-v1',
            content_url('mu-plugins/assets/mad4b-package-components-editor-context-v1.css'),
            [],(string)filemtime($file));
    }
}
add_action('add_meta_boxes_tours-and-activities',
    [MAD4B_Package_Components_Editor_Context_V1::class,'register'],105,1);
add_action('admin_enqueue_scripts',
    [MAD4B_Package_Components_Editor_Context_V1::class,'assets'],75,1);
}
