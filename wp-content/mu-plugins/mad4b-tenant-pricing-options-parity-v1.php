<?php
/**
 * Plugin Name: MAD4B Tenant Pricing Options Parity
 * Description: Restores existing native pricing settings registration/reader in an enrolled tenant.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Tenant_Pricing_Options_Parity_V1',false)){
final class MAD4B_Tenant_Pricing_Options_Parity_V1 {
    const SLUG='etg-dynamic-pricing-controls';
    public static function eligible():bool {
        return class_exists('MAD4B_Tenant_Service_Identity_Boundary_V1',false)
            && MAD4B_Tenant_Service_Identity_Boundary_V1::eligible();
    }
    public static function load_reader():void {
        if(function_exists('shared_product_pricing_setting')||!self::eligible())return;
        $file=__DIR__.'/shared-product-core/pricing/page-options-provider.php';
        if(is_file($file)&&is_readable($file))require_once $file;
    }
    /** Exact source controls only. Does not set values or change quote decisions. */
    public static function fields(array $fields,$page):array {
        if(!is_object($page) || ($page->slug??'')!==self::SLUG
            || !self::eligible() || isset($fields['booking_fulfillment_mode']))
            return $fields;
        $item=[
            'type'=>'select','object_type'=>'field','field_layout'=>'inline',
            'name'=>'booking_fulfillment_mode','hide_field_name'=>false,
            'element'=>'control','title'=>'Booking fulfillment mode','class'=>'',
            'description'=>'Global booking intent policy. Inquiry only, Request to Book, or Instant Booking. Effective Request/Instant additionally require Enable live booking ON, schedule eligibility, and original checkout readiness. Tour/Rate overrides inherit this default. Independent of dynamic pricing and stay price policy.',
            'options'=>[
                'inquiry_only'=>'Inquiry only',
                'request_to_book'=>'Request to Book (confirmation required)',
                'instant_booking'=>'Instant Booking (subject to eligibility)',
            ],
        ];
        $out=[];
        foreach($fields as $key=>$value){
            $out[$key]=$value;
            if($key==='booking_enabled')$out['booking_fulfillment_mode']=$item;
        }
        if(!isset($out['booking_fulfillment_mode']))$out['booking_fulfillment_mode']=$item;
        return $out;
    }
    public static function boot():void {
        add_action('plugins_loaded',[__CLASS__,'load_reader'],5);
        add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'fields'],12,2);
    }
}
MAD4B_Tenant_Pricing_Options_Parity_V1::boot();
}
