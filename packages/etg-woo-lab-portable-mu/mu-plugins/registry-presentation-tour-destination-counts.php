<?php
/** Local Lab: correct empty Tour card destination counts from linked term metadata. */
if (!defined('ABSPATH')) return;
add_filter('elementor/widget/render_content', static function ($content, $widget) {
    if (!is_string($content) || !str_contains($content, 'jet-engine-query-count') ||
        !is_object($widget) || !method_exists($widget, 'get_name') ||
        $widget->get_name() !== 'jet-listing-dynamic-field') return $content;
    $is_country = str_contains($content, 'query-93');
    $is_city = str_contains($content, 'query-182');
    if (!$is_country && !$is_city) return $content;
    $post_id = (int)get_the_ID();
    if (!$post_id || get_post_type($post_id) !== 'tours-and-activities') return $content;
    global $wpdb;
    static $counts = [];
    if (!isset($counts[$post_id])) {
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=%d AND tt.taxonomy='destinations'",
            $post_id
        ));
        $counts[$post_id] = ['country'=>0,'locality'=>0];
        foreach ((array)$ids as $term_id) {
            $type=(string)get_term_meta((int)$term_id,'destination_type',true);
            if ($type==='country') $counts[$post_id]['country']++;
            if (in_array($type,['state_governorate','city','zone'],true)) $counts[$post_id]['locality']++;
        }
    }
    $query_id = $is_country ? 93 : 182;
    $value = $counts[$post_id][$is_country?'country':'locality'];
    $pattern = '~(<span\\b[^>]*\\bquery-'. $query_id .'\\b[^>]*>)(0)(</span>)~';
    if (!preg_match($pattern,$content)) return $content; // Only repair a zero, never override a valid native count.
    if ($value===0) return ''; // A card should not show "0 country" or "0 city" badges.
    return preg_replace($pattern,'${1}'.(int)$value.'${3}',$content,1);
}, 111, 2);
