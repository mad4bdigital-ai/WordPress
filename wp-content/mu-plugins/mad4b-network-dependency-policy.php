<?php
/**
 * Plugin Name: MAD4B Adaptive Network Dependency Policy
 * Version: 0.1.2
 * Description: Company-neutral runtime asset graph, source-aware safe trimming, native Woo and Elementor fallback.
 */
defined('ABSPATH') || exit;
final class MAD4B_Network_Dependency_Policy {
    const OPT = 'mad4b_network_dependency_policy_v1';
    const GRAPH = 'mad4b_network_dependency_graph_v1';
    const GROUP = 'mad4b-network-dependency';
    private static $verdict = 'unchanged';

    private static function public_request(): bool {
        if (is_admin() || wp_doing_ajax() || is_user_logged_in()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || is_preview()) return false;
        if (function_exists('is_checkout') && (is_checkout() || is_cart() || is_account_page())) return false;
        return is_front_page() || is_singular() || is_archive() || is_search();
    }
    private static function gallery_inventory(): string {
        $cache = wp_cache_get('gallery-published-v1',self::GROUP);
        if (in_array($cache,['present','absent'],true)) return $cache;
        // Public requests must never scan large Elementor JSON or post_content.
        // Preserve the gallery script if the prior verified inventory is absent.
        $persisted=get_transient('mad4b_gallery_inventory_published_v2');
        if (in_array($persisted,['present','absent'],true)) {
            wp_cache_set('gallery-published-v1',$persisted,self::GROUP,1800);
            return $persisted;
        }
        return 'unknown';
    }
    /**
     * Explicit maintenance-only refresh, never called from wp_enqueue_scripts.
     * Schedule through an authorized off-peak Content Job when needed.
     */
    public static function refresh_gallery_inventory(): string {
        global $wpdb;
        // Both Elementor widgets and WordPress block/shortcode references count.
        // A new published gallery disables optimization until a route-specific
        // dependency proof is available. Unknown means preserve native scripts.
        $meta = $wpdb->get_var(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
             WHERE p.post_status='publish' AND m.meta_key='_elementor_data'
             AND (m.meta_value LIKE '%jet-woo-product-gallery-slider%'
               OR m.meta_value LIKE '%jet-woo-product-gallery-grid%'
               OR m.meta_value LIKE '%jet-woo-product-gallery-modern%'
               OR m.meta_value LIKE '%jet-woo-product-gallery-anchor%') LIMIT 1"
        );
        if ($wpdb->last_error !== '') return 'unknown';
        $content = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish'
             AND post_content LIKE '%jet-woo-product-gallery%' LIMIT 1"
        );
        if ($wpdb->last_error !== '') return 'unknown';
        $state = ($meta || $content) ? 'present' : 'absent';
        // A transient is persistent across PHP-FPM workers even if
        // the object-cache backend is unavailable; save_post/meta hooks
        // invalidate it. A stale or missing result is fail-safe 'unknown'.
        set_transient('mad4b_gallery_inventory_published_v2',$state,1800);
        wp_cache_set('gallery-published-v1',$state,self::GROUP,1800);
        return $state;
    }
    public static function invalidate(): void {
        wp_cache_delete('gallery-published-v1',self::GROUP);
        delete_transient('mad4b_gallery_inventory_published_v2');
    }
    public static function invalidate_elementor_meta($meta_id, $post_id, $meta_key): void {
        if ($meta_key === '_elementor_data') self::invalidate();
    }
    public static function constrain(): void {
        if (!self::public_request()) return;
        if (get_option(self::OPT,'off') !== 'on') return;
        if (!wp_script_is('jet-swiper','enqueued')) { self::$verdict='jet_swiper_not_queued'; return; }
        if (function_exists('is_product') && is_product()) { self::$verdict='product_gallery_preserved'; return; }
        if (function_exists('is_woocommerce') && is_woocommerce()) { self::$verdict='woocommerce_context_preserved'; return; }
        $scripts=wp_scripts();
        $asset=$scripts->registered['jet-swiper']??null;
        if (!is_object($asset) || strpos((string)$asset->src,'/jet-woo-product-gallery/assets/lib/swiper/')===false) {
            self::$verdict='owner_mismatch_preserved'; return;
        }
        foreach ((array)$scripts->queue as $handle) {
            if ($handle === 'jet-swiper') continue;
            if (in_array('jet-swiper',(array)($scripts->registered[$handle]->deps??[]),true)) {
                self::$verdict='active_dependency_preserved'; return;
            }
        }
        $inventory=self::gallery_inventory();
        if ($inventory !== 'absent') { self::$verdict='gallery_or_unknown_preserved'; return; }
        wp_dequeue_script('jet-swiper');
        self::$verdict='unused_gallery_swiper_dequeued';
    }
    private static function asset_depth(string $handle, array &$cache, array &$stack, $scripts): int {
        if (isset($cache[$handle])) return $cache[$handle];
        if (isset($stack[$handle])) return 0;
        $stack[$handle]=true; $max=0;
        foreach ((array)($scripts->registered[$handle]->deps??[]) as $dep) {
            $max=max($max,self::asset_depth((string)$dep,$cache,$stack,$scripts));
        }
        unset($stack[$handle]);
        return $cache[$handle]=min(32,1+$max);
    }
    public static function observe(): void {
        if (!self::public_request() || get_option(self::OPT,'off') !== 'on') return;
        $id=(int)get_queried_object_id();
        $route=is_front_page()?'front_page':(is_singular()?'single_'.sanitize_key(get_post_type($id)):(is_search()?'search':'archive'));
        $lang=apply_filters('wpml_current_language',null);
        $lang=is_string($lang)&&preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/i',$lang)?$lang:substr(determine_locale(),0,2);
        $key=substr(hash('sha256',$route.'|'.$id.'|'.$lang),0,18);
        if (!wp_cache_add('throttle-'.$key,1,self::GROUP,1800)) return;
        $scripts=wp_scripts(); $styles=wp_styles();
        $queued=array_slice(array_values(array_unique((array)$scripts->queue)),0,240);
        $depths=[];$maxDepth=0;$branches=[];
        foreach ($queued as $handle) {
            $stack=[];$depth=self::asset_depth((string)$handle,$depths,$stack,$scripts);
            $maxDepth=max($maxDepth,$depth);
            if ($depth>=4 && count($branches)<18) $branches[]=['handle'=>$handle,'depth'=>$depth];
        }
        $snapshot=['route'=>$route,'document_id'=>$id,'language'=>$lang,'observed_at'=>time(),
            'scripts_queued'=>count($scripts->queue),'styles_queued'=>count($styles->queue),
            'max_registered_dependency_depth'=>$maxDepth,'long_chains'=>$branches,
            'gallery_inventory'=>self::gallery_inventory(), 'policy'=>self::$verdict,
            'planning'=>'handles_and_registered_dependencies_only_no_browser_timing'];
        $all=(array)get_option(self::GRAPH,[]);
        $all[$key]=$snapshot;
        uasort($all,static fn($a,$b)=>($b['observed_at']??0)<=>($a['observed_at']??0));
        update_option(self::GRAPH,array_slice($all,0,18,true),false);
    }
    public static function boot(): void {
        add_action('wp_enqueue_scripts',[__CLASS__,'constrain'],999);
        add_action('wp_footer',[__CLASS__,'observe'],999);
        add_action('save_post',[__CLASS__,'invalidate'],20,0);
        add_action('deleted_post',[__CLASS__,'invalidate'],20,0);
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) {
            add_action($hook,[__CLASS__,'invalidate_elementor_meta'],20,3);
        }
    }
}
MAD4B_Network_Dependency_Policy::boot();
