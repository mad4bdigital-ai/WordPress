<?php
// Hermetic negative-path regression for the Staging owner opt-in Cron handoff.
define('ABSPATH', __DIR__ . '/');
function add_action($hook, $callback, $priority = 10) {}
function is_wp_error($v) { return false; }
function absint($n) { return abs((int)$n); }
function get_option($key, $default = null) { return array(); }
function wp_next_scheduled($hook) { return $GLOBALS['old_event']; }
function wp_schedule_single_event($when, $hook, $args, $wp_error = false) {
    $GLOBALS['schedule_called']++;
    return $GLOBALS['can_schedule'];
}
function wp_unschedule_event($when, $hook, $args = array(), $wp_error = false) {
    $GLOBALS['unscheduled']++;
    return true;
}
function wp_clear_scheduled_hook($hook) { $GLOBALS['cleared']++; return true; }
require dirname(__DIR__) . '/includes/class-mad4b-scp-runtime-convergence.php';
function check_case($v, $message) {
    if (!$v) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
$method = new ReflectionMethod('MAD4B_SCP_Runtime_Convergence', 'schedule_resume');
$method->setAccessible(true);
$GLOBALS['old_event'] = time() + 7;
$GLOBALS['can_schedule'] = false;
$GLOBALS['schedule_called'] = 0;
$GLOBALS['unscheduled'] = 0;
$GLOBALS['cleared'] = 0;
$r = $method->invoke(null, time() + 70);
check_case($r === false && $GLOBALS['schedule_called'] === 1 &&
    $GLOBALS['unscheduled'] === 0 && $GLOBALS['cleared'] === 0,
    'Failed replacement must preserve previously scheduled event');
$GLOBALS['can_schedule'] = true;
$r = $method->invoke(null, time() + 70);
check_case($r === true && $GLOBALS['unscheduled'] === 1 && $GLOBALS['cleared'] === 0,
    'Successful replacement unschedules only the old event');
$GLOBALS['old_event'] = false;
$GLOBALS['can_schedule'] = false;
$r = $method->invoke(null, time() + 70);
check_case($r === false, 'No phantom success when no event exists');
$source = file_get_contents(dirname(__DIR__) . '/includes/class-mad4b-scp-runtime-convergence.php');
$start = strpos($source, 'public static function resume_after_automation_policy_opt_in()');
$end = strpos($source, 'public static function maybe_schedule_pending()', $start);
$body = substr($source, $start, $end - $start);
check_case(strpos($body, 'ALREADY_CONVERGED_HISTORICAL_CHECKPOINT') !== false,
    'Do not requeue an already-converged historical checkpoint');
check_case(strpos($body, 'self::schedule_resume(') < strpos($body, 'self::automatic_checkpoint_cas('),
    'Cron must be scheduled before checkpoint activation');
check_case(strpos($body, "'checkpoint_unchanged'=>true") !== false,
    'Failed schedule must not activate an executable checkpoint');
echo "RUNTIME_SAFE_RESUME_HANDOFF: PASS\n";
