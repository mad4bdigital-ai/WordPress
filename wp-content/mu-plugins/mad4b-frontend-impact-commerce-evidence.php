<?php
/**
 * Plugin Name: MAD4B Frontend Impact — Commerce & Interaction Evidence Adapter
 * Description: Read-only scoring of verified existing commercial, filter, form and UI issues.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

add_filter('mad4b/frontend_impact/score_unmapped_issue',static function($unmapped,$issue,$pages) {
    if(!is_array($issue)||!is_array($pages))return $unmapped;
    $code=(string)($issue['code']??'');
    $source=(string)($issue['source']??'');
    $kind='';$matching=[];$score=null;$group='';$confidence='not_verified';
    $evidence='';$source_post=0;$provider='native_public_html';
    $each=static function(callable $match)use($pages):array {
        $result=[];
        foreach($pages as $locale=>$rows)foreach((array)$rows as $row) {
            if(!is_array($row)||empty($row['url'])||($row['status']??0)!==200)continue;
            if($match($row,(string)$locale))$result[]=$row['url'];
        }
        return array_slice(array_values(array_unique($result)),0,8);
    };
    if($code==='expired_rate_renewal_required' &&
       ($issue['post_type']??'')==='tour-rates') {
        $rate_id=(int)($issue['object_id']??0);
        if($rate_id<1||get_post_type($rate_id)!=='tour-rates')return $unmapped;
        $source_post=(int)get_post_meta($rate_id,'related_tour_id',true);
        if($source_post<1||get_post_type($source_post)!=='tours-and-activities'||
           get_post_status($source_post)!=='publish')return $unmapped;
        $end=(int)get_post_meta($rate_id,'tour_rate_end_date',true);
        if($end<1||$end>=time())return $unmapped;
        $matching=$each(static function($page)use($source_post) {
            return in_array((string)($page['kind']??''),['tour','archive'],true) &&
                in_array($source_post,array_map('intval',(array)($page['post_ids']??[])),true);
        });
        if(!$matching)return $unmapped; // No actual visitor exposure verified.
        $score=80;$kind='rate_validity';$group='rate/tour/'.$source_post;
        $confidence='verified_published_tour_in_public_html';
        $evidence='expired_native_rate_related_to_published_tour_in_live_page';
        $provider='shared_product_native_relation';
    } elseif($code==='form_submissions_unread' &&
            ($issue['post_type']??'')==='site') {
        $rules=class_exists('MAD4B_SCP_Content_Readiness_Alerts')
            ?MAD4B_SCP_Content_Readiness_Alerts::rules():[];
        $form_id=(int)($rules['form_id']??0);
        if($form_id<1)return $unmapped;
        $matching=$each(static function($page)use($form_id) {
            return in_array($form_id,array_map('intval',(array)($page['form_ids']??[])),true);
        });
        if(!$matching)return $unmapped;
        $score=54;$kind='form_followup';$group='forms/'.$form_id;
        $confidence='verified_form_visible_not_form_submission_failure';
        $evidence='existing_readiness_backlog_linked_to_live_public_form';
        $provider='fluent_forms_readiness';
    } elseif($code==='perf_catalog_initial_layout' &&
            $source==='mad4b-live-performance') {
        $matching=$each(static fn($p)=>(($p['kind']??'')==='archive' &&
            (int)($p['listing_grids']??0)>0));
        if(!$matching)return $unmapped;
        $score=74;$kind='catalog_layout';$group='catalog/listing-layout';
        $confidence='live_archive_and_existing_performance_alert';
        $evidence='catalog_layout_monitor_issue_and_published_jetengine_grid';
    } elseif($code==='perf_rate_locale_parity' &&
            $source==='mad4b-live-performance') {
        $matching=$each(static fn($p)=>(($p['kind']??'')==='tour' &&
            (int)($p['forms']??0)>0));
        if(!$matching)return $unmapped;
        $score=79;$kind='rate_locale_parity';$group='commerce/rate-translations';
        $confidence='observed_tour_surface_alert_not_independent_price_gap_proof';
        $evidence='native_price_parity_monitor_issue_on_published_tour_surfaces';
    } elseif($code==='perf_ratecss_source_missing' &&
            $source==='mad4b-live-performance') {
        $matching=$each(static fn($p)=>(($p['kind']??'')==='tour' &&
            (int)($p['listing_grids']??0)>0));
        if(!$matching)return $unmapped;
        $score=62;$kind='rate_css';$group='commerce/rate-display-css';
        $confidence='live_surface_only_asset_failure_from_source_monitor';
        $evidence='rate_css_monitor_issue_on_published_tour_page';
    } elseif($code==='perf_ttfb_site' && $source==='mad4b-live-performance') {
        $matching=$each(static fn($p)=>(($p['kind']??'')==='home'));
        if(!$matching)return $unmapped;
        $score=69;$kind='site_performance';$group='performance/home';
        $confidence='existing_performance_monitor_and_live_homepage';
        $evidence='existing_ttfb_monitor_issue_affects_live_published_site';
    } elseif($code==='perf_lcpdiscover_home' && $source==='mad4b-live-performance') {
        $matching=$each(static fn($p)=>(($p['kind']??'')==='home' &&
            (int)($p['elementor_elements']??0)>0));
        if(!$matching)return $unmapped;
        $score=64;$kind='hero_performance';$group='performance/hero';
        $confidence='existing_monitor_and_live_elementor_home';
        $evidence='source_lcp_monitor_issue_and_live_home_elementor';
    } elseif($code==='perf_probe_stale' && $source==='mad4b-live-performance') {
        // An outdated probe is instrumentation debt, not a visitor outage.
        $score=29;$kind='observability';$group='observability/probe';
        $confidence='source_monitor_only';
        $evidence='stale_measurement_requires_new_observation';
    } elseif(in_array($code,['ccr_tour_highlights','ccr_tour_itinerary'],true) &&
          $source==='content-completeness' &&
          ($issue['post_type']??'')==='tours-and-activities') {
        $source_post=(int)($issue['object_id']??0);
        if(get_post_status($source_post)!=='publish')return $unmapped;
        $matching=$each(static function($p)use($source_post) {
            return ($p['kind']??'')==='tour' &&
                in_array($source_post,array_map('intval',(array)($p['post_ids']??[])),true);
        });
        if(!$matching)return $unmapped;
        $score=62;$kind='tour_content';$group='content/tour/'.$source_post;
        $confidence='published_tour_visible_missing_source_field';
        $evidence='existing_completeness_gap_on_public_tour_detail';
    } else return $unmapped;
    if(!is_int($score)||$score<0||$score>100)return $unmapped;
    // Every result has a stable source issue, no generated/claimed issue ID.
    // No monetary values, customer data, booking grants, or fabricated failures.
    return [
        'score'=>$score,'confidence'=>$confidence,'surface'=>$kind,
        'component_kind'=>$kind,'group'=>$group,'evidence'=>$evidence,
        'observed_urls'=>array_slice($matching,0,3),
        'source_post_id'=>$source_post ?: null,'source_provider'=>$provider,
        'publication_authorized'=>false,'booking_authorized'=>false,
        'verification'=>'read_only_public_html_plus_existing_alert'
    ];
},20,3);
