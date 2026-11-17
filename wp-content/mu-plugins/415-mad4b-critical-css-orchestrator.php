<?php
/**
 * Plugin Name: MAD4B Verified Critical CSS
 * Version: 0.3.29
 * Description: Serve measured first-paint CSS only for exact live LiteSpeed CSS content hashes. Fail open on any mismatch.
 */
defined('ABSPATH') || exit;

final class MAD4B_Verified_Critical_CSS_V1 {
    private const OPT = 'mad4b_perf_critical_css_v1';
    private const STORAGE = '/mad4b-critical-css-v1';
    private const MAX_CSS = 1048576;
    private static $manifest = null;
    // Only the exact request which successfully rewrote a fully verified
    // sub-mobile cap may certify its final HTML as cache-ready.
    private static ?int $verifiedSubMobileCap = null;
    private static ?int $verifiedSubMobileNative = null;


    private static function eligible(): bool {
        if ((string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS'] ?? '') !== 'mobile'
            || ( !empty($_SERVER['HTTP_COOKIE'])
                && ( !class_exists('MAD4B_Public_Cookie_Cache_Contract_V1',false)
                    || !MAD4B_Public_Cookie_Cache_Contract_V1::allow(
                        (string)$_SERVER['HTTP_COOKIE'] ) ) )
            || get_option('litespeed.conf.optm-css_async','0') === '1'
            || is_admin() || wp_doing_ajax() || is_user_logged_in()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')
            || !class_exists('MAD4B_Shared_First_Paint_Performance', false)
            || !MAD4B_Shared_First_Paint_Performance::home_view()) return false;
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($query !== '') {
            // The original QA query string must never opt public Production
            // visitors into unmeasured async CSS. Company operator activation
            // requires the local option, exact manifest SHA and measured proof.
            return $query === 'mad4b_critical_css_probe=1'
                && wp_get_environment_type()==='staging'
                && class_exists('MAD4B_Experience_Site_Scope_V1',false)
                && MAD4B_Experience_Site_Scope_V1::bound();
        }
        return get_option(self::OPT, 'off') === 'on';
    }

    private static function entries(): array {
        // Company-specific manifests, never a file copied from another site.
        // v1 remains compatible with the earlier Wayfatours release. v2
        // additionally binds route, WPML locale, Elementor graph and CSS epoch.
        $host = strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST));
        $key = get_current_blog_id() . '|' . $host;
        if (!is_array(self::$manifest)) self::$manifest = [];
        if (array_key_exists($key,self::$manifest)) return self::$manifest[$key];
        self::$manifest[$key] = [];
        $filename = WP_CONTENT_DIR . self::STORAGE . '/manifest.json';
        if ($host === '' || is_link($filename) || !is_file($filename)
            || @filesize($filename) > 65536) return [];
        $decoded = json_decode((string)@file_get_contents($filename),true);
        if (!is_array($decoded)
            || !in_array($decoded['version']??null,[1,2],true)
            || !is_array($decoded['entries']??null)
            || !is_string($decoded['site']??null)
            || strcasecmp($decoded['site'],$host)!==0) return [];
        return self::$manifest[$key] = $decoded;
    }

    /**
     * Elementor owns the actual viewport breakpoints. Never infer a breakpoint
     * from User-Agent, locale, a client header, or a hard-coded company table.
     * If Elementor changes the responsive settings, the old certificate
     * automatically falls back to the original render-blocking stylesheet.
     */
    private static function native_mobile_breakpoint(): ?int {
        if (!class_exists('\Elementor\Plugin',false)) return null;
        $instance=\Elementor\Plugin::$instance ?? null;
        $manager=is_object($instance)?($instance->breakpoints??null):null;
        if (!is_object($manager) || !method_exists($manager,'get_breakpoints'))
            return null;
        $all=$manager->get_breakpoints();
        if (!is_array($all)) return null;
        $mobile=$all['mobile']??null;
        if (!is_object($mobile) || !method_exists($mobile,'get_value'))
            return null;
        $raw=$mobile->get_value();
        if ((!is_int($raw) && !(is_string($raw) && ctype_digit($raw)))
            || (int)$raw<480 || (int)$raw>1100) return null;
        return (int)$raw;
    }

    /**
     * A performance-qualified sub-mobile first-paint cap may be narrower
     * than Elementor's actual mobile breakpoint. This is NOT a change to
     * Elementor templates, its media queries or the device classifier:
     * >=cap+1 receives native render-blocking LiteSpeed CSS.
     *
     * A narrower cap requires its own complete Chromium proof at the
     * cap/cap+1 edge AND the actual Elementor mobile/mobile+1 edge.
     * Unknown breakpoints, stale evidence and wider-than-native caps fail
     * closed to the original stylesheet.
     */
    private static function adaptive_width_proven(int $cap, array $cert): bool {
        $native=self::native_mobile_breakpoint();
        if ($cap<480 || $native===null || $cap>$native) return false;
        $widths=(array)($cert['viewports']??[]);
        if (($cert['adaptive_mobile_breakpoint_px']??null)!==$cap
            || ($cert['decision']??'')!=='passed_evidence'
            || !in_array($cap,$widths,true)
            || !in_array($cap+1,$widths,true)) return false;
        if ($cap===$native) return true;
        return ($cert['native_mobile_breakpoint_px']??null)===$native
            && in_array($native,$widths,true)
            && in_array($native+1,$widths,true)
            && in_array(320,$widths,true)
            && in_array(390,$widths,true)
            && in_array(414,$widths,true);
    }

    private static function verified_variant(array $manifest, array $meta,
        string $filename, string $buffer, string $locale): ?array {
        if ((int)($manifest['version']??0)===1) {
            // Signed off manually under the legacy release. Retain company
            // compatibility, but never import unverified source canaries.
            if (($meta['gate_status']??'')!=='pass'
                || !in_array($locale,(array)($meta['languages']??[]),true)
                || !in_array('mobile',(array)($meta['devices']??[]),true)) return null;
            return $meta;
        }
        if ((int)($manifest['version']??0)!==2
            || ($manifest['contract']??'')!=='mad4b.mobile-critical-css.v2'
            || ($meta['bundle_file']??'')!==$filename
            || !is_array($meta['variants']??null)) return null;
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $variant=$meta['variants'][$path.'|'.$locale.'|mobile']??null;
        if (!is_array($variant)
            || ($variant['gate_status']??'')!=='pass'
            || ($variant['evidence']['decision']??'')!=='passed_evidence'
            || ($variant['route_path']??'')!==$path
            || ($variant['language']??'')!==$locale
            || ($variant['device']??'')!=='mobile'
            || (int)($variant['page_id']??0)!==(int)get_queried_object_id()
            || (int)($variant['css_epoch']??-1)!==(int)get_option('mad4b_css_ai_epoch_v1',0)
            || !is_string($variant['elementor_doc_fingerprint']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$variant['elementor_doc_fingerprint'])) return null;
        // The v2 manifest is immutable per actual CSS SHA, route and locale.
        // An adaptive-width certificate cannot be upgraded to full-width CSS.
        // A separately enrolled native runtime is mandatory for publication;
        // this read-only verifier never creates that enrollment.
        if (array_key_exists('css_critical_mobile_breakpoint',$variant)) {
            $px=$variant['css_critical_mobile_breakpoint'];
            $cert=(array)($variant['evidence']??[]);
            if (!is_int($px) || $px>1100
                || !self::adaptive_width_proven($px,$cert)
                || str_contains($buffer,'data-mad4b-first-city='))
                return null;
        }
        $evidence=(array)($variant['evidence']??[]);
        if (!empty($evidence['requires_mobile_hero_priority'])) {
            $posterPath=(string)($evidence['required_hero_path']??'');
            if (!preg_match('#^/wp-content/uploads/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_.-]+\.(?:webp|avif)$#D',$posterPath)
                || !str_contains($buffer,$posterPath)
                || !class_exists('MAD4B_Delayed_Hero_YT',false)
                || !MAD4B_Delayed_Hero_YT::mobile_hero_priority()) return null;
        }
        if (!empty($evidence['requires_city_preload_deferred'])
            && str_contains($buffer,'data-mad4b-first-city=')) return null;
        // Any changed template, listing, popup or WPML document set falls back
        // to native LiteSpeed CSS until browser QA certifies a fresh bundle.
        $start=stripos($buffer,'<body');
        if ($start===false) return null;
        preg_match_all('/(?<![a-z0-9_-])elementor-(\d+)\b/i',
            substr($buffer,$start),$found);
        $docs=array_values(array_unique(array_map('intval',$found[1])));
        sort($docs,SORT_NUMERIC);
        $fingerprint=hash('sha256',implode(',',$docs));
        if (!hash_equals($variant['elementor_doc_fingerprint'],$fingerprint)) return null;
        return $variant;
    }

    public static function transform($buffer, bool $force = false) {
        if (!is_string($buffer) || strlen($buffer) < 1000 || strlen($buffer) > 6000000
            || str_contains($buffer, 'id="mad4b-critical-css-v1"')
            || !$force && !self::eligible()) return $buffer;
        if (!str_contains($buffer, '</head>') || !str_contains($buffer, '/wp-content/litespeed/css/')) return $buffer;

        $tags = [];
        preg_match_all('~<link\b[^>]*>~i', $buffer, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[0] as [$tag, $position]) {
            if (!preg_match('~\brel=["\x27]stylesheet["\x27]~i', $tag)) continue;
            if (!preg_match('~\bhref=["\x27]([^"\x27]+)["\x27]~i', $tag, $href)) continue;
            $host = wp_parse_url($href[1], PHP_URL_HOST);
            $siteHost = wp_parse_url((string)get_option('home',''), PHP_URL_HOST);
            if (!is_string($host) || strcasecmp($host, (string)$siteHost) !== 0) continue;
            $path = (string) wp_parse_url($href[1], PHP_URL_PATH);
            if (!preg_match('~^/wp-content/litespeed/css/([a-f0-9]{32}\.css)$~D', $path, $name)) continue;
            $tags[] = [$tag, $position, $href[1], $name[1]];
        }
        // Don't mutate pages with multiple optimizer bundles or ambiguous stylesheets.
        if (count($tags) !== 1) return $buffer;
        [$tag, $pos, $url, $filename] = $tags[0];
        $original = WP_CONTENT_DIR . '/litespeed/css/' . $filename;
        if (!is_file($original) || is_link($original)) {
            $original = WP_CONTENT_DIR . '/litespeed/.mad4b-asset-retention/css/' . $filename;
        }
        if (!is_file($original) || is_link($original) || @filesize($original) > 6291456) return $buffer;
        $sha = hash_file('sha256', $original);
        $manifest = self::entries();
        $entries = (array)($manifest['entries']??[]);
        if (!is_string($sha) || !is_array($entries[$sha] ?? null)) return $buffer;
        $locale = (string)apply_filters('wpml_current_language', null);
        $meta = self::verified_variant($manifest,$entries[$sha],$filename,$buffer,$locale);
        if ($meta===null) return $buffer;
        $file = $meta['file'] ?? null;
        $criticalSha = $meta['critical_sha'] ?? null;
        if (!is_string($file) || !preg_match('/^[a-f0-9]{64}\.css$/D', $file)
            || !is_string($criticalSha) || !preg_match('/^[a-f0-9]{64}$/D', $criticalSha)) return $buffer;
        $ccssPath = WP_CONTENT_DIR . self::STORAGE . '/' . $file;
        if (is_link($ccssPath) || !is_file($ccssPath) || @filesize($ccssPath) < 16000
            || @filesize($ccssPath) > self::MAX_CSS
            || hash_file('sha256', $ccssPath) !== $criticalSha) return $buffer;
        $critical = @file_get_contents($ccssPath);
        if (!is_string($critical) || stripos($critical, '</style') !== false) return $buffer;

        // Fetch priority is measured per verified site+locale+route candidate.
        // Missing/invalid values keep the Wayfatours-proven low default.
        $priority=(string)($meta['css_fetch_priority']??'low');
        if (!in_array($priority,['low','auto','high'],true)) $priority='low';
        $safeUrl = esc_url($url);
        $adaptive=(int)($meta['css_critical_mobile_breakpoint']??0);
        $criticalMedia=$adaptive>0?' media="(max-width:'.$adaptive.'px)"':'';
        $fullMedia=$adaptive>0?'(min-width:'.($adaptive+1).'px)':'print';
        $markup = '<style id="mad4b-critical-css-v1"'.$criticalMedia.'>' . $critical . '</style>';
        if (($meta['css_full_preload']??false)===true) {
            // Only preload the full stylesheet when Critical CSS is active.
            // At >=768px the original full CSS must remain render-blocking,
            // without a duplicate high-priority preload stealing LCP bandwidth.
            $preloadMedia=$adaptive>0?' media="(max-width:'.$adaptive.'px)"':'';
            $markup .= '<link rel="preload" as="style" fetchpriority="high"'.$preloadMedia.' href="' . $safeUrl . '" />';
        }
        // At tablet widths the native full CSS remains render-blocking.
        // Default browser priority, with no tablet preload, preserves
        // the original 768px LCP and first-paint critical path.
        $fullPriority=$adaptive>0?'auto':$priority;
        $markup .= '<link id="mad4b-css-rest-v1" rel="stylesheet" href="' . $safeUrl . '" media="' . $fullMedia . '" fetchpriority="' . $fullPriority . '" onload="this.onload=null;this.media=&#39;all&#39;" />';
        $markup .= '<noscript>' . $tag . '</noscript>';
        // If a CSP/loader suppresses the link onload, restore after load.
        $inlineLoader = class_exists('MAD4B_Mobile_CSS_Inline_Contract_V1', false)
            ? MAD4B_Mobile_CSS_Inline_Contract_V1::inline_loader('mad4b-css-rest-v1') : '';
        if ($inlineLoader !== '') {
            $markup .= $inlineLoader;
        } else {
            // Preserve the established inline fallback when this company's
            // contract has not yet been provisioned or is intentionally off.
            $markup .= '<script data-no-optimize="1">(function(){var e=document.getElementById("mad4b-css-rest-v1");if(!e)return;window.addEventListener("load",function(){if(e.media!=="all")e.media="all"},{once:true});setTimeout(function(){if(e.media!=="all")e.media="all"},6500)})()</script>';
        }
        $result=substr_replace($buffer, $markup, $pos, strlen($tag));
        $native=self::native_mobile_breakpoint();
        if ($adaptive>0 && $native!==null && $adaptive<$native) {
            // Final-header admission is request-local; a LiteSpeed hit that
            // skipped our rewrite MUST NOT inherit this mutable state.
            self::$verifiedSubMobileCap=$adaptive;
            self::$verifiedSubMobileNative=$native;
        }
        return $result;
    }


    /**
     * The final LiteSpeed HTML buffer is the only authority to certify
     * critical + async mobile home markup for public FastCGI storage.
     * An optimization/cache hit inside LiteSpeed can skip earlier transforms:
     * in that case this proof is absent and the edge must fail closed.
     */
    public static function mobile_home_markup_complete($html): bool {
        if (!is_string($html) || strlen($html) < 25000
            || substr_count($html,'id="mad4b-critical-css-v1"')!==1
            || substr_count($html,'id="mad4b-css-rest-v1"')!==1
            || substr_count($html,'data-mad4b-css-async-lib="inline-v1"')!==1) return false;
        if (!preg_match('~<style id="mad4b-critical-css-v1"(?: media="\\(max-width:([0-9]{3,4})px\\)")?>~',
            $html,$match,PREG_OFFSET_CAPTURE)) return false;
        $start=$match[0][1]+strlen($match[0][0]);
        $adaptive=isset($match[1][0])?(int)$match[1][0]:0;
        if ($adaptive>0) {
            $native=self::native_mobile_breakpoint();
            $requestProof=$native!==null
                && $adaptive<$native
                && self::$verifiedSubMobileCap===$adaptive
                && self::$verifiedSubMobileNative===$native;
            if ($adaptive<480 || $adaptive>1100
                || ($native!==$adaptive && !$requestProof)
                || !str_contains($html,'id="mad4b-css-rest-v1"')
                || !str_contains($html,'media="(min-width:'.($adaptive+1).'px)"')
                || str_contains($html,'data-mad4b-first-city=')) return false;
        }
        $end=strpos($html,'</style>',$start);
        return $end!==false && ($end-$start)>=16000 && ($end-$start)<=1048576
            && str_contains($html,'/wp-content/litespeed/css/');
    }

    /**
     * Independent full-CSS fallback admission. Never claims Critical CSS is
     * ready. A complete native LiteSpeed bundle is safe to cache when WP's
     * existing public/cookie gates also pass. Missing or ambiguous CSS fails
     * closed. This method never synthesizes or rewrites any CSS.
     */
    public static function mobile_home_native_css_complete($html): bool {
        if (!is_string($html) || strlen($html) < 25000 || strlen($html) > 6000000
            || !str_contains($html,'</head>')
            || str_contains($html,'id="mad4b-critical-css-v1"')
            || str_contains($html,'id="mad4b-css-rest-v1"')) return false;
        preg_match_all('~<link\b[^>]*>~i',$html,$found);
        $candidates=[];
        $site=(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST);
        if ($site==='') return false;
        foreach((array)($found[0]??[]) as $tag) {
            if (!preg_match('~\brel=["\x27]stylesheet["\x27]~i',$tag)) continue;
            if (!preg_match('~\bhref=["\x27]([^"\x27]+)["\x27]~i',$tag,$matches)) continue;
            $url=html_entity_decode($matches[1],ENT_QUOTES,'UTF-8');
            $host=(string)wp_parse_url($url,PHP_URL_HOST);
            if ($host==='' || strcasecmp($host,$site)!==0) continue;
            $path=(string)wp_parse_url($url,PHP_URL_PATH);
            if (!preg_match('~^/wp-content/litespeed/css/([a-f0-9]{32}\.css)$~D',$path,$file)) continue;
            // Must be a render-blocking full stylesheet, not print/onload.
            if (preg_match('~\bonload\s*=~i',$tag)
                || (preg_match('~\bmedia=["\x27]([^"\x27]+)["\x27]~i',$tag,$media)
                    && strcasecmp($media[1],'all')!==0)) return false;
            $candidates[]=$file[1];
        }
        if(count($candidates)!==1) return false;
        $asset=WP_CONTENT_DIR.'/litespeed/css/'.$candidates[0];
        if (!is_file($asset) || is_link($asset)) {
            $asset=WP_CONTENT_DIR.'/litespeed/.mad4b-asset-retention/css/'.$candidates[0];
        }
        if (!is_file($asset) || is_link($asset) || @filesize($asset)<100000
            || @filesize($asset)>6291456) return false;
        $sha=@hash_file('sha256',$asset);
        return is_string($sha) && preg_match('/^[a-f0-9]{64}$/D',$sha)===1;
    }

    public static function certify_final_mobile_home($html) {
        if (!self::eligible() || headers_sent()) return $html;
        // This callback executes after the final MAD4B inline async loader
        // (priority 12001), so a missing tag means HTML is NOT cache-ready.
        $ready=self::mobile_home_markup_complete($html);
        header('X-MAD4B-Mobile-CSS-Ready: '.($ready ? '1' : '0'),true);
        $native_complete = !$ready
            && get_option('mad4b_perf_native_css_cache_v1','off') === 'on'
            && self::mobile_home_native_css_complete($html);
        header('X-MAD4B-Mobile-CSS-Native-Complete: '.($native_complete ? '1' : '0'),true);
        if (!$ready && !$native_complete) {
            // Defense in depth: the edge MUST NOT retain an incomplete
            // first-screen mobile snapshot even during background refresh.
            // These are final WordPress response headers, not client input.
            header('Cache-Control: private, no-store, max-age=0',true);
            header('X-Accel-Expires: 0',true);
        }
        return $html;
    }

    public static function boot(): void {
        add_filter('litespeed_buffer_finalize', [__CLASS__, 'transform'], 999, 1);
        add_filter('litespeed_buffer_finalize', [__CLASS__, 'certify_final_mobile_home'], 13000, 1);
    }
}
add_action('init', ['MAD4B_Verified_Critical_CSS_V1','boot'], 31);

