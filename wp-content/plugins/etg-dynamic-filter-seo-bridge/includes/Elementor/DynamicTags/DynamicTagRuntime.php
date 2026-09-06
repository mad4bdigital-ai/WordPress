<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

/**
 * Request-scoped dependency registry for Elementor Dynamic Tags.
 *
 * Elementor stores only each registered tag class and later recreates it with
 * new $tag_class( [ 'settings' => ..., 'id' => ... ] ). Dynamic Tag classes
 * therefore must not require ETG service arguments in their constructors.
 */
final class DynamicTagRuntime {
    private static $resolver;
    private static $slots;
    private static $catalogProvider;
    private static $previewContextProvider;
    private static $catalogCache = null;

    public static function configure(PresentationResolver $resolver, ContentSlotRegistry $slots, callable $catalogProvider, callable $previewContextProvider = null): void {
        self::$resolver = $resolver;
        self::$slots = $slots;
        self::$catalogProvider = $catalogProvider;
        self::$previewContextProvider = $previewContextProvider;
        self::$catalogCache = null;
    }

    public static function resolver(): ?PresentationResolver {
        return self::$resolver instanceof PresentationResolver ? self::$resolver : null;
    }

    public static function slots(): ?ContentSlotRegistry {
        return self::$slots instanceof ContentSlotRegistry ? self::$slots : null;
    }

    public static function catalog(): array {
        if ( null !== self::$catalogCache ) { return self::$catalogCache; }
        if (!is_callable(self::$catalogProvider)) { self::$catalogCache = array(); return self::$catalogCache; }
        try {
            $catalog = call_user_func(self::$catalogProvider);
            self::$catalogCache = is_array($catalog) ? $catalog : array();
        } catch (\Throwable $error) {
            self::$catalogCache = array();
        }
        return self::$catalogCache;
    }

    public static function previewContext(string $previewUrl): ?array {
        $previewUrl = trim($previewUrl);
        if ('' === $previewUrl || !is_callable(self::$previewContextProvider)) { return null; }
        try {
            $context = call_user_func(self::$previewContextProvider, $previewUrl);
            return is_array($context) && $context ? $context : null;
        } catch (\Throwable $error) {
            return null;
        }
    }

    public static function tokenOptions(): array {
        $options = array();
        foreach ((array) (self::catalog()['tokens'] ?? array()) as $token => $meta) {
            $token = strtolower(trim((string) $token));
            if ('' === $token) { continue; }
            $options[$token] = (string) ($meta['label'] ?? $token) . ' [' . $token . ']';
        }
        if (!$options) { $options = array('title'=>'Filter Title [title]'); }
        return $options;
    }

    public static function tokenTypes(): array {
        $types = array();
        foreach ((array) (self::catalog()['tokens'] ?? array()) as $token => $meta) {
            $token = strtolower(trim((string) $token));
            if ('' === $token) { continue; }
            $types[$token] = (string) ($meta['type'] ?? 'text');
        }
        if (!$types) { $types = array('title'=>'text'); }
        return $types;
    }

    public static function slotOptions(): array {
        $slots = self::slots();
        $options = array();
        if (!$slots) { return $options; }
        foreach ($slots->all() as $slot) {
            $id = sanitize_key((string) ($slot['id'] ?? ''));
            if ('' === $id) { continue; }
            $origin = (string) ($slot['origin'] ?? 'custom');
            $suffix = 'built_in' === $origin ? ' · built-in' : ('override' === $origin ? ' · customized' : '');
            $options[$id] = (string) ($slot['label'] ?? $id) . $suffix . ' [' . $id . ']';
        }
        return $options;
    }

    public static function groupOptions(): array {
        $options = array();
        foreach ((array) (self::catalog()['groups'] ?? array()) as $key => $meta) {
            $key = self::normalizeGroup((string) $key);
            if ('' === $key) { continue; }
            $profiles = array_values(array_filter(array_map('sanitize_key', (array) ($meta['profile_ids'] ?? array()))));
            $label = $key;
            if ($profiles) { $label .= ' · ' . implode(', ', $profiles); }
            $options[$key] = $label;
        }
        ksort($options, SORT_STRING);
        return $options;
    }

    public static function normalizeGroup(string $group): string {
        $group = trim($group);
        if ('' === $group || 'auto' === strtolower($group)) { return ''; }
        $parts = explode('/', $group, 2);
        if (2 !== count($parts)) { return ''; }
        $provider = sanitize_key((string) $parts[0]);
        $queryId = sanitize_key((string) $parts[1]);
        return '' !== $provider && '' !== $queryId ? $provider . '/' . $queryId : '';
    }

    public static function roleOptions(): array {
        $roles = array();
        foreach (array_keys((array) (self::catalog()['tokens'] ?? array())) as $token) {
            if (0 !== strpos((string) $token, 'term:')) { continue; }
            $parts = explode(':', (string) $token, 3);
            if (3 !== count($parts)) { continue; }
            $role = sanitize_key((string) $parts[1]);
            if ('' === $role) { continue; }
            $roles[$role] = ucwords(str_replace(array('_','-'), ' ', $role));
        }
        if (!$roles) { $roles = array('location'=>'Location','tour_type'=>'Tour Type','style'=>'Style'); }
        ksort($roles, SORT_STRING);
        return $roles;
    }
}
