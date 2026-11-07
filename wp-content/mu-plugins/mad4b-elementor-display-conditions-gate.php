<?php
/**
 * Plugin Name: MAD4B — Elementor Safe Display Conditions Gate
 * Description: Company-scoped, WPML-aware native Elementor Pro conditions preflight with fail-closed runtime guards.
 * Version: 0.6.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Elementor_Condition_Tree_Gate {
    private const POLICY = 'mad4b-elementor-condition-gate-policy.json';
    private const CONTRACT = 'mad4b.elementor.display-conditions-gate.v1';
    private static array $seen = [];
    private static bool $eligible = false;
    private static bool $installed = false;
    private static array $stats = [
        'nodes'=>0,'conditional'=>0,'verified_unconditional'=>0,
        'skipped'=>0,'native'=>0,'installed'=>0,'audit_checked'=>0,
        'audit_mismatch'=>0,'audit_settings_ns'=>0,'tree_scan_ns'=>0,
    ];

    public static function policy(): array {
        static $config = null;
        if ($config !== null) return $config;
        $config = [];
        $path = WPMU_PLUGIN_DIR . '/' . self::POLICY;
        if (!is_file($path) || !is_readable($path) || is_link($path)) return [];
        $raw = @file_get_contents($path);
        $doc = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true) : null;
        if (!is_array($doc) || ($doc['contract']??'') !== self::CONTRACT) return [];
        $host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        $environment = wp_get_environment_type();
        $company=$doc['company_code']??null;
        if (!is_string($host)
            || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}\z/D',$host)
            || strtolower((string)wp_parse_url(home_url('/'),PHP_URL_SCHEME))!=='https'
            || !in_array($environment,['staging','production'],true)
            || ($doc['site_host']??'')!==$host
            || ($doc['environment']??'')!==$environment
            || !in_array(($doc['status']??''),['canary','active'],true)
            || ($doc['routes']??'')!=='public'
            || ($doc['active_route_scope']??'')!=='front_page_only'
            || !is_string($company)
            || !preg_match('/\A[a-z][a-z0-9-]{2,47}\z/D',$company))
            return [];
        // The local company profile is authoritative when available;
        // no central certificate, signature or broker round-trip needed.
        if (defined('MAD4B_TENANT_COMPANY_CODE')
            && MAD4B_TENANT_COMPANY_CODE!==$company)return [];
        if (defined('MAD4B_TENANT_ORIGIN')
            && rtrim((string)MAD4B_TENANT_ORIGIN,'/')!=='https://'.$host)return [];
        if (defined('MAD4B_TENANT_ENVIRONMENT')
            && MAD4B_TENANT_ENVIRONMENT!==$environment)return [];
        $languages=$doc['languages']??null;
        if (!is_array($languages) || !$languages || count($languages)>30)return [];
        $valid=[];
        foreach($languages as $locale){
            if (!is_string($locale)
                || !preg_match('/\A[a-z]{2,3}(?:-[a-z]{2,3})?\z/D',$locale)
                || isset($valid[$locale]))return [];
            $valid[$locale]=true;
        }
        return $config=$doc;
    }

    private static function known_public_route(): bool {
        if (is_front_page() || is_home()) return true;
        if (is_tax(['destinations','tour-types','tour-styles'])) return true;
        if (is_post_type_archive('tours-and-activities')) return true;
        return is_singular(['page','post','tours-and-activities']);
    }

    public static function request(): bool {
        $cfg=self::policy();
        if (!$cfg || (string)get_option('mad4b_elementor_condition_gate_v1','1')==='0')
            return false;
        if (is_admin() || wp_doing_ajax() || is_user_logged_in() || is_preview()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('WP_CLI') && WP_CLI)
            || ($_SERVER['REQUEST_METHOD']??'')!=='GET'
            || !empty($_SERVER['QUERY_STRING'])
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_MAD4B_PASSPORT'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
            || (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE))
            return false;
        if (function_exists('is_customize_preview') && is_customize_preview())return false;
        if (function_exists('is_cart') && is_cart()
            || function_exists('is_checkout') && is_checkout()
            || function_exists('is_account_page') && is_account_page()
            || function_exists('is_wc_endpoint_url') && is_wc_endpoint_url())
            return false;
        if (!self::known_public_route() || !empty($_SERVER['HTTP_HOST'])
            && strtolower((string)$_SERVER['HTTP_HOST'])!==$cfg['site_host'])return false;
        $lang=(string)apply_filters('wpml_current_language','');
        if (!in_array($lang,$cfg['languages'],true))return false;
        $wire=(string)($_SERVER['HTTP_COOKIE']??'');
        // Locale cookie alone is safe, but ANY currency, session, cart, market,
        // visitor or analytics cookie restores the unmodified native handler.
        if ($wire!=='') {
            if (strlen($wire)>160 || str_contains($wire,';'))return false;
            $parts=explode('=',trim($wire),2);
            if (count($parts)!==2 || $parts[0]!=='wp-wpml_current_language'
                || rawurldecode($parts[1])!==$lang)return false;
        }
        foreach ((array)$_COOKIE as $name=>$value) {
            if ($name!=='wp-wpml_current_language'
                || !is_string($value) || $value!==$lang)return false;
        }
        $mode=(string)($_SERVER['HTTP_X_MAD4B_CONDITION_GATE']??'');
        if ($mode==='native-v1')return false;
        // Audited source and company home pages only. Taxonomy archives,
        // tours and other pages remain explicit canary until separately
        // audited. Language is validated above and differs by company.
        if ($cfg['status']==='active' && is_front_page())return true;
        return $mode==='v1' && ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1';
    }

    public static function discover($nodes,$document_id) {
        if (!self::$eligible || !is_array($nodes))return $nodes;
        $start=hrtime(true);
        $walk=static function($children)use(&$walk):void {
            foreach($children as $node) {
                if(!is_array($node))continue;
                $id=$node['id']??null;
                if (is_string($id) && preg_match('/^[a-zA-Z0-9]{4,32}$/D',$id)) {
                    $settings=$node['settings']??null;
                    $safe=is_array($settings)
                        && !array_key_exists('e_display_conditions',$settings)
                        && !array_key_exists('display-conditions',$settings)
                        && !array_key_exists('__dynamic__',$settings)
                        && !array_key_exists('conditions',$settings)
                        && !array_key_exists('props',$node);
                    // For duplicate IDs, any uncertain instance blocks reuse.
                    self::$seen[$id]=isset(self::$seen[$id])?self::$seen[$id]&&$safe:$safe;
                    self::$stats['nodes']++;
                    self::$stats[$safe?'verified_unconditional':'conditional']++;
                }
                if (isset($node['elements']) && is_array($node['elements']))$walk($node['elements']);
            }
        };
        $walk($nodes);
        self::$stats['tree_scan_ns']+=hrtime(true)-$start;
        return $nodes;
    }

    public static function install():void {
        self::$eligible=self::request();
        if (!self::$eligible)return;
        global $wp_filter;
        $findNative = static function(string $tag) use ($wp_filter):array {
            $hook=$wp_filter[$tag]??null;
            if (!($hook instanceof WP_Hook)) return [];
            $matches=[];
            foreach ($hook->callbacks[10]??[] as $entry) {
                $cb=$entry['function']??null;
                if (is_array($cb) && count($cb)===2 && is_object($cb[0])
                    && get_class($cb[0])==='ElementorPro\\Modules\\DisplayConditions\\Module'
                    && $cb[1]==='before_element_render') $matches[]=$cb;
            }
            return $matches;
        };

        // Elementor Pro 4.x owns one generic before_render hook.
        // Elementor Pro 3.x owns four per-element hooks. Only wrap a
        // complete, unambiguous native registration topology.
        $general='elementor/frontend/before_render';
        $globalMatches=$findNative($general);
        $targets=[];
        if (count($globalMatches)===1) {
            foreach (['section','column','widget','container'] as $type) {
                if ($findNative('elementor/frontend/'.$type.'/before_render'))return;
            }
            $targets[$general]=$globalMatches[0];
        } elseif (count($globalMatches)!==0) {
            return;
        } else {
            $types=['section','column','widget','container'];
            foreach ($types as $type) {
                $tag='elementor/frontend/'.$type.'/before_render';
                $matches=$findNative($tag);
                if (count($matches)!==1) return;
                $targets[$tag]=$matches[0];
            }
            $owner=null;
            foreach ($targets as $native) {
                if ($owner===null)$owner=$native[0];
                elseif ($owner!==$native[0])return;
            }
        }
        // Other JetEngine, visibility and layout handlers stay attached
        // with their original order. Only replace Elementor Pro's handler.
        // Preflight every target before modifying any native callback.
        // A mixed or partly registered runtime must remain entirely native.
        $slots=[];
        foreach ($targets as $tag=>$native) {
            $hook=$wp_filter[$tag]??null;
            if (!($hook instanceof WP_Hook))return;
            $key=_wp_filter_build_unique_id($tag,$native,10);
            if (!isset($hook->callbacks[10][$key])
                || ($hook->callbacks[10][$key]['function']??null)!==$native)return;
            $slots[$tag]=$key;
        }
        foreach ($targets as $tag=>$native) {
            $handler=static function($element) use($native):void {
                $id=(is_object($element) && method_exists($element,'get_id'))?$element->get_id():null;
                if(is_string($id) && isset(self::$seen[$id]) && self::$seen[$id]===true){
                    if (($_SERVER['HTTP_X_MAD4B_CONDITION_AUDIT']??'')==='1') {
                        $start=hrtime(true);
                        $settings=method_exists($element,'get_settings_for_display')
                            ?$element->get_settings_for_display():null;
                        self::$stats['audit_settings_ns']+=hrtime(true)-$start;
                        self::$stats['audit_checked']++;
                        if (!is_array($settings)
                            || !empty($settings['e_display_conditions'])
                            || !empty($settings['display-conditions'])
                            || !empty($settings['__dynamic__'])) {
                            self::$stats['audit_mismatch']++;
                            self::$stats['native']++;
                            $native($element);
                            return;
                        }
                    }
                    self::$stats['skipped']++;return;
                }
                self::$stats['native']++;
                $native($element);
            };
            // WordPress WP_Hook callbacks are a keyed ordered array.
            // Change only the exact native handler function value at p10.
            $wp_filter[$tag]->callbacks[10][$slots[$tag]]['function']=$handler;
        }
        self::$installed=true;
        self::$stats['installed']=1;
        add_action('shutdown',static function():void {
            if(self::$installed &&
                (($_SERVER['HTTP_X_MAD4B_CONDITION_PROFILE']??'')==='1'
                || ($_SERVER['HTTP_X_MAD4B_CONDITION_GATE']??'')==='v1'))
                error_log('MAD4B_ELEMENTOR_CONDITION_GATE '.wp_json_encode(
                    ['host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
                    'language'=>(string)apply_filters('wpml_current_language','')]
                    +self::$stats));
        },9999);
    }
}
add_filter('elementor/frontend/builder_content_data',
    ['MAD4B_Elementor_Condition_Tree_Gate','discover'],PHP_INT_MAX,2);
add_action('wp',['MAD4B_Elementor_Condition_Tree_Gate','install'],20);
