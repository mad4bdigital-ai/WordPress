<?php
/**
 * Plugin Name: MAD4B Stay Commercial Readiness (Native)
 * Description: Read-only operational audit and native shadow-quote handoff for company-owned accommodation.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Stay_Commercial_Readiness_V1', false)) {
final class MAD4B_Stay_Commercial_Readiness_V1 {
    const ACTION='mad4b_stay_commercial_readiness_v1';
    const PAGE_SLUGS=['dynamic-pricing-controls','etg-dynamic-pricing-controls'];
    const LIMIT=20;

    public static function authorized():bool {
        if(class_exists('MAD4B_Stay_Pricing_Controls_Panel_V1',false))
            return MAD4B_Stay_Pricing_Controls_Panel_V1::permitted();
        return current_user_can('commerce_manage_pricing') && current_user_can('edit_posts');
    }
    private static function id($value):int {
        if(!is_scalar($value) || is_bool($value))return 0;
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $id===false?0:(int)$id;
    }
    private static function money($value):bool {
        return is_scalar($value) && !is_bool($value)
            && preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,4})?$/D',(string)$value)
            && (float)$value>0;
    }
    private static function direct(int $id,bool $unit):array {
        $prefix=$unit?'unit':'prop';
        $amount=(string)get_post_meta($id,$unit?'unit_start_price':'prop_start_price',true);
        $currency=strtoupper((string)get_post_meta($id,$prefix.'_base_currency',true));
        $basis=(string)get_post_meta($id,$prefix.'_direct_price_unit',true);
        $kind=(string)get_post_meta($id,$prefix.'_direct_price_kind',true);
        $amountSet=self::money($amount);
        $allowed=['per_room_night','per_cabin_night','per_person_night','per_person','per_stay'];
        $validCurrency=preg_match('/^[A-Z]{3}$/D',$currency);
        if($validCurrency && function_exists('get_woocommerce_currencies'))
            $validCurrency=array_key_exists($currency,(array)get_woocommerce_currencies());
        return ['amount_entered'=>$amountSet,'currency_set'=>(bool)$validCurrency,
            'basis_set'=>in_array($basis,$allowed,true),'price_kind'=>$kind,
            'sell_configured'=>$amountSet && $validCurrency
                && in_array($basis,$allowed,true) && $kind==='sell',
            'internal_cost_only'=>$kind==='cost'];
    }
    private static function links():array {
        if(!function_exists('abk_entity_map'))return [];
        $map=abk_entity_map();
        if(is_wp_error($map)||!isset($map['relation_id'],$map['relation_table_suffix']))return [];
        global $wpdb;
        $table=$wpdb->prefix.$map['relation_table_suffix'];
        // Relation map is only used for editorial inventory, not for quote authorization.
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT parent_object_id property_id,child_object_id unit_id FROM {$table}
             WHERE rel_id=%d LIMIT 3001",(int)$map['relation_id']),ARRAY_A);
        if(count($rows)>3000)return [];
        $out=[];
        foreach($rows as $row)$out[(int)$row['unit_id']][]=(int)$row['property_id'];
        return $out;
    }
    public static function evaluate(int $unit,array $parents,bool $withLinks=false):array {
        $status=get_post_status($unit);
        $parentIds=array_values(array_unique(array_map('intval',$parents)));
        $property=count($parentIds)===1?$parentIds[0]:0;
        $pstatus=$property?get_post_status($property):'missing';
        $kind=(string)get_post_meta($unit,'unit_kind',true);
        $capacity=(int)get_post_meta($unit,'unit_max_occupancy',true);
        $inventory=(int)get_post_meta($unit,'unit_inventory_quantity',true);
        $readiness=(string)get_post_meta($unit,'unit_readiness',true);
        $available=(string)get_post_meta($unit,'unit_available',true);
        $gallery=get_post_meta($unit,'unit_gellery',true);
        $hasImage=(int)get_post_thumbnail_id($unit)>0
            || (is_string($gallery)&&trim($gallery)!=='')
            || (is_array($gallery)&&count($gallery)>0);
        $title=(string)get_the_title($unit);
        $qa=(bool)preg_match('/\b(QA\s+ONLY|fixture|demo|test)\b/i',$title)
            || in_array((string)get_post_meta($unit,'_mad4b_is_test_data',true),['1','yes','true'],true);
        $catalog=[];
        if(count($parentIds)!==1)$catalog[]='property_relation_unverified';
        if($pstatus!=='publish')$catalog[]='parent_property_unpublished';
        if($qa)$catalog[]='qa_or_test_unit';
        if(!in_array($kind,['room','suite','cabin'],true))$catalog[]='room_kind_needs_review';
        if($capacity<1)$catalog[]='occupancy_not_verified';
        if(!$hasImage)$catalog[]='unit_images_missing';
        if($inventory<1)$catalog[]='physical_inventory_unverified';
        if($readiness!=='validated')$catalog[]='unit_readiness_not_validated';
        $commercial=$catalog;
        if($status!=='publish')$commercial[]='unit_unpublished';
        if(!in_array($available,['1','true','yes','on'],true))$commercial[]='unit_availability_unconfirmed';
        $unitDirect=self::direct($unit,true);
        $propertyDirect=$property?self::direct($property,false):null;
        if(!$unitDirect['sell_configured']
            && !($propertyDirect && $propertyDirect['sell_configured']))
            $commercial[]='direct_sell_price_not_configured';
        // Unit and Property Direct prices remain indicative until a certified
        // native rate and inventory/age/financial policy can produce a shadow quote.
        $commercial[]='native_rate_or_booking_receipt_required';
        return [
            'unit_id'=>$unit,'title'=>html_entity_decode($title,ENT_QUOTES,'UTF-8'),'unit_status'=>$status,
            'property_id'=>$property?:null,
            'property_title'=>$property?html_entity_decode((string)get_the_title($property),ENT_QUOTES,'UTF-8'):'',
            'property_status'=>$pstatus,
            'catalog_blockers'=>$catalog,'commercial_blockers'=>$commercial,
            'catalog_ready_for_review'=>count($catalog)===0,
            'checkout_ready'=>false,'qa_only'=>$qa,
            'unit_kind'=>$kind,'capacity_set'=>$capacity>0,
            'inventory_set'=>$inventory>0,'unit_media_present'=>$hasImage,
            'unit_readiness'=>$readiness,'unit_available'=>$available,
            'unit_direct'=>$unitDirect,'property_direct'=>$propertyDirect,
            'edit_unit_url'=>$withLinks && current_user_can('edit_post',$unit)
                ?get_edit_post_link($unit,''):null,
            'edit_property_url'=>$withLinks && $property && current_user_can('edit_post',$property)
                ?get_edit_post_link($property,''):null,
        ];
    }
    public static function audit(array $input=[]) {
        if(!self::authorized())return new WP_Error('stay_readiness_forbidden');
        $search=sanitize_text_field(substr((string)($input['search']??''),0,85));
        $scope=(string)($input['scope']??'all');
        $allowed=['all','priority','draft_parent','published','qa','missing_capacity','missing_images'];
        if(!in_array($scope,$allowed,true))return new WP_Error('stay_readiness_scope_invalid');
        $page=filter_var($input['paged']??1,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>100]]);
        if($page===false)return new WP_Error('stay_readiness_page_invalid');
        $posts=get_posts(['post_type'=>'units','post_status'=>['publish','draft'],
            'posts_per_page'=>2001,'fields'=>'all','orderby'=>'ID','order'=>'DESC',
            'suppress_filters'=>true]);
        if(count($posts)>2000)return new WP_Error('stay_readiness_limit_exceeded');
        $unitIds=array_map(static function($post){return (int)$post->ID;},$posts);
        if($unitIds)update_meta_cache('post',$unitIds);
        $rels=self::links();
        // Prime Property objects and all their meta in a few bounded reads.
        $parentIds=[];
        foreach($rels as $unitId=>$parents)if(in_array((int)$unitId,$unitIds,true))
            foreach($parents as $parentId)$parentIds[(int)$parentId]=1;
        $propertyIds=array_keys($parentIds);
        if($propertyIds){
            get_posts(['post_type'=>'properties','post_status'=>['publish','draft'],
                'post__in'=>$propertyIds,'posts_per_page'=>count($propertyIds),
                'fields'=>'all','suppress_filters'=>true]);
            update_meta_cache('post',$propertyIds);
        }
        $all=[];$summary=[
            'total'=>0,'published'=>0,'draft_published_property'=>0,
            'draft_draft_property'=>0,'qa'=>0,'catalog_ready_for_review'=>0,
            'missing_capacity'=>0,'missing_unit_images'=>0,
            'missing_inventory'=>0,'direct_sell_configured'=>0,
            'commercially_ready'=>0,
        ];
        foreach($posts as $post){
            $id=(int)$post->ID;
            if(!current_user_can('edit_post',$id))continue;
            $row=self::evaluate($id,$rels[$id]??[]);
            if($row['property_id'] && !current_user_can('edit_post',$row['property_id']))
                continue;
            $summary['total']++;
            if($row['unit_status']==='publish')$summary['published']++;
            if($row['unit_status']==='draft'&&$row['property_status']==='publish')
                $summary['draft_published_property']++;
            if($row['unit_status']==='draft'&&$row['property_status']==='draft')
                $summary['draft_draft_property']++;
            if($row['qa_only'])$summary['qa']++;
            if($row['catalog_ready_for_review'])$summary['catalog_ready_for_review']++;
            if(!$row['capacity_set'])$summary['missing_capacity']++;
            if(!$row['unit_media_present'])$summary['missing_unit_images']++;
            if(!$row['inventory_set'])$summary['missing_inventory']++;
            if($row['unit_direct']['sell_configured'])$summary['direct_sell_configured']++;
            if($search!=='' && stripos($row['title'].' '.$row['property_title'],$search)===false)continue;
            if($scope==='priority'&&!($row['unit_status']==='draft'&&$row['property_status']==='publish'&&!$row['qa_only']))continue;
            if($scope==='draft_parent'&&$row['property_status']!=='draft')continue;
            if($scope==='published'&&$row['unit_status']!=='publish')continue;
            if($scope==='qa'&&!$row['qa_only'])continue;
            if($scope==='missing_capacity'&&$row['capacity_set'])continue;
            if($scope==='missing_images'&&$row['unit_media_present'])continue;
            $all[]=$row;
        }
        $found=count($all);$slice=array_slice($all,($page-1)*self::LIMIT,self::LIMIT);
        foreach($slice as &$row){
            $row['edit_unit_url']=current_user_can('edit_post',$row['unit_id'])
                ?get_edit_post_link($row['unit_id'],''):null;
            $row['edit_property_url']=$row['property_id'] &&
                current_user_can('edit_post',$row['property_id'])
                ?get_edit_post_link($row['property_id'],''):null;
        }
        unset($row);
        $supplierPolicy=class_exists('MAD4B_Stay_Secondary_Self_Supplier_V1',false)
            ?MAD4B_Stay_Secondary_Self_Supplier_V1::status():null;
        $supplierConflict=!is_array($supplierPolicy)
            ||($supplierPolicy['status']??'')!=='self_fallback_active';
        return ['schema'=>'mad4b.stay-readiness.v1','site'=>home_url(),
            'provider'=>'self','supplier_policy_requires_review'=>$supplierConflict,
            'supplier_primary'=>'external_contract',
            'current_fallback_supplier'=>$supplierConflict?null:'self',
            'fallback_selection_active'=>!$supplierConflict,
            'scope'=>$scope,'search'=>$search,'summary'=>$summary,
            'items'=>$slice,'page'=>$page,'pages'=>(int)ceil($found/self::LIMIT),
            'found'=>$found,'read_only'=>true,'published_or_qa_mutated'=>false,
            'checkout_ready'=>false];
    }
    public static function handoff(array $input) {
        if(!self::authorized())return new WP_Error('stay_readiness_forbidden');
        $property=self::id($input['property_id']??null);
        $unit=self::id($input['unit_id']??null);
        if(!$property||!$unit||!current_user_can('edit_post',$property)
           ||!current_user_can('edit_post',$unit))return new WP_Error('stay_handoff_entities_forbidden');
        if(!function_exists('mad4b_stay_layered_price_preview_v1'))
            return new WP_Error('stay_handoff_resolver_missing');
        $payload=['property_id'=>$property,'unit_id'=>$unit];
        foreach(['checkin','nights','adults','children','board_type'] as $key)
            if(isset($input[$key]))$payload[$key]=$input[$key];
        if(!class_exists('MAD4B_Stay_Secondary_Self_Supplier_V1'))
            return ['status'=>'blocked','reason'=>'supplier_router_missing',
                'checkout_ready'=>false,'mutation_performed'=>false,'provider'=>'self'];
        $resolution=MAD4B_Stay_Secondary_Self_Supplier_V1::resolve($payload);
        if(is_wp_error($resolution))return ['status'=>'blocked',
            'reason'=>$resolution->get_error_code(),
            'checkout_ready'=>false,'mutation_performed'=>false,'provider'=>'self'];
        $quote=$resolution['quote'];
        $selected=$quote['selected_source']??null;
        $receipt=['status'=>'blocked','reason'=>'no_eligible_company_rate',
            'selected_source'=>$selected,'checkout_ready'=>false,'mutation_performed'=>false,
            'provider'=>'self','native_engine'=>'abk_accommodation_shadow_quote'];
        if($selected!=='unit_rate'){
            $receipt['reason']=$selected?'native_published_unit_rate_required':'no_eligible_company_rate';
            return $receipt;
        }
        if(!function_exists('abk_accommodation_shadow_quote'))
            return array_merge($receipt,['reason'=>'native_shadow_engine_missing']);
        $rateId=(int)($quote['price']['id']??0);
        $date=(string)($quote['checkin']??'');
        $nights=(int)($quote['nights']??0);
        if(!$rateId||!$date||$nights<1)return array_merge($receipt,['reason'=>'native_quote_dates_missing']);
        try{
            $checkout=(new DateTimeImmutable($date,new DateTimeZone('UTC')))
                ->modify('+'.$nights.' days')->format('Y-m-d');
        }catch(Exception $e){return array_merge($receipt,['reason'=>'native_quote_dates_invalid']);}
        $native=abk_accommodation_shadow_quote([
            'rate_id'=>$rateId,'checkin'=>$date,'checkout'=>$checkout,
            'rooms'=>1,'adults'=>(int)$quote['adults'],'children'=>(int)$quote['children'],
            'availability_mode'=>'price_only',
        ]);
        if(is_wp_error($native))
            return array_merge($receipt,['reason'=>$native->get_error_code()]);
        return [
            'status'=>'native_shadow_preview','reason'=>'not_checkout_authorized',
            'selected_source'=>$selected,'rate_id'=>$rateId,'property_id'=>$property,
            'unit_id'=>$unit,'price_basis'=>$native['price_basis']??null,
            'base_total'=>$native['total']??null,'currency'=>$native['currency']??null,
            'inventory_status'=>$native['inventory_status']??'not_checked',
            'checkout_ready'=>false,'mutation_performed'=>false,'provider'=>'self',
            'native_engine'=>'abk_accommodation_shadow_quote',
        ];
    }
    public static function ajax():void {
        if(!self::authorized())wp_send_json_error(['code'=>'stay_readiness_forbidden'],403);
        check_ajax_referer(self::ACTION,'nonce');
        $mode=isset($_POST['mode'])&&is_string($_POST['mode'])
            ?sanitize_key(wp_unslash($_POST['mode'])):'';
        $data=[];
        foreach(['scope','paged','search','property_id','unit_id','checkin','nights',
             'adults','children','board_type'] as $key)
            if(isset($_POST[$key])&&is_string($_POST[$key]))
                $data[$key]=sanitize_text_field(wp_unslash($_POST[$key]));
        if($mode==='audit')$result=self::audit($data);
        elseif($mode==='handoff')$result=self::handoff($data);
        else $result=new WP_Error('stay_readiness_mode_unknown');
        if(is_wp_error($result))wp_send_json_error(['code'=>$result->get_error_code()],400);
        wp_send_json_success($result);
    }
    public static function assets():void {
        $page=isset($_GET['page'])&&is_string($_GET['page'])
           ?sanitize_key(wp_unslash($_GET['page'])):'';
        if(!in_array($page,self::PAGE_SLUGS,true)||!self::authorized())return;
        $dir=__DIR__.'/mad4b-stay-readiness/';
        $url=plugin_dir_url(__FILE__).'mad4b-stay-readiness/';
        if(!is_file($dir.'admin.js')||!is_file($dir.'admin.css')||!is_file($dir.'panel.html'))return;
        wp_enqueue_style('mad4b-stay-readiness',$url.'admin.css',[],
            (string)filemtime($dir.'admin.css'));
        wp_enqueue_script('mad4b-stay-readiness',$url.'admin.js',
            ['mad4b-stay-pricing-inline'],(string)filemtime($dir.'admin.js'),true);
        wp_add_inline_script('mad4b-stay-readiness','window.MAD4BStayReadiness='.wp_json_encode([
            'ajax_url'=>admin_url('admin-ajax.php'),'action'=>self::ACTION,
            'nonce'=>wp_create_nonce(self::ACTION),
            'template'=>file_get_contents($dir.'panel.html')
        ]).';','before');
    }
    public static function boot():void {
        add_action('wp_ajax_'.self::ACTION,[__CLASS__,'ajax']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],90);
    }
}
MAD4B_Stay_Commercial_Readiness_V1::boot();
}
