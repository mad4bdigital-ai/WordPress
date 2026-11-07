<?php
/**
 * Plugin Name: MAD4B JetEngine Static Dynamic-CSS Bypass
 * Description: Avoid redundant Elementor dynamic CSS traversal only for proven static listing template metadata.
 * Version: 0.1.2
 */
defined('ABSPATH') || exit;
final class MAD4B_Jet_Static_Dynamic_CSS_Bypass {
    private static bool $installed = false;
    private static array $static = [];
    private static array $counts = ['static_skipped'=>0, 'vendor_delegated'=>0];

    public static function candidate(): bool {
        if (wp_get_environment_type()!=='staging' || is_admin()
            || is_user_logged_in() || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || ($_SERVER['REQUEST_METHOD']??'')!=='GET') return false;
        if (array_key_exists('mad4b_static_dcss',$_GET))
            return (string)$_GET['mad4b_static_dcss']==='1';
        // QA showed 0/114 static templates on current Home. Keep optional
        // proof adapter dormant by default to avoid overhead on dynamic cards.
        return get_option('mad4b_static_dcss_skip_v1','off')==='on';
    }

    public static function static_template(int $id): bool {
        if ($id<=0)return false;
        if (array_key_exists($id,self::$static))return self::$static[$id];
        $post=get_post($id);
        if (!$post || !in_array($post->post_type,['elementor_library','jet-engine'],true)
            || $post->post_status!=='publish')return self::$static[$id]=false;
        $meta=get_post_meta($id,'_elementor_css',true);
        if (!is_array($meta) || ($meta['status']??'')!=='file'
            || !isset($meta['dynamic_elements_ids'])
            || !is_array($meta['dynamic_elements_ids'])
            || !empty($meta['dynamic_elements_ids'])
            || !is_numeric($meta['time']??null))
            return self::$static[$id]=false;
        $updated=(int)strtotime((string)$post->post_modified_gmt.' UTC');
        // Elementor's CSS metadata may lag behind an edit; never trust
        // a no-dynamic-elements claim older than the current template.
        if ($updated<=0 || (int)$meta['time'] < $updated)
            return self::$static[$id]=false;
        return self::$static[$id]=true;
    }

    public static function static_group($listing_id,$inner_templates): bool {
        if (!self::static_template((int)$listing_id))return false;
        if (!is_array($inner_templates) || count($inner_templates)>20)return false;
        foreach($inner_templates as $id)
            if (!self::static_template((int)$id))return false;
        return true;
    }

    public static function intercept($content,$listing_id,$inner_templates) {
        $native=self::$vendor;
        if (!is_callable($native))return $content;
        if (!self::candidate() || !is_string($content)
            || !self::static_group($listing_id,$inner_templates)){
            self::$counts['vendor_delegated']++;
            return call_user_func($native,$content,$listing_id,$inner_templates);
        }
        // Static styles live in Elementor's normal external stylesheet.
        // Native Dynamic_CSS::render_styles only emits for
        // dynamic_elements_ids. Empty verified list produces no inline CSS.
        self::$counts['static_skipped']++;
        return $content;
    }

    private static $vendor=null;
    public static function install(): void {
        if (self::$installed || !self::candidate())return;
        $hook='jet-engine/elementor-views/frontend/listing-content';
        global $wp_filter;
        if (empty($wp_filter[$hook]) || !is_object($wp_filter[$hook]))return;
        $callbacks=$wp_filter[$hook]->callbacks[10]??[];
        foreach($callbacks as $item) {
            $callback=$item['function']??null;
            if (!is_array($callback) || count($callback)!==2
                || !is_object($callback[0])
                || !($callback[0] instanceof Jet_Engine_Dynamic_Tags_Manager)
                || $callback[1]!=='add_listing_item_dynamic_css')continue;
            $accepted=(int)($item['accepted_args']??0);
            if($accepted!==3)return;
            self::$vendor=$callback;
            remove_filter($hook,$callback,10);
            add_filter($hook,[__CLASS__,'intercept'],10,3);
            self::$installed=true;
            return;
        }
    }
    public static function counters(): array { return self::$counts; }
    public static function qa_receipt(): void {
        if (!isset($_GET['mad4b_static_dcss_probe'])
            || (string)$_GET['mad4b_static_dcss_probe']!=='1'
            || wp_get_environment_type()!=='staging')return;
        error_log('MAD4B_STATIC_CSS_QA skipped='.self::$counts['static_skipped']
            .' delegated='.self::$counts['vendor_delegated']
            .' installed='.(self::$installed?'1':'0'));
    }
}
add_action('shutdown',['MAD4B_Jet_Static_Dynamic_CSS_Bypass','qa_receipt'],9999);
add_action('wp',['MAD4B_Jet_Static_Dynamic_CSS_Bypass','install'],9);
add_action('elementor/frontend/init',['MAD4B_Jet_Static_Dynamic_CSS_Bypass','install'],99);
