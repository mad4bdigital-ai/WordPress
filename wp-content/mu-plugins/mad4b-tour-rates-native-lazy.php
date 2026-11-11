<?php
/**
 * Plugin Name: MAD4B Tour Rates — Native Lazy Listing
 * Description: Defer only the expensive public rate-card/listing markup via JetEngine's signed native AJAX. Pricing and booking engines stay untouched.
 * Version: 1.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Rates_Native_Lazy_V1 {
    private static ?bool $enabled = null;
    private static int $deferred = 0;
    private static int $signed_ajax_tour = 0;
    private static string $signed_ajax_lang = '';
    private static int $signed_ajax_listing = 0;
    private static int $active_rate = 0;

    // JetEngine calls this after it restores the widget from its saved Elementor
    // document and validates its settings signature. Never accept a client-side
    // tour ID without validating the public page, canonical route and locale.
    public static function verify_signed_rate_ajax(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || (string)($_REQUEST['action']??'') !== 'jet_engine_ajax'
            || (string)($_REQUEST['handler']??'') !== 'get_listing'
            || (string)($_REQUEST['listing_type']??'') !== 'elementor') return;
        $settings=$_REQUEST['widget_settings']??[];
        $page=$_REQUEST['page_settings']??[];
        if (!is_array($settings) || !is_array($page)) return;
        $widget_id=(string)($settings['_element_id']??'');
        if (!in_array($widget_id,['tour_rates_accordion','tour_rates'],true)
            || empty($settings['custom_query'])
            || !filter_var($settings['custom_query'],FILTER_VALIDATE_BOOLEAN)) return;
        $query_id=absint($settings['custom_query_id']??0);
        $listing_id=absint($settings['lisitng_id']??0);
        if (!$query_id || !$listing_id || get_post_status($listing_id)!=='publish'
            || !class_exists('\Jet_Engine\Query_Builder\Manager')) return;
        $query=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($query_id);
        if (!$query || $query->query_type!=='posts'
            || !preg_match('/rates?/i',(string)$query->name)) return;
        $queried=(string)($page['queried_id']??'');
        if (!preg_match('/^([1-9][0-9]*)[|]WP_Post$/D',$queried,$match)) return;
        $tour=absint($match[1]);
        if (get_post_type($tour)!=='tours-and-activities'
            || get_post_status($tour)!=='publish'
            || (int)get_queried_object_id()!==$tour
            || !is_singular('tours-and-activities')) return;
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $canonical=(string)wp_parse_url((string)get_permalink($tour),PHP_URL_PATH);
        if ($path==='' || $path!==$canonical) return;
        $language_data=apply_filters('wpml_post_language_details',null,$tour);
        $language=is_array($language_data)?(string)($language_data['language_code']??''):'';
        $current=(string)apply_filters('wpml_current_language','');
        $default=(string)apply_filters('wpml_default_language','');
        if (!preg_match('/^[a-z]{2,5}$/D',$language) || $current!==$language
            || $current===$default) return;
        self::$signed_ajax_tour=$tour;
        self::$signed_ajax_lang=$language;
        self::$signed_ajax_listing=$listing_id;
    }

    public static function trusted_ajax_language(int $tour): string {
        return $tour>0 && self::$signed_ajax_tour===$tour
            ? self::$signed_ajax_lang : '';
    }
    // JetEngine's first Ajax Rate item can lose its item context when
    // Elementor switches to the main Tour while opening the listing document.
    // Capture only a published Rate belonging to the signed Tour and the
    // matching WPML language. Do not mutate the saved Elementor document.
    public static function capture_rate($item): void {
        if (!($item instanceof \WP_Post) || $item->post_type!=='tour-rates'
            || get_post_status($item)!=='publish' || !self::$signed_ajax_listing) return;
        $owner=absint(get_post_meta($item->ID,'related_tour_id',true));
        if (!$owner || $owner!==self::$signed_ajax_tour
            || self::trusted_ajax_language($owner)==='')return;
        $details=apply_filters('wpml_post_language_details',null,$item->ID);
        if (!is_array($details)
            || (string)($details['language_code']??'')!==self::$signed_ajax_lang)return;
        self::$active_rate=$item->ID;
    }
    public static function restore_elementor_rate($nodes,$document_id) {
        if (!is_array($nodes) || (int)$document_id!==self::$signed_ajax_listing
            || !self::$signed_ajax_tour)return $nodes;
        // Native JetEngine object-stack retains the real listing item, even
        // when Elementor temporarily resets $post to the parent Tour.
        // Prefer this verified stack identity over request-local memory.
        $rate=null;
        $stack=jet_engine()->listings->objects_stack->get_stack();
        if (is_array($stack)) {
            foreach (array_reverse($stack) as $item) {
                if ($item instanceof \WP_Post && $item->post_type==='tour-rates'
                    && absint(get_post_meta($item->ID,'related_tour_id',true))===self::$signed_ajax_tour) {
                    $rate=$item;
                    break;
                }
            }
        }
        if (!$rate && self::$active_rate)$rate=get_post(self::$active_rate);
        if (!($rate instanceof \WP_Post) || $rate->post_type!=='tour-rates'
            || $rate->post_status!=='publish'
            || absint(get_post_meta($rate->ID,'related_tour_id',true))!==self::$signed_ajax_tour
            || self::trusted_ajax_language(self::$signed_ajax_tour)==='')return $nodes;
        // Restore JetEngine's own item state before Elementor starts the
        // listing document. Nested Rate pricing and Select Rate remain native.
        if ((int)jet_engine()->listings->data->get_current_object_id()!==$rate->ID) {
            $GLOBALS['post']=$rate;
            setup_postdata($rate);
            jet_engine()->listings->data->set_current_object($rate);
        }
        return $nodes;
    }
    // The eligible source has already enforced parent association, active
    // published status, Rate validity, currency and audience policy. Restrict
    // the WPML AJAX workaround to this verified native Rate query only.
    public static function maintain_verified_query($args,$query) {
        if (!is_array($args) || ($query->query_id??'')!=='tour_rates') return $args;
        $tour=absint($args['queried_object_id']??0);
        if (!$tour || self::trusted_ajax_language($tour)==='') return $args;
        $eligibility=function_exists('shared_product_eligible_tour_rate_ids')
            ? shared_product_eligible_tour_rate_ids($tour) : [];
        $post_in=array_map('absint',(array)($args['post__in']??[]));
        $post_in=array_values(array_intersect($post_in,$eligibility));
        $args['post__in']=$post_in?:[0];
        $args['suppress_filters']=true;
        return $args;
    }

    private static function public_tour_context(): bool {
        if (!is_singular('tours-and-activities')
            || is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
            || (function_exists('is_customize_preview') && is_customize_preview())
            || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;

        $mode=(string)get_option('mad4b_tour_rates_native_lazy_mode','canary');
        $is_canary=$mode==='canary' && isset($_GET['mad4b_rate_lazy_canary'])
            && (string)$_GET['mad4b_rate_lazy_canary']==='1';
        if ($mode!=='active' && !$is_canary) return false;
        foreach (array_keys((array)$_GET) as $k) {
            if (preg_match('/^(?:utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i', (string)$k)) continue;
            if ($is_canary && $k==='mad4b_rate_lazy_canary') continue;
            return false; // Filters, dates, market, permalink and booking context render natively.
        }
        return true;
    }
    private static function enabled(): bool {
        if (self::$enabled===null) self::$enabled=self::public_tour_context();
        return self::$enabled;
    }
    private static function is_rate_display($settings): bool {
        if (!self::enabled() || !is_array($settings) || empty($settings['custom_query'])
            || !filter_var($settings['custom_query'],FILTER_VALIDATE_BOOLEAN)) return false;
        $widget_id=(string)($settings['_element_id']??'');
        if (!in_array($widget_id,['tour_rates','tour_rates_accordion'],true)) return false;
        $query_id=absint($settings['custom_query_id']??0);
        $listing_id=absint($settings['lisitng_id']??0); // JetEngine's native key.
        if (!$query_id || !$listing_id || get_post_status($listing_id)!=='publish') return false;
        if (!class_exists('\Jet_Engine\Query_Builder\Manager')) return false;
        $query=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($query_id);
        if (!$query || $query->query_type!=='posts'
            || !preg_match('/rates?/i',(string)$query->name)) return false;
        foreach (['is_archive_template','pagination','use_load_more','use_load_more_pagination'] as $setting) {
            if (!empty($settings[$setting]) && filter_var($settings[$setting],FILTER_VALIDATE_BOOLEAN)) return false;
        }
        return true;
    }
    public static function lazy($current,$settings) {
        if ($current) return $current;
        if (!self::is_rate_display($settings)) return $current;
        self::$deferred++;
        return true;
    }
    public static function options($options,$settings) {
        if (is_array($options) && self::is_rate_display($settings)) {
            // Load just before the section approaches the viewport.
            $offset=(int)get_option('mad4b_tour_rates_native_lazy_offset',360);
            $options['offset']=max(0,min(800,$offset)).'px';
        }
        return $options;
    }
    public static function qa() {
        if (!isset($_GET['mad4b_rate_lazy_canary'])
            || (string)$_GET['mad4b_rate_lazy_canary']!=='1'
            || !self::enabled()) return;
        printf("\n<!-- MAD4B_RATE_NATIVE_LAZY deferred=%d mode=%s -->\n",
            self::$deferred,esc_html((string)get_option('mad4b_tour_rates_native_lazy_mode','canary')));
    }
}
add_filter('jet-engine/listing/grid/is_lazy_load',
    ['MAD4B_Tour_Rates_Native_Lazy_V1','lazy'],50,2);
add_filter('jet-engine/listing/grid/lazy-load/options',
    ['MAD4B_Tour_Rates_Native_Lazy_V1','options'],50,2);
add_action('wp_footer',['MAD4B_Tour_Rates_Native_Lazy_V1','qa'],999);

// A read-only context binding for the one native Rate listing AJAX handler.
add_action('jet-engine/ajax-handlers/before-call-handler',
    ['MAD4B_Tour_Rates_Native_Lazy_V1','verify_signed_rate_ajax'],10);
add_filter('jet-engine/query-builder/types/posts-query/args',
    ['MAD4B_Tour_Rates_Native_Lazy_V1','maintain_verified_query'],999,2);
// Preserve the signed per-Rate context across the initial Elementor
// document render, when JetEngine's main query is temporarily the Tour.
add_action('jet-engine/listings/frontend/setup-data',
    ['MAD4B_Tour_Rates_Native_Lazy_V1','capture_rate'],500,1);
add_filter('elementor/frontend/builder_content_data',
    ['MAD4B_Tour_Rates_Native_Lazy_V1','restore_elementor_rate'],PHP_INT_MAX,2);

