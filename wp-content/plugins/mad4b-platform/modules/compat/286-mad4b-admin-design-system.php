<?php
/**
 * Plugin Name: MAD4B Staging Admin Design System
 * Description: Presentation-only shared layout for enrolled MAD4B Staging admin workspaces.
 * Version: 0.2.0
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Staging_Admin_Design {
    private const HOST = 'tours.dev.mad4b.com';
    private const EXTRA_PAGES = array(
        'mad4b-shared-content-hub',
        'mad4b-hub-research-feed',
        'mad4b-dynamic-bulk-runtime',
        'mad4b-hub-workflow-operations',
        'mad4b-hub-workflow-triggers',
        'mad4b-hub-dynamic-runtime',
        'mad4b-hub-executor-preference',
        'mad4b-hub-bulk-queue',
        'mad4b-ai-context-guard',
        'mad4b-hub-auto-distribute',
        'mad4b-company-brand-readiness',
        'mad4b-hub-dedup-discovery',
        'mad4b-hub-field-fusion',
        'mad4b-canonical-registry',
        'mad4b-company-canonical-pilot',
        'mad4b-canonical-gaps'
    );
    private static function allowed(): bool {
        if (!is_admin() || wp_doing_ajax() || !current_user_can('manage_options')
            || wp_get_environment_type() !== 'staging'
            || strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST)) !== self::HOST) return false;
        $raw = $_GET['page'] ?? '';
        if (!is_string($raw) || strlen($raw) > 128) return false;
        $page = sanitize_key(wp_unslash($raw));
        if (in_array($page, self::EXTRA_PAGES, true)) return true;
        return class_exists('MAD4B_SCP_Admin_Workspace')
            && MAD4B_SCP_Admin_Workspace::current_page() === $page && $page !== '';
    }
    public static function body_class($classes) {
        return self::allowed() ? $classes . ' mad4b-design-v2-page' : $classes;
    }
    public static function enqueue(): void {
        if (!self::allowed()) return;
        $root = WPMU_PLUGIN_DIR . '/mad4b-admin-design-assets/';
        $base = content_url('mu-plugins/mad4b-admin-design-assets/');
        $scheme = (string)wp_parse_url(home_url('/'), PHP_URL_SCHEME);
        foreach (array('css','js') as $extension) {
            $name = 'admin-design.' . $extension;
            if (!is_readable($root . $name)) continue;
            $url = set_url_scheme($base . $name, $scheme);
            $version = '2.0.' . (string)filemtime($root . $name);
            if ($extension === 'css') wp_enqueue_style('mad4b-staging-admin-design-v2', $url, array(), $version);
            else wp_enqueue_script('mad4b-staging-admin-design-v2', $url, array(), $version, true);
        }
    }
}
add_filter('admin_body_class', array('MAD4B_Staging_Admin_Design','body_class'), 50);
add_action('admin_enqueue_scripts', array('MAD4B_Staging_Admin_Design','enqueue'), 90);

