<?php
/**
 * Plugin Name: MAD4B LiteSpeed → FastCGI Selective Coherence
 * Description: Map explicit LiteSpeed URL/Post purge events onto site-local Nginx FastCGI path generations; never purge all or flush Redis.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_LiteSpeed_FastCGI_Coherence_V1',false)) {
final class MAD4B_LiteSpeed_FastCGI_Coherence_V1 {
    public const VERSION='1.0.0';
    public const RECEIPTS='mad4b_litespeed_fastcgi_receipts_v1';
    private const MAX_PATHS=60;
    private static array $paths=[];
    private static array $events=[];
    private static bool $overflow=false;
    private static bool $finished=false;

    public static function url_path($url): ?string {
        if (!class_exists('MAD4B_Cache_Control_V1',false) ||
            !is_string($url) || $url==='' || strlen($url)>1024) return null;
        if ($url[0]==='/') {
            $parts=wp_parse_url($url);
            if (!is_array($parts) || isset($parts['host']) ||
                isset($parts['query']) || isset($parts['fragment']) ||
                isset($parts['user']) || isset($parts['pass']))
                return null;
            $path=(string)($parts['path']??'');
            return MAD4B_Cache_Control_V1::is_safe_public_path($path)?$path:null;
        }
        return MAD4B_Cache_Control_V1::path_from_url($url);
    }

    private static function record_path(string $path,string $source): void {
        if (self::$finished || self::$overflow || !MAD4B_Cache_Control_V1::is_safe_public_path($path))return;
        if (isset(self::$paths[$path]))return;
        if(count(self::$paths)>=self::MAX_PATHS) {
            self::$overflow=true;self::$paths=[];return;
        }
        self::$paths[$path]=true;
        if (count(self::$events)<8)self::$events[$source]=true;
    }

    /** Called ONLY after LSCWP dispatches a successful URL-purge request. */
    public static function on_url($url): void {
        $path=self::url_path($url);
        if ($path!==null)self::record_path($path,'litespeed_purged_link');
    }

    /** Published post/page/tour only; WPML translations map to their own URLs. */
    public static function on_post($post_id): void {
        if (!is_numeric($post_id) || (int)$post_id<1 ||
            !class_exists('MAD4B_Cache_Control_V1',false)) return;
        if (self::$overflow || self::$finished) return;
        $preview=MAD4B_Cache_Control_V1::preview_target('post:'.(int)$post_id);
        if (isset($preview['error']) || !is_array($preview['paths']??null) ||
            count($preview['paths'])>self::MAX_PATHS)return;
        foreach($preview['paths'] as $path)
            if (is_string($path))self::record_path($path,'litespeed_purged_post');
    }

    /** Static homepage only. Unknown homepage routing remains unchanged. */
    public static function on_frontpage(): void {
        $page=(int)get_option('page_on_front',0);
        if ($page>0)self::on_post($page);
    }

    public static function pending(): array {
        return ['paths'=>array_keys(self::$paths),'overflow'=>self::$overflow];
    }

    public static function status(): array {
        return ['contract'=>'mad4b.lsc.fastcgi.coherence.v1',
            'version'=>self::VERSION,'bridge_hooks'=>[
                'litespeed_purged_link','litespeed_purged_post','litespeed_purged_frontpage'],
            'scope'=>'site_local_public_paths_only',
            'global_litespeed_purge_all'=>'NOT_MAPPED_TO_FASTCGI',
            'redis_flush_allowed'=>false,'batch_max'=>self::MAX_PATHS,
            'recent_receipts'=>array_reverse((array)get_option(self::RECEIPTS,[]))];
    }

    /**
     * One site-local path generation batch per request, after all LiteSpeed
     * purge events. No calls back into LiteSpeed => no purge recursion.
     */
    public static function flush(): void {
        if (self::$finished)return;
        self::$finished=true;
        if (!self::$overflow && !self::$paths)return;
        $paths=array_keys(self::$paths);
        $result=self::$overflow?
            ['status'=>'batch_overflow_skipped','versioned'=>0]:
            MAD4B_Cache_Control_V1::socket_purge($paths);
        $receipt=[
            'contract'=>'mad4b.lsc.fastcgi.receipt.v1',
            'at_utc'=>gmdate('c'),
            'events'=>array_keys(self::$events),
            'paths_count'=>count($paths),'sample_paths'=>array_slice($paths,0,6),
            'result'=>$result,'redis_flushed'=>false,
            'global_invalidation_performed'=>false
        ];
        // Keep the previous 20 status receipts, never store credentials or
        // customer payloads; preserve existing source cache authority.
        $rows=(array)get_option(self::RECEIPTS,[]);
        $rows[]=$receipt;
        update_option(self::RECEIPTS,array_slice($rows,-20),false);
        do_action('mad4b/cache_control/litespeed_fastcgi_receipt',$receipt);
        self::$paths=[];
    }

    public static function admin_menu(): void {
        add_management_page('LiteSpeed → FastCGI Coherence',
            'LiteSpeed → FastCGI Coherence','manage_options',
            'mad4b-lsc-fastcgi-coherence',[self::class,'admin_page']);
    }
    public static function admin_page(): void {
        if (!current_user_can('manage_options'))wp_die('Forbidden');
        $s=self::status();
        echo '<div class="wrap"><h1>LiteSpeed → FastCGI Coherence</h1>';
        echo '<p>URL, published Post and static Front Page purge events selectively version Nginx FastCGI paths. No Redis flush, no blanket HTML purge.</p>';
        echo '<p><strong>Global LiteSpeed Purge All:</strong> Not mapped to FastCGI. Use MAD4B Cache Control for an explicitly previewed URL scope.</p>';
        echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-cache-lifecycle')).'">MAD4B Cache Control</a></p>';
        echo '<h2>Recent bridge receipts</h2><table class="widefat striped"><thead><tr><th>UTC</th><th>Event</th><th>Paths</th><th>Versioned</th><th>Status</th></tr></thead><tbody>';
        foreach(array_slice($s['recent_receipts'],0,10) as $r)
            echo '<tr><td>'.esc_html($r['at_utc']??'').'</td><td>'.
                esc_html(implode(', ',(array)($r['events']??[]))).'</td><td>'.
                (int)($r['paths_count']??0).'</td><td>'.
                (int)($r['result']['versioned']??0).'</td><td>'.
                esc_html($r['result']['status']??'unknown').'</td></tr>';
        echo '</tbody></table></div>';
    }

    public static function boot(): void {
        add_action('litespeed_purged_link',[self::class,'on_url'],20,1);
        add_action('litespeed_purged_post',[self::class,'on_post'],20,1);
        add_action('litespeed_purged_frontpage',[self::class,'on_frontpage'],20);
        add_action('shutdown',[self::class,'flush'],9999);
        add_action('admin_menu',[self::class,'admin_menu'],95);
    }
}
MAD4B_LiteSpeed_FastCGI_Coherence_V1::boot();
}
