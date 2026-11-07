<?php
/**
 * Plugin Name: MAD4B Tour Pricing Distribution Bridge
 * Version: 1.1.0
 * Description: Read-only, weighted retail-price evidence for MAD4B content distribution.
 *
 * This is NOT a checkout gateway and not a Google Things to do submission.
 */
if (!defined('ABSPATH')) exit;

final class MAD4B_Tour_Price_Distribution_V1 {
    public const CONTRACT = 'mad4b.tour-price-distribution.v1';

    private static function currency($s): string {
        $s = strtoupper((string)$s);
        return preg_match('/^[A-Z]{3}$/D', $s) ? $s : '';
    }

    private static function positive_money($value): ?float {
        if (!is_scalar($value) || !is_numeric($value)) return null;
        $value = (float)$value;
        return is_finite($value) && $value > 0 ? $value : null;
    }

    /** Internal Google Things to do checklist. Every requirement is independently testable. */
    public static function google_checklist(?array $quote, ?array $confirmation = null): array {
        $has_price = is_array($quote) && self::positive_money($quote['amount'] ?? null) !== null
            && self::currency($quote['currency'] ?? '') !== '';
        $fees = ($confirmation['fees_included'] ?? false) === true;
        $standard_adult = ($confirmation['standard_adult'] ?? false) === true;
        $one_adult = ($confirmation['one_adult_bookable'] ?? false) === true;
        $group_size = filter_var($confirmation['required_group_size'] ?? 0, FILTER_VALIDATE_INT);
        $group_full = ($confirmation['minimum_group_total_verified'] ?? false) === true
            && $group_size !== false && $group_size >= 2;
        $full_transaction = ($confirmation['price_is_total_transaction'] ?? false) === true;
        $occupancy = $standard_adult && $full_transaction && ($one_adult || $group_full);
        // Availability proof must include a review of Google's eligible booking window.
        $availability = ($confirmation['availability_confirmed'] ?? false) === true
            && ($confirmation['booking_window_verified'] ?? false) === true;
        $url = $confirmation['booking_url'] ?? null;
        $url_ok = is_string($url) && in_array(wp_parse_url($url, PHP_URL_SCHEME), ['https','http'], true)
            && strtolower((string)wp_parse_url($url, PHP_URL_HOST))
                === strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        $total = self::positive_money($confirmation['total'] ?? null);
        $checkout = $has_price && ($confirmation['checkout_ready'] ?? false) === true
            && $total !== null && abs($total - (float)$quote['amount']) <= 0.009
            && self::currency($confirmation['currency'] ?? '') === $quote['currency']
            && $url_ok && $full_transaction;
        $requirements = [
            'price' => [$has_price, 'Resolve an eligible weighted Rate or Direct price (price_on_request).'],
            'fees_and_taxes' => [$fees, 'Prove all mandatory taxes and booking/activity fees are included in the amount.'],
            'occupancy' => [$occupancy, 'Prove standard adult booking and the full minimum transaction (single adult or required group total).'],
            'availability' => [$availability, 'Confirm genuinely bookable dates/inventory, with the Google booking window and rate validity checked.'],
            'checkout_match' => [$checkout, 'Verify the final bookable checkout amount, currency, and working same-site booking URL.'],
        ];
        $items=[];$missing=[];
        foreach ($requirements as $code=>$row) {
            $ok=$row[0] === true;
            $items[$code]=['status'=>$ok?'passed':'action_required', 'required_for'=>'google_things_to_do', 'action'=>$row[1]];
            if (!$ok) $missing[]=$code;
        }
        return [
            'contract'=>'mad4b.gttd-readiness-checklist.v1',
            'status'=>$missing?'action_required':'price_checks_verified',
            'severity'=>$missing?'high':'none',
            'missing'=>$missing,
            'items'=>$items,
            'content_distribution_blocked'=>false,
            'google_submission_allowed'=>false, // Feed/partner authorization is separate.
            'resolution'=>'Re-evaluate the live price and booking proof; manual task completion is not evidence.',
        ];
    }

    private static function status(int $id, string $reason, array $extra = []): array {
        return array_merge([
            'contract' => self::CONTRACT,
            'tour_id' => $id,
            'state' => 'price_on_request',
            'reason' => $reason,
            'quote' => null,
            'distribution' => [
                'content_eligible' => get_post_status($id) === 'publish',
                'price_preview_eligible' => false,
                'google_things_to_do_price_ready' => false,
                'google_things_to_do_submission_ready' => false,
                'merchant_offer_price_ready' => false,
            ],
            'google_things_to_do' => self::google_checklist(null),
            'alert' => ['severity'=>'high','code'=>'gttd_price_on_request',
                'title'=>'Google Things to do: price_on_request — commercial price requires review',
                'channel'=>'google_things_to_do','content_distribution_blocked'=>false],
            'mutations' => false,
        ], $extra);
    }

