<?php
/**
 * Local Lab: quote-derived numeric filter for the native Tours catalog.
 * In-memory index is rebuilt each request (no stale postmeta or exchange rates).
 * Never mutates Rates, stored selling prices, WCML settings or orders.
 */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;

function shared_presentation_catalog_price_scope_v1(): bool {
    if (wp_doing_ajax()) return is_array(shared_presentation_catalog_jsf_request_v1());
    if (is_admin()) return false;
    return is_array(shared_presentation_catalog_url_context_v1($_SERVER['REQUEST_URI'] ?? ''));
}

function shared_presentation_catalog_price_facet_index_v1(): array {
    static $cache = [];
    $currency = function_exists('pricing_display_currency') ? pricing_display_currency() : get_woocommerce_currency();
    $language = apply_filters('wpml_current_language', null) ?: 'en';
    $key = $currency . '/' . $language . '/' . current_time('Y-m-d');
    if (isset($cache[$key])) return $cache[$key];
    $ids = get_posts([
        'post_type' => 'tours-and-activities', 'post_status' => 'publish',
        'posts_per_page' => 1501, 'fields' => 'ids', 'suppress_filters' => false,
        'meta_query' => [
            'relation'=>'AND',
            ['key'=>'tour_public_status','value'=>'0','compare'=>'!=','type'=>'NUMERIC'],
            ['key'=>'tour_mode','value'=>'extra_trip','compare'=>'!='],
        ],
    ]);
    // A partial result would yield wrong pricing. Fail closed on catalog growth.
    if (!is_array($ids) || count($ids)>1500) return $cache[$key] = ['valid'=>false,'quotes'=>[]];
    // WP_Query(fields=ids) does not bulk-prime postmeta. Price resolution
    // reads source tour metadata for each result; prime in one query first.
    // This is request-local WP object cache, NOT a cached price or FX quote.
    if ($ids) update_meta_cache('post', $ids);
    // One request-local scan replaces one related_tour_id SQL lookup per tour.
    // The same source rate resolver still validates dates, seasons, occupancy
    // prices, sale status, translations, currency and current FX authority.
    $use_rate_map = false;
    if (count($ids) > 1) {
        global $wpdb;
        $relations = $wpdb->get_results(
            $wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s LIMIT 10001",
                'related_tour_id'), ARRAY_A);
        if (is_array($relations) && count($relations) < 10001) {
            $by_tour = [];
            foreach ($relations as $relation) {
                $tour = (string)($relation['meta_value'] ?? '');
                $rate = (int)($relation['post_id'] ?? 0);
                if (ctype_digit($tour) && $rate > 0) $by_tour[$tour][$rate]=$rate;
            }
            $GLOBALS['mad4b_catalog_rate_lookup_v1']=$by_tour;
            $use_rate_map = true;
        }
    }
    $quotes = [];
    try {
    foreach ($ids as $id) {
        $id = (int)$id;
        $evidence = shared_lab_tour_price_resolve($id);
        if (($evidence['status'] ?? '') !== 'available') continue;
        $amount = $evidence['best']['final'] ?? null;
        $source = $evidence['currency'] ?? null;
        if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount <= 0
            || !is_string($source) || !preg_match('/^[A-Z]{3}$/D',$source)) continue;
        // Same original FX authority as the canonical price presenter.
        $display = pricing_currency_convert((float)$amount,$source,$currency);
        if (!is_numeric($display) || !is_finite((float)$display) || (float)$display <= 0) continue;
        $quotes[$id] = round((float)$display, 2);
    }
    } finally {
        if ($use_rate_map) unset($GLOBALS['mad4b_catalog_rate_lookup_v1']);
    }
    return $cache[$key] = ['valid'=>true,'quotes'=>$quotes,'currency'=>$currency];
}

// JetSmartFilters' supported range-source hook. It now sees the same monetary
// amounts as the native Dynamic Tags rather than the stale start_price meta.
add_filter('jet-smart-filters/range-filter/source-data', static function ($existing,$callback,$query_var,$query_args) {
    if ($query_var !== 'start_price' || !shared_presentation_catalog_price_scope_v1()) return $existing;
    $index = shared_presentation_catalog_price_facet_index_v1();
    if (!$index['valid'] || !$index['quotes']) return ['min'=>0,'max'=>0];
    $context=wp_doing_ajax()
        ?shared_presentation_catalog_jsf_request_v1()
        :shared_presentation_catalog_url_context_v1($_SERVER['REQUEST_URI']??'');
    // Native bulk object/term relationships: avoid N*M has_term() calls
    // for 40+ tours and multiple JSF taxonomies. Keep WordPress matching
    // semantics (ID, slug, name) and native has_term fallback on WP errors.
    $eligible_ids = array_fill_keys(array_keys($index['quotes']), true);
    foreach ((array)($context['facets']??[]) as $taxonomy=>$slugs) {
        if (!$eligible_ids) break;
        $terms = wp_get_object_terms(array_keys($eligible_ids),$taxonomy,
            ['fields'=>'all_with_object_id']);
        $allowed = [];
        if (is_wp_error($terms)) {
            foreach ($eligible_ids as $id=>$unused)
                if (has_term((array)$slugs,$taxonomy,(int)$id)) $allowed[(int)$id]=true;
        } else {
            $need = array_map('strval',(array)$slugs);
            foreach ($terms as $term) {
                if (!($term instanceof WP_Term)) continue;
                if (in_array((string)$term->slug,$need,true)
                    || in_array((string)$term->name,$need,true)
                    || in_array((string)$term->term_id,$need,true))
                    $allowed[(int)$term->object_id]=true;
            }
        }
        $eligible_ids = array_intersect_key($eligible_ids,$allowed);
    }
    $values = [];
    foreach ($eligible_ids as $id=>$unused) $values[]=$index['quotes'][$id];
    if (!$values) return ['min'=>0,'max'=>0];
    return ['min'=>min($values),'max'=>max($values)];
}, 1000, 4);

