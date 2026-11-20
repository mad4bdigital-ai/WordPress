<?php
/**
 * Plugin Name: MAD4B Pricing Rule State Inspector
 * Version: 1.0.0
 * Description: Read-only company-specific visibility of targeting dimensions, saved Strategy Drafts and commercial activation evidence.
 */
defined('ABSPATH')||exit;

final class MAD4B_Pricing_Rules_Visibility_V1 {
    public const AJAX='mad4b_pricing_rules_readback_v1';

    public static function permitted(): bool {
        return current_user_can('commerce_manage_pricing') &&
            current_user_can('manage_woocommerce');
    }

    /** Sanitized, read-only summary. Do not infer activation from dimension selection. */
    public static function snapshot(): array|WP_Error {
        if(!self::permitted())return new WP_Error('pricing_rules_readback_forbidden');
        global $wpdb;
        $on=in_array(strtolower((string)get_option(
            'etg-dynamic-pricing-controls_pricing_engine_enabled','false')),
            ['true','1','on','yes'],true);
        $booking=in_array(strtolower((string)get_option(
            'etg-dynamic-pricing-controls_booking_enabled','false')),
            ['true','1','on','yes'],true);
        $dimensionClass='MAD4B_JetEngine_Pricing_Dimensions_V1';
        $settings=class_exists($dimensionClass,false)
            ?$dimensionClass::current():['mode'=>'all','enabled_ids'=>[],
                'revision'=>0,'saved'=>false];
        $mode=$settings['mode']==='custom'?'custom':'all';
        $chosen=array_values(array_filter((array)($settings['enabled_ids']??[]),
            'is_string'));
        if($mode==='all')$chosen=[]; // all is dynamic, not a stored list
        // All Lab strategies and native assignments are derived from ONE
        // canonical registry snapshot. Do not independently re-read their
        // WP options, which could produce inconsistent counters.
        $registry=class_exists('MAD4B_Unified_Pricing_Policy_Registry_V1',false)
            ?MAD4B_Unified_Pricing_Policy_Registry_V1::snapshot()
            :new WP_Error('unified_policy_registry_unavailable');
        if(is_wp_error($registry))return $registry;
        $strategies=$registry['strategy_summaries']??[];
        $byDimension=[];
        foreach($strategies as $record)
            foreach((array)($record['dimensions']??[]) as $dimension)
                $byDimension[$dimension][]=$record;
        $native=$registry['native_assignment_summary']??[
            'rows'=>0,'disabled'=>0,'enabled_unapproved'=>0,
            'enabled_approved'=>0,'native_layer_enabled'=>false,
        ];
        $publish=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour-rates' AND post_status='publish'");
        $global=get_option('mad4b_pricing_global_policy_ids_v1',[]);
        $globalRefs=is_array($global)?count($global):0;
        $linkedTerms=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key=%s",
            'mad4b_pricing_policy_ids'));
        $linkedPosts=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s",
            'mad4b_pricing_policy_override_ids'));
        return [
            'schema'=>2,
            'scope'=>'mad4b_pricing_lab_policy_bindings_native_preset_readback',
            'read_only'=>true,'no_sale_quote_authority'=>true,
            'engine_enabled'=>$on,'booking_enabled'=>$booking,
            'dimension_mode'=>$mode,'dimension_saved'=>(bool)($settings['saved']??false),
            'dimension_revision'=>(int)($settings['revision']??0),
            'selected_dimensions'=>$chosen,
            'strategy_count'=>count($strategies),'strategies'=>$strategies,
            'by_dimension'=>$byDimension,
            'native_assignments'=>$native,
            'policy_bindings'=>[
                'global_refs'=>$globalRefs,'term_records'=>$linkedTerms,
                'post_records'=>$linkedPosts,
            ],
            'published_tour_rates'=>$publish,
            'effective_native_rule_count_is_proven'=>false,
            'unified_registry'=>$registry,
            'note'=>'Enabled targeting dimensions are available for strategy design. They are not active price rules. Only native provider evaluation can prove an effective quote.',
        ];
    }
    public static function ajax(): void {
        if(!self::permitted())wp_send_json_error(['reason'=>'Forbidden'],403);
        if(!check_ajax_referer(self::AJAX,'nonce',false))
            wp_send_json_error(['reason'=>'Invalid nonce'],403);
        $result=self::snapshot();
        if(is_wp_error($result))
            wp_send_json_error(['reason'=>$result->get_error_code()],403);
        wp_send_json_success($result);
    }
    public static function assets(): void {
        if(!self::permitted())return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!in_array($page,['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true))
            return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-pricing-rules-visibility-v1.js';
        if(!is_readable($file))return;
        wp_enqueue_script('mad4b-pricing-rules-visibility-v1',
            content_url('mu-plugins/assets/mad4b-pricing-rules-visibility-v1.js'),
            ['mad4b-jetengine-functional-modes'],(string)filemtime($file),true);
        wp_add_inline_script('mad4b-pricing-rules-visibility-v1',
            'window.MAD4BPricingRulesReadback='.wp_json_encode([
                'ajax'=>admin_url('admin-ajax.php'),'action'=>self::AJAX,
                'nonce'=>wp_create_nonce(self::AJAX),
                'lab'=>admin_url('admin.php?page=mad4b-pricing-lab'),
                'bindings'=>admin_url('admin.php?page=mad4b-policy-bindings'),
            ]).';','before');
    }
}
add_action('admin_enqueue_scripts',[MAD4B_Pricing_Rules_Visibility_V1::class,'assets'],46);
add_action('wp_ajax_'.MAD4B_Pricing_Rules_Visibility_V1::AJAX,
    [MAD4B_Pricing_Rules_Visibility_V1::class,'ajax']);
