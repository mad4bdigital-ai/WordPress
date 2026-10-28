<?php
/**
 * Shared presentation context projection. Local QA experimental contract v1.
 * Read-only: no pricing, visibility override, storage, order, or AJAX mutations.
 */
if (!defined('ABSPATH')) { return; }
if (!defined('DB_NAME') || DB_NAME !== 'etg_ops_checkout_qa') { return; }
final class Shared_Presentation_Context_Registry {
    const CONTRACT = 'shared.presentation-context.v1';
    private static function datum($value, $source, $status = 'resolved') {
        return ['value' => $value, 'source' => $source, 'status' => $status];
    }
    public static function definitions() {
        return [
            'locale' => ['authority' => 'wordpress_locale', 'affects' => ['copy','formatting']],
            'language' => ['authority' => 'wpml_runtime', 'affects' => ['translation','visibility','filters']],
            'viewer_tier' => ['authority' => 'wordpress_capabilities', 'affects' => ['visibility','eligibility']],
            'tour_id' => ['authority' => 'wordpress_queried_object', 'affects' => ['relations','filters','rate_discovery']],
            'display_currency' => ['authority' => 'existing_pricing_presenter_and_wcml', 'affects' => ['formatting','fx_preview']],
            'store_currency' => ['authority' => 'woocommerce_setting', 'affects' => ['currency_context']],
            'price_source_currency' => ['authority' => 'pricing_source_registry_provider_receipt', 'affects' => ['quote']],
            'order_currency' => ['authority' => 'woocommerce_order', 'affects' => ['checkout','ledger']],
            'accounting_currency' => ['authority' => 'erp_ledger_policy', 'affects' => ['reporting']],
            'fx_quote' => ['authority' => 'existing_fx_provider_rate_timestamp', 'affects' => ['price_presentation']],
            'market_profile' => ['authority' => 'approved_market_profile', 'affects' => ['eligibility','quote']],
            'passport_nationality' => ['authority' => 'explicit_traveller_declaration', 'affects' => ['eligibility','quote']],
            'travel_date' => ['authority' => 'tour_booking_selection', 'affects' => ['season','rates','availability']],
            'season_id' => ['authority' => 'season_calendar', 'affects' => ['rates','visibility']],
            'package_level_id' => ['authority' => 'tour_booking_selection', 'affects' => ['rates','options']],
            'tour_rate_id' => ['authority' => 'pricing_source_registry', 'affects' => ['quote','visibility']],
            'program_id' => ['authority' => 'tour_program_relation', 'affects' => ['options','visibility']],
            'occupancy' => ['authority' => 'age_occupancy_registry_and_booking_selection', 'affects' => ['quote','capacity']],
            'accommodation' => ['authority' => 'accommodation_policy_registry', 'affects' => ['quote','availability']],
            'optional_activity_ids' => ['authority' => 'contextual_product_options', 'affects' => ['extras','quote']],
            'service_activation' => ['authority' => 'company_product_activation', 'affects' => ['menus','visibility']],
            'booking_mode' => ['authority' => 'commerce_booking_policy', 'affects' => ['inquiry','checkout']],
        ];
    }
    public static function snapshot($selected = []) {
        if (!is_array($selected)) { return new WP_Error('presentation_context_input_invalid'); }
        $allowed = ['travel_date','season_id','package_level_id','tour_rate_id','program_id',
            'market_profile','passport_nationality','occupancy','accommodation','optional_activity_ids'];
        if (array_diff(array_keys($selected), $allowed)) {
            return new WP_Error('presentation_context_unknown_selection');
        }
        $defs = self::definitions();
        $values = [];
        foreach ($defs as $name => $definition) {
            $values[$name] = self::datum(null, $definition['authority'], 'unresolved');
        }
        $values['locale'] = self::datum(get_locale(), 'wordpress_locale');
        $lang = apply_filters('wpml_current_language', null);
        if (is_string($lang) && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/iD', $lang)) {
            $values['language'] = self::datum(strtolower($lang), 'wpml_runtime');
        }
        $viewer = !is_user_logged_in() ? 'guest' :
            (current_user_can('manage_woocommerce') ? 'commerce_manager' : 'authenticated');
        $values['viewer_tier'] = self::datum($viewer, 'wordpress_capabilities');
        $post_id = (int) get_queried_object_id();
        if ($post_id > 0 && get_post_type($post_id) === 'tours-and-activities') {
            $values['tour_id'] = self::datum($post_id, 'wordpress_queried_object');
        }
        $store = strtoupper((string) get_option('woocommerce_currency', ''));
        if (preg_match('/^[A-Z]{3}$/D', $store)) {
            $values['store_currency'] = self::datum($store, 'woocommerce_setting');
        }
        $values['display_currency'] = self::display_currency();
        foreach ($selected as $key => $value) {
            if (!self::valid_selection($key, $value)) {
                return new WP_Error('presentation_context_selection_invalid', $key);
            }
            // Never silently upgrade a user selection into verified quote authority.
            $values[$key] = self::datum($value, 'explicit_unverified_selection', 'declared_unverified');
        }
        $canonical = [];
        foreach ($values as $key => $datum) {
            $canonical[$key] = [$datum['status'], $datum['source'], $datum['value']];
        }
        $fingerprint = hash('sha256', wp_json_encode([self::CONTRACT, $canonical]));
        return [
            'contract' => self::CONTRACT,
            'dimensions' => $values,
            'fingerprint' => $fingerprint,
            'cache_policy' => ['scope' => 'request_only', 'shared_cache_allowed' => false,
                'vary' => array_keys($defs)],
            'decision_policy' => ['early_prune_authorized' => false,
                'pricing_authorized' => false, 'checkout_authorized' => false,
                'requires_resolved_dynamic_conditions' => true],
            'source_registries' => [
                'pricing' => class_exists('MAD4B_Pricing_Source_Registry') ? 'present' : 'missing',
                'occupancy' => class_exists('MAD4B_Age_Occupancy_Registry') ? 'present' : 'missing',
                'contextual_options' => class_exists('Commerce_Contextual_Product_Options') ? 'present' : 'missing',
            ],
            'mutation_performed' => false,
        ];
    }
    private static function valid_selection($key, $value) {
        if (in_array($key, ['season_id','package_level_id','tour_rate_id','program_id'], true)) {
            return is_int($value) && $value > 0;
        }
        if ($key === 'travel_date') {
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value;
        }
        if ($key === 'passport_nationality') return is_string($value) && preg_match('/^[A-Z]{2}$/D', $value) === 1;
        if ($key === 'market_profile') return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $value) === 1;
        if ($key === 'optional_activity_ids') return is_array($value) && count($value) <= 25 &&
            count(array_filter($value, fn($v) => !is_int($v) || $v <= 0)) === 0;
        if ($key === 'occupancy') {
            if (!is_array($value) || array_diff(array_keys($value),
                ['adults','children','infants','rooms','persons','child_ages','infant_ages'])) return false;
            foreach ($value as $field => $v) {
                if (in_array($field, ['child_ages','infant_ages'], true)) {
                    // Age parsing belongs to the native Age Occupancy provider.
                    if (!is_array($v) || !array_is_list($v) || count($v) > 50 ||
                        !class_exists('MAD4B_Age_Fluent_Forms') ||
                        !method_exists('MAD4B_Age_Fluent_Forms', 'clean_ages') ||
                        is_wp_error(MAD4B_Age_Fluent_Forms::clean_ages($v, count($v)))) return false;
                } elseif (!is_int($v) || $v < 0 || $v > 50) return false;
            }
            return true;
        }
        if ($key === 'accommodation') {
            if (!is_array($value) || count($value) > 16 ||
                array_diff(array_keys($value), ['property_id','unit_id','rate_id','rooms','stages'])) return false;
            foreach ($value as $field => $v) {
                if ($field === 'stages') {
                    if (!is_array($v) || count($v) > 12) return false;
                    foreach ($v as $stage) {
                        if (!is_array($stage) || count($stage) > 8 ||
                            array_diff(array_keys($stage), ['country_code','property_id','unit_id','rate_id','rooms'])) return false;
                        foreach ($stage as $k => $item) {
                            if ($k === 'country_code') {
                                if (!is_string($item) || !preg_match('/^[A-Z]{2}$/D', $item)) return false;
                            } elseif (!is_int($item) || $item <= 0 || $item > 100000000) return false;
                        }
                    }
                } elseif (!is_int($v) || $v <= 0 || $v > 100000000) return false;
            }
            return true;
        }
        return false;
    }
    public static function display_currency() {
        $observed = [];
        if (function_exists('pricing_display_currency')) {
            $observed['pricing_presenter'] = strtoupper((string) pricing_display_currency());
        }
        if (function_exists('get_woocommerce_currency')) {
            $observed['wcml_woocommerce'] = strtoupper((string) get_woocommerce_currency());
        }
        foreach ($observed as $c) {
            if (!preg_match('/^[A-Z]{3}$/D', $c)) return self::datum(null, 'currency_providers', 'unresolved');
        }
        if (!$observed) return self::datum(null, 'currency_providers', 'unresolved');
        if (count(array_unique(array_values($observed))) > 1) {
            return self::datum(null, 'currency_provider_disagreement', 'conflict');
        }
        return self::datum(reset($observed), implode('+', array_keys($observed)));
    }
}
