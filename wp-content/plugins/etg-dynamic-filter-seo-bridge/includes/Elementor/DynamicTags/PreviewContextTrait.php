<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

trait PreviewContextTrait {
    /*
     * Regression semantics retained in source, not rendered UI:
     * - the placeholder only and is not a saved value;
     * - Live parity: Editor preview is synthetic;
     * - live requests resolve the real URL/AJAX state and remain fail-closed.
     */
    protected function etgRegisterPreviewControl(): void {
        $this->add_control('preview_url', array(
            'label' => 'Preview Filter URL',
            'type' => \Elementor\Controls_Manager::TEXT,
            'placeholder' => '/tours-and-activities/jsf/…',
            'description' => 'Editor preview only. Leave blank to use no synthetic filter context.',
            'label_block' => true,
        ));

        $rawType = defined('\\Elementor\\Controls_Manager::RAW_HTML')
            ? constant('\\Elementor\\Controls_Manager::RAW_HTML')
            : 'raw_html';
        $this->add_control('preview_live_parity_notice', array(
            'type' => $rawType,
            'raw' => '<strong>Live:</strong> ETG uses the real URL/AJAX state. Invalid or mismatched context stays blank.',
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info etg-dfsb-tag-help',
        ));
    }

    protected function etgPreviewContext() {
        $url = trim((string) $this->get_settings('preview_url'));
        if ('' === $url || !$this->etgIsEditorPreview()) { return null; }
        return DynamicTagRuntime::previewContext($url);
    }

    private function etgIsEditorPreview(): bool {
        // Elementor resolves dynamic-tag values through its authenticated
        // render_tags AJAX endpoint. That request is not guaranteed to report
        // editor/preview mode through Plugin::$instance, so the registrar brackets
        // the render pass explicitly via elementor/dynamic_tags/before_render.
        if (DynamicTagRuntime::isEditorRenderPass()) { return true; }
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
