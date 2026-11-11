<?php
/**
 * Plugin Name: MAD4B Portable Tour CSS Delivery — Versioned Elementor
 * Description: Content-hashed Elementor post CSS on all public frontend routes; preserves native sheet order.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_CSS_Delivery_V1 {
    private static ?array $policy = null;
    public static function policy():array {
        if (self::$policy !== null) return self::$policy;
        $file = WPMU_PLUGIN_DIR . '/mad4b-tour-css-delivery.policy.json';
        if (!is_file($file) || is_link($file) || @filesize($file)>2048) return self::$policy=[];
        $j=json_decode((string)file_get_contents($file),true);
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        if (!is_array($j) || ($j['contract']??'')!=='mad4b.tour-css-delivery.v1'
            || !in_array(($j['site']??null),[$host,'@current'],true)) return self::$policy=[];
        return self::$policy=$j;
    }
    public static function eligible():bool {
        $p=self::policy();
        if (is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
            || (function_exists('is_customize_preview') && is_customize_preview())
            || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET') return false;
        // CSS URL fingerprinting cannot make a private page publicly cacheable.
        // It only changes the style asset URL on frontend responses.
        if (($p['mode']??'off')==='active')return true;
        return ($p['mode']??'off')==='canary'
            && ($_SERVER['QUERY_STRING']??'')==='mad4b_css_delivery_probe=1'
            && ($_SERVER['HTTP_X_MAD4B_ASSET_QA']??'')==='1';
    }
    public static function version($src) {
        if (!is_string($src) || !self::eligible())return $src;
        $site=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        $host=(string)wp_parse_url($src,PHP_URL_HOST);
        if ($host!=='' && strcasecmp($host,$site)!==0)return $src;
        $path=(string)wp_parse_url($src,PHP_URL_PATH);
        if (!preg_match('~^/wp-content/uploads/elementor/css/(post-[0-9]+\.css)$~D',$path,$m))return $src;
        $dir=realpath(WP_CONTENT_DIR.'/uploads/elementor/css');
        $file=realpath(WP_CONTENT_DIR.'/uploads/elementor/css/'.$m[1]);
        if (!$dir || !$file || !str_starts_with($file,$dir.'/') || !is_file($file))return $src;
        $stat=@stat($file);
        if (!$stat || (int)$stat['size']<1 || (int)$stat['size']>2097152)return $src;
        // File contents, not mtime+length, are authoritative. Same-second,
        // same-size Elementor rewrites must invalidate browser CSS versions.
        static $hashes=[];
        if (!isset($hashes[$file])) {
            $digest=@hash_file('sha256',$file);
            if (!is_string($digest) || strlen($digest)!==64)return $src;
            $hashes[$file]=substr($digest,0,16);
        }
        $epoch=base_convert((string)(int)$stat['mtime'],10,36);
        $size=base_convert((string)(int)$stat['size'],10,36);
        return add_query_arg('mad4b_ev',$epoch.'-'.$size.'-'.$hashes[$file],$src);
    }
    public static function boot():void {
        add_filter('style_loader_src',[self::class,'version'],9999,1);
    }
}
MAD4B_Tour_CSS_Delivery_V1::boot();
