<?php
/**
 * Plugin Name: MAD4B Price Evaluator Shadow
 * Version: 1.0.0
 * Description: Deterministic internal cost-to-price preview. No public price, Rate, payment, or checkout integration.
 */
defined('ABSPATH') || exit;

final class MAD4B_Price_Evaluator_Shadow_V1 {
    public const CONTRACT='mad4b.cost-to-price-shadow.v1';
    public const STAGES=[
        'base_markup'=>0,'base_margin'=>0,
        'market_markup'=>1,'tour_type_markup'=>2,
        'package_markup'=>3,'promotion_discount'=>4,
    ];
    /**
     * Integer minor-unit math. A basis point is 0.01%; e.g. 2000 = 20%.
     * This class never obtains commercial authority from caller-supplied policy
     * flags: all resulting prices remain internal simulations only.
     *
     * @return array|WP_Error
     */
    public static function simulate($composition,array $policies,int $taxBps=0) {
        if (!is_array($composition)||
            ($composition['contract']??'')!=='mad4b.component-cost-composition.preview.v1' ||
            ($composition['ready_for_provider_reverification']??false)!==true ||
            ($composition['source_mode']??'')!=='supplier_cost_only' ||
            ($composition['rate_generation_ready']??true)!==false ||
            !is_int($composition['supplier_cost_total_minor']??null) ||
            $composition['supplier_cost_total_minor']<0 ||
            $composition['supplier_cost_total_minor']>1000000000000)
            return new WP_Error('pricing_shadow_cost_not_proven');
        if (count($policies)<1||count($policies)>6)
            return new WP_Error('pricing_shadow_policy_count_invalid');
        if ($taxBps<0||$taxBps>3000)
            return new WP_Error('pricing_shadow_tax_invalid');
        $found=[];$ids=[];$rules=[];
        foreach($policies as $p){
            if(!is_array($p))return new WP_Error('pricing_shadow_policy_invalid');
            $kind=(string)($p['kind']??'');
            $id=(string)($p['id']??'');
            $basis=$p['basis_points']??null;
            if(!isset(self::STAGES[$kind]) ||
                !preg_match('/^[a-z0-9][a-z0-9:_-]{2,100}$/D',$id) ||
                isset($ids[$id]) || isset($found[self::STAGES[$kind]]) ||
                !is_int($basis) ||
                !self::allowed($kind,$basis) ||
                ($p['status']??'')!=='approved' ||
                !is_int($p['revision']??null) || $p['revision']<1 ||
                !preg_match('/^[a-f0-9]{64}$/D',(string)($p['source_sha256']??'')))
                return new WP_Error('pricing_shadow_duplicate_or_unsafe_policy');
            $ids[$id]=true;
            $found[self::STAGES[$kind]]=true;
            $rules[]=['id'=>$id,'kind'=>$kind,'basis_points'=>$basis,
                'source_sha256'=>$p['source_sha256'],'revision'=>$p['revision']];
        }
        if(!isset($found[0]))
            return new WP_Error('pricing_shadow_base_policy_required');
        usort($rules,static fn($a,$b)=>
            self::STAGES[$a['kind']]<=>self::STAGES[$b['kind']]);
        $amount=$composition['supplier_cost_total_minor'];
        $steps=[];
        foreach($rules as $p){
            $before=$amount;
            $bps=$p['basis_points'];
            if($p['kind']==='base_margin'){
                $amount=self::mulDivRounded($before,10000,10000-$bps);
            }elseif($p['kind']==='promotion_discount'){
                $amount=self::mulDivRounded($before,10000-$bps,10000);
            }else{
                $amount=self::mulDivRounded($before,10000+$bps,10000);
            }
            if(is_wp_error($amount))return $amount;
            $steps[]=[
                'id'=>$p['id'],'kind'=>$p['kind'],'policy_basis_points'=>$bps,
                'before_minor'=>$before,'after_minor'=>$amount,
                'source_sha256'=>$p['source_sha256'],
            ];
        }
        $subtotal=$amount;
        $tax=$taxBps>0?self::mulDivRounded($subtotal,$taxBps,10000):0;
        if(is_wp_error($tax))return $tax;
        if($subtotal>PHP_INT_MAX-$tax)
            return new WP_Error('pricing_shadow_total_overflow');
        return [
            'contract'=>self::CONTRACT,
            'tour_id'=>$composition['tour_id'],
            'service_date'=>$composition['service_date'],
            'currency'=>$composition['supplier_cost_currency'],
            'supplier_cost_minor'=>$composition['supplier_cost_total_minor'],
            'subtotal_minor'=>$subtotal,'tax_basis_points'=>$taxBps,
            'tax_minor'=>$tax,'simulated_total_minor'=>$subtotal+$tax,
            'steps'=>$steps,
            'calculation_order'=>'cost > margin_or_markup > market > tour_type > package > promotion > tax',
            'preview_only'=>true,'rate_generation_ready'=>false,
            'checkout_ready'=>false,'retail_price_changed'=>false,
            'provider_reverification_required'=>true,
            'blockers'=>['provider_quote_reverification_required',
                'commercial_approval_missing','native_rate_generation_not_connected'],
        ];
    }
    private static function allowed(string $kind,int $bps):bool {
        if($bps<0)return false;
        if($kind==='base_margin')return $bps<=8000;
        if($kind==='promotion_discount')return $bps<=9000;
        return $bps<=50000;
    }
    private static function mulDivRounded(int $num,int $factor,int $denom) {
        if($denom<1||$factor<0||$num<0||
            ($num>0 && $factor>intdiv(PHP_INT_MAX-5000,$num)))
            return new WP_Error('pricing_shadow_math_overflow');
        return intdiv($num*$factor+intdiv($denom,2),$denom);
    }
}
