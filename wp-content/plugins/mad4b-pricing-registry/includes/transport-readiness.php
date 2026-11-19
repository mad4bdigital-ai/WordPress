<?php
/**
 * Transportation classification and pricing source preflight.
 *
 * Expand-only adapter: legacy term IDs, JetEngine fields and CCT schema remain
 * authoritative. No quote, booking or published price is modified here.
 */
defined('ABSPATH') || exit;

final class MAD4B_Transport_Pricing_Readiness_V1 {
    public const CONTRACT = 'mad4b.transport-pricing-readiness.v1';
    private const MODES = array(
        568 => 'road',
        578 => 'rail',
        581 => 'water',
        594 => 'air',
        595 => 'adventure',
    );
    private const SERVICE_MODES = array(
        'fixed_route_schedule'   => array('route_pattern' => 'fixed_route', 'schedule_model' => 'fixed_schedule'),
        'fixed_route_on_demand'  => array('route_pattern' => 'fixed_route', 'schedule_model' => 'on_demand'),
        'on_demand_city'         => array('route_pattern' => 'open_area', 'schedule_model' => 'on_demand'),
        'point_to_point'         => array('route_pattern' => 'point_to_point'),
        'package_bundle'        => array('composition_model' => 'bundle'),
        'seat_based_inventory'  => array('inventory_model' => 'seat'),
        'vehicle_based_inventory'=> array('inventory_model' => 'vehicle'),
    );
    private const PRICING_UNITS = array('per_day', 'per_trip', 'per_person', 'per_cabin', 'per_vehicle');
    private const MAX_SCAN = 500;

    public static function decompose($legacy): array {
        $values = is_array($legacy) ? $legacy : (is_string($legacy) && $legacy !== '' ? array($legacy) : array());
        $out = array('route_pattern' => array(), 'schedule_model' => array(),
            'inventory_model' => array(), 'composition_model' => array(), 'unknown_values' => array());
        foreach ($values as $value) {
            $value = is_scalar($value) ? (string) $value : '';
            if (!isset(self::SERVICE_MODES[$value])) {
                if ($value !== '') $out['unknown_values'][] = $value;
                continue;
            }
            foreach (self::SERVICE_MODES[$value] as $dimension => $normalized) {
                $out[$dimension][] = $normalized;
            }
        }
        foreach ($out as $dimension => $entries) $out[$dimension] = array_values(array_unique($entries));
        return $out;
    }

