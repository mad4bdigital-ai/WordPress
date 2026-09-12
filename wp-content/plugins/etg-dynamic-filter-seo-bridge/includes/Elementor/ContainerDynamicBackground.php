<?php
namespace ETG\DynamicFilterSEOBridge\Elementor;

use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\DynamicTagRuntime;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\MediaAssetValidator;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

final class ContainerDynamicBackground {
    const SECTION_ID = 'etg_dfsb_dynamic_background';
    const MAX_SLIDES = 30;

    private $resolver;
    private $slots;

    public function __construct(PresentationResolver $resolver, ContentSlotRegistry $slots) {
        $this->resolver = $resolver;
        $this->slots = $slots;
    }

    public function register(): void {
        add_action('elementor/element/container/section_background/after_section_end', array($this, 'registerControls'), 10, 2);
        add_action('elementor/frontend/container/before_render', array($this, 'beforeRender'), 10, 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueueAssets'), 35);
    }

    public function enqueueAssets(): void {
        if (is_admin()) { return; }
        wp_enqueue_style(
            'etg-dfsb-container-background',
            plugins_url('assets/css/container-dynamic-background.css', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
            array(),
            defined('ETG_DFSB_ASSET_VERSION') ? ETG_DFSB_ASSET_VERSION : ETG_DFSB_VERSION
        );
        wp_enqueue_script(
            'etg-dfsb-container-background',
            plugins_url('assets/js/container-dynamic-background.js', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
            array(),
            defined('ETG_DFSB_ASSET_VERSION') ? ETG_DFSB_ASSET_VERSION : ETG_DFSB_VERSION,
            true
        );
    }

    public function registerControls($element, $args = array()): void {
        if (!is_object($element) || !method_exists($element, 'start_controls_section') || !class_exists('\\Elementor\\Controls_Manager')) { return; }
        $controls = '\\Elementor\\Controls_Manager';
        $mediaModes = ContentSlotRegistry::mediaModes();

        $element->start_controls_section(self::SECTION_ID, array(
            'label' => 'ETG Dynamic Background',
            'tab' => $controls::TAB_STYLE,
        ));
        $element->add_control('etg_dfsb_background_mode', array(
            'label' => 'Background Mode',
            'type' => $controls::SELECT,
            'options' => array('off' => 'Elementor Default', 'image' => 'ETG Dynamic Image', 'slideshow' => 'ETG Dynamic Slideshow'),
            'default' => 'off',
            'render_type' => 'template',
        ));
        $element->add_control('etg_dfsb_background_collection_mode', array(
            'label' => 'Collection Mode',
            'type' => $controls::SELECT,
            'options' => $mediaModes,
            'default' => 'balanced',
            'condition' => array('etg_dfsb_background_mode!' => 'off'),
        ));
        $element->add_control('etg_dfsb_background_suitability', array(
            'label' => 'Background Suitability',
            'type' => $controls::SELECT,
            'options' => array(
                'any' => 'Any healthy image',
                'landscape' => 'Landscape',
                'wide' => 'Wide Hero',
                'custom' => 'Custom dimensions',
            ),
            'default' => 'any',
            'condition' => array('etg_dfsb_background_mode!' => 'off'),
            'description' => 'Filters ETG media before rendering. Wide Hero requires at least 1200px width and 1.5 aspect ratio.',
        ));
        $element->add_control('etg_dfsb_background_min_width', array(
            'label' => 'Minimum Width (px)', 'type' => $controls::NUMBER, 'min' => 0, 'max' => 10000, 'step' => 10, 'default' => 1200,
            'condition' => array('etg_dfsb_background_mode!' => 'off', 'etg_dfsb_background_suitability' => 'custom'),
        ));
        $element->add_control('etg_dfsb_background_min_height', array(
            'label' => 'Minimum Height (px)', 'type' => $controls::NUMBER, 'min' => 0, 'max' => 10000, 'step' => 10, 'default' => 0,
            'condition' => array('etg_dfsb_background_mode!' => 'off', 'etg_dfsb_background_suitability' => 'custom'),
        ));
        $element->add_control('etg_dfsb_background_min_ratio', array(
            'label' => 'Minimum Aspect Ratio', 'type' => $controls::NUMBER, 'min' => 0, 'max' => 5, 'step' => 0.05, 'default' => 1.5,
            'condition' => array('etg_dfsb_background_mode!' => 'off', 'etg_dfsb_background_suitability' => 'custom'),
            'description' => 'Width ÷ height. 1.78 is approximately 16:9.',
        ));
        $element->add_control('etg_dfsb_background_no_suitable', array(
            'label' => 'When No Suitable Image Exists',
            'type' => $controls::SELECT,
            'options' => array('fallback' => 'Use configured fallback', 'hide' => 'Hide ETG background'),
            'default' => 'fallback',
            'condition' => array('etg_dfsb_background_mode!' => 'off', 'etg_dfsb_background_suitability!' => 'any'),
        ));
        $element->add_control('etg_dfsb_background_preview_url', array(
            'label' => 'Preview Filter URL',
            'type' => $controls::TEXT,
            'default' => '',
            'condition' => array('etg_dfsb_background_mode!' => 'off'),
            'description' => 'Editor preview only. Live rendering uses the real URL/AJAX state.',
            'label_block' => true,
        ));
        $element->add_control('etg_dfsb_background_gallery_source', array(
            'label' => 'Slideshow Source',
            'type' => $controls::SELECT,
            'options' => array(
                'collection' => 'ETG Collection (Recommended)',
                'dynamic_gallery' => 'External Dynamic Gallery (Initial only)',
            ),
            'default' => 'collection',
            'condition' => array('etg_dfsb_background_mode' => 'slideshow'),
            'description' => 'ETG Collection resolves Collection Mode directly. Use External only for a separate Gallery Dynamic Tag.',
        ));
        $element->add_control('etg_dfsb_background_image', array(
            'label' => 'Initial / Fallback Image',
            'type' => $controls::MEDIA,
            'dynamic' => array('active' => true),
            'condition' => array('etg_dfsb_background_mode' => 'image'),
            'description' => 'Optional. Dynamic Tags are supported. ETG live filtering still uses the selected Collection Mode.',
        ));
        $element->add_control('etg_dfsb_background_gallery', array(
            'label' => 'External Dynamic Gallery',
            'type' => $controls::GALLERY,
            'dynamic' => array('active' => true),
            'condition' => array(
                'etg_dfsb_background_mode' => 'slideshow',
                'etg_dfsb_background_gallery_source' => 'dynamic_gallery',
            ),
            'description' => 'Optional initial source only. Live JetSmartFilters refresh still uses Collection Mode.',
        ));
        $element->add_control('etg_dfsb_background_fallback_image', array(
            'label' => 'Slideshow Fallback Image',
            'type' => $controls::MEDIA,
            'dynamic' => array('active' => true),
            'condition' => array('etg_dfsb_background_mode' => 'slideshow'),
        ));
        $element->add_control('etg_dfsb_background_max_slides', array(
            'label' => 'Maximum Slides',
            'type' => $controls::NUMBER,
            'min' => 1,
            'max' => self::MAX_SLIDES,
            'default' => 8,
            'condition' => array('etg_dfsb_background_mode' => 'slideshow'),
        ));
        $element->add_control('etg_dfsb_background_min_slides', array(
            'label' => 'Minimum Slides',
            'type' => $controls::NUMBER,
            'min' => 1,
            'max' => self::MAX_SLIDES,
            'default' => 2,
            'condition' => array('etg_dfsb_background_mode' => 'slideshow'),
            'description' => 'Below this threshold the first healthy image remains visible; no broken slide is invented.',
        ));
        $element->add_control('etg_dfsb_background_playback', array(
            'label' => 'Slideshow Playback',
            'type' => $controls::SELECT,
            'options' => array('animated' => 'Animated slideshow', 'static' => 'Static — first suitable image'),
            'default' => 'animated',
            'condition' => array('etg_dfsb_background_mode' => 'slideshow'),
            'description' => 'Static keeps the selected collection but disables rotation and motion effects.',
        ));
        $element->add_control('etg_dfsb_background_autoplay', array(
            'label' => 'Autoplay',
            'type' => $controls::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
            'condition' => array('etg_dfsb_background_mode' => 'slideshow', 'etg_dfsb_background_playback' => 'animated'),
        ));
        $element->add_control('etg_dfsb_background_duration', array(
            'label' => 'Slide Duration (ms)',
            'type' => $controls::NUMBER,
            'min' => 1000,
            'max' => 30000,
            'step' => 250,
            'default' => 5000,
            'condition' => array('etg_dfsb_background_mode' => 'slideshow', 'etg_dfsb_background_playback' => 'animated', 'etg_dfsb_background_autoplay' => 'yes'),
        ));
        $element->add_control('etg_dfsb_background_transition', array(
            'label' => 'Transition',
            'type' => $controls::SELECT,
            'options' => array('fade' => 'Fade', 'crossfade' => 'Crossfade', 'slide' => 'Slide'),
            'default' => 'crossfade',
            'condition' => array('etg_dfsb_background_mode' => 'slideshow', 'etg_dfsb_background_playback' => 'animated'),
        ));
        $element->add_control('etg_dfsb_background_transition_duration', array(
            'label' => 'Transition Duration (ms)',
            'type' => $controls::NUMBER,
            'min' => 0,
            'max' => 5000,
            'step' => 100,
            'default' => 800,
            'condition' => array('etg_dfsb_background_mode' => 'slideshow', 'etg_dfsb_background_playback' => 'animated'),
        ));
        $element->add_control('etg_dfsb_background_fit', array(
            'label' => 'Image Fit',
            'type' => $controls::SELECT,
            'options' => array('cover' => 'Cover', 'contain' => 'Contain'),
            'default' => 'cover',
            'condition' => array('etg_dfsb_background_mode!' => 'off'),
        ));
        $element->add_control('etg_dfsb_background_position', array(
            'label' => 'Position',
            'type' => $controls::SELECT,
            'options' => array(
                'center center' => 'Center Center', 'center top' => 'Center Top', 'center bottom' => 'Center Bottom',
                'left center' => 'Left Center', 'right center' => 'Right Center',
            ),
            'default' => 'center center',
            'condition' => array('etg_dfsb_background_mode!' => 'off'),
        ));
        $element->add_control('etg_dfsb_background_ken_burns', array(
            'label' => 'Ken Burns',
            'type' => $controls::SWITCHER,
            'return_value' => 'yes',
            'default' => '',
            'condition' => array('etg_dfsb_background_mode' => 'slideshow', 'etg_dfsb_background_playback' => 'animated'),
        ));
        $element->add_control('etg_dfsb_background_pause_hover', array(
            'label' => 'Pause on Hover',
            'type' => $controls::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
            'condition' => array('etg_dfsb_background_mode' => 'slideshow', 'etg_dfsb_background_playback' => 'animated'),
        ));
        $element->add_control('etg_dfsb_background_random_start', array(
            'label' => 'Random Start',
            'type' => $controls::SWITCHER,
            'return_value' => 'yes',
            'default' => '',
            'condition' => array('etg_dfsb_background_mode' => 'slideshow', 'etg_dfsb_background_playback' => 'animated'),
        ));
        $element->add_control('etg_dfsb_background_overlay_color', array(
            'label' => 'ETG Overlay Color',
            'type' => $controls::COLOR,
            'default' => '',
            'condition' => array('etg_dfsb_background_mode!' => 'off'),
            'description' => 'Optional. Elementor Background Overlay remains supported independently.',
        ));
        $element->add_control('etg_dfsb_background_overlay_opacity', array(
            'label' => 'ETG Overlay Opacity',
            'type' => $controls::NUMBER,
            'min' => 0,
            'max' => 1,
            'step' => 0.05,
            'default' => 0,
            'condition' => array('etg_dfsb_background_mode!' => 'off'),
        ));
        foreach (array('desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile') as $device => $label) {
            $element->add_control('etg_dfsb_background_' . $device . '_behavior', array(
                'label' => $label . ' Behavior',
                'type' => $controls::SELECT,
                'options' => array('inherit' => 'Use selected mode', 'first_image' => 'First image only', 'disabled' => 'Disabled'),
                'default' => 'inherit',
                'condition' => array('etg_dfsb_background_mode!' => 'off'),
            ));
        }
        $element->add_control('etg_dfsb_background_group', array(
            'label' => 'AJAX Group',
            'type' => $controls::TEXT,
            'default' => 'auto',
            'condition' => array('etg_dfsb_background_mode!' => 'off'),
            'description' => 'Use auto for deterministic URL → active group → single group resolution, or provider/query_id for an explicit group.',
        ));
        $element->end_controls_section();
    }

    public function beforeRender($element): void {
        if (!is_object($element) || !method_exists($element, 'get_settings_for_display') || !method_exists($element, 'add_render_attribute')) { return; }
        $settings = $element->get_settings_for_display();
        $mode = sanitize_key((string)($settings['etg_dfsb_background_mode'] ?? 'off'));
        if (!in_array($mode, array('image', 'slideshow'), true)) { return; }

        $collection = sanitize_key((string)($settings['etg_dfsb_background_collection_mode'] ?? 'balanced'));
        if (!isset(ContentSlotRegistry::mediaModes()[$collection])) { $collection = 'balanced'; }
        $limit = max(1, min(self::MAX_SLIDES, (int)($settings['etg_dfsb_background_max_slides'] ?? 8)));
        if ('image' === $mode) { $limit = 1; }
        $minimum = max(1, min($limit, (int)($settings['etg_dfsb_background_min_slides'] ?? 2)));
        $context = $this->previewContext((string)($settings['etg_dfsb_background_preview_url'] ?? ''));
        $slotId = ContentSlotRegistry::backgroundSlotId($mode === 'image' ? 'image' : 'gallery', $collection);

        $gallerySource = sanitize_key((string)($settings['etg_dfsb_background_gallery_source'] ?? 'collection'));
        if (!in_array($gallerySource, array('collection', 'dynamic_gallery'), true)) { $gallerySource = 'collection'; }
        $playback = sanitize_key((string)($settings['etg_dfsb_background_playback'] ?? 'animated'));
        if (!in_array($playback, array('animated', 'static'), true)) { $playback = 'animated'; }
        $suitability = $this->suitabilityPolicy($settings);
        $noSuitable = sanitize_key((string)($settings['etg_dfsb_background_no_suitable'] ?? 'fallback'));
        if (!in_array($noSuitable, array('fallback', 'hide'), true)) { $noSuitable = 'fallback'; }

        $items = 'image' === $mode
            ? $this->initialImageItems($element, $slotId, $context)
            : $this->initialGalleryItems($element, $slotId, $context, $limit, $minimum, $gallerySource);
        $items = $this->filterSuitableItems($items, $suitability);
        if (!$items && 'fallback' === $noSuitable) { $items = $this->fallbackItems($element, $mode); }
        $fallbackItems = 'hide' === $noSuitable ? array() : $this->fallbackItems($element, $mode);

        $group = trim((string)($settings['etg_dfsb_background_group'] ?? 'auto'));
        if ('' === $group) { $group = 'auto'; }
        if ('auto' !== $group && !preg_match('/\A[A-Za-z0-9_-]+\/[A-Za-z0-9_-]+\z/', $group)) { $group = 'auto'; }

        $attrs = array(
            'class' => array('etg-dfsb-dynamic-background', 'etg-dfsb-dynamic-background--' . $mode),
            'data-etg-dfsb-background-mode' => $mode,
            'data-etg-dfsb-background-source' => 'slideshow' === $mode ? $gallerySource : 'collection',
            'data-etg-dfsb-media-slot' => $slotId,
            'data-etg-dfsb-media-target' => 'gallery',
            'data-etg-dfsb-group' => $group,
            'data-etg-dfsb-gallery' => $this->encode($items),
            'data-etg-dfsb-background-fallback' => $this->encode($fallbackItems),
            'data-etg-dfsb-background-max-slides' => (string)$limit,
            'data-etg-dfsb-background-min-slides' => (string)$minimum,
            'data-etg-dfsb-background-playback' => $playback,
            'data-etg-dfsb-background-suitability' => $suitability['name'],
            'data-etg-dfsb-background-min-width' => (string)$suitability['min_width'],
            'data-etg-dfsb-background-min-height' => (string)$suitability['min_height'],
            'data-etg-dfsb-background-min-ratio' => (string)$suitability['min_ratio'],
            'data-etg-dfsb-background-no-suitable' => $noSuitable,
            'data-etg-dfsb-background-fit' => in_array((string)($settings['etg_dfsb_background_fit'] ?? 'cover'), array('cover', 'contain'), true) ? (string)$settings['etg_dfsb_background_fit'] : 'cover',
            'data-etg-dfsb-background-position' => $this->position((string)($settings['etg_dfsb_background_position'] ?? 'center center')),
            'data-etg-dfsb-background-autoplay' => 'animated' === $playback && !empty($settings['etg_dfsb_background_autoplay']) ? '1' : '0',
            'data-etg-dfsb-background-duration' => (string)max(1000, min(30000, (int)($settings['etg_dfsb_background_duration'] ?? 5000))),
            'data-etg-dfsb-background-transition' => $this->transition((string)($settings['etg_dfsb_background_transition'] ?? 'crossfade')),
            'data-etg-dfsb-background-transition-duration' => (string)max(0, min(5000, (int)($settings['etg_dfsb_background_transition_duration'] ?? 800))),
            'data-etg-dfsb-background-ken-burns' => 'animated' === $playback && !empty($settings['etg_dfsb_background_ken_burns']) ? '1' : '0',
            'data-etg-dfsb-background-pause-hover' => !empty($settings['etg_dfsb_background_pause_hover']) ? '1' : '0',
            'data-etg-dfsb-background-random-start' => 'animated' === $playback && !empty($settings['etg_dfsb_background_random_start']) ? '1' : '0',
            'data-etg-dfsb-background-overlay-color' => $this->color((string)($settings['etg_dfsb_background_overlay_color'] ?? '')),
            'data-etg-dfsb-background-overlay-opacity' => (string)max(0, min(1, (float)($settings['etg_dfsb_background_overlay_opacity'] ?? 0))),
            'data-etg-dfsb-background-desktop' => $this->behavior((string)($settings['etg_dfsb_background_desktop_behavior'] ?? 'inherit')),
            'data-etg-dfsb-background-tablet' => $this->behavior((string)($settings['etg_dfsb_background_tablet_behavior'] ?? 'inherit')),
            'data-etg-dfsb-background-mobile' => $this->behavior((string)($settings['etg_dfsb_background_mobile_behavior'] ?? 'inherit')),
        );
        $element->add_render_attribute('_wrapper', $attrs);
    }

    private function initialImageItems($element, string $slotId, array $context = null): array {
        $value = method_exists($element, 'get_settings_for_display') ? $element->get_settings_for_display('etg_dfsb_background_image') : array();
        $image = $this->normalizeImage($value);
        if (!$this->hasImage($image)) { $image = $this->resolver->slotImage($slotId, $context); }
        return $this->hasImage($image) ? array($image) : array();
    }

    private function initialGalleryItems($element, string $slotId, array $context = null, int $limit = 8, int $minimum = 2, string $source = 'collection'): array {
        $source = in_array($source, array('collection', 'dynamic_gallery'), true) ? $source : 'collection';
        $items = array();

        if ('dynamic_gallery' === $source) {
            $value = method_exists($element, 'get_settings_for_display') ? $element->get_settings_for_display('etg_dfsb_background_gallery') : array();
            $items = $this->normalizeGallery($value, $limit);
        } else {
            $items = $this->resolver->slotGallery($slotId, $context, $limit);
        }

        if ($items && count($items) < $minimum) { $items = array($items[0]); }
        return array_slice($this->normalizeGallery($items, $limit), 0, $limit);
    }

    private function fallbackItems($element, string $mode): array {
        $key = 'image' === $mode ? 'etg_dfsb_background_image' : 'etg_dfsb_background_fallback_image';
        $value = method_exists($element, 'get_settings_for_display') ? $element->get_settings_for_display($key) : array();
        $image = $this->normalizeImage($value);
        return $this->hasImage($image) ? array($image) : array();
    }

    private function previewContext(string $url) {
        $url = trim($url);if ('' === $url) { return null; }
        try { $context = DynamicTagRuntime::previewContext($url);return is_array($context) && $context ? $context : null; } catch (\Throwable $error) { return null; }
    }

    private function normalizeGallery($value, int $limit): array {
        $out = array();$seen = array();
        foreach (array_slice(is_array($value) ? $value : array(), 0, $limit) as $item) {
            $image = $this->normalizeImage($item);if (!$this->hasImage($image)) { continue; }
            $key = !empty($image['id']) ? 'id:' . (int)$image['id'] : 'url:' . (string)$image['url'];if (isset($seen[$key])) { continue; }
            $seen[$key] = true;$out[] = $image;
        }
        return $out;
    }

    private function normalizeImage($value): array {
        $id = 0;$url = '';$width = 0;$height = 0;$ratio = 0.0;$dimensionsSource = 'unknown';
        if (is_array($value)) {
            $id = isset($value['id']) && is_numeric($value['id']) ? max(0, (int)$value['id']) : 0;
            $url = isset($value['url']) && is_scalar($value['url']) ? trim((string)$value['url']) : '';
            $width = isset($value['width']) && is_numeric($value['width']) ? max(0, (int)$value['width']) : 0;
            $height = isset($value['height']) && is_numeric($value['height']) ? max(0, (int)$value['height']) : 0;
            $ratio = isset($value['aspect_ratio']) && is_numeric($value['aspect_ratio']) ? max(0.0, (float)$value['aspect_ratio']) : ($height > 0 ? round($width / $height, 4) : 0.0);
            $dimensionsSource = isset($value['dimensions_source']) && is_scalar($value['dimensions_source']) ? sanitize_key((string)$value['dimensions_source']) : 'unknown';
        } elseif (is_numeric($value)) {
            $id = max(0, (int)$value);
        } elseif (is_string($value)) {
            $url = trim($value);
        }

        if ($id > 0) {
            $health = MediaAssetValidator::inspect($id);
            if (empty($health['valid'])) { return array('id' => 0, 'url' => '', 'width' => 0, 'height' => 0, 'aspect_ratio' => 0.0, 'dimensions_source' => 'invalid'); }
            $url = isset($health['url']) && is_scalar($health['url']) ? trim((string)$health['url']) : '';
            $width = max(0, (int)($health['width'] ?? $width));
            $height = max(0, (int)($health['height'] ?? $height));
            $ratio = isset($health['aspect_ratio']) && is_numeric($health['aspect_ratio']) ? max(0.0, (float)$health['aspect_ratio']) : ($height > 0 ? round($width / $height, 4) : $ratio);
            $dimensionsSource = sanitize_key((string)($health['dimensions_source'] ?? $dimensionsSource));
            if ('' === $url && function_exists('wp_get_attachment_image_url')) {
                $candidate = wp_get_attachment_image_url($id, 'full');
                $url = is_string($candidate) ? trim($candidate) : '';
            }
        }

        if ('' !== $url && function_exists('esc_url_raw')) { $url = (string)esc_url_raw($url); }
        if ('' === $url) { return array('id' => 0, 'url' => '', 'width' => 0, 'height' => 0, 'aspect_ratio' => 0.0, 'dimensions_source' => 'missing_url'); }
        return array('id' => $id, 'url' => $url, 'width' => $width, 'height' => $height, 'aspect_ratio' => $ratio, 'dimensions_source' => $dimensionsSource);
    }

    private function suitabilityPolicy(array $settings): array {
        $name = sanitize_key((string)($settings['etg_dfsb_background_suitability'] ?? 'any'));
        if (!in_array($name, array('any','landscape','wide','custom'), true)) { $name = 'any'; }
        $minWidth = 0;$minHeight = 0;$minRatio = 0.0;
        if ('landscape' === $name) { $minRatio = 1.15; }
        elseif ('wide' === $name) { $minWidth = 1200;$minRatio = 1.5; }
        elseif ('custom' === $name) {
            $minWidth = max(0, min(10000, (int)($settings['etg_dfsb_background_min_width'] ?? 0)));
            $minHeight = max(0, min(10000, (int)($settings['etg_dfsb_background_min_height'] ?? 0)));
            $minRatio = max(0.0, min(5.0, (float)($settings['etg_dfsb_background_min_ratio'] ?? 0)));
        }
        return array('name'=>$name,'min_width'=>$minWidth,'min_height'=>$minHeight,'min_ratio'=>rtrim(rtrim(number_format($minRatio,4,'.',''),'0'),'.'));
    }

    private function filterSuitableItems(array $items, array $policy): array {
        if ('any' === (string)($policy['name'] ?? 'any')) { return $items; }
        $out = array();foreach($items as$item){$item=is_array($item)?$item:array();if($this->imageSuitable($item,$policy)){$out[]=$item;}}return$out;
    }

    private function imageSuitable(array $image, array $policy): bool {
        if (!$this->hasImage($image)) { return false; }
        if ('any' === (string)($policy['name'] ?? 'any')) { return true; }
        $width=max(0,(int)($image['width']??0));$height=max(0,(int)($image['height']??0));$ratio=isset($image['aspect_ratio'])&&is_numeric($image['aspect_ratio'])?(float)$image['aspect_ratio']:($height>0?$width/$height:0.0);
        if ($width <= 0 || $height <= 0 || $ratio <= 0) { return false; }
        return $width >= (int)($policy['min_width']??0) && $height >= (int)($policy['min_height']??0) && $ratio >= (float)($policy['min_ratio']??0);
    }

    private function hasImage(array $image): bool { return !empty($image['id']) || !empty($image['url']); }
    private function transition(string $value): string { return in_array($value, array('fade', 'crossfade', 'slide'), true) ? $value : 'crossfade'; }
    private function color(string $value): string {
        $value=trim($value);if(''===$value){return'';}if(function_exists('sanitize_hex_color')){return(string)(sanitize_hex_color($value)?:'');}
        return preg_match('/\A#[A-Fa-f0-9]{6}\z/',$value)?$value:'';
    }
    private function behavior(string $value): string { return in_array($value, array('inherit', 'first_image', 'disabled'), true) ? $value : 'inherit'; }
    private function position(string $value): string { return in_array($value, array('center center', 'center top', 'center bottom', 'left center', 'right center'), true) ? $value : 'center center'; }
    private function encode(array $value): string { $json = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_SLASHES) : json_encode($value, JSON_UNESCAPED_SLASHES);return is_string($json) ? $json : '[]'; }
}
