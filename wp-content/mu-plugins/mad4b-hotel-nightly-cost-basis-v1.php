<?php
/**
 * Plugin Name: MAD4B Hotel Cost Nightly Basis (Source-Verified)
 * Description: Binds the normalized per-person/night cost unit to the owner-approved 253-cell Hotels sheet receipt. Does not authorize checkout or supplier stock.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Hotel_Nightly_Cost_Basis_V1',false)) {
final class MAD4B_Hotel_Nightly_Cost_Basis_V1 {
    public const CONTRACT='mad4b.hotel-nightly-cost-unit-declaration.v1';
    public const OPTION='mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1';
    public static function evidence(int $id):array {
        if(get_post_type($id)!=='property-rates')return ['valid'=>false,'reason'=>'not_property_rate'];
        if((string)get_post_meta($id,'mad4b_rate_scope_v1',true)!=='property' ||
           (int)get_post_meta($id,'prop_related_unit_id',true)!==0 ||
           (string)get_post_meta($id,'mad4b_rate_price_kind_v1',true)!=='cost')
            return ['valid'=>false,'reason'=>'not_property_standard_cost'];
        $owner=get_post_meta($id,'_mad4b_supplier_sheet_proposal_v1',true);
        $approval=get_post_meta($id,'_mad4b_self_provider_owner_cost_approval_v1',true);
        $receipt=get_option(self::OPTION);
        if(!is_array($owner)||($owner['schema']??'')!=='mad4b.property-wide-rate-proposal.v1'||
           !is_array($approval)||($approval['supplier']??'')!=='self'||
           ($approval['cost_value_source_approved']??null)!==true||
           !is_array($receipt)||
           ($receipt['contract']??'')!=='mad4b.company-owner-approved-inferred-hotel-nightly-cost-basis.v1'||
           ($receipt['site']??'')!==home_url()||
           ($receipt['basis']??'')!=='per_person_per_night'||
           ($receipt['method']??'')!=='owner_approved_uniform_hotels_template_inference'||
           (int)($receipt['sheet_cell_count']??0)!==253 ||
           count((array)($receipt['entries']??[]))!==253)
            return ['valid'=>false,'reason'=>'original_owner_basis_evidence_missing'];
        $cell=(string)($owner['source_cell']??'');
        $amount=get_post_meta($id,'base_amount',true);
        if(!preg_match('/^[CHM][0-9]{1,4}$/D',$cell)||!is_numeric($amount)||
           (float)$amount<=0||(string)get_post_meta($id,'currency',true)!=='USD'||
           ($owner['price_unit']??'')!=='per_person'||
           ($owner['source_sheet_id']??'')!==($approval['source_sheet_id']??'')||
           ($owner['source_cell']??'')!==($approval['source_cell']??''))
            return ['valid'=>false,'reason'=>'rate_source_amount_or_identity_mismatch'];
        $matches=[];
        foreach($receipt['entries'] as $e){
            if(is_array($e) && ($e['cell']??'')===$cell)$matches[]=$e;
        }
        if(count($matches)!==1)return ['valid'=>false,'reason'=>'source_cell_missing_or_duplicate'];
        $e=$matches[0];
        if(($e['source_entity']??null)!==($owner['source_entity']??null)||
           ($e['season']??null)!==($owner['source_season']??null)||
           ($e['occupancy']??null)!==($owner['occupancy']??null)||
           ($e['rate_basis']??null)!=='per_person_per_night'||
           ($e['evidence']??null)!=='uniform_hotels_template_owner_approved'||
           (int)($e['source_cost_usd_cents']??0)!==(int)round((float)$amount*100)||
           ($e['checkout_authorized']??true)!==false)
            return ['valid'=>false,'reason'=>'approved_sheet_cell_mismatch'];
        return ['valid'=>true,'basis'=>'per_person_night','source_cell'=>$cell,
            'source_cost_usd_cents'=>(int)$e['source_cost_usd_cents'],
            'occupancy'=>(string)$e['occupancy'],
            'meal_plan'=>(string)($owner['meal_plan']??''),
            'source'=>'existing_owner_approved_uniform_hotels_basis',
            'cost_receipt_approved'=>false,'checkout_ready'=>false];
    }
    public static function effective(int $id):bool {
        if((string)get_post_meta($id,'prop_price_unit',true)!=='per_person_night')return false;
        $record=get_post_meta($id,'_mad4b_hotel_cost_basis_normalized_v1',true);
        $e=self::evidence($id);
        return !empty($e['valid']) && is_array($record) &&
            ($record['contract']??'')===self::CONTRACT &&
            (int)($record['rate_id']??0)===$id &&
            ($record['original_unit']??'')==='per_person' &&
            ($record['normalized_unit']??'')==='per_person_night' &&
            ($record['source_cell']??'')===$e['source_cell'] &&
            (int)($record['source_cost_usd_cents']??0)===$e['source_cost_usd_cents'] &&
            ($record['scope']??'')==='property_cost_not_retail' &&
            ($record['checkout_authorized']??true)===false;
    }
}
}
