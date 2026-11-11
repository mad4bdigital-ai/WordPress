<?php
/**
 * Plugin Name: MAD4B Local-First LiteSpeed Compatibility Authority
 * Version: 0.2.0
 * Description: Coordinates MAD4B's certified, device-aware CSS overlays with LiteSpeed Cache without changing LSCWP files or replacing its native optimizer.
 */
defined('ABSPATH') || exit;

final class MAD4B_LSC_Local_Provider_V1 {
    const CONTRACT = 'mad4b.lsc.local-first.v1';
    const OPT = 'mad4b_lsc_css_provider_v1';
    const DEFAULT_MODE = 'local_first';

    public static function identity(): array {
        // Tenant-owned identity from canonical WordPress settings, not locale-filtered home_url().
        $home = untrailingslashit((string)get_option('home', ''));
        $scheme = (string)wp_parse_url($home, PHP_URL_SCHEME);
        $host = (string)wp_parse_url($home, PHP_URL_HOST);
        $environment = wp_get_environment_type();
        $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
        $uuid = is_array($profile) ? (string)($profile['site_uuid'] ?? '') : '';
        if (!in_array($scheme, ['https', 'http'], true) || $host === ''
            || !in_array($environment, ['staging', 'production'], true)
            || !wp_is_uuid($uuid)) return [];
        if (class_exists('MAD4B_SCP_Site_Profile', false)
            && method_exists('MAD4B_SCP_Site_Profile', 'status')) {
            $status = MAD4B_SCP_Site_Profile::status();
            if (!is_array($status) || empty($status['configured'])
                || empty($status['origin_match']) || empty($status['environment_match'])
                || empty($status['deployment_binding_match'])
                || (string)($status['site_uuid'] ?? '') !== $uuid) return [];
        }
        if ($environment === 'production' && $scheme !== 'https') return [];
        if (defined('MAD4B_TENANT_SITE_UUID') && $uuid !== (string)MAD4B_TENANT_SITE_UUID) return [];
        if (defined('MAD4B_TENANT_ORIGIN')
            && rtrim(strtolower($home), '/') !== rtrim(strtolower((string)MAD4B_TENANT_ORIGIN), '/')) return [];
        if (defined('MAD4B_TENANT_ENVIRONMENT')
            && $environment !== (string)MAD4B_TENANT_ENVIRONMENT) return [];
        return ['origin' => $home, 'host' => strtolower($host),
            'site_uuid' => $uuid, 'environment' => $environment];
    }

    public static function scope(): bool {
        return self::identity() !== [];
    }

    public static function mode(): string {
        if (!self::scope()) return 'native';
        $value = (string)get_option(self::OPT, self::DEFAULT_MODE);
        return in_array($value, ['local_first', 'native'], true) ? $value : 'native';
    }

    public static function local_css_eligible(): bool {
        if (!self::scope() || self::mode() !== 'local_first') return false;
        // Runtime versions are matched against each worker-certified CSS manifest,
        // not a site-specific release pair hardcoded into the plugin.
        if (!defined('LSCWP_V') || !defined('ELEMENTOR_VERSION')
            || !is_string(LSCWP_V) || LSCWP_V === ''
            || !is_string(ELEMENTOR_VERSION) || ELEMENTOR_VERSION === '') return false;
        if (!self::resolved_css_switch_v2('optm-css_comb')
            || !self::resolved_css_switch_v2('cache-mobile')) return false;
        // A genuine QUIC CSS optimizer is mutually exclusive with MAD4B CSS deferral.
        if (self::resolved_css_switch_v2('optm-ucss')
            || self::resolved_css_switch_v2('optm-css_async')
            || self::resolved_css_switch_v2('guest_optm')) return false;
        return true;
    }

    public static function device(string $userAgent): string {
        // A shared two-device classification for LiteSpeed/Nginx cache variants.
        return preg_match('~Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi~i',
            $userAgent) ? 'mobile' : 'desktop';
    }

    public static function source_version(): string {
        return (defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : 'absent')
            . '/' . (defined('LSCWP_V') ? (string) LSCWP_V : 'absent');
    }

