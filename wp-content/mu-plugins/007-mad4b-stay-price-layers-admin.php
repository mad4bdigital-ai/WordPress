<?php
/**
 * Plugin Name: MAD4B Stay Price Layers Admin
 * Description: Self-provider price authoring on existing JetEngine Properties, Units, and Property Rates.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Stay_Layers_Admin_V1', false)) {
final class MAD4B_Stay_Layers_Admin_V1 {
    const PAGE='mad4b-stay-price-layers';
    const NONCE='mad4b_stay_layers_nonce';
    private static function allowed(int $id):bool {
        return current_user_can('commerce_manage_pricing') && current_user_can('edit_post',$id);
    }
    public static function menu():void {
        if(!current_user_can('commerce_manage_pricing'))return;
        add_submenu_page('mad4b-pricing-workspace','Property & Unit Pricing',
            'Property & Unit Pricing','commerce_manage_pricing',self::PAGE,[self::class,'page']);
    }
    public static function boxes():void {
        foreach(['properties','units'] as $type)
            add_action('add_meta_boxes_'.$type,static function($post)use($type){
                if(!($post instanceof WP_Post)||!self::allowed((int)$post->ID))return;
                add_meta_box('mad4b-stay-direct','Self Provider — Direct Price',
                    [self::class,'direct_box'],$type,'normal','default');
            });
        add_action('add_meta_boxes_property-rates',static function($post){
            if(!($post instanceof WP_Post)||!self::allowed((int)$post->ID))return;
            add_meta_box('mad4b-stay-rate-scope','Self Provider — Rate Layer',
                [self::class,'rate_box'],'property-rates','normal','default');
        });
    }
    private static function field(string $text,string $name,string $value,string $type='text'):void {
        echo '<p><label><strong>'.esc_html($text).'</strong><br><input type="'.esc_attr($type)
            .'" name="mad4b_layer_'.esc_attr($name).'" value="'.esc_attr($value).'"'
            .($type==='number'?' step="0.0001" min="0"':'')
            .' style="width:100%;max-width:330px"></label></p>';
    }
    private static function choice(string $text,string $name,string $selected,array $values):void {
        echo '<p><label><strong>'.esc_html($text).'</strong><br><select name="mad4b_layer_'.esc_attr($name).'" style="width:100%;max-width:330px">';
        foreach($values as $v=>$label)
            echo '<option value="'.esc_attr($v).'"'.selected($selected,$v,false).'>'.esc_html($label).'</option>';
        echo '</select></label></p>';
    }
    private static function basis():array {
        $raw=function_exists('abk_price_unit_choices') ? abk_price_unit_choices() : [];
        $keys=is_array($raw)?($raw['values']??[]):[];
        return is_array($keys)?$keys:[];
    }
    public static function direct_box(WP_Post $post):void {
        $id=(int)$post->ID;
        $unit=$post->post_type==='units';
        wp_nonce_field('mad4b_stay_price_'.$id,self::NONCE);
        echo '<p>Pricing provider: <strong>Company / Self</strong>. This extends existing JetEngine price fields, without creating another price store.</p>';
        if($unit){
            $price=(string)get_post_meta($id,'unit_start_price',true);
            echo '<p><strong>Unit Direct Price:</strong> '.($price!==''?esc_html($price):'Not entered')
                .'. Edit this in the existing JetEngine <strong>Unit Price</strong> field above.</p>';
        }else{
            self::field('Property Direct Price (optional)','amount',
                (string)get_post_meta($id,'prop_start_price',true),'number');
        }
        $pre=$unit?'unit_':'prop_';
        $currencyKey=$unit?'unit_base_currency':'prop_base_currency';
        $basisKey=$unit?'unit_direct_price_unit':'prop_direct_price_unit';
        $kindKey=$unit?'unit_direct_price_kind':'prop_direct_price_kind';
        self::field('Currency (3-letter ISO)','currency',
            (string)get_post_meta($id,$currencyKey,true));
        self::choice('Selling Basis','basis',
            (string)get_post_meta($id,$basisKey,true),
            [''=>'Select price basis']+self::basis());
        self::choice('Source amount represents','kind',
            (string)get_post_meta($id,$kindKey,true)?:'sell',
            ['sell'=>'Direct selling price','cost'=>'Internal cost (not a selling price)']);
        echo '<p><small>Direct prices are indicative until availability, meal inclusion and checkout readiness are independently verified.</small></p>';
    }
    public static function rate_box(WP_Post $post):void {
        $id=(int)$post->ID;
        wp_nonce_field('mad4b_stay_rate_'.$id,self::NONCE);
        $unit=(int)get_post_meta($id,'prop_related_unit_id',true);
        $property=(int)get_post_meta($id,'prop_related_property_id',true);
        $scope=(string)get_post_meta($id,'mad4b_rate_scope_v1',true);
        self::choice('Price scope','scope',$scope?:($unit?'unit':''),
            [''=>'Choose rate scope','unit'=>'Unit Rate — existing Unit relation',
             'property'=>'Property Rate — applies without a Unit relation']);
        self::choice('Source amount represents','kind',
            (string)get_post_meta($id,'mad4b_rate_price_kind_v1',true)?:'sell',
            ['sell'=>'Selling price','cost'=>'Internal cost (not a selling price)']);
        echo '<p>Property ID: '.($property?:'Not linked').' · Unit ID: '.($unit?:'Not linked').'.</p>';
        echo '<p><small>A Property-wide Rate must have a linked Property and <strong>no Unit ID</strong>. Keep native amount, currency, dates, meal plan, occupancy, and Stop Sale in the existing JetEngine fields. Draft/Stop Sale rates are never selected.</small></p>';
    }
    private static function approved_post(int $id,string $action):bool {
        if((defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||wp_is_post_revision($id)||wp_is_post_autosave($id))
            return false;
        if(!self::allowed($id)||!isset($_POST[self::NONCE])||!is_string($_POST[self::NONCE]))
            return false;
        return wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])),$action)===1
            || wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])),$action)===2;
    }
    private static function post_string(string $key):?string {
        $key='mad4b_layer_'.$key;
        if(!isset($_POST[$key])||!is_string($_POST[$key]))return null;
        return trim(wp_unslash($_POST[$key]));
    }
    public static function save_direct(int $id,WP_Post $post):void {
        if(!in_array($post->post_type,['properties','units'],true)
            ||!self::approved_post($id,'mad4b_stay_price_'.$id))return;
        $amount=self::post_string('amount');
        $currency=self::post_string('currency');
        $basis=self::post_string('basis');
        $kind=self::post_string('kind');
        if($currency===null||$basis===null||$kind===null)return;
        $currency=strtoupper($currency);
        if($currency!==''&&!preg_match('/^[A-Z]{3}$/D',$currency))return;
        if($basis!==''&&!array_key_exists($basis,self::basis()))return;
        if(!in_array($kind,['sell','cost'],true))return;
        if($post->post_type==='properties'){
            if($amount===null)return;
            if($amount!==''&&(!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D',$amount)
                ||(float)$amount<=0))return;
            if($amount!=='')update_post_meta($id,'prop_start_price',$amount);
            else delete_post_meta($id,'prop_start_price');
        }
        $unit=$post->post_type==='units';
        update_post_meta($id,$unit?'unit_base_currency':'prop_base_currency',$currency);
        update_post_meta($id,$unit?'unit_direct_price_unit':'prop_direct_price_unit',$basis);
        update_post_meta($id,$unit?'unit_direct_price_kind':'prop_direct_price_kind',$kind);
    }
    public static function save_rate(int $id,WP_Post $post):void {
        if(!self::approved_post($id,'mad4b_stay_rate_'.$id))return;
        $scope=self::post_string('scope');
        $kind=self::post_string('kind');
        if(!in_array($scope,['unit','property'],true)||!in_array($kind,['sell','cost'],true))return;
        $unit=(int)get_post_meta($id,'prop_related_unit_id',true);
        $property=(int)get_post_meta($id,'prop_related_property_id',true);
        if(!$property||get_post_type($property)!=='properties')return;
        if(($scope==='property'&&$unit) || ($scope==='unit'&&!$unit))return;
        update_post_meta($id,'mad4b_rate_scope_v1',$scope);
        update_post_meta($id,'mad4b_rate_price_kind_v1',$kind);
    }
    public static function page():void {
        if(!current_user_can('commerce_manage_pricing'))wp_die('Pricing permission required');
        $pid=absint($_GET['mad4b_layer_property_id']??$_GET['property_id']??0);
        $uid=absint($_GET['mad4b_layer_unit_id']??$_GET['unit_id']??0);
        $day=sanitize_text_field((string)($_GET['mad4b_layer_checkin']??$_GET['checkin']??''));
        echo '<div class="wrap"><h1>Property &amp; Unit Pricing</h1>';
        echo '<p>Company / Self Provider · Existing JetEngine Properties, Units, Property Rates · no new CPTs.</p>';
        echo '<ol><li>Unit Rate: published matching Property Rate with Unit link</li>';
        echo '<li>Unit Direct: native Unit Price</li>';
        echo '<li>Property Rate: published rate with explicit Property scope and no Unit link</li>';
        echo '<li>Property Direct: base Starting From price</li></ol>';
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        self::field('Property ID','property_id',(string)$pid);
        self::field('Unit ID (optional)','unit_id',$uid?(string)$uid:'');
        self::field('Check-in date','checkin',$day,'date');
        self::field('Nights','nights',(string)absint($_GET['mad4b_layer_nights']??1),'number');
        self::field('Adults','adults',(string)absint($_GET['mad4b_layer_adults']??2),'number');
        self::field('Children','children',(string)absint($_GET['mad4b_layer_children']??0),'number');
        $boardOptions=[''=>'Inherit Property / Cruise meal plan'];
        $terms=get_terms(['taxonomy'=>'board-type','hide_empty'=>false]);
        if(!is_wp_error($terms))foreach($terms as $term)
            $boardOptions[$term->slug]=$term->name;
        self::choice('Meal Plan','board_type',
            sanitize_key((string)($_GET['mad4b_layer_board_type']??'')),$boardOptions);
        echo '<p><button class="button button-primary">Preview price source</button></p></form>';
        // Inputs above use the shared admin field renderer; accept this prefix in preview.
        $pid=absint($_GET['mad4b_layer_property_id']??$_GET['property_id']??0);
        $uid=absint($_GET['mad4b_layer_unit_id']??$_GET['unit_id']??0);
        $day=sanitize_text_field((string)($_GET['mad4b_layer_checkin']??$_GET['checkin']??''));
        if($pid && current_user_can('edit_post',$pid)){
            $args=['property_id'=>$pid,'unit_id'=>$uid,'checkin'=>$day,
                'nights'=>absint($_GET['mad4b_layer_nights']??1),
                'adults'=>absint($_GET['mad4b_layer_adults']??2),
                'children'=>absint($_GET['mad4b_layer_children']??0)];
            $chosenBoard=sanitize_key((string)($_GET['mad4b_layer_board_type']??''));
            if($chosenBoard!=='')$args['board_type']=$chosenBoard;
            $r=MAD4B_Stay_Layered_Pricing_V1::preview($args);
            if(is_wp_error($r))echo '<p class="notice notice-warning">Preview: '.esc_html($r->get_error_code()).'</p>';
            else {
                echo '<h2>Price source result</h2>';
                echo '<p><strong>Selected:</strong> '.esc_html($r['selected_source']??'Unpriced').'</p>';
                if($r['price'])echo '<p><strong>Indicative:</strong> '.esc_html($r['price']['amount'].' '.$r['price']['currency']).' / '.esc_html($r['price']['price_unit']).'</p>';
                echo '<p><small>Checkout not authorized · '.$r['price_status'].' · Meal: '.esc_html($r['meal_base']).'</small></p>';
                echo '<pre style="max-width:800px;overflow:auto;background:#fff;padding:12px">'.esc_html(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
            }
            echo '<p><a class="button" href="'.esc_url(get_edit_post_link($pid)).'">Edit Property Direct Price</a> ';
            if($uid&&current_user_can('edit_post',$uid))echo '<a class="button" href="'.esc_url(get_edit_post_link($uid)).'">Edit Unit Direct Price</a> ';
            echo '<a class="button" href="'.esc_url(admin_url('edit.php?post_type=property-rates')).'">Existing Property Rates</a></p>';
        }
        echo '</div>';
    }
    public static function native_shortcut():void {
        if(!current_user_can('commerce_manage_pricing'))return;
        $page=sanitize_key((string)($_GET['page']??''));
        if(!in_array($page,['dynamic-pricing-controls','etg-dynamic-pricing-controls','mad4b-pricing-workspace'],true))return;
        echo '<div class="notice notice-info"><p><strong>Accommodation Pricing:</strong> Existing Property and Unit direct prices, plus optional Property Rates and Unit Rates. ';
        echo '<a href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">Open Property &amp; Unit Pricing</a></p></div>';
    }
    public static function boot():void {
        add_action('admin_menu',[self::class,'menu'],105);
        add_action('admin_notices',[self::class,'native_shortcut']);
        add_action('admin_init',[self::class,'boxes']);
        add_action('save_post_properties',[self::class,'save_direct'],900,2);
        add_action('save_post_units',[self::class,'save_direct'],900,2);
        add_action('save_post_property-rates',[self::class,'save_rate'],900,2);
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b-pricing/v1','/stay-layered-preview',[
                'methods'=>'GET',
                'permission_callback'=>static function($r){
                    $id=absint($r->get_param('property_id'));
                    return current_user_can('commerce_manage_pricing')&&$id&&current_user_can('edit_post',$id);
                },
                'callback'=>static function($r){
                    $payload=[];
                    foreach(['property_id','unit_id','checkin','nights','adults','children','board_type'] as $key)
                        if($r->has_param($key))$payload[$key]=$r->get_param($key);
                    $result=MAD4B_Stay_Layered_Pricing_V1::preview($payload);
                    return is_wp_error($result)?$result:rest_ensure_response($result);
                },
            ]);
        });
    }
}
MAD4B_Stay_Layers_Admin_V1::boot();
}
