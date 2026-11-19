<?php
/**
 * Plugin Name: MAD4B Guide Daily Base Cost
 * Description: Tenant-owned internal guide/day cost basis; no booking or selling authority.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Guide_Daily_Base_Cost_V1 {
    public const OPTION = 'mad4b_guide_daily_base_cost_v1';
    public const IDENTITY_META = '_mad4b_guide_locale_code';

    public static function template(): array {
        return [
            'schema' => 1,
            'currency' => 'EGP',
            'unit' => 'per_guide_day',
            'supplier' => 'self',
            'amounts' => ['ar' => 2000, 'en' => 2500, 'other' => 3000],
            'status' => 'internal_cost_basis',
            'cost_provenance' => 'company_defined_base',
            'commercial_activation' => false,
        ];
    }

    public static function read(): array|WP_Error {
        $policy = get_option(self::OPTION, null);
        if (!is_array($policy)) return new WP_Error('guide_cost_policy_missing');
        $expected = self::template();
        if (array_diff(array_keys($policy), array_keys($expected)) ||
            array_diff(array_keys($expected), array_keys($policy)) ||
            $policy['schema'] !== 1 ||
            $policy['currency'] !== 'EGP' ||
            $policy['unit'] !== 'per_guide_day' ||
            $policy['supplier'] !== 'self' ||
            $policy['status'] !== 'internal_cost_basis' ||
            $policy['cost_provenance'] !== 'company_defined_base' ||
            $policy['commercial_activation'] !== false ||
            !is_array($policy['amounts']) ||
            array_diff(array_keys($policy['amounts']), ['ar','en','other']) ||
            array_diff(['ar','en','other'], array_keys($policy['amounts']))) {
            return new WP_Error('guide_cost_policy_invalid');
        }
        foreach ($policy['amounts'] as $amount) {
            if (!is_int($amount) || $amount <= 0 || $amount > 1000000) {
                return new WP_Error('guide_cost_amount_invalid');
            }
        }
        return $policy;
    }

    /**
     * Resolve a selected taxonomy term to an immutable language identity, never
     * by translated display name or a client's unvalidated language token.
     */
    public static function identity(string|int $selection): string|WP_Error {
        if (!taxonomy_exists('guide-languages')) return new WP_Error('guide_language_taxonomy_missing');
        if (is_int($selection)) {
            $term = get_term($selection, 'guide-languages');
        } elseif (preg_match('/^[a-z0-9-]{2,64}$/D', $selection)) {
            $term = get_term_by('slug', $selection, 'guide-languages');
        } else {
            return new WP_Error('guide_language_selection_invalid');
        }
        if (!$term || is_wp_error($term) || !($term instanceof WP_Term) ||
            $term->taxonomy !== 'guide-languages' ||
            str_starts_with((string)$term->slug,'qa-')) {
            return new WP_Error('guide_language_term_unavailable');
        }
        $identity=(string)get_term_meta((int)$term->term_id, self::IDENTITY_META, true);
        if (!preg_match('/^[a-z]{2}$/D',$identity)) {
            return new WP_Error('guide_language_identity_unverified');
        }
        return $identity;
    }

    /**
     * Read-only component cost; explicit guided days and guide count.
     * Never infer guide days from total itinerary days or per-person count.
     */
    public static function preview(string|int $language, int $guidedDays, int $guides=1): array|WP_Error {
        if ($guidedDays < 1 || $guidedDays > 365 ||
            $guides < 1 || $guides > 30) return new WP_Error('guide_cost_quantity_invalid');
        $policy=self::read();
        if (is_wp_error($policy)) return $policy;
        $code=self::identity($language);
        if (is_wp_error($code)) return $code;
        $tier=array_key_exists($code, ['ar'=>true,'en'=>true]) ? $code : 'other';
        $unit=(int)$policy['amounts'][$tier];
        $amount=$unit*$guidedDays*$guides;
        return [
            'contract'=>'mad4b.guide.daily-base-cost.v1',
            'component'=>'guide',
            'language_code'=>$code,
            'tier'=>$tier,
            'supplier'=>$policy['supplier'],
            'currency'=>'EGP',
            'price_unit'=>'per_guide_day',
            'guided_days'=>$guidedDays,
            'guide_count'=>$guides,
            'unit_cost'=>$unit,
            'base_cost'=>$amount,
            'amount_type'=>'internal_cost_not_selling_price',
            'guide_assignment_confirmed'=>false,
            'supplier_availability_confirmed'=>false,
            'tour_rate_inclusion_unknown'=>true,
            'apply_to_commercial_total'=>false,
            'checkout_ready'=>false,
        ];
    }

    public static function status(): array {
        $policy=self::read();
        return [
            'contract'=>'mad4b.guide.daily-base-cost.readback.v1',
            'configured'=>!is_wp_error($policy),
            'reason'=>is_wp_error($policy)?$policy->get_error_code():null,
            'currency'=>'EGP',
            'unit'=>'per_guide_day',
            'supplier'=>'self',
            'amounts'=>!is_wp_error($policy)?$policy['amounts']:[],
            'priced_tour_components'=>false,
            'checkout_ready'=>false,
        ];
    }
}
add_filter('mad4b_company_reconcile_component_readiness', static function($providers){
    if (!is_array($providers)) $providers=[];
    $providers['guide_daily_base_cost']=MAD4B_Guide_Daily_Base_Cost_V1::status();
    return $providers;
},35);
