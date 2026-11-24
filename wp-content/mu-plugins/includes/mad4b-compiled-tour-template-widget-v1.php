<?php
/**
 * Class loaded only after Elementor Pro registers its Template widget.
 * Preserve the original widget name, attributes and HTML wrapper.
 */
defined('ABSPATH') || exit;
if (!class_exists('\ElementorPro\Modules\Library\Widgets\Template')) return;
if (!class_exists('MAD4B_Compiled_Tour_Family_V1_Widget',false)) {
    class MAD4B_Compiled_Tour_Family_V1_Widget extends \ElementorPro\Modules\Library\Widgets\Template {
        protected function render() {
            $templateId=absint($this->get_settings('template_id'));
            $native=function(): string {
                ob_start();
                parent::render();
                return (string)ob_get_clean();
            };
            echo MAD4B_Compiled_Tour_Family_V1::replay_or_native($this,$native,$templateId);
        }
    }
}
