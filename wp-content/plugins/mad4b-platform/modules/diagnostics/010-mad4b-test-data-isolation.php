<?php
/**
 * Plugin Name: MAD4B Data Classification Visibility Guard
 * Description: Generic public-query visibility policy for records explicitly marked as test-only.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Data_Classification_Isolation {
    private static $ids = null;

    public static function is_test_only($post_id) {
        $id = absint($post_id);
        if (!$id) return false;
        $flag = strtolower(trim((string) get_post_meta($id, '_mad4b_is_test_data', true)));
        if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) return true;
        $classification = strtolower(trim((string) get_post_meta($id, '_mad4b_data_classification', true)));
        return in_array($classification, ['test_only', 'sandbox_only'], true);
    }

    private static function ids() {
        if (self::$ids !== null) return self::$ids;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} " .
            "WHERE meta_key IN ('_mad4b_is_test_data','_mad4b_data_classification')",
            ARRAY_A
        );
        $ids = [];
        foreach ((array) $rows as $row) {
            $value = strtolower(trim((string) $row['meta_value']));
            if (
                ($row['meta_key'] === '_mad4b_is_test_data' && in_array($value, ['1','true','yes','on'], true)) ||
                ($row['meta_key'] === '_mad4b_data_classification' && in_array($value, ['test_only','sandbox_only'], true))
            ) $ids[(int)$row['post_id']] = true;
        }
        self::$ids = array_keys($ids);
        return self::$ids;
    }

    public static function restrict_public_queries($query) {
        if (!($query instanceof WP_Query)) return;
        if (is_admin() && !wp_doing_ajax()) return;
        $ids = self::ids();
        if (!$ids) return;
        // WP_Query ignores post__not_in whenever post__in is present.
        // Intersect explicitly, and fail closed with ID 0 for an all-test selection.
        $included = array_filter(array_map('absint', (array) $query->get('post__in')));
        if ($included) {
            $allowed = array_values(array_diff($included, $ids));
            $query->set('post__in', $allowed ?: [0]);
        }
        $current = array_map('absint', (array) $query->get('post__not_in'));
        $query->set('post__not_in', array_values(array_unique(array_merge($current, $ids))));
    }
    public static function boot() {
        add_action('pre_get_posts', [__CLASS__, 'restrict_public_queries'], 9999);
    }
}
MAD4B_Data_Classification_Isolation::boot();
