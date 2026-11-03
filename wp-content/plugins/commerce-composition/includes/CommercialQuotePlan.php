<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Pure, side-effect-free projection from an already verified native Preflight.
 * A proposed Woo item is NOT a price, availability hold, checkout grant or order.
 */
final class CommercialQuotePlan
{
    public const CONTRACT = 'commerce.commercial-quote-plan.v1';

    private static function denied(string $code): \WP_Error
    {
        return new \WP_Error($code, 'Commercial quote planning unavailable');
    }

    public static function fromPreflight(array $receipt, string $chosenRole): array|\WP_Error
    {
        if (($receipt['contract'] ?? null) !== 'commerce.commercial-preflight.v1'
            || ($receipt['no_side_effects'] ?? false) !== true
            || ($receipt['single_sale_line_required'] ?? false) !== true
            || ($receipt['double_charge_parent_and_rate_forbidden'] ?? false) !== true
            || ($receipt['commercial_quote_issued'] ?? true) !== false
            || ($receipt['cart_mutated'] ?? true) !== false
            || ($receipt['order_created'] ?? true) !== false
            || ($receipt['supplier_booking_created'] ?? true) !== false) {
            return self::denied('commercial_plan_preflight_not_safe');
        }
        foreach (['preflight_digest', 'policy_digest'] as $key) {
            if (!is_string($receipt[$key] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $receipt[$key])) {
                return self::denied('commercial_plan_digest_invalid');
            }
        }
        if (!is_string($receipt['site_uuid'] ?? null)
            || $receipt['site_uuid'] === ''
            || !is_int($receipt['policy_revision'] ?? null)
            || $receipt['policy_revision'] < 1) {
            return self::denied('commercial_plan_site_policy_identity_invalid');
        }
        $source = $receipt['source'] ?? null;
        if (!is_array($source) || !is_int($source['service_id'] ?? null)
            || $source['service_id'] < 1
            || (!is_null($source['rate_id'] ?? null)
                && ($source['relationship_verified'] ?? false) !== true)) {
            return self::denied('commercial_plan_source_relation_invalid');
        }
        $choices = $receipt['sale_line_choices'] ?? null;
        if (!is_array($choices) || !array_is_list($choices)
            || count($choices) < 1 || count($choices) > 2
            || !in_array($chosenRole, ['parent_service', 'independent_rate'], true)) {
            return self::denied('commercial_plan_sale_choice_invalid');
        }
        $chosen = null;
        $observedRoles = [];
        foreach ($choices as $item) {
            if (!is_array($item)
                || !in_array($item['role'] ?? null, ['parent_service', 'independent_rate'], true)
                || !is_int($item['product_id'] ?? null)
                || $item['product_id'] < 1
                || ($item['cart_write_authorized'] ?? true) !== false
                || ($item['order_item_write_authorized'] ?? true) !== false
                || !is_string($item['source_post_type'] ?? null)) {
                return self::denied('commercial_plan_sale_choice_untrusted');
            }
            if (in_array($item['role'], $observedRoles, true)) {
                return self::denied('commercial_plan_duplicate_sale_role');
            }
            $observedRoles[] = $item['role'];
            if ($item['role'] === 'parent_service' && $item['product_id'] !== $source['service_id']) {
                return self::denied('commercial_plan_parent_identity_mismatch');
            }
            if ($item['role'] === 'independent_rate'
                && ($source['rate_id'] ?? null) !== $item['product_id']) {
                return self::denied('commercial_plan_rate_identity_mismatch');
            }
            if ($item['role'] === $chosenRole) $chosen = $item;
        }
        if ($chosen === null) return self::denied('commercial_plan_choice_not_allowed_by_policy');
        $effective = $receipt['company_policy'] ?? null;
        $approvals = $receipt['approvals'] ?? null;
        $preview = $receipt['pricing_preview'] ?? null;
        if (!is_array($effective) || !is_array($approvals) || !is_array($preview)
            || ($preview['price_is_final'] ?? true) !== false
            || !in_array($effective['availability_mode'] ?? null,
                ['live_provider', 'allotment', 'manual_confirmation'], true)
            || !in_array($effective['order_timing'] ?? null,
                ['after_acceptance', 'after_payment', 'manual'], true)
            || !in_array($effective['supplier_booking_timing'] ?? null,
                ['after_order', 'after_payment', 'manual'], true)
            || !in_array($approvals['manager']['company_mode'] ?? null,
                ['required', 'conditional', 'not_required'], true)
            || !in_array($approvals['traveler']['company_mode'] ?? null,
                ['required', 'channel_verified'], true)) {
            return self::denied('commercial_plan_policy_evidence_invalid');
        }
        $indicative = ($preview['status'] ?? null) === 'indicative_only'
            && is_numeric($preview['amount'] ?? null)
            && is_finite((float)$preview['amount'])
            && (float)$preview['amount'] >= 0
            && is_string($preview['currency'] ?? null)
            && (bool)preg_match('/^[A-Z]{3}$/D', $preview['currency']);
        // The draft deliberately has NO cart price, cart quantity or checkout nonce.
        // These must come from a separately verified signed final Quote later.
        $candidate = [
            'role' => $chosenRole,
            'woo_product_id' => $chosen['product_id'],
            'service_id' => $source['service_id'],
            'rate_id' => $source['rate_id'] ?? null,
            'quantity' => null,
            'cart_price' => null,
            'currency' => null,
            'cart_write_authorized' => false,
        ];
        $requirements = [
            'site_and_company_binding' => 'verified_by_native_preflight_required',
            'quote_price' => 'signed_native_final_quote_required',
            'supplier_cost_and_margin' => 'registered_cost_provider_or_approved_manual_review',
            'taxes_and_fees' => 'tax_provider_final_receipt',
            'availability' => $effective['availability_mode'] === 'manual_confirmation'
                ? 'supplier_manual_confirmation_receipt'
                : ($effective['availability_mode'] === 'allotment'
                    ? 'native_allotment_hold_receipt' : 'live_provider_hold_receipt'),
            'manager_approval' => match ($approvals['manager']['company_mode']) {
                'required' => 'required_same_final_quote_digest',
                'conditional' => 'evaluate_company_condition_against_final_quote',
                default => 'not_required_by_company_policy',
            },
            'traveler_acceptance' => $approvals['traveler']['company_mode'] === 'required'
                ? 'required_same_final_quote_digest'
                : 'verified_channel_same_final_quote_digest',
            'woo_order_timing' => $effective['order_timing'],
            'supplier_booking_timing' => $effective['supplier_booking_timing'],
        ];
        $digest = hash('sha256', wp_json_encode([
            self::CONTRACT, $receipt['site_uuid'], $receipt['policy_revision'],
            $receipt['preflight_digest'], $chosenRole, $candidate, $requirements,
        ], JSON_UNESCAPED_SLASHES));
        return [
            'contract' => self::CONTRACT,
            'site_uuid' => $receipt['site_uuid'],
            'policy_revision' => $receipt['policy_revision'],
            'preflight_digest' => $receipt['preflight_digest'],
            'plan_digest' => $digest,
            'chosen_sale_line' => $candidate,
            'indicative_preview' => $indicative ? [
                'amount' => (float)$preview['amount'], 'currency' => $preview['currency'],
                'not_a_final_price' => true,
            ] : null,
            'requirements' => $requirements,
            'status' => 'awaiting_certified_commercial_quote',
            'final_quote_issued' => false,
            'manager_approval_verified' => false,
            'traveler_acceptance_verified' => false,
            'inventory_hold_verified' => false,
            'cart_write_authorized' => false,
            'order_creation_authorized' => false,
            'payment_authorized' => false,
            'supplier_booking_authorized' => false,
            'no_side_effects' => true,
        ];
    }

