<?php
/**
 * Plugin Name: MAD4B Tour Countries Mobile Card
 * Description: Mobile-only full-width country cards on the single tour page; no Elementor layout mutations.
 * Version: 1.0.0
 * Author: MAD4B
 */
defined('ABSPATH') || exit;

add_action('wp_enqueue_scripts', static function () {
    if (!is_singular('tours-and-activities')) {
        return;
    }
    $css = <<<'CSS'
@media screen and (max-width: 767px) {
  body.single-tours-and-activities #single_tour_countries,
  body.single-tours-and-activities #single_tour_countries > .elementor-widget-container,
  body.single-tours-and-activities #single_tour_countries .jet-listing-grid,
  body.single-tours-and-activities #single_tour_countries .jet-listing-grid__items,
  body.single-tours-and-activities #single_tour_countries .jet-listing-grid__item,
  body.single-tours-and-activities #single_tour_countries .elementor-45928,
  body.single-tours-and-activities #single_tour_countries .elementor-element-40cda4a,
  body.single-tours-and-activities #single_tour_countries .elementor-element-72ce2a0 {
    width: 100% !important;
    max-width: none !important;
    min-width: 0;
    box-sizing: border-box;
  }
  body.single-tours-and-activities #single_tour_countries .jet-listing-grid__items {
    grid-template-columns: minmax(0, 1fr) !important;
  }
  body.single-tours-and-activities #single_tour_countries .elementor-element-72ce2a0 {
    --width: 100% !important;
    --flex-basis: auto !important;
    flex: 1 1 auto;
    align-self: stretch;
  }
}
CSS;
    wp_register_style('mad4b-tour-country-mobile', false, [], '1.0.0');
    wp_enqueue_style('mad4b-tour-country-mobile');
    wp_add_inline_style('mad4b-tour-country-mobile', $css);
}, 99);
