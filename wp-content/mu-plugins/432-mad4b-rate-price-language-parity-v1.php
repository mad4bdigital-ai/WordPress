<?php
/**
 * Plugin Name: MAD4B Tour Rate WPML Same-Currency Price Parity
 * Description: Last-writer price parity within WPML translation siblings of the same tour rate AND currency; preserves cross-currency market pricing.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Tour_Rate_Price_Parity_V1', false)) {
final class MAD4B_Tour_Rate_Price_Parity_V1 {
    const TYPE = 'tour-rates';
    const TOUR_TYPE = 'tours-and-activities';
    const CRON_HOOK = 'mad4b_rate_price_parity_integrity_daily_v1';
    const SYNC_META = [
        'single_price', 'double_price', 'triple_price',
        'single_sale_price', 'double_sale_price', 'triple_sale_price',
        'egyptians_single_price', 'egyptians_double_price', 'egyptians_triple_price',
        'egyptians_single_sale_price', 'egyptians_double_sale_price', 'egyptians_triple_sale_price',
        'has_egyptians_price', 'is_on_sale'
    ];
    private static bool $syncing = false;
    private static array $affectedTours = [];

    private static function currency(int $id): string {
        $currency = strtoupper(trim((string)get_post_meta($id, 'base_currency', true)));
        return preg_match('/^[A-Z]{3}$/D', $currency) ? $currency : '';
    }

    /** Verify WPML group and parent Tour identity; never join unrelated rates. */
    private static function trid(int $id, string $type): int {
        global $wpdb;
        static $cache = [];
        $k = $type . ':' . $id;
        if (array_key_exists($k, $cache)) return $cache[$k];
        if ($id < 1 || !get_post_type($id)) return $cache[$k] = 0;
        $table = $wpdb->prefix . 'icl_translations';
        $trid = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$table} WHERE element_id=%d AND element_type=%s LIMIT 1",
            $id, 'post_' . $type
        ));
        return $cache[$k] = absint($trid);
    }

    private static function parent_trid(int $id): int {
        $parent = absint(get_post_meta($id, 'related_tour_id', true));
        if (!$parent || get_post_type($parent) !== self::TOUR_TYPE) return 0;
        return self::trid($parent, self::TOUR_TYPE);
    }

    public static function members(int $id): array {
        if ($id < 1 || get_post_type($id) !== self::TYPE || get_post_status($id) !== 'publish') return [];
        $trid = self::trid($id, self::TYPE);
        $currency = self::currency($id);
        $parentTrid = self::parent_trid($id);
        if (!$trid || !$currency || !$parentTrid) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'icl_translations';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT element_id FROM {$table} WHERE trid=%d AND element_type=%s LIMIT 100",
            $trid, 'post_' . self::TYPE
        ));
        if (!is_array($ids) || count($ids) > 50) return [];
        $members = [];
        foreach ($ids as $candidate) {
            $candidate = absint($candidate);
            if (!$candidate || get_post_type($candidate) !== self::TYPE ||
                get_post_status($candidate) !== 'publish' ||
                self::currency($candidate) !== $currency ||
                self::parent_trid($candidate) !== $parentTrid) continue;
            $members[] = $candidate;
        }
        sort($members, SORT_NUMERIC);
        return array_values(array_unique($members));
    }

    /** Reject invalid numeric / boolean values rather than duplicating corrupted rates. */
    private static function field_valid(string $key, $value): bool {
        if (!is_scalar($value)) return false;
        $value = (string)$value;
        if (in_array($key, ['is_on_sale', 'has_egyptians_price'], true))
            return in_array(strtolower($value), ['', '0', '1', 'yes', 'no', 'true', 'false'], true);
        if ($value === '') return true;
        return is_numeric($value) && is_finite((float)$value) &&
            (float)$value >= 0 && (float)$value <= 1000000000;
    }

    private static function copy_field(int $from, int $to, string $key): bool {
        $exists = metadata_exists('post', $from, $key);
        $value = $exists ? get_post_meta($from, $key, true) : '';
        if ($exists && !self::field_valid($key, $value)) return false;
        $toExists = metadata_exists('post', $to, $key);
        $current = $toExists ? get_post_meta($to, $key, true) : '';
        if ((string)$current === (string)$value) return false;
        if (!$exists) delete_post_meta($to, $key);
        else update_post_meta($to, $key, $value);
        $afterExists = metadata_exists('post', $to, $key);
        $after = $afterExists ? get_post_meta($to, $key, true) : '';
        return (string)$after === (string)$value &&
            (!$exists ? !$afterExists : $afterExists);
    }

    private static function remember_tours(array $ids): void {
        foreach ($ids as $id) {
            $tour = absint(get_post_meta($id, 'related_tour_id', true));
            if ($tour && get_post_type($tour) === self::TOUR_TYPE)
                self::$affectedTours[$tour] = true;
        }
    }

    public static function meta_changed($metaId, $postId, $key, $value = null): void {
        $postId = absint($postId);
        $key = (string)$key;
        if (self::$syncing || !in_array($key, self::SYNC_META, true) ||
            !$postId || get_post_type($postId) !== self::TYPE ||
            get_post_status($postId) !== 'publish') return;
        $members = self::members($postId);
        if (count($members) < 2) return;
        self::$syncing = true;
        try {
            foreach ($members as $peer) {
                if ($peer === $postId) continue;
                if (!self::copy_field($postId, $peer, $key))
                    error_log('MAD4B_RATE_PARITY_WRITE_UNCONFIRMED field='.sanitize_key($key));
            }
            self::remember_tours($members);
        } finally {
            self::$syncing = false;
        }
    }

    /** One quiet integrity pass per day; no public-page scanning or repeated Chromium. */
    public static function reconcile(bool $apply = false): array {
        global $wpdb;
        $table = $wpdb->prefix . 'icl_translations';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.trid, p.ID AS id, p.post_modified_gmt
             FROM {$wpdb->posts} p
             JOIN {$table} t ON t.element_id=p.ID AND t.element_type=%s
             WHERE p.post_type=%s AND p.post_status='publish'
             ORDER BY t.trid, p.ID LIMIT 1001",
            'post_' . self::TYPE, self::TYPE
        ), ARRAY_A);
        $result = ['groups_checked'=>0, 'drift_count'=>0,
            'fields_changed'=>0, 'writes_unconfirmed'=>0,
            'authorization_missing'=>false,
            'conflicting_latest_timestamps'=>0, 'rate_limit_exceeded'=>false];
        if ($apply && !current_user_can('commerce_manage_pricing') &&
            !current_user_can('manage_options')) {
            $result['authorization_missing'] = true;
            $apply = false;
        }
        if (!is_array($rows) || count($rows) > 1000) {
            $result['rate_limit_exceeded'] = true; return $result;
        }
        $grouped = [];
        foreach ($rows as $row) {
            $id = absint($row['id']);
            $currency = self::currency($id);
            $parentTrid = self::parent_trid($id);
            if (!$currency || !$parentTrid) continue;
            $grouped[(int)$row['trid'] . '|' . $currency . '|' . $parentTrid][] =
                ['id'=>$id, 'modified'=>(string)$row['post_modified_gmt']];
        }
        self::$syncing = true;
        try {
            foreach ($grouped as $group) {
                if (count($group) < 2) continue;
                $result['groups_checked']++;
                $max = max(array_column($group, 'modified'));
                $winners = array_values(array_filter($group,
                    static fn($r)=>$r['modified']===$max));
                $peers = array_column($group, 'id');
                $groupChanged = false;
                foreach (self::SYNC_META as $field) {
                    $winningValues = [];
                    foreach ($winners as $candidate) {
                        $value = (string)get_post_meta($candidate['id'], $field, true);
                        $winningValues[$value] = $candidate['id'];
                    }
                    if (count($winningValues) > 1) {
                        $result['conflicting_latest_timestamps']++;
                        continue;
                    }
                    $source = $winners[0]['id'];
                    if (!self::field_valid($field, get_post_meta($source, $field, true))) continue;
                    foreach ($peers as $peer) {
                        if ($peer === $source) continue;
                        $from = (string)get_post_meta($source, $field, true);
                        $to = (string)get_post_meta($peer, $field, true);
                        if ($from === $to) continue;
                        $result['drift_count']++;
                        if ($apply) {
                            if (self::copy_field($source, $peer, $field)) {
                                $result['fields_changed']++;
                                $groupChanged = true;
                            } else $result['writes_unconfirmed']++;
                        }
                    }
                }
                if ($apply && $groupChanged) self::remember_tours($peers);
            }
        } finally {
            self::$syncing = false;
        }
        return $result;
    }

    public static function daily(): void {
        $summary = self::reconcile(true);
        update_option('mad4b_rate_price_parity_integrity_v1',
            ['at_utc'=>gmdate('c'),'result'=>$summary], false);
        if ($summary['conflicting_latest_timestamps'] || $summary['rate_limit_exceeded'] ||
            $summary['writes_unconfirmed'])
            error_log('MAD4B_RATE_PARITY_INTEGRITY_WARNING ' . wp_json_encode($summary));
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 6 * HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function invalidate_changed_tours(): void {
        if (!self::$affectedTours) return;
        $tours = array_keys(self::$affectedTours);
        self::$affectedTours = [];
        foreach ($tours as $tourId) do_action('litespeed_purge_post', $tourId);
        if (class_exists('MAD4B_Tour_Rate_Cache_Lifecycle_V1', false))
            MAD4B_Tour_Rate_Cache_Lifecycle_V1::signal('rate-price-parity');
    }

    public static function boot(): void {
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [__CLASS__, 'meta_changed'], 35, 4);
        }
        add_action('init', [__CLASS__, 'schedule'], 90);
        add_action(self::CRON_HOOK, [__CLASS__, 'daily']);
        add_action('shutdown', [__CLASS__, 'invalidate_changed_tours'], 100);
    }
}
MAD4B_Tour_Rate_Price_Parity_V1::boot();
}
