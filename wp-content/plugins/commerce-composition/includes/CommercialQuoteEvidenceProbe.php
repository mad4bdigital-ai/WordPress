<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * QA44: domain-native source receipt probe.
 * Readers and verifiers are installed server-side. Never accepts client receipts.
 * A verified receipt is evidence only: NEVER a final quote, checkout or booking grant.
 */
final class CommercialQuoteEvidenceProbe
{
    private const NS = 'commerce-composition/v1';
    private const KINDS = [
        'supplier_cost', 'tax', 'availability', 'final_quote',
        'manager_approval', 'traveler_acceptance'
    ];
    private const RECEIPT = 'commerce.source-evidence-receipt.v1';

    private static function record(string $state, string $owner = '', array $reasons = []): array
    {
        return [
            'state' => $state, 'source_owner' => $owner,
            'receipt_verified_by_source' => $state === 'source_verified_evidence_only',
            'reasons' => $reasons
        ];
    }

    /**
     * Strict context binding BEFORE source verification, so a verifier cannot
     * attest a receipt from another Site, policy, plan or Woo sale line.
     */
    private static function structurallyBound(array $receipt, array $context, string $kind, string $providerId): bool
    {
        $same = [
            'contract' => self::RECEIPT,
            'kind' => $kind,
            'provider_id' => $providerId,
            'site_uuid' => $context['site_uuid'],
            'policy_revision' => $context['policy_revision'],
            'policy_digest' => $context['policy_digest'],
            'preflight_digest' => $context['preflight_digest'],
            'plan_digest' => $context['plan_digest'],
            'woo_product_id' => $context['woo_product_id'],
        ];
        foreach ($same as $field => $expected) {
            if (!array_key_exists($field, $receipt) || $receipt[$field] !== $expected) return false;
        }
        if (!is_string($receipt['receipt_id'] ?? null)
            || !preg_match('/^[A-Za-z0-9:_-]{6,128}$/D', $receipt['receipt_id'])
            || !is_string($receipt['receipt_digest'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $receipt['receipt_digest'])) return false;

        foreach (['issued_at_utc', 'expires_at_utc'] as $field) {
            if (!is_string($receipt[$field] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $receipt[$field])) return false;
        }
        $issued = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $receipt['issued_at_utc'], new \DateTimeZone('UTC'));
        $expires = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $receipt['expires_at_utc'], new \DateTimeZone('UTC'));
        if (!$issued || !$expires
            || $issued->format('Y-m-d\TH:i:s\Z') !== $receipt['issued_at_utc']
            || $expires->format('Y-m-d\TH:i:s\Z') !== $receipt['expires_at_utc']) return false;
        $now = time();
        return $issued->getTimestamp() <= $now + 60
            && $expires->getTimestamp() > $now
            && $expires->getTimestamp() > $issued->getTimestamp();
    }

    public static function inspect(int $sourceId, array $configuration, string $saleRole,
        string $market = '', string $channel = 'web'): array|\WP_Error
    {
        $readiness = CommercialQuoteReadiness::inspect($sourceId, $configuration, $saleRole, $market, $channel);
        if (is_wp_error($readiness)) return $readiness;

        $chosen = $readiness['selected_sale_line'];
        $context = [
            'contract' => 'commerce.source-evidence-read-context.v1',
            'site_uuid' => $readiness['site_uuid'],
            'policy_revision' => $readiness['policy_revision'],
            'policy_digest' => $readiness['policy_digest'],
            'preflight_digest' => $readiness['preflight_digest'],
            'plan_digest' => $readiness['plan_digest'],
            'woo_product_id' => $chosen['woo_product_id'],
            'service_id' => $chosen['service_id'],
            'rate_id' => $chosen['rate_id'],
            'sale_role' => $chosen['role'],
        ];
        // No personal data, addresses, contact details or unapproved prices
        // may travel to the source provider.
        $travelDate = $configuration['travel_date'] ?? null;
        if (is_string($travelDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $travelDate)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $travelDate);
            if ($date && $date->format('Y-m-d') === $travelDate) {
                $context['travel_date'] = $travelDate;
            }
        }
        $occupancy = $configuration['occupancy'] ?? null;
        if (is_string($occupancy) && in_array($occupancy,
            ['single','double','twin','triple','quad','quadruple','family','shared'], true)) {
            $context['occupancy'] = $occupancy;
        }
        $declared = apply_filters('commerce_composition_final_quote_evidence_providers', []);
        if (!is_array($declared)) $declared = [];
        $results = [];

        foreach (self::KINDS as $kind) {
            if (!array_key_exists($kind, $declared)) {
                $results[$kind] = self::record('missing_provider', '', ['source_adapter_not_registered']);
                continue;
            }
            $adapter = $declared[$kind];
            if (!is_array($adapter)
                || ($adapter['contract'] ?? null) !== 'commerce.source-evidence-provider.v1'
                || ($adapter['site_uuid'] ?? null) !== $context['site_uuid']
                || !is_string($adapter['provider_id'] ?? null)
                || !preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D', $adapter['provider_id'])
                || !is_string($adapter['source_owner'] ?? null)
                || !preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D', $adapter['source_owner'])
                || !is_callable($adapter['read'] ?? null)
                || !is_callable($adapter['verify'] ?? null)) {
                $results[$kind] = self::record('invalid_provider_binding', '', ['tenant_or_contract_or_verifier_invalid']);
                continue;
            }
            $owner = $adapter['source_owner'];
            try {
                $receipt = ($adapter['read'])($context);
            } catch (\Throwable $e) {
                $results[$kind] = self::record('source_unavailable', $owner, ['source_read_failed']);
                continue;
            }
            if (is_wp_error($receipt) || !is_array($receipt)) {
                $results[$kind] = self::record('source_unavailable', $owner, ['no_source_receipt']);
                continue;
            }
            if (!self::structurallyBound($receipt, $context, $kind, $adapter['provider_id'])) {
                $results[$kind] = self::record('receipt_rejected', $owner, ['source_receipt_context_or_expiry_invalid']);
                continue;
            }
            try {
                $verified = ($adapter['verify'])($receipt, $context) === true;
            } catch (\Throwable $e) {
                $verified = false;
            }
            $results[$kind] = $verified
                ? self::record('source_verified_evidence_only', $owner)
                : self::record('receipt_rejected', $owner, ['source_provenance_verification_failed']);
        }

        $observed = count(array_filter($results,
            static fn(array $r) => $r['receipt_verified_by_source']));
        return [
            'contract' => 'commerce.source-evidence-probe.v1',
            'site_uuid' => $context['site_uuid'],
            'policy_revision' => $context['policy_revision'],
            'policy_digest' => $context['policy_digest'],
            'plan_digest' => $context['plan_digest'],
            'selected_sale_line' => $chosen,
            'readiness_digest' => $readiness['readiness_digest'],
            'company_policy_status' => $readiness['company_policy_status'],
            'source_receipts_observed' => $observed,
            'source_receipts_required' => count(self::KINDS),
            'provider_probes' => $results,
            'commercial_readiness_pending_gates' => $readiness['pending_gates'],
            'final_quote_certified' => false,
            'commercial_quote_issued' => false,
            'manager_approval_authorized' => false,
            'traveler_acceptance_authorized' => false,
            'checkout_authorized' => false,
            'order_authorized' => false,
            'payment_authorized' => false,
            'inventory_hold_authorized' => false,
            'supplier_booking_authorized' => false,
            'no_side_effects' => true,
        ];
    }

    public static function routes(): void
    {
        register_rest_route(self::NS, '/management/commercial-quote/evidence-probe', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => static fn() =>
                current_user_can('manage_options') && current_user_can('manage_woocommerce'),
            'callback' => static function (\WP_REST_Request $request) {
                $body = $request->get_json_params();
                if (!is_array($body) || array_is_list($body)
                    || !is_int($body['source_id'] ?? null)
                    || !is_array($body['configuration'] ?? null)
                    || !is_string($body['sale_role'] ?? null)
                    || array_diff(array_keys($body),
                        ['source_id', 'configuration', 'sale_role', 'market', 'channel'])
                    || (isset($body['market']) && !is_string($body['market']))
                    || (isset($body['channel']) && !is_string($body['channel']))) {
                    return new \WP_Error('commercial_evidence_probe_invalid_input',
                        'Invalid commercial evidence probe request', ['status' => 400]);
                }
                $result = self::inspect($body['source_id'], $body['configuration'],
                    $body['sale_role'], (string)($body['market'] ?? ''),
                    (string)($body['channel'] ?? 'web'));
                if (is_wp_error($result)) {
                    return new \WP_Error($result->get_error_code(),
                        'Commercial source evidence unavailable', ['status' => 422]);
                }
                $response = new \WP_REST_Response($result, 200);
                $response->header('Cache-Control', 'private,no-store');
                return $response;
            }
        ]);
    }

    public static function bootstrap(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }
}
