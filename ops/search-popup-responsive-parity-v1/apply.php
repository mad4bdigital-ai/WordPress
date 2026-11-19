<?php
/**
 * Scoped Elementor Tours Search popup remediation.
 * Dry-run by default; requires MAD4B_POPUP_APPLY=1 and an exact preimage hash
 * for writes. Does not change any site-wide cache or JS settings.
 */
if (!defined('ABSPATH') || !function_exists('get_post_meta')) {
    fwrite(STDERR, "WORDPRESS_BOOT_REQUIRED\n");
    exit(70);
}
global $wpdb;
$id = 41878;
$templateId = 41728;
$metaKey = '_elementor_page_settings';
$marker = '/* MAD4B_SEARCH_POPUP_RESPONSIVE_20261119_V1 */';
$sourceCss = file_get_contents(__DIR__ . '/popup-responsive.css');
if (!is_string($sourceCss) ||
    !str_starts_with($sourceCss, $marker) ||
    substr_count($sourceCss, $marker) !== 1 ||
    !str_contains($sourceCss, "#elementor-popup-modal-{$id}") ||
    !str_contains($sourceCss, ".elementor-{$templateId}") ||
    !str_contains($sourceCss, '.jet-ajax-search__results-area')) {
    fwrite(STDERR, "UNTRUSTED_SCOPED_CSS\n");
    exit(71);
}
$post = get_post($id);
if (!$post || $post->post_type !== 'elementor_library' ||
    get_post_meta($id, '_elementor_template_type', true) !== 'popup') {
    fwrite(STDERR, "POPUP_DOCUMENT_ID_MISMATCH\n");
    exit(72);
}
$data = get_post_meta($id, '_elementor_data', true);
if (!is_string($data) || !str_contains($data, (string)$templateId)) {
    fwrite(STDERR, "EMBEDDED_SEARCH_TEMPLATE_NOT_PRESENT\n");
    exit(73);
}
$current = get_post_meta($id, $metaKey, true);
if (!is_array($current)) {
    fwrite(STDERR, "POPUP_SETTINGS_NOT_ARRAY\n");
    exit(74);
}
$mobile = ['unit' => 'vw', 'size' => 94, 'sizes' => []];
$tablet = ['unit' => 'vw', 'size' => 92, 'sizes' => []];
$existingCss = (string)($current['custom_css'] ?? '');
$liveSha = hash('sha256', serialize($current));
if (str_contains($existingCss, $marker)) {
    $fragment = substr($existingCss, strpos($existingCss, $marker));
    if (substr_count($existingCss, $marker) !== 1 ||
        $fragment !== $sourceCss ||
        ($current['width_mobile'] ?? null) !== $mobile ||
        ($current['width_tablet'] ?? null) !== $tablet) {
        fwrite(STDERR, "INSTALLED_POPUP_DRIFT_REQUIRES_RECONCILIATION\n");
        exit(75);
    }
    echo wp_json_encode([
        'state' => 'ALREADY_APPLIED_EXACT_CSS_AND_SETTINGS',
        'popup_id' => $id,
        'meta_sha256' => $liveSha,
        'popup_css_sha256' => hash('sha256', $sourceCss),
        'site_url' => home_url(),
        'wp_mutated' => false,
    ]) . "\n";
    return;
}
$expected = getenv('MAD4B_POPUP_EXPECTED_META_SHA256');
if (!is_string($expected) || !preg_match('/^[0-9a-f]{64}$/', $expected) ||
    !hash_equals($expected, $liveSha)) {
    fwrite(STDERR, "PREIMAGE_NOT_APPROVED_OR_CHANGED\n");
    exit(76);
}
$desired = $current;
$desired['width_mobile'] = $mobile;
$desired['width_tablet'] = $tablet;
$desired['custom_css'] = rtrim($existingCss) . "\n\n" . $sourceCss;
$desiredSha = hash('sha256', serialize($desired));
if (getenv('MAD4B_POPUP_APPLY') !== '1') {
    echo wp_json_encode([
        'state' => 'PLAN_VALID_NO_WRITE',
        'popup_id' => $id,
        'expected_meta_sha256' => $expected,
        'desired_meta_sha256' => $desiredSha,
        'source_css_sha256' => hash('sha256', $sourceCss),
        'wp_mutated' => false,
    ]) . "\n";
    return;
}
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",
        $id,
        $metaKey
    ),
    ARRAY_A
);
if (count($rows) !== 1 || $rows[0]['meta_value'] !== serialize($current)) {
    fwrite(STDERR, "RAW_POSTMETA_PREIMAGE_MISMATCH\n");
    exit(77);
}
// WordPress update_post_meta() applies wp_unslash(), changing existing
// custom CSS. Preserve exact original bytes using a conditional SQL update.
$updated = $wpdb->query(
    $wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND BINARY meta_value=%s",
        serialize($desired),
        (int)$rows[0]['meta_id'],
        $rows[0]['meta_value']
    )
);
if ($updated !== 1) {
    fwrite(STDERR, "POPUP_CAS_UPDATE_REFUSED\n");
    exit(78);
}
wp_cache_delete($id, 'post_meta');
try {
    (new \Elementor\Core\Files\CSS\Post($id))->update();
} catch (Throwable $error) {
    fwrite(STDERR, "ELEMENTOR_CSS_REGENERATE_FAILED\n");
    exit(79);
}
$readback = get_post_meta($id, $metaKey, true);
if ($readback !== $desired || hash('sha256', serialize($readback)) !== $desiredSha) {
    fwrite(STDERR, "POPUP_POST_WRITE_VERIFICATION_FAILED\n");
    exit(80);
}
do_action('litespeed_purge_url', home_url('/'));
echo wp_json_encode([
    'state' => 'APPLIED_VERIFIED',
    'popup_id' => $id,
    'meta_sha256' => $desiredSha,
    'source_css_sha256' => hash('sha256', $sourceCss),
    'only_scoped_popup_updated' => true,
    'js_settings_changed' => false,
]) . "\n";
