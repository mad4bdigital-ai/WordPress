<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Read-only product identity from the INSTALLED CPT Product Integration.
 * A Woo Product identity does not certify a rate, price, checkout or booking.
 * NEVER manufactures a separate post_type=product for a CPT service.
 */
final class ServiceProductIdentity
{
    public static function inspect(int $sourceId):array|\WP_Error
    {
        if (!current_user_can('manage_options') ||
            !current_user_can('manage_woocommerce'))
            return new \WP_Error('source_identity_operator_required');

        $source=get_post($sourceId);
        if (!$source || $source->post_status!=='publish')
            return new \WP_Error('source_identity_unavailable');

        // These are service roots; a rate is priced as part of the service
        // selection, not silently sold as a second independent product.
        $types=['tours-and-activities','properties',
                'transportations','cruises'];
        if (!in_array($source->post_type,$types,true))
            return new \WP_Error('source_identity_not_service');

        if (!function_exists('cptwooint') || !function_exists('wc_get_product'))
            return new \WP_Error('source_identity_cpt_provider_missing');
        $settings=get_option('cptwooint_settings',[]);
        $selected=is_array($settings)?
            ($settings['selected_post_types']??[]):[];
        if (!is_array($selected) ||
            !array_key_exists($source->post_type,$selected))
            return new \WP_Error('source_identity_cpt_type_not_enabled');

        $product=wc_get_product($sourceId);
        if (!($product instanceof \WC_Product) ||
            (int)$product->get_id()!==$sourceId ||
            get_post_type($product->get_id())!==$source->post_type)
            return new \WP_Error('source_identity_cpt_product_unreadable');

        return [
            'source_id'=>$sourceId,
            'source_type'=>$source->post_type,
            'woocommerce_product_id'=>$sourceId,
            'identity_owner'=>'cpt_product_integration',
            'data_owner'=>'original_service_cpt',
            'pricing_owner'=>'domain_rate_engine',
            'product_identity_verified'=>true,
            // A readable Product may expose a CPT metadata price. This is
            // not a signed quote or an approved booking rate.
            'wc_raw_price_is_approved_quote'=>false,
            'order_item_creation_authorized'=>false,
            'supplier_booking_authorized'=>false,
            'payment_authorized'=>false,
        ];
    }

    /** Inspection of an original Request is not authorization to write. */
    public static function previewRequest(int $requestId,?string $travelDate=null):array|\WP_Error
    {
        global $wpdb;
        $case=IntakeCaseRegistry::preview($requestId);
        if (is_wp_error($case))return $case;

        $t=$wpdb->prefix.'commerce_request_batches';
        $snapshot=$wpdb->get_var($wpdb->prepare(
            "SELECT snapshot_json FROM $t WHERE id=%d",$requestId));
        if (!is_string($snapshot) ||
            !hash_equals($case['source_sha256'],hash('sha256',$snapshot)))
            return new \WP_Error('source_identity_request_changed');

        $lines=json_decode($snapshot,true);
        if (!is_array($lines)||count($lines)<1||count($lines)>100)
            return new \WP_Error('source_identity_invalid_lines');

        if($travelDate!==null &&
            !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$travelDate))
            return new \WP_Error('source_identity_travel_date_invalid');
        $result=[];
        foreach ($lines as $index=>$line) {
            if (!is_array($line)||
                !isset($line['source_id'])||
                !is_numeric($line['source_id']))
                return new \WP_Error('source_identity_invalid_source');
            $identity=self::inspect((int)$line['source_id']);
            if (is_wp_error($identity))
                return $identity;
            $rate=(int)($line['rate_id']??0);
            if ($rate && !get_post($rate))
                return new \WP_Error('source_identity_rate_unavailable');
            $rateInfo=null;
            if($rate && $identity['source_type']==='tours-and-activities'){
                $rateInfo=TourRateEligibility::inspect(
                    $identity['source_id'],$rate,$travelDate);
                if(is_wp_error($rateInfo) &&
                   $rateInfo->get_error_code()==='rate_travel_date_invalid')
                    return $rateInfo;
            }
            $validRate=is_array($rateInfo);
            $resolution=null;
            if($travelDate!==null&&$rate&&$identity['source_type']==='tours-and-activities')
                $resolution=TourRateResolution::inspect(
                    $identity['source_id'],$travelDate,$rate);
            $result[]=[
                'line_index'=>$index,
                'source_id'=>$identity['source_id'],
                'woocommerce_product_id'=>$identity['woocommerce_product_id'],
                'rate_id'=>$rate?:null,
                'product_identity_verified'=>true,
                'rate_relationship_verified'=>$validRate,
                'rate_evidence_error'=>is_wp_error($rateInfo)?
                    $rateInfo->get_error_code():null,
                'rate_selection_eligible'=>$validRate?
                    $rateInfo['rate_selection_eligible']:false,
                'selected_rate_matches_original_request'=>$rate>0,
                'selected_rate_resolution_status'=>is_array($resolution)?
                    $resolution['status']:null,
                'selected_rate_resolution_error'=>is_wp_error($resolution)?
                    $resolution->get_error_code():null,
                'eligible_alternative_rate_ids'=>is_array($resolution)?
                    array_values(array_filter($resolution['eligible_rate_ids'],
                       static fn($candidate)=>$candidate!==$rate)):[],
                'rate_selection_blockers'=>$validRate?
                    $rateInfo['blockers']:['rate_relation_not_verified'],
                'rate_period_start_utc'=>$validRate?
                    $rateInfo['period_start_utc']:null,
                'rate_period_end_utc'=>$validRate?
                    $rateInfo['period_end_utc']:null,
                'rate_currency'=>$validRate?$rateInfo['currency']:null,
                'rate_relationship_certified'=>false,
                'quote_approved'=>false,
                'order_item_creation_authorized'=>false,
            ];
        }
        return [
            'request_id'=>$requestId,
            'source_channel'=>$case['source_channel'],
            'route_mode'=>$case['route_mode'],
            'items'=>$result,
            'all_product_identities_readable'=>true,
            'all_rates_approved'=>false,
            'woocommerce_line_items_bookable'=>false,
            'no_new_products_created'=>true,
        ];
    }
}
