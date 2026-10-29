<?php
/**
 * Read-only WordPress CLI audit for company Kit token inheritance.
 *
 * Usage: wp eval-file scripts/audit_company_navigation_kit.php --path=/var/www/html
 * New company onboarding gate: MAD4B_NEW_COMPANY_ADMISSION=1 wp eval-file ...
 *
 * Never writes Elementor JSON or Site Kit options.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    return;
}
$kitId = (int)\Elementor\Plugin::$instance->kits_manager->get_active_id();
$kit = (array)get_post_meta($kitId, '_elementor_page_settings', true);
$systemNames = array_column((array)($kit['system_colors'] ?? []), '_id');
$customNames = array_column((array)($kit['custom_colors'] ?? []), '_id');
$counts = array_count_values(array_filter($customNames, 'is_string'));
$duplicateIds = array_keys(array_filter($counts, static function ($count) { return $count > 1; }));
sort($duplicateIds);
$requiredSystem = ['primary', 'secondary', 'text', 'accent'];
$missingSystem = array_values(array_filter($requiredSystem, static function ($name) use ($systemNames) {
    return count(array_keys($systemNames, $name, true)) !== 1;
}));
$surfaceCount = count(array_keys($customNames, 'bnav001', true));
$fields = [
 '9757cf2'=>[
  'color_menu_item'=>'text','color_menu_item_active'=>'accent','pointer_color_menu_item_active'=>'accent',
  'toggle_background_color'=>'bnav001','toggle_color'=>'text',
  'background_color_dropdown_item'=>'bnav001','nav_menu_divider_color'=>'accent',
 ],
 'b29a19b'=>[
  'item_text_color_normal'=>'text','item_text_color_active'=>'accent',
  'marker_color'=>'accent','border_color'=>'accent','background_color'=>'bnav001',
 ],
];
$issues = [];
if ($missingSystem) $issues[] = 'missing_semantic_system_tokens';
if ($surfaceCount !== 1) $issues[] = 'non_unique_header_surface';
$ids = [24784,42179,35121,35123,35119,40121];
foreach ($ids as $templateId) {
    $tree = json_decode((string)get_post_meta($templateId, '_elementor_data', true), true);
    if (!is_array($tree)) { $issues[] = 'invalid_template_json_'.$templateId; continue; }
    $found = [];
    $scan = static function ($nodes) use (&$scan, $fields, &$issues, &$found, $templateId) {
        foreach ((array)$nodes as $node) {
            $widget = $node['id'] ?? '';
            if (isset($fields[$widget])) {
                $found[$widget] = ($found[$widget] ?? 0) + 1;
                $glob = (array)($node['settings']['__globals__'] ?? []);
                foreach ($fields[$widget] as $setting=>$token) {
                    if (($glob[$setting] ?? '') !== 'globals/colors?id='.$token) {
                        $issues[] = 'wrong_kit_color_'.$templateId.'_'.$widget.'_'.$setting;
                    }
                }
            }
            if (!empty($node['elements'])) $scan($node['elements']);
        }
    };
    $scan($tree);
    foreach (array_keys($fields) as $widget) {
        if (($found[$widget] ?? 0) !== 1) $issues[] = 'invalid_widget_count_'.$templateId.'_'.$widget;
    }
}
$strict = getenv('MAD4B_NEW_COMPANY_ADMISSION') === '1';
if ($strict && $duplicateIds) $issues[] = 'new_company_kit_duplicate_color_ids';
$status = !$issues ? ($duplicateIds ? 'COMPLIANT_NAVIGATION_WITH_KIT_LEGACY_DEBT' : 'COMPLIANT') : 'GOVERNANCE_BLOCKED';
echo wp_json_encode([
 'contract'=>'mad4b.elementor-kit-navigation-audit.v1',
 'site'=>wp_parse_url(home_url(),PHP_URL_HOST),
 'kit_id'=>$kitId,
 'status'=>$status,
 'checked_translated_tour_templates'=>count($ids),
 'legacy_duplicate_custom_color_ids'=>$duplicateIds,
 'strict_new_company_admission'=>$strict,
 'issues'=>$issues,
 'source_mutated'=>false,
], JSON_UNESCAPED_UNICODE).PHP_EOL;
if ($issues) WP_CLI::error('Governed navigation Kit audit blocked');
