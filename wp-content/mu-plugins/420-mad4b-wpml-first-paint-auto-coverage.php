<?php
/**
 * Plugin Name: MAD4B First-Paint WPML Language Inheritance
 * Version: 1.0.0
 * Description: Native WPML registry drives already-approved hero/poster first-paint language policies for enrolled companies.
 */
defined('ABSPATH') || exit;

/**
 * Honors installed company hero and responsive LCP behavior. Only replaces
 * the two outdated per-locale allowlists when the company explicitly enables
 * its WordPress option, not when a user visits a language or query URL.
 *
 * Any unsupported registry, cross-company host or invalid WPML language
 * falls back to the original stored allowlist. No language is granted CSS
 * certification by this bridge; the native Critical CSS gate remains separate.
 */
final class MAD4B_WPML_First_Paint_Languages_V1 {
    private const OPT = 'mad4b_perf_wpml_first_paint_all_languages_v1';

    private static function trusted_site(): bool {
        $host = strtolower((string) wp_parse_url((string) home_url('/'), PHP_URL_HOST));
        return in_array($host, ['tours.dev.mad4b.com','wayfatours.com'], true);
    }

    public static function enabled(): bool {
        return self::trusted_site()
            && get_option(self::OPT, 'off') === 'on';
    }

    public static function active_languages(): array {
        if (!self::enabled()) return [];
        $s = get_option('icl_sitepress_settings', []);
        if (!is_array($s) || !is_array($s['active_languages'] ?? null)) return [];
        $raw = $s['active_languages'];
        if (count($raw) < 1 || count($raw) > 20) return [];
        $codes = [];
        foreach ($raw as $lang) {
            if (!is_string($lang) || !preg_match('/^[a-z]{2}$/D', $lang)) return [];
            $codes[] = $lang;
        }
        $codes = array_values(array_unique($codes));
        $default = (string)($s['default_language'] ?? '');
        if (count($codes) !== count($raw) || !in_array($default, $codes, true)) return [];
        return $codes;
    }

    public static function inherit($stored) {
        $langs = self::active_languages();
        return $langs !== [] ? $langs : $stored;
    }

    /** List exact anonymous WPML homepage paths; reject foreign URLs. */
    public static function home_paths($settings): array {
        if (!is_array($settings) || !is_array($settings['active_languages'] ?? null)) return [];
        $raw = $settings['active_languages'];
        if (!$raw || count($raw) > 20) return [];
        foreach ($raw as $lang) if (!is_string($lang) || !preg_match('/^[a-z]{2}$/D',$lang)) return [];
        if (count(array_unique($raw)) !== count($raw)) return [];
        $default = (string)($settings['default_language'] ?? '');
        if (!in_array($default, $raw, true)) return [];
        $paths = ['/'];
        foreach ($raw as $lang) {
            if (!is_string($lang) || !preg_match('/^[a-z]{2}$/D',$lang)) return [];
            if ($lang !== $default) $paths[] = '/'.$lang.'/';
        }
        return array_values(array_unique($paths));
    }

    /** Exact path-generation invalidation; never purge all HTML/CSS. */
    public static function purge_home_paths(array $paths): bool {
        if (!self::enabled() || !$paths || count($paths) > 41) return false;
        foreach ($paths as $path) {
            if ($path !== '/' && (!is_string($path) || !preg_match('~^/[a-z]{2}/$~D',$path))) return false;
        }
        $socket = WP_CONTENT_DIR.'/.mad4b-purge/origin.sock';
        if (is_link($socket) || !file_exists($socket)) return false;
        $line = wp_json_encode(array_values(array_unique($paths)),JSON_UNESCAPED_SLASHES)."\n";
        $stream = @stream_socket_client('unix://'.$socket,$errno,$errstr,2);
        if (!$stream) return false;
        stream_set_timeout($stream,5);
        $written = fwrite($stream,$line);
        $reply = $written === strlen($line) ? json_decode((string)fgets($stream,4096),true) : null;
        fclose($stream);
        return is_array($reply) && ($reply['status']??'')==='ok'
            && (int)($reply['paths']??0)===count(array_unique($paths));
    }

    /** Event-driven self-healing on WPML language or company policy edits. */
    public static function updated_option($option,$old,$new): void {
        if (!in_array($option,['icl_sitepress_settings',self::OPT],true) || !self::enabled()) return;
        if ($option==='icl_sitepress_settings') {
            $a=self::home_paths($old);$b=self::home_paths($new);
            if ($a===$b || !$b) return;
            $paths=array_values(array_unique(array_merge($a,$b)));
        } else {
            if ($new!=='on' || $old===$new) return;
            $paths=self::home_paths(get_option('icl_sitepress_settings',[]));
        }
        if (!$paths) return;
        if (!self::purge_home_paths($paths)) {
            update_option('mad4b_wpml_firstpaint_purge_retry_v1',
                ['site'=>strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)),
                 'paths'=>$paths,'at'=>time()],false);
        }
    }

    /** Fail-safe retry on a normal, not visitor-blocking, cron or CLI pass. */
    public static function retry_pending(): void {
        if (!self::enabled()) return;
        if (!(defined('WP_CLI') && WP_CLI) && !wp_doing_cron()) return;
        $job=get_option('mad4b_wpml_firstpaint_purge_retry_v1',[]);
        if (!is_array($job) || !$job) return;
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        $paths=$job['paths']??null;
        if (($job['site']??'')!==$host || !is_array($paths)) return;
        if (self::purge_home_paths($paths)) {
            delete_option('mad4b_wpml_firstpaint_purge_retry_v1');
        }
    }

    public static function status(): array {
        return [
            'contract' => 'mad4b.wpml-first-paint-inheritance.v1',
            'enabled' => self::enabled(),
            'wpml_languages' => self::active_languages(),
            'policy' => 'active_wpml_languages_with_native_company_opt_in',
            'modifies_css_manifest' => false,
            'modifies_public_css_bytes' => false,
        ];
    }
}

// These remain the installed Delayed Hero / Shared Performance plugin's
// existing exact option keys. Hero image requests and bandwidth policies
// are still governed by their native role and page/device gates.
add_filter('option_mad4b_perf_mobile_hero_priority_languages_v1',
    [MAD4B_WPML_First_Paint_Languages_V1::class, 'inherit'], 50, 1);
add_filter('option_mad4b_perf_hero_responsive_languages_v1',
    [MAD4B_WPML_First_Paint_Languages_V1::class, 'inherit'], 50, 1);

// A newly enrolled company can lack one of the legacy option rows.
// Apply the same WPML policy to WordPress' default-option path, rather than
// assuming the option was created manually by a site administrator.
add_filter('default_option_mad4b_perf_mobile_hero_priority_languages_v1',
    [MAD4B_WPML_First_Paint_Languages_V1::class,'inherit'],50,1);
add_filter('default_option_mad4b_perf_hero_responsive_languages_v1',
    [MAD4B_WPML_First_Paint_Languages_V1::class,'inherit'],50,1);

add_action('updated_option',
    [MAD4B_WPML_First_Paint_Languages_V1::class,'updated_option'], 50, 3);
add_action('wp_loaded',
    [MAD4B_WPML_First_Paint_Languages_V1::class,'retry_pending'], 90, 0);
