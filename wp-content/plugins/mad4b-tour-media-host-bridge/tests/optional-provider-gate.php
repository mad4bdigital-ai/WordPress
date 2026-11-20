<?php
// Hermetic plugin boundary test; no WordPress or database bootstrap.
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
$scenario = $argv[1] ?? 'eligible';
if (!in_array($scenario, ['eligible', 'no-host', 'no-tour-cpt'], true)) exit(2);
$hooks = [];
function add_action($hook, $callback, $priority = 10) {
    $GLOBALS['hooks'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
}
function post_type_exists($slug) {
    return $slug === 'tours-and-activities' && $GLOBALS['scenario'] !== 'no-tour-cpt';
}
$GLOBALS['scenario'] = $scenario;
if ($scenario !== 'no-host') {
    class MAD4B_SCP_Host_Agent_Bridge {
        public static function active_for($ability) { return false; }
    }
}
require dirname(__DIR__) . '/mad4b-tour-media-host-bridge.php';
if (count($hooks) !== 1 || $hooks[0]['hook'] !== 'init' || $hooks[0]['priority'] !== 99) {
    fwrite(STDERR, 'FAIL expected only one late-init hook' . PHP_EOL);
    exit(1);
}
($hooks[0]['callback'])();
$expect = $scenario === 'eligible';
$required = [
    'MAD4B_SCP_Host_Media_Intake' => ['execute', 'propose_localized_alt'],
    'MAD4B_SCP_Host_Media_Closure' => ['execute', 'preflight'],
    'MAD4B_SCP_Host_Media_Reconcile_Proposal' => ['execute'],
    'MAD4B_SCP_Host_Visual_Strategy_Proposal' => ['execute'],
];
foreach ($required as $class => $methods) {
    if (class_exists($class, false) !== $expect) {
        fwrite(STDERR, "FAIL $scenario class $class unexpected load state" . PHP_EOL);
        exit(1);
    }
    if ($expect) foreach ($methods as $method) {
        if (!method_exists($class, $method)) {
            fwrite(STDERR, "FAIL missing method $class::$method" . PHP_EOL);
            exit(1);
        }
    }
}
echo "PASS: optional tourism media source gate $scenario" . PHP_EOL;
