<?php
/**
 * MAD4B primary Tour query contract: a Listing Grid may temporarily replace
 * global $wp_query with a Repeater_Query and its non-post queried object.
 * Resolve the original *main* WP_Query only, never the mutable item query.
 * Side-effect-free, no fabricated post_type and no mutation of JetEngine data.
 */
defined('ABSPATH') || exit;
if (!function_exists('shared_presentation_primary_tour_post_v1')) {
    function shared_presentation_primary_tour_post_v1(): ?\WP_Post {
        if (wp_get_environment_type() !== 'local') return null;
        $q = $GLOBALS['wp_the_query'] ?? null;
        if (!($q instanceof \WP_Query) || !$q->is_singular) return null;
        $obj = $q->queried_object ?? null;
        if (!($obj instanceof \WP_Post)) {
            // JetEngine may replace the main query's queried_object with a
            // repeater row; retain only a verifiably original post identity.
            $id = (int)($q->queried_object_id ?? 0);
            $mainPost = $q->post ?? null;
            if ($mainPost instanceof \WP_Post && $id > 0 && $mainPost->ID === $id) {
                $obj = $mainPost;
            } elseif ($id > 0) {
                $obj = get_post($id);
            } else {
                return null;
            }
        }
        return $obj instanceof \WP_Post && $obj->post_type === 'tours-and-activities' ? $obj : null;
    }
}
