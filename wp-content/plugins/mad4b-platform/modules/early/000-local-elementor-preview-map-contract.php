<?php
/**
 * Plugin Name: Local QA Elementor Preview Map Asset Contract
 * Description: Prevent unrelated Google Maps API copies on two confirmed
 *              map-free Elementor template previews; native map pages remain
 *              owned by their source plugins and settings.
 */
defined('ABSPATH') || exit;

if (
    ! function_exists('wp_get_environment_type')
    || wp_get_environment_type() !== 'local'
    || ! isset($_GET['elementor-preview'])
    || ! in_array(absint($_GET['elementor-preview']), [40299, 45152], true)
    || (isset($_SERVER['REQUEST_METHOD']) && ! in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD'], true))
) {
    return;
}

// Do not suppress a real map if one is introduced in either template later.
// The source must be valid native Elementor tree data; unknown or embedded
// global/template widgets are treated conservatively as potentially mapped.
$mad4b_elementor_tree = json_decode(
    (string) get_post_meta(absint($_GET['elementor-preview']), '_elementor_data', true),
    true
);
if (! is_array($mad4b_elementor_tree) || ! $mad4b_elementor_tree) {
    return;
}
$mad4b_might_contain_map = static function ($items) use (&$mad4b_might_contain_map): bool {
    if (! is_array($items)) {
        return true;
    }
    foreach ($items as $item) {
        if (! is_array($item)) {
            return true;
        }
        $type = strtolower((string) ($item['widgetType'] ?? ''));
        if ($type !== '' && (
            strpos($type, 'map') !== false
            || in_array($type, ['template', 'global', 'shortcode', 'html'], true)
        )) {
            return true;
        }
        if (isset($item['elements']) && $mad4b_might_contain_map($item['elements'])) {
            return true;
        }
    }
    return false;
};
if ($mad4b_might_contain_map($mad4b_elementor_tree)) {
    return;
}
unset($mad4b_elementor_tree, $mad4b_might_contain_map);

// Keep JetEngine's primary Google Maps provider: its frontend-maps.js module
// still initializes in Elementor previews even when no native map widget is
// present. Blocking that provider previously produced the console error
// "JetEngine Map Provider could not be loaded". Remove only duplicate loaders.
$mad4b_unused_map_scripts = [
    'google-maps-api',             // JetElements duplicate loader
    'uael-google-maps-api',        // Ultimate Elementor Addons duplicate
    'rank-math-google-maps',
];

// Remove stray pre-enqueued scripts at both normal header and footer phases.
$mad4b_trim_map_scripts = static function () use ($mad4b_unused_map_scripts): void {
    foreach ($mad4b_unused_map_scripts as $handle) {
        if (wp_script_is($handle, 'enqueued')) {
            wp_dequeue_script($handle);
        }
    }
};
add_action('wp_print_scripts', $mad4b_trim_map_scripts, 1);
add_action('wp_print_footer_scripts', $mad4b_trim_map_scripts, 1);

// Some plugins enqueue during late Elementor rendering. Skip their API
// <script> tag even then. The accompanying non-API widget source is preserved.
add_filter('script_loader_tag', static function ($tag, $handle, $src) use ($mad4b_unused_map_scripts) {
    if (in_array($handle, $mad4b_unused_map_scripts, true)
        && is_string($src)
        && strpos($src, 'maps.googleapis.com/maps/api/js') !== false) {
        return '';
    }
    return $tag;
}, 10, 3);
