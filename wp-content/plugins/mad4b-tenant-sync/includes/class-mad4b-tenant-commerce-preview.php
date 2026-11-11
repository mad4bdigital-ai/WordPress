<?php
/**
 * Commerce Composition preview: read-only. No Woo filters, no financial writes.
 * Source quote authority remains the native WooCommerce/Rate provider.
 */
defined('ABSPATH')||exit;
final class MAD4B_Tenant_Commerce_Preview {
    const CONTRACT='mad4b.tenant-commerce-preview.v1';
    public static function rate($rate_id,$policy=null) {
        $id=absint($rate_id);
        $result=[
            'contract'=>self::CONTRACT,'rate_id'=>$id,
            'status'=>'requires_review','source'=>'native_woocommerce_rate_cpt',
            'blockers'=>[],'price_preview'=>null,
            'commerce_quote_authorized'=>false,
            'checkout_authorized'=>false,'order_mutated'=>false,
            'woocommerce_price_overridden'=>false,'mutation_performed'=>false
        ];
        if(get_post_type($id)!=='tour-rates'||get_post_status($id)!=='publish'){
            $result['blockers'][]='rate_not_published';return $result;
        }
        $parent=absint(get_post_meta($id,'related_tour_id',true));
        $result['parent_tour_id']=$parent;
        if(!$parent||get_post_type($parent)!=='tours-and-activities'
            ||get_post_status($parent)!=='publish')
            $result['blockers'][]='parent_tour_identity_unverified';
        if(!class_exists('MAD4B_Hub_Source_Reader'))
            $result['blockers'][]='hub_reference_reader_unavailable';
        else {
            $candidate=MAD4B_Hub_Source_Reader::candidate($id,'double');
            if(is_wp_error($candidate))$result['blockers'][]=$candidate->get_error_code();
            else {
                $result['source_price_basis']=$candidate['source']['price_basis'];
                $result['source_reference_price_minor']=$candidate['source']['amount_minor'];
                $result['source_currency']=$candidate['source']['currency'];
                $result['global_offer_id']=$candidate['global_offer_id'];
                // Calculation allowed as a NON-authoritative scenario only:
                // a source candidate is NOT a shared offer publication.
                if(is_array($policy) && class_exists('MAD4B_Tenant_Price_Engine')) {
                    $site=MAD4B_Tenant_Sync::profile();
                    $demo=[
                        'global_offer_id'=>$candidate['global_offer_id'],
                        'source_version'=>$candidate['source_version'],
                        'price_basis'=>$candidate['source']['price_basis'],
                        'amount_minor'=>$candidate['source']['amount_minor'],
                        'currency'=>$candidate['source']['currency'],
                        'stop_sale'=>$candidate['source']['stop_sale'],
                        'share_state'=>'approved',
                        'allowed_tenants'=>[$site['site_uuid']]
                    ];
                    $result['price_preview']=MAD4B_Tenant_Price_Engine::preview(
                        $demo,$policy,$site['site_uuid']);
                    $result['simulation_approval_ephemeral']=true;
                }
            }
        }
        if(!function_exists('wc_get_product') || !wc_get_product($id))
            $result['blockers'][]='woo_sellable_rate_identity_not_certified';
        $result['blockers'][]='signed_shared_price_publication_missing';
        $result['blockers'][]='supplier_cost_or_reference_basis_not_certified_for_commerce';
        $result['blockers'][]='taxes_not_verified';
        $result['blockers'][]='confirmed_inventory_not_verified';
        $result['blockers'][]='booking_and_order_item_identity_not_certified';
        return $result;
    }
}
