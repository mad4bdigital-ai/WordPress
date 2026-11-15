<?php
/**
 * Plugin Name: MAD4B Tour Gallery Popup Layout
 * Description: Responsive, scroll-safe Elementor/UAEL tour gallery dialog; does not alter gallery data or other popups.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular('tours-and-activities')) return;
    wp_register_style('mad4b-tour-gallery-popup-layout', false, [], '1.0.0');
    wp_enqueue_style('mad4b-tour-gallery-popup-layout');
    wp_add_inline_style('mad4b-tour-gallery-popup-layout', <<<'CSS'
/* Target only Elementor popups containing the UAEL tour photo gallery. */
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-widget-content {
    width: min(1100px, calc(100vw - 32px)) !important;
    max-width: calc(100vw - 32px) !important;
    max-height: calc(100dvh - 32px) !important;
    border-radius: 16px !important;
    overflow: hidden !important;
    box-sizing: border-box;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-message {
    width: 100% !important;
    max-height: calc(100dvh - 40px) !important;
    min-height: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    padding: 18px 20px !important;
    box-sizing: border-box !important;
    overscroll-behavior: contain;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-close-button {
    position: absolute !important;
    z-index: 100 !important;
    top: 10px !important;
    right: 12px !important;
    margin: 0 !important;
    background: rgba(255,255,255,.95) !important;
    border-radius: 50%;
    padding: 8px;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .elementor-widget-uael-image-gallery,
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .uael-img-gallery-wrap {
    width: 100% !important;
    max-width: 100% !important;
}
@media (max-width: 767px) {
    body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-widget-content {
        width: calc(100vw - 16px) !important;
        max-width: calc(100vw - 16px) !important;
        max-height: calc(100dvh - 16px) !important;
    }
    body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-message {
        padding: 14px 10px !important;
        max-height: calc(100dvh - 24px) !important;
    }
}
CSS
    );
}, 55);

add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular('tours-and-activities')) return;
    // Keep the Elementor button as the single popup authority. This asset
    // only makes the surrounding background-image photo frames actionable.
    $relative='mad4b-tour-gallery-popup-layout/frames.js';
    $path=__DIR__.'/'.$relative;
    if (!is_readable($path) || is_link($path)) return;
    wp_enqueue_script(
        'mad4b-tour-gallery-frame-opener',
        plugins_url($relative, __FILE__),
        ['elementor-frontend'],
        (string)filemtime($path),
        true
    );
    wp_add_inline_style('mad4b-tour-gallery-popup-layout',
        'body.single-tours-and-activities .mad4b-gallery-frame-opener {'.
        'cursor:pointer;}'.
        'body.single-tours-and-activities .mad4b-gallery-frame-opener:focus-visible {'.
        'outline:3px solid var(--e-global-color-accent,#b88942);'.
        'outline-offset:-4px;}'
    );
}, 58);
