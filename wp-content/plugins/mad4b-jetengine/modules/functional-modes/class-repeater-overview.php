<?php
defined('ABSPATH') || exit;

/**
 * Read-only preview of unsaved JetEngine repeater controls.
 * Never calls update_option, wp_insert_post, shared pricing writes, or JetEngine save.
 */
final class MAD4B_JetEngine_Repeater_Overview_V1 {
    public const AJAX_ACTION = 'mad4b_jetengine_repeater_overview_v1';
    public static function boot(): void {
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_preview']);
    }

    private static function message(string $value): string {
        $clean = sanitize_text_field($value);
        return function_exists('mb_substr') ? mb_substr($clean, 0, 160) : substr($clean, 0, 160);
    }

    public static function generate(array $page, array $payload): array {
        $out = [];
        foreach (array_slice($payload, 0, 12) as $item) {
            if (!is_array($item)) continue;
            $fieldName = (string)($item['field'] ?? '');
            $definition = $page['fields'][$fieldName] ?? null;
            if (!is_array($definition) || ($definition['type'] ?? '') !== 'repeater') continue;
            $schema = (array)($definition['repeater_fields'] ?? []);
            $allow = [];
            foreach ($schema as $s) {
                if (is_array($s) && isset($s['name'], $s['label'])) {
                    $allow[(string)$s['name']] = self::message((string)$s['label']);
                }
            }
            $rows = [];
            foreach (array_slice((array)($item['rows'] ?? []), 0, 50) as $i => $values) {
                if (!is_array($values)) continue;
                $parts = [];
                foreach ($allow as $name => $label) {
                    if (!array_key_exists($name, $values) || !is_scalar($values[$name])) continue;
                    $value = self::message((string)$values[$name]);
                    if ($value === '') continue;
                    $parts[] = ['label'=>$label, 'value'=>$value];
                    if (count($parts) >= 8) break;
                }
                $rows[] = [
                    'index'=>(int)$i,
                    'parts'=>$parts,
                    'summary'=>$parts ? implode(' · ', array_map(
                        static fn($p) => $p['label'] . ': ' . $p['value'], $parts
                    )) : __('No values entered', 'mad4b-jetengine'),
                ];
            }
            $out[] = [
                'field'=>$fieldName,
                'total'=>count($rows),
                'rows'=>$rows,
                'preview_only'=>true,
            ];
        }
        return $out;
    }

    public static function ajax_preview(): void {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');
        $slug = isset($_POST['page_slug']) && is_string($_POST['page_slug'])
            ? sanitize_key(wp_unslash($_POST['page_slug'])) : '';
        // This read-only AJAX endpoint can describe other options pages.
        // Master pricing users are limited to the native pricing page,
        // while WordPress settings administrators keep existing behavior.
        if(!current_user_can('manage_options') &&
            !($slug==='etg-dynamic-pricing-controls'
              && MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized()))
            wp_send_json_error(['message'=>'Forbidden'],403);
        $pages = MAD4B_JetEngine_Field_Modes_V1::pages();
        if ($slug === '' || !isset($pages[$slug])) {
            wp_send_json_error(['message'=>'Unknown JetEngine Options Page'], 400);
        }
        $raw = isset($_POST['items']) && is_string($_POST['items'])
            ? wp_unslash($_POST['items']) : '';
        if ($raw === '' || strlen($raw) > 50000) wp_send_json_error(['message'=>'Invalid preview size'], 400);
        $payload = json_decode($raw, true);
        if (!is_array($payload) || count($payload) > 12) wp_send_json_error(['message'=>'Invalid preview request'], 400);
        wp_send_json_success([
            'previews'=>self::generate($pages[$slug], $payload),
            'preview_only'=>true,
            'saved'=>false,
        ]);
    }
}
