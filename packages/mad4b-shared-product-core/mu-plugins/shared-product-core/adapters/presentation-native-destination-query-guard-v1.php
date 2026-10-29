<?php
/**
 * REV109: company-aware destination authorization for native Tour Archive
 * direct JSF URLs and its corresponding AJAX query. No global term rewrite.
 * Presentation option policy alone is NOT an authorization boundary.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local'
    || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_presentation_native_destination_sale_authorized_v1($value): bool {
    if (!is_scalar($value) || !function_exists('native_destination_approved')
        || !function_exists('native_destinations_sale_term_index')
        || !function_exists('service_channel_allowed')
        || !service_channel_allowed('tours','destination_discovery')) return false;
    $raw = (string)$value;
    if ($raw === '' || strlen($raw) > 80) return false;
    $term = ctype_digit($raw)
        ? get_term((int)$raw, 'destinations')
        : get_term_by('slug', $raw, 'destinations');
    if (!$term instanceof WP_Term || $term->taxonomy !== 'destinations') return false;
    if (function_exists('destinations_activation_allowed')
        && !destinations_activation_allowed((int)$term->term_id)) return false;
    $en = (int)apply_filters('wpml_object_id',$term->term_id,'destinations',false,'en');
    $canonical = $en ?: (int)$term->term_id;
    $sellable = native_destinations_sale_term_index('en');
    if (!isset($sellable[$canonical])) return false;
    $countries = [];
    foreach (native_destination_approved('en') as $c) {
        $id = absint($c['id'] ?? 0);
        if ($id) $countries[$id] = true;
    }
    if (!$countries) return false;
    $chain = array_merge([(int)$term->term_id],
        array_map('intval',get_ancestors((int)$term->term_id,'destinations','taxonomy')));
    foreach ($chain as $id) {
        $translated = (int)apply_filters('wpml_object_id',$id,'destinations',false,'en');
        if (isset($countries[$translated ?: $id])) return true;
    }
    return false;
}
/**
 * Pure inspector. Empty result is safe; it means the request did not select
 * destinations, or the exact authorized provider was not involved.
 */
function shared_presentation_native_destination_denied_v1(
    string $requestUri, ?array $ajax = null
): bool {
    $descriptor = shared_presentation_catalog_contract_v1();
    $provider = (string)($descriptor['provider']??'');
    if (!preg_match('~^jet-engine/[a-z0-9_-]{2,80}$~D',$provider)) return false;
    if (is_array($ajax)) {
        if (($ajax['action'] ?? '') !== 'jet_smart_filters'
            || ($ajax['provider'] ?? '') !== $provider) return false;
        $q = $ajax['query'] ?? [];
        if (!is_array($q) || !array_key_exists('_tax_query_destinations', $q)) return false;
        $raw = $q['_tax_query_destinations'];
        if (!is_scalar($raw) && !is_array($raw)) return true;
        $tokens = is_array($raw) ? $raw : explode(',',(string)$raw);
        if (count($tokens) > 10) return true;
        foreach ($tokens as $token) {
            if (!is_scalar($token)) return true;
            $value = trim((string)$token);
            if ($value !== '' && !shared_presentation_native_destination_sale_authorized_v1($value)) return true;
        }
        return false;
    }
    $path = wp_parse_url($requestUri,PHP_URL_PATH);
    $nativePath = str_replace('/', ':', $provider);
    if (!is_string($path) || !preg_match(
        '~^/(?:ar/|de/|fr/|es/|it/)?tours-and-activities/jsf/'.preg_quote($nativePath,'~').'/tax/([^/?#]+)~',
        $path,$match
    )) return false;
    foreach (explode(';',rawurldecode($match[1])) as $part) {
        if (!str_starts_with($part,'destinations:')) continue;
        $list = substr($part,strlen('destinations:'));
        $tokens = explode(',',$list);
        if (!$list || count($tokens) > 10) return true;
        foreach($tokens as $token) {
            if (!shared_presentation_native_destination_sale_authorized_v1($token)) return true;
        }
    }
    return false;
}
/** Exact Tour Catalog Query Builder owner, not global WP_Query. */
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query) {
    $descriptor = shared_presentation_catalog_contract_v1();
    $provider = (string)($descriptor['provider']??'');
    if (!str_starts_with($provider,'jet-engine/') ||
        !is_array($args) ||
        (int)($query->id??0)!==(int)($descriptor['query_id']??0) ||
        ($query->query_id??'')!==substr($provider,strlen('jet-engine/'))) return $args;
    $invalid = wp_doing_ajax()
        ? shared_presentation_native_destination_denied_v1('',$_POST)
        : (!is_admin() && shared_presentation_native_destination_denied_v1((string)($_SERVER['REQUEST_URI']??'')));
    if ($invalid) $args['post__in'] = [0];
    return $args;
}, 2200, 2);
