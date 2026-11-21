<?php
/**
 * Plugin Name: MAD4B Verified Component Cost Contract
 * Version: 1.0.0
 * Description: Read-only supplier-cost receipt validation; never prices or activates Tour Rates.
 */
defined('ABSPATH') || exit;

final class MAD4B_Component_Cost_Contract_V1 {
    public const SCHEMA = 'mad4b.component-cost-evidence.v1';
    public const KINDS = ['accommodation','transportation','guide','extras'];
    public const CURRENCIES = ['EGP','USD','EUR','GBP'];

    /**
     * Accepts only scoped supplier-COST evidence, not prices or user-entered
     * policy options. The supplied receipt MUST be revalidated by the native
     * provider before any commercial authorization is considered.
     *
     * @return array|WP_Error
     */
    public static function validate(array $receipt, int $tour, string $date) {
        if (($receipt['schema']??'') !== self::SCHEMA)
            return new WP_Error('cost_evidence_schema_invalid');
        if (get_post_type($tour) !== 'tours-and-activities' ||
            get_post_status($tour)!=='publish')
            return new WP_Error('cost_evidence_tour_invalid');
        if (!self::validDate($date))
            return new WP_Error('cost_evidence_date_invalid');
        if ((int)($receipt['tour_id']??0) !== $tour ||
            (string)($receipt['service_date']??'') !== $date)
            return new WP_Error('cost_evidence_scope_mismatch');

        $kind=(string)($receipt['kind']??'');
        $currency=(string)($receipt['currency']??'');
        $role=(string)($receipt['role']??'');
        $source=(string)($receipt['source']??'');
        $line=(string)($receipt['line_key']??'');
        $supplier=(string)($receipt['supplier_id']??'');
        if (!in_array($kind,self::KINDS,true) ||
            !in_array($currency,self::CURRENCIES,true) ||
            $role!=='supplier_cost' ||
            !in_array($source,['self_cost','verified_supplier_contract'],true) ||
            !preg_match('/^[a-z0-9][a-z0-9._:-]{1,100}$/D',$line) ||
            !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{1,100}$/D',$supplier))
            return new WP_Error('cost_evidence_identity_invalid');
        if (($receipt['status']??'') !== 'approved' ||
            !is_int($receipt['amount_minor']??null) ||
            $receipt['amount_minor']<0 ||
            !is_int($receipt['quantity']??null) ||
            $receipt['quantity']<1 || $receipt['quantity']>500 ||
            ($receipt['amount_minor']===0 &&
                ($receipt['zero_cost_reason']??'')!=='certified_not_required'))
            return new WP_Error('cost_evidence_amount_or_approval_invalid');
        if (!is_int($receipt['reviewer_id']??null) ||
            $receipt['reviewer_id']<1 ||
            !is_int($receipt['revision']??null) ||
            $receipt['revision']<1 ||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($receipt['source_sha256']??'')) ||
            !self::validTimestamp((string)($receipt['approved_at']??'')))
            return new WP_Error('cost_evidence_review_invalid');

        $expires=$receipt['valid_until']??null;
        if ($expires!==null &&
            (!self::validDate($expires) || strcmp($expires,$date)<0))
            return new WP_Error('cost_evidence_expired');
        $units=(int)$receipt['quantity'];
        $amount=(int)$receipt['amount_minor'];
        if ($amount > intdiv(PHP_INT_MAX,$units))
            return new WP_Error('cost_evidence_amount_overflow');
        return [
            'schema'=>self::SCHEMA,'tour_id'=>$tour,'service_date'=>$date,
            'kind'=>$kind,'line_key'=>$line,'supplier_id'=>$supplier,
            'source'=>$source,'currency'=>$currency,
            'amount_minor'=>$amount,'quantity'=>$units,
            'line_total_minor'=>$amount*$units,'approved_at'=>$receipt['approved_at'],
            'source_sha256'=>$receipt['source_sha256'],'revision'=>$receipt['revision'],
            'reviewer_id'=>$receipt['reviewer_id'],
            'provider_reverification_required'=>true,
            'price_role'=>'supplier_cost_only',
            'retail_rate_amount_used_as_cost'=>false,
        ];
    }
    private static function validDate($value): bool {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d\d)-(\d\d)$/D',$value,$m))
            return false;
        return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
    }
    private static function validTimestamp(string $value): bool {
        if ($value==='' || strlen($value)>48)return false;
        try{
            $t=new DateTimeImmutable($value);
            return $t->getTimestamp()>0;
        }catch(Throwable $e){return false;}
    }
    /**
     * A deterministic cost-only preview. Never grants native publishing,
     * checkout or payment authority, even with a complete mocked receipt set.
     *
     * @return array|WP_Error
     */
    public static function compose(int $tour,string $date,array $receipts) {
        if (!self::validDate($date)) return new WP_Error('cost_evidence_date_invalid');
        if (count($receipts)>250)
            return new WP_Error('cost_evidence_receipt_limit_exceeded');
        $rows=[];$kinds=[];$currencies=[];$keys=[];
        foreach($receipts as $receipt){
            if(!is_array($receipt))return new WP_Error('cost_evidence_receipt_invalid');
            $result=self::validate($receipt,$tour,$date);
            if(is_wp_error($result))return $result;
            if(isset($keys[$result['line_key']]))
                return new WP_Error('cost_evidence_duplicate_line');
            $keys[$result['line_key']]=true;
            $kinds[$result['kind']]=true;
            $currencies[$result['currency']]=true;
            $rows[]=$result;
        }
        $missing=array_values(array_diff(self::KINDS,array_keys($kinds)));
        $mixed=count($currencies)>1;
        if ($mixed)return new WP_Error('cost_evidence_fx_required');
        $sum=0;
        foreach($rows as $row){
            if($row['line_total_minor']>PHP_INT_MAX-$sum)
                return new WP_Error('cost_evidence_total_overflow');
            $sum+=$row['line_total_minor'];
        }
        $currency=array_key_first($currencies);
        $complete=!$missing && $currency!==null;
        return [
            'contract'=>'mad4b.component-cost-composition.preview.v1',
            'tour_id'=>$tour,'service_date'=>$date,
            'lines'=>$rows,'missing_components'=>$missing,
            'supplier_cost_total_minor'=>$complete?$sum:null,
            'supplier_cost_currency'=>$complete?$currency:null,
            'ready_for_provider_reverification'=>$complete,
            'rate_generation_ready'=>false,'checkout_ready'=>false,
            'retail_price_changed'=>false,
            'source_mode'=>'supplier_cost_only',
            'blockers'=>$complete?
                ['provider_reverification_and_margin_tax_fx_required']:
                array_map(static fn($v)=>'cost_evidence_missing:'.$v,$missing),
        ];
    }
}