    /** Read one price as chosen by the existing public rate resolver and source weights. */
    public static function snapshot(int $id, ?string $date = null, ?string $target_currency = null): array {
        if (get_post_type($id) !== 'tours-and-activities' || get_post_status($id) !== 'publish') {
            return self::status($id, 'tour_not_published');
        }
        if (in_array((string)get_post_meta($id, 'tour_public_status', true), ['0','false','stop_sale'], true)) {
            return self::status($id, 'tour_not_public');
        }
        $day = $date ?: current_time('Y-m-d');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d') !== $day) return self::status($id, 'invalid_date');
        $currency = self::currency($target_currency ?: get_woocommerce_currency());
        if ($currency === '' || !function_exists('shared_product_resolve_price_sources')
            || !function_exists('shared_lab_tour_price_resolve') || !function_exists('pricing_currency_convert')) {
            return self::status($id, 'pricing_engine_unavailable');
        }
        if (function_exists('pricing_scope_resolve')) {
            $scope = pricing_scope_resolve($id, 0, []);
            if (is_wp_error($scope)) return self::status($id, 'pricing_scope_unavailable');
            if (!empty($scope['values']['stop_sale_global'])) return self::status($id, 'stop_sale');
        }
        $mode = (string)get_post_meta($id, 'tour_mode', true);
        $policy = shared_product_price_source_policy($mode);
        if (is_wp_error($policy)) return self::status($id, 'source_policy_'.$policy->get_error_code());
        $projection = shared_lab_tour_price_resolve($id, $day);
        $candidates = [];
        $direct = self::positive_money(get_post_meta($id, 'start_price', true));
        $direct_currency = self::currency(get_post_meta($id, 'tour_base_currency', true));
        if ($direct !== null && $direct_currency !== '') {
            $candidates['direct'] = ['amount'=>$direct, 'currency'=>$direct_currency];
        }
        $rate = null;
        if (($projection['status'] ?? '') === 'available'
            && ($projection['source'] ?? '') === 'tour-rates') {
            $best = $projection['best'] ?? [];
            $amount = self::positive_money($best['final'] ?? null);
            $rate_currency = self::currency($projection['currency'] ?? '');
            $rate_id = (int)($best['rate_id'] ?? 0);
            if ($amount === null || $rate_currency === '' || $rate_id < 1
                || get_post_type($rate_id) !== 'tour-rates' || get_post_status($rate_id) !== 'publish') {
                return self::status($id, 'rate_projection_invalid');
            }
            $candidates['rate'] = ['amount'=>$amount,'currency'=>$rate_currency];
            $rate = [
                'id'=>$rate_id, 'count'=>(int)($projection['rate_count'] ?? 0),
                'occupancy'=>(string)($best['occupancy'] ?? ''),
                'period'=>(string)($projection['period'] ?? ''),
                'valid_from'=>!empty($best['start']) ? gmdate('Y-m-d', (int)$best['start']) : null,
                'valid_to'=>!empty($best['end']) ? gmdate('Y-m-d', (int)$best['end']) : null,
            ];
        } elseif (($projection['status'] ?? '') !== 'available'
            && !in_array((string)($projection['reason'] ?? ''),
                ['etg_no_valid_tour_rate','etg_price_unavailable','rate_unavailable','no_valid_rate'],true)) {
            // Unknown rate/FX errors may hide eligible higher-priority prices.
            return self::status($id, 'rate_projection_'.sanitize_key((string)($projection['reason'] ?? 'unavailable')));
        }
        $choice = shared_product_resolve_price_sources($candidates, $mode);
        if (is_wp_error($choice)) return self::status($id,'weighted_source_'.$choice->get_error_code());
        $winner = (string)($choice['source'] ?? '');
        if (!isset($candidates[$winner])) return self::status($id, 'weighted_source_not_in_candidates');
        $source_currency = self::currency($choice['currency'] ?? '');
        $raw_amount = self::positive_money($choice['amount'] ?? null);
        if ($raw_amount === null || $source_currency === '') return self::status($id, 'winner_amount_invalid');
        $amount = $source_currency === $currency ? $raw_amount
            : pricing_currency_convert($raw_amount, $source_currency, $currency);
        $amount = self::positive_money($amount);
        if ($amount === null) return self::status($id, 'conversion_unavailable');
        $basis = (string)get_post_meta($id, 'tour_price_unit', true);
        $known_bases = ['per_person','per_trip','per_room','per_cabin','per_day','per_vehicle','table'];
        if (!in_array($basis,$known_bases,true)) $basis = 'unknown';
        $details = $winner === 'rate' ? $rate : null;
        $quote = [
            'amount'=>round($amount,2), 'currency'=>$currency,
            'price_basis'=>$basis,
            'source'=>$winner === 'rate' ? 'tour_rate' : 'tour_direct',
            'source_engine'=>$winner === 'rate' ? 'shared_lab_tour_price_resolve' : 'post_meta_validated_by_weighted_policy',
            'rate'=>$details,
            'pricing_date'=>$day,
            'currency_conversion'=>['from'=>$source_currency,'to'=>$currency,'converted'=>$currency!==$source_currency],
            'weights'=>['direct'=>(int)$policy['direct_weight'],'rate'=>(int)$policy['rate_weight'],
                'tie_break'=>$policy['tie_break'],'fallback'=>$policy['fallback']],
            'selection_status'=>'weighted_source_selected',
            'quote_kind'=>'indicative_retail_from',
            'availability'=>'not_checked',
            'market_context'=>'site_default_public',
            'market_specific_price_verified'=>false,
            'checkout_ready'=>false,
        ];
        // A channel adapter must independently verify that a complete retail
        // checkout price is available for this exact price, basis and context.
        // Do not use a shadow quote, starting price or stock guess as proof.
        $confirmation = apply_filters('mad4b_distribution_confirmed_retail_price_v1', null,
            ['tour_id'=>$id,'quote'=>$quote,'date'=>$day,'currency'=>$currency]);
        $eligible = is_array($confirmation)
            && ($confirmation['checkout_ready'] ?? false) === true
            && ($confirmation['fees_included'] ?? false) === true
            && ($confirmation['standard_adult'] ?? false) === true
            && self::positive_money($confirmation['total'] ?? null) !== null
            && abs((float)$confirmation['total']-$quote['amount']) <= 0.009
            && self::currency($confirmation['currency'] ?? '') === $currency
            && is_string($confirmation['booking_url'] ?? null)
            && strtolower((string)wp_parse_url($confirmation['booking_url'],PHP_URL_HOST))
                === strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        return [
            'contract'=>self::CONTRACT,'tour_id'=>$id,
            'state'=>'price_reference_available','reason'=>null,'quote'=>$quote,
            'google_things_to_do'=>self::google_checklist($quote,$confirmation),
            'alert'=>self::google_checklist($quote,$confirmation)['status']==='action_required'
                ? ['severity'=>'high','code'=>'gttd_requirements_unverified',
                    'title'=>'Google Things to do: verify fees, occupancy, availability and checkout',
                    'channel'=>'google_things_to_do','content_distribution_blocked'=>false]
                : null,
            'distribution'=>[
                'content_eligible'=>true,'price_preview_eligible'=>true,
                // For Things to do, a per-person amount must also be the
                // complete bookable transaction for one standard adult.
                // Do not send half of a required double-occupancy booking.
                'google_things_to_do_price_ready'=>$eligible
                    && self::google_checklist($quote, $confirmation)['status'] === 'price_checks_verified',
                'google_things_to_do_submission_ready'=>false, // requires authorized partner feed
                'merchant_offer_price_ready'=>$eligible,
                'price_alignment'=>$eligible ? 'verified' : 'not_verified',
                'verified_booking_url'=>$eligible ? esc_url_raw($confirmation['booking_url']) : null,
                'warnings'=>self::google_checklist($quote,$confirmation)['missing'],
            ],
            'mutations'=>false,
        ];
    }

