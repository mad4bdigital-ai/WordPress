<?php
/**
 * Plugin Name: MAD4B Accommodation Pricing — Native Controls Panel
 * Description: AJAX property/unit selector inside the native JetEngine Pricing Controls page; read-only commercial preview.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Stay_Pricing_Controls_Panel_V1', false)) {
final class MAD4B_Stay_Pricing_Controls_Panel_V1 {
    const AJAX_ACTION='mad4b_stay_pricing_controls_v1';
    const TYPES=[
        'property-types'=>'Property type',
        'destinations'=>'Destination',
        'stars'=>'Star category',
        'good-for'=>'Good for',
        'amenities'=>'Amenities',
    ];
    const PAGE_SLUGS=['dynamic-pricing-controls','etg-dynamic-pricing-controls'];
    private static function numeric_id($raw):int {
        if(!is_scalar($raw)||is_bool($raw))return 0;
        $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $id===false?0:(int)$id;
    }
    public static function permitted():bool {
        if (class_exists('MAD4B_JetEngine_Pricing_Controls_Access_V1'))
            return MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized();
        return current_user_can('commerce_manage_pricing')
            && (current_user_can('manage_woocommerce') ||
              (current_user_can('mad4b_manage_company_operations') && current_user_can('mad4b_work_company_tasks')));
    }
    private static function can_property(int $property):bool {
        return self::permitted() && get_post_type($property)==='properties'
            && get_post_status($property)==='publish' && current_user_can('edit_post',$property);
    }
    private static function input_text($value,int $max=90):string {
        return sanitize_text_field(substr(is_string($value)?$value:'',0,$max));
    }
    private static function taxonomy_allowed(string $taxonomy):bool {
        return isset(self::TYPES[$taxonomy])
            && taxonomy_exists($taxonomy) && is_object_in_taxonomy('properties',$taxonomy);
    }
    public static function facets():array {
        $out=[];
        foreach(self::TYPES as $taxonomy=>$label){
            if(!self::taxonomy_allowed($taxonomy))continue;
            $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>true,'orderby'=>'count',
                'order'=>'DESC','number'=>75]);
            $options=[];
            if(!is_wp_error($terms))foreach($terms as $term){
                if(!($term instanceof WP_Term))continue;
                $options[]=['id'=>(int)$term->term_id,'label'=>html_entity_decode($term->name,ENT_QUOTES,'UTF-8'),
                    'count'=>(int)$term->count];
            }
            $out[]=['taxonomy'=>$taxonomy,'label'=>$label,'options'=>$options,
                'searchable'=>true];
        }
        $boards=get_terms(['taxonomy'=>'board-type','hide_empty'=>false]);
        $meal=[];
        if(!is_wp_error($boards))foreach($boards as $term)
            if($term instanceof WP_Term)$meal[]=['id'=>$term->slug,
                'label'=>html_entity_decode($term->name,ENT_QUOTES,'UTF-8')];
        $unitTypes=get_terms(['taxonomy'=>'unit-type','hide_empty'=>false,'number'=>75]);
        $unitOptions=[];
        if(!is_wp_error($unitTypes))foreach($unitTypes as $term)
            if($term instanceof WP_Term)$unitOptions[]=['id'=>(int)$term->term_id,
                'label'=>html_entity_decode($term->name,ENT_QUOTES,'UTF-8')];
        return ['properties'=>$out,'unit_types'=>$unitOptions,'boards'=>$meal,
            'provider'=>'self','mode'=>'read_only','price_storage'=>'native'];
    }
    public static function term_search(array $input) {
        $taxonomy=self::input_text($input['taxonomy']??'',45);
        if(!self::taxonomy_allowed($taxonomy))return new WP_Error('stay_panel_taxonomy_not_allowed');
        $q=self::input_text($input['search']??'',65);
        if(strlen($q)<2)return ['taxonomy'=>$taxonomy,'options'=>[]];
        $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>true,
            'search'=>$q,'number'=>75,'orderby'=>'count','order'=>'DESC']);
        if(is_wp_error($terms))return $terms;
        $items=[];
        foreach($terms as $term)
            if($term instanceof WP_Term)$items[]=['id'=>(int)$term->term_id,
                'label'=>html_entity_decode($term->name,ENT_QUOTES,'UTF-8'),
                'count'=>(int)$term->count];
        return ['taxonomy'=>$taxonomy,'options'=>$items];
    }
    private static function classification(int $post,string $taxonomy):array {
        $terms=get_the_terms($post,$taxonomy);
        if(is_wp_error($terms)||!is_array($terms))return [];
        return array_values(array_slice(array_map(static function($term) {
            return ['id'=>(int)$term->term_id,'label'=>html_entity_decode($term->name,ENT_QUOTES,'UTF-8')];
        },$terms),0,4));
    }
    private static function price_status(int $id,string $key):string {
        $v=get_post_meta($id,$key,true);
        return is_numeric($v)&&(float)$v>0?'entered':'not_configured';
    }
    public static function properties(array $input) {
        // Authenticated admin catalog: keep company inventory visible even when
        // public service channels are disabled (front-end pre_get_posts guard).
        // Every returned post still requires publish status and edit_post.
        if(!self::permitted())return new WP_Error('stay_panel_forbidden');
        $page=min(40,max(1,absint($input['paged']??1)));
        $termFilters=$input['filters']??[];
        if(!is_array($termFilters)||count($termFilters)>count(self::TYPES))
            return new WP_Error('stay_panel_filters_invalid');
        $clauses=['relation'=>'AND'];
        foreach($termFilters as $taxonomy=>$rawId){
            if(!is_string($taxonomy)||!self::taxonomy_allowed($taxonomy))
                return new WP_Error('stay_panel_filter_invalid');
            if($rawId===''||$rawId===null)continue;
            $termId=self::numeric_id($rawId);
            if(!$termId)return new WP_Error('stay_panel_term_invalid');
            $term=get_term($termId,$taxonomy);
            if(is_wp_error($term)||!($term instanceof WP_Term))
                return new WP_Error('stay_panel_term_unknown');
            $clauses[]=['taxonomy'=>$taxonomy,'field'=>'term_id',
                'terms'=>[$termId],'operator'=>'IN','include_children'=>true];
        }
        $args=['post_type'=>'properties','post_status'=>'publish','posts_per_page'=>12,
            'paged'=>$page,'s'=>self::input_text($input['search']??'',90),
            'orderby'=>'title','order'=>'ASC','fields'=>'ids',
            'ignore_sticky_posts'=>true,'suppress_filters'=>true,
            'update_post_meta_cache'=>false,'update_post_term_cache'=>false];
        if(count($clauses)>1)$args['tax_query']=$clauses;
        $query=new WP_Query($args);
        $items=[];
        foreach($query->posts as $postId){
            $id=(int)$postId;
            if(!current_user_can('edit_post',$id))continue;
            $meal=class_exists('MAD4B_Hotel_Meal_Base_Policy_V1')
                ?MAD4B_Hotel_Meal_Base_Policy_V1::resolve($id):null;
            $items[]=['id'=>$id,'title'=>html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8'),
                'type'=>self::classification($id,'property-types'),
                'destinations'=>self::classification($id,'destinations'),
                'stars'=>self::classification($id,'stars'),
                'meal_base'=>is_array($meal)?(string)($meal['base_board']??''):'',
                'direct_price'=>self::price_status($id,'prop_start_price'),
                'edit_url'=>get_edit_post_link($id,''),
            ];
        }
        return ['items'=>$items,'page'=>$page,'pages'=>(int)$query->max_num_pages,
            'found'=>(int)$query->found_posts,'read_only'=>true];
    }
    public static function units(array $input) {
        $property=self::numeric_id($input['property_id']??0);
        if(!$property||!self::can_property($property))return new WP_Error('stay_panel_property_forbidden');
        if(!function_exists('abk_entity_map'))return new WP_Error('stay_panel_relation_missing');
        $map=abk_entity_map();
        if(is_wp_error($map))return $map;
        $page=min(40,max(1,absint($input['paged']??1)));
        $termId=empty($input['unit_type'])?0:self::numeric_id($input['unit_type']);
        if(!empty($input['unit_type'])&&!$termId)return new WP_Error('stay_panel_unit_type_invalid');
        if($termId){
            $term=get_term($termId,'unit-type');
            if(!($term instanceof WP_Term))return new WP_Error('stay_panel_unit_type_unknown');
        }
        global $wpdb;
        $table=$wpdb->prefix.$map['relation_table_suffix'];
        $idRows=$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT r.child_object_id FROM {$table} r
              INNER JOIN {$wpdb->posts} p ON p.ID=r.child_object_id
              WHERE r.rel_id=%d AND r.parent_object_id=%d
              AND p.post_type=%s AND p.post_status=%s
              ORDER BY r.child_object_id DESC LIMIT 501",
            (int)$map['relation_id'],$property,'units','publish'));
        if(count($idRows)>500)return new WP_Error('stay_panel_unit_result_limit');
        if(!$idRows)return ['items'=>[],'page'=>1,'pages'=>0,'found'=>0,'property_id'=>$property];
        $args=['post_type'=>'units','post_status'=>'publish','post__in'=>array_map('intval',$idRows),
            'posts_per_page'=>12,'paged'=>$page,
            'orderby'=>'title','order'=>'ASC','fields'=>'ids',
            'ignore_sticky_posts'=>true,'suppress_filters'=>true,
            'update_post_meta_cache'=>false,'update_post_term_cache'=>false];
        if($termId)$args['tax_query']=[['taxonomy'=>'unit-type','field'=>'term_id','terms'=>[$termId]]];
        $query=new WP_Query($args);
        $items=[];
        foreach($query->posts as $unit){
            $id=(int)$unit;
            if(!current_user_can('edit_post',$id))continue;
            $types=self::classification($id,'unit-type');
            $items[]=['id'=>$id,'title'=>html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8'),
                'types'=>$types,'capacity'=>max(0,(int)get_post_meta($id,'unit_max_occupancy',true)),
                'direct_price'=>self::price_status($id,'unit_start_price'),
                'edit_url'=>get_edit_post_link($id,'')];
        }
        return ['items'=>$items,'page'=>$page,'pages'=>(int)$query->max_num_pages,
            'found'=>(int)$query->found_posts,'property_id'=>$property,'read_only'=>true];
    }
    public static function rates(array $input) {
        $property=self::numeric_id($input['property_id']??0);
        $unit=empty($input['unit_id'])?0:self::numeric_id($input['unit_id']);
        if(!$property||!self::can_property($property))return new WP_Error('stay_panel_property_forbidden');
        if($unit&&!current_user_can('edit_post',$unit))return new WP_Error('stay_panel_unit_forbidden');
        $query=new WP_Query(['post_type'=>'property-rates','post_status'=>['publish','draft','pending'],
            'meta_query'=>[['key'=>'prop_related_property_id','value'=>(string)$property,'compare'=>'=']],
            'posts_per_page'=>41,'fields'=>'ids','orderby'=>'date','order'=>'DESC',
            'no_found_rows'=>true,'suppress_filters'=>true]);
        if(count($query->posts)>40)return new WP_Error('stay_panel_rate_limit');
        $out=[];$counts=['published'=>0,'draft'=>0,'pending'=>0];
        foreach($query->posts as $row){
            $id=(int)$row;$unitId=(int)get_post_meta($id,'prop_related_unit_id',true);
            if($unit && $unitId!==$unit && $unitId!==0)continue;
            $scope=(string)get_post_meta($id,'mad4b_rate_scope_v1',true);
            $kind=$unitId?'unit_rate':($scope==='property'?'property_rate':'unscoped');
            $status=get_post_status($id);
            if($status==='publish')$counts['published']++;
            elseif(isset($counts[$status]))$counts[$status]++;
            $boardId=(int)get_post_meta($id,'prop_board_type',true);
            $boardTerm=$boardId?get_term($boardId,'board-type'):null;
            $out[]=['id'=>$id,'title'=>html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8'),
                'kind'=>$kind,'status'=>$status,'unit_id'=>$unitId?:null,
                'amount'=>(string)get_post_meta($id,'base_amount',true),
                'currency'=>(string)get_post_meta($id,'currency',true),
                'valid_from'=>(string)get_post_meta($id,'valid_from',true),
                'valid_to'=>(string)get_post_meta($id,'valid_to',true),
                'board'=>$boardTerm instanceof WP_Term?$boardTerm->name:'',
                'edit_url'=>current_user_can('edit_post',$id)?get_edit_post_link($id,''):null,
                'qa_only'=>(bool)preg_match('/\bQA\s+ONLY\b/i',(string)get_the_title($id))];
        }
        return ['items'=>$out,'counts'=>$counts,'read_only'=>true,
            'rates_index_url'=>admin_url('edit.php?post_type=property-rates')];
    }
    public static function preview(array $input) {
        $property=self::numeric_id($input['property_id']??0);
        $unit=empty($input['unit_id'])?0:self::numeric_id($input['unit_id']);
        if(!$property||!self::can_property($property))return new WP_Error('stay_panel_property_forbidden');
        if($unit&&!current_user_can('edit_post',$unit))return new WP_Error('stay_panel_unit_forbidden');
        if(!function_exists('mad4b_stay_layered_price_preview_v1'))
            return new WP_Error('stay_panel_resolver_unavailable');
        $payload=['property_id'=>$property,'unit_id'=>$unit];
        foreach(['checkin','nights','adults','children','board_type'] as $key)
            if(array_key_exists($key,$input))$payload[$key]=$input[$key];
        $result=mad4b_stay_layered_price_preview_v1($payload);
        if(is_wp_error($result))return $result;
        return ['quote'=>$result,'edit_property_url'=>get_edit_post_link($property,''),
            'edit_unit_url'=>$unit?get_edit_post_link($unit,''):null,
            'rate_index_url'=>admin_url('edit.php?post_type=property-rates')];
    }
    public static function ajax():void {
        if(!self::permitted())wp_send_json_error(['code'=>'stay_panel_forbidden'],403);
        check_ajax_referer(self::AJAX_ACTION,'nonce');
        $mode=isset($_POST['mode'])&&is_string($_POST['mode'])?sanitize_key(wp_unslash($_POST['mode'])):'';
        $input=[];
        foreach(['property_id','unit_id','unit_type','search','paged','checkin',
            'nights','adults','children','board_type','taxonomy'] as $key)
            if(isset($_POST[$key])&&is_string($_POST[$key]))
                $input[$key]=sanitize_text_field(wp_unslash($_POST[$key]));
        if(isset($_POST['filters'])&&is_string($_POST['filters'])){
            $raw=wp_unslash($_POST['filters']);
            if(strlen($raw)>1600)wp_send_json_error(['code'=>'stay_panel_filter_limit'],400);
            $decoded=json_decode($raw,true);
            if(!is_array($decoded))wp_send_json_error(['code'=>'stay_panel_filters_invalid'],400);
            $input['filters']=$decoded;
        }
        if($mode==='facets')$result=self::facets();
        elseif($mode==='term_search')$result=self::term_search($input);
        elseif($mode==='properties')$result=self::properties($input);
        elseif($mode==='units')$result=self::units($input);
        elseif($mode==='rates')$result=self::rates($input);
        elseif($mode==='preview')$result=self::preview($input);
        else $result=new WP_Error('stay_panel_action_unknown');
        if(is_wp_error($result))wp_send_json_error(['code'=>$result->get_error_code()],400);
        wp_send_json_success($result);
    }
    public static function assets():void {
        $page=isset($_GET['page'])&&is_string($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):'';
        if(!in_array($page,self::PAGE_SLUGS,true)||!self::permitted())return;
        $dir=__DIR__.'/mad4b-stay-pricing-admin/';
        $url=plugin_dir_url(__FILE__).'mad4b-stay-pricing-admin/';
        if(!is_file($dir.'ui.css')||!is_file($dir.'ui.js')||!is_file($dir.'panel.html'))return;
        wp_enqueue_style('mad4b-stay-pricing-inline',$url.'ui.css',[],(string)filemtime($dir.'ui.css'));
        wp_enqueue_script('mad4b-stay-pricing-inline',$url.'ui.js',[],
            (string)filemtime($dir.'ui.js'),true);
        wp_add_inline_script('mad4b-stay-pricing-inline','window.MAD4BStayPricingUI='.wp_json_encode([
            'ajax_url'=>admin_url('admin-ajax.php'),'action'=>self::AJAX_ACTION,
            'nonce'=>wp_create_nonce(self::AJAX_ACTION),
            'template'=>file_get_contents($dir.'panel.html'),
            'rates_url'=>admin_url('edit.php?post_type=property-rates'),
            'fallback_url'=>admin_url('admin.php?page=mad4b-stay-price-layers'),
        ]).';','before');
    }
    public static function boot():void {
        add_action('wp_ajax_'.self::AJAX_ACTION,[__CLASS__,'ajax']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],70);
    }
}
MAD4B_Stay_Pricing_Controls_Panel_V1::boot();
}
