<?php
/** Company-authorized admin UI for read-only inheritance and reference edits. */
defined('ABSPATH')||exit;
final class MAD4B_Hybrid_Pricing_Admin_V1 {
    public const PAGE='mad4b-policy-bindings';
    private const CORE='MAD4B_Hybrid_Pricing_Policies_V1';
    public static function boot():void {
        if(!is_admin())return;
        add_action('admin_menu',[__CLASS__,'menu'],110);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        add_action('wp_ajax_'.MAD4B_Hybrid_Pricing_Policies_V1::AJAX,[__CLASS__,'ajax']);
        add_action('add_meta_boxes',[__CLASS__,'meta_boxes'],25);
        foreach(MAD4B_Hybrid_Pricing_Policies_V1::taxonomies() as $tax)
            add_action($tax.'_edit_form_fields',[__CLASS__,'term_panel'],40,2);
    }
    public static function menu():void {
        if(!MAD4B_Hybrid_Pricing_Policies_V1::permitted())return;
        add_submenu_page(MAD4B_Pricing_Workspace_V1::SLUG,
            'MAD4B Policy Inheritance','Policy Bindings',
            'commerce_manage_pricing',self::PAGE,[__CLASS__,'page']);
    }
    public static function assets():void {
        if(!MAD4B_Hybrid_Pricing_Policies_V1::permitted()
          ||sanitize_key((string)($_GET['page']??''))!==self::PAGE)return;
        $js=__DIR__.'/assets/hybrid-policy-bindings.js';
        wp_enqueue_script('mad4b-hybrid-policy-bindings',
            plugins_url('assets/hybrid-policy-bindings.js',__FILE__),
            [],file_exists($js)?(string)filemtime($js):'1.0',true);
        wp_add_inline_script('mad4b-hybrid-policy-bindings',
            'window.MAD4BPolicyBindings='.wp_json_encode([
                'ajax'=>admin_url('admin-ajax.php'),
                'action'=>MAD4B_Hybrid_Pricing_Policies_V1::AJAX,
                'nonce'=>wp_create_nonce(MAD4B_Hybrid_Pricing_Policies_V1::AJAX),
                'initial_scope'=>in_array(($_GET['scope']??''),['global','term','tour','rate'],true)
                    ?sanitize_key($_GET['scope']):'global',
                'initial_id'=>absint($_GET['target_id']??0)
            ]).';','before');
        $css=__DIR__.'/assets/hybrid-policy-bindings.css';
        wp_enqueue_style('mad4b-hybrid-policy-bindings',
            plugins_url('assets/hybrid-policy-bindings.css',__FILE__),
            [],file_exists($css)?(string)filemtime($css):'1.0');
    }
    public static function page():void {
        if(!MAD4B_Hybrid_Pricing_Policies_V1::permitted())wp_die('Forbidden');
        echo '<div class="wrap"><h1>Pricing Policy Bindings</h1>';
        echo '<p>Central versioned Pricing Lab policies, referenced by native Taxonomy Term Meta and Tour/Rate Meta. Higher-priority references override inherited entries for preview only. Nothing here activates checkout prices, booking policies or campaigns.</p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-pricing-lab')).'">Create a Pricing Lab Draft first</a></p>';
        echo '<div id="mad4b-policy-binding-root"><p>Loading policy reference registry...</p></div></div>';
    }
    public static function meta_boxes():void {
        foreach(['tours-and-activities','tour-rates'] as $type)
            add_meta_box('mad4b-hybrid-pricing-bindings','MAD4B Policy Inheritance',
                [__CLASS__,'post_panel'],$type,'side','default');
    }
    public static function post_panel($post):void {
        if(!MAD4B_Hybrid_Pricing_Policies_V1::permitted())return;
        $scope=$post->post_type==='tour-rates'?'rate':'tour';
        $refs=MAD4B_Hybrid_Pricing_Policies_V1::read($scope,(int)$post->ID);
        echo '<p>'.esc_html(count($refs['ids'])).' central policy reference(s).</p>';
        echo '<p><a href="'.esc_url(add_query_arg([
            'page'=>self::PAGE,'scope'=>$scope,'target_id'=>(int)$post->ID
        ],admin_url('admin.php'))).'">Manage Policy References & Preview Inheritance</a></p>';
        echo '<small>Stored using '.esc_html(MAD4B_Hybrid_Pricing_Policies_V1::POST_META)
            .'. Published Rate prices remain unchanged.</small>';
    }
    public static function term_panel($term,$taxonomy):void {
        if(!MAD4B_Hybrid_Pricing_Policies_V1::permitted())return;
        $refs=MAD4B_Hybrid_Pricing_Policies_V1::read('term',(int)$term->term_id);
        echo '<tr class="form-field"><th scope="row">MAD4B Policy Inheritance</th><td>';
        echo '<p>'.esc_html(count($refs['ids'])).' central policy reference(s).</p>';
        echo '<p><a href="'.esc_url(add_query_arg([
            'page'=>self::PAGE,'scope'=>'term','target_id'=>(int)$term->term_id
        ],admin_url('admin.php'))).'">Manage native Term Meta references</a></p>';
        echo '<p class="description">Stored in '.esc_html(
            MAD4B_Hybrid_Pricing_Policies_V1::TERM_META).'; no duplicated policy body.</p>';
        echo '</td></tr>';
    }
    public static function ajax():void {
        $core=self::CORE;
        if(!$core::permitted())wp_send_json_error(['message'=>'Pricing permission required'],403);
        check_ajax_referer($core::AJAX,'nonce');
        $op=sanitize_key((string)($_POST['operation']??'catalog'));
        if($op==='catalog'){
            $out=[];
            foreach($core::presets() as $p)
                $out[]=['id'=>$p['id'],'title'=>$p['title']??$p['id'],
                    'revision'=>(int)($p['revision']??0),'status'=>$p['status']??'unknown'];
            wp_send_json_success(['policies'=>$out,'taxonomies'=>$core::taxonomies(),
                'meta_keys'=>['term'=>$core::TERM_META,'post'=>$core::POST_META],
                'resolution_config'=>$core::resolution_config(),
                'scope_order'=>$core::RESOLUTION_LEVELS,
                'commercial_activation'=>false]);
        }
        if($op==='save_resolution'){
            if(($_POST['confirm']??'')!=='save_preview_resolution_only')
                wp_send_json_error(['message'=>'Confirm read-only resolution change'],400);
            $raw=isset($_POST['config'])&&is_string($_POST['config'])
                ?wp_unslash($_POST['config']):'';
            if(strlen($raw)>3500)
                wp_send_json_error(['message'=>'Resolution settings too long'],400);
            $input=json_decode($raw,true);
            if(!is_array($input))
                wp_send_json_error(['message'=>'Invalid resolution configuration'],400);
            $r=$core::save_resolution_config($input,absint($_POST['revision']??0));
            if(is_wp_error($r))
                wp_send_json_error(['code'=>$r->get_error_code(),
                    'message'=>$r->get_error_message()?:'Invalid resolver configuration'],409);
            wp_send_json_success($r);
        }
        if($op==='search'){
            $scope=sanitize_key((string)($_POST['scope']??''));
            $query=sanitize_text_field((string)($_POST['q']??''));
            $query=substr($query,0,70);
            $options=[];
            if($scope==='term'){
                $taxonomy=sanitize_key((string)($_POST['taxonomy']??''));
                if(!in_array($taxonomy,$core::taxonomies(),true))
                    wp_send_json_error(['message'=>'Choose a supported taxonomy'],400);
                $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,
                    'number'=>35,'name__like'=>$query]);
                if(!is_wp_error($terms))
                    foreach($terms as $term)$options[]=['id'=>(int)$term->term_id,
                        'label'=>$term->name.' ('.$term->term_id.')'];
            }elseif(in_array($scope,['tour','rate'],true)){
                $type=$scope==='tour'?'tours-and-activities':'tour-rates';
                $posts=get_posts(['post_type'=>$type,
                    'post_status'=>['publish','draft','private','pending'],
                    'posts_per_page'=>35,'s'=>$query,'suppress_filters'=>true]);
                foreach($posts as $post)$options[]=['id'=>(int)$post->ID,
                    'label'=>$post->post_title.' ('.$post->ID.')'];
            }else wp_send_json_error(['message'=>'Unsupported search scope'],400);
            wp_send_json_success(['items'=>$options]);
        }
        $scope=sanitize_key((string)($_POST['scope']??'global'));
        $id=absint($_POST['id']??0);
        if(!in_array($scope,['global','term','tour','rate'],true))
            wp_send_json_error(['message'=>'Invalid policy scope'],400);
        $check=$core::validate_target($scope,$id);
        if(is_wp_error($check))
            wp_send_json_error(['message'=>$check->get_error_message()],400);
        if($op==='read'){
            $r=$core::read($scope,$id);
            if(in_array($scope,['tour','rate'],true))$r['resolution']=$core::resolve($id);
            wp_send_json_success($r);
        }
        if($op==='resolve'){
            if(!in_array($scope,['tour','rate'],true))
                wp_send_json_error(['message'=>'Choose a Tour or Rate'],400);
            wp_send_json_success($core::resolve($id));
        }
        if($op==='save'){
            if(($_POST['confirm']??'')!=='bind_policy_references_only')
                wp_send_json_error(['message'=>'Explicit reference-only confirmation required'],400);
            $raw=isset($_POST['ids'])&&is_string($_POST['ids'])
                ?wp_unslash($_POST['ids']):'';
            if(strlen($raw)>1600)wp_send_json_error(['message'=>'Too many references'],400);
            $refs=json_decode($raw,true);
            if(!is_array($refs))wp_send_json_error(['message'=>'Invalid references'],400);
            $r=$core::bind($scope,$id,$refs,absint($_POST['revision']??0));
            if(is_wp_error($r))wp_send_json_error([
                'code'=>$r->get_error_code(),
                'message'=>$r->get_error_message()?:'Policy binding failed'],409);
            wp_send_json_success($r);
        }
        wp_send_json_error(['message'=>'Unknown operation'],400);
    }
}
