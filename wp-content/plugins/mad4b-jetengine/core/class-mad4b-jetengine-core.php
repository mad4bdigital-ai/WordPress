<?php
defined('ABSPATH') || exit;
/**
 * Independent module discovery, explicit per-site enablement, no vendor overrides.
 * Install a module by adding modules/{slug}/module.php; no hard-coded loader changes.
 */
final class MAD4B_JetEngine_Core_V1 {
    const VERSION = '1.0.0';
    const OPTION = 'mad4b_jetengine_module_flags_v1';
    private static string $root = '';
    private static array $modules = [];
    private static array $status = [];
    private static bool $booted = false;

    public static function root(): string { return self::$root; }
    public static function modules(): array { return self::$modules; }
    public static function status(): array { return self::$status; }
    public static function legacy_active(): bool {
        return in_array('mad4b-jetengine-hub-addon/mad4b-jetengine-hub-addon.php', (array)get_option('active_plugins', []), true);
    }
    public static function flags(): array {
        $saved = get_option(self::OPTION, []);
        return is_array($saved) ? $saved : [];
    }
    private static function discover(): array {
        $out = [];
        $root = self::$root . '/modules';
        $dirs = glob($root . '/*/module.php') ?: [];
        sort($dirs, SORT_STRING);
        foreach ($dirs as $file) {
            $slug = basename(dirname($file));
            if (!preg_match('/^[a-z][a-z0-9-]{1,50}$/D', $slug)) continue;
            $spec = require $file;
            if (!is_array($spec) || ($spec['id'] ?? '') !== $slug ||
                !isset($spec['boot']) || !is_callable($spec['boot'])) continue;
            $spec['dependencies'] = array_values(array_filter((array)($spec['dependencies'] ?? []), 'is_string'));
            $spec['default_enabled'] = (bool)($spec['default_enabled'] ?? false);
            $out[$slug] = $spec;
        }
        return $out;
    }
    private static function is_enabled(string $id, array $spec): bool {
        $flags = self::flags();
        if (array_key_exists($id, $flags)) return $flags[$id] === true;
        if ($id === 'hub') return self::legacy_active();
        return $spec['default_enabled'];
    }
    public static function boot(string $root): void {
        if (self::$booted) return;
        self::$booted = true;
        self::$root = $root;
        self::$modules = self::discover();
        $pending = self::$modules;
        $attempts = 0;
        while ($pending && ++$attempts <= count(self::$modules) + 1) {
            $progress = false;
            foreach ($pending as $id => $spec) {
                if (!self::is_enabled($id, $spec)) {
                    self::$status[$id] = 'disabled';
                    unset($pending[$id]); $progress = true; continue;
                }
                // The old active plugin owns the Hub bootstrap until its explicit migration.
                if ($id === 'hub' && self::legacy_active()) {
                    self::$status[$id] = 'legacy_active';
                    unset($pending[$id]); $progress = true; continue;
                }
                $deps = $spec['dependencies'];
                $unresolved = array_filter($deps, static fn($d) => !isset(self::$status[$d]));
                if ($unresolved) continue;
                $missing = array_filter($deps, static fn($d) => (self::$status[$d] ?? '') !== 'active');
                if ($missing) {
                    self::$status[$id] = 'dependency_unavailable';
                    unset($pending[$id]); $progress = true; continue;
                }
                try {
                    ($spec['boot'])();
                    self::$status[$id] = 'active';
                } catch (Throwable $e) {
                    error_log('MAD4B JetEngine ' . $id . ': ' . $e->getMessage());
                    self::$status[$id] = 'boot_error';
                }
                unset($pending[$id]); $progress = true;
            }
            if (!$progress) break;
        }
        foreach ($pending as $id => $_) self::$status[$id] = 'dependency_cycle';
        if (is_admin()) add_action('admin_menu', [__CLASS__, 'register_admin'], 20);
    }
    public static function register_admin(): void {
        add_menu_page('MAD4B JetEngine', 'MAD4B JetEngine', 'manage_options',
            'mad4b-jetengine', [__CLASS__, 'admin_page'], 'dashicons-admin-generic', 58);
    }
    public static function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mad4b_jetengine_modules_save'])) {
            check_admin_referer('mad4b_jetengine_modules_save');
            $flags = self::flags();
            foreach (self::$modules as $id => $_) {
                $flags[$id] = isset($_POST['modules']) && is_array($_POST['modules'])
                    && isset($_POST['modules'][$id]) && $_POST['modules'][$id] === '1';
            }
            update_option(self::OPTION, $flags, false);
            echo '<div class="notice notice-success"><p>Module settings saved. Reload this page to inspect the new runtime state.</p></div>';
        }
        echo '<div class="wrap"><h1>MAD4B JetEngine · Modules</h1>';
        echo '<p>Modules are discovered automatically. Each company controls activation independently.</p>';
        echo '<form method="post">';
        wp_nonce_field('mad4b_jetengine_modules_save');
        echo '<table class="widefat striped"><thead><tr><th>Module</th><th>Version</th><th>Enabled</th><th>Runtime</th></tr></thead><tbody>';
        foreach (self::$modules as $id => $m) {
            $on = self::is_enabled($id, $m);
            echo '<tr><td><strong>' . esc_html($m['label'] ?? $id) . '</strong><br><code>' . esc_html($id) . '</code></td>';
            echo '<td>' . esc_html((string)($m['version'] ?? '1')) . '</td><td><label>';
            echo '<input type="checkbox" name="modules[' . esc_attr($id) . ']" value="1" ' . checked($on, true, false) . '> Enabled</label></td>';
            echo '<td>' . esc_html(self::$status[$id] ?? 'pending') . '</td></tr>';
        }
        echo '</tbody></table><p><button type="submit" name="mad4b_jetengine_modules_save" value="1" class="button button-primary">Save modules</button></p></form></div>';
    }
}
