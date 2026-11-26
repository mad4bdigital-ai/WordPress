<?php
/**
 * MAD4B Transportation Self Provider — native Dynamic Pricing Controls extension.
 * Source data is trusted and immutable; previews do not authorize checkout.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/pricing-admin-context.php';
final class MAD4B_Transport_Self_Pricing_Controls_V1 {
    const OPTION='mad4b_transport_self_pricing_controls_v1';
    const HISTORY='mad4b_transport_self_pricing_history_v1';
    const AJAX='mad4b_transport_self_pricing_v1';
    const SHA='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
    const CITIES=['Cairo','Aswan','Luxor'];
    const VEHICLES=['limousine','luxury_7_seats','h1','hiace','coaster','bus'];
    public static function permitted():bool {
        if(class_exists('MAD4B_JetEngine_Pricing_Controls_Access_V1'))
            return MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized();
        return current_user_can('commerce_manage_pricing') &&
           (current_user_can('manage_woocommerce') ||
            (current_user_can('mad4b_manage_company_operations') &&
             current_user_can('mad4b_work_company_tasks')));
    }
    public static function defaults():array {
        return [
            'revision'=>0,'provider'=>'self','source_type'=>'trusted_original_pdf_2026',
            'source_sha256'=>self::SHA,'effective_from'=>'2026-04-01',
            'currency'=>'','price_role'=>'reference_only',
            'apply_upper_egypt_five_pct'=>true,'global_markup_pct'=>0.0,
            'city_markup_pct'=>array_fill_keys(self::CITIES,0.0),
            'vehicle_markup_pct'=>array_fill_keys(self::VEHICLES,0.0),
            'market_profile_authority'=>'existing_approved_profiles',
            'updated_at'=>null,'updated_by'=>null
        ];
    }
    public static function current():array {
        $out=self::defaults();
        $v=get_option(self::OPTION,[]);
        if(is_array($v))foreach([
            'revision','currency','price_role','apply_upper_egypt_five_pct',
            'global_markup_pct','city_markup_pct','vehicle_markup_pct',
            'updated_at','updated_by'
        ] as $key)if(array_key_exists($key,$v))$out[$key]=$v[$key];
        return $out;
    }
    public static function source():array {
        $s=require dirname(__DIR__).'/data/transport-pdf-2026-localized.php';
        if(!is_array($s)||($s['source_sha256']??'')!==self::SHA||
            count($s['rows']??[])!==51)return [];
        return $s;
    }
    private static function percent($v,int $max=200) {
        if(!is_scalar($v)||is_bool($v)||!is_numeric($v)||!is_finite((float)$v)||
            (float)$v<0||(float)$v>$max)return new WP_Error('transport_pct_invalid');
        return round((float)$v,2);
    }
    public static function validate(array $raw) {
        $keys=['currency','price_role','apply_upper_egypt_five_pct',
            'global_markup_pct','city_markup_pct','vehicle_markup_pct'];
        if(array_diff(array_keys($raw),$keys)||count($raw)!==count($keys))
            return new WP_Error('transport_policy_fields_invalid');
        $currency=strtoupper(trim((string)$raw['currency']));
        if($currency!==''&&(!preg_match('/^[A-Z]{3}$/D',$currency)||
            !function_exists('get_woocommerce_currencies')||
            !array_key_exists($currency,get_woocommerce_currencies())))
            return new WP_Error('transport_currency_unknown');
        $role=(string)$raw['price_role'];
        if(!in_array($role,['reference_only','self_cost','self_selling'],true))
            return new WP_Error('transport_price_role_invalid');
        if(!is_bool($raw['apply_upper_egypt_five_pct']))
            return new WP_Error('transport_discount_invalid');
        $global=self::percent($raw['global_markup_pct']);
        if(is_wp_error($global))return $global;
        $city=$raw['city_markup_pct']??null;
        $vehicle=$raw['vehicle_markup_pct']??null;
        if(!is_array($city)||array_keys($city)!==self::CITIES||
           !is_array($vehicle)||array_keys($vehicle)!==self::VEHICLES)
            return new WP_Error('transport_policy_dimensions_invalid');
        $c=[];$v=[];
        foreach($city as $k=>$value){
            $p=self::percent($value);
            if(is_wp_error($p))return $p;
            $c[$k]=$p;
        }
        foreach($vehicle as $k=>$value){
            $p=self::percent($value);
            if(is_wp_error($p))return $p;
            $v[$k]=$p;
        }
        return ['currency'=>$currency,'price_role'=>$role,
            'apply_upper_egypt_five_pct'=>$raw['apply_upper_egypt_five_pct'],
            'global_markup_pct'=>$global,'city_markup_pct'=>$c,'vehicle_markup_pct'=>$v];
    }
    public static function save(array $raw,int $expected) {
        if(!self::permitted())return new WP_Error('transport_permission_denied');
        $validated=self::validate($raw);
        if(is_wp_error($validated))return $validated;
        $lock=self::OPTION.'_save_lock';
        if(!add_option($lock,time(),'',false)){
            $held=get_option($lock,0);
            if(!is_numeric($held)||time()-(int)$held<=90 ||
               !delete_option($lock)||!add_option($lock,time(),'',false))
                return new WP_Error('transport_policy_save_in_progress');
        }
        try {
        $prior=self::current();
        if($prior['revision']!==$expected)return new WP_Error('transport_revision_conflict');
        $next=array_merge($prior,$validated,[
            'revision'=>$expected+1,'updated_at'=>gmdate('c'),
            'updated_by'=>get_current_user_id()
        ]);
        update_option(self::OPTION,$next,false);
        if(self::current()['revision']!==$expected+1)
            return new WP_Error('transport_policy_readback_failed');
        $history=get_option(self::HISTORY,[]);
        if(!is_array($history))$history=[];
        $history[]=['before'=>$prior,'after'=>$next,'at'=>gmdate('c'),
            'source_sha256'=>self::SHA,'checkout_authorized'=>false];
        update_option(self::HISTORY,array_slice($history,-20),false);
        return ['status'=>'saved','policy'=>self::current(),
            'provider'=>'self','source_trusted'=>true,'checkout_authorized'=>false];
        } finally {
            delete_option($lock);
        }
    }
    public static function catalog(string $city='',string $lang='en'):array {
        $source=self::source();$policy=self::current();
        if(!$source)return ['error'=>'pdf_source_missing'];
        if(!in_array($city,array_merge([''],self::CITIES),true))$city='';
        $langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        $langs=is_array($langs)?array_keys($langs):['en'];
        if(!in_array($lang,$langs,true))$lang='en';
        $map=get_option('mad4b_transfer_pdf_2026_vehicle_media_map_v1',[]);
        $linked=[];
        // Batch-read all source Draft IDs before building localized catalog
        // to avoid one WP_Query for each of the 51 vendor operations.
        $sourcePosts=get_posts([
            'post_type'=>'transportations',
            'post_status'=>['draft','publish'],
            'posts_per_page'=>600,
            'fields'=>'ids','suppress_filters'=>true,
            'meta_key'=>'_mad4b_transfer_pdf_2026_sha256',
            'meta_value'=>self::SHA
        ]);
        foreach($sourcePosts as $id){
            $postLang=(string)get_post_meta($id,
                '_mad4b_transfer_pdf_2026_editorial_language',true);
            if($postLang!==$lang)continue;
            $sourceKey=(string)get_post_meta($id,
                '_mad4b_transfer_pdf_2026_source_key',true);
            if(!$sourceKey)continue;
            $linked[$sourceKey][]=(int)$id;
        }
        $sourceIds=array_merge([],...array_values($linked));
        $operational=class_exists('MAD4B_Transport_Source_Operations_Readiness_V1')
            ?MAD4B_Transport_Source_Operations_Readiness_V1::map($sourceIds)
            :[];
        $items=[];
        foreach($source['rows'] as $r){
            if($city!==''&&$r['city']!==$city)continue;
            $prices=[];
            foreach($r['vehicle_categories'] as $key){
                $id=(int)($map[$key]??0);
                $prices[$key]=[
                    'amount'=>$r['vehicle_rates_unverified'][$key],
                    'image_id'=>$id,'image_url'=>$id?wp_get_attachment_image_url($id,'thumbnail'):null
                ];
            }
            $postIds=$linked[$r['source_key']]??[];
            $postId=count($postIds)===1?$postIds[0]:0;
            $component=$postId&&class_exists('MAD4B_Transport_Tour_Component_Eligibility_V1')
                ?MAD4B_Transport_Tour_Component_Eligibility_V1::inspection($postId)
                :['qualifies_as_tour_component'=>false,
                   'reason_codes'=>['missing_or_duplicate_translation_draft'],
                   'opted_in'=>false,'status'=>'requires_qualification',
                   'rate_approved'=>false,'checkout_authorized'=>false];
            $items[]=[
                'post_id'=>$postId,'translation_count'=>count($postIds),
                'edit_url'=>$postId?get_edit_post_link($postId,'raw'):null,
                'component'=>$component,
                'operations'=>$operational[$postId]??[
                    'booking_ready'=>false,
                    'reason_codes'=>['source_translation_not_unique']
                ],
                'key'=>$r['source_key'],'city'=>$r['city'],
                'row'=>$r['source_row'],
                'title'=>$r['titles'][$lang]??$r['titles']['en'],
                'original_ar'=>$r['source_ar'],
                'service_type'=>$r['service_kind'],
                'prices'=>$prices,'review_flags'=>$r['quality_flags']
            ];
        }
        return [
            'contract'=>'mad4b.transport-self-pricing-controls.v1',
            'provider'=>['id'=>'self','name'=>'Company / Self','status'=>'active_self_source'],
            'trusted_pdf'=>['sha256'=>self::SHA,'source_date'=>'2026-04-01',
                'rows'=>51,'price_cells'=>210,'source_approved'=>true,
                'currency_printed_in_pdf'=>false,'discount_in_aswan_luxor'=>5],
            'policy'=>$policy,'items'=>$items,
            'languages'=>$langs,'city'=>$city,'language'=>$lang,
            'approved_market_profiles'=>class_exists('MAD4B_SCP_Market_Profiles')
                ?count(MAD4B_SCP_Market_Profiles::approved_list()):0,
            'edit_drafts_url'=>admin_url('tools.php?page=mad4b-transfer-pdf-2026-review'),
            'transportations_url'=>admin_url('edit.php?post_type=transportations'),
            'native_rates_url'=>admin_url('edit.php?post_type=transport-rates'),
            'market_url'=>admin_url('admin.php?page=mad4b-market-profiles'),
            'tour_component_field'=>class_exists('MAD4B_Transport_Tour_Component_Eligibility_V1')
                ?MAD4B_Transport_Tour_Component_Eligibility_V1::FIELD
                :'trans_use_in_tour_rate_generation',
            'tour_component_field_location'=>'below_transport_description',
            'component_qualified_count'=>count(array_filter($items,
                static fn($row)=>!empty($row['component']['qualifies_as_tour_component']))),
            'checkout_authorized'=>false,'public_price_written'=>false
        ];
    }
    public static function preview(array $input) {
        $source=self::source();
        $policy=self::current();
        $key=(string)($input['key']??'');
        $vehicle=(string)($input['vehicle']??'');
        $qty=filter_var($input['quantity']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>30]]);
        if(!$qty||!in_array($vehicle,self::VEHICLES,true)||
           !preg_match('/^(Cairo|Aswan|Luxor):([0-9]{1,2})$/D',$key))
            return new WP_Error('transport_preview_input_invalid');
        $row=null;
        foreach($source['rows'] as $r)if($r['source_key']===$key){$row=$r;break;}
        if(!$row || !array_key_exists($vehicle,$row['vehicle_rates_unverified']))
            return new WP_Error('transport_preview_source_missing');
        $amount=$row['vehicle_rates_unverified'][$vehicle];
        if($amount===null)return [
            'status'=>'no_price_in_pdf','key'=>$key,'vehicle'=>$vehicle,
            'provider'=>'self','source_approved'=>true,
            'checkout_ready'=>false,'missing_is_zero'=>false
        ];
        if(!is_int($amount)||$amount<=0)return new WP_Error('transport_pdf_price_invalid');
        $city=$row['city'];
        $discount=$city!=='Cairo'&&$policy['apply_upper_egypt_five_pct']?5.0:0.0;
        $net=round($amount*(1-$discount/100),2);
        $markup=$policy['global_markup_pct']+
            $policy['city_markup_pct'][$city]+
            $policy['vehicle_markup_pct'][$vehicle];
        $unit=round($net*(1+$markup/100),2);
        $market=function_exists('shared_product_market_current_context')
            ?shared_product_market_current_context():[];
        return [
            'status'=>$policy['currency']!==''?'source_preview':'currency_not_selected',
            'key'=>$key,'city'=>$city,'vehicle'=>$vehicle,'quantity'=>(int)$qty,
            'provider'=>'self','source_sha256'=>self::SHA,'source_approved'=>true,
            'source_amount'=>$amount,'source_discount_pct'=>$discount,
            'after_discount'=>$net,'markup_total_pct'=>round($markup,2),
            'unit_reference_amount'=>$unit,
            'extended_reference_amount'=>round($unit*(int)$qty,2),
            'currency'=>$policy['currency'],'price_role'=>$policy['price_role'],
            'market_profile_id'=>$market['profile_id']??null,
            'market_adjustment_applied'=>false,
            'native_market_policy_required'=>true,
            'availability_checked'=>false,'public_price_authorized'=>false,
            'checkout_ready'=>false
        ];
    }
    public static function ajax():void {
        if(!self::permitted())wp_send_json_error(['code'=>'forbidden'],403);
        check_ajax_referer(self::AJAX,'nonce');
        $mode=sanitize_key((string)($_POST['mode']??''));
        if($mode==='catalog')$result=self::catalog(
            sanitize_text_field((string)($_POST['city']??'')),
            sanitize_key((string)($_POST['lang']??'en')));
        elseif($mode==='preview')$result=self::preview([
            'key'=>sanitize_text_field((string)($_POST['key']??'')),
            'vehicle'=>sanitize_key((string)($_POST['vehicle']??'')),
            'quantity'=>sanitize_text_field((string)($_POST['quantity']??'1'))
        ]);
        elseif($mode==='save') {
            $raw=isset($_POST['policy']) && is_string($_POST['policy'])
                ?wp_unslash($_POST['policy']):'';
            if(strlen($raw)>4000)wp_send_json_error(['code'=>'oversized_policy'],400);
            $policy=json_decode($raw,true);
            if(!is_array($policy))wp_send_json_error(['code'=>'invalid_policy_json'],400);
            $revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0]]);
            if($revision===false||$revision===null)
                wp_send_json_error(['code'=>'invalid_revision'],400);
            $result=self::save($policy,(int)$revision);
        } else $result=new WP_Error('unknown_transport_pricing_action');
        if(is_wp_error($result))wp_send_json_error(['code'=>$result->get_error_code()],400);
        wp_send_json_success($result);
    }
    public static function assets():void {
        if(!self::permitted()||!is_admin())return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!MAD4B_Pricing_Admin_Context_V1::matches($page))
            return;
        $dir=dirname(__DIR__).'/assets/';
        if(!is_file($dir.'transport-self-controls.js')||
           !is_file($dir.'transport-self-controls.css'))return;
        $main=dirname(__DIR__).'/mad4b-pricing-registry.php';
        wp_enqueue_style('mad4b-transport-self-controls',
            set_url_scheme(plugins_url('assets/transport-self-controls.css',$main),'https'),[],
            (string)filemtime($dir.'transport-self-controls.css'));
        wp_enqueue_script('mad4b-transport-self-controls',
            set_url_scheme(plugins_url('assets/transport-self-controls.js',$main),'https'),[],
            (string)filemtime($dir.'transport-self-controls.js'),true);
        wp_add_inline_script('mad4b-transport-self-controls',
            'window.MAD4BTransportSelfPricing='.wp_json_encode([
                'url'=>admin_url('admin-ajax.php'),'action'=>self::AJAX,
                'nonce'=>wp_create_nonce(self::AJAX),
                'lang'=>(string)apply_filters('wpml_current_language',null),
                'template'=>file_get_contents($dir.'transport-self-panel.html')
            ]).';','before');
    }
    public static function boot():void {
        add_action('wp_ajax_'.self::AJAX,[__CLASS__,'ajax']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],75);
    }
}
MAD4B_Transport_Self_Pricing_Controls_V1::boot();
