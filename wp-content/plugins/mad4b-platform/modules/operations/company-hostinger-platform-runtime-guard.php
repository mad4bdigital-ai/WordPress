<?php
/**
 * Coolify Local Lab: this copy is not running inside Hostinger hPanel.
 * The vendor Hostinger plugin accesses $_SERVER['H_PLATFORM'] without isset().
 * Supply the truthful disabled value ONLY when no real platform sets one.
 * Never simulate Hostinger eligibility, change vendor files or send events.
 */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;
if (!array_key_exists('H_PLATFORM', $_SERVER)) {
    $_SERVER['H_PLATFORM'] = '';
}
