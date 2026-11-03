<?php
namespace DynamicFilterSEOBridge\WPML;

/**
 * Read-only, source-backed filter language switching. WPML continues to own the
 * archive URLs; JSF continues to own selected filters and their AND/IN state.
 */
final class ActiveFacetLanguageSwitcher {
    public function enqueue(): void {
        if (is_admin() || !function_exists('jet_smart_filters') ||
            !function_exists('shared_presentation_catalog_url_context_v1') ||
            !has_filter('wpml_current_language')) { return; }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (!$uri || !shared_presentation_catalog_url_context_v1($uri)) { return; }
        $language = sanitize_key((string) apply_filters('wpml_current_language', null));
        if (!$language) { return; }
        $contract = function_exists('shared_presentation_catalog_contract_v1')
            ? shared_presentation_catalog_contract_v1() : [];
        $taxonomies = array_values(array_filter(array_unique(array_map('sanitize_key',
            (array)($contract['taxonomies'] ?? []))), 'taxonomy_exists'));
        if (!$taxonomies || count($taxonomies)>12) { return; }
        $mapping = self::termMap($language, $taxonomies);
        if (!$mapping) { return; }
        $path = plugin_dir_path(DFSB_MAIN_FILE).'assets/js/dfsb-wpml-filter-switcher.js';
        if (!is_file($path)) { return; }
        $version = in_array(wp_get_environment_type(), ['local','development','staging'], true)
            ? (string)filemtime($path) : DFSB_ASSET_VERSION;
        wp_enqueue_script('dfsb-wpml-filter-switcher',
            plugins_url('assets/js/dfsb-wpml-filter-switcher.js', DFSB_MAIN_FILE),
            ['etg-dfsb-ajax-filter-state'], $version, true);
        wp_add_inline_script('dfsb-wpml-filter-switcher',
            'window.DFSB_WPML_FILTER_SWITCHER='.wp_json_encode([
                'contract'=>'dfsb.wpml-native-language-switch.v1',
                'sourceLanguage'=>$language,
                'provider'=>(string)($contract['provider'] ?? 'jet-engine/tours_query_archive'),
                'map'=>$mapping,
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';', 'before');
    }

    /**
     * Exact WPML term_taxonomy_id -> trid -> locale slug mapping. Never use
     * get_term in another locale without a WPML context switch: WPML can
     * silently replace its data with the current language's terms.
     */
    public static function termMap(string $language, array $taxonomies): array {
        global $wpdb;
        $language = sanitize_key($language);
        $taxonomies = array_values(array_unique(array_filter(array_map('sanitize_key', $taxonomies),
            static fn($tax): bool => taxonomy_exists($tax))));
        if (!$language || !$taxonomies || count($taxonomies)>12) { return []; }
        $elements = array_map(static fn($tax): string => 'tax_'.$tax, $taxonomies);
        $placeholder = implode(',',array_fill(0,count($elements),'%s'));
        $sql = $wpdb->prepare(
            "SELECT x.trid,x.language_code,tt.taxonomy,t.slug
             FROM {$wpdb->prefix}icl_translations x
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=x.element_id
             INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
             WHERE x.element_type IN ($placeholder) AND x.language_code<>''
             ORDER BY tt.taxonomy,x.trid,x.language_code", ...$elements);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || count($rows)>6500) { return []; }
        $grouped=[];
        foreach ($rows as $r) {
            $tax=(string)$r['taxonomy'];$trid=(int)$r['trid'];
            $lang=sanitize_key((string)$r['language_code']);
            $slug=(string)$r['slug'];
            if (!$tax||!$trid||!$lang||!preg_match('/^[a-z0-9_-]{1,80}$/D',$slug))continue;
            $grouped[$tax][$trid][$lang]=$slug;
        }
        $map=[];
        foreach ($grouped as $tax=>$groups) {
            foreach ($groups as $translations) {
                if (empty($translations[$language])) { continue; }
                $slug=$translations[$language];
                if (isset($map[$tax][$slug])) { $map[$tax][$slug]=[]; continue; }
                $map[$tax][$slug]=$translations;
            }
        }
        return $map;
    }
}
