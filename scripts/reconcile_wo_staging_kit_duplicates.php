<?php
/**
 * ONE-TIME Wo Vacation Staging-only source-backed Kit duplicate reconciliation.
 * Default DRY-RUN. Set MAD4B_KIT_DEDUP_APPLY=1 to apply CAS once.
 * Retains the LAST entry for each duplicate ID, matching the exact value
 * observed in the rendered Elementor global CSS on 2026-10-30.
 * No template, customer, order, price or production data is written.
 */
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) { return; }
global $wpdb;
if (wp_get_environment_type() !== 'staging' || DB_NAME !== 'etg_commerce_staging' ||
    wp_parse_url(home_url(), PHP_URL_HOST) !== 'tours.dev.mad4b.com' ||
    get_option('blogname') !== 'Wo Vacation') {
    WP_CLI::error('SCOPE_DENIED_NOT_WO_VACATION_STAGING');
}
$kitId = (int)\Elementor\Plugin::$instance->kits_manager->get_active_id();
if ($kitId !== 60050) WP_CLI::error('KIT_ID_CHANGED');
$expected = [
    'e14cd5e'=>'#F6AE22','80789f7'=>'#F9F7F5','62e8100'=>'#D9D1CA',
    '272e6c6'=>'#EDE0CF','6f02953'=>'#4E596A','0ce0fb4'=>'#FFFFFF',
    '3a5c55e'=>'#FBFBFB','c18767c'=>'#9FA1AC','e3861c0'=>'#FFFCF4',
    '9f7c109'=>'#5D452F','36b053a'=>'#474035','a558f6f'=>'#D4B483',
    '887fd61'=>'#78614C',
];
$row = $wpdb->get_row($wpdb->prepare(
    "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_elementor_page_settings' LIMIT 1",
    $kitId
), ARRAY_A);
if (!$row) WP_CLI::error('KIT_SETTINGS_NOT_FOUND');
$settings = maybe_unserialize($row['meta_value']);
if (!is_array($settings) || !is_array($settings['custom_colors'] ?? null) ||
    count($settings['custom_colors']) !== 56) {
    WP_CLI::error('UNEXPECTED_KIT_PALETTE_SHAPE');
}
$colors = $settings['custom_colors'];
$lastIndex = [];
$countById = [];
foreach ($colors as $i=>$item) {
    $id = $item['_id'] ?? null;
    if (!is_string($id)) WP_CLI::error('INVALID_CUSTOM_COLOR_ID');
    $lastIndex[$id] = $i;
    $countById[$id] = ($countById[$id] ?? 0) + 1;
}
$duplicateIds = array_filter($countById, static function($n) { return $n > 1; });
if (count($duplicateIds) !== 13 || count($colors)-count($lastIndex) !== 37) {
    WP_CLI::error('CHANGED_DUPLICATE_CENSUS');
}
foreach ($expected as $id=>$winner) {
    if (!isset($lastIndex[$id]) || ($colors[$lastIndex[$id]]['color'] ?? '') !== $winner ||
        ($countById[$id] ?? 0) < 3) {
        WP_CLI::error('LIVE_CSS_WINNER_NOT_VERIFIED_'.$id);
    }
}
$newColors = [];
foreach ($colors as $i=>$item) {
    if ($lastIndex[$item['_id']] === $i) $newColors[] = $item;
}
if (count($newColors) !== 19) WP_CLI::error('UNEXPECTED_FINAL_PALETTE');
$old = $row['meta_value'];
$settings['custom_colors'] = $newColors;
$new = maybe_serialize($settings);
if (!is_string($new) || $new === $old) WP_CLI::error('NO_VALID_CHANGE');
$plan = [
    'site'=>'tours.dev.mad4b.com',
    'kit'=>$kitId,
    'mode'=>getenv('MAD4B_KIT_DEDUP_APPLY')==='1' ? 'APPLY' : 'DRY_RUN',
    'before'=>count($colors),
    'after'=>count($newColors),
    'duplicate_ids_removed'=>count($duplicateIds),
    'redundant_rows_removed'=>count($colors)-count($newColors),
    'last_rendered_css_values_preserved'=>$expected,
    'backup_created'=>false,
];
if (getenv('MAD4B_KIT_DEDUP_APPLY')!=='1') {
    echo wp_json_encode(['status'=>'PREVIEW_NO_WRITES','plan'=>$plan], JSON_UNESCAPED_UNICODE).PHP_EOL;
    return;
}
$wpdb->query('START TRANSACTION');
$modified = $wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND meta_value=%s",
    $new, $row['meta_id'], $old
));
if ($modified !== 1) {
    $wpdb->query('ROLLBACK');
    WP_CLI::error('CAS_WRITE_FAILED_ROLLED_BACK');
}
$wpdb->query('COMMIT');
wp_cache_delete($kitId, 'post_meta');
$actual = get_post_meta($kitId, '_elementor_page_settings', true);
$valid = is_array($actual) && count((array)($actual['custom_colors'] ?? []))===19 &&
    wp_json_encode($actual)===wp_json_encode($settings);
if (function_exists('mad4b_kit_integrity_validate_palette')) {
    $valid = $valid && !is_wp_error(mad4b_kit_integrity_validate_palette($actual));
}
if (!$valid) {
    $rollback = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND meta_value=%s",
        $old, $row['meta_id'], $new
    ));
    wp_cache_delete($kitId, 'post_meta');
    WP_CLI::error('READBACK_FAILED_RESTORED_'.($rollback===1?'YES':'NO'));
}
\Elementor\Plugin::$instance->files_manager->clear_cache();
echo wp_json_encode(['status'=>'DEDUPLICATED_KIT_WITH_CSS_WINNERS_PRESERVED','plan'=>$plan], JSON_UNESCAPED_UNICODE).PHP_EOL;
