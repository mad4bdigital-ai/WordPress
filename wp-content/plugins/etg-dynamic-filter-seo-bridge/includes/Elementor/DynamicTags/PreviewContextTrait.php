<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

trait PreviewContextTrait {
    protected function etgRegisterPreviewControl(): void {
        $this->add_control('preview_url', array(
            'label' => 'Preview URL (Editor only)',
            'type' => \Elementor\Controls_Manager::TEXT,
            'placeholder' => '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/',
            'description' => 'Optional same-site filter URL used only in Elementor editor/preview. It resolves as non-authorizing evidence and is ignored on the live front end.',
            'label_block' => true,
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