    /** Bridge only channel-confirmed weighted prices into existing Product/Offer schema. */
    public static function schema_offer($existing, int $tour_id) {
        if ($existing !== null) return $existing;
        // Avoid cost of rate discovery on every public view when no verified
        // checkout price provider exists. Internal distribution uses REST.
        if (!has_filter('mad4b_distribution_confirmed_retail_price_v1')) return null;
        $snapshot = self::snapshot($tour_id);
        if (($snapshot['distribution']['merchant_offer_price_ready'] ?? false) !== true) return null;
        $quote = $snapshot['quote'] ?? [];
        $url = $snapshot['distribution']['verified_booking_url'] ?? '';
        if (empty($url) || empty($quote['amount']) || empty($quote['currency'])) return null;
        return [
            'checkout_ready'=>true, 'price'=>$quote['amount'],
            'currency'=>$quote['currency'], 'url'=>$url,
        ];
    }

    public static function rest(): void {
        register_rest_route('mad4b-distribution/v1','/tour/(?P<id>\d+)',[
            'methods'=>'GET',
            'permission_callback'=>static fn() => current_user_can('manage_woocommerce'),
            'callback'=>static function(WP_REST_Request $request) {
                $id=absint($request['id']);
                if (!$id || get_post_type($id)!=='tours-and-activities')
                    return new WP_Error('invalid_tour','Tour not found',['status'=>404]);
                $date=sanitize_text_field((string)($request->get_param('date') ?: ''));
                $currency=sanitize_text_field((string)($request->get_param('currency') ?: ''));
                $snapshot=self::snapshot($id,$date?:null,$currency?:null);
                if (class_exists('MAD4B_Tour_Destination_Structured_Data')) {
                    $p=get_post($id);
                    $snapshot['tour'] = $p ? MAD4B_Tour_Destination_Structured_Data::tour_node($p) : null;
                }
                $response = rest_ensure_response($snapshot);
                $response->header('Cache-Control','private, no-store');
                return $response;
            },
        ]);
    }
}
add_action('rest_api_init',['MAD4B_Tour_Price_Distribution_V1','rest']);
add_filter('mad4b_schema_verified_tour_offer', ['MAD4B_Tour_Price_Distribution_V1','schema_offer'], 20, 2);
