<?php
/**
 * Read-only Elementor / Elementor Pro compatibility probe for known site templates.
 * Must be run separately on each target; it does NOT certify editor save workflows.
 */
if (PHP_SAPI !== 'cli' || getenv('MAD4B_ELEMENTOR_READONLY') !== '1') {
    fwrite(STDERR, "READONLY_OPT_IN_REQUIRED\n"); exit(2);
}
$root = rtrim((string)getenv('MAD4B_ELEMENTOR_SITE_ROOT'), '/');
if (!$root || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "SITE_ROOT_REQUIRED\n"); exit(2);
}
require_once $root . '/wp-load.php';
$expected = (string)getenv('MAD4B_ELEMENTOR_EXPECTED_PRO');
$actual = defined('ELEMENTOR_PRO_VERSION') ? (string)ELEMENTOR_PRO_VERSION : '';
$documentIds = array_filter(array_map('trim', explode(',', (string)getenv('MAD4B_ELEMENTOR_DOC_IDS'))));
if (!$actual || !$expected || $actual !== $expected || !$documentIds
    || count($documentIds) > 8 || !class_exists('Elementor\\Plugin')
    || !class_exists('ElementorPro\\Plugin')) {
    fwrite(STDERR, "VERSION_OR_DEPENDENCY_GATE_FAILED\n"); exit(2);
}
$warnings = [];
set_error_handler(static function ($severity, $message) use (&$warnings) {
    if ($severity & error_reporting()) $warnings[] = substr((string)$message, 0, 200);
    return true;
});
$rows = [];
try {
    foreach ($documentIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || get_post_type($id) !== 'elementor_library') {
            $rows[] = ['id' => $rawId, 'error' => 'template_not_present'];
            continue;
        }
        $document = \Elementor\Plugin::instance()->documents->get($id);
        $data = $document ? $document->get_elements_data() : null;
        $result = ['id' => $id, 'document_loaded' => is_object($document),
            'elements_valid' => is_array($data), 'top_level_nodes' => is_array($data) ? count($data) : null];
        if (is_array($data)) {
            $startLevel = ob_get_level();
            ob_start();
            try {
                $rendered = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($id, true);
                $result['render_success'] = is_string($rendered) && trim($rendered) !== '';
                $result['render_bytes'] = is_string($rendered) ? strlen($rendered) : 0;
            } catch (Throwable $e) {
                $result['render_exception'] = get_class($e);
                $result['render_success'] = false;
            } finally {
                while (ob_get_level() > $startLevel) ob_end_clean();
            }
        }
        $rows[] = $result;
    }
} finally {
    restore_error_handler();
}
$success = count($rows) === count($documentIds) && !$warnings;
foreach ($rows as $row) {
    if (empty($row['document_loaded']) || empty($row['elements_valid']) || empty($row['render_success'])) {
        $success = false;
    }
}
echo json_encode([
    'contract' => 'mad4b.commerce.elementor-readonly-parity.v1',
    'site_origin' => home_url(), 'environment' => wp_get_environment_type(),
    'elementor_core_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
    'elementor_pro_version' => $actual, 'docs' => $rows, 'warnings' => $warnings,
    'read_only_probe' => true, 'editor_save_certified' => false,
    'pass' => $success,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($success ? 0 : 1);
