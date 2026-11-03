<?php
declare(strict_types=1);
namespace Commerce\Composition;

use DomainException;

/**
 * Pure composition engine. Policies and source-price callbacks MUST originate server-side.
 * Browser-provided values are only selection IDs / quantities. No guest PII or payment state.
 */
final class Composer
{
    private const ROLES = [
        'fixed_included'=>'included',
        'choose_one_included'=>'included',
        'upgrade_delta'=>'delta',
        'optional_attached'=>'additional',
        'separately_accounted_extra'=>'separate',
    ];
    private static function reject(string $reason): never { throw new DomainException($reason); }
    private static function ident(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]{0,79}$/D', $value)) self::reject('invalid_identifier');
        return $value;
    }
    private static function unsignedId(mixed $v): int
    {
        if (!is_int($v) && !(is_string($v) && preg_match('/^[1-9][0-9]*$/D', $v))) self::reject('invalid_source_id');
        $n = filter_var($v, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($n === false) self::reject('invalid_source_id');
        return (int)$n;
    }
    private static function quantity(mixed $v, int $limit): int
    {
        $n = self::unsignedId($v);
        if ($n > $limit) self::reject('quantity_policy_limit');
        return $n;
    }
    public static function compose(array $policy, array $input, array $channels, callable $priceResolver): array
    {
        if (($policy['schema'] ?? null) !== 1 || !is_array($policy['parent'] ?? null)) self::reject('policy_schema_invalid');
        $parent = $policy['parent'];
        $parentService = self::ident($parent['service'] ?? null);
        $sourceType = self::ident($parent['source_type'] ?? null);
        $sourceId = self::unsignedId($parent['source_id'] ?? null);
        $rateId = isset($parent['rate_id']) ? self::unsignedId($parent['rate_id']) : null;
        $intent = $input['intent'] ?? 'request';
        if (!in_array($intent, ['request','sale'], true)) self::reject('intent_invalid');
        $parentChannel = $input['channel'] ?? 'standalone';
        if (!in_array($parentChannel, ['standalone','package'], true)) self::reject('channel_invalid');
        if (!Channels::allowed($channels, $parentService, $parentChannel)) self::reject('parent_channel_denied');
        if (isset($input['parent_source_id']) && self::unsignedId($input['parent_source_id']) !== $sourceId) self::reject('parent_source_mismatch');
        if (isset($input['parent_rate_id']) && $rateId !== self::unsignedId($input['parent_rate_id'])) self::reject('parent_rate_mismatch');

        $groups = $policy['groups'] ?? [];
        $selected = $input['groups'] ?? [];
        if (!is_array($groups) || !array_is_list($groups) || !is_array($selected)) self::reject('groups_invalid');
        if (count($groups)>200 || count($selected)>count($groups)) self::reject('groups_policy_limit');
        $known = []; $components = []; $financial = [];
        $priceTotal = 0; $priceIsComplete = true; $currency = null;
        foreach ($groups as $group) {
            if (!is_array($group)) self::reject('group_invalid');
            $groupKey = self::ident($group['key'] ?? null);
            if (isset($known[$groupKey])) self::reject('duplicate_group');
            $known[$groupKey] = true;
            $role = $group['role'] ?? null;
            if (!is_string($role) || !isset(self::ROLES[$role])) self::reject('unsupported_role');
            $catalogue = $group['options'] ?? [];
            if (!is_array($catalogue) || !array_is_list($catalogue) || count($catalogue)>250) self::reject('catalogue_invalid');
            $allowed = [];
            foreach ($catalogue as $option) {
                if (!is_array($option)) self::reject('option_invalid');
                $service = self::ident($option['service'] ?? null);
                $type = self::ident($option['source_type'] ?? null);
                $id = self::unsignedId($option['source_id'] ?? null);
                $key = $type . ':' . $id;
                if (isset($allowed[$key])) self::reject('duplicate_catalogue_option');
                $allowed[$key] = ['service'=>$service,'source_type'=>$type,'source_id'=>$id,
                    'supplier_ref'=>$option['supplier_ref'] ?? null];
            }
            $choices = $selected[$groupKey] ?? [];
            if ($role==='fixed_included') {
                if (count($catalogue)!==1) self::reject('fixed_group_requires_one_source');
                if (array_key_exists($groupKey,$selected) && $choices!==[]) self::reject('fixed_component_not_customer_editable');
                $sole=$catalogue[0];
                $choices=[['source_type'=>$sole['source_type'],'source_id'=>$sole['source_id'],'quantity'=>1]];
            }
            if (!is_array($choices) || !array_is_list($choices)) self::reject('selection_shape_invalid');
            $min = $group['min'] ?? ($role==='fixed_included'||$role==='choose_one_included'?1:0);
            $max = $group['max'] ?? ($role==='fixed_included'||$role==='choose_one_included'||$role==='upgrade_delta'?1:count($catalogue));
            if (!is_int($min) || !is_int($max) || $min<0 || $max<$min || $max>count($catalogue)) self::reject('group_cardinality_policy_invalid');
            if (count($choices)<$min || count($choices)>$max) self::reject('selection_cardinality_invalid');
            $seen = [];
            foreach ($choices as $choice) {
                if (!is_array($choice) || array_diff(array_keys($choice), ['source_type','source_id','quantity'])) self::reject('selection_untrusted_field');
                $key = self::ident($choice['source_type'] ?? null) . ':' . self::unsignedId($choice['source_id'] ?? null);
                if (!isset($allowed[$key])) self::reject('unlinked_option');
                if (isset($seen[$key])) self::reject('duplicate_selection');
                $seen[$key] = true;
                $option = $allowed[$key];
                if (!Channels::allowed($channels, $option['service'], 'package')) self::reject('component_channel_denied');
                $limit = $group['quantity_limit'] ?? 999;
                if (!is_int($limit) || $limit<1 || $limit>999999) self::reject('quantity_policy_invalid');
                $qty = self::quantity($choice['quantity'] ?? 1, $limit);
                if ($role==='fixed_included'||$role==='choose_one_included'||$role==='upgrade_delta') {
                    if ($qty !== 1) self::reject('exclusive_quantity_invalid');
                }
                $pricing = $role === 'optional_attached' && ($group['included_when_selected'] ?? false) === true
                    ? 'included' : self::ROLES[$role];
                // Callback owns product/rate identity, valuation, currency, taxes and expiry. No client amount.
                $valuation = $priceResolver($parent, $group, $option, $qty, $pricing, $intent);
                if (!is_array($valuation) || !in_array($valuation['state'] ?? null, ['included','priced','pending'], true))
                    self::reject('price_authority_invalid');
                $state = $valuation['state'];
                $amount = $valuation['amount'] ?? null;
                $amountCurrency = $valuation['currency'] ?? null;
                if ($state==='priced') {
                    if ((!is_int($amount)&&!is_float($amount)) || !is_finite((float)$amount) || $amount<0
                        || !is_string($amountCurrency) || !preg_match('/^[A-Z]{3}$/D', $amountCurrency))
                        self::reject('priced_amount_invalid');
                    if ($currency !== null && $currency !== $amountCurrency) self::reject('mixed_currency_requires_explicit_fx');
                    $currency = $amountCurrency;
                } else {
                    if ($amount !== null) self::reject('nonpriced_amount_not_null');
                    if ($state==='pending') $priceIsComplete=false;
                }
                if ($intent==='sale' && $state==='pending') self::reject('sale_requires_approved_price');
                $component = $option + [
                    'group'=>$groupKey,'role'=>$role,'pricing'=>$pricing,
                    'quantity'=>$qty,'valuation_state'=>$state,'amount'=>$amount,
                    'currency'=>$state==='priced'?$amountCurrency:null,
                    'valuation_ref'=>$valuation['reference'] ?? null,
                ];
                $components[] = $component;
                if ($pricing==='separate') $financial[] = $component;
                elseif ($state==='priced' && $pricing!=='included') $priceTotal += $amount;
            }
        }
        foreach (array_keys($selected) as $key) if (!isset($known[$key])) self::reject('unknown_group');
        if ($intent==='sale' && $priceIsComplete===false) self::reject('sale_requires_complete_price');
        return [
            'schema'=>1,'parent'=>['service'=>$parentService,'source_type'=>$sourceType,
                'source_id'=>$sourceId,'rate_id'=>$rateId],
            'intent'=>$intent,'components'=>$components,'dependent_financial_lines'=>$financial,
            'additional_amount'=>$priceIsComplete ? round($priceTotal,2) : null,
            'additional_currency'=>$currency,'price_state'=>$priceIsComplete?'components_evaluated':'price_pending',
            'status'=>'structure_and_component_evidence_only_not_checkout_authority',
        ];
    }
}
