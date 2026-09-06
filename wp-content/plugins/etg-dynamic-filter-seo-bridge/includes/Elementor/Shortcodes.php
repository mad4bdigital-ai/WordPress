<?php
namespace ETG\DynamicFilterSEOBridge\Elementor;

require_once dirname( __DIR__ ) . '/Identifiers/PresentationToken.php';

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Identifiers\PresentationToken;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

final class Shortcodes {
    private $contextProvider;
    private $evidenceContextProvider;
    private $content;
    private $gallery;
    private $presentation;

    public function __construct(callable $contextProvider, ContentComposer $content, GalleryComposer $gallery, callable $evidenceContextProvider = null, PresentationResolver $presentation = null) {
        $this->contextProvider = $contextProvider;
        $this->content = $content;
        $this->gallery = $gallery;
        $this->evidenceContextProvider = $evidenceContextProvider;
        $this->presentation = $presentation;
    }

    public function register(): void {
        add_shortcode('etg_filter_h1', array($this, 'h1'));
        add_shortcode('etg_filter_intro', array($this, 'intro'));
        add_shortcode('etg_filter_sections', array($this, 'sections'));
        add_shortcode('etg_filter_gallery', array($this, 'gallery'));
        add_shortcode('etg_filter_keyword', array($this, 'keyword'));
        add_shortcode('etg_filter_breadcrumb_context', array($this, 'breadcrumb'));
        add_shortcode('etg_filter_term', array($this, 'term'));
        add_shortcode('etg_filter_term_section', array($this, 'termSection'));
        add_shortcode('etg_dynamic_content', array($this, 'dynamicContent'));
        add_shortcode('etg_dynamic_value', array($this, 'dynamicValue'));
        add_shortcode('etg_dynamic_rows', array($this, 'dynamicRows'));
        add_shortcode('etg_dynamic_image', array($this, 'dynamicImage'));
        add_shortcode('etg_dynamic_background', array($this, 'dynamicBackground'));
        add_shortcode('etg_dynamic_gallery', array($this, 'dynamicGallery'));
    }

    public function h1(): string {
        $c = $this->context();
        return $this->renderable($c) ? esc_html($this->content->title($c)) : '';
    }

    public function intro(): string {
        $c = $this->context();
        if (!$this->renderable($c)) { return ''; }
        $intro = $this->content->intro($c);
        return '' !== trim(wp_strip_all_tags($intro)) ? wpautop(wp_kses_post($intro)) : '';
    }

    public function sections(): string {
        $c = $this->context();
        return $this->renderable($c) ? wp_kses_post($this->content->sections($c)) : '';
    }

    public function gallery($atts = array()): string {
        $c = $this->context();
        if (!$this->renderable($c)) { return ''; }
        $atts = shortcode_atts(array('mode'=>'combined','limit'=>'9','size'=>'large'), (array) $atts, 'etg_filter_gallery');
        return $this->gallery->render($c, $atts);
    }

    public function keyword(): string {
        $c = $this->context();
        return $this->renderable($c) ? esc_html($this->content->keyword($c)) : '';
    }

    public function breadcrumb(): string {
        $c = $this->context();
        if (!$this->renderable($c)) { return ''; }
        $names = array();
        foreach ($this->content->breadcrumbLabels($c) as $name) { $names[] = esc_html((string) $name); }
        return implode(' &rsaquo; ', $names);
    }

