<?php
/**
 * Plugin Name: MAD4B Destination Demand Order — Shared Presentation Policy
 * Description: Company-local, WPML-aware ranking of already eligible city/governorate terms in native JetEngine and Elementor navigation.
 * Version: 1.2.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Destination_Demand_Rank_V1 {
    public const CONTRACT = 'mad4b.destination-demand-order.v1';
    private const MAX_TERMS = 150;
    // Bounded fallback: a site beyond this catalog size keeps editorial order
    // rather than performing an unbounded scan during a public request.
    private const MAX_VISIBLE_TOURS = 3000;
    private const ALLOWED_TYPES = ['city', 'state_governorate'];
    private static array $count_memo = [];
    private static array $visible_index_memo = [];

    public static function language(string $language = ''): string {
        $language = $language ?: (string)apply_filters('wpml_current_language', 'en');
        return preg_match('/^[a-z]{2}(?:-[A-Za-z]{2})?$/D', $language) ? $language : '';
    }

    /**
     * WP_Query is the canonical visibility owner (as in JetEngine Query 273).
     * It applies the site's WPML untranslated-tour fallback, publication,
     * extra-trip exclusion and installed visibility rules. A raw SQL count
     * joined only to the selected locale silently undercounts what visitors
     * can actually browse. This builds one lightweight, request-scoped index,
     * NOT one post query per destination and NOT a frontend preload.
     */
    private static function visible_term_index(string $language): ?array {
        global $wpdb;
        if (array_key_exists($language,self::$visible_index_memo))
            return self::$visible_index_memo[$language];
        if (!($wpdb instanceof wpdb)) return null;
        $previous = (string)apply_filters('wpml_current_language','');
        $switch = defined('ICL_SITEPRESS_VERSION') && $previous !== $language;
        if ($switch) do_action('wpml_switch_language',$language);
        try {
            $query = new WP_Query([
                'post_type'=>'tours-and-activities', 'post_status'=>'publish',
                'posts_per_page'=>self::MAX_VISIBLE_TOURS+1,
                'fields'=>'ids', 'no_found_rows'=>true,
                'suppress_filters'=>false, 'ignore_sticky_posts'=>true,
                'update_post_meta_cache'=>false, 'update_post_term_cache'=>false,
            ]);
            $posts = array_values(array_unique(array_filter(array_map('absint',(array)$query->posts))));
            if (count($posts)>self::MAX_VISIBLE_TOURS) return null;
            if (!$posts) return self::$visible_index_memo[$language]=[];
            $placeholders=implode(',',array_fill(0,count($posts),'%d'));
            $sql="SELECT tr.object_id AS post_id, tt.term_id AS term_id
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                WHERE tt.taxonomy='destinations' AND tr.object_id IN ($placeholders)";
            $rows=$wpdb->get_results($wpdb->prepare($sql,...$posts),ARRAY_A);
            if (!is_array($rows)||$wpdb->last_error) return null;
            $index=[];
            foreach ($rows as $row) {
                $term=(int)($row['term_id']??0);
                $post=(int)($row['post_id']??0);
                if ($term>0 && $post>0)$index[$term][$post]=true;
            }
            return self::$visible_index_memo[$language]=$index;
        } finally {
            // Never leak a temporary WPML locale into the caller's query.
            if ($switch) do_action('wpml_switch_language',$previous?:null);
        }
    }

    /** Count unique visible tour posts assigned to either localized term or
     * its original WPML term. Both are accepted by native WPML Terms Queries. */
    public static function counts(array $term_ids, string $language = ''): ?array {
        $language=self::language($language);
        if ($language==='') return null;
        $ids=array_values(array_unique(array_filter(array_map('absint',$term_ids))));
        if (!$ids||count($ids)>self::MAX_TERMS) return null;
        sort($ids,SORT_NUMERIC);
        $key=$language.'|'.implode(',',$ids);
        if (array_key_exists($key,self::$count_memo))return self::$count_memo[$key];
        $index=self::visible_term_index($language);
        if ($index===null) return null;
        $default=(string)apply_filters('wpml_default_language','en');
        $out=[];
        foreach ($ids as $term) {
            $original=defined('ICL_SITEPRESS_VERSION')
                ? (int)apply_filters('wpml_object_id',$term,'destinations',false,$default)
                : $term;
            $visible=$index[$term]??[];
            if ($original>0 && $original!==$term)
                $visible+=($index[$original]??[]);
            $out[$term]=count($visible);
        }
        return self::$count_memo[$key]=$out;
    }

    /** Strict sort: keep exactly the same entries and all original tie order. */
    public static function rank_terms(array $items, string $language = ''): array {
        if (count($items) < 2 || count($items) > self::MAX_TERMS) return $items;
        $ids = [];
        foreach ($items as $item) {
            if (!($item instanceof WP_Term) || $item->taxonomy !== 'destinations') return $items;
            $ids[] = (int)$item->term_id;
        }
        $counts = self::counts($ids, $language);
        if ($counts === null) return $items;
        $rows = [];
        foreach (array_values($items) as $i => $term) {
            $rows[] = ['term'=>$term, 'index'=>$i, 'n'=>$counts[(int)$term->term_id] ?? 0];
        }
        usort($rows, static fn($a,$b) => ($b['n'] <=> $a['n']) ?: ($a['index'] <=> $b['index']));
        return array_map(static fn($r) => $r['term'], $rows);
    }

    public static function rank_ids(array $ids, string $language = ''): array {
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (count($ids) < 2 || count($ids) > self::MAX_TERMS) return $ids;
        $terms = [];
        foreach ($ids as $id) {
            $term = WP_Term::get_instance($id);
            if (!($term instanceof WP_Term) || $term->taxonomy !== 'destinations') return $ids;
            $terms[] = $term;
        }
        return array_map(static fn($t) => (int)$t->term_id, self::rank_terms($terms,$language));
    }

    /** Only a flat Terms Query of cities/governorates. Never post queries,
     * parent/child structures, attractions, or editorial manual sort keys. */
    public static function query_qualifies($query): bool {
        if (!is_object($query) || (string)($query->query_type ?? '') !== 'terms') return false;
        $q = (array)($query->query ?? []);
        $tax = (array)($q['taxonomy'] ?? []);
        if (count($tax) !== 1 || (string)reset($tax) !== 'destinations') return false;
        $sort = strtolower((string)($q['orderby'] ?? ''));
        if ($sort !== '' && !in_array($sort,['name','term_id'],true)) return false;
        $kind = '';
        foreach ((array)($q['meta_query'] ?? []) as $clause) {
            if (!is_array($clause) || (string)($clause['key']??'') !== 'destination_type') continue;
            if ((string)($clause['compare']??'=') !== '=') return false;
            $value = (string)($clause['value']??'');
            if (!in_array($value, self::ALLOWED_TYPES, true)) return false;
            if ($kind && $kind !== $value) return false;
            $kind = $value;
        }
        // Existing and future Query Builder IDs use this *data contract*,
        // not a hard-coded company/site or a fixed Elementor template ID.
        return $kind !== '';
    }

    public static function sort_query_items($items, $query) {
        if (!is_array($items) || count($items)<2 || !self::query_qualifies($query)) return $items;
        $enabled = apply_filters('mad4b/destination_demand_rank/enable', true,
            (int)($query->id??0), 'jet-query-builder');
        return $enabled ? self::rank_terms($items) : $items;
    }

    /** Legacy native Elementor Terms Listing: eligibility is owned by
     * navigation-editor-grid (earlier filter @40), not this rank service.
     * Sort BEFORE WP_Term_Query paginates to the 6/12 visible cards. */
    public static function sort_nav_query_args($args, $grid, $settings) {
        if (!is_array($args) || !is_array($settings)) return $args;
        if ((int)($settings['lisitng_id']??$settings['listing_id']??0)!==67125
          || !str_contains((string)($settings['_css_classes']??''),'navigation-editor-grid')) return $args;
        $ids = array_values(array_filter(array_map('absint', (array)($args['include']??[]))));
        if (count($ids)<2 || count($ids)>self::MAX_TERMS) return $args;
        $language = function_exists('native_destination_language') ? native_destination_language() : self::language();
        $ordered=self::rank_ids($ids, $language);
        if (count($ordered)!==count($ids) || array_diff($ordered,$ids) || array_diff($ids,$ordered))return $args;
        $args['include']=$ordered;
        $args['orderby']='include';
        $args['order']='ASC';
        return $args;
    }

    /** Native nested tabs also use the same already-eligible IDs; no new
     * countries, descendants or authorizations can be added by ranking. */
    /** Public HTML is affected when a tour enters or leaves publication,
     * including transitions which do NOT call wp_set_object_terms. This is
     * deliberately event-driven and reuses the existing safe HTML-only purge
     * broker; it never flushes Redis, WooCommerce or private page state. */
    public static function publication_changed($new_status, $old_status, $post): void {
        if (!($post instanceof WP_Post) || $post->post_type!=='tours-and-activities') return;
        if (($new_status==='publish')===($old_status==='publish')) return;
        do_action('mad4b/public_html/safe_purge_all','destination_demand_rank_publication_changed');
    }

    public static function sort_eligible_child_ids($ids, $parent_id, $language) {
        if (!is_array($ids)) return $ids;
        $enabled = apply_filters('mad4b/destination_demand_rank/enable', true, 0, 'native-nested-tabs');
        return $enabled ? self::rank_ids($ids, (string)$language) : $ids;
    }
}
add_action('transition_post_status',[MAD4B_Destination_Demand_Rank_V1::class,'publication_changed'],25,3);
add_filter('jet-engine/query-builder/query/items',
    [MAD4B_Destination_Demand_Rank_V1::class,'sort_query_items'],2700,2);
add_filter('jet-engine/listing/grid/terms-query-args',
    [MAD4B_Destination_Demand_Rank_V1::class,'sort_nav_query_args'],90,3);
add_filter('mad4b/destination_demand_rank/eligible_child_ids',
    [MAD4B_Destination_Demand_Rank_V1::class,'sort_eligible_child_ids'],10,3);
// The Platform ordered map is an integrity/inventory contract; it does not
// automatically include newly registered modules in all company runtimes.
// Register the optional bridge from this already loaded MU owner so Module
// Registry can prove it is actually Loaded. Plugin-less companies skip it.
$mad4b_demand_bridge=WP_PLUGIN_DIR.'/mad4b-platform/modules/presentation/mad4b-destination-demand-rank-v1.php';
if (is_file($mad4b_demand_bridge)) require_once $mad4b_demand_bridge;
