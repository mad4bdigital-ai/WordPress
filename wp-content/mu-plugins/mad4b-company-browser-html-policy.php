<?php
/**
 * Plugin Name: MAD4B Company Public HTML Browser Cache Policy v1 (Company Scoped)
 * Description: Company-default public catalogue HTML caching, isolated from commerce/session state; original WordPress/WCML remains authoritative.
 * Version: 1.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Browser_HTML_Policy {
    public const POLICY='browser.html.cache';

    // Weekly content-change cadence. Browser lifetime stays bounded because
    // existing server purge cannot invalidate an already-cached browser copy.
    private const MAX_TTL=3600;
    private const DEFAULTS=[
        'home_ttl_seconds'=>900,           // 15 min
        'archive_ttl_seconds'=>900,        // 15 min
        'destination_ttl_seconds'=>3600,  // 60 min
        'tour_ttl_seconds'=>1800,          // 30 min
        'page_ttl_seconds'=>1800,          // 30 min
    ];
    public static function company_bound(): bool {
        return class_exists('MAD4B_Experience_Site_Scope_V1',false)
            && MAD4B_Experience_Site_Scope_V1::bound()
            && in_array(wp_get_environment_type(),
                ['local','development','staging','production'],true);
    }
    public static function enabled(): bool {
        // Preserve the existing Staging default. Production requires explicit
        // company-local opt-in after FastCGI and WooCommerce cache-key parity.
        $default=wp_get_environment_type()==='staging' ? 'on' : 'off';
        return self::company_bound() &&
            get_option('mad4b_browser_html_public_v1',$default)==='on';
    }
    // Backward compatible method name; the runtime is no longer site/staging
    // hardcoded. Call company_bound() in new integrations.
    public static function staging(): bool {
        return wp_get_environment_type()==='staging' && self::company_bound();
    }
    private static function canonical_host(): string {
        $home=get_option('home','');
        return is_string($home)?strtolower((string)wp_parse_url($home,PHP_URL_HOST)):'';
    }
    private static function wpml_languages(): array {
        $active=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        if(!is_array($active) || !$active)return [];
        return array_values(array_filter(array_map('strval',array_keys($active)),
            static fn($v)=>preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/iD',$v)));
    }
    public static function provider($providers): array {
        if (!is_array($providers)) $providers=[];
        $providers['browser-html-public']=[
            'label'=>'Browser HTML Cache — Public Pages',
            'policy_id'=>self::POLICY,
            'fields'=>[
                'mode'=>['type'=>'enum','options'=>[
                    'public_safe_default'=>'Cache eligible public HTML by default',
                    'off'=>'Disable public HTML browser caching',
                ]],
                'home_ttl_seconds'=>['type'=>'int','min'=>0,'max'=>self::MAX_TTL],
                'archive_ttl_seconds'=>['type'=>'int','min'=>0,'max'=>self::MAX_TTL],
                'destination_ttl_seconds'=>['type'=>'int','min'=>0,'max'=>self::MAX_TTL],
                'tour_ttl_seconds'=>['type'=>'int','min'=>0,'max'=>self::MAX_TTL],
                'page_ttl_seconds'=>['type'=>'int','min'=>0,'max'=>self::MAX_TTL],
                // Backward compatibility: older company-wide TTL rule.
                'ttl_seconds'=>['type'=>'int','min'=>0,'max'=>self::MAX_TTL],
            ],
            'allowed_scopes'=>['company'],
        ];
        return $providers;
    }
    private static function route_kind(): string {
        if (is_front_page())return 'home';
        if (is_post_type_archive('tours-and-activities'))return 'archive';
        if (is_tax('destinations'))return 'destination';
        if (is_tax(['tour-types','tour-styles']))return 'archive';
        if (is_singular('tours-and-activities'))return 'tour';
        return 'page';
    }
    public static function config(): array {
        $defaults=array_merge(['mode'=>'public_safe_default'],self::DEFAULTS);
        $values=[];
        if (class_exists('MAD4B_Company_Policy_Registry_V2',false)) {
            $decision=MAD4B_Company_Policy_Registry_V2::resolve(self::POLICY,[],$defaults);
            $values=is_array($decision)?($decision['values']??[]):[];
        }
        if (!is_array($values))$values=[];
        // Preserve explicit existing company-wide TTL unless granular rules
        // override. Do not reinterpret the old 90-second default as an override.
        if (isset($values['ttl_seconds'])) {
            foreach (self::DEFAULTS as $key=>$value)
                if (!isset($values[$key]))$defaults[$key]=$values['ttl_seconds'];
        }
        $defaults=array_replace($defaults,$values);
        $times=[];
        foreach (self::DEFAULTS as $key=>$fallback)
            $times[$key]=max(0,min(self::MAX_TTL,(int)$defaults[$key]));
        $kind=self::route_kind();
        return [
            'contract'=>'mad4b.company.browser-html-cache-policy.v1',
            'mode'=>$defaults['mode']==='off'?'off':'public_safe_default',
            'route_kind'=>$kind,
            'ttl_seconds'=>$times[$kind.'_ttl_seconds'],
            'route_ttls'=>$times,
            'max_ttl_seconds'=>self::MAX_TTL,
            'default_on'=>self::enabled(),
            'private_content_cached'=>false,
            'market_specific_html_cached'=>false,
            'production_mutation'=>false,
        ];
    }
    private static function canonical_public_path(): bool {
        $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        if ($path==='' || !str_starts_with($path,'/') || str_contains($path,'//')
            || preg_match('~/(?:cart|checkout|account|my-account|booking|reserve|order|order-pay|wp-json|wp-admin|wp-login|feed)(?:/|$)~i',$path))
            return false;
        $locale=(string)apply_filters('wpml_current_language','');
        $languages=self::wpml_languages();
        if (!in_array($locale,$languages,true)) return false;
        $host=self::canonical_host();
        if ($host==='')return false;
        $link='';
        if (is_front_page()) $link=(string)get_permalink((int)get_queried_object_id());
        elseif (is_post_type_archive('tours-and-activities')) $link=(string)get_post_type_archive_link('tours-and-activities');
        elseif (is_singular(['page','post','tours-and-activities'])) {
            $p=get_queried_object();
            if (!$p instanceof WP_Post || $p->post_status!=='publish'
                || post_password_required($p) || !is_post_publicly_viewable($p)) return false;
            $link=(string)get_permalink($p);
        } elseif (is_tax(['destinations','tour-types','tour-styles'])) {
            $term=get_queried_object();
            if (!$term instanceof WP_Term) return false;
            $url=get_term_link($term);
            $link=is_wp_error($url)?'':(string)$url;
        } else return false;
        if (!$link || strtolower((string)wp_parse_url($link,PHP_URL_HOST))!==$host
            || wp_parse_url($link,PHP_URL_QUERY)!==null) return false;
        $canonical=(string)wp_parse_url($link,PHP_URL_PATH);
        if ($canonical!==$path) return false;
        // The site's own WPML active/default languages are authoritative.
        // No hardcoded EN/AR/FR/... assumptions and no foreign locale cache.
        $default=(string)apply_filters('wpml_default_language','en');
        if (!in_array($default,$languages,true))return false;
        if ($locale!==$default)return str_starts_with($path,'/'.$locale.'/');
        foreach($languages as $language) {
            if ($language===$default)continue;
            if ($path==='/'.$language || str_starts_with($path,'/'.$language.'/'))
                return false;
        }
        return true;
    }
    public static function eligible(): bool {
        if (!self::enabled() || PHP_SAPI==='cli'
            || is_admin() || is_user_logged_in() || is_preview() || is_feed()
            || is_search() || is_404() || wp_doing_ajax()
            || (defined('REST_REQUEST')&&REST_REQUEST)
            || ($_SERVER['REQUEST_METHOD']??'')!=='GET') return false;
        // Strict first-visit anonymous shell. Presence of ANY cookie prevents
        // browser HTML cache; a later visitor-market cookie creates a new state.
        foreach (['HTTP_COOKIE','HTTP_AUTHORIZATION','PHP_AUTH_USER','HTTP_X_WP_NONCE',
            'HTTP_X_MAD4B_BYPASS_CACHE','HTTP_X_MAD4B_CURRENCY',
            'HTTP_X_GEO_COUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY',
            'HTTP_X_REQUESTED_WITH','HTTP_X_MAD4B_DYNAMIC_CSS',
            'HTTP_X_MAD4B_CSS_PRIORITY','HTTP_CF_IPCOUNTRY'] as $field) {
            if (!empty($_SERVER[$field])) return false;
        }
        if (!empty($_SERVER['QUERY_STRING']))return false;
        if (defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE)return false;
        if (function_exists('is_cart') && is_cart())return false;
        if (function_exists('is_checkout') && is_checkout())return false;
        if (function_exists('is_account_page') && is_account_page())return false;
        if (get_option('woocommerce_default_customer_address')!=='base'
            || get_option('woocommerce_currency')!=='USD')return false;
        if (function_exists('get_woocommerce_currency') &&
            get_woocommerce_currency()!=='USD')return false;
        return self::canonical_public_path();
    }
    public static function apply_headers(): void {
        if (headers_sent() || !self::eligible()) return;
        $cfg=self::config();
        if ($cfg['mode']!=='public_safe_default' || $cfg['ttl_seconds']<1) return;
        $headers=headers_list();
        $vary=[];
        $declaredPublic=false;
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:/i',$header))return;
            // Never replace explicit private/no-cache decisions. Other
            // WordPress plugins retain authority over their response.
            if (preg_match('/^Cache-Control:\s*(.*)$/i',$header,$parts)
                && preg_match('/(?:^|,)\s*(?:private|no-store|no-cache|max-age\s*=\s*0)(?:\b|,)/i',$parts[1]))return;
            if (preg_match('/^X-MAD4B-Cache-Policy:\s*(?:private-context|private-commerce)/i',$header))return;
            if (preg_match('/^X-MAD4B-Cache-Eligibility:\s*public-static-v1\s*$/i',$header))$declaredPublic=true;
            if (preg_match('/^Vary:\s*(.*)$/i',$header,$parts))
                foreach(explode(',',$parts[1]) as $v)$vary[strtolower(trim($v))]=true;
        }
        // Existing signed/validated public route owner has a more restrictive
        // front-page/archive eligibility gate; do not expand public cache to
        // routes with unverified Elementor/WCML/market dependencies yet.
        if (!$declaredPublic)return;
        // Cache lifecycle purges server URLs on content edits, while browser
        // copies remain TTL bounded; no live client purge is claimed.
        if (!isset($vary['cookie']))header('Vary: Cookie',false);
        if (!isset($vary['user-agent']))header('Vary: User-Agent',false);
        header('Cache-Control: public, max-age='.$cfg['ttl_seconds'].', must-revalidate',true);
        header('X-MAD4B-Browser-HTML: public-default-v1',true);
        header('X-MAD4B-Browser-HTML-TTL: '.$cfg['ttl_seconds'],true);
    }
    public static function boot(): void {
        add_filter('mad4b/policy_registry/providers',[self::class,'provider'],20);
        add_action('template_redirect',[self::class,'apply_headers'],9999);
    }
}
MAD4B_Company_Browser_HTML_Policy::boot();
