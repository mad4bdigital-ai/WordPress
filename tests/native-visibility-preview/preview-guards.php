<?php
/**
 * Hermetic native Elementor visibility test.
 * Run once per site mode and plugin, with no WordPress database or browser.
 */
namespace Elementor {
    final class Plugin {
        public static $instance;
    }
}
namespace {
    define('ABSPATH', true);
    function add_action(...$args) { return true; }
    function add_filter(...$args) { return true; }
    function is_admin() { return getenv('MAD4B_TEST_ADMIN') === '1'; }
    function wp_doing_ajax() { return getenv('MAD4B_TEST_AJAX') === '1'; }
    function is_preview() { return getenv('MAD4B_TEST_PREVIEW') === '1'; }
    function get_option($key, $default = []) {
        return $key === 'contact-and-help-info'
            ? ['support_group_url' => getenv('MAD4B_TEST_GROUP_URL') ?: '',
               'support_chat_url' => ''] : $default;
    }
    function home_url($path = '/') { return 'https://tours.dev.mad4b.com' . $path; }
    function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
    function apply_filters($name, $value, ...$args) { return $value; }
    function get_post_type($post_id) { return 'elementor_library'; }
    function wp_doing_cron() { return false; }
    final class MAD4B_Stub_Editor {
        public function is_edit_mode(): bool { return getenv('MAD4B_TEST_EDITOR') === '1'; }
    }
    \Elementor\Plugin::$instance = (object) ['editor' => new MAD4B_Stub_Editor()];
    $plugin = getenv('MAD4B_TEST_PLUGIN');
    $allowed = ['need-help', 'single-reviews', 'home-reviews'];
    if (!in_array($plugin, $allowed, true)) {
        fwrite(STDERR, "INVALID_PLUGIN_SCENARIO\n"); exit(2);
    }
    $root = dirname(__DIR__, 2) . '/wp-content/mu-plugins/';
    if ($plugin !== 'need-help') {
        require $root . 'mad4b-home-reviews-native-visibility.php';
    }
    if ($plugin === 'need-help') {
        require $root . 'mad4b-need-help-native-visibility.php';
    } elseif ($plugin === 'single-reviews') {
        require $root . 'mad4b-single-reviews-native-visibility.php';
    }
    $frontend = !is_admin() && !wp_doing_ajax()
        && getenv('MAD4B_TEST_EDITOR') !== '1';
    if ($plugin === 'need-help') {
        $r = new \ReflectionMethod('MAD4B_Need_Help_Visibility_V1', 'frontend');
        if ($r->invoke(null) !== $frontend) throw new \RuntimeException('HELP_FRONTEND_CONTEXT_DRIFT');
        $pre = MAD4B_Need_Help_Visibility_V1::shortcode_preflight(
            false, 'elementor-template', ['id' => 25213], []);
        $has_destination = (bool) getenv('MAD4B_TEST_GROUP_URL');
        if ($pre !== (($frontend && !$has_destination) ? '' : false)) {
            throw new \RuntimeException('HELP_TEMPLATE_VISIBILITY_DRIFT');
        }
    } elseif ($plugin === 'single-reviews') {
        $r = new \ReflectionMethod('MAD4B_Universal_Reviews_Visibility_V2', 'is_frontend');
        if ($r->invoke(null) !== $frontend) throw new \RuntimeException('REVIEWS_FRONTEND_CONTEXT_DRIFT');
        $nodes = [['elType' => 'widget', 'widgetType' => 'template',
                   'settings' => ['template_id' => 27219]]];
        $out = MAD4B_Universal_Reviews_Visibility_V2::prune_data($nodes, 69715);
        if ($out !== ($frontend ? [] : $nodes)) {
            throw new \RuntimeException('REVIEWS_TEMPLATE_VISIBILITY_DRIFT');
        }
    } else {
        $r = new \ReflectionMethod('MAD4B_Home_Reviews_Native_Visibility_V1', 'is_elementor_editor');
        if ($r->invoke(null) !== (getenv('MAD4B_TEST_EDITOR') === '1')) {
            throw new \RuntimeException('HOME_REVIEWS_EDITOR_CONTEXT_DRIFT');
        }
        $widget = new class {
            public array $settings = ['template_id' => 27219];
            public function get_name(): string { return 'template'; }
            public function get_settings_for_display(): array { return $this->settings; }
            public function set_settings($key, $value): void { $this->settings[$key] = $value; }
        };
        MAD4B_Home_Reviews_Native_Visibility_V1::before_render($widget);
        $expect = $frontend ? '' : 27219;
        if ($widget->settings['template_id'] !== $expect) {
            throw new \RuntimeException('HOME_REVIEWS_EARLY_RENDER_DRIFT');
        }
    }
    echo "PASS {$plugin}:preview=" . (is_preview() ? '1' : '0')
        . ":editor=" . getenv('MAD4B_TEST_EDITOR') . "\n";
}
