<?php
/**
 * Native read-only WordPress contract. No do_action or post-meta writes.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) return;
if (!function_exists('mad4b_render_query_is_generated_meta_artifact'))
    WP_CLI::error('Original Query Governor generated-meta guard missing');
$cases=[
    Elementor\Core\Base\Document::CACHE_META_KEY => true,
    Elementor\Core\Base\Elements_Iteration_Actions\Assets::ASSETS_META_KEY => true,
    '_elementor_css' => true,
    '_edit_lock' => true,
    '_edit_last' => true,
    '_mad4b_watermark_fingerprint_v1' => true,
    '_elementor_data' => false,
    '_elementor_page_settings' => false,
    '_listing_data' => false,
    '_price' => false,
    'booking_policy' => false,
    'rate_override' => false,
    'supplier_price' => false,
];
foreach ($cases as $meta_key=>$expected) {
    $actual=mad4b_render_query_is_generated_meta_artifact($meta_key);
    if ($actual!==$expected) {
        WP_CLI::error('Generated meta guard mismatch for '.sanitize_key($meta_key));
    }
}
WP_CLI::success(wp_json_encode([
    'contract'=>'mad4b.render-query.elementor-generated-meta-invalidation.v1',
    'native_elementor_cache_key'=>Elementor\Core\Base\Document::CACHE_META_KEY,
    'native_assets_key'=>Elementor\Core\Base\Elements_Iteration_Actions\Assets::ASSETS_META_KEY,
    'checked'=>count($cases),
    'dynamic_content_keys_invalidate'=>true,
    'read_only'=>true,
    'content_or_options_mutated'=>false,
],JSON_UNESCAPED_SLASHES));
