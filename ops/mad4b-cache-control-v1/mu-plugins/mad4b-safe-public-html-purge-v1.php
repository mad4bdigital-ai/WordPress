<?php
/**
 * Plugin Name: MAD4B Safe Public HTML Cache Purge
 * Description: Public HTML only: LiteSpeed page cache and independently approved company-local FastCGI global epoch; no Redis or object cache flush.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Safe_Public_HTML_Purge_V1',false)) {
final class MAD4B_Safe_Public_HTML_Purge_V1 {
    public const VERSION='1.1.0';
    private const SIGNAL_PREFIX='MAD4B_SAFE_PUBLIC_HTML_ALL_V2'."\n";
    private const LEGACY_SIGNAL='MAD4B_SAFE_PUBLIC_HTML_ALL_V1'."\n";
    private static string $last_request_id='';
    private static string $last_status='not_requested';
    private static bool $submitted=false;

    public static function signal_file(): string {
        return WP_CONTENT_DIR.'/.mad4b-purge/global-html-invalidate.request';
    }
    public static function host_receipt(): array {
        $file=WP_CONTENT_DIR.'/.mad4b-purge/global-html-status.json';
        if (is_link($file) || !is_file($file) || !is_readable($file) ||
            filesize($file)>4096)return ['status'=>'not_yet_verified'];
        $value=json_decode((string)file_get_contents($file),true);
        return is_array($value) && ($value['contract']??'')==='mad4b.public-html.global-epoch.v1' ?
            $value : ['status'=>'not_yet_verified'];
    }
    /** One fixed, company-local signal. Never take path, filename or shell args from HTTP. */
    public static function signal(): bool {
        $file=self::signal_file();
        if (is_link($file) || !is_file($file) || !is_writable($file) ||
            filesize($file)>128) {
            self::$last_status='signal_unavailable';
            return false;
        }
        $previous=(string)@file_get_contents($file);
        if ($previous!==self::LEGACY_SIGNAL &&
            !preg_match('/^MAD4B_SAFE_PUBLIC_HTML_ALL_V2\n[a-f0-9]{24}\n$/D',$previous)) {
            self::$last_status='unrecognized_signal_contract';
            return false;
        }
        try { $id=bin2hex(random_bytes(12)); }
        catch (\Throwable $error) {
            self::$last_status='request_id_unavailable';
            return false;
        }
        $payload=self::SIGNAL_PREFIX.$id."\n";
        $ok=@file_put_contents($file,$payload,LOCK_EX);
        if ($ok!==strlen($payload) || @file_get_contents($file)!==$payload) {
            self::$last_status='signal_write_failed';
            return false;
        }
        self::$last_request_id=$id;
        clearstatcache(true,$file);
        self::$last_status='host_epoch_pending';
        return true;
    }
    public static function on_page_cache_purged(): void {
        self::signal();
    }
    public static function purge_public_html(string $reason='mad4b_safe_public_html'): array {
        if (!class_exists('\LiteSpeed\Purge',false) ||
            !method_exists('\LiteSpeed\Purge','purge_all_lscache')) {
            return ['status'=>'litespeed_page_cache_unavailable'];
        }
        $file=self::signal_file();
        if (is_link($file)||!is_file($file)||!is_writable($file))
            return ['status'=>'host_global_epoch_signal_unavailable'];
        if (self::$submitted)return ['status'=>'already_queued_in_request',
            'host_epoch_pending'=>true,'redis_flushed_by_mad4b'=>false,
            'request_id'=>self::$last_request_id];
        self::$last_status='not_requested';
        // Vendor page-cache-only operation. This deliberately NEVER invokes
        // the vendor's purge_all(), purge_all_object(), or wp_cache_flush().
        \LiteSpeed\Purge::purge_all_lscache($reason);
        if (self::$last_status==='host_epoch_pending')self::$submitted=true;
        return ['status'=>self::$last_status==='host_epoch_pending'?
            'public_html_purge_queued':'global_epoch_signal_not_received',
            'host_epoch_pending'=>self::$last_status==='host_epoch_pending',
            'redis_flushed_by_mad4b'=>false,
            'request_id'=>self::$last_request_id];
    }
    public static function request(): void {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed','Forbidden',['response'=>403]);
        check_admin_referer('mad4b_safe_public_html_all_v1','_wpnonce');
        $result=self::purge_public_html('approved_wp_admin_safe_public_html');
        $status=sanitize_key($result['status']);
        $req=$result['request_id']??'';
        if (!is_string($req) || !preg_match('/^[a-f0-9]{24}$/D',$req))$req='';
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-safe-public-html','result'=>$status,'req'=>$req],admin_url('tools.php')));
        exit;
    }
    public static function page(): void {
        if (!current_user_can('manage_options'))wp_die('Forbidden');
        $r=self::host_receipt();
        $req=isset($_GET['req']) && is_scalar($_GET['req'])?
            sanitize_key(wp_unslash((string)$_GET['req'])):'';
        if (!preg_match('/^[a-f0-9]{24}$/D',$req))$req='';
        $status=isset($_GET['result']) && is_scalar($_GET['result'])?
            sanitize_key(wp_unslash((string)$_GET['result'])):'';
        echo '<div class="wrap"><h1>MAD4B Safe Public HTML Purge</h1>';
        echo '<p>Purges public LiteSpeed page cache, then rotates the Nginx FastCGI site epoch in a separate verified host operation. Does not flush Redis or object cache.</p>';
        echo '<p><strong>LiteSpeed native Purge All is different:</strong> that command can flush Redis Object Cache. Use this action for public HTML.</p>';
        if ($status!=='')echo '<div class="notice notice-info"><p>Request state: '.esc_html($status).'. Host completion appears below after the independent Nginx check.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_safe_public_html_all">';
        wp_nonce_field('mad4b_safe_public_html_all_v1');
        submit_button('Purge public HTML (LiteSpeed + FastCGI, preserve Redis)','secondary');
        echo '</form><h2>Last verified Nginx host operation</h2>';
        if ($req!=='') {
            $matched=(($r['request_id']??'')===$req && ($r['status']??'')==='ok');
            echo '<p>Current request '.esc_html($req).': <strong>'.
                ($matched?'VERIFIED_COMPLETE':'PENDING_OR_SUPERSEDED').
                '</strong>. An older host receipt never proves this request completed.</p>';
        }
        echo '<p>Host status: <strong>'.esc_html($r['status']??'unknown').'</strong> · UTC: '.
            esc_html($r['utc']??'not yet').'</p>';
        echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-cache-lifecycle')).'">Use scoped URL Cache Control instead</a></p></div>';
    }
    public static function warn_litespeed_admin(): void {
        if (!current_user_can('manage_options'))return;
        $page=isset($_GET['page']) && is_scalar($_GET['page'])?
            sanitize_key(wp_unslash((string)$_GET['page'])):'';
        if (!str_starts_with($page,'litespeed'))return;
        echo '<div class="notice notice-warning"><p><strong>MAD4B:</strong> Native LiteSpeed Purge All can flush Redis Object Cache. '.
            'For public HTML, use <a href="'.esc_url(admin_url('tools.php?page=mad4b-safe-public-html')).'">'.
            'Safe Public HTML Purge</a> instead. It rotates Nginx FastCGI site keys and preserves Redis.</p></div>';
    }

    public static function boot(): void {
        add_action('litespeed_purged_all_lscache',[self::class,'on_page_cache_purged'],15,0);
        add_action('mad4b/public_html/safe_purge_all',[self::class,'purge_public_html'],10,1);
        add_action('admin_menu',static function():void {
            add_management_page('MAD4B Safe Public HTML Purge',
                'Safe Public HTML Purge','manage_options',
                'mad4b-safe-public-html',[self::class,'page']);
        },95);
        add_action('admin_post_mad4b_safe_public_html_all',[self::class,'request']);
        add_action('admin_notices',[self::class,'warn_litespeed_admin']);
    }
}
MAD4B_Safe_Public_HTML_Purge_V1::boot();
}

