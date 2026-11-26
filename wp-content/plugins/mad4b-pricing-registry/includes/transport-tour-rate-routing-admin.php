<?php
/**
 * Tour Rate editor route-candidate advisor.
 *
 * Native JetEngine fields persist the Tour Rate assignment. This read-only
 * interface supplies stage-matched, opt-in PDF candidates and confidence/
 * blockers. It never mutates Rates or source post status.
 */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Tour_Rate_Routing_Admin_V1 {
    private const HANDLE='mad4b-transport-tour-rate-route-admin';
    public static function permitted(int $postId):bool {
        return MAD4B_Transport_Tour_Rate_Routing_V1::permitted() &&
            $postId>0 && get_post_type($postId)==='tour-rates' &&
            current_user_can('edit_post',$postId);
    }
    public static function register():void {
        global $post;
        if(!($post instanceof WP_Post)||!self::permitted((int)$post->ID))return;
        add_meta_box('mad4b-transport-rate-candidates',
            'Transportation · Destination and Status Candidate Advisor',
            [__CLASS__,'render'],'tour-rates','normal','default');
    }
    private static function choices():array {
        $source=MAD4B_Transport_Tour_Rate_Routing_V1::source();
        if(!$source)return [];
        $slugs=['cairo','aswan-gov','luxor'];
        foreach($source['route']['entries'] as $v)
            foreach($v['dest_slugs'] as $slug)$slugs[]=$slug;
        $slugs=array_values(array_unique($slugs));
        $out=[];
        foreach($slugs as $slug) {
            $t=get_term_by('slug',$slug,'destinations');
            if(!($t instanceof WP_Term))continue;
            $out[]=['term_id'=>(int)$t->term_id,'name'=>(string)$t->name,
                'slug'=>$slug];
        }
        return $out;
    }
    public static function render($post):void {
        $postId=(int)$post->ID;
        if(!self::permitted($postId))return;
        $langDetails=apply_filters('wpml_post_language_details',null,$postId);
        $lang=sanitize_key((string)($langDetails['language_code']??'en'));
        $active=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
        if(!in_array($lang,$active,true))$lang='en';
        $stored=get_post_meta($postId,MAD4B_Transport_Tour_Rate_Routing_V1::META,true);
        if(!is_array($stored)||count($stored)>30)$stored=[];
        $existing=[];
        foreach($stored as $entry){
            if(!is_array($entry))continue;
            $existing[]=[
                'key'=>sanitize_key((string)($entry['key']??'')),
                'label'=>sanitize_text_field((string)($entry['label']??'')),
                'day_offset'=>(int)($entry['day_offset']??0),
                'origin_term_id'=>(int)($entry['origin_term_id']??0),
                'destination_term_id'=>(int)($entry['destination_term_id']??0),
                'join'=>in_array(($entry['join']??''),['OR','AND'],true)?$entry['join']:'OR',
                'required'=>(string)($entry['required']??'1')!=='0',
                'return_to_origin'=>(string)($entry['return_to_origin']??'0')==='1',
                'service_kind'=>sanitize_key((string)($entry['service_kind']??'any')),
                'price_policy'=>sanitize_key((string)($entry['price_policy']??'inquiry')),
                'transport_ids'=>array_values(array_map('absint',is_array($entry['transport_ids']??null)?$entry['transport_ids']:[]))
            ];
        }
        $mode=(string)get_post_meta($postId,'transport_selection_mode',true);
        if(!in_array($mode,['simple','stages'],true))$mode='simple';
        $orig=(int)get_post_meta($postId,'related_origin_destination_term_id',true);
        $config=[
            'route'=>rest_url('mad4b-pricing/v1/transport-tour-rate-candidates'),
            'workflow'=>rest_url('mad4b-pricing/v1/transport-package-workflow'),
            'savedSelection'=>MAD4B_Transport_Package_Workflow_V1::snapshot($postId),
            'nonce'=>wp_create_nonce('wp_rest'),
            'costAjax'=>admin_url('admin-ajax.php'),
            'costAction'=>MAD4B_Transport_Distance_Time_V1::AJAX,
            'costNonce'=>wp_create_nonce(MAD4B_Transport_Distance_Time_V1::AJAX),
            'postId'=>$postId,'lang'=>$lang,'mode'=>$mode,
            'defaultOrigin'=>$orig,'terms'=>self::choices(),'stages'=>$existing,
            'tourRateEditable'=>true,'sourceTrusted'=>true,
            'checkoutAuthorized'=>false
        ];
        echo '<div class="mad4b-tr-route-advisor" data-transport-route-advisor>';
        echo '<p><strong>Source criteria:</strong> Transportation Status = Yes <em>AND</em> origin/destination matching. A PDF city-level match is a draft proposal, not a verified pickup point or a sellable Rate.</p>';
        echo '<p>This panel reads the same native Transportation Stage fields shown in the Tour Rate editor. Stage changes below can be saved to native JetEngine fields for a <strong>Draft Tour Rate</strong> using the guarded save action below; published Rates remain read-only.</p>';
        echo '<div data-tr="controls"></div>';
        echo '<div data-tr="actions"><button type="button" class="button button-primary" data-tr="preview">Find PDF candidates</button> <button type="button" class="button" data-tr="add">Add preview stage</button></div>';
        echo '<div data-tr="results" class="mad4b-tr-results" role="status" aria-live="polite">Choose a stage and check route candidates.</div>';
        echo '<details class="mad4b-tr-cost-bridge"><summary><strong>Transport cost preview · EGP</strong> — selected journeys only</summary>';
        echo '<p>Use the <strong>Find PDF candidates</strong> action first, then choose one candidate for each OR stage (or each required component for AND). Enter real journey distance and driving minutes. Use the guarded Save assignment action to persist selected components to an existing Draft Tour Rate. No Transport Rate is created.</p>';
        echo '<div data-tr="cost-form" class="mad4b-tr-cost-form"></div>';
        echo '<div class="mad4b-tr-cost-buttons"><button type="button" class="button" data-tr="cost-add">Add selected journey</button> <button type="button" class="button button-primary" data-tr="cost-preview">Calculate EGP costs</button> <button type="button" class="button" data-tr="cost-clear">Clear selection</button> <button type="button" class="button button-primary" data-tr="cost-save">Save to Draft Tour Rate</button></div>';
        echo '<div data-tr="cost-selected" class="mad4b-tr-cost-selected">No selected components.</div>';
        echo '<div data-tr="cost-results" class="mad4b-tr-results" role="status" aria-live="polite"></div>';
        echo '<p class="description">Draft Save writes existing JetEngine selection fields and minimal route/vehicle inputs only. It protects against stale tabs, checks WPML and route eligibility, and does not modify published Rates or commercial prices.</p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-rate-generation')).'">Open existing Rate Generator</a> · Generating a complete Tour Rate requires an independently established whole-package price; transport cost alone must never replace accommodation, guide, extras or occupancy pricing.</p>';
        echo '<p class="description">All amounts are non-commercial operating-cost estimates in EGP. Distances, availability, supplier capacity, taxes, published prices and checkout remain unverified. Planning mode never claims a saved Tour Rate relation; Verified mode rejects an unlinked Transportation source.</p></details>';
        echo '<p class="description">A candidate remains blocked from commerce until exact pickup/dropoff points, native Transport Rate, capacity and approval are verified. The source PDF does not supply all these details.</p>';
        echo '<script type="application/json" data-tr="config">'.
            wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).
            '</script></div>';
    }
    public static function assets($hook):void {
        if(!in_array($hook,['post.php','post-new.php'],true))return;
        $postId=(int)($_GET['post']??0);
        if(!$postId||!self::permitted($postId))return;
        $main=dirname(__DIR__).'/mad4b-pricing-registry.php';
        $dir=dirname(__DIR__).'/assets/';
        foreach(['transport-tour-rate-routing-admin.js',
                 'transport-tour-rate-routing-admin.css'] as $file)
           if(!is_file($dir.$file))return;
        wp_enqueue_script(self::HANDLE,
            set_url_scheme(plugins_url('assets/transport-tour-rate-routing-admin.js',$main),'https'),[],
            (string)filemtime($dir.'transport-tour-rate-routing-admin.js'),true);
        wp_enqueue_style(self::HANDLE,
            set_url_scheme(plugins_url('assets/transport-tour-rate-routing-admin.css',$main),'https'),[],
            (string)filemtime($dir.'transport-tour-rate-routing-admin.css'));
    }
    public static function boot():void {
        add_action('add_meta_boxes_tour-rates',[__CLASS__,'register']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],77);
    }
}
MAD4B_Transport_Tour_Rate_Routing_Admin_V1::boot();
