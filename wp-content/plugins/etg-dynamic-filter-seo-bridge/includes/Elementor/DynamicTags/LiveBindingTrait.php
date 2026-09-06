<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

/**
 * Shared non-authorizing AJAX presentation controls for text-like Elementor tags.
 *
 * A blank group means Auto. Auto binds only when the browser can identify one
 * unambiguous JetSmartFilters provider/query group. Explicit groups remain the
 * safest choice on pages with multiple listings/providers.
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

        $options = array('' => 'Auto — single matching JetSmartFilters group');
        foreach (DynamicTagRuntime::groupOptions() as $key => $label) { $options[$key] = $label; }
        $this->add_control('live_group', array(
            'label' => 'AJAX Group',
            'type' => \Elementor\Controls_Manager::SELECT,
            'options' => $options,
            'default' => '',
            'condition' => array('live_update' => 'yes'),
            'description' => 'Use Auto for a single listing. On multi-provider pages choose the exact provider/query group, for example jet-engine/tours_query_archive.',
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
