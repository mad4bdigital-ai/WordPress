<?php
/**
 * Plugin Name: MAD4B Accommodation — Unmanaged Stock Policy
 * Description: Tenant-bound accommodation stock opt-out, retaining occupancy, stop-sale and supplier confirmation safeguards.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Stay_Unmanaged_Stock_Policy_V1',false)) {
final class MAD4B_Stay_Unmanaged_Stock_Policy_V1 {
    const OPTION='mad4b_stay_inventory_sales_policy_v1';
    const SCHEMA='mad4b.stay-inventory-sales-policy.v1';
    public static function status():array {
        $c=get_option(self::OPTION,[]);
        if(!is_array($c) || !$c)return [
            'status'=>'not_configured','mode'=>'legacy',
            'stock_quantity_required'=>true,'checkout_ready'=>false
        ];
        $valid=($c['schema']??'')===self::SCHEMA
            && ($c['domain']??'')==='accommodation'
            && ($c['site']??'')===rtrim(home_url(),'/')
            && ($c['mode']??'')==='unmanaged'
            && ($c['stock_quantity_required']??null)===false
            && ($c['enforce_occupancy_capacity']??null)===true
            && ($c['honor_stop_sale']??null)===true
            && ($c['do_not_decrement_stock']??null)===true
            && ($c['requires_supplier_availability_confirmation']??null)===true
            && ($c['checkout_ready']??null)===false;
        return [
            'status'=>$valid?'unmanaged_active':'invalid_configuration',
            'mode'=>$valid?'unmanaged':'legacy',
            'stock_quantity_required'=>!$valid,
            'keep_occupancy_capacity_check'=>true,
            'honor_stop_sale'=>true,
            'requires_supplier_availability_confirmation'=>true,
            'checkout_ready'=>false,
            'scope'=>'accommodation_only',
        ];
    }
    public static function stock_quantity_required():bool {
        return self::status()['stock_quantity_required']!==false;
    }
    public static function filter_quantity_required($required,$domain='accommodation') {
        // Explicitly do not affect tours, transport, WooCommerce stock, or any
        // other product family; only verified, tenant-bound accommodation.
        if($domain!=='accommodation')return $required;
        return self::stock_quantity_required()? $required : false;
    }
    public static function boot():void {
        add_filter('mad4b/stay/stock_quantity_required',
            [__CLASS__,'filter_quantity_required'],10,2);
    }
}
MAD4B_Stay_Unmanaged_Stock_Policy_V1::boot();
}