    public function term($atts = array()): string {
        $c = $this->context();
        if (!$this->renderable($c)) { return ''; }
        $atts = shortcode_atts(array('role'=>'','taxonomy'=>'','field'=>'description','autop'=>'1','size'=>'full'), (array) $atts, 'etg_filter_term');
        $term = $this->termForAttributes($c, $atts);
        if (!$term) { return ''; }
        $field = sanitize_key((string) $atts['field']);
        $allowed = array('name','slug','description','short_description','seo_title','meta_description','focus_keyword','image_url','image_id','count','location_level');
        if (!in_array($field, $allowed, true)) { return ''; }
        if ('image_url' === $field) {
            $id = (int) ($term['image_id'] ?? 0);
            if (!$id || !function_exists('wp_get_attachment_image_url')) { return ''; }
            $url = wp_get_attachment_image_url($id, sanitize_key((string) $atts['size']) ?: 'full');
            return $url ? esc_url($url) : '';
        }
        $value = $term[$field] ?? '';
        if (is_array($value) || is_object($value)) { return ''; }
        $value = (string) $value;
        if (in_array($field, array('description','short_description'), true)) {
            $value = wp_kses_post($value);
            return $this->truthy($atts['autop']) && '' !== trim(wp_strip_all_tags($value)) ? wpautop($value) : $value;
        }
        return esc_html($value);
    }

    public function termSection($atts = array()): string {
        $c = $this->context();
        if (!$this->renderable($c)) { return ''; }
        $atts = shortcode_atts(array('role'=>'','taxonomy'=>'','field'=>'description','heading'=>'1','heading_level'=>'2','class'=>''), (array) $atts, 'etg_filter_term_section');
        $term = $this->termForAttributes($c, $atts);
        if (!$term) { return ''; }
        $field = sanitize_key((string) $atts['field']);
        if (!in_array($field, array('description','short_description'), true)) { return ''; }
        $body = (string) ($term[$field] ?? '');
        if ('' === trim(wp_strip_all_tags($body))) { return ''; }
        $level = max(2, min(6, (int) $atts['heading_level']));
        $class = 'etg-filter-term-section';
        $extra = sanitize_html_class((string) $atts['class']);
        if ($extra) { $class .= ' ' . $extra; }
        $html = '<section class="' . esc_attr($class) . '">';
        if ($this->truthy($atts['heading']) && !empty($term['name'])) { $html .= '<h' . $level . '>' . esc_html((string) $term['name']) . '</h' . $level . '>'; }
        $html .= '<div class="etg-filter-term-section__content">' . wpautop(wp_kses_post($body)) . '</div></section>';
        return $html;
    }

    public function dynamicContent($atts = array()): string {
        if (!$this->presentation) { return ''; }
        $atts = shortcode_atts(array('id'=>'','group'=>'','live'=>'1'), (array) $atts, 'etg_dynamic_content');
        $id = sanitize_key((string) $atts['id']);
        if ('' === $id) { return ''; }
        $value = $this->presentation->slot($id);
        $type = $this->presentation->slotType($id);
        $rendered = 'html' === $type ? wp_kses_post($value) : ('url' === $type ? esc_url($value) : esc_html($value));
        if (!$this->truthy($atts['live']) || in_array($type, array('image','gallery','json'), true)) { return $rendered; }
        return $this->liveWrap('slot', $id, $rendered, (string) $atts['group']);
    }

    public function dynamicValue($atts = array()): string {
        if (!$this->presentation) { return ''; }
        $atts = shortcode_atts(array('token'=>'','format'=>'text','group'=>'','live'=>'1'), (array) $atts, 'etg_dynamic_value');
        $token = PresentationToken::normalize((string) $atts['token']);
        if ('' === $token) { return ''; }
        $value = $this->presentation->value($token);
        if (is_array($value) || is_object($value)) { return ''; }
        $value = (string) $value;
        $format = sanitize_key((string) $atts['format']);
        $rendered = 'html' === $format ? wp_kses_post($value) : ('url' === $format ? esc_url($value) : esc_html($value));
        if (!$this->truthy($atts['live'])) { return $rendered; }
        return $this->liveWrap('token', $token, $rendered, (string) $atts['group']);
    }

