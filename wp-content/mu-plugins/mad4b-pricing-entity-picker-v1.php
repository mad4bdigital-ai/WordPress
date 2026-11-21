<?php
/**
 * Plugin Name: MAD4B Native JetEngine Pricing Entity Picker
 * Version: 1.0.0
 * Description: Authorized AJAX entity lookup for original JetEngine policy input IDs; no shadow fields or saves.
 */
defined('ABSPATH') || exit;

final class MAD4B_Pricing_Entity_Picker_V1 {
    public const ACTION = 'mad4b_pricing_entity_picker_v1';
    public static function authorized():bool {
        return current_user_can('commerce_manage_pricing')
            && current_user_can('manage_woocommerce');
    }
    /** @return array|WP_Error */
    public static function search(string $kind,string $q,int $limit=15) {
        if(!self::authorized())return new WP_Error('pricing_picker_forbidden');
        $q=trim(sanitize_text_field($q));
        if(strlen($q)<2 || strlen($q)>80)
            return new WP_Error('pricing_picker_search_length');
        $limit=max(1,min(20,$limit));
        $taxonomies=[
            'package'=>'package_levels','tier'=>'group-size-tiers',
            'guide'=>'guide-languages',
        ];
        if(isset($taxonomies[$kind])){
            $taxonomy=$taxonomies[$kind];
            $args=['taxonomy'=>$taxonomy,'hide_empty'=>false,
                'number'=>$limit,'orderby'=>'name','order'=>'ASC'];
            if(ctype_digit($q))$args['include']=[(int)$q];
            else $args['search']=$q;
            $terms=get_terms($args);
            if(is_wp_error($terms))return $terms;
            $rows=[];
            foreach((array)$terms as $term){
                if(!($term instanceof WP_Term))continue;
                $canonical=apply_filters('wpml_object_id',$term->term_id,
                    $taxonomy,false,'en');
                $canonical=is_numeric($canonical) && (int)$canonical>0
                    ?(int)$canonical:(int)$term->term_id;
                $rows[]=[
                    'id'=>(int)$term->term_id,'name'=>(string)$term->name,
                    'type'=>$kind,'canonical_id'=>$canonical,
                    'slug'=>(string)$term->slug,'status'=>$taxonomy==='package_levels'
                        ?((string)get_term_meta($canonical,'package_pricing_enabled',true)
                            ==='1'?'eligible_term_gate':'review_term_gate')
                        :'taxonomy_term',
                ];
            }
            return $rows;
        }
        $posts=['tour'=>'tours-and-activities','rate'=>'tour-rates'];
        if(!isset($posts[$kind]))return new WP_Error('pricing_picker_kind_invalid');
        $args=['post_type'=>$posts[$kind],
            'post_status'=>['publish','draft'],
            'posts_per_page'=>$limit,'fields'=>'ids',
            'orderby'=>'title','order'=>'ASC'];
        if(ctype_digit($q))$args['post__in']=[(int)$q];
        else $args['s']=$q;
        $ids=get_posts($args);$rows=[];
        foreach($ids as $id){
            $post=get_post((int)$id);
            if(!($post instanceof WP_Post))continue;
            $lang=apply_filters('wpml_post_language_details',null,$post->ID);
            $canonical=apply_filters('wpml_object_id',$post->ID,$post->post_type,false,'en');
            $rows[]=[
                'id'=>(int)$post->ID,'name'=>get_the_title($post),
                'type'=>$kind,'status'=>$post->post_status,
                'canonical_id'=>is_numeric($canonical)?(int)$canonical:(int)$post->ID,
                'language'=>is_array($lang)?sanitize_key((string)($lang['language_code']??'')):'',
                'related_tour_id'=>$kind==='rate'?
                    (int)get_post_meta($post->ID,'related_tour_id',true):0,
            ];
        }
        return $rows;
    }
    public static function ajax():void {
        if(!self::authorized())wp_send_json_error(['reason'=>'Forbidden'],403);
        if(!check_ajax_referer(self::ACTION,'nonce',false))
            wp_send_json_error(['reason'=>'Invalid nonce'],403);
        $kind=sanitize_key((string)wp_unslash($_POST['kind']??''));
        $q=sanitize_text_field((string)wp_unslash($_POST['q']??''));
        $results=self::search($kind,$q);
        if(is_wp_error($results))
            wp_send_json_error(['reason'=>$results->get_error_code()],422);
        wp_send_json_success(['results'=>$results,
            'write_authority'=>'original_jetengine_input_only',
            'save_performed'=>false]);
    }
    public static function enqueue():void {
        $page=sanitize_key((string)($_GET['page']??''));
        if(!self::authorized()||
            !in_array($page,['dynamic-pricing-controls',
                'etg-dynamic-pricing-controls'],true))return;
        if(!wp_script_is('mad4b-jetengine-functional-modes','registered'))return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-pricing-entity-picker-v1.js';
        if(!is_readable($file))return;
        wp_enqueue_script('mad4b-pricing-entity-picker-v1',
            content_url('mu-plugins/assets/mad4b-pricing-entity-picker-v1.js'),
            ['mad4b-jetengine-functional-modes'],(string)filemtime($file),true);
        wp_add_inline_style('mad4b-jetengine-functional-modes', '
          .mad4b-native-entity-picker{margin:8px 0;padding:6px 9px;border:1px solid #dcdcde;border-radius:6px;background:#f9fafb;max-width:100%;box-sizing:border-box}
          .mad4b-native-entity-picker summary{cursor:pointer;font-size:12px;font-weight:600;line-height:1.5}
          .mad4b-native-entity-picker-panel{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:8px}
          .mad4b-native-entity-picker-panel>input[type=search]{width:min(100%,280px);min-width:180px;flex:1}
          .mad4b-native-picker-status{font-size:12px;margin:0;width:100%}
          .mad4b-native-picker-results{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:6px;width:100%}
          .mad4b-native-picker-choice{display:flex;flex-direction:column;align-items:flex-start;gap:3px;padding:8px 10px;border:1px solid #c3c4c7;border-radius:5px;background:white;color:#1d2327;cursor:pointer;text-align:start;min-width:0}
          .mad4b-native-picker-choice strong{font-size:12px;overflow-wrap:anywhere}
          .mad4b-native-picker-choice small{font-size:11px;color:#50575e}
          .mad4b-native-picker-choice:hover,.mad4b-native-picker-choice:focus-visible{border-color:#2271b1;outline:2px solid #2271b1;outline-offset:1px}
          @media(max-width:600px){.mad4b-native-picker-results{grid-template-columns:1fr}.mad4b-native-entity-picker-panel>input[type=search]{width:100%}}
        ');
        $conditionalFile=WPMU_PLUGIN_DIR.'/assets/mad4b-pricing-mode-conditional-v1.js';
        if(is_readable($conditionalFile)){
            wp_enqueue_script('mad4b-pricing-mode-conditional-v1',
                content_url('mu-plugins/assets/mad4b-pricing-mode-conditional-v1.js'),
                ['mad4b-jetengine-functional-modes'],
                (string)filemtime($conditionalFile),true);
            wp_add_inline_style('mad4b-jetengine-functional-modes', '
                .mad4b-financial-disclosure{grid-column:1/-1;display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 10px;border:1px solid #ddd;border-radius:5px;background:#f6f7f7}
                .mad4b-financial-disclosure-note{font-size:12px;color:#50575e}
                .jet-engine-options-page-wrap.mad4b-ws-visual .cx-control[data-control-name="shared_mode_policy_rows"] .cx-ui-repeater-item-control.mad4b-pricing-financial-hidden{display:none!important}
                .jet-engine-options-page-wrap.mad4b-ws-native .mad4b-financial-disclosure{display:none!important}
            ');
        }
        wp_add_inline_script('mad4b-pricing-entity-picker-v1',
            'window.MAD4BPricingEntityPicker='.wp_json_encode([
                'url'=>admin_url('admin-ajax.php'),
                'action'=>self::ACTION,'nonce'=>wp_create_nonce(self::ACTION)
            ]).';','before');
    }
}
add_action('wp_ajax_'.MAD4B_Pricing_Entity_Picker_V1::ACTION,
    [MAD4B_Pricing_Entity_Picker_V1::class,'ajax']);
add_action('admin_enqueue_scripts',
    [MAD4B_Pricing_Entity_Picker_V1::class,'enqueue'],69);