    public static function safe_cookie_header(string $raw, string $locale): bool {
        // Reused by the local CSS runtime without depending on the retired legacy experiment.
        if ($locale === '' || strlen($raw) > 4096) return false;
        $seen = [];
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;
            $eq = strpos($pair, '=');
            if ($eq === false) return false;
            $name = trim(substr($pair, 0, $eq));
            $value = trim(substr($pair, $eq + 1));
            if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $name) || isset($seen[$name])) return false;
            $seen[$name] = true;
            if ($name === 'wp-wpml_current_language') {
                if (!hash_equals($locale, rawurldecode($value))) return false;
            } elseif ($name === '_lscache_vary') {
                if (!preg_match('/^[A-Za-z0-9._-]{1,180}$/D', $value)) return false;
            } elseif (in_array($name, ['sbjs_current','sbjs_current_add','sbjs_first',
                'sbjs_first_add','sbjs_migrations','sbjs_session','sbjs_udata',
                '_ga','_gid','_gcl_au','_fbp'], true)
                || preg_match('/^_ga_[A-Za-z0-9]{4,24}$/D', $name)) {
                continue;
            } else {
                return false;
            }
        }
        return true;
    }

    public static function legacy_css_allowed(): bool {
        // Legacy MU334 is retained only as an inert compatibility facade.
        return false;
    }


    private static function resolved_css_switch_v2(string $key): bool {
        $class='LiteSpeed\\Conf';
        if(class_exists($class))return (bool)$class::cls()->conf($key);
        return (string)get_option('litespeed.conf.'.$key,'0')==='1';
    }

    public static function css_delivery_diagnostics_v2(): array {
        $keys=['optm-css_comb','optm-css_comb_ext_inl','optm-ucss','optm-ucss_inline','optm-css_async','optm-css_async_inline'];
        $config=[];$resolved=[];$codes=[];
        $class='LiteSpeed\\Conf';
        $c=class_exists($class)?$class::cls():null;
        foreach($keys as $k){
            $config[$k]=(string)get_option('litespeed.conf.'.$k,'0')==='1';
            $resolved[$k]=self::resolved_css_switch_v2($k);
        }
        $cloud='LiteSpeed\\Cloud';
        $qc=class_exists($cloud)?(bool)$cloud::cls()->activated():false;
        $u=$resolved['optm-ucss'];$a=$resolved['optm-css_async'];
        if($resolved['optm-ucss_inline']&&!$u)$codes[]='UCSS_INLINE_PARENT_OFF';
        if($resolved['optm-css_async_inline']&&!$a)$codes[]='ASYNC_LIBRARY_PARENT_OFF';
        if($a&&!$qc)$codes[]='CCSS_QUIC_INACTIVE';
        if($u&&!$qc)$codes[]='UCSS_QUIC_INACTIVE';
        if($u&&$a&&$resolved['optm-ucss_inline'])$codes[]='ASYNC_CONFLICT_UCSS_INLINE';
        foreach($keys as $k)if($config[$k]!==$resolved[$k])$codes[]='RESOLVED_OVERRIDE_'.$k;
        $observed=get_option('mad4b_lsc_css_frontend_observed_v2',[]);
        $recent=[];
        if(is_array($observed)&&($observed['contract']??'')==='mad4b.lsc.css-html-observation.v2'
            && ($observed['site_host']??'')===(string)wp_parse_url((string)get_option('home'),PHP_URL_HOST)) {
            foreach((array)($observed['samples']??[]) as $sample) {
                if(!is_array($sample)||(int)($sample['observed_at_epoch']??0)<time()-3600)continue;
                $recent[]=$sample;
                if(!empty($sample['litespeed_ccss'])&&!$a)$codes[]='OBSERVED_CCSSLINK_WHILE_ASYNC_OFF';
                if(!empty($sample['litespeed_ucss'])&&!$u)$codes[]='OBSERVED_UCSS_WHILE_GENERATION_OFF';
                if(!empty($sample['mad4b_ccss'])&&(int)(get_option('mad4b_perf_css_policy_receipt_v2',[])['approved_count']??0)===0)
                    $codes[]='OBSERVED_MAD4B_CCSS_WITHOUT_APPROVED_VARIANT';
            }
        }
        $bundleDrift=get_option('mad4b_lsc_css_bundle_drift_v2',[]);
        $activeBundleDrift=[];
        if (is_array($bundleDrift) && ($bundleDrift['contract']??'')==='mad4b.lsc.css-bundle-drift.v2'
            && ($bundleDrift['site_host']??'')===(string)wp_parse_url((string)get_option('home'),PHP_URL_HOST)
            && (int)($bundleDrift['observed_at_epoch']??0)>time()-86400) {
            $manifestFile=WP_CONTENT_DIR.'/mad4b-predictive-css-v2/manifest.json';
            if (is_file($manifestFile) && !is_link($manifestFile) && filesize($manifestFile)<65536) {
                $manifest=json_decode((string)@file_get_contents($manifestFile),true);
                $route=is_array($manifest)?($manifest['routes'][($bundleDrift['path']??'').'|'.($bundleDrift['locale']??'')]??null):null;
                if (is_array($route) && is_array($route['bundle_variants']??null)
                    && !isset($route['bundle_variants'][$bundleDrift['bundle_file']??''])) {
                    $codes[]='CSS_BUNDLE_NOT_IN_CERTIFIED_MANIFEST';
                    $activeBundleDrift=$bundleDrift;
                }
            }
        }
        $codes=array_values(array_unique($codes));
        return ['contract'=>'mad4b.lsc.css-effective-state.v2','configured'=>$config,
            'resolved'=>$resolved,'quic_activated'=>$qc,'drift_codes'=>$codes,
            'effective_async'=>$a&&$qc&&!($u&&$resolved['optm-ucss_inline'])?'conditional_on_ccss':'off',
            'effective_ucss'=>$u&&$qc?'conditional_on_ucss_artifact':'off',
            'last_event'=>get_option('mad4b_lsc_css_drift_event_v1',[]),
            'observed_frontend'=>$recent,'bundle_drift'=>$activeBundleDrift];
    }
    public static function css_setting_changed_v2($old,$new,$option): void {
        if($old===$new)return;
        update_option('mad4b_lsc_css_drift_event_v1',['option'=>$option,
            'observed_at'=>time(),'contract'=>'mad4b.lsc.css-option-event.v1'],false);
        do_action('mad4b/performance/css_plan_updated');
    }
    /** Observe anonymous LiteSpeed output, not Nginx/edge cache hits. */
    public static function observe_css_html_v2($html) {
        if (!is_string($html) || strlen($html)<500 || strlen($html)>6000000
            || wp_get_environment_type()!=='staging' || is_admin()
            || wp_doing_ajax() || is_user_logged_in()
            || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
            || !empty($_SERVER['QUERY_STRING']) || !empty($_SERVER['HTTP_COOKIE'])
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) return $html;
        $device=(string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']??'');
        if (!in_array($device,['mobile','desktop'],true))return $html;
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $lang=(string)apply_filters('wpml_current_language','');
        if ($path==='' || strlen($path)>120
            || !preg_match('~^/[a-z0-9/_\\.-]*$~iD',$path)
            || !preg_match('/^[a-z]{2}$/iD',$lang))return $html;
        // Only public HTML pages; protocol discovery and REST responses must
        // not be treated as page-level CSS observations.
        if (str_starts_with($path,'/.') || str_contains($path,'/wp-json/')
            || !str_contains(strtolower($html),'<html')
            || !str_contains(strtolower($html),'</head>')) return $html;
        preg_match('~/wp-content/litespeed/css/([a-f0-9]{32}\\.css)~i',$html,$bundleMatch);
        $bundle=(string)($bundleMatch[1]??'');
        $host=(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST);
        $old=get_option('mad4b_lsc_css_frontend_observed_v2',[]);
        $samples=is_array($old)?(array)($old['samples']??[]):[];
        $id=$lang.'|'.$path.'|'.$device;
        foreach($samples as $row)if (is_array($row) && ($row['id']??'')===$id
            && (string)($row['bundle_file']??'')===$bundle
            && (int)($row['observed_at_epoch']??0)>time()-300)return $html;
        $samples=array_values(array_filter($samples,static fn($r)=>is_array($r)&&($r['id']??'')!==$id));
        $samples[]=['id'=>$id,'path'=>$path,'locale'=>$lang,'device'=>$device,
            'bundle_file'=>$bundle,'observed_at_epoch'=>time(),
            'litespeed_ccss'=>str_contains($html,'id="litespeed-ccss"'),
            'litespeed_ucss'=>str_contains($html,'id="litespeed-ucss"'),
            'mad4b_ccss'=>str_contains($html,'id="mad4b-predictive-critical-v2"'),
            'mad4b_async'=>str_contains($html,'id="mad4b-predictive-full-css"'),
            'css_bundle'=>str_contains($html,'/litespeed/css/')];
        update_option('mad4b_lsc_css_frontend_observed_v2',[
            'contract'=>'mad4b.lsc.css-html-observation.v2',
            'site_host'=>$host,'source'=>'php_litespeed_finalize',
            'samples'=>array_slice($samples,-12)],false);
        // A new LiteSpeed bundle is operational drift, even when the saved
        // CSS settings and global epoch are unchanged. Never auto-certify it.
        $manifestFile=WP_CONTENT_DIR.'/mad4b-predictive-css-v2/manifest.json';
        if ($bundle!=='' && is_file($manifestFile) && !is_link($manifestFile)
            && filesize($manifestFile)<65536) {
            $manifest=json_decode((string)@file_get_contents($manifestFile),true);
            $route=is_array($manifest)?($manifest['routes'][$path.'|'.$lang]??null):null;
            if (($manifest['site_host']??'')===$host && is_array($route)
                && is_array($route['bundle_variants']??null)
                && !isset($route['bundle_variants'][$bundle])) {
                $previous=get_option('mad4b_lsc_css_bundle_drift_v2',[]);
                $fingerprint=hash('sha256',$lang.'|'.$path.'|'.$device.'|'.$bundle);
                if (!is_array($previous) || ($previous['fingerprint']??'')!==$fingerprint) {
                    update_option('mad4b_lsc_css_bundle_drift_v2',[
                        'contract'=>'mad4b.lsc.css-bundle-drift.v2',
                        'site_host'=>$host,'locale'=>$lang,'path'=>$path,'device'=>$device,
                        'bundle_file'=>$bundle,'fingerprint'=>$fingerprint,
                        'observed_at_epoch'=>time(),'status'=>'unrecognized_for_exact_css_plan'],false);
                    do_action('mad4b/performance/css_plan_updated');
                }
            }
        }
        return $html;
    }

    public static function capabilities(): array {
        if (!self::scope()) return ['scope' => 'unavailable'];
        return [
            'contract' => self::CONTRACT,
            'scope' => self::identity()['environment'],
            'mode' => self::mode(),
            'css_combine_owner' => 'litespeed_cache',
            'device_cache_owner' => 'litespeed_cache_and_nginx',
            'candidate_and_certification_owner' => 'mad4b_local_worker',
            'runtime_css_overlay' => self::local_css_eligible() ? 'eligible_certified_only' : 'native_litespeed',
            'remote_css_optimization' => 'mutually_exclusive_fail_closed',
            'quic_services' => [
                // These remain in native LSCWP/QUIC until a locally VERIFIED functional
                // replacement exists. Never pretend MU CSS replaces CDN or image transcoding.
                'cdn' => (string)get_option('litespeed.conf.cdn-quic', '0') === '1' ? 'litespeed_passthrough' : 'off',
                'image_optimization' => (string)get_option('litespeed.conf.img_optm-auto', '0') === '1' ? 'litespeed_passthrough' : 'off',
                'viewport_images' => (string)get_option('litespeed.conf.media-vpi', '0') === '1' ? 'litespeed_passthrough' : 'off',
                'ccss' => self::css_delivery_diagnostics_v2()['effective_async'],
                'ucss' => self::css_delivery_diagnostics_v2()['effective_ucss'],
            ],
            'no_plugin_source_mutation' => true,
        ];
    }

    public static function boot(): void {
        if (!self::scope()) return;
        // No duplicate remote job, generator, HTTP hook or image replacement.
        // LSCWP owns its native CSS combine. MAD4B CSS overlay attaches only
        // after it has certified source, device, language, cache, and vitals.
        add_action('wp', [__CLASS__, 'enforce_single_css_owner'], 104);
        add_filter('litespeed_buffer_finalize',[__CLASS__,'observe_css_html_v2'],1201,1);
        foreach(['optm-ucss','optm-ucss_inline','optm-css_async','optm-css_async_inline','optm-css_comb','optm-css_comb_ext_inl','guest_optm','cache-mobile'] as $k) add_action('update_option_litespeed.conf.'.$k,[__CLASS__,'css_setting_changed_v2'],20,3);
    }

    public static function enforce_single_css_owner(): void {
        if (self::mode() !== 'local_first') return;
        // A user switching on QUIC UCSS/async at any time gets native LSCWP,
        // never two CSS generators or competing asynchronous CSS treatments.
        if (!self::local_css_eligible()) {
            do_action('mad4b/litespeed/compat_fallback', [
                'contract' => self::CONTRACT,
                'reason' => 'local_css_ineligible_or_quic_css_active',
            ]);
        }
    }
}
add_action('plugins_loaded', ['MAD4B_LSC_Local_Provider_V1', 'boot'], 30);
