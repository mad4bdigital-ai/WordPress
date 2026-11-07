<?php
/**
 * Plugin Name: MAD4B · Shared First-Paint Performance
 * Description: Tenant-local font, public CSS stability, above-fold destination and responsive brand image optimizations.
 * Version: 1.0.4
 */
defined('ABSPATH') || exit;

final class MAD4B_Shared_First_Paint_Performance {
    private static function owner(): bool {
        // Elementor invokes homepage() for each rendered widget. Avoid repeated
        // WPML home_url() conversion and brand-option lookup within one request.
        // Blog and language are both part of the key: a WPML language switch or
        // multisite blog switch must never inherit another context's decision.
        static $by_context = [];
        $language = (string)apply_filters('wpml_current_language', '');
        $key = get_current_blog_id() . '|' . $language;
        if (array_key_exists($key, $by_context)) return $by_context[$key];
        $context = get_option('mad4b_scp_brand_context_profile_v1', []);
        $uuid = is_array($context) ? (string)($context['site_uuid'] ?? '') : '';
        return $by_context[$key] = (
            (bool)preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $uuid)
            && class_exists('MAD4B_Experience_Site_Scope_V1', false)
            && MAD4B_Experience_Site_Scope_V1::bound()
        );
    }

    public static function home_view(): bool {
        if (is_front_page()) return true;
        if (!is_page()) return false;
        $current=(int)get_queried_object_id();
        $front=(int)get_option('page_on_front',0);
        if (!$current || !$front) return false;
        $language=(string)apply_filters('wpml_current_language',null);
        $translated=(int)apply_filters('wpml_object_id',$front,'page',false,$language);
        return $translated>0 && $current===$translated;
    }

    private static function homepage(): bool {
        return self::owner() && !is_admin() && !wp_doing_ajax() && !is_preview() && self::home_view();
    }

    public static function locally_hosted_font(): void {
        if (!self::homepage()) return;
        $font_files = glob(WP_CONTENT_DIR.'/uploads/elementor/google-fonts/fonts/cairo-*.woff2');
        if (!$font_files) return;
        global $wp_styles;
        if (!($wp_styles instanceof WP_Styles)) return;
        $handle = $wp_styles->registered['astra-google-fonts'] ?? null;
        $src = is_object($handle) ? (string)$handle->src : '';
        // Only the redundant Cairo-only external stylesheet; never drop other families.
        if (!preg_match('~^https://fonts\.googleapis\.com/css\?family=Cairo(?::|%3A)~i', $src)) return;
        wp_dequeue_style('astra-google-fonts');
    }

    private static function safe_public_css(): bool {
        if (!self::homepage() || is_user_logged_in() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || (!empty($_SERVER['QUERY_STRING']) &&
              ((string)$_SERVER['QUERY_STRING'] !== 'mad4b_predictive_css_probe=1'
               || wp_get_environment_type() !== 'staging'))
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY']) || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) return false;
        $uri = (string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        // WPML may use a translated homepage slug (e.g. /es/pagina-de-inicio/)
        // rather than /es/. Match only the canonical permalink for the proven
        // translated front-page post, never arbitrary localized pages.
        $front_id = (int)get_queried_object_id();
        $canonical = $front_id > 0 ? get_permalink($front_id) : false;
        $canonical_path = is_string($canonical) ? (string)wp_parse_url($canonical, PHP_URL_PATH) : '';
        if ($uri === '' || $canonical_path === '' || $uri !== $canonical_path
            || (string)wp_parse_url((string)$canonical, PHP_URL_HOST)
                !== (string)wp_parse_url((string)get_option('home',''), PHP_URL_HOST)
            || wp_parse_url((string)$canonical, PHP_URL_QUERY) !== null) return false;
        $locale = apply_filters('wpml_current_language', null);
        if (!is_string($locale) || $locale === '') return false;
        $header = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if (strlen($header) > 4096) return false;
        foreach (explode(';', $header) as $item) {
            $item = trim($item);
            if ($item === '') continue;
            $parts = explode('=', $item, 2);
            if (count($parts) !== 2) return false;
            $key = trim($parts[0]);
            if ($key === 'wp-wpml_current_language') {
                if (!hash_equals($locale, rawurldecode($parts[1]))) return false;
                continue;
            }
            // LiteSpeed's first-visitor cache-variation cookie is not an auth
            // or pricing credential. Exclude per-item JetEngine dynamic CSS
            // even after LiteSpeed issues this tightly validated cookie.
            if ($key === '_lscache_vary') {
                if (!preg_match('/^[A-Za-z0-9_.%\-]{1,128}$/D',$parts[1])) return false;
                continue;
            }
            if (preg_match('/^(?:sbjs_[a-z_]+|_ga(?:_[a-zA-Z0-9]{4,24})?|_gid|_gcl_au)$/D', $key)) continue;
            return false;
        }
        return true;
    }

    public static function stable_css_combination(): void {
        if (!self::safe_public_css()
            || (string)get_option('litespeed.conf.optm-css_comb') !== '1'
            || (string)get_option('litespeed.conf.optm-css_comb_ext_inl') !== '1') return;
        // JetEngine inserts different elementor-dcss-* selectors per rendered city.
        // Retain those per-item rules outside LiteSpeed's reusable static CSS bundle.
        add_filter('litespeed_optimize_css_excludes', static function($excludes) {
            $excludes = is_array($excludes) ? $excludes : [];
            $excludes[] = 'elementor-dcss-';
            return array_values(array_unique($excludes));
        }, 9998);
    }

    private static function city_widget_selector(): string {
        // Company-specific Elementor widget id: no imported Tours.dev DOM ids.
        // The selected card must have the same Elementor widget structure and
        // an approved local 768px asset; missing configuration is a safe no-op.
        $id = (string)get_option('mad4b_perf_city_image_widget_id_v1','');
        return preg_match('/^[a-f0-9]{5,16}$/iD',$id)
            ? '.elementor-element-'.strtolower($id) : '';
    }
    private static function brand_widget_id(): string {
        $id = (string)get_option('mad4b_perf_brand_image_widget_id_v1','');
        return preg_match('/^[a-f0-9]{5,16}$/iD',$id) ? strtolower($id) : '';
    }

    private static function optimized_city(): ?array {
        if (!self::homepage()) return null;
        $base_id = (int)get_option('mad4b_perf_primary_city_term_id', 0);
        if (!$base_id) return null;
        $language=(string)apply_filters('wpml_current_language',null);
        $id=(int)apply_filters('wpml_object_id',$base_id,'destinations',false,$language);
        if (!$id) return null;
        $term = get_term($id, 'destinations');
        if (!$term || is_wp_error($term)) return null;
        $url = (string)get_term_meta($id, 'featured_image', true);
        $parsed = wp_parse_url($url);
        if (!is_array($parsed) || strtolower((string)($parsed['host'] ?? ''))
            !== strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST))) return null;
        $path = (string)($parsed['path'] ?? '');
        $prefix = '/wp-content/uploads/';
        if (!str_starts_with($path, $prefix)) return null;
        $base = realpath(WP_CONTENT_DIR.'/uploads');
        $origin = realpath(WP_CONTENT_DIR.'/uploads/'.rawurldecode(substr($path,strlen($prefix))));
        if (!$base || !$origin || !str_starts_with($origin, $base.'/')) return null;
        $optimized = $origin.'.mad4b-768.webp';
        if (!is_file($optimized)) return null;
        $relative = substr($optimized,strlen($base)+1);
        $public = set_url_scheme(content_url('/uploads/'.$relative),'https');
        return ['id'=>$id,'url'=>$public];
    }

    public static function mobile_city_lcp(): void {
        $city = self::optimized_city();
        if (!$city) return;
        $id = $city['id'];
        if (self::city_widget_selector()==='') return;
        $url = esc_url($city['url']);
        echo '<link rel="preload" as="image" media="(max-width: 767px)" fetchpriority="high" href="'.$url.'" data-mad4b-first-city="1">'."\n";
        // Apply to the initial selected destination only. Do not eagerly force
        // all off-screen Swiper slides to download their background images.
        // The first city LCP asset must not wait for the geometry-lazy JS
        // class. Scope this higher-specificity override to this selected
        // destination only; all other Swiper slides stay lazy until visible.
        $widget_selector=self::city_widget_selector();
        if ($widget_selector==='') return;
        $first_city_selector='.jet-listing-grid__item.jet-listing-dynamic-post-'.(int)$id.' '.$widget_selector;
        echo '<style id="mad4b-mobile-lcp-city" data-no-optimize="1">@media(max-width:767px){'
            .'html.mad4b-city-geometry-lazy '.$first_city_selector.','.$first_city_selector
            .'{background-image:url("'.$url.'") !important;background-position:center;background-size:cover;}'
            .'}</style>'."\n";
    }

    public static function local_city_card_assets(): void {
        if (!self::homepage() || self::city_widget_selector()==='') return;
        $ids=get_option('mad4b_perf_home_city_card_ids_v1',[]);
        if (!is_array($ids)||!$ids) return;
        $rules=[];
        $host=strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST));
        $root=realpath(WP_CONTENT_DIR.'/uploads');
        if (!$root) return;
        $language=(string)apply_filters('wpml_current_language',null);
        foreach(array_slice(array_unique(array_map('absint',$ids)),0,12) as $base_id) {
            if(!$base_id)continue;
            $id=(int)apply_filters('wpml_object_id',$base_id,'destinations',false,$language);
            if(!$id)continue;
            $term=get_term($id,'destinations');
            if(!$term||is_wp_error($term))continue;
            $url=(string)get_term_meta($id,'featured_image',true);
            $meta=wp_parse_url($url);
            if(!is_array($meta)||strtolower((string)($meta['host']??''))!==$host)continue;
            $path=(string)($meta['path']??'');
            if(!str_starts_with($path,'/wp-content/uploads/'))continue;
            $origin=realpath($root.'/'.rawurldecode(substr($path,strlen('/wp-content/uploads/'))));
            if(!$origin || !str_starts_with($origin,$root.'/'))continue;
            $optimized=$origin.'.mad4b-768.webp';
            if(!is_file($optimized))continue;
            $public=set_url_scheme(content_url('/uploads/'.substr($optimized,strlen($root)+1)),'https');
            $rules[]='.jet-listing-grid__item.jet-listing-dynamic-post-'.$id
                    .' '.self::city_widget_selector().'{background-image:url("'.esc_url($public).'") !important;}';
        }
        if($rules)echo '<style id="mad4b-local-city-card-assets" data-no-optimize="1">'
            .implode('',$rules).'</style>'.PHP_EOL;
    }

    public static function responsive_brand_image($html, $widget) {
        if (!self::homepage() || !is_object($widget)
            || !method_exists($widget,'get_id') || !method_exists($widget,'get_name')
            || $widget->get_name() !== 'image'
            || self::brand_widget_id()===''
            || strtolower((string)$widget->get_id()) !== self::brand_widget_id()
            || !class_exists('WP_HTML_Tag_Processor')) return $html;
        if (strpos((string)$html,'/company-brands/') === false) return $html;
        $tag = new WP_HTML_Tag_Processor((string)$html);
        while ($tag->next_tag('img')) {
            $src = (string)$tag->get_attribute('src');
            $uri = wp_parse_url($src);
            if (!is_array($uri) || ($uri['host'] ?? '') !== wp_parse_url((string)get_option('home',''),PHP_URL_HOST)) continue;
            $path=(string)($uri['path']??'');
            if (!str_starts_with($path,'/wp-content/uploads/company-brands/')) continue;
            $basename = pathinfo(rawurldecode($path),PATHINFO_FILENAME);
            $basename = (string)preg_replace('/-\d+x\d+$/D','',$basename);
            $newpath = dirname(rawurldecode($path)).'/'.$basename.'-mad4b-220.webp';
            $uploads = realpath(WP_CONTENT_DIR.'/uploads');
            $full = realpath(ABSPATH.ltrim($newpath,'/'));
            if (!$uploads || !$full || !str_starts_with($full,$uploads.'/')) continue;
            $url = set_url_scheme(content_url(substr($newpath,strlen('/wp-content'))),'https');
            $small_path = str_replace('-mad4b-220.webp','-mad4b-160.webp',$newpath);
            $small_file = realpath(ABSPATH.ltrim($small_path,'/'));
            $small_url = ($small_file && str_starts_with($small_file,$uploads.'/'))
                ? set_url_scheme(content_url(substr($small_path,strlen('/wp-content'))),'https')
                : $url;
            $tag->set_attribute('src',$small_url);
            $tag->set_attribute('srcset',$small_url.' 160w, '.$url.' 220w');
            $tag->set_attribute('sizes','(max-width: 767px) 74px, 110px');
            $tag->set_attribute('width','160');
            $tag->set_attribute('height','105');
            $tag->set_attribute('loading','eager');
            return $tag->get_updated_html();
        }
        return $html;
    }

    public static function remove_duplicate_hero_preload(): void {
        // The original Staging provider preloads the same hero poster, sometimes
        // using a language-prefixed /es/wp-content path. The shared hero owner
        // is the only preload producer; keep the former critical CSS and Swiper policy.
        if (class_exists('MAD4B_Critical_Front_Scripts_V1')
            && has_action('wp_head',['MAD4B_Critical_Front_Scripts_V1','poster_preload']) !== false) {
            remove_action('wp_head',['MAD4B_Critical_Front_Scripts_V1','poster_preload'],1);
        }
    }

    public static function start(): void {
        if (!self::owner()) return;
        add_action('wp', [__CLASS__,'remove_duplicate_hero_preload'], 120);
        add_action('wp', [__CLASS__,'stable_css_combination'], 105);
        add_action('wp_enqueue_scripts', [__CLASS__,'locally_hosted_font'], 99999);
        add_action('wp_head', [__CLASS__,'mobile_city_lcp'], 3);
        add_action('wp_head', [__CLASS__,'local_city_card_assets'], 4);
        add_filter('elementor/widget/render_content',[__CLASS__,'responsive_brand_image'],120,2);
    }
}
add_action('init', ['MAD4B_Shared_First_Paint_Performance','start'], 12);
