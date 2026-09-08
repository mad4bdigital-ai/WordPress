<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

/**
 * Shared non-authorizing AJAX presentation controls for text-like Elementor tags.
 *
 * A blank group means Auto. Auto first honors an exact provider/query identity
 * encoded in the current JetSmartFilters pretty URL. Without that evidence it
 * falls back to exactly one active semantic group, then exactly one available
 * group. Any remaining ambiguity fails closed.
 */
trait LiveBindingTrait {
    protected function etgRegisterLiveBindingControls(): void {
        $this->add_control('live_update', array(
            'label' => 'AJAX Live Update',
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
            'description' => 'Presentation-only live update. It never grants URL, SEO, sitemap or indexing authority.',
        ));

        $options = array('' => 'Auto — URL group → active group → single group');
        foreach (DynamicTagRuntime::groupOptions() as $key => $label) { $options[$key] = $label; }
        $this->add_control('live_group', array(
            'label' => 'AJAX Group',
            'type' => \Elementor\Controls_Manager::SELECT,
            'options' => $options,
            'default' => '',
            'condition' => array('live_update' => 'yes'),
            'description' => 'Auto safely follows the exact provider/query encoded in a /jsf/... URL when present. Otherwise it requires one active or one available group. You can still choose an explicit group such as jet-engine/tours_query_archive.',
        ));
    }

    protected function etgLiveEnabled(): bool {
        return 'yes' === (string) $this->get_settings('live_update');
    }

    protected function etgLiveGroup(): string {
        return DynamicTagRuntime::normalizeGroup((string) $this->get_settings('live_group'));
    }

    protected function etgValueOrFallback($value): string {
        $value = is_scalar($value) ? (string) $value : '';
        if ('' !== trim(wp_strip_all_tags($value))) { return $value; }
        $fallback = (string) $this->get_settings('fallback');
        return $fallback;
    }

    protected function etgBindingAttributes(string $kind, string $id, string $fallback = ''): string {
        if (!$this->etgLiveEnabled()) { return ''; }
        $id = trim($id);
        if ('' === $id) { return ''; }
        $attribute = 'slot' === $kind ? 'data-etg-dfsb-slot' : 'data-etg-dfsb-token';
        $group = $this->etgLiveGroup();
        if ('' === $group) { $group = 'auto'; }
        $attrs = ' ' . $attribute . '="' . esc_attr($id) . '" data-etg-dfsb-group="' . esc_attr($group) . '"';
        $fallback = trim(wp_strip_all_tags($fallback));
        if ('' !== $fallback) { $attrs .= ' data-etg-dfsb-fallback="' . esc_attr($fallback) . '"'; }
        return $attrs;
    }
}
