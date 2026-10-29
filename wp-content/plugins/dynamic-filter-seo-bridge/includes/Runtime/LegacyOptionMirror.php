<?php
namespace DynamicFilterSEOBridge\Runtime;

defined('ABSPATH') || exit;

/**
 * Non-authorizing expand phase for historic persisted option keys.
 * Existing settings endpoints, permission checks and target mutexes remain
 * authoritative on legacy options until an independently reviewed cutover.
 * Neutral keys are read-only compatibility snapshots for new consumers.
 */
final class LegacyOptionMirror {
    private const MAP = array(
        'etg_dfsb_settings' => 'dfsb_settings',
        'etg_dfsb_dynamic_content_slots' => 'dfsb_dynamic_content_slots',
        'etg_dfsb_media_discovery' => 'dfsb_media_discovery',
    );
    public static function map(): array { return self::MAP; }
    public static function read(string $legacy, $fallback = array()) {
        if (!isset(self::MAP[$legacy]) || !function_exists('get_option')) return $fallback;
        $old = get_option($legacy, null);
        // An explicitly removed/absent legacy source is NOT automatically
        // resurrected from a stale snapshot. Cutover requires separate approval.
        if ($old === null) return $fallback;
        $new = get_option(self::MAP[$legacy], null);
        return ($new !== null && $new === $old) ? $new : $old;
    }
    public static function register(): void {
        add_action('updated_option', array(__CLASS__, 'updated'), 20, 3);
        add_action('added_option', array(__CLASS__, 'added'), 20, 2);
    }
    public static function added($option, $value): void {
        self::copy((string)$option, $value, null);
    }
    public static function updated($option, $oldValue, $value): void {
        self::copy((string)$option, $value, $oldValue);
    }
    private static function copy(string $source, $value, $previous = null): void {
        if (!isset(self::MAP[$source]) || !is_array($value)) return;
        $target = self::MAP[$source];
        // Migration never grants permission to write a source option.
        // Conflicting neutral copies are retained for explicit reconciliation.
        $current = get_option($target, null);
        if ($current !== null && $current !== $value && $current !== $previous) {
            do_action('dfsb/option-mirror-conflict', $source, $target);
            return;
        }
        if ($current !== $value) update_option($target, $value, false);
    }
}