    public function dynamicRows($atts = array()): string {
        if (!$this->presentation) { return ''; }
        $atts = shortcode_atts(array('id'=>'','source'=>'','format'=>'json'), (array) $atts, 'etg_dynamic_rows');
        $id = sanitize_key((string) $atts['id']);
        $source = sanitize_key((string) $atts['source']);
        if (!$id || !$source) { return ''; }
        $rows = $this->presentation->slotRows($id, $source);
        $format = sanitize_key((string) $atts['format']);
        if ('count' === $format) { return esc_html((string) count($rows)); }
        $json = wp_json_encode($rows);
        return is_string($json) ? esc_html($json) : '';
    }

    /** Native image helper backed by an image Content Slot. */
    public function dynamicImage($atts = array()): string {
        if (!$this->presentation) { return ''; }
        $atts = shortcode_atts(array('id'=>'','group'=>'','live'=>'1','alt'=>'','class'=>'','loading'=>'lazy'), (array) $atts, 'etg_dynamic_image');
        $id = sanitize_key((string) $atts['id']);
        if (!$id || 'image' !== $this->presentation->slotType($id)) { return ''; }
        $image = $this->presentation->slotImage($id);
        $url = (string) ($image['url'] ?? '');
        $attachmentId = (int) ($image['id'] ?? 0);
        $alt = sanitize_text_field((string) $atts['alt']);
        if ('' === $alt && $attachmentId && function_exists('get_post_meta')) { $alt = sanitize_text_field((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true)); }
        $class = $this->classList((string) $atts['class'], 'etg-dfsb-dynamic-image');
        $loading = in_array((string) $atts['loading'], array('lazy','eager'), true) ? (string) $atts['loading'] : 'lazy';
        $html = '<img class="' . esc_attr($class) . '" alt="' . esc_attr($alt) . '" loading="' . esc_attr($loading) . '"';
        if ('' !== $url) { $html .= ' src="' . esc_url($url) . '"'; }
        if ($attachmentId) { $html .= ' data-etg-dfsb-attachment-id="' . esc_attr((string) $attachmentId) . '"'; }
        if ($this->truthy($atts['live'])) { $html .= $this->mediaBindingAttributes($id, 'src', (string) $atts['group']); }
        return $html . '>';
    }

    /** Background helper; content remains ordinary WordPress/Elementor markup. */
    public function dynamicBackground($atts = array(), $content = ''): string {
        if (!$this->presentation) { return ''; }
        $atts = shortcode_atts(array('id'=>'','group'=>'','live'=>'1','class'=>''), (array) $atts, 'etg_dynamic_background');
        $id = sanitize_key((string) $atts['id']);
        if (!$id || 'image' !== $this->presentation->slotType($id)) { return ''; }
        $image = $this->presentation->slotImage($id);
        $url = (string) ($image['url'] ?? '');
        $class = $this->classList((string) $atts['class'], 'etg-dfsb-dynamic-background');
        $style = '' !== $url ? ' style="background-image:url(&quot;' . esc_attr($url) . '&quot;)"' : '';
        $binding = $this->truthy($atts['live']) ? $this->mediaBindingAttributes($id, 'background', (string) $atts['group']) : '';
        $body = function_exists('do_shortcode') ? do_shortcode((string) $content) : (string) $content;
        return '<div class="' . esc_attr($class) . '"' . $style . $binding . '>' . wp_kses_post($body) . '</div>';
    }

    /** Gallery payload helper for Swiper/JetEngine/custom adapters. */
    public function dynamicGallery($atts = array()): string {
        if (!$this->presentation) { return ''; }
        $atts = shortcode_atts(array('id'=>'','group'=>'','live'=>'1','limit'=>'12','class'=>''), (array) $atts, 'etg_dynamic_gallery');
        $id = sanitize_key((string) $atts['id']);
        if (!$id || 'gallery' !== $this->presentation->slotType($id)) { return ''; }
        $limit = max(1, min(30, (int) $atts['limit']));
        $gallery = $this->presentation->slotGallery($id, null, $limit);
        $json = wp_json_encode($gallery);
        if (!is_string($json)) { $json = '[]'; }
        $class = $this->classList((string) $atts['class'], 'etg-dfsb-dynamic-gallery');
        $binding = $this->truthy($atts['live']) ? $this->mediaBindingAttributes($id, 'gallery', (string) $atts['group']) : '';
        return '<div class="' . esc_attr($class) . '" data-etg-dfsb-gallery="' . esc_attr($json) . '"' . $binding . '></div>';
    }

