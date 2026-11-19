<?php
/**
 * Shared Rate Generator => MAD4B Hybrid Resolver read-only bridge.
 *
 * Monetary values, source fingerprint and native quote keys are untouched.
 * The additional hybrid_policy_context is a diagnostic only.
 */
defined('ABSPATH') || exit;
final class MAD4B_Hybrid_Rate_Generator_Bridge_V1 {
    public const AJAX='mad4b_rate_generator_hybrid_preview_v1';

    public static function boot():void {
        add_filter('mad4b/rate_generator/plan',[__CLASS__,'plan'],10,3);
        add_filter('mad4b/rate_generator/selection',[__CLASS__,'selection'],10,3);
        add_filter('mad4b/rate_generator/quote',[__CLASS__,'quote'],10,3);
        if(!is_admin())return;
        add_action('mad4b/rate_generator/policy_panel',[__CLASS__,'panel'],10,1);
        add_action('wp_ajax_'.self::AJAX,[__CLASS__,'ajax']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],20);
    }
    private static function permitted():bool {
        return class_exists('MAD4B_Hybrid_Pricing_Policies_V1')
           &&MAD4B_Hybrid_Pricing_Policies_V1::permitted();
    }
    public static function inspect(string $phase,string $kind,int $tour,int $rate=0):array {
        $base=[
            'schema'=>'mad4b-rate-generator-hybrid-context.v1',
            'phase'=>$phase,'kind'=>$kind,'tour_id'=>$tour,
            'rate_id'=>$rate,'mode'=>'advisory_preview_only',
            'commercial_activation'=>false,
            'native_quote_amount_modified'=>false,
            'native_fingerprint_modified'=>false,
            'stop_sale_modified'=>false,
        ];
        if($kind!=='tour')return $base+['status'=>'not_applicable'];
        if(!self::permitted())return $base+['status'=>'pricing_permission_required'];
        if(!$tour||get_post_type($tour)!=='tours-and-activities')
            return $base+['status'=>'tour_not_found'];
        $resolver='MAD4B_Hybrid_Pricing_Policies_V1';
        $tourDecision=$resolver::resolve($tour);
        $rateDecision=null;
        if($rate){
            if(get_post_type($rate)!=='tour-rates'
              ||(int)get_post_meta($rate,'related_tour_id',true)!==$tour)
                return $base+['status'=>'rate_parent_mismatch'];
            $rateDecision=$resolver::resolve($rate);
        }
        $selected=$rateDecision??$tourDecision;
        return $base+[
            'status'=>'ready',
            'effective_source'=>$selected['effective']['source']
                ??'existing_pricing_default',
            'effective_policy'=>$selected['effective']??null,
            'inheritance'=>$selected['levels']??[],
            'conflicts'=>$selected['conflicts']??[],
            'priority_order'=>$selected['resolution_config']['order']
                ??$resolver::RESOLUTION_LEVELS,
            'operator_review_required'=>!empty($selected['conflicts'])
                ||($selected['status']??'')==='ambiguous_policy_scope',
        ];
    }
    public static function plan($hint,$plan,$input):array {
        if(!is_array($plan))return [];
        return self::inspect('generator_prepare',(string)($plan['kind']??''),
            (int)($plan['parent_id']??0));
    }
    public static function selection($hint,$result,$input):array {
        if(!is_array($result))return [];
        return self::inspect('rate_selection',(string)($result['kind']??''),
            (int)($result['parent_id']??0),
            (int)($result['selected_rate_id']??0));
    }
    public static function quote($hint,$quote,$input):array {
        if(!is_array($quote))return [];
        return self::inspect('explicit_rate_quote',(string)($quote['kind']??''),
            (int)($quote['parent_id']??0),
            (int)($quote['rate_id']??0));
    }
    public static function panel(int $tour=0):void {
        if(!self::permitted())return;
        $id=$tour && get_post_type($tour)==='tours-and-activities'?$tour:0;
        echo '<section class="mad4b-rate-hybrid-panel" id="mad4b-rate-hybrid-panel" aria-label="Rate Generator policy inheritance">';
        echo '<h3>Hybrid Policy & Rate Generator</h3>';
        echo '<p>One policy inheritance inspector for Rate Generation, Pricing Lab and Policy Bindings. Source prices, stop-sale, locale and WooCommerce quote calculations stay with their native engines.</p>';
        echo '<label>Inspect: <select id="mad4b-rate-hybrid-kind">';
        echo '<option value="tour">Tour / generated Rate draft</option><option value="rate">Existing Tour Rate</option></select></label> ';
        echo '<label>Record ID <input type="number" min="1" id="mad4b-rate-hybrid-target" value="'.esc_attr($id?:'').'" /></label> ';
        echo '<button type="button" class="button" id="mad4b-rate-hybrid-run">Preview inheritance</button>';
        echo '<div id="mad4b-rate-hybrid-results" role="status" aria-live="polite">Choose a Tour or Rate, then preview its inherited Draft policy references.</div>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-policy-bindings')).'">Configure precedence</a> · ';
        echo '<a href="'.esc_url(admin_url('admin.php?page=mad4b-pricing-lab')).'">Open Pricing Lab</a></p>';
        echo '</section>';
    }
    public static function assets():void {
        if(!self::permitted()
          ||sanitize_key((string)($_GET['page']??''))!=='wc-settings'
          ||sanitize_key((string)($_GET['tab']??''))!=='rate_generation')return;
        $dir=__DIR__.'/assets/';
        wp_enqueue_script('mad4b-rate-hybrid-bridge',
            plugins_url('assets/hybrid-rate-generator.js',__FILE__),[],
            (string)filemtime($dir.'hybrid-rate-generator.js'),true);
        wp_add_inline_script('mad4b-rate-hybrid-bridge',
            'window.MAD4BHybridRateGenerator='.wp_json_encode([
                'endpoint'=>admin_url('admin-ajax.php'),
                'action'=>self::AJAX,
                'nonce'=>wp_create_nonce(self::AJAX),
            ]).';','before');
        wp_enqueue_style('mad4b-rate-hybrid-bridge',
            plugins_url('assets/hybrid-rate-generator.css',__FILE__),[],
            (string)filemtime($dir.'hybrid-rate-generator.css'));
    }
    public static function ajax():void {
        if(!self::permitted())
            wp_send_json_error(['message'=>'Company pricing permission required'],403);
        check_ajax_referer(self::AJAX,'nonce');
        $kind=sanitize_key((string)($_POST['kind']??'tour'));
        $id=absint($_POST['record_id']??0);
        if($id<1||!in_array($kind,['rate','tour'],true))
            wp_send_json_error(['message'=>'Choose a valid Tour or Rate'],400);
        if($kind==='tour'){
            if(get_post_type($id)!=='tours-and-activities')
                wp_send_json_error(['message'=>'Tour not found'],404);
            $result=self::inspect('generator_ui','tour',$id);
        }else{
            if(get_post_type($id)!=='tour-rates')
                wp_send_json_error(['message'=>'Tour Rate not found'],404);
            $parent=(int)get_post_meta($id,'related_tour_id',true);
            $result=self::inspect('generator_ui','tour',$parent,$id);
        }
        if(($result['status']??'')!=='ready')
            wp_send_json_error(['message'=>'Rate inheritance unavailable',
                'reason'=>$result['status']??'unknown'],409);
        wp_send_json_success($result);
    }
}