// The existing native slider gets a dynamically resolved currency suffix and
// precision for this ONE filter. Its saved settings remain editable/unchanged.
add_filter('get_post_metadata', static function ($value,$object_id,$meta_key,$single) {
    // Almost every Elementor/JetSmartFilters metadata read passes through
    // here. Resolve the expensive Registry only for these 3 range settings,
    // otherwise dynamic facet discovery would recursively read itself.
    if (!in_array((string)$meta_key,['_values_suffix','_values_decimal_num','_source_step'],true))
        return $value;
    $registry=function_exists('shared_presentation_provider_v2')
        ?shared_presentation_provider_v2('tours.archive'):null;
    $ids=array_map('intval',(array)($registry['price_filter_ids']??[24515]));
    if (!in_array((int)$object_id,$ids,true) ||
        !shared_presentation_catalog_price_scope_v1()) return $value;
    $replacements=[
        '_values_suffix'=>' '.(function_exists('pricing_display_currency')?pricing_display_currency():get_woocommerce_currency()),
        '_values_decimal_num'=>'2',
        '_source_step'=>'1',
    ];
    if (!array_key_exists((string)$meta_key,$replacements)) return $value;
    return $single ? $replacements[$meta_key] : [$replacements[$meta_key]];
}, 1000, 4);

// JSF creates a legacy numeric meta-query row from the Range filter. Replace
// ONLY that numeric clause on Query Builder #5 with quote-indexed post IDs.
// The rest of the original query (WPML taxonomy intersection, public status,
// extra_trip exclusion, date and any other native filters) still applies.
add_filter('jet-engine/query-builder/types/posts-query/args', static function ($args,$query) {
    $contract = shared_presentation_catalog_contract_v1();
    if (!is_array($args) || !Presentation_Policy_Context_v1::catalogue_filters_apply($query,$contract) ||
        !shared_presentation_catalog_price_scope_v1() ||
        !is_array($args['meta_query']??null)) return $args;
    // Native JSF attaches the provider marker for AJAX. On a direct
    // permalink GET JetEngine Query Builder #5 has no provider marker;
    // authorization to project is instead the strictly parsed archive URI.
    if (wp_doing_ajax() && ($args['jet_smart_filters']??'')!==$contract['provider']) return $args;
    $range = null;
    foreach ($args['meta_query'] as $key=>$row) {
        if (!is_array($row) || ($row['key']??'')!=='start_price') continue;
        // One native Range facet only; an unexpected shape must not bypass filtering.
        $range = $row;
        unset($args['meta_query'][$key]);
        break;
    }
    if ($range === null && !wp_doing_ajax()) {
        $context=shared_presentation_catalog_url_context_v1(
            (string)($_SERVER['REQUEST_URI']??'')
        );
        if (is_array($context) && is_array($context['price_range']??null)) {
            $price=$context['price_range'];
            $range=['compare'=>'BETWEEN','value'=>[$price['min'],$price['max']]];
        }
    }
    if ($range === null) return $args;
    $values = $range['value']??null;
    if (!is_array($values) || count($values)!==2 || ($range['compare']??'')!=='BETWEEN') {
        $args['post__in']=[0];return $args;
    }
    foreach ($values as $value) {
        if (!is_scalar($value) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',(string)$value)) {
            $args['post__in']=[0];return $args;
        }
    }
    $low=(float)$values[0];$high=(float)$values[1];
    if ($low>$high) { $args['post__in']=[0];return $args; }
    $index=shared_presentation_catalog_price_facet_index_v1();
    if (!$index['valid']) { $args['post__in']=[0];return $args; }
    $matching=[];
    foreach ($index['quotes'] as $id=>$amount) {
        if ($amount + 0.005 >= $low && $amount - 0.005 <= $high) $matching[]=(int)$id;
    }
    // Intersect with any prior native post restriction; never widen it.
    $existing=$args['post__in']??null;
    if (is_array($existing) && $existing) $matching=array_values(array_intersect($matching,array_map('intval',$existing)));
    $args['post__in']=$matching ?: [0];
    return $args;
}, 2020, 2);
