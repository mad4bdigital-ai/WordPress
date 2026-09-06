<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

trait PreviewContextTrait {
    protected function etgRegisterPreviewControl(): void {
        $this->add_control('preview_url', array(
            'label' => 'Preview Filter URL (Editor only)',
            'type' => \Elementor\Controls_Manager::TEXT,
            'placeholder' => 'Paste a filtered URL, e.g. /tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/',
            'description' => 'Editor-only non-authorizing evidence. The gray example is a placeholder only and is not a saved value. Leave blank for no synthetic editor context. This field is ignored on the live front end.',
            'label_block' => true,
        ));

        $rawType = defined('\\Elementor\\Controls_Manager::RAW_HTML')
            ? constant('\\Elementor\\Controls_Manager::RAW_HTML')
            : 'raw_html';
        $this->add_control('preview_live_parity_notice', array(
            'type' => $rawType,
            'raw' => '<strong>Live parity:</strong> Editor preview is synthetic. On the live front end ETG resolves the actual pretty URL / JetSmartFilters AJAX state against the disabled governed profile. A valid exact match may render presentation-only content while Global remains OFF; any route/profile/runtime mismatch stays blank and fail-closed.',
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ));
    }

    protected function etgPreviewContext() {
        $url = trim((string) $this->get_settings('preview_url'));
        if ('' === $url || !$this->etgIsEditorPreview()) { return null; }
        return DynamicTagRuntime::previewContext($url);
    }

    private function etgIsEditorPreview(): bool {
        if (!class_exists('\\Elementor\\Plugin') || !isset(\Elementor\Plugin::$instance)) { return false; }
        $plugin = \Elementor\Plugin::$instance;
        try {
            if (isset($plugin->editor) && is_object($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) { return true; }
            if (isset($plugin->preview) && is_object($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode()) { return true; }
        } catch (\Throwable $error) {
            return false;
        }
        return false;
    }
}
