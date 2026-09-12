<?php
namespace ETG\DynamicFilterSEOBridge\Elementor;

/**
 * Presentation-only archive hero normalization.
 *
 * Elementor caches parsed display settings independently from Base_Object
 * settings. The preset applies a best-effort in-memory normalization, then
 * invalidates Elementor's parsed display cache before ContainerDynamicBackground
 * reads get_settings_for_display(). A pure normalizer remains available for
 * regression/evidence checks. Nothing here persists Elementor data.
 */
final class ArchiveHeroPreset {
    const MARKER_CLASS = 'etg-dfsb-archive-hero';
    const DISABLE_CLASS = 'etg-dfsb-background-off';

    public function register(): void {
        add_action('elementor/frontend/container/before_render', array($this, 'beforeRender'), 5, 1);
        // Elementor can pre-warm parsed_active_settings before before_render.
        // Invalidate that cache only for ETG archive marker/kill-switch Containers
        // after our in-memory normalization and before ContainerDynamicBackground.
        add_action('elementor/frontend/container/before_render', array($this, 'invalidateDisplayCache'), 8, 1);
    }

    public function beforeRender($element): void {
        if (!is_object($element) || !method_exists($element, 'set_settings')) { return; }
        $raw = self::rawSettings($element);
        $normalized = self::normalizeForRender($element, $raw);

        // Apply the normalized ETG controls to Base_Object settings. The priority-8
        // cache invalidation below forces Elementor to rebuild display settings from
        // these values before ContainerDynamicBackground runs at priority 10.
        foreach ($normalized as $key => $value) {
            if (0 !== strpos((string)$key, 'etg_dfsb_background_')) { continue; }
            if (!array_key_exists($key, $raw) || $raw[$key] !== $value) {
                $element->set_settings($key, $value);
            }
        }

        $mode = sanitize_key((string)($normalized['etg_dfsb_background_mode'] ?? 'off'));
        $origin = sanitize_key((string)($normalized['__etg_dfsb_background_origin'] ?? ''));
        if (method_exists($element, 'add_render_attribute') && ('archive_background_off' === $origin || in_array($mode, array('image', 'slideshow'), true))) {
            $element->add_render_attribute('_wrapper', array(
                'data-etg-dfsb-background-origin' => $origin ?: 'explicit',
                'data-etg-dfsb-background-scope' => 'container',
            ));
        }
    }

    public function invalidateDisplayCache($element): void {
        if (!is_object($element)) { return; }
        $raw = self::rawSettings($element);
        if (!self::hasClass($raw, self::MARKER_CLASS) && !self::hasClass($raw, self::DISABLE_CLASS)) { return; }

        $class = new \ReflectionObject($element);
        while ($class) {
            if ($class->hasProperty('parsed_active_settings')) {
                try {
                    $property = $class->getProperty('parsed_active_settings');
                    $property->setAccessible(true);
                    $property->setValue($element, null);
                } catch (\Throwable $error) {
                    // Fail closed: the downstream Container still sees Elementor's
                    // original display settings rather than a partially patched cache.
                }
                return;
            }
            $class = $class->getParentClass();
        }
    }

