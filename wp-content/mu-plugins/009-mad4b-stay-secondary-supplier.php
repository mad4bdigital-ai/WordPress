<?php
/**
 * Plugin Name: MAD4B Accommodation Supplier Priority — Secondary Self
 * Description: Additive supplier identity and read-only precedence policy for native accommodation pricing.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Stay_Secondary_Self_Supplier_V1', false)) {
final class MAD4B_Stay_Secondary_Self_Supplier_V1 {
    public const OPTION = 'mad4b_stay_supplier_precedence_v1';
    public const SUPPLIER = 'self';
    public const CONTRACT = 'mad4b.accommodation-supplier-precedence.v1';
    public const RESOLVE_CONTRACT = 'mad4b.stay-supplier-resolution.v1';

    public static function configured(): array {
        $raw = get_option(self::OPTION, null);
        if (!is_array($raw)) return [
            'schema'=>self::CONTRACT,
            'tenant_url'=>home_url(),
            'domain'=>'accommodation',
            'primary'=>'external_contract',
            'secondary'=>'self',
            'fallback'=>'only_when_primary_has_no_eligible_verified_offer',
            'operation'=>'preview_only',
            'booking_authorized'=>false,
            'pricing_activated'=>false,
            'self_fallback_enabled'=>false,
            'configuration_status'=>'default_not_persisted',
        ];
        return $raw;
    }
    public static function status(): array {
        // No implicit supplier registration on other tenant sites.
        if (!is_array(get_option(self::OPTION, null))) return [
            'contract'=>self::CONTRACT,'status'=>'not_configured',
            'checkout_ready'=>false,'supplier_order'=>[],'selected_supplier'=>null,
            'self_fallback_enabled'=>false
        ];
        $config=self::configured();
        $valid=($config['schema']??'')===self::CONTRACT
            &&($config['tenant_url']??'')===home_url()
            &&($config['domain']??'')==='accommodation'
            &&($config['primary']??'')==='external_contract'
            &&($config['secondary']??'')==='self'
            &&($config['operation']??'')==='preview_only'
            &&($config['fallback']??'')==='only_when_primary_has_no_eligible_verified_offer'
            &&($config['booking_authorized']??null)===false
            &&($config['pricing_activated']??null)===false;
        if (!$valid) return ['contract'=>self::CONTRACT,'status'=>'configuration_mismatch',
            'checkout_ready'=>false,'supplier_order'=>[],'selected_supplier'=>null];
        $registry=function_exists('BookingExperience\\supplier_integrations')
            ? \BookingExperience\supplier_integrations() : [];
        if(is_wp_error($registry))return ['contract'=>self::CONTRACT,
            'status'=>'supplier_registry_error','checkout_ready'=>false,
            'supplier_order'=>[],'selected_supplier'=>null];
        $external=[];
        foreach((array)$registry as $id=>$source) {
            if($id===self::SUPPLIER || !is_array($source)
               ||!in_array('accommodation',(array)($source['categories']??[]),true))continue;
            // Supplier availability is not inferred from a catalog entry or company claim.
            $external[]=[
                'id'=>$id,
                'label'=>(string)($source['name']??$id),
                'active'=>($source['active']??false)===true,
                'quote_connector'=>is_callable($source['quote']??null),
                'marketing_authorized'=>($source['marketing_authorized']??false)===true,
            ];
        }
        $connected=false;
        foreach($external as $x)if($x['active']&&$x['quote_connector'])$connected=true;
        $fallbackEnabled=($config['self_fallback_enabled']??false)===true;
        return [
            'contract'=>self::CONTRACT,
            'status'=>$fallbackEnabled?'self_fallback_active':'configured_preview_only',
            'domain'=>'accommodation',
            'supplier_order'=>[
                ['rank'=>1,'id'=>'external_contract','label'=>'External contracted supplier',
                 'status'=>$connected?'connector_registered':'not_connected',
                 'supplier_ids'=>array_values(array_map(static fn($x)=>$x['id'],$external)),
                 'verification_required'=>true],
                ['rank'=>2,'id'=>self::SUPPLIER,
                 'label'=>sanitize_text_field((string)get_option('blogname','Company')).' (Self)',
                 'status'=>function_exists('mad4b_stay_layered_price_preview_v1')
                    ?'native_preview_available':'native_resolver_unavailable',
                 'price_layers'=>class_exists('MAD4B_Stay_Layered_Pricing_V1',false)
                    ? MAD4B_Stay_Layered_Pricing_V1::PRIORITY : [],
                 'verification_required'=>true],
            ],
            'fallback_policy'=>'Only when no eligible, verified external quote exists',
            'self_fallback_enabled'=>$fallbackEnabled,
            'routing_supplier'=>$fallbackEnabled?'self':null,
            'selected_supplier'=>null,
            'external_suppliers'=>$external,
            'configured_external_quotes'=>0,
            'self_price_authorized'=>false,
            'checkout_ready'=>false,
            'mutation_performed'=>false,
        ];
    }
    /**
     * Actual supplier routing for authorized admin previews.
     * The external supplier adapter has no trusted, verified selling-price callback;
     * connection or discovery alone never establishes an eligible offer.
     * Fallback to the native self source does not certify a price or availability.
     */
    public static function resolve(array $input) {
        if(array_diff(array_keys($input),[
            'property_id','unit_id','checkin','nights','adults','children','board_type'
        ])) return new WP_Error('stay_supplier_unknown_field');
        foreach($input as $value)if(!is_scalar($value)||is_bool($value))
            return new WP_Error('stay_supplier_invalid_input');
        $rawProperty=$input['property_id']??0;
        $property=filter_var($rawProperty,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $unit=filter_var($input['unit_id']??0,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0]]);
        if($property===false||$unit===false)
            return new WP_Error('stay_supplier_invalid_entity');
        $property=(int)$property;$unit=(int)$unit;
        if(!current_user_can('commerce_manage_pricing')||
           !current_user_can('edit_post',$property)||
           ($unit&&!current_user_can('edit_post',$unit)))
            return new WP_Error('stay_supplier_forbidden');
        $policy=self::status();
        if(($policy['status']??'')!=='self_fallback_active'
            || ($policy['self_fallback_enabled']??false)!==true
            || ($policy['checkout_ready']??true)!==false)
            return new WP_Error('stay_supplier_fallback_not_enabled');
        $pStatus=get_post_status($property);
        if(get_post_type($property)!=='properties'||
           !in_array($pStatus,['publish','draft'],true))
            return new WP_Error('stay_supplier_property_ineligible');
        $uStatus='';
        if($unit){
            $uStatus=get_post_status($unit);
            if(get_post_type($unit)!=='units'||
                !in_array($uStatus,['publish','draft'],true))
                return new WP_Error('stay_supplier_unit_ineligible');
            if(!function_exists('abk_entity_map'))
                return new WP_Error('stay_supplier_relation_unavailable');
            $map=abk_entity_map();
            if(is_wp_error($map))return $map;
            global $wpdb;
            $table=$wpdb->prefix.$map['relation_table_suffix'];
            $linked=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE rel_id=%d AND parent_object_id=%d AND child_object_id=%d",
                (int)$map['relation_id'],$property,$unit));
            if($linked!==1)
                return new WP_Error('stay_supplier_relation_mismatch');
        }
        $externalConnected=false;
        foreach((array)($policy['external_suppliers']??[]) as $ext) {
            if(!empty($ext['active'])&&!empty($ext['quote_connector']))
                $externalConnected=true;
        }
        // Never equate an available connector with a verified, eligible quote.
        $reason=$externalConnected
            ?'external_price_verification_not_integrated'
            :'external_supplier_not_connected';
        $draft=$pStatus==='draft'||$uStatus==='draft';
        if($draft){
            $quote=[
                'contract'=>'mad4b.stay-layered-price.v1','provider_id'=>'self',
                'property_id'=>$property,'unit_id'=>$unit?:null,
                'selected_source'=>null,'price'=>null,
                'price_status'=>'draft_entity_pending_review',
                'meal_base'=>null,
                'requested_board'=>(string)($input['board_type']??''),
                'availability_verified'=>false,'checkout_ready'=>false,
                'quote_authorized'=>false,'mutation_performed'=>false
            ];
        }else{
            if(!function_exists('mad4b_stay_layered_price_preview_v1'))
                return new WP_Error('stay_supplier_native_resolver_missing');
            $quote=mad4b_stay_layered_price_preview_v1($input);
            if(is_wp_error($quote))return $quote;
            if(!is_array($quote)||($quote['provider_id']??null)!=='self'
                || ($quote['checkout_ready']??true)!==false
                || ($quote['quote_authorized']??true)!==false)
                return new WP_Error('stay_supplier_native_preview_invalid');
        }
        $price=$quote['price']??null;
        return [
            'contract'=>self::RESOLVE_CONTRACT,
            'selected_supplier'=>'self',
            'routing_supplier'=>'self',
            'fallback_used'=>true,
            'fallback_reason'=>$reason,
            'external_quotes_checked'=>0,
            'external_quote_status'=>$externalConnected
                ?'adapter_registered_but_not_price_certified':'not_connected',
            'price_supplier'=>$price?'self':null,
            'price_status'=>(string)($quote['price_status']??'unpriced'),
            'quote'=>$quote,
            'checkout_ready'=>false,
            'supplier_price_verified'=>false,
            'availability_verified'=>false,
            'mutation_performed'=>false,
        ];
    }

    public static function register(array $sources): array {
        // Tenant-neutral file, site-specific opt-in through a verified option.
        $cfg=get_option(self::OPTION, null);
        if(!is_array($cfg)
           || ($cfg['schema']??'')!==self::CONTRACT
           || ($cfg['tenant_url']??'')!==home_url()
           || ($cfg['domain']??'')!=='accommodation'
           || ($cfg['primary']??'')!=='external_contract'
           || ($cfg['secondary']??'')!=='self'
           || ($cfg['fallback']??'')!=='only_when_primary_has_no_eligible_verified_offer'
           || ($cfg['operation']??'')!=='preview_only'
           || ($cfg['booking_authorized']??null)!==false
           || ($cfg['pricing_activated']??null)!==false) return $sources;
        foreach($sources as $entry) {
            if(is_array($entry)&&($entry['id']??null)===self::SUPPLIER) return $sources;
        }
        if(isset($sources[self::SUPPLIER]))return $sources;
        // Company-owned supplier is a separate read-only native price source.
        // Do not expose as a supplier catalog, booking quote or approved retail rate.
        $sources[self::SUPPLIER]=[
            'id'=>self::SUPPLIER,
            'name'=>sanitize_text_field((string)get_option('blogname','Company')).' (Self)',
            'active'=>true,
            'marketing_authorized'=>false,
            'markets'=>[],
            'categories'=>['accommodation'],
            'catalog'=>null,
            'quote'=>null,
        ];
        return $sources;
    }
}
add_filter('booking_experience_supplier_integrations',
    ['MAD4B_Stay_Secondary_Self_Supplier_V1','register'],20,1);
}