/**
 * MAD4B Predictive Rendering & Preload — CSS plan v2.
 * Exactly one owner for the first-screen CSS plan; respects Elementor/JetEngine
 * native conditional rendering and never removes hidden content in PHP.
 * Pilot is opt-in per route+language+device; all unverified paths use native CSS.
 */
final class MAD4B_Predictive_CSS_Plan_V2 {
    private const OPTION = 'mad4b_perf_predictive_css_v2';
    private const REL = '/mad4b-predictive-css-v2/';
    // A request may switch company blogs. Never reuse a manifest belonging
    // to the first blog or a WPML-translated home_url() within that request.
    private static array $cachedBySite = [];

    private static function load(): array {
        if (!class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound()) return [];
        $home=get_option('home','');
        if (!is_string($home) || $home==='') return [];
        $host=(string)wp_parse_url($home,PHP_URL_HOST);
        if ($host==='' || !preg_match('/^[a-z0-9.-]+$/iD',$host)) return [];
        $key=(int)get_current_blog_id().'|'.strtolower($home);
        if (isset(self::$cachedBySite[$key])) return self::$cachedBySite[$key];
        if (count(self::$cachedBySite)>=12) self::$cachedBySite=[];
        self::$cachedBySite[$key]=[];
        $file=WP_CONTENT_DIR.self::REL.'manifest.json';
        if (is_link($file) || !is_file($file) || @filesize($file)>65536) return [];
        $data=json_decode((string)@file_get_contents($file),true);
        if (!is_array($data) || ($data['version']??null)!==2
            || ($data['contract']??'')!=='mad4b.predictive-css-plan.v2'
            || !is_string($data['site_host']??null)
            || strcasecmp($data['site_host'],$host)!==0
            || !is_array($data['routes']??null)) return [];
        return self::$cachedBySite[$key]=$data;
    }
    public static function summary(): array {
        $manifest = self::load();
        $rows = [];
        foreach (array_slice((array)($manifest['routes'] ?? []),0,16) as $route) {
            if (!is_array($route)) continue;
            foreach (['mobile','desktop'] as $device) {
                $row=$route['devices'][$device]??null;
                if (!is_array($row)) continue;
                $rows[]=['path'=>$route['path']??'', 'locale'=>$route['language']??'',
                    'device'=>$device,'status'=>$row['state']??'unknown',
                    'strategy'=>$row['strategy']??'', 'critical_gzip_bytes'=>(int)($row['critical_gzip_bytes']??0),
                    'template_count'=>count((array)($route['elementor_doc_ids']??[])),
                    'performance_gate'=>$row['performance_gate']['status']??'unverified',
                    'measured_lcp'=>(string)($row['measured_lcp']??''),
                    'baseline_fcp_ms'=>(int)($row['performance_gate']['baseline_fcp_ms']??0),
                    'async_fcp_ms'=>(int)($row['performance_gate']['async_fcp_ms']??0),
                    'baseline_lcp_ms'=>(int)($row['performance_gate']['baseline_lcp_ms']??0),
                    'async_lcp_ms'=>(int)($row['performance_gate']['async_lcp_ms']??0),
                    'bundle_sha'=>substr((string)($route['bundle_sha']??''),0,14),
                    'bundle_variant_shas'=>array_values(array_filter(array_map(
                        static fn($v)=>is_array($v)?(string)($v['bundle_sha']??''):'',
                        (array)($route['bundle_variants']??[])
                    )))];
            }
        }
        return ['present'=>!empty($manifest),'release'=>$manifest['release_state']??'none',
            'device_independent_release_supported'=>true,
            'live_css_epoch'=>(int)get_option('mad4b_css_ai_epoch_v1',0),
            'manifest_css_epoch'=>isset($manifest['routes']['/|en']['css_epoch']) ? (int)$manifest['routes']['/|en']['css_epoch'] : null,
            'runtime'=>get_option(self::OPTION,'off'),'routes'=>$rows,
            'bundle_variants'=>array_sum(array_map(static fn($r)=>count((array)($r['bundle_variants']??[])),(array)($manifest['routes']??[]))),
            'guard'=>'source+route+locale+device+CSS_SHA+Elementor_HTML+epoch+published_graph_digest',
            'ucss_mobile_coverage'=>get_option('mad4b_perf_css_mobile_coverage_v1', [])];
    }
    private static function current_route(): ?array {
        if (!class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound()) return null;
        if (is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !class_exists('MAD4B_Shared_First_Paint_Performance',false)
            || !MAD4B_Shared_First_Paint_Performance::home_view()) return null;
        $query = (string)($_SERVER['QUERY_STRING']??'');
        $probe = ($query === 'mad4b_predictive_css_probe=1');
        // No visitor-facing QA backdoor on any Production company.
        if ($probe && wp_get_environment_type() !== 'staging') return null;
        // CSS variants are independent of visitor pricing, but never modify
        // commerce or private request contexts, and keep cache segregation.
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['PHP_AUTH_USER'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
            || !empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])) return null;
        // A resolved LiteSpeed UCSS or Async choice owns CSS delivery. Never
        // allow MAD4B to inject a second optimization on the same request.
        if (!class_exists('MAD4B_LSC_Local_Provider_V1',false)
            || !MAD4B_LSC_Local_Provider_V1::local_css_eligible()) return null;
        $manifest=self::load();
        if (!$manifest) return null;
        // Release may certify one viewport without requiring the other viewport
        // to pass. Device + bundle gates below remain independent and mandatory.
        if (!$probe && ($query !== '' || get_option(self::OPTION,'off') !== 'on'
            || !in_array(($manifest['release_state']??''), ['certified','device_certified'], true))) return null;
        // Trusted Nginx FastCGI device classification; never accept an HTTP client header.
        $device=(string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']??'');
        if (!in_array($device,['mobile','desktop'],true)) return null;
        // Staging CSS Async canary is mobile-only: desktop LCP regressed.
        if ($probe && $device !== 'mobile') return null;
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $id=(int)get_queried_object_id();
        $canonical=(string)wp_parse_url((string)get_permalink($id), PHP_URL_PATH);
        if ($path === '' || $canonical === '' || $path !== $canonical) return null;
        $locale=apply_filters('wpml_current_language',null);
        if (!is_string($locale) || !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/iD',$locale)) return null;
        // Reuse MAD4B's existing market/cookie protection; this blocks
        // WooCommerce cart/session, logged-in, and unknown personalization.
        if (!class_exists('MAD4B_LSC_Local_Provider_V1',false)
            || !MAD4B_LSC_Local_Provider_V1::safe_cookie_header(
                (string)($_SERVER['HTTP_COOKIE']??''),$locale)) return null;
        $policy=$manifest['routes'][$path.'|'.$locale]??null;
        if (!is_array($policy) || ($policy['page_id']??null)!==$id
            || (!$probe && ($policy['css_epoch']??null)!==(int)get_option('mad4b_css_ai_epoch_v1',0))) return null;
        // Discovery runs in the admin/CLI control plane. Its class is not
        // present on ordinary public requests. Read the company-scoped, stored
        // discovery snapshot directly, with the same freshness checks as status().
        $discovery=get_option('mad4b_adaptive_presentation_discovery_v1',[]);
        $brand=get_option('mad4b_scp_brand_context_profile_v1',[]);
        $host=(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST);
        if (!is_array($discovery) || !is_array($brand)
            || empty($brand['site_uuid'])
            || ($discovery['site_uuid']??'')!==$brand['site_uuid']
            || strcasecmp((string)($discovery['site_host']??''),$host)!==0
            || ($discovery['state']??'')!=='observed'
            || !empty($discovery['truncated'])
            || !is_string($discovery['published_graph_digest']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$discovery['published_graph_digest'])
            || !is_string($policy['discovery_digest']??null)) return null;
        // Site-wide content intake can mark discovery stale for an unrelated
        // route. For explicit one-request canaries ONLY, rely on the exact
        // rendered Elementor document set + source CSS SHA checks below.
        // Public release must still have an up-to-date discovery snapshot.
        if (!$probe && ((int)get_option('mad4b_adaptive_presentation_scan_needed_v1',0)!==0
            || !hash_equals($policy['discovery_digest'],$discovery['published_graph_digest']))) return null;
        $row=$policy['devices'][$device]??null;
        if (!is_array($row) || ($row['strategy']??'')!=='critical_inline_then_native_async'
            || ($row['state']??'')!==($probe?'canary':'certified')) return null;
        // A device never graduates to live async solely because a plan was
        // generated or an administrator switched a flag. The measured LCP and
        // CLS performance evidence for this exact device must be accepted.
        if (!$probe && ($row['performance_gate']['status']??'')!=='pass') return null;
        return [$policy,$row,$device];
    }
    public static function transform($html) {
        if (!is_string($html) || strlen($html)<1000 || strlen($html)>6000000
            || !str_contains($html,'</head>') || str_contains($html,'id="mad4b-predictive-critical-v2"')) return $html;
        // Identical-route QA control: the same request still runs LiteSpeed's
        // normal optimizer, but the mobile predictive transform is suppressed.
        // This affects only the explicit staging probe and cannot enter the
        // public cached HTML route because of its query string.
        if ((string)($_SERVER['QUERY_STRING']??'')==='mad4b_predictive_css_probe=1'
            && (string)($_SERVER['HTTP_X_MAD4B_CSS_AB']??'')==='native') return $html;
        $route=self::current_route();
        if (!$route) return $html;
        [$policy,$plan,$device]=$route;
        // Exact rendered Elementor document-set verification is conservative:
        // conditional changes, translations and new templates fall back to native CSS.
        $bodyStart=stripos($html,'<body');
        if ($bodyStart===false) return $html;
        preg_match_all('/(?<![a-z0-9_-])elementor-(\d+)\b/i',substr($html,$bodyStart),$docMatches);
        $ids=array_values(array_unique(array_map('intval',$docMatches[1])));
        sort($ids,SORT_NUMERIC);
        $docHash=hash('sha256',implode(',',$ids));
        if (!is_string($policy['elementor_doc_fingerprint']??null)
            || !hash_equals($policy['elementor_doc_fingerprint'],$docHash)) return $html;
        $matches=[];
        preg_match_all('~<link\b[^>]*>~i',$html,$links,PREG_OFFSET_CAPTURE);
        foreach ($links[0] as [$tag,$at]) {
            if (!preg_match('~\brel=["\x27]stylesheet["\x27]~i',$tag)
                || !preg_match('~\bhref=["\x27]([^"\x27]+)["\x27]~i',$tag,$found)) continue;
            $url=html_entity_decode($found[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            if (strcasecmp((string)wp_parse_url($url,PHP_URL_HOST),(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST))!==0) continue;
            $path=(string)wp_parse_url($url,PHP_URL_PATH);
            if (!preg_match('~^/wp-content/litespeed/css/([a-f0-9]{32}\.css)$~D',$path,$foundPath)) continue;
            $matches[]=[$tag,$at,$url,$foundPath[1]];
        }
        if (count($matches)!==1) return $html;
        [$old,$offset,$url,$bundle]=$matches[0];
        // A route can have several byte-verified, stable LiteSpeed bundles, for
        // example a cached public bundle and a JetEngine-normalized uncached one.
        // Never infer equivalence from URL names, source prefix or template IDs.
        $versions=(array)($policy['bundle_variants']??[]);
        $variant=$versions[$bundle]??null;
        if ($versions) {
            if (!is_array($variant) || ($variant['bundle_file']??'')!==$bundle) return $html;
            $candidate=$variant['devices'][$device]??null;
            $isProbe=((string)($_SERVER['QUERY_STRING']??''))==='mad4b_predictive_css_probe=1';
            if (!is_array($candidate)
                || ($candidate['strategy']??'')!=='critical_inline_then_native_async'
                || ($candidate['state']??'')!==($isProbe?'canary':'certified')) return $html;
            if (!$isProbe && ($candidate['performance_gate']['status']??'')!=='pass') return $html;
            $plan=$candidate;
            $sourceSha=(string)($variant['bundle_sha']??'');
        } else {
            // Backward-compatible single-bundle manifests fail closed too.
            if (($policy['bundle_file']??'')!==$bundle) return $html;
            $sourceSha=(string)($policy['bundle_sha']??'');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D',$sourceSha)) return $html;
        // A public release must also have fresh, route/device/variant-matched
        // measured evidence. The receipt is deterministic data from the native
        // Performance Intelligence engine: no certificates, approvals or new
        // external broker. A stale/missing receipt restores native CSS.
        $isProbe=(string)($_SERVER['QUERY_STRING']??'')==='mad4b_predictive_css_probe=1';
        if (!$isProbe) {
            $rec=get_option('mad4b_perf_css_policy_receipt_v2',[]);
            $host=(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST);
            $criticalHash=(string)($plan['critical_sha']??'');
            $fingerprint=hash('sha256',implode('|',[$host,(string)($policy['path']??''),
                (string)apply_filters('wpml_current_language',''),$device,$sourceSha,
                (string)($policy['elementor_doc_fingerprint']??''),$criticalHash,
                (string)(int)($policy['css_epoch']??-1)]));
            $rule=is_array($rec)?($rec['policies'][$fingerprint]??null):null;
            if (!is_array($rec) || ($rec['contract']??'')!=='mad4b.css-runtime-policy.v2'
                || ($rec['site_host']??'')!==$host
                || ($rec['environment']??'')!==wp_get_environment_type()
                || !is_array($rule) || ($rule['decision']??'')!=='passed_evidence'
                || ($rule['device']??'')!==$device
                || (int)($rule['expires_at_epoch']??0)<=time()
                || (int)($rec['evaluated_at_epoch']??0)>time()+300) return $html;
        }

        $root=realpath(WP_CONTENT_DIR.'/litespeed/css');
        $css=$root?realpath($root.'/'.$bundle):false;
        if (!$root || !$css || !str_starts_with($css,$root.'/') || !is_file($css)
            || is_link($css) || filesize($css)>6291456 || hash_file('sha256',$css)!==$sourceSha) return $html;
        $name=(string)($plan['critical_file']??'');$sha=(string)($plan['critical_sha']??'');
        if (!preg_match('/^[a-f0-9]{64}\.css$/D',$name)
            || !preg_match('/^[a-f0-9]{64}$/D',$sha)) return $html;
        $critical=WP_CONTENT_DIR.self::REL.$name;
        if (is_link($critical) || !is_file($critical) || filesize($critical)<16000
            || filesize($critical)>1048576 || hash_file('sha256',$critical)!==$sha) return $html;
        $style=@file_get_contents($critical);
        if (!is_string($style) || stripos($style,'</style')!==false || stripos($style,'<script')!==false) return $html;
        // LiteSpeed's own verified 1.35KB inline async library, no QUIC.cloud dependency.
        $library=WP_PLUGIN_DIR.'/litespeed-cache/assets/js/css_async.min.js';
        if (!is_file($library) || is_link($library) || filesize($library)<200 || filesize($library)>5000) return $html;
        $loader=@file_get_contents($library);
        if (!is_string($loader) || stripos($loader,'</script')!==false) return $html;
        $safe=esc_url($url);
        // Candidate UCSS is NEVER the public fallback. It can be loaded only
        // by a one-request mobile QA probe on the exact SHA-matched CSS asset.
        // Generated coverage is NOT proof that Elementor/JetEngine async DOM
        // mutations, translated widgets or filters are safe to strip.
        $ucssQa=(string)($_SERVER['QUERY_STRING']??'')==='mad4b_predictive_css_probe=1'
            && in_array((string)($_SERVER['HTTP_X_MAD4B_CSS_AB']??''),['ucss','ucss_priority'],true)
            && $device==='mobile';
        $ucssUsed=false;
        if ($ucssQa) {
            $dataFile=WP_CONTENT_DIR.self::REL.'mobile-ucss-aggressive-'.$bundle.'.json';
            $ucssData=(!is_link($dataFile)&&is_file($dataFile)&&filesize($dataFile)<8192)
                ? json_decode((string)@file_get_contents($dataFile),true) : null;
            if (is_array($ucssData)
                && ($ucssData['contract']??'')==='mad4b.ucss.mobile-aggressive-canary.v1'
                && ($ucssData['state']??'')==='canary_only'
                && ($ucssData['device']??'')==='mobile'
                && ($ucssData['language']??'')===(string)apply_filters('wpml_current_language','')
                && ($ucssData['site_host']??'')===(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST)
                && ($ucssData['source_bundle']??'')===$bundle
                && ($ucssData['source_sha256']??'')===$sourceSha
                && is_string($ucssData['candidate_filename']??null)
                && preg_match('/^[a-f0-9]{64}\.ucss-aggressive-canary\.css$/D',$ucssData['candidate_filename'])
                && is_string($ucssData['candidate_sha256']??null)) {
                $ucssFile=WP_CONTENT_DIR.self::REL.$ucssData['candidate_filename'];
                if (!is_link($ucssFile)&&is_file($ucssFile)
                    && filesize($ucssFile)>=10000 && filesize($ucssFile)<=3000000
                    && hash_equals((string)$ucssData['candidate_sha256'],(string)hash_file('sha256',$ucssFile))) {
                    $safe=esc_url(content_url(self::REL.$ucssData['candidate_filename']));
                    $ucssUsed=true;
                }
            }
        }
        $tag='<style id="mad4b-predictive-critical-v2" data-device="'.esc_attr($device).'">'.$style.'</style>';
        $tag.='<script id="mad4b-predictive-inline-css-async-lib" data-no-optimize="1">'.$loader.'</script>';
        // Let the critical image preload win. Complete CSS remains a reliable fallback.
        // Compare the native CSS async priority against high-priority full-
        // stylesheet preload without changing public policy or CSS content.
        // Restricted to the explicit staging canary; all other routes unchanged.
        $priorityProbe = (string)($_SERVER['QUERY_STRING']??'') === 'mad4b_predictive_css_probe=1'
            && in_array((string)($_SERVER['HTTP_X_MAD4B_CSS_AB']??''),['priority','ucss_priority'],true);
        if ($priorityProbe) $tag .= '<link rel="preload" as="style" fetchpriority="high" href="'.$safe.'" data-mad4b-css-priority="pilot">';
        $ucssAttr=$ucssUsed ? ' data-mad4b-ucss-candidate="qa-only"' : '';
        $tag.='<link id="mad4b-predictive-full-css"'.$ucssAttr.' rel="stylesheet" media="print" href="'.$safe.'" onload="this.onload=null;this.media=\'all\'">';
        $tag.='<noscript>'.$old.'</noscript>';
        $tag.='<script data-no-optimize="1">(function(){var x=document.getElementById("mad4b-predictive-full-css");if(!x)return;function restore(){if(x.media!=="all"&&x.sheet)x.media="all"}window.addEventListener("load",restore,{once:true});setTimeout(restore,9000)})();</script>';
        return substr_replace($html,$tag,$offset,strlen($old));
    }
    public static function boot(): void {
        add_filter('litespeed_buffer_finalize',[__CLASS__,'transform'],1001,1);
    }
}
add_action('init',['MAD4B_Predictive_CSS_Plan_V2','boot'],32);
