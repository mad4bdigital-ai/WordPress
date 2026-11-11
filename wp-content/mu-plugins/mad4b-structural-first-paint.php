<?php
/**
 * Plugin Name: MAD4B Structural First Paint
 * Version: 1.4.3
 * Description: Prime responsive layout geometry from current Elementor CSS before late assets/widgets; company neutral.
 */
defined('ABSPATH') || exit;
final class MAD4B_Structural_First_Paint {
    const OPTION = 'mad4b_perf_structural_first_paint_v1';
    const GROUP = 'mad4b-first-paint';
    const MAX_OUTPUT = 115000;

    private static function eligible(): bool {
        if (get_option(self::OPTION, 'off') !== 'on' || is_admin() || is_user_logged_in()
            || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || is_feed() || is_preview() || !(is_singular() || is_archive() || is_search())
            || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
        if (function_exists('is_checkout') && (is_checkout() || is_cart() || is_account_page())) return false;
        return is_singular() ? (bool)get_queried_object_id() : true;
    }
    private static function linked_templates(int $postId, int $limit = 2): array {
        $data = json_decode((string)get_post_meta($postId, '_elementor_data', true), true);
        if (!is_array($data)) return [];
        $found = [];
        $walk = static function(array $nodes) use (&$walk, &$found, $limit): void {
            foreach ($nodes as $node) {
                if (!is_array($node) || count($found) >= $limit) return;
                $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
                $type = (string)($node['widgetType'] ?? '');
                $id = 0;
                if ($type === 'template') $id = absint($settings['template_id'] ?? 0);
                if ($type === 'shortcode' && preg_match('/\[elementor-template\s+id=["\x27]?(\d+)/i',(string)($settings['shortcode']??''),$m)) $id = (int)$m[1];
                if ($id) $found[$id] = $id;
                if (!empty($node['elements']) && is_array($node['elements'])) $walk($node['elements']);
            }
        };
        $walk($data);
        return array_values($found);
    }
    public static function prime_private_home_templates(): void {
        if (!is_front_page() || !is_user_logged_in() || is_admin() || is_preview()
            || wp_doing_ajax() || !empty($_GET['elementor-preview'])
            || !class_exists(\Elementor\Core\Files\CSS\Post::class)) return;
        // Elementor shortcodes may enqueue the hero stylesheet after wp_head.
        // Reuse its native handles, responsive rules, and translated document.
        foreach (self::linked_templates((int)get_queried_object_id(), 2) as $id) {
            $id = (int) apply_filters('wpml_object_id', $id, 'elementor_library', true);
            if ($id && get_post_status($id) === 'publish') {
                \Elementor\Core\Files\CSS\Post::create($id)->enqueue();
            }
        }
    }
    private static function documents(): array {
        $ids = [];
        if (class_exists('\\ElementorPro\\Modules\\ThemeBuilder\\Module')) {
            try {
                $manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
                $locations = ['header', is_singular() ? 'single' : 'archive'];
                foreach ($locations as $location) {
                    foreach ((array)$manager->get_documents_for_location($location) as $id => $doc) {
                        if ($id > 0) { $ids[(int)$id] = (int)$id; break; }
                    }
                }
            } catch (\Throwable $e) { /* No guessed header; normal CSS remains intact. */ }
        }
        $pageId = is_singular() ? (int)get_queried_object_id() : 0;
        if ($pageId) $ids[$pageId] = $pageId;
        foreach (self::linked_templates($pageId, 2) as $id) {
            $ids[$id] = $id;
            $tree = json_decode((string)get_post_meta($id, '_elementor_data', true), true);
            if (!is_array($tree)) continue;
            $scan = static function(array $nodes) use (&$scan, &$ids): void {
                foreach ($nodes as $node) {
                    if (!is_array($node)) continue;
                    if (($node['widgetType'] ?? '') === 'jet-listing-grid') {
                        $listing = absint($node['settings']['lisitng_id'] ?? 0);
                        if ($listing && count($ids) < 8) $ids[$listing] = $listing;
                    }
                    if (!empty($node['elements']) && is_array($node['elements'])) $scan($node['elements']);
                }
            };
            $scan($tree);
        }
        return array_slice(array_values($ids), 0, 6);
    }
    private static function is_structural(string $name): bool {
        if (str_starts_with($name, '--')) return true;
        return (bool)preg_match('/^(?:display|position|box-sizing|width|height|min-width|max-width|min-height|max-height|aspect-ratio|border-radius|overflow|overflow-x|overflow-y|padding(?:-[a-z]+)?|margin(?:-[a-z]+)?|gap|row-gap|column-gap|flex(?:-[a-z]+)?|grid(?:-[a-z]+)?|align-(?:items|self|content)|justify-(?:content|items|self)|inset|top|right|bottom|left|font-size|font-family|font-style|font-weight|line-height|letter-spacing|text-align|text-wrap|text-transform|word-break|direction|order|object-fit|object-position|background-position|background-size|background-repeat|vertical-align|white-space)$/D', $name);
    }
    private static function extract(string $css, int $limit): string {
        // Generated Elementor CSS has flat rules and @media wrappers. Non-layout
        // properties are discarded so assets, visibility and animations remain native.
        $parts = preg_split('/([{}])/', $css, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return '';
        $stack = []; $pending = ''; $result = '';
        foreach ($parts as $part) {
            if ($part === '{') { $stack[] = trim($pending); $pending = ''; continue; }
            if ($part !== '}') { $pending .= $part; continue; }
            if (!$stack) { $pending = ''; continue; }
            $selector = array_pop($stack); $body = trim($pending); $pending = '';
            if ($selector === '' || $selector[0] === '@') continue;
            foreach ($stack as $ancestor) if (str_starts_with($ancestor, '@keyframes')) continue 2;
            $declarations = [];
            foreach (explode(';', $body) as $declaration) {
                $at = strpos($declaration, ':');
                if ($at === false) continue;
                $property = strtolower(trim(substr($declaration, 0, $at)));
                $value = trim(substr($declaration, $at + 1));
                if ($value !== '' && self::is_structural($property)) $declarations[] = $property . ':' . $value;
            }
            if (!$declarations) continue;
            $rule = $selector . '{' . implode(';', $declarations) . '}';
            foreach (array_reverse($stack) as $ancestor) {
                if (preg_match('/^@(?:media|supports|container)\b/i', $ancestor)) $rule = $ancestor . '{' . $rule . '}';
            }
            if (strlen($result) + strlen($rule) > $limit) break;
            $result .= $rule;
        }
        return $result;
    }
    private static function carousel_stability(array $documents): string {
        $rules = ''; $seen = [];
        foreach ($documents as $docId) {
            $data = json_decode((string)get_post_meta($docId, '_elementor_data', true), true);
            if (!is_array($data)) continue;
            $walk = static function(array $nodes) use (&$walk, &$rules, &$seen): void {
                foreach ($nodes as $node) {
                    if (!is_array($node) || count($seen) >= 12) return;
                    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
                    if (($node['widgetType'] ?? '') === 'jet-listing-grid'
                        && in_array($settings['carousel_enabled'] ?? null, ['yes','true',true,1],true)) {
                        $id = (string)($node['id'] ?? '');
                        if (!preg_match('/^[a-f0-9]{5,16}$/D',$id) || isset($seen[$id])) continue;
                        $seen[$id]=true;
                        $base = '.elementor-element-' . $id
                            . ' .jet-listing-grid__slider.swiper-lib:not(.swiper-initialized)'
                            . ' > .swiper-wrapper > .swiper-slide';
                        $counts = [
                            max(1,min(8,(int)($settings['columns'] ?? 3))),
                            max(1,min(8,(int)($settings['columns_tablet'] ?? 2))),
                            max(1,min(8,(int)($settings['columns_mobile'] ?? 1))),
                        ];
                        $width = static fn($n) => 'width:calc(100% / '.$n.');flex:0 0 calc(100% / '.$n.');min-width:0;';
                        // Native vertical gestures must win over a horizontal Swiper.
                        // This rule is inferred only from Elementor's carousel widget.
                        $direction = (string)($settings['carousel_direction'] ?? $settings['slider_direction'] ?? 'horizontal');
                        if ($direction !== 'vertical') {
                            $slider = '.elementor-element-' . $id . ' .jet-listing-grid__slider.swiper-lib';
                            $rules .= $slider . '{touch-action:pan-y;overscroll-behavior-x:contain;}';
                        }
                        $rules .= $base . '{' . $width($counts[0]) . '}';
                        $rules .= '@media(max-width:1024px){'.$base.'{'.$width($counts[1]).'}}';
                        $rules .= '@media(max-width:767px){'.$base.'{'.$width($counts[2]).'}}';
                    }
                    if (!empty($node['elements']) && is_array($node['elements'])) $walk($node['elements']);
                }
            };
            $walk($data);
        }
        return $rules;
    }
    private static function header_asset_sources(): array {
        global $wp_styles;
        if (!($wp_styles instanceof WP_Styles)) return [];
        $assets = []; $folder = realpath(WP_CONTENT_DIR . '/mu-plugins/assets');
        if (!$folder) return [];
        $prefix = '/wp-content/mu-plugins/assets/';
        foreach (array_slice((array)$wp_styles->queue, 0, 90) as $handle) {
            $registered = $wp_styles->registered[$handle] ?? null;
            $src = is_object($registered) ? (string)($registered->src ?? '') : '';
            $path = (string)wp_parse_url($src, PHP_URL_PATH);
            if (!str_starts_with($path, $prefix) || !preg_match('/\.css$/i', $path)) continue;
            $host = wp_parse_url($src, PHP_URL_HOST);
            if ($host && strcasecmp((string)$host, (string)wp_parse_url(home_url('/'),PHP_URL_HOST)) !== 0) continue;
            $real = realpath($folder . '/' . substr($path, strlen($prefix)));
            if (!$real || !str_starts_with($real, $folder . '/') || is_link($real)
                || !is_file($real) || filesize($real) > 55000) continue;
            $text = file_get_contents($real);
            if (!is_string($text) || !preg_match('/\.(?:elementor-location-header|site-header|header-context-|e-n-menu|e-n-tabs)/', $text)) continue;
            $assets[] = [$real, (int)filemtime($real), (int)filesize($real)];
            if (count($assets) >= 8) break;
        }
        return $assets;
    }
    public static function preload_critical_carousel(): void {
        if (!self::eligible()) return;
        $hasSlider = false;
        foreach (self::documents() as $id) {
            $tree = json_decode((string)get_post_meta($id,'_elementor_data',true),true);
            if (!is_array($tree)) continue;
            $walk = static function(array $nodes) use (&$walk,&$hasSlider): void {
                foreach ($nodes as $element) {
                    if (!is_array($element) || $hasSlider) continue;
                    $settings = (array)($element['settings'] ?? []);
                    if (($element['widgetType'] ?? '') === 'jet-listing-grid'
                        && in_array($settings['carousel_enabled'] ?? null, ['yes','true',true,1],true)) {
                        $hasSlider = true; return;
                    }
                    if (!empty($element['elements']) && is_array($element['elements'])) $walk($element['elements']);
                }
            };
            $walk($tree);
            if ($hasSlider) break;
        }
        if (!$hasSlider) return;
        $scripts = wp_scripts();
        $registered = $scripts->registered['swiper'] ?? null;
        if (!is_object($registered) || empty($registered->src)) return;
        $url = (string)$registered->src;
        if (str_starts_with($url, '//')) return;
        if (str_starts_with($url, '/')) $url = home_url($url);
        elseif (!preg_match('~^https?://~i',$url)) $url = $scripts->base_url . '/' . ltrim($url,'/');
        $host = (string)wp_parse_url($url,PHP_URL_HOST);
        $origin = (string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        $path = (string)wp_parse_url($url,PHP_URL_PATH);
        if (strcasecmp($host,$origin) !== 0
            || !preg_match('~^/wp-content/plugins/elementor/assets/lib/swiper/[a-z0-9/.-]+\.js$~iD',$path)) return;
        echo '<link rel="preload" as="script" fetchpriority="low" href="' . esc_url($url) . '" data-mad4b-swiper-critical="1">' . "\n";
    }
    /** Independent of structural CSS: safe touch gesture for initialized horizontal carousels. */
    public static function scroll_gesture(): void {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        echo '<style id="mad4b-native-vertical-gesture" data-no-optimize="1">'
            . '@media(pointer:coarse){.jet-listing-grid__slider.swiper-lib.swiper-horizontal{touch-action:pan-y!important;overscroll-behavior-x:contain}}'
            . '</style>' . "\n";
    }
    public static function emit(): void {
        if (!self::eligible()) return;
        $documents = self::documents();
        $sources = []; $base = WP_CONTENT_DIR . '/uploads/elementor/css/';
        foreach ($documents as $id) {
            $file = $base . 'post-' . $id . '.css';
            if (!is_file($file) || is_link($file) || filesize($file) > 130000) continue;
            $sources[] = [$file, (int)filemtime($file), (int)filesize($file)];
        }
        $sources = array_merge($sources, self::header_asset_sources());
        if (!$sources) return;
        $key = 'layout-' . substr(hash('sha256', (string)filemtime(__FILE__) . '|' . wp_json_encode($sources)),0,36);
        $critical = wp_cache_get($key, self::GROUP);
        if (!is_string($critical)) {
            $critical = '';
            foreach ($sources as $source) {
                $contents = file_get_contents($source[0]);
                if (!is_string($contents)) continue;
                $remaining = self::MAX_OUTPUT - strlen($critical);
                if ($remaining < 500) break;
                $critical .= self::extract($contents, $remaining);
            }
            // Append only complete CSS rules. Never truncate in the middle of a block.
            wp_cache_set($key,$critical,self::GROUP,43200);
        }
        $critical .= self::carousel_stability($documents);
        if ($critical === '' || stripos($critical, '</style') !== false) return;
        echo '<style id="mad4b-structural-first-paint" data-no-optimize="1">' . $critical . '</style>' . "\n";
    }
}
add_action('wp_head',['MAD4B_Structural_First_Paint','scroll_gesture'],2);
add_action('wp_head',['MAD4B_Structural_First_Paint','emit'],2);
add_action('wp_head',['MAD4B_Structural_First_Paint','preload_critical_carousel'],4);

add_action('wp_enqueue_scripts',[MAD4B_Structural_First_Paint::class,'prime_private_home_templates'],30);
