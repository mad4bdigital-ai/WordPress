<?php
/**
 * Plugin Name: MAD4B Portable — Tour Elementor CSS Segments
 * Version: 0.2.0
 * Description: Merge adjacent native generated Elementor post CSS only, preserving native style order, source files, and fail-safe fallback.
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_CSS_Segments_V2 {
    private static bool $planned = false;
    private static array $first = [];
    private static array $omit = [];
    private static int $replaced = 0;
    private static bool $probe = false;

    private static function policy():array {
        $path = WPMU_PLUGIN_DIR.'/mad4b-tour-css-segments.policy.json';
        if (!is_file($path) || is_link($path) || filesize($path)>2048)return [];
        $p=json_decode((string)file_get_contents($path),true);
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        if (!is_array($p)||($p['contract']??'')!=='mad4b.tour-css-segments.v2'
            || !in_array(($p['site']??null),['@current',$host],true))return [];
        return $p;
    }
    public static function eligible(): bool {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || !is_singular('tours-and-activities') || is_admin() || wp_doing_ajax()
            || !empty($_POST) || is_user_logged_in() || is_preview())return false;
        $cfg=self::policy();
        $query=(string)($_SERVER['QUERY_STRING']??'');
        $probe=$query==='mad4b_css_segments_probe=1'
            && ($_SERVER['HTTP_X_MAD4B_ASSET_QA']??'')==='1';
        self::$probe=$probe;
        return $probe && ($cfg['mode']??'off')==='canary'
            || $query==='' && ($cfg['mode']??'off')==='active';
    }

    public static function setup(): void {
        if (!self::eligible()) return;
        if (self::$probe && !headers_sent()) {
            header('Cache-Control: private, no-store, max-age=0');
        }
        add_action('wp_print_styles', [self::class,'plan'], 200);
        add_filter('style_loader_tag', [self::class,'rewrite'], 500, 4);
    }

    private static function css_path($handle, $styles): ?string {
        if (!preg_match('/^elementor-post-([0-9]+)$/D', $handle, $m)) return null;
        $reg = $styles->registered[$handle] ?? null;
        if (!$reg || !is_string($reg->src) ||
            (string)($reg->args ?? 'all') !== 'all' ||
            !empty($reg->extra['after']) ||
            !empty($reg->extra['conditional']) ||
            !empty($reg->extra['rtl']) ||
            !empty($reg->extra['alt']))return null;
        $path = wp_parse_url($reg->src, PHP_URL_PATH);
        if (!is_string($path) ||
            $path !== '/wp-content/uploads/elementor/css/post-'.$m[1].'.css')return null;
        $base=realpath(WP_CONTENT_DIR.'/uploads/elementor/css');
        $file=realpath(WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$m[1].'.css');
        if (!$base||!$file||!str_starts_with($file,$base.'/')||!is_file($file)||!is_readable($file))return null;
        return $file;
    }

    public static function plan(): void {
        if (self::$planned) return;
        self::$planned = true;
        $styles=wp_styles();
        $styles->all_deps($styles->queue);
        $groups=[];$run=[];
        foreach ($styles->to_do as $handle) {
            if (in_array($handle, $styles->done, true))continue;
            $p=self::css_path($handle,$styles);
            if ($p !== null) {
                $run[]=['handle'=>$handle,'path'=>$p];
            } else {
                if (count($run)>=3)$groups[]=$run;
                $run=[];
            }
        }
        if (count($run)>=3)$groups[]=$run;
        if (count($groups)>15)return;
        $base=realpath(WP_CONTENT_DIR.'/uploads/elementor/css');
        if (!$base||!is_writable($base))return;
        foreach ($groups as $group) {
            if (count($group)>70)continue;
            $data='';$keys=[];$good=true;
            foreach ($group as $entry) {
                $body=@file_get_contents($entry['path']);
                if (!is_string($body)||$body===''||strlen($body)>500000 ||
                    preg_match('/@(?:import|charset|layer)\b/i',$body)){$good=false;break;}
                // Generated Elementor post files use absolute URLs or no url().
                // Fail closed for any relative reference to prevent path rewrite bugs.
                if (preg_match('/url\(\s*[\x22\x27]?\s*(?!data:|https?:|\/\/|\/)/i',$body)){$good=false;break;}
                $keys[]=$entry['handle'].':'.hash('sha256',$body);
                $data.="\n/* ". $entry['handle'] ." */\n".$body."\n";
                if (strlen($data)>1500000){$good=false;break;}
            }
            if (!$good)continue;
            $digest=substr(hash('sha256',implode('|',$keys)),0,24);
            $file=$base.'/mad4b-tour-css-'.$digest.'.css';
            if (!is_file($file)) {
                $tmp=tempnam($base,'.mad4b-css-');
                if (!$tmp)continue;
                if (file_put_contents($tmp,$data,LOCK_EX)!==strlen($data)||
                    !@chmod($tmp,0644) || !@rename($tmp,$file)) {
                    @unlink($tmp);
                    continue;
                }
            }
            if (!is_file($file)||filesize($file)!==strlen($data))continue;
            $link=content_url('uploads/elementor/css/'.basename($file));
            $first=$group[0]['handle'];
            self::$first[$first]=$link;
            foreach (array_slice($group,1) as $entry)self::$omit[$entry['handle']]=true;
        }
    }

    public static function rewrite($tag,$handle,$href,$media) {
        if (isset(self::$first[$handle])) {
            self::$replaced++;
            return "<link rel='stylesheet' id='".esc_attr($handle)."-css' href='".esc_url(self::$first[$handle])."' media='".esc_attr($media)."' />\n";
        }
        if (isset(self::$omit[$handle])) return '';
        return $tag;
    }
    public static function report(): void {
        if (!self::$probe) return;
        echo "\n<!-- MAD4B_CSS_SEGMENT_QA ".esc_html(wp_json_encode([
            'bundle_groups'=>count(self::$first),'bundled_styles'=>count(self::$first)+count(self::$omit),
            'removed_requests'=>count(self::$omit),'used'=>self::$replaced,
        ])) ." -->\n";
    }
}
add_action('wp',[MAD4B_Tour_CSS_Segments_V2::class,'setup'],120);
add_action('wp_footer',[MAD4B_Tour_CSS_Segments_V2::class,'report'],999);
