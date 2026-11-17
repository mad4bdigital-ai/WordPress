<?php
if(!defined('ABSPATH'))exit;

/** Lab-only opt-in for the shared Astra/Elementor action compatibility hook. */
if (wp_get_environment_type() === 'local' && !defined('ASTRA_ELEMENTOR_ACTION_COMPAT_ENABLED')) {
    define('ASTRA_ELEMENTOR_ACTION_COMPAT_ENABLED', true);
}
