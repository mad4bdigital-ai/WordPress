<?php
/**
 * Plugin Name: MAD4B Verified Critical CSS
 * Version: 0.3.12
 * Description: Serve measured first-paint CSS only for exact live LiteSpeed CSS content hashes. Fail open on any mismatch.
 */
defined('ABSPATH') || exit;

final class MAD4B_Verified_Critical_CSS_V1 {
    private const OPT = 'mad4b_perf_critical_css_v1';
    private const STORAGE = '/mad4b-critical-css-v1';
    private const MAX_CSS = 1048576;
    private static $manifest = null;

    private static function eligible(): bool {
        if (is_admin() || wp_doing_ajax() || is_user_logged_in()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')
            || !class_exists('MAD4B_Shared_First_Paint_Performance', false)
            || !MAD4B_Shared_First_Paint_Performance::home_view()) return false;
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($query !== '') {
            return $query === 'mad4b_critical_css_probe=1';
        }
        return get_option(self::OPT, 'off') === 'on';
    }

    private static function entries(): array {
        if (self::$manifest !== null) return self::$manifest;
        self::$manifest = [];
        $filename = WP_CONTENT_DIR . self::STORAGE . '/manifest.json';
        if (is_link($filename) || !is_file($filename) || @filesize($filename) > 32768) return [];
        $decoded = json_decode((string) @file_get_contents($filename), true);
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1
            || !is_array($decoded['entries'] ?? null)) return [];
        return self::$manifest = $decoded['entries'];
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
            $siteHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
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
        $entries = self::entries();
        if (!is_string($sha) || !is_array($entries[$sha] ?? null)) return $buffer;
        $meta = $entries[$sha];
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

        $safeUrl = esc_url($url);
        $markup = '<style id="mad4b-critical-css-v1">' . $critical . '</style>';
        // Promote the nonblocking complete sheet to high network priority.
        // Both links share the exact same URL so Chromium can reuse preload.
        $markup .= '<link rel="preload" as="style" fetchpriority="high" href="' . $safeUrl . '" />';
        $markup .= '<link id="mad4b-css-rest-v1" rel="stylesheet" href="' . $safeUrl . '" media="print" onload="this.onload=null;this.media=&#39;all&#39;" />';
        $markup .= '<noscript>' . $tag . '</noscript>';
        // If a CSP/loader suppresses the link onload, restore after load.
        $markup .= '<script data-no-optimize="1">(function(){var e=document.getElementById("mad4b-css-rest-v1");if(!e)return;window.addEventListener("load",function(){if(e.media!=="all")e.media="all"},{once:true});setTimeout(function(){if(e.media!=="all")e.media="all"},6500)})()</script>';
        return substr_replace($buffer, $markup, $pos, strlen($tag));
    }

    public static function boot(): void {
        add_filter('litespeed_buffer_finalize', [__CLASS__, 'transform'], 999, 1);
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
    private static ?array $cached = null;

    private static function load(): array {
        if (self::$cached !== null) return self::$cached;
        self::$cached = [];
        $file = WP_CONTENT_DIR . self::REL . 'manifest.json';
        if (is_link($file) || !is_file($file) || @filesize($file) > 65536) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        $host = (string)wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!is_array($data) || ($data['version'] ?? null) !== 2
            || ($data['contract'] ?? '') !== 'mad4b.predictive-css-plan.v2'
            || !is_string($data['site_host'] ?? null)
            || strcasecmp($data['site_host'], $host) !== 0
            || !is_array($data['routes'] ?? null)) return [];
        return self::$cached = $data;
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
        $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
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
        if (!$probe && ((int)get_option('mad4b_adaptive_presentation_scan_needed_v1',0)
                > (int)($discovery['scanned_at']??0)
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
            if (strcasecmp((string)wp_parse_url($url,PHP_URL_HOST),(string)wp_parse_url(home_url('/'),PHP_URL_HOST))!==0) continue;
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
            $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
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
                && ($ucssData['site_host']??'')===(string)wp_parse_url(home_url('/'),PHP_URL_HOST)
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