    /** Admin preview: build from native Preflight, never from untrusted proof JSON. */
    public static function routes(): void
    {
        register_rest_route('commerce-composition/v1', '/management/commercial-quote/plan', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [CommercialExecutionPreflight::class, 'allowed'],
            'callback' => static function (\WP_REST_Request $request) {
                $body = $request->get_json_params();
                if (!is_array($body) || array_is_list($body)
                    || !is_int($body['source_id'] ?? null)
                    || !is_array($body['configuration'] ?? null)
                    || !is_string($body['sale_role'] ?? null)
                    || count(array_diff(array_keys($body),
                        ['source_id', 'configuration', 'sale_role', 'market', 'channel'])) > 0) {
                    return new \WP_Error('commercial_plan_request_invalid', 'Invalid request',
                        ['status' => 400]);
                }
                $preflight = CommercialExecutionPreflight::inspect(
                    $body['source_id'], $body['configuration'],
                    (string)($body['market'] ?? ''), (string)($body['channel'] ?? 'web'));
                if (is_wp_error($preflight)) {
                    return new \WP_Error($preflight->get_error_code(),
                        'Native preflight unavailable', ['status' => 422]);
                }
                $plan = self::fromPreflight($preflight, $body['sale_role']);
                if (is_wp_error($plan)) {
                    return new \WP_Error($plan->get_error_code(),
                        'Commercial quote plan unavailable', ['status' => 422]);
                }
                $response = new \WP_REST_Response($plan, 200);
                $response->header('Cache-Control', 'private,no-store');
                return $response;
            },
        ]);
    }

    public static function bootstrap(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }
}
