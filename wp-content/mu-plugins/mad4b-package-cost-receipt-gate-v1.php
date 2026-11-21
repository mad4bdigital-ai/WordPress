<?php
/**
 * Plugin Name: MAD4B Package Cost Receipt Gate
 * Version: 1.0.0
 * Description: Fail-closed source cost admission (date, currency, occupancy, availability, identity, authority).
 * This plugin NEVER creates, signs or approves supplier receipts.
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Package_Cost_Receipt_Gate_V1', false)) {
final class MAD4B_Package_Cost_Receipt_Gate_V1 {
    public const CONTRACT = 'mad4b.supplier-cost-receipt.v1';
    private const CURRENCIES = ['EGP','USD','EUR','GBP'];
    private const KIND_BASIS = [
        'hotel'=>['per_room_night','per_person_night'],
        'resort'=>['per_room_night','per_person_night'],
        'cruise'=>['per_cabin_night','per_person_night'],
        'sleeper_train'=>['per_cabin_trip','per_person_trip'],
    ];
    public static function validate($receipt, array $context):array {
        $errors=[];
        $fail=static function(string $code)use(&$errors):void { $errors[$code]=$code; };
        if (!is_array($receipt)) return ['qualified'=>false,'blockers'=>['receipt_missing'],'contract'=>self::CONTRACT];
        if (($receipt['contract']??null)!==self::CONTRACT || ($receipt['status']??null)!=='approved') $fail('receipt_not_approved');
        $kind=(string)($context['kind']??'');
        if (!isset(self::KIND_BASIS[$kind]) || ($receipt['kind']??'')!==$kind ||
            !in_array($receipt['basis']??'',self::KIND_BASIS[$kind]??[],true)) $fail('receipt_kind_or_basis_invalid');
        $tour=(int)($context['tour_id']??0);
        $key=(string)($context['stage_key']??'');
        if ($tour<=0 || $key==='' ||
            (int)($receipt['tour_id']??0)!==$tour || ($receipt['stage_key']??'')!==$key) $fail('receipt_wrong_tour_or_stage');
        $currency=(string)($context['currency']??'');
        if (!in_array($currency,self::CURRENCIES,true) ||
            ($receipt['currency']??'')!==$currency) $fail('receipt_currency_mismatch_or_unverified_fx');
        $amount=$receipt['cost_minor']??null;
        if (!is_int($amount) || $amount<1 || $amount>1000000000 || ($receipt['amount_role']??'')!=='supplier_cost_not_retail')
            $fail('receipt_cost_amount_not_verified');
        // A Self operator is permitted only with real documentary cost, never a guessed fallback.
        $mode=(string)($receipt['supplier_mode']??'');
        if (!in_array($mode,['self','external'],true) || !is_string($receipt['supplier_ref']??null) ||
            trim((string)$receipt['supplier_ref'])==='') $fail('receipt_supplier_identity_missing');
        if ($mode==='self' && ($receipt['cost_method']??'')!=='documented_actual')
            $fail('self_supplier_actual_cost_document_missing');
        if (($receipt['cost_method']??'')==='estimate' || ($receipt['source_is_estimate']??true)!==false)
            $fail('receipt_estimate_not_admissible');
        $sha=(string)($receipt['source_document_sha256']??'');
        if (!preg_match('/^[a-f0-9]{64}$/D',$sha) ||
            !is_string($receipt['source_document_ref']??null) ||
            trim((string)$receipt['source_document_ref'])==='' ||
            !in_array($receipt['source_document_kind']??'',['contract','supplier_invoice','actual_cost_record'],true))
            $fail('receipt_document_evidence_missing');
        $date=self::date((string)($context['service_date']??''));
        $last=self::date((string)($context['last_service_date']??''));
        $from=self::date((string)($receipt['valid_from']??''));
        $until=self::date((string)($receipt['valid_to']??''));
        if (!$date||!$last||!$from||!$until ||
            $last<$date || $from>$date || $until<$last)
            $fail('receipt_service_date_not_covered');
        $qty=['adults','children','infants'];
        $pax=$receipt['occupancy']??null;
        if (!is_array($pax)) $fail('receipt_occupancy_missing');
        else foreach($qty as $field) {
            if (!isset($pax[$field])||!is_int($pax[$field]) ||
                $pax[$field] !== (int)($context[$field]??-1))
                $fail('receipt_occupancy_mismatch');
        }
        $unitId=(int)($receipt['unit_id']??0);
        if ($kind==='hotel'||$kind==='resort') {
            if ((int)($receipt['property_id']??0)<1 ||
                (int)($receipt['property_id']??0)!==(int)($context['property_id']??0) ||
                get_post_type((int)($receipt['property_id']??0))!=='properties' ||
                $unitId<1 || get_post_type($unitId)!=='units' ||
                !in_array($unitId,array_map('intval',(array)($context['unit_ids']??[])),true))
                $fail('receipt_room_property_unit_mismatch');
            if (!in_array($receipt['meal_plan']??null,['BB','HB','FB','AI','SAI','RO'],true))
                $fail('receipt_meal_plan_missing');
        } elseif ($kind==='cruise') {
            if ((int)($receipt['vessel_id']??0)<1 ||
                (int)($receipt['vessel_id']??0)!==(int)($context['vessel_id']??0) ||
                (int)($receipt['cabin_unit_id']??0)<1 ||
                (int)($receipt['cabin_unit_id']??0)!==(int)($context['cabin_unit_id']??0) ||
                get_post_type((int)($receipt['vessel_id']??0))!=='properties' ||
                get_post_type((int)($receipt['cabin_unit_id']??0))!=='units' ||
                empty($context['cruise_program']) ||
                ($receipt['cruise_program']??null)!==$context['cruise_program'] ||
                ($receipt['sailing_date']??null)!==$context['service_date'])
                $fail('receipt_cruise_program_cabin_or_departure_missing');
        } elseif ($kind==='sleeper_train') {
            $train=(int)($receipt['train_id']??0);
            if ($train<1 || $train!==(int)($context['train_id']??0) ||
                !in_array((string)($receipt['cabin_class']??''),['Standard','Elite'],true) ||
                ($receipt['cabin_class']??'')!==($context['cabin_class']??'') ||
                ($receipt['service_date']??null)!==$context['service_date'])
                $fail('receipt_train_or_cabin_mismatch');
            if (get_post_type($train)!=='transportations' ||
                (string)get_post_meta($train,'trans_train_number',true)!==(string)($context['train_number']??''))
                $fail('receipt_train_identity_invalid');
            // The booked leg must match the original stage itinerary and its
            // native, source-owned proposal. A train's first station is NOT
            // necessarily the passenger's boarding station (e.g. 87 from Luxor).
            $boarding=(int)($receipt['boarding_station_id']??0);
            $arrival=(int)($receipt['arrival_station_id']??0);
            if ($boarding<=0 || $arrival<=0 ||
                $boarding===$arrival ||
                $boarding!==(int)($context['boarding_station_id']??0) ||
                $arrival!==(int)($context['arrival_station_id']??0))
                $fail('receipt_train_stage_segment_unverified');
            $fromStops=[];$toStops=[];
            foreach((array)get_post_meta($train,'trans_train_stops',true) as $stop){
                if(!is_array($stop))continue;
                $id=(int)($stop['station_id']??0);
                $order=$stop['sort_num']??null;
                $time=(string)($stop['time']??'');
                if($id<1 || !is_numeric($order) || (int)$order<1 ||
                   !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time) ||
                   get_post_type($id)!=='stations' || get_post_status($id)!=='publish')
                    continue;
                $entry=['order'=>(int)$order,'time'=>$time];
                if($id===$boarding)$fromStops[]=$entry;
                if($id===$arrival)$toStops[]=$entry;
            }
            if(count($fromStops)!==1 || count($toStops)!==1 ||
               ($fromStops[0]['order']??PHP_INT_MAX)>=($toStops[0]['order']??0))
                $fail('receipt_train_stop_order_invalid');
            if(count($fromStops)!==1 || count($toStops)!==1 ||
               ($receipt['departure_time']??'')!==($fromStops[0]['time']??null) ||
               ($receipt['arrival_time']??'')!==($toStops[0]['time']??null))
                $fail('receipt_train_timetable_invalid');
        }
        $stock=$receipt['availability']??null;
        $now=time();
        if (!is_array($stock) || ($stock['status']??'')!=='confirmed' ||
            ($stock['service_date']??'')!==($context['service_date']??'') ||
            !is_int($stock['available_quantity']??null) || $stock['available_quantity']<1 ||
            !is_string($stock['source_ref']??null) || trim($stock['source_ref'])==='' ||
            !is_string($stock['checked_at']??null) || !is_string($stock['expires_at']??null) ||
            !($checked=strtotime($stock['checked_at'])) ||
            !($expires=strtotime($stock['expires_at'])) ||
            $checked>$now+300 || $checked<$now-172800 || $expires<=$now)
            $fail('receipt_availability_not_verified_or_expired');
        // Approval identity does not imply cryptographic authority. The Original trust provider
        // must explicitly verify a signed, tenant-scoped approval; default is DENY.
        if (!isset($receipt['approval_ref']) || !is_string($receipt['approval_ref']) ||
            trim($receipt['approval_ref'])==='') $fail('receipt_approval_reference_missing');
        $trusted=apply_filters('mad4b/package-cost-receipt/verify-authority',false,$receipt,$context);
        if ($trusted!==true) $fail('receipt_signed_authority_unverified');
        return ['qualified'=>!$errors,'blockers'=>array_values($errors),'contract'=>self::CONTRACT];
    }
    private static function date(string $date):?string {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))return null;
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        return $parsed && $parsed->format('Y-m-d')===$date?$date:null;
    }
}
}