    private function liveWrap(string $kind, string $id, string $rendered, string $group = ''): string {
        $attr = 'slot' === $kind ? 'data-etg-dfsb-slot' : 'data-etg-dfsb-token';
        return '<span class="etg-dfsb-live-shortcode" ' . $attr . '="' . esc_attr($id) . '"' . $this->groupAttribute($group) . '>' . $rendered . '</span>';
    }

    private function mediaBindingAttributes(string $id, string $target, string $group = ''): string {
        $target = in_array($target, array('src','background','gallery'), true) ? $target : 'auto';
        return ' data-etg-dfsb-media-slot="' . esc_attr($id) . '" data-etg-dfsb-media-target="' . esc_attr($target) . '"' . $this->groupAttribute($group);
    }

    private function groupAttribute(string $group): string {
        $group = preg_replace('/[^A-Za-z0-9_\-\/.]/', '', trim($group));
        if ('' === $group) { $group = 'auto'; }
        return ' data-etg-dfsb-group="' . esc_attr($group) . '"';
    }

    private function classList(string $classes, string $default): string {
        $out = array($default);
        foreach (preg_split('/\s+/', trim($classes)) ?: array() as $class) {
            $class = sanitize_html_class((string) $class);
            if ('' !== $class && !in_array($class, $out, true)) { $out[] = $class; }
        }
        return implode(' ', $out);
    }

    private function termForAttributes(array $context, array $atts): array {
        $role = sanitize_key((string) ($atts['role'] ?? ''));
        $taxonomy = sanitize_key((string) ($atts['taxonomy'] ?? ''));
        if ('' === $role && $taxonomy) { $role = sanitize_key((string) ($context['taxonomy_roles'][$taxonomy] ?? '')); }
        if ('' === $role) { return array(); }
        return (array) ($context['terms'][$role] ?? array());
    }

    private function truthy($value): bool {
        return in_array(strtolower(trim((string) $value)), array('1','true','yes','on'), true);
    }

    private function context(): array {
        $c = call_user_func($this->contextProvider);
        $c = is_array($c) ? $c : array();
        if ($this->renderable($c)) { return $c; }
        if ('disabled' !== (string) ($c['scope']['reason'] ?? '') || !$this->evidenceContextProvider) { return $c; }
        $e = call_user_func($this->evidenceContextProvider);
        $e = is_array($e) ? $e : array();
        if (empty($e['evidence_only']) || empty($e['dark_presentation_allowed'])) { return $c; }
        return $this->renderable($e) ? $e : $c;
    }

    private function renderable(array $c): bool {
        if (empty($c['active']) || empty($c['in_scope']) || empty($c['runtime_ready']) || empty($c['filters'])) { return false; }
        if (isset($c['scope_valid']) && empty($c['scope_valid'])) { return false; }
        $ajax = 'ajax' === (string) ($c['state_transport'] ?? '');
        if ($ajax) {
            if (empty($c['provider_observation_matches_state']) || (array_key_exists('filtered_query_complete', $c) && empty($c['filtered_query_complete'])) || !empty($c['unsupported_filter_props'])) { return false; }
        } elseif (isset($c['provider_observation_matches_url']) && empty($c['provider_observation_matches_url'])) { return false; }
        $profile = (array) ($c['profile'] ?? array());
        $binding = (array) ($c['post_type_binding'] ?? array());
        if (!empty($profile['require_post_type_binding']) && (empty($binding['observed']) || empty($binding['matches_profile']))) { return false; }
        return empty($c['unknown_filters']) && empty($c['malformed']) && empty($c['missing_terms']) && empty($c['translation_fallback']) && !empty($c['terms']);
    }
}