    public static function assess_rate(array $row, array $parent, ?int $now = null): array {
        $issues = array();
        $rateId = (int) ($row['_ID'] ?? 0);
        $parentId = (int) ($row['related_transport_id'] ?? 0);
        $parentMode = (int) ($parent['mode_id'] ?? 0);
        $parentType = (int) ($parent['type_id'] ?? 0);
        $rateMode = (int) ($row['trans_mode_term_id'] ?? 0);
        $rateType = (int) ($row['trans_type_term_id'] ?? 0);
        if ($parentId <= 0 || !$parent['exists'] || $parentId !== (int) ($parent['id'] ?? 0)) $issues[] = 'transport_reference_missing';
        if ($parentMode <= 0 || $parentType <= 0) $issues[] = 'transport_classification_missing';
        if ($rateMode <= 0 || $rateType <= 0) $issues[] = 'rate_classification_missing';
        if ($parentMode && $rateMode && $parentMode !== $rateMode) $issues[] = 'mode_mismatch';
        if ($parentType && $rateType && $parentType !== $rateType) $issues[] = 'type_mismatch';
        $currency = strtoupper(trim((string) ($row['base_currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) $issues[] = 'currency_missing_or_invalid';
        $unit = trim((string) ($row['pricing_type'] ?? ''));
        if (!in_array($unit, self::PRICING_UNITS, true)) $issues[] = 'pricing_unit_unsupported';
        $from = (int) ($row['trans_start_date'] ?? 0);
        $until = (int) ($row['trans_end_date'] ?? 0);
        if ($from <= 0 || $until <= 0 || $until < $from) $issues[] = 'rate_dates_invalid';
        elseif (($now ?? time()) > $until) $issues[] = 'rate_expired';
        foreach (array('base_price', 'sale_price') as $priceKey) {
            $v = $row[$priceKey] ?? null;
            if ($v === null || $v === '' || !is_numeric($v) || (float) $v < 0) $issues[] = $priceKey . '_invalid';
        }
        return array('rate_id' => $rateId, 'transport_id' => $parentId,
            'status' => $issues ? 'review_required' : 'structurally_valid',
            'issues' => array_values(array_unique($issues)), 'eligible_for_checkout' => false);
    }

    public static function assess_transport(array $item): array {
        $modeId = (int) ($item['mode_id'] ?? 0);
        $typeId = (int) ($item['type_id'] ?? 0);
        $typeParentId = (int) ($item['type_parent_id'] ?? 0);
        $issues = array();
        if (!isset(self::MODES[$modeId])) $issues[] = 'transport_mode_unknown';
        if ($typeId <= 0) $issues[] = 'transport_type_missing';
        if ($typeId > 0 && $typeParentId !== $modeId) $issues[] = 'type_parent_mismatch';
        $normalized = self::decompose($item['service_mode'] ?? array());
        if (!$normalized['route_pattern'] && !$normalized['composition_model']) $issues[] = 'service_route_unclassified';
        if ($normalized['unknown_values']) $issues[] = 'unknown_legacy_service_mode';
        return array('transport_id' => (int) ($item['id'] ?? 0),
            'mode' => self::MODES[$modeId] ?? 'unknown',
            'type_id' => $typeId, 'dimensions' => $normalized, 'issues' => $issues,
            'status' => $issues ? 'review_required' : 'structurally_valid');
    }

    public static function scan(): array {
        global $wpdb;
        $result = array('contract' => self::CONTRACT, 'site' => home_url('/'),
            'transport_count' => 0, 'rates_count' => 0, 'rates_table_present' => false,
            'transport_issues' => array(), 'rate_issues' => array(),
            'transport_issue_counts' => array(), 'rate_issue_counts' => array(),
            'legacy_transport_modes' => self::MODES, 'mutation_performed' => false,
            'checkout_authorized' => false);
        $posts = get_posts(array('post_type' => 'transportations', 'post_status' => array('publish', 'draft'),
            'posts_per_page' => self::MAX_SCAN, 'fields' => 'ids', 'suppress_filters' => true));
        $result['transport_count'] = count($posts);
        foreach ($posts as $postId) {
            $modeId = (int) get_post_meta($postId, 'trans_mode', true);
            $typeId = (int) get_post_meta($postId, 'trans_type', true);
            $term = $typeId ? get_term($typeId, 'transportation-types') : null;
            $info = self::assess_transport(array('id' => (int) $postId, 'mode_id' => $modeId,
                'type_id' => $typeId,
                'type_parent_id' => ($term && !is_wp_error($term)) ? (int) $term->parent : 0,
                'service_mode' => get_post_meta($postId, 'service_mode', true)));
            if ($info['issues']) {
                $result['transport_issues'][] = array('id' => (int) $postId,
                    'status' => get_post_status($postId), 'issues' => $info['issues']);
                foreach ($info['issues'] as $issue) $result['transport_issue_counts'][$issue] =
                    ($result['transport_issue_counts'][$issue] ?? 0) + 1;
            }
        }
        $table = $wpdb->prefix . 'jet_cct_transport_rates';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) return $result;
        $result['rates_table_present'] = true;
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY _ID ASC LIMIT " . self::MAX_SCAN, ARRAY_A);
        $result['rates_count'] = count((array) $rows);
        foreach ((array) $rows as $row) {
            $transportId = (int) ($row['related_transport_id'] ?? 0);
            $post = $transportId ? get_post($transportId) : null;
            $parent = array('id' => $transportId,
                'exists' => $post && $post->post_type === 'transportations',
                'mode_id' => $post ? (int) get_post_meta($transportId, 'trans_mode', true) : 0,
                'type_id' => $post ? (int) get_post_meta($transportId, 'trans_type', true) : 0);
            $verdict = self::assess_rate($row, $parent);
            if ($verdict['issues']) {
                $result['rate_issues'][] = $verdict;
                foreach ($verdict['issues'] as $issue) $result['rate_issue_counts'][$issue] =
                    ($result['rate_issue_counts'][$issue] ?? 0) + 1;
            }
        }
        return $result;
    }

    public static function permitted(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('commerce_manage_pricing');
    }
    public static function rest(): void {
        register_rest_route('mad4b-pricing/v1', '/transport-readiness', array(
            'methods' => 'GET',
            'callback' => static fn() => rest_ensure_response(self::scan()),
            'permission_callback' => array(__CLASS__, 'permitted'),
        ));
    }
    public static function admin_menu(): void {
        add_management_page('Transport Pricing Readiness', 'Transport Pricing Readiness',
            'manage_woocommerce', 'mad4b-transport-pricing-readiness', array(__CLASS__, 'render'));
    }
    public static function pricing_link(): void {
        if (!self::permitted() || !isset($_GET['page'])) return;
        $page = sanitize_key((string) wp_unslash($_GET['page']));
        if (!in_array($page, array('dynamic-pricing-controls', 'etg-dynamic-pricing-controls'), true)) return;
        echo '<div class="notice notice-info"><p><strong>Transportation Pricing:</strong> ';
        echo 'Classification and rates integrity checks are available in ';
        echo '<a href="' . esc_url(admin_url('tools.php?page=mad4b-transport-pricing-readiness')) . '">Transport Pricing Readiness</a>. ';
        echo 'No prices or bookings are changed by this check.</p></div>';
    }
    public static function render(): void {
        if (!self::permitted()) wp_die(esc_html__('Not allowed.', 'mad4b'));
        $snapshot = self::scan();
        echo '<div class="wrap"><h1>Transport Pricing Readiness</h1>';
        echo '<p>Read-only, non-bookable preflight of the existing JetEngine Transportation CPT and Transport Rates CCT.</p>';
        echo '<p><strong>Transportations:</strong> ' . esc_html((string) $snapshot['transport_count']);
        echo ' &nbsp; <strong>Rate records:</strong> ' . esc_html((string) $snapshot['rates_count']);
        echo ' &nbsp; <strong>Rate table:</strong> ' . ($snapshot['rates_table_present'] ? 'Present' : 'Missing') . '</p>';
        MAD4B_Transport_Source_Candidates_V1::admin();
        MAD4B_Transport_CPT_Readiness_V1::admin();
        foreach (array('transport_issues' => 'Transportation classification issues',
                      'rate_issues' => 'Rate integrity issues') as $key => $title) {
            echo '<h2>' . esc_html($title) . '</h2>';
            if (!$snapshot[$key]) { echo '<p>No issues found in scanned records.</p>'; continue; }
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Issues</th></tr></thead><tbody>';
            foreach ($snapshot[$key] as $item) {
                $id = $item['transport_id'] ?? $item['id'] ?? 0;
                $id = $key === 'rate_issues' ? $item['rate_id'] : $id;
                echo '<tr><td>' . esc_html((string) $id) . '</td><td>' .
                    esc_html(implode(', ', $item['issues'])) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p>Contract: <code>' . esc_html(self::CONTRACT) .
            '</code> | Scan limit: ' . esc_html((string) self::MAX_SCAN) . ' per source</p></div>';
    }
    public static function boot(): void {
        add_action('rest_api_init', array(__CLASS__, 'rest'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 100);
        add_action('admin_notices', array(__CLASS__, 'pricing_link'));
    }
}
MAD4B_Transport_Pricing_Readiness_V1::boot();
