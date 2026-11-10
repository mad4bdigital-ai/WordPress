<?php
/**
 * Pure, deterministic minor-unit price composition.
 * Pricing is a PREVIEW unless certified commerce adapter explicitly consumes
 * an owner-approved policy and performs a fresh provider quote.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Price_Engine {
    const CONTRACT = 'mad4b.tenant-price-preview.v1';
    private static function error($reason) {
        return ['contract'=>self::CONTRACT,'status'=>'requires_review',
            'reason'=>$reason,'checkout_authorized'=>false,'mutation_performed'=>false];
    }
    private static function integer($x,$min,$max) {
        if (!is_int($x) && !(is_string($x) && preg_match('/^(0|[1-9][0-9]*)$/D',$x))) return null;
        if (is_string($x) && strlen($x)>15) return null;
        $v=(int)$x;
        return $v>=$min && $v<=$max ? $v : null;
    }
    private static function ceildiv($a,$b) {
        return intdiv($a,$b)+(($a%$b)>0?1:0);
    }
    public static function preview($offer,$policy,$tenant_site_uuid,$fx=null) {
        if (!is_array($offer)||!is_array($policy)) return self::error('invalid_contract');
        if (!preg_match('/^[a-f0-9-]{36}$/D',(string)$tenant_site_uuid)) return self::error('invalid_tenant_identity');
        if (($offer['share_state']??'')!=='approved') return self::error('offer_not_approved_for_sharing');
        $allow=$offer['allowed_tenants']??[];
        if (!is_array($allow)||!in_array($tenant_site_uuid,$allow,true)) return self::error('tenant_not_permitted');
        if (($offer['stop_sale']??true)!==false) return self::error('source_stop_sale_or_unverified');
        if (empty($policy['enabled'])) return self::error('tenant_pricing_policy_disabled');
        $source_kind=(string)($offer['price_basis']??'');
        if (!in_array($source_kind,['supplier_cost','wholesale','reference_retail'],true))
            return self::error('price_basis_not_classified');
        $currency=(string)($offer['currency']??'');
        $target=(string)($policy['display_currency']??'');
        if (!preg_match('/^[A-Z]{3}$/D',$currency)||!preg_match('/^[A-Z]{3}$/D',$target))
            return self::error('currency_not_verified');
        $amount=self::integer($offer['amount_minor']??null,1,100000000000);
        if ($amount===null) return self::error('source_amount_missing');
        $model=(string)($policy['model']??'');
        if (!in_array($model,['markup','target_margin'],true)) return self::error('pricing_model_invalid');
        $basis=self::integer($policy['basis_points']??null,0,$model==='markup'?30000:8500);
        $fee=self::integer($policy['fixed_fee_minor']??null,0,1000000000);
        $minprofit=self::integer($policy['minimum_profit_minor']??null,0,1000000000);
        $round=self::integer($policy['rounding_increment_minor']??null,1,1000000);
        if ($basis===null||$fee===null||$minprofit===null||$round===null) return self::error('tenant_policy_invalid');
        if ($model==='target_margin' && $source_kind!=='supplier_cost')
            return self::error('target_margin_requires_verified_supplier_cost');
        if ($minprofit>0 && $source_kind!=='supplier_cost')
            return self::error('minimum_profit_requires_verified_supplier_cost');
        $converted=$amount;
        $fx_version='same_currency';
        if ($currency!==$target) {
            if (!is_array($fx)||($fx['from']??'')!==$currency||($fx['to']??'')!==$target
                ||($fx['certified']??false)!==true ||empty($fx['version']))
                return self::error('certified_fx_required');
            $n=self::integer($fx['numerator']??null,1,10000000);
            $d=self::integer($fx['denominator']??null,1,10000000);
            if ($n===null||$d===null||$amount>intdiv(PHP_INT_MAX,$n))
                return self::error('fx_ratio_invalid');
            $converted=self::ceildiv($amount*$n,$d);
            $fx_version=(string)$fx['version'];
        }
        if ($converted<1||$converted>100000000000||$converted>intdiv(PHP_INT_MAX,40000))
            return self::error('converted_amount_out_of_range');
        $start=$model==='markup'
            ? self::ceildiv($converted*(10000+$basis),10000)
            : self::ceildiv($converted*10000,10000-$basis);
        if ($start>PHP_INT_MAX-$fee) return self::error('price_overflow');
        $raw=$start+$fee;
        $floor=$converted+$minprofit;
        $raw=max($raw,$floor);
        if ($raw>PHP_INT_MAX-$round) return self::error('rounding_overflow');
        $display=self::ceildiv($raw,$round)*$round;
        if ($display>100000000000) return self::error('display_amount_out_of_range');
        $profit=$display-$converted;
        if ($profit<0) return self::error('negative_profit');
        $digest_data=[
            'global_offer_id'=>(string)($offer['global_offer_id']??''),
            'source_version'=>(string)($offer['source_version']??''),
            'tenant_site_uuid'=>$tenant_site_uuid,
            'source_minor'=>$amount,
            'source_currency'=>$currency,
            'policy'=>$policy,
            'fx_version'=>$fx_version,
        ];
        return [
            'contract'=>self::CONTRACT,
            'status'=>'preview_only',
            'global_offer_id'=>$digest_data['global_offer_id'],
            'source_version'=>$digest_data['source_version'],
            'tenant_site_uuid'=>$tenant_site_uuid,
            'source_basis'=>$source_kind,
            'source_amount_minor'=>$amount,
            'source_currency'=>$currency,
            'converted_basis_minor'=>$converted,
            'display_currency'=>$target,
            'display_price_minor'=>$display,
            'difference_from_source_minor'=>$profit,
            'estimated_gross_profit_minor'=>$source_kind==='supplier_cost'?$profit:null,
            'effective_gross_margin_basis_points'=>$source_kind==='supplier_cost'
                ? intdiv($profit*10000,$display):null,
            'model'=>$model,
            'policy_sha256'=>hash('sha256',json_encode($policy,JSON_UNESCAPED_SLASHES)),
            'quote_sha256'=>hash('sha256',json_encode($digest_data,JSON_UNESCAPED_SLASHES)),
            'fx_version'=>$fx_version,
            'source_cost_verified'=>$source_kind==='supplier_cost',
            'tax_validated'=>false,
            'inventory_validated'=>false,
            'checkout_authorized'=>false,
            'mutation_performed'=>false,
        ];
    }
}