    /**
     * Normalize render settings without mutating Elementor persistence or depending
     * on Elementor's parsed_active_settings cache.
     */
    public static function normalizeForRender($element, array $display): array {
        $raw = self::rawSettings($element);

        // Persisted scalar ETG controls are the authority. Do not copy dynamic
        // image/gallery controls here because get_settings_for_display() is the
        // layer that resolves their Dynamic Tags.
        $authoritativeKeys = array(
            'etg_dfsb_background_mode', 'etg_dfsb_background_collection_mode',
            'etg_dfsb_background_suitability', 'etg_dfsb_background_min_width',
            'etg_dfsb_background_min_height', 'etg_dfsb_background_min_ratio',
            'etg_dfsb_background_no_suitable', 'etg_dfsb_background_preview_url',
            'etg_dfsb_background_gallery_source', 'etg_dfsb_background_max_slides',
            'etg_dfsb_background_min_slides', 'etg_dfsb_background_playback',
            'etg_dfsb_background_autoplay', 'etg_dfsb_background_duration',
            'etg_dfsb_background_transition', 'etg_dfsb_background_transition_duration',
            'etg_dfsb_background_fit', 'etg_dfsb_background_position',
            'etg_dfsb_background_ken_burns', 'etg_dfsb_background_pause_hover',
            'etg_dfsb_background_random_start', 'etg_dfsb_background_overlay_color',
            'etg_dfsb_background_overlay_opacity', 'etg_dfsb_background_desktop_behavior',
            'etg_dfsb_background_tablet_behavior', 'etg_dfsb_background_mobile_behavior',
            'etg_dfsb_background_group',
        );
        foreach ($authoritativeKeys as $key) {
            if (array_key_exists($key, $raw)) { $display[$key] = $raw[$key]; }
        }

        if (self::hasClass($raw, self::DISABLE_CLASS)) {
            $display['etg_dfsb_background_mode'] = 'off';
            $display['__etg_dfsb_background_origin'] = 'archive_background_off';
            $display['__etg_dfsb_background_scope'] = 'container';
            return $display;
        }

        $marked = self::hasClass($raw, self::MARKER_CLASS);
        $hasSavedMode = array_key_exists('etg_dfsb_background_mode', $raw);
        $mode = sanitize_key((string)($hasSavedMode ? $raw['etg_dfsb_background_mode'] : ($display['etg_dfsb_background_mode'] ?? 'off')));
        $origin = 'explicit';

        if (!$hasSavedMode && $marked) {
            $mode = 'slideshow';
            $origin = 'archive_hero_marker';
            $defaults = array(
                'etg_dfsb_background_mode' => 'slideshow',
                'etg_dfsb_background_collection_mode' => 'balanced',
                'etg_dfsb_background_max_slides' => 8,
                'etg_dfsb_background_min_slides' => 2,
                'etg_dfsb_background_gallery_source' => 'collection',
                'etg_dfsb_background_playback' => 'animated',
                'etg_dfsb_background_no_suitable' => 'fallback',
                'etg_dfsb_background_autoplay' => 'yes',
                'etg_dfsb_background_duration' => 5000,
                'etg_dfsb_background_transition' => 'crossfade',
                'etg_dfsb_background_transition_duration' => 800,
                'etg_dfsb_background_ken_burns' => '',
                'etg_dfsb_background_pause_hover' => 'yes',
                'etg_dfsb_background_random_start' => '',
                'etg_dfsb_background_group' => 'auto',
            );
            foreach ($defaults as $key => $value) {
                if (!array_key_exists($key, $raw)) { $display[$key] = $value; }
            }
        }

        $display['etg_dfsb_background_mode'] = $mode;
        if (!in_array($mode, array('image', 'slideshow'), true)) { return $display; }

        if (!array_key_exists('etg_dfsb_background_suitability', $raw)) {
            $display['etg_dfsb_background_suitability'] = 'slideshow' === $mode ? 'wide' : 'any';
        }
        if (!array_key_exists('etg_dfsb_background_fit', $raw)) {
            $display['etg_dfsb_background_fit'] = 'cover';
        }
        if (!array_key_exists('etg_dfsb_background_position', $raw)) {
            $display['etg_dfsb_background_position'] = 'center center';
        }
        $display['__etg_dfsb_background_origin'] = $origin;
        $display['__etg_dfsb_background_scope'] = 'container';
        return $display;
    }

    private static function rawSettings($element): array {
        if (!is_object($element)) { return array(); }
        if (method_exists($element, 'get_data')) {
            try {
                $data = $element->get_data('settings');
                if (is_array($data)) { return $data; }
            } catch (\Throwable $error) {
                // Fall through to the public raw-data or settings APIs.
            }
        }
        if (method_exists($element, 'get_raw_data')) {
            try {
                $data = $element->get_raw_data(false);
                if (is_array($data) && isset($data['settings']) && is_array($data['settings'])) { return $data['settings']; }
            } catch (\Throwable $error) {
                // Older Elementor stacks can fall back to get_settings().
            }
        }
        if (method_exists($element, 'get_settings')) {
            $settings = $element->get_settings();
            return is_array($settings) ? $settings : array();
        }
        return array();
    }

    private static function hasClass(array $raw, string $needle): bool {
        foreach (array('_css_classes', 'css_classes', 'css_class') as $key) {
            if (!isset($raw[$key]) || !is_scalar($raw[$key])) { continue; }
            $value = trim((string)$raw[$key]);
            if ('' === $value) { continue; }
            foreach (preg_split('/\s+/', $value) as $className) {
                if ($needle === trim((string)$className)) { return true; }
            }
        }
        return false;
    }
}
