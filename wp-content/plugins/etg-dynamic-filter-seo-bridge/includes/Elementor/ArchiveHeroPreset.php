<?php
namespace ETG\DynamicFilterSEOBridge\Elementor;

/**
 * Presentation-only archive hero normalization.
 *
 * This layer never writes Elementor settings. It only supplies safe in-memory
 * defaults before ContainerDynamicBackground reads the Container on front-end
 * render. Explicitly saved ETG Background Mode always wins.
 */
final class ArchiveHeroPreset {
    const MARKER_CLASS = 'etg-dfsb-archive-hero';

    public function register(): void {
        add_action('elementor/frontend/container/before_render', array($this, 'beforeRender'), 5, 1);
    }

    public function beforeRender($element): void {
        if (!is_object($element) || !method_exists($element, 'get_settings_for_display') || !method_exists($element, 'set_settings')) { return; }
        $raw = $this->rawSettings($element);
        $display = (array)$element->get_settings_for_display();
        $marked = $this->hasMarker($raw, $display);
        $hasSavedMode = array_key_exists('etg_dfsb_background_mode', $raw);
        $mode = sanitize_key((string)($raw['etg_dfsb_background_mode'] ?? $display['etg_dfsb_background_mode'] ?? 'off'));
        $origin = 'explicit';

        if (!$hasSavedMode && $marked) {
            $mode = 'slideshow';
            $origin = 'archive_hero_marker';
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_mode', 'slideshow');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_collection_mode', 'balanced');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_max_slides', 8);
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_min_slides', 2);
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_gallery_source', 'collection');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_playback', 'animated');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_no_suitable', 'fallback');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_autoplay', 'yes');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_duration', 5000);
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_transition', 'crossfade');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_transition_duration', 800);
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_ken_burns', '');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_pause_hover', 'yes');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_random_start', '');
            $this->setIfMissing($element, $raw, 'etg_dfsb_background_group', 'auto');
        }

        if (!in_array($mode, array('image', 'slideshow'), true)) { return; }

        // Backgrounds are crop-sensitive. A slideshow uses a Wide Hero policy
        // unless the editor explicitly persisted another suitability policy.
        if (!array_key_exists('etg_dfsb_background_suitability', $raw)) {
            $element->set_settings('etg_dfsb_background_suitability', 'slideshow' === $mode ? 'wide' : 'any');
        }
        // Normalize unsaved controls before ContainerDynamicBackground reads
        // them, preventing undefined-index warnings without persisting values.
        if (!array_key_exists('etg_dfsb_background_fit', $raw)) {
            $element->set_settings('etg_dfsb_background_fit', 'cover');
        }
        if (!array_key_exists('etg_dfsb_background_position', $raw)) {
            $element->set_settings('etg_dfsb_background_position', 'center center');
        }

        if (method_exists($element, 'add_render_attribute')) {
            $element->add_render_attribute('_wrapper', array(
                'data-etg-dfsb-background-origin' => $origin,
                'data-etg-dfsb-background-scope' => 'container',
            ));
        }
    }

    private function rawSettings($element): array {
        if (method_exists($element, 'get_data')) {
            try {
                $data = $element->get_data('settings');
                if (is_array($data)) { return $data; }
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

    private function setIfMissing($element, array $raw, string $key, $value): void {
        if (!array_key_exists($key, $raw)) { $element->set_settings($key, $value); }
    }

    private function hasMarker(array $raw, array $display): bool {
        foreach (array('_css_classes', 'css_classes', 'css_class') as $key) {
            $value = '';
            if (isset($raw[$key]) && is_scalar($raw[$key])) { $value = trim((string)$raw[$key]); }
            elseif (isset($display[$key]) && is_scalar($display[$key])) { $value = trim((string)$display[$key]); }
            if ('' === $value) { continue; }
            foreach (preg_split('/\s+/', $value) as $className) {
                if (self::MARKER_CLASS === trim((string)$className)) { return true; }
            }
        }
        return false;
    }
}
