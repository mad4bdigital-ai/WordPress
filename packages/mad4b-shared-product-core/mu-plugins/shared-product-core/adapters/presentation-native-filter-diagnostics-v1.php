<?php
/**
 * REV108 read-only inspection of native JSF contracts.
 *
 * The admin REST preview is SOURCE evidence, never an impersonation of the
 * live archive. Provider-bound sale, rate, price, service and availability
 * policies remain with their native domain owners.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local'
    || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_presentation_native_filter_diagnostics_v1(): array {
    $sources = shared_presentation_native_typed_manifest_v1();
    $taxBindings = shared_presentation_native_consumers_v1();
    $records = [];
    $states = [];
    $families = [];
    $warnings = 0;
    foreach ($sources as $id => $source) {
        $id = (int)$id;
        $state = (string)($source['state'] ?? 'unknown');
        $family = (string)($source['data_source'] ?? '');
        if ($family === '') $family = (string)($source['filter_type'] ?? '') === 'date-range'
            ? 'native_date_range' : 'unspecified';
        $states[$state] = ($states[$state] ?? 0) + 1;
        $families[$family] = ($families[$family] ?? 0) + 1;
        $binding = $taxBindings[$id] ?? null;
        $resolved = [];
        if ($binding && isset($binding['providers'])) {
            foreach ($binding['providers'] as $slug => $p) {
                $resolved[$slug] = (string)($p['post_type'] ?? '');
            }
        }
        $diagnostics = [];
        if ($family === 'taxonomies' && !$binding) {
            $diagnostics[] = 'no_verified_native_taxonomy_provider_binding';
        }
        if ($family === 'taxonomies' && $binding
            && !count(array_filter($resolved))) {
            $diagnostics[] = 'provider_post_type_unresolved_in_registry';
        }
        if (in_array($state, [
            'unknown_source_fail_closed_for_policy',
            'requires_source_binding_review',
            'cct_requires_registered_collection_and_tenant_scope',
            'range_requires_explicit_price_or_source_contract',
            'native_date_scope_requires_eligibility',
        ], true)) {
            $diagnostics[] = $state;
        }
        if ($family === 'query_builder') {
            $diagnostics[] = 'terms_source_validated_only_not_rate_or_sale_eligibility';
        }
        $warnings += count($diagnostics);
        $records[] = [
            'filter_id' => $id,
            'filter_type' => (string)($source['filter_type'] ?? ''),
            'data_source' => (string)($source['data_source'] ?? ''),
            'query_var' => (string)($source['query_var'] ?? ''),
            'state' => $state,
            'source_owner' => (string)($source['owner'] ?? ''),
            'source_reference' => $source['reference'] ?? null,
            'provider_slugs' => array_values((array)($source['provider_slugs'] ?? [])),
            'verified_post_type_by_provider' => $resolved,
            'template_ids' => array_values(array_map('intval', (array)($source['template_ids'] ?? []))),
            'diagnostics' => $diagnostics,
            'option_policy' => $family === 'taxonomies'
                ? (count(array_filter($resolved))
                    ? 'rev107_scoped_native_taxonomy_options'
                    : 'native_or_specialized_provider_unresolved_no_general_policy_grant')
                : 'native_delegate_no_generalized_option_override',
            'price_and_availability_authorized' => false,
            'write_authorized' => false,
        ];
    }
    ksort($states);
    ksort($families);
    return [
        'schema_version' => '1.0.0',
        'environment' => wp_get_environment_type(),
        'scope' => 'admin_readonly_source_contract_inspection',
        'frontend_equivalence' => false,
        'summary' => [
            'mounted_published_filters' => count($records),
            'source_families' => $families,
            'states' => $states,
            'diagnostic_flags' => $warnings,
        ],
        'contracts' => $records,
    ];
}
function shared_presentation_native_filter_rest_v1(array $payload, int $status = 200): WP_REST_Response {
    $response = new WP_REST_Response($payload, $status);
    $response->header('Cache-Control', 'private, no-store, max-age=0');
    return $response;
}
add_action('rest_api_init', static function(): void {
    $admin = static function(): bool {
        return current_user_can('manage_options')
            && wp_get_environment_type() === 'local'
            && defined('SHARED_PRODUCT_LAB_ONLY') && SHARED_PRODUCT_LAB_ONLY;
    };
    register_rest_route('shared-product/v1', '/native-filter-contracts', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => $admin,
        'args' => [
            'source' => ['type' => 'string', 'required' => false],
            'state' => ['type' => 'string', 'required' => false],
            'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'minimum' => 1, 'maximum' => 100],
        ],
        'callback' => static function(WP_REST_Request $request): WP_REST_Response {
            $all = shared_presentation_native_filter_diagnostics_v1();
            $source = (string)($request->get_param('source') ?? '');
            $state = (string)($request->get_param('state') ?? '');
            $records = array_values(array_filter($all['contracts'],
                static fn(array $r): bool =>
                    (!$source || $r['data_source'] === $source)
                    && (!$state || $r['state'] === $state)));
            $limit = max(1, min(100, (int)$request->get_param('limit') ?: 50));
            $all['matching_count'] = count($records);
            $all['contracts'] = array_slice($records, 0, $limit);
            return shared_presentation_native_filter_rest_v1($all);
        },
    ]);
    register_rest_route('shared-product/v1', '/native-filter-contracts/(?P<id>\d+)/preview', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => $admin,
        'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        'callback' => static function(WP_REST_Request $request) {
            $id = absint($request['id']);
            $manifest = shared_presentation_native_filter_diagnostics_v1();
            $contract = null;
            foreach ($manifest['contracts'] as $candidate) {
                if ($candidate['filter_id'] === $id) {
                    $contract = $candidate;
                    break;
                }
            }
            if (!$contract) return new WP_Error(
                'shared_filter_not_mounted',
                'Filter not mounted by a published native Elementor consumer',
                ['status' => 404]);
            $preview = [
                'filter' => $contract,
                'preview_mode' => 'source_readback_not_public_render',
                'live_provider_scope_evaluated' => false,
                'price_eligibility_evaluated' => false,
                'service_activation_evaluated' => false,
                'availability_evaluated' => false,
                'next_test' => 'native_frontend_provider_and_ajax_e2e',
            ];
            if ($contract['data_source'] === 'taxonomies') {
                $taxonomy = (string)($contract['source_reference']['taxonomy'] ?? '');
                if ($taxonomy && taxonomy_exists($taxonomy)) {
                    $terms = get_terms([
                        'taxonomy' => $taxonomy, 'hide_empty' => true,
                        'number' => 101, 'orderby' => 'name', 'order' => 'ASC'
                    ]);
                    if (!is_wp_error($terms) && is_array($terms)) {
                        $preview['native_unscoped_sample'] = array_map(
                            static fn(WP_Term $term): array => [
                                'term_id' => (int)$term->term_id,
                                'parent' => (int)$term->parent,
                                'name' => $term->name,
                            ], array_slice($terms, 0, 20));
                        $preview['native_source_sample_truncated'] = count($terms) > 20;
                    }
                }
            }
            return shared_presentation_native_filter_rest_v1($preview);
        },
    ]);
}, 25);
