<?php
/**
 * Company Performance Preset: anonymous Elementor Mega Menu SSR memoization.
 * Works only under a reviewed tenant-specific staging configuration.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging' || PHP_SAPI === 'cli') return;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || !empty($_SERVER['HTTP_COOKIE']) || !empty($_SERVER['HTTP_AUTHORIZATION'])
    || !empty($_SERVER['QUERY_STRING'])
    || wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) !== '/'
    || ($_SERVER['HTTP_X_MAD4B_FRAGMENT_AB'] ?? 'enabled') === 'baseline') return;

$get_target = static function(): array {
    if (!function_exists('mad4b_company_fragment_preset_enabled')
        || !mad4b_company_fragment_preset_enabled('mega_menu_markup')) return [];
    $cfg = mad4b_company_fragment_preset_config();
    return [(string)$cfg['targets']['menu_widget_id'] => (int)$cfg['targets']['menu_document_id']];
};

$cfg_file_ns = hash('sha256', strtolower((string)($_SERVER['HTTP_HOST'] ?? 'unknown')));
$root = sys_get_temp_dir().'/mad4b-company-menu-fragments-'.$cfg_file_ns;
if (!is_dir($root) && !@mkdir($root, 0700, true)) return;
if (!is_writable($root)) return;
$started = [];
$hit = [];

$key_for = static function($widget_id, $doc_id) use($root) {
    $post = get_post($doc_id);
    if (!$post || $post->post_status !== 'publish') return null;
    $locale = (string) apply_filters('wpml_current_language', '');
    $base = home_url('/').'|'.get_locale().'|'.$locale.'|'.
        get_woocommerce_currency().'|'.(wp_is_mobile() ? 'mobile' : 'desktop').'|'.
        $widget_id.'|'.$doc_id.'|'.$post->post_modified_gmt;
    return $root.'/'.hash('sha256', $base).'.json';
};

$cacheable = static function($html): bool {
    return is_string($html) && strlen($html) >= 100 && strlen($html) <= 1048576 &&
        !preg_match('/nonce|data-price|woocommerce-cart|wc_fragments|wcml|add-to-cart|checkout|fluentform|<form\b|wp-admin|logged_in|customer|cart_hash|client_secret/i', $html);
};

$read = static function($file) use($cacheable) {
    if (!$file || !is_file($file) || filesize($file) > 1100000) return null;
    $content = json_decode((string) @file_get_contents($file), true);
    return is_array($content) && ($content['expires'] ?? 0) > time()
        && $cacheable($content['html'] ?? null) ? $content['html'] : null;
};

$write = static function($file, $html) use($cacheable): void {
    if (!$file || !$cacheable($html)) return;
    $cfg = mad4b_company_fragment_preset_config();
    $ttl = (int) ($cfg['fragment_ttl_seconds'] ?? 0);
    if ($ttl < 5 || $ttl > 60) return;
    $value = json_encode(['expires'=>time()+$ttl, 'html'=>$html], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($value)) return;
    $tmp = $file.'.'.getmypid().'.tmp';
    if (@file_put_contents($tmp, $value, LOCK_EX) === false) return;
    @chmod($tmp, 0600);
    @rename($tmp, $file);
};

add_filter('elementor/frontend/builder_content_data', static function($nodes, $document_id)
    use($get_target, $key_for, $read, &$hit) {
    $targets = $get_target();
    if (!$targets || !in_array((int)$document_id, $targets, true) || !is_array($nodes)) return $nodes;
    $walk = static function(&$items) use(&$walk, $targets, $read, $key_for, &$hit) {
        foreach ($items as &$item) {
            if (!is_array($item)) continue;
            $id = (string) ($item['id'] ?? '');
            if (isset($targets[$id]) && ($item['widgetType'] ?? '') === 'mega-menu') {
                $html = $read($key_for($id, $targets[$id]));
                if ($html !== null) {
                    $item = [
                        'id' => 'eab'.substr(hash('sha256', $id), 0, 4),
                        'elType' => 'widget', 'widgetType' => 'html',
                        'settings' => ['html' => $html], 'elements' => []
                    ];
                    $hit[$id] = true;
                }
            } elseif (!empty($item['elements']) && is_array($item['elements'])) {
                $walk($item['elements']);
            }
        }
    };
    $walk($nodes);
    return $nodes;
}, 9999, 2);

add_action('elementor/frontend/before_render', static function($widget)
    use($get_target, &$started, &$hit) {
    $targets = $get_target();
    if (!$targets || !is_object($widget) || !method_exists($widget,'get_id')) return;
    $id = (string)$widget->get_id();
    if (!isset($targets[$id]) || isset($hit[$id])) return;
    $doc = \Elementor\Plugin::$instance->documents->get_current();
    if (!$doc || (int)$doc->get_main_id() !== $targets[$id]) return;
    $started[spl_object_id($widget)] = ['id'=>$id, 'doc'=>$targets[$id], 'level'=>ob_get_level()];
    ob_start();
}, 999999);

add_action('elementor/frontend/after_render', static function($widget)
    use(&$started, $key_for, $write) {
    if (!is_object($widget)) return;
    $oid = spl_object_id($widget);
    if (!isset($started[$oid])) return;
    $info = $started[$oid];
    unset($started[$oid]);
    if (ob_get_level() !== $info['level']+1) return;
    $html = ob_get_clean();
    $write($key_for($info['id'], $info['doc']), $html);
    echo $html; // Existing rendered Elementor output, no markup alterations on cache MISS.
}, 999999);
