<?php
/**
 * Plugin Name: MAD4B Native Property Rate Scope UI
 * Description: Views and draft shortcuts for the original JetEngine property-rates CPT. No additional post type or price store.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Property_Rate_Scope_UI_V1',false)){
final class MAD4B_Property_Rate_Scope_UI_V1 {
    public const FILTER='mad4b_rate_scope_filter';
    public const CREATE='mad4b_property_rate_create_draft';
    private static function allowed():bool {
        return current_user_can('commerce_manage_pricing') &&
            current_user_can('edit_commerce_rates');
    }
    public static function requestedScope():string {
        $raw=isset($_GET[self::FILTER])&&is_string($_GET[self::FILTER])
            ?sanitize_key(wp_unslash($_GET[self::FILTER])):'';
        return in_array($raw,['property','unit'],true)?$raw:'';
    }
    public static function views(array $views):array {
        if(!self::allowed())return $views;
        $active=self::requestedScope();
        foreach(['property'=>'Property Standard Rates','unit'=>'Unit-Specific Rates'] as $scope=>$label){
            $url=add_query_arg([self::FILTER=>$scope,'paged'=>false],
                admin_url('edit.php?post_type=property-rates'));
            $views['mad4b_'.$scope]='<a href="'.esc_url($url).'"'.
                ($active===$scope?' class="current" aria-current="page"':'').
                '>'.esc_html($label).'</a>';
        }
        return $views;
    }
    public static function toolbar(string $postType,string $which='top'):void {
        if($postType!=='property-rates'||$which!=='top'||!self::allowed())return;
        $scope=self::requestedScope();
        echo '<label class="screen-reader-text" for="mad4b-rate-scope-select">Rate Scope</label>';
        echo '<select name="'.esc_attr(self::FILTER).'" id="mad4b-rate-scope-select" aria-label="Filter by Rate Scope">';
        foreach([''=>'All Rate scopes','property'=>'Property Standard (no Unit)','unit'=>'Unit-Specific'] as $value=>$label){
            echo '<option value="'.esc_attr($value).'"'.selected($scope,$value,false).'>'.esc_html($label).'</option>';
        }
        echo '</select>';
    }
    public static function filterQuery($query):void {
        if(!is_admin()||!self::allowed()||!$query->is_main_query()||
            $query->get('post_type')!=='property-rates')return;
        $scope=self::requestedScope();if($scope==='')return;
        $existing=(array)$query->get('meta_query');
        if($scope==='property'){
            $condition=['relation'=>'AND',
                ['key'=>'mad4b_rate_scope_v1','value'=>'property'],
                ['relation'=>'OR',
                    ['key'=>'prop_related_unit_id','compare'=>'NOT EXISTS'],
                    ['key'=>'prop_related_unit_id','value'=>'0','compare'=>'=']]];
        }else{
            $condition=['key'=>'prop_related_unit_id','value'=>0,
                'type'=>'NUMERIC','compare'=>'>'];
        }
        $query->set('meta_query',$existing?
            ['relation'=>'AND',$existing,$condition]:[$condition]);
    }
    public static function columns(array $columns):array {
        if(!self::allowed())return $columns;
        $columns['mad4b_rate_scope']='Rate Scope';
        $columns['mad4b_rate_native_links']='Property / Unit';
        $columns['mad4b_rate_source_role']='Amount role / Sale';
        return $columns;
    }
    private static function editLink(int $id,string $name):string {
        if($id<=0||!current_user_can('edit_post',$id))return esc_html($name);
        $link=get_edit_post_link($id,'raw');
        return $link?'<a href="'.esc_url($link).'">'.esc_html($name).'</a>':esc_html($name);
    }
    public static function column(string $column,int $id):void {
        if(!self::allowed())return;
        $property=(int)get_post_meta($id,'prop_related_property_id',true);
        $unit=(int)get_post_meta($id,'prop_related_unit_id',true);
        $scope=(string)get_post_meta($id,'mad4b_rate_scope_v1',true);
        if($column==='mad4b_rate_scope'){
            if($unit>0)echo '<strong>Unit-Specific Rate</strong>'.
                ($scope!=='unit'?'<p><small>Scope metadata needs review</small></p>':'');
            elseif($scope==='property')echo '<strong>Property Standard Rate</strong><p><small>No Unit required</small></p>';
            else echo '<strong>Unclassified</strong><p><small>Confirm original scope</small></p>';
        }elseif($column==='mad4b_rate_native_links'){
            echo $property>0?self::editLink($property,get_the_title($property)):'No linked Property';
            if($unit>0)echo '<p>Unit: '.self::editLink($unit,get_the_title($unit)).'</p>';
        }elseif($column==='mad4b_rate_source_role'){
            $kind=(string)get_post_meta($id,'mad4b_rate_price_kind_v1',true);
            echo esc_html($kind==='cost'?'Cost reference (verify)':($kind==='sell'?'Selling Rate':'Role not classified'));
            echo '<p><small>'.esc_html((string)get_post_meta($id,'base_amount',true).' '.
                (string)get_post_meta($id,'currency',true).' / '.
                (string)get_post_meta($id,'prop_price_unit',true)).'</small></p>';
            echo '<small>'.((string)get_post_meta($id,'stop_sale',true)==='0'
                ?'Stop Sale: Off':'Stop Sale: On or unverified').'</small>';
        }
    }
    /** Native JetEngine relation 223 is the sole authority; never accept an arbitrary URL pairing. */
    public static function unitParent(int $unit):int {
        if($unit<=0||get_post_type($unit)!=='units')return 0;
        global $wpdb;
        if(!isset($wpdb)||empty($wpdb->prefix))return 0;
        $table=$wpdb->prefix.'jet_rel_default';
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT parent_object_id FROM {$table} WHERE rel_id=%d AND child_object_id=%d LIMIT 3",
            223,$unit));
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)$ids))));
        return count($ids)===1&&get_post_type($ids[0])==='properties'?$ids[0]:0;
    }
    public static function box(WP_Post $post):void {
        if(!self::allowed()||!current_user_can('edit_post',$post->ID))return;
        $unit=$post->post_type==='units'?(int)$post->ID:0;
        $property=$unit?self::unitParent($unit):(int)$post->ID;
        if(!$property||get_post_type($property)!=='properties'||
           !current_user_can('edit_post',$property)){
            echo '<p>Link this Unit to exactly one native Property (JetEngine relation 223) before creating a Unit-Specific Rate. Property Standard Rates do not need Units.</p>';
            return;
        }
        $scope=$unit?'unit':'property';
        echo '<p><strong>'.esc_html($unit?'Unit-Specific Rate':'Property Standard Rate').'</strong></p>';
        echo '<p>'.esc_html($unit?'Optional room/cabin override.':'Default Rate without a Unit.').
            ' New Rate starts as Draft with Stop Sale active, without any amount or payment authorization.</p>';
        // The WordPress post editor already contains a form. Nested forms are
        // invalid HTML and could save the Property instead of creating a Draft.
        $nonce=wp_create_nonce(self::CREATE.':'.$property.':'.$unit);
        echo '<p><button type="button" class="button button-secondary mad4b-rate-draft-create"'.
            ' data-property="'.esc_attr((string)$property).'"'.
            ' data-unit="'.esc_attr((string)$unit).'"'.
            ' data-nonce="'.esc_attr($nonce).'">'.
            esc_html($unit?'Create Unit Rate Draft':'Create Standard Property Rate Draft').'</button></p>';
        echo '<p class="mad4b-rate-draft-status" role="status" aria-live="polite"></p>';
        echo '<p><a href="'.esc_url(admin_url('edit.php?post_type=property-rates&'.self::FILTER.'='.$scope)).
            '">View '.esc_html($unit?'Unit-Specific Rates':'Property Standard Rates').'</a></p>';
    }
    public static function metaboxes():void {
        foreach(['properties','units'] as $type)
            add_action('add_meta_boxes_'.$type,static function($post)use($type):void {
                if(!$post instanceof WP_Post||!self::allowed())return;
                add_meta_box('mad4b-native-rate-draft-shortcut','Native Rate · '.
                    ($type==='units'?'Unit-Specific':'Property Standard'),
                    [self::class,'box'],$type,'side','default');
            });
    }
    public static function createDraft():void {
        if(!self::allowed())wp_send_json_error(['code'=>'rate_create_forbidden'],403);
        if(!isset($_POST['property_id'],$_POST['unit_id']))
            wp_send_json_error(['code'=>'rate_identity_missing'],422);
        $property=absint(wp_unslash($_POST['property_id']));
        $unit=absint(wp_unslash($_POST['unit_id']));
        if(!check_ajax_referer(self::CREATE.':'.$property.':'.$unit,'nonce',false))
            wp_send_json_error(['code'=>'rate_nonce_invalid'],403);
        if($property<=0||get_post_type($property)!=='properties'||
           !current_user_can('edit_post',$property))
            wp_send_json_error(['code'=>'rate_property_invalid'],422);
        if($unit>0&&(!current_user_can('edit_post',$unit)||self::unitParent($unit)!==$property))
            wp_send_json_error(['code'=>'rate_unit_parent_relation_invalid'],422);
        $scope=$unit?'unit':'property';
        $title=($unit?'Unit-Specific Rate':'Property Standard Rate').' — '.
            wp_strip_all_tags(get_the_title($unit?:$property));
        $id=wp_insert_post(['post_type'=>'property-rates','post_status'=>'draft',
            'post_title'=>$title,'post_author'=>get_current_user_id()],true);
        if(is_wp_error($id)||$id<=0)wp_send_json_error(['code'=>'rate_draft_creation_failed'],500);
        add_post_meta($id,'prop_related_property_id',$property,true);
        add_post_meta($id,'prop_related_unit_id',$unit,true);
        add_post_meta($id,'mad4b_rate_scope_v1',$scope,true);
        add_post_meta($id,'stop_sale','1',true);
        add_post_meta($id,'avilabile_status','0',true);
        // Leave source amount, supplier cost, dates, inventory, margin and selling authority unset.
        wp_send_json_success(['post_id'=>$id,'edit_url'=>get_edit_post_link($id,'raw'),
            'post_status'=>'draft','price_written'=>false,'checkout_ready'=>false],201);
    }
    public static function footer():void {
        if(!self::allowed())return;
        $screen=get_current_screen();
        if(!$screen||!in_array($screen->post_type,['properties','units'],true))return;
        $endpoint=wp_json_encode(admin_url('admin-ajax.php'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        echo '<script>(()=>{const endpoint='.$endpoint.';'.
        'document.addEventListener("click",async event=>{'.
        'const button=event.target.closest("button.mad4b-rate-draft-create");if(!button)return;'.
        'const status=button.closest(".inside")?.querySelector(".mad4b-rate-draft-status");'.
        'button.disabled=true;if(status)status.textContent="Creating unpriced Draft…";'.
        'try{const body=new URLSearchParams({action:"'.self::CREATE.'",'.
        'property_id:button.dataset.property,unit_id:button.dataset.unit,nonce:button.dataset.nonce});'.
        'const response=await fetch(endpoint,{method:"POST",credentials:"same-origin",'.
        'headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()});'.
        'const json=await response.json();'.
        'if(!response.ok||!json?.success||!json.data?.edit_url)throw new Error(json?.data?.code||"draft_creation_failed");'.
        'window.location.assign(json.data.edit_url);'.
        '}catch(error){if(status)status.textContent="Unable to create Draft: "+String(error.message||error);button.disabled=false;}'.
        '});})();</script>';
    }
    public static function boot():void {
        add_filter('views_edit-property-rates',[self::class,'views'],40);
        add_action('restrict_manage_posts',[self::class,'toolbar'],50,2);
        add_action('pre_get_posts',[self::class,'filterQuery'],50);
        add_filter('manage_property-rates_posts_columns',[self::class,'columns'],50);
        add_action('manage_property-rates_posts_custom_column',[self::class,'column'],50,2);
        add_action('admin_init',[self::class,'metaboxes']);
        add_action('wp_ajax_'.self::CREATE,[self::class,'createDraft']);
        add_action('admin_footer-post.php',[self::class,'footer']);
        add_action('admin_footer-post-new.php',[self::class,'footer']);
    }
}
MAD4B_Property_Rate_Scope_UI_V1::boot();
}
