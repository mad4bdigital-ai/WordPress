<?php
/**
 * Unified Display Context Registry v1 — isolated Local QA, observational only.
 * No monetary calculation, commerce approval, SQL rewrite, or DOM pruning.
 * Source systems remain the sole authorities for their respective domains.
 */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    return;
}

final class Shared_Display_Context_Registry {
    public const CONTRACT = 'shared.display-context.v1';

    public static function authorities(): array {
        return [
            'language'       => 'WPML runtime active languages',
            'market'         => 'approved Market Profiles / explicit customer context',
            'targeting'      => 'MAD4B Market Profiles targeting exclusions / country and language policy',
            'preferences'    => 'validated Market Preferences; not commercial authority',
            'currency'       => 'WooCommerce / WCML customer presentation currency',
            'exchange'       => 'shared_product currency provider (validated quote FX only)',
            'pricing'        => 'Tour Rate / service-specific price provider',
            'season'         => 'existing Season taxonomy and Tour Rate validity',
            'service'        => 'Service Activation public availability',
            'visibility'     => 'JetEngine Dynamic Visibility / Elementor',
            'commerce'       => 'native booking and quote engines',
            'accounting'     => 'ERP ledger (never inferred from display currency)',
        ];
    }

    private static function currency($value): ?string {
        if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value)) {
            return null;
        }
        return function_exists('get_woocommerce_currencies')
            && !array_key_exists($value, get_woocommerce_currencies()) ? null : $value;
    }

    private static function valid_date($value): bool {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $value;
    }

    public static function resolve(array $input = []) {
        // All request-specific selections are explicit. Never infer buyer intent from GeoIP,
        // URL parameters, a theme currency switcher, or stale PHP globals.
        $allowed = [
            'tour_id', 'rate_id', 'travel_date', 'season_term_id', 'package_term_id',
            'accommodation_id', 'transport_id', 'occupancy', 'travellers',
            'extras_hash', 'provider_revision', 'service', 'channel', 'audience',
        ];
        if (array_diff(array_keys($input), $allowed)) {
            return new WP_Error('display_context_unknown_dimension');
        }
        $ids = ['tour_id','rate_id','season_term_id','package_term_id',
                'accommodation_id','transport_id','travellers'];
        foreach ($ids as $key) {
            if (!array_key_exists($key, $input)) continue;
            if (!is_int($input[$key]) || $input[$key] < 1) {
                return new WP_Error('display_context_invalid_integer', $key);
            }
        }
        // Booking Configuration v2 sets a hard maximum of 999 travellers.
        // The display layer cannot claim a larger validated party.
        if (isset($input['travellers']) && $input['travellers'] > 999) {
            return new WP_Error('display_context_travellers_exceed_native_limit');
        }
        if (isset($input['audience']) && !in_array($input['audience'], ['general','egyptians'], true)) {
            return new WP_Error('display_context_invalid_audience');
        }
        if (isset($input['travel_date']) && !self::valid_date($input['travel_date'])) {
            return new WP_Error('display_context_invalid_travel_date');
        }
        if (isset($input['occupancy']) && (!is_string($input['occupancy'])
             || !preg_match('/^[a-z][a-z0-9_-]{0,30}$/D', $input['occupancy']))) {
            return new WP_Error('display_context_invalid_occupancy');
        }
        foreach (['extras_hash','provider_revision'] as $key) {
            if (isset($input[$key]) && (!is_string($input[$key])
                || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $input[$key]))) {
                return new WP_Error('display_context_invalid_revision', $key);
            }
        }
        if (isset($input['service']) && !in_array($input['service'], [
            'tours','properties','transportations','visas',
        ], true)) return new WP_Error('display_context_unknown_service');

        $language = sanitize_key((string) apply_filters('wpml_current_language', ''));
        $runtimeLanguages = [];
        $wpml = $GLOBALS['sitepress'] ?? null;
        if (is_object($wpml) && method_exists($wpml, 'get_active_languages')) {
            $runtimeLanguages = array_keys((array) $wpml->get_active_languages());
        }
        $languageVerified = $language !== ''
            && (!$wpml || in_array($language, $runtimeLanguages, true));
        $languageState = $languageVerified ? 'verified' : 'unresolved';

        $market = function_exists('shared_product_market_current_context')
            ? shared_product_market_current_context() : [];
        if (!is_array($market)) $market = [];
        $marketStatus = (string) ($market['status'] ?? 'provider_unavailable');
        $marketApproved = in_array($marketStatus, [
            'auto_matched_site_approved_market', 'explicit_site_approved_market',
        ], true);
        if ($marketApproved && ($market['language'] ?? null) !== $language) {
            $marketApproved = false;
            $marketStatus = 'approved_market_language_conflict';
        }

        $storeCurrency = self::currency((string) get_option('woocommerce_currency', ''));
        $commerceCurrency = function_exists('get_woocommerce_currency')
            ? self::currency((string) get_woocommerce_currency()) : null;
        $wcml = apply_filters('wcml_client_currency', null);
        $wcmlCurrency = self::currency($wcml);
        // A selected-but-invalid WCML currency must fail closed, not silently
        // revert to the store currency and mislabel an amount.
        $wcmlHasSelection = is_string($wcml) && $wcml !== '';
        $presentationCurrency = $wcmlHasSelection ? $wcmlCurrency : $commerceCurrency;
        $presentationAuthority = $wcmlHasSelection
            ? ($wcmlCurrency ? 'WCML' : 'WCML_invalid_selection')
            : ($commerceCurrency ? 'WooCommerce' : 'unresolved');

        $tourId = $input['tour_id'] ?? null;
        $rateId = $input['rate_id'] ?? null;
        if ($tourId && get_post_type($tourId) !== 'tours-and-activities') {
            return new WP_Error('display_context_invalid_tour');
        }
        if ($rateId) {
            if (get_post_type($rateId) !== 'tour-rates') {
                return new WP_Error('display_context_invalid_rate');
            }
            $linkedTourId = (int) get_post_meta($rateId, 'related_tour_id', true);
            if (!$tourId || $linkedTourId !== $tourId) {
                return new WP_Error('display_context_rate_tour_mismatch');
            }
        }
        $audience = $input['audience'] ?? null;
        if ($rateId && $audience === 'egyptians' &&
            (string) get_post_meta($rateId, 'has_egyptians_price', true) !== '1') {
            return new WP_Error('display_context_audience_not_available');
        }
        $sourceCurrency = $rateId
            ? self::currency((string) get_post_meta($rateId,
                $audience === 'egyptians' ? 'egyptians_currency' : 'base_currency', true))
            : null;
        $fxRate = null;
        $fxStatus = 'not_requested';
        if ($sourceCurrency !== null && $presentationCurrency !== null) {
            $fxRate = function_exists('pricing_public_fx_rate')
                ? pricing_public_fx_rate($sourceCurrency, $presentationCurrency)
                : ($sourceCurrency === $presentationCurrency ? 1.0 : null);
            $fxStatus = $fxRate === null ? 'missing_or_stale_fx'
                : ($sourceCurrency === $presentationCurrency ? 'same_currency' : 'verified_provider_rate');
        } elseif ($rateId) {
            $fxStatus = 'unresolved_currency';
        }

        $service = $input['service'] ?? null;
        // Explicit surface fixes 'tour_only' services disappearing from tour
        // component UI. No channel parameter means legacy standalone semantics.
        $channel = $input['channel'] ?? 'standalone';
        if (!in_array($channel, ['standalone', 'tour_component', 'destination_discovery'], true)) {
            return new WP_Error('display_context_unknown_channel');
        }
        if ($channel === 'tour_component' && (!$tourId || get_post_status($tourId) !== 'publish')) {
            return new WP_Error('display_context_tour_component_requires_published_tour');
        }
        if ($channel === 'destination_discovery' && $tourId !== null) {
            return new WP_Error('display_context_discovery_cannot_impersonate_tour');
        }
        $serviceAllowed = $service !== null && function_exists('service_channel_allowed')
            ? (bool) service_channel_allowed($service, $channel) : null;

        $actor = wp_get_current_user();
        $roles = is_object($actor) ? array_values((array) $actor->roles) : [];
        sort($roles, SORT_STRING);
        $actorId = is_object($actor) ? (int) $actor->ID : 0;
        $surface = is_admin() ? 'admin' : (wp_doing_ajax() ? 'ajax' : (defined('REST_REQUEST') && REST_REQUEST ? 'rest' : 'frontend'));
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
        $actorScope = $actorId > 0 ? 'authenticated' : 'guest';
        $fxSnapshot = function_exists('pricing_public_fx_snapshot')
            ? pricing_public_fx_snapshot() : null;
        $revision = is_array($fxSnapshot)
            ? (string) ($fxSnapshot['fetched_at'] ?? 'unknown') : 'missing_or_expired';

        $selections = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) $selections[$key] = $input[$key];
        }
        // Every participating authority resolves once for this context only.
        // A snapshot and its fingerprint must agree even if a switcher or
        // policy hook changes later in the request. No cross-call caching.
        $facets = apply_filters('shared_display_context/facet_snapshots', [], $selections);
        if (!is_array($facets) || array_diff(array_keys($facets), ['policies', 'targeting'])) {
            return new WP_Error('display_context_facets_invalid');
        }
        $facetFingerprints = [];
        foreach ($facets as $facet) {
            if (!is_array($facet) || !is_array($facet['snapshot'] ?? null)
                || !is_array($facet['fingerprints'] ?? null)) {
                return new WP_Error('display_context_facet_invalid');
            }
            foreach ($facet['fingerprints'] as $dimension => $value) {
                if (array_key_exists($dimension, $facetFingerprints)) {
                    return new WP_Error('display_context_facet_collision');
                }
                $facetFingerprints[$dimension] = $value;
            }
        }
        // Future providers may add bounded fingerprint dimensions, never
        // overwrite the native policy or market authority's cache partitions.
        $extensions = apply_filters('shared_display_context/extension_fingerprints', $facetFingerprints, $selections);
        if (is_array($extensions)) {
            foreach ($facetFingerprints as $dimension => $original) {
                if (!array_key_exists($dimension, $extensions)
                    || $extensions[$dimension] !== $original) {
                    return new WP_Error('display_context_facet_fingerprint_overwrite');
                }
            }
        }
        if (!is_array($extensions) || count($extensions) > 20) {
            return new WP_Error('display_context_extensions_invalid');
        }
        foreach ($extensions as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{1,48}$/D', $key)
                || !(is_int($value) || is_bool($value) ||
                (is_string($value) && strlen($value) <= 128))) {
                return new WP_Error('display_context_extension_invalid');
            }
        }
        ksort($extensions, SORT_STRING);
        // Identity and active session conditions participate in the digest,
        // not in reusable output shared across visitors.
        $fingerprint = [
            'contract' => self::CONTRACT,
            'language' => $languageVerified ? $language : null,
            'language_state' => $languageState,
            'market_profile' => $marketApproved ? ($market['profile_id'] ?? null) : null,
            'market_revision' => $marketApproved ? ($market['profile_revision'] ?? null) : null,
            'market_status' => $marketStatus,
            'market_country' => $marketApproved ? ($market['country'] ?? null) : null,
            'nationality' => $marketApproved ? ($market['nationality'] ?? null) : null,
            'store_currency' => $storeCurrency,
            'presentation_currency' => $presentationCurrency,
            'source_currency' => $sourceCurrency,
            'fx_revision' => $revision,
            'service' => $service,
            'channel' => $channel,
            'service_allowed' => $serviceAllowed,
            'activation_revision' => hash('sha256', wp_json_encode(get_option('service-activation', []))),
            'selections' => $selections,
            'extension' => $extensions,
            'actor_scope' => $actorScope,
            'actor_id' => $actorId,
            'actor_roles' => $roles,
            'surface' => $surface,
            'method' => $method,
            'editor_preview' => (class_exists('Elementor\Plugin')
                && isset(\Elementor\Plugin::$instance->preview)
                && \Elementor\Plugin::$instance->preview->is_preview_mode()),
        ];
        $key = hash('sha256', wp_json_encode($fingerprint));

        $response = [
            'contract' => self::CONTRACT,
            'mode' => 'read_only',
            'scope' => $surface,
            'language' => [
                'code' => $languageVerified ? $language : null,
                'status' => $languageState,
                'authority' => 'WPML',
                'runtime_active' => $runtimeLanguages,
            ],
            'market' => [
                'status' => $marketStatus,
                'approved' => $marketApproved,
                'profile_id' => $marketApproved ? ($market['profile_id'] ?? null) : null,
                'revision' => $marketApproved ? ($market['profile_revision'] ?? null) : null,
                'country' => $marketApproved ? ($market['country'] ?? null) : null,
                'nationality' => $marketApproved ? ($market['nationality'] ?? null) : null,
                'authority' => 'Market Profiles',
            ],
            'currency' => [
                'store' => $storeCurrency,
                'presentation' => $presentationCurrency,
                'presentation_authority' => $presentationAuthority,
                'presentation_status' => $presentationCurrency !== null ? 'resolved' : 'blocked',
                'transaction_source' => $sourceCurrency,
                'accounting' => null,
                'accounting_authority' => 'ERP',
                'exchange_status' => $fxStatus,
                'exchange_rate' => $fxRate,
                'exchange_authority' => 'Currency Provider',
                'exchange_revision' => $revision,
            ],
            'availability' => ['status' => 'not_checked', 'authority' => 'Native booking engine'],
            'pricing' => [
                'status' => $rateId ? 'source_identified_not_quoted' : 'not_quoted',
                'audience' => $audience,
                'audience_authority' => 'existing Tour Rate audience resolver',
                // The passport switcher merely declares visitor nationality.
                // Only canonical Booking v2 validates every traveller's
                // nationality for an Egyptian-audience commercial quote.
                'audience_evidence_status' => $audience === 'egyptians'
                    ? 'requires_canonical_all_traveller_passport_validation'
                    : 'not_evaluated_by_display',
                'audience_pricing_eligibility_authorized' => false,
                'authority' => 'Native price / Tour Rates',
                'quote_authorized' => false,
                'checkout_authorized' => false,
            ],
            'visibility' => [
                'service' => $service,
                'channel' => $channel,
                'service_allowed' => $serviceAllowed,
                'authority' => 'Service Activation / JetEngine / Elementor',
                'prerender_skip_authorized' => false,
            ],
            'selections' => $selections,
            'actor' => ['scope' => $actorScope, 'roles' => $roles],
            'cache' => [
                'fingerprint' => $key,
                'request_local_only' => true,
                'persistent_eligible' => false,
                'may_prerender_skip' => false,
                'reasons' => array_values(array_filter([
                    !$languageVerified ? 'unverified_wpml_language' : null,
                    !$marketApproved ? 'no_approved_market_for_price_variants' : null,
                    $presentationCurrency === null ? 'presentation_currency_unresolved' : null,
                    $rateId && $fxRate === null ? 'currency_conversion_unavailable' : null,
                    $actorId > 0 ? 'authenticated_identity' : null,
                ])),
            ],
            'authorities' => self::authorities(),
            'mutation_performed' => false,
        ];
        // Additive policy facet; the pricing/member engines alone determine
        // entitlements, discounts, stacking and final quote approvals.
        $response['targeting'] = $facets['targeting']['snapshot'] ?? [
            'contract' => 'shared.target-market.v1',
            'status' => 'authority_unavailable',
            'commercial_authorized' => false,
            'content_pruning_authorized' => false,
        ];
        $response['policies'] = $facets['policies']['snapshot'] ?? [
            'contract' => 'shared.display-policy.v1',
            'status' => 'provider_unavailable',
            'mutation_performed' => false,
        ];
        return $response;
    }

    /** Inspect the original JetEngine decision; never replace that authority. */
    public static function visibility_probe($element, array $input = []) {
        $snapshot = self::resolve($input);
        if (is_wp_error($snapshot)) return $snapshot;
        if (!is_object($element) || !method_exists($element, 'get_settings')
            || !method_exists($element, 'get_settings_for_display')) {
            return new WP_Error('display_context_element_invalid');
        }
        $settings = $element->get_settings();
        $enabled = filter_var($settings['jedv_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $result = [
            'contract' => self::CONTRACT,
            'context_fingerprint' => $snapshot['cache']['fingerprint'],
            'authority' => 'JetEngine Dynamic Visibility',
            'decision' => 'not_applicable',
            'prerender_skip_authorized' => false,
            'status' => 'audit_only',
        ];
        if (!$enabled) return $result;
        $checker = 'Jet_Engine\Modules\Dynamic_Visibility\Condition_Checker';
        if (!class_exists($checker)) {
            $result['decision'] = 'unknown_provider_unavailable';
            return $result;
        }
        try {
            // Effective dynamic settings, not raw Elementor JSON, determine
            // whether a conditional node can render. Never infer from raw data.
            $allowed = (new $checker())->check_cond(
                $settings, $element->get_settings_for_display()
            );
            $result['decision'] = $allowed ? 'show' : 'hide';
        } catch (\Throwable $e) {
            $result['decision'] = 'unknown_provider_error';
        }
        return $result;
    }

    public static function rest(): void {
        register_rest_route('shared-display/v1', '/context', [
            'methods' => 'GET',
            'permission_callback' => static fn() => current_user_can('manage_woocommerce'),
            'callback' => static function (\WP_REST_Request $request) {
                $values = [];
                $allowed = [
                    'tour_id','rate_id','travel_date','season_term_id',
                    'package_term_id','accommodation_id','transport_id',
                    'occupancy','travellers','extras_hash','provider_revision','service','channel','audience',
                ];
                $numeric = [
                    'tour_id','rate_id','season_term_id','package_term_id',
                    'accommodation_id','transport_id','travellers',
                ];
                $params = $request->get_params();
                if (array_diff(array_keys($params), $allowed)) {
                    return new WP_Error('display_context_unknown_dimension',
                        'Only declared display dimensions are supported', ['status'=>422]);
                }
                foreach ($params as $field => $value) {
                    $values[$field] = in_array($field, $numeric, true)
                        && is_scalar($value) && ctype_digit((string) $value)
                        ? (int) $value : $value;
                }
                $snapshot = self::resolve($values);
                if (is_wp_error($snapshot)) {
                    $code = $snapshot->get_error_code();
                    // Invalid client context is not an internal server error.
                    // Failures of an essential provider remain 503.
                    $http = str_contains((string) $code, '_unavailable') ? 503 : 422;
                    return new WP_Error($code,
                        $http === 422 ? 'Invalid or unsupported display context'
                            : 'Display context provider unavailable',
                        ['status'=>$http]);
                }
                return rest_ensure_response($snapshot);
            },
        ]);
    }
}
function shared_display_context_snapshot(array $input = []) {
    return Shared_Display_Context_Registry::resolve($input);
}
/** Stable read-only value adapter for Elementor / JetEngine / PHP consumers. */
function shared_display_context_value(string $key, array $input = []) {
    $supported = [
        'language' => ['language', 'code'],
        'market_status' => ['market', 'status'],
        'nationality' => ['targeting', 'nationality', 'selected'],
        'nationality_selection_source' => ['targeting', 'nationality', 'selection_source'],
        'market_country' => ['market', 'country'],
        'currency' => ['currency', 'presentation'],
        'currency_status' => ['currency', 'presentation_status'],
        'rate_currency' => ['currency', 'transaction_source'],
        'exchange_status' => ['currency', 'exchange_status'],
        'service_allowed' => ['visibility', 'service_allowed'],
        'availability_status' => ['availability', 'status'],
        'target_market_policy_revision' => ['targeting', 'policy_revision'],
    ];
    if (!isset($supported[$key])) return new WP_Error('display_context_value_not_allowed');
    $snapshot = shared_display_context_snapshot($input);
    if (is_wp_error($snapshot)) return $snapshot;
    $value = $snapshot;
    foreach ($supported[$key] as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return null;
        $value = $value[$part];
    }
    return is_scalar($value) ? $value : null;
}

add_action('rest_api_init', ['Shared_Display_Context_Registry', 'rest']);
