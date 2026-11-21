<?php
/**
 * Company Performance Preset: short-lived public, per-term JetEngine card cache.
 * Injected alternate elements (Fluent Forms), pricing and booking remain dynamic.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging' || PHP_SAPI === 'cli') return;

add_action('init', static function() {
    if (!function_exists('mad4b_company_fragment_preset_enabled')
        || !mad4b_company_fragment_preset_enabled('individual_attractions_cards')
        || ($_SERVER['HTTP_X_MAD4B_CARD_AB'] ?? 'enabled') === 'control') return;

    $cfg = mad4b_company_fragment_preset_config();
    $targets = $cfg['targets'];
    $doc_id = $targets['attractions_document_id'];
    $widget_id = $targets['attractions_widget_id'];
    $listing_id = $targets['attractions_listing_id'];
    $taxonomy = $targets['attractions_taxonomy'];
    $ttl = (int) $cfg['fragment_ttl_seconds'];
    $root = sys_get_temp_dir().'/mad4b-company-card-fragments-'.
        hash('sha256', strtolower($cfg['site_host']));
    if (!is_dir($root) && !@mkdir($root, 0700, true)) return;
    if (!is_writable($root)) return;

    $read = static function($key) use($root) {
        $file = $root . '/' . $key . '.json';
        if (!is_file($file) || filesize($file) > 76000) return false;
        $entry = json_decode((string) @file_get_contents($file), true);
        return is_array($entry) && ($entry['expires'] ?? 0) > time() ? ($entry['html'] ?? false) : false;
    };
    $write = static function($key, $html) use($root, $ttl) {
        $file = $root . '/' . $key . '.json';
        $payload = json_encode(['expires'=>time()+$ttl,'html'=>$html],
            JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) return;
        $tmp = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
    };
    require_once WPMU_PLUGIN_DIR . '/mad4b-shared-render/public-term-cards.php';
    mad4b_register_public_term_cards($doc_id, $widget_id, $listing_id, $taxonomy, $read, $write);
}, 20);
