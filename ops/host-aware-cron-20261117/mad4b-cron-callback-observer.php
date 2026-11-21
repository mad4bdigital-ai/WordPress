<?php
/**
 * Plugin Name: MAD4B Bounded WP Cron Callback Observatory (Staging only)
 * Version: 1.0.0
 * Description: Diagnose slow WordPress Cron event callbacks without mutating schedules or event args.
 */
defined('ABSPATH') || exit;
if (!defined('DOING_CRON') || DOING_CRON !== true
    || wp_get_environment_type() !== 'staging'
    || (string) wp_parse_url((string) get_option('home'), PHP_URL_HOST) !== 'tours.dev.mad4b.com') {
    return;
}
final class MAD4B_WP_Cron_Callback_Observer_V1 {
    private static $started = [];
    private static $runId = '';
    private static $eventCount = 0;
    private static $last = '';

    private static function output(string $event, array $details): void {
        // No hook arguments, visitor identifiers, URLs, auth headers or PII.
        $row = array_merge([
            'contract'=>'mad4b.wp-cron-event-observation.v1',
            'event'=>$event,
            'entry_point'=>(defined('WP_CLI') && WP_CLI) ? 'wpcli' : 'wp_cron_native',
            'run_id'=>self::$runId,
            'time_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
        ],$details);
        error_log('MAD4B_WP_CRON_TRACE '.wp_json_encode($row, JSON_UNESCAPED_SLASHES));
    }
    public static function begin(): void {
        $hook = (string)current_filter();
        if ($hook==='' || !preg_match('~^[a-zA-Z0-9_/.-]{1,150}$~D',$hook)) return;
        self::$started[$hook] = microtime(true);
        self::$last = $hook;
        self::$eventCount++;
        self::output('hook_start',['hook'=>$hook,'seq'=>self::$eventCount]);
    }
    public static function end(): void {
        $hook = (string)current_filter();
        if (!isset(self::$started[$hook])) return;
        $elapsed = round((microtime(true) - self::$started[$hook])*1000,1);
        unset(self::$started[$hook]);
        self::output('hook_end',['hook'=>$hook,'elapsed_ms'=>$elapsed]);
    }
    public static function shutdown(): void {
        foreach (self::$started as $hook=>$start) {
            self::output('hook_unfinished',['hook'=>$hook,'elapsed_ms'=>round((microtime(true)-$start)*1000,1)]);
        }
        self::output('process_finish',['hooks_started'=>self::$eventCount,'last_hook'=>self::$last,'php_peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,1),'aborted'=>boolval(self::$started)]);
    }
    public static function boot(): void {
        // Cron is a native PHP request; only scheduled events are subscribed.
        // All other hooks and front-end requests remain untouched.
        self::$runId = bin2hex(random_bytes(6));
        $events = _get_cron_array();
        if (!is_array($events)) return;
        $hooks=[];
        foreach ($events as $jobs) foreach ($jobs as $hook=>$instances) {
            if (is_string($hook) && preg_match('~^[a-zA-Z0-9_/.-]{1,150}$~D',$hook)) $hooks[$hook]=1;
        }
        if (count($hooks)>300) return;
        foreach (array_keys($hooks) as $hook) {
            add_action($hook,[__CLASS__,'begin'],-1000000000,0);
            add_action($hook,[__CLASS__,'end'],1000000000,0);
        }
        register_shutdown_function([__CLASS__,'shutdown']);
        self::output('process_start',['cron_hooks_monitored'=>count($hooks)]);
    }
}
add_action('init',['MAD4B_WP_Cron_Callback_Observer_V1','boot'],99999);
