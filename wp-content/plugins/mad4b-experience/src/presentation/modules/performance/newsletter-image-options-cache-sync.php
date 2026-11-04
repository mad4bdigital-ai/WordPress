<?php
/**
 * Plugin Name: Newsletter Image Options Cache Sync
 * Description: Regenerates shared Newsletter Elementor image CSS after JetEngine Look and Feel media changes.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) exit;
if(!MAD4B_Presentation_Experience::enabled('newsletter-image-css-sync'))return;

/**
 * The single JetEngine media option is shared by all six WPML templates.
 * Image backgrounds are compiled into Elementor CSS and LiteSpeed HTML/CSS,
 * so both levels must be invalidated on a real change (not every settings save).
 */
add_action('update_option_look-and-feel', static function($old, $new) {
    if (!is_array($old) || !is_array($new)) return;
    $previous=(string)($old['newsletter_signup_image']??'');
    $current=(string)($new['newsletter_signup_image']??'');
    if ($previous===$current) return;

    if (class_exists('Elementor\Plugin')) {
        $manager=\Elementor\Plugin::instance()->files_manager;
        if (is_object($manager) && method_exists($manager, 'clear_cache'))
            $manager->clear_cache();
    }

    // Newsletter is an embedded template on home, destination, and other
    // Elementor surfaces. A page-local purge cannot cover these consumers.
    do_action('litespeed_purge_all');
}, 100, 2);

