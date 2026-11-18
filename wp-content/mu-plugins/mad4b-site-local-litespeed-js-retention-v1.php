<?php
/**
 * Plugin Name: MAD4B Site-Owned LiteSpeed JS Retention
 * Description: Hourly bounded cleanup for aged generated JS cache on ETG Staging only.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) return;
final class MAD4B_Site_Local_Litespeed_JS_Retention_V1 {
    const HOOK = 'mad4b_site_local_litespeed_js_retention_v1';
    const MAX_FILES = 600;
    const MAX_SECONDS = 9;
    const RETENTION_SECONDS = 7 * 86400;

    public static function allowed(): bool {
        return wp_get_environment_type() === 'staging'
            && (string) wp_parse_url(home_url('/'), PHP_URL_HOST) === 'staging.egypttourgates.com';
    }
    public static function register(): void {
        if (!self::allowed()) return;
        add_action(self::HOOK, [__CLASS__, 'prune']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 3600, 'hourly', self::HOOK);
        }
    }
    public static function prune(): array {
        $result = ['state' => 'DEFERRED', 'deleted' => 0, 'bytes_freed' => 0,
                   'examined' => 0, 'skipped' => 0];
        if (!self::allowed()) return $result;
        $path = WP_CONTENT_DIR . '/litespeed/js';
        $root = realpath($path);
        if ($root === false || !is_dir($root) || is_link($path)
            || $root !== $path || !is_readable($root) || !is_writable($root)) return $result;
        $start = microtime(true);
        $cutoff = time() - self::RETENTION_SECONDS;
        try {
            $dir = new DirectoryIterator($root);
            foreach ($dir as $entry) {
                if (microtime(true) - $start >= self::MAX_SECONDS || $result['deleted'] >= self::MAX_FILES) break;
                if ($entry->isDot() || !$entry->isFile() || $entry->isLink()) continue;
                $name = $entry->getFilename();
                if (!preg_match('/^[a-f0-9]{32}\.js$/D', $name)) continue;
                $result['examined']++;
                if ($entry->getMTime() >= $cutoff) { $result['skipped']++; continue; }
                $candidate = $root . '/' . $name;
                if (is_link($candidate) || realpath($candidate) !== $candidate) continue;
                $size = $entry->getSize();
                if (@unlink($candidate)) { $result['deleted']++; $result['bytes_freed'] += $size; }
            }
        } catch (UnexpectedValueException $e) { $result['state'] = 'ERROR'; return $result; }
        $result['state'] = 'COMPLETE';
        $result['duration_ms'] = round((microtime(true)-$start) * 1000, 1);
        // Local operational evidence only, never URLs, session or user data.
        update_option('mad4b_site_local_js_retention_last_run', $result, false);
        return $result;
    }
}
add_action('init', ['MAD4B_Site_Local_Litespeed_JS_Retention_V1','register'], 11);
