<?php
/**
 * Plugin Name: MAD4B Dynamic Destination Contents — Elementor
 * Description: Native Elementor dynamic, nested destination TOC; progressive enhancement of existing Elementor anchors.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Destination_Contents_Loader {
    public const NAME = 'mad4b-destination-contents';
    public static function labels(): array {
        return [
            'en'=>['Destination contents','Overview & highlights','Things to do','Places to explore','Travel planning'],
            'ar'=>['محتويات الوجهة','نظرة عامة وأهم المميزات','أنشطة وتجارب','أماكن للاستكشاف','تخطيط الرحلة'],
            'fr'=>['Sommaire de la destination','Présentation','Activités','Lieux à explorer','Préparer le voyage'],
            'de'=>['Reiseziel-Inhalte','Überblick','Aktivitäten','Orte entdecken','Reiseplanung'],
            'it'=>['Contenuti della destinazione','Panoramica','Attività','Luoghi da scoprire','Organizzare il viaggio'],
            'es'=>['Contenido del destino','Resumen y puntos destacados','Qué hacer','Lugares para explorar','Planifica tu viaje']
        ];
    }
    public static function language(): string {
        $lang=(string)apply_filters('wpml_current_language','');
        if (!$lang) $lang=strtolower(substr((string)get_locale(),0,2));
        return isset(self::labels()[$lang]) ? $lang : 'en';
    }
    public static function assets(): void {
        $dir=WPMU_PLUGIN_DIR.'/assets/';
        $base=content_url('/mu-plugins/assets/');
        foreach ([
            'mad4b-destination-contents-native.css'=>'css',
            'mad4b-destination-contents-native.js'=>'js'
        ] as $name=>$type) {
            if (!is_readable($dir.$name)) continue;
            if ($type==='css') wp_register_style('mad4b-destination-contents-native',$base.$name,[],(string)filemtime($dir.$name));
            else wp_register_script('mad4b-destination-contents-native',$base.$name,[],(string)filemtime($dir.$name),true);
        }
    }
    public static function widget($widgets): void {
        if (!class_exists('\Elementor\Widget_Base')) return;
        require_once WPMU_PLUGIN_DIR.'/assets/class-mad4b-destination-contents-widget.php';
        $widgets->register(new \MAD4B_Destination_Contents_Widget());
    }
    public static function disable_old_js(): void {
        if (!is_tax('destinations')) return;
        // The legacy Elementor TOC remains in the template as a fallback
        // and as the independent desktop navigation, but avoid a second mobile drawer.
        // Only disable the old enhancer when Elementor enqueues the
        // replacement. Preserve fallback on archives without the new widget.
        if (!wp_script_is('mad4b-destination-contents-native','enqueued')) return;
        wp_dequeue_script('mad4b-mobile-destination-toc');
        wp_dequeue_style('mad4b-mobile-destination-toc');
    }
}
add_action('elementor/widgets/register',['MAD4B_Destination_Contents_Loader','widget'],25);
add_action('wp_enqueue_scripts',['MAD4B_Destination_Contents_Loader','assets'],8);
add_action('wp_print_scripts',['MAD4B_Destination_Contents_Loader','disable_old_js'],99);
add_action('wp_print_styles',['MAD4B_Destination_Contents_Loader','disable_old_js'],99);
// Original Elementor TOC enhancement stays active as fallback until the native widget is ready.
