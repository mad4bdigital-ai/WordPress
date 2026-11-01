<?php
/** Hermetic WP 6.9+ dependency-order regression; no DB, network or live WordPress. */
define('ABSPATH', '/fixture/');
$GLOBALS['test_context'] = true;
$GLOBALS['test_query_id'] = 5;
$GLOBALS['test_vendor_enqueues'] = true;
$GLOBALS['test_vendor_only_registered'] = false;
$GLOBALS['test_hooks'] = array();
$GLOBALS['test_registry'] = array();
$GLOBALS['test_queue'] = array();
$GLOBALS['test_inline'] = array();
$GLOBALS['test_notices'] = array();
$GLOBALS['test_printed'] = array();

function wp_get_environment_type() { return 'local'; }
function add_action($name, $callback, $priority = 10) {
    $GLOBALS['test_hooks'][$name][$priority][] = $callback;
}
function wp_parse_url($str, $component = -1) { return parse_url($str, $component); }
function shared_presentation_catalog_url_context_v1($url) { return $GLOBALS['test_context'] ? array('url' => $url) : false; }
function shared_presentation_catalog_contract_v1() { return array('query_id' => $GLOBALS['test_query_id'], 'provider' => 'jet-engine:tours_query_archive'); }
function plugin_dir_url($path) { return 'https://example.test/mu-plugins/shared-product-core/adapters/'; }
function wp_json_encode($value) { return json_encode($value); }
function wp_enqueue_script($handle, $src = '', $deps = array(), $version = '', $in_footer = false) {
    $GLOBALS['test_registry'][$handle] = array('src' => $src, 'deps' => $deps, 'footer' => $in_footer);
    $GLOBALS['test_queue'][$handle] = true;
}
function wp_script_is($handle, $status = 'enqueued') {
    if ($status === 'registered') return isset($GLOBALS['test_registry'][$handle]);
    if ($status === 'enqueued') return isset($GLOBALS['test_queue'][$handle]);
    return false;
}
function wp_add_inline_script($handle, $data, $position = 'after') {
    if (!isset($GLOBALS['test_registry'][$handle])) return false;
    $GLOBALS['test_inline'][$handle] = array('data' => $data, 'position' => $position);
    return true;
}
function test_print_scripts($phase) {
    foreach ($GLOBALS['test_queue'] as $handle => $_) {
        foreach ($GLOBALS['test_registry'][$handle]['deps'] as $dep) {
            if (!wp_script_is($dep, 'registered')) {
                $GLOBALS['test_notices'][] = $phase.':'.$handle.':'.$dep;
            }
        }
        if ($phase === 'footer' && empty($GLOBALS['test_notices'])) $GLOBALS['test_printed'][$handle] = true;
    }
}
function test_run($hook) {
    if (!isset($GLOBALS['test_hooks'][$hook])) return;
    ksort($GLOBALS['test_hooks'][$hook]);
    foreach ($GLOBALS['test_hooks'][$hook] as $callbacks) foreach ($callbacks as $callback) $callback();
}
function test_assert($ok, $reason) {
    if (!$ok) { fwrite(STDERR, 'FAIL '.$reason."\n"); exit(1); }
    echo 'PASS '.$reason."\n";
}
require dirname(__DIR__).'/mu-plugins/shared-product-core/adapters/presentation-jsf-pagination-v2.php';
add_action('wp_footer', static function() {
    if (!$GLOBALS['test_vendor_enqueues'] && !$GLOBALS['test_vendor_only_registered']) return;
    $GLOBALS['test_registry']['jet-smart-filters'] = array('src' => 'vendor-public.js', 'deps' => array(), 'footer' => true);
    if ($GLOBALS['test_vendor_enqueues']) $GLOBALS['test_queue']['jet-smart-filters'] = true;
}, 15);
add_action('wp_footer', static function() { test_print_scripts('footer'); }, 20);

function test_scenario($vendor, $context, $query_id = 5, $only_registered = false) {
    $GLOBALS['test_registry'] = array();
    $GLOBALS['test_queue'] = array();
    $GLOBALS['test_inline'] = array();
    $GLOBALS['test_notices'] = array();
    $GLOBALS['test_printed'] = array();
    $GLOBALS['test_vendor_enqueues'] = $vendor;
    $GLOBALS['test_vendor_only_registered'] = $only_registered;
    $GLOBALS['test_context'] = $context;
    $GLOBALS['test_query_id'] = $query_id;
    $_SERVER['REQUEST_URI'] = '/egypt/tours-and-activities/';
    test_run('wp_enqueue_scripts');
    test_print_scripts('head');
    test_run('wp_footer');
    return $GLOBALS['test_notices'];
}
test_assert(test_scenario(true, true) === array(), 'no unregistered JSF dependency in head or footer');
$handle = 'shared-presentation-jsf-pagination-v2';
test_assert(isset($GLOBALS['test_printed'][$handle]), 'pagination script queued in native footer lane');
test_assert($GLOBALS['test_registry'][$handle]['deps'] === array('jet-smart-filters'), 'native JSF dependency preserved');
test_assert(($GLOBALS['test_inline'][$handle]['position'] ?? '') === 'before', 'inline config loads before paginator');
test_assert(!isset($GLOBALS['test_printed'][$handle]) === false, 'target script is not silently disabled');
test_assert(test_scenario(false, true) === array() && !isset($GLOBALS['test_queue'][$handle]), 'absent JSF skips adapter without wrong dependency');
test_assert(test_scenario(false, true, 5, true) === array() && !isset($GLOBALS['test_queue'][$handle]), 'registered but unused JSF does not trigger adapter');
test_assert(test_scenario(true, false) === array() && !isset($GLOBALS['test_queue'][$handle]), 'non-catalog route does not enqueue');
test_assert(test_scenario(true, true, 4) === array() && !isset($GLOBALS['test_queue'][$handle]), 'nonmatching query id does not enqueue');
echo "JSF_PAGINATION_ENQUEUE_ORDER: PASS\n";
