<?php
defined('ABSPATH') || exit;
/**
 * Native JetEngine Page Recipes.
 *
 * Presentation/interaction only. Source fields and native submit never change.
 * A site-scoped canary can be switched off instantly without reverting code.
 */
final class MAD4B_JetEngine_Page_Recipes_V1 {
    public const OPTION = 'mad4b_jetengine_page_recipe_mode_v1';
    public const ACTION = 'mad4b_jetengine_page_recipe_toggle_v1';
    public const CANARY = 'etg-dynamic-pricing-controls';
    public static function boot(): void {
        if (!is_admin()) return;
        add_action('admin_post_'.self::ACTION, [__CLASS__, 'toggle']);
    }
    public static function mode(): string {
        $value = get_option(self::OPTION, 'canary');
        return $value === 'off' ? 'off' : 'canary';
    }
    public static function authorized(): bool {
        return current_user_can('manage_options');
    }
    public static function config(string $slug): array {
        $applicable = $slug === self::CANARY;
        $enabled = $applicable && self::mode() === 'canary';
        // Preview-only URL switch never writes any option.
        $preview = sanitize_key((string)($_GET['mad4b_recipe'] ?? ''));
        if ($preview === 'off') $enabled = false;
        elseif ($preview === 'on' && $applicable && self::mode() !== 'off') $enabled = true;
        return [
            'id' => $applicable ? 'pricing-general-v1' : '',
            'version' => 1,
            'enabled' => $enabled,
            'can_toggle' => $applicable && self::authorized(),
            'mode' => self::mode(),
            'save_url' => admin_url('admin-post.php'),
            'nonce' => $applicable && self::authorized()
                ? wp_create_nonce(self::ACTION) : '',
            'action' => self::ACTION,
            'source_page' => admin_url('admin.php?page=dynamic-pricing-controls'),
        ];
    }
    public static function toggle(): void {
        if (!self::authorized()) wp_die('Not allowed', '', ['response' => 403]);
        check_admin_referer(self::ACTION, 'recipe_nonce');
        $value = sanitize_key((string)wp_unslash($_POST['mode'] ?? ''));
        if (!in_array($value, ['off','canary'], true)) {
            wp_die('Invalid page recipe mode', '', ['response' => 422]);
        }
        update_option(self::OPTION, $value, false);
        wp_safe_redirect(admin_url('admin.php?page=dynamic-pricing-controls'), 303);
        exit;
    }
}
MAD4B_JetEngine_Page_Recipes_V1::boot();
