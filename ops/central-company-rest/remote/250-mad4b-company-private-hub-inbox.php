<?php
/**
 * Plugin Name: MAD4B Company Private Hub Inbox
 * Description: Read-only reference snapshots from a connected MAD4B Central Hub, private by default.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) { exit; }
final class MAD4B_Company_Hub_Inbox_V1 {
    const ROUTE = '/mad4b-company/v1/hub-reference-inbox';
    const SOURCE = 'tourism-almallahgroup';
    const STORE = 'mad4b_hub_private_inbox_tourism_v1';
    private static function fail($reason, $status=403) {
        return new WP_Error('mad4b_hub_inbox_' . $reason, 'Reference inbox rejected.', ['status' => $status]);
    }
    public static function enabled(): bool {
        return class_exists('MAD4B_Company_Simple_Identity_V1') &&
            (bool) MAD4B_Company_Simple_Identity_V1::identity_for_host();
    }
    public static function permission($request) {
        if (!self::enabled()) { return self::fail('unavailable'); }
        return MAD4B_Company_Simple_Identity_V1::authorized_for($request, 'POST', self::ROUTE);
    }
    public static function accept(WP_REST_Request $request) {
        if (self::permission($request) !== true) { return self::fail('unauthorized'); }
        $raw = $request->get_body();
        if (!is_string($raw) || strlen($raw) > 150000) { return self::fail('oversize', 400); }
        $data = json_decode($raw, true);
        $site = MAD4B_Company_Simple_Identity_V1::identity_for_host();
        if (!is_array($data) ||
            ($data['contract'] ?? '') !== 'mad4b.hub.private-reference-snapshot.v1' ||
            ($data['source_company'] ?? '') !== self::SOURCE ||
            ($data['consumer_company'] ?? '') !== $site['company_id'] ||
            ($data['reference_only'] ?? false) !== true ||
            ($data['may_publish'] ?? true) !== false ||
            ($data['may_checkout'] ?? true) !== false ||
            !isset($data['items'], $data['snapshot_sha256']) ||
            !is_array($data['items']) || count($data['items']) > 150 ||
            !is_string($data['snapshot_sha256']) ||
            !preg_match('/^[a-f0-9]{64}$/D', $data['snapshot_sha256'])) {
            return self::fail('invalid_contract', 400);
        }
        $seen = [];
        $items = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item) || count($item) !== 9 ||
                ($item['source_company'] ?? '') !== self::SOURCE ||
                !in_array($item['kind'] ?? '', ['nile_category', 'nile_tour'], true) ||
                !in_array($item['language'] ?? '', ['en', 'ar'], true) ||
                !is_int($item['source_id'] ?? null) || $item['source_id'] <= 0 ||
                !is_string($item['title'] ?? null) ||
                strlen($item['title']) < 1 || strlen($item['title']) > 750 ||
                !is_string($item['summary'] ?? null) || strlen($item['summary']) > 2000 ||
                ($item['reference_only'] ?? false) !== true ||
                ($item['may_publish'] ?? true) !== false ||
                ($item['may_checkout'] ?? true) !== false) {
                return self::fail('unsafe_item', 400);
            }
            $key = $item['kind'] . ':' . $item['language'] . ':' . $item['source_id'];
            if (isset($seen[$key])) { return self::fail('duplicate_item', 400); }
            $seen[$key] = true;
            $items[] = $item;
        }
        // Immutable field normalization: digest excludes retrieval timestamps.
        $digest = hash('sha256', wp_json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!hash_equals($digest, $data['snapshot_sha256'])) { return self::fail('digest_mismatch', 400); }
        // One bounded atomic replacement, only after every row passes.
        update_option(self::STORE, [
            'source_company' => self::SOURCE,
            'consumer_company' => $site['company_id'],
            'items' => $items, 'snapshot_sha256' => $digest,
            'reference_only' => true, 'may_publish' => false,
            'may_checkout' => false, 'received_at' => gmdate('c'),
        ], false);
        $response = new WP_REST_Response([
            'contract' => 'mad4b.hub.private-reference-receipt.v1',
            'consumer_company' => $site['company_id'],
            'snapshot_sha256' => $digest,
            'stored_items' => count($items),
            'reference_only' => true
        ], 200);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
    public static function status() {
        $saved = get_option(self::STORE, []);
        return new WP_REST_Response([
            'contract' => 'mad4b.hub.private-inbox-status.v1',
            'reference_items' => is_array($saved['items'] ?? null) ? count($saved['items']) : 0,
            'last_received' => $saved['received_at'] ?? null,
            'public_listing_active' => false,
            'checkout_active' => false
        ], 200);
    }
    public static function preview(WP_REST_Request $request) {
        $saved = get_option(self::STORE, []);
        $items = is_array($saved['items'] ?? null) ? $saved['items'] : [];
        $lang = (string) ($request->get_param('language') ?: '');
        $kind = (string) ($request->get_param('kind') ?: '');
        if ($lang !== '') $items = array_values(array_filter($items, static function($item) use ($lang) { return $item['language'] === $lang; }));
        if ($kind !== '') $items = array_values(array_filter($items, static function($item) use ($kind) { return $item['kind'] === $kind; }));
        return new WP_REST_Response(['items' => array_slice($items, 0, 100), 'total' => count($items), 'reference_only' => true], 200);
    }
    public static function boot(): void {
        add_action('rest_api_init', static function() {
            if (!self::enabled()) return;
            register_rest_route('mad4b-company/v1', '/hub-reference-inbox', [
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => [__CLASS__, 'permission'],
                'callback' => [__CLASS__, 'accept']
            ]);
            register_rest_route('mad4b-company/v1', '/hub-inbox/status', [
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => static function() { return current_user_can('manage_options'); },
                'callback' => [__CLASS__, 'status']
            ]);
            register_rest_route('mad4b-company/v1', '/hub-inbox/preview', [
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => static function() { return current_user_can('manage_options'); },
                'callback' => [__CLASS__, 'preview']
            ]);
        });
    }
}
MAD4B_Company_Hub_Inbox_V1::boot();
