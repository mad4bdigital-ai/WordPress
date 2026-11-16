<?php
/**
 * Plugin Name: MAD4B Canonical Public Page Origin Cache Gate
 * Description: Opt in already verified, non-personalized public pages to Nginx HTML cache.
 * Version: 1.1.1
 */
defined('ABSPATH') || exit;
final class MAD4B_General_Public_Page_Origin_Cache {
    public static function ready(): bool {
        // The registered company/site profile, not a baked-in host or
        // environment string, owns admission. Unknown deployments fail closed.
        // Browser policy proves the current WPML route and WCML base-currency
        // context separately, while Nginx retains the market isolation HOLD.
        if (!class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound()
            || !class_exists('MAD4B_Company_Browser_HTML_Policy',false)
            || !MAD4B_Company_Browser_HTML_Policy::company_bound()
            || !MAD4B_Company_Browser_HTML_Policy::enabled()
            || !MAD4B_Company_Browser_HTML_Policy::eligible()) return false;
        // A public tour detail displays stable stored, seasonal base rates.
        // Treat it as cacheable content, not a live quote/checkout endpoint.
        if ((function_exists('is_woocommerce') && is_woocommerce()
                && !is_singular('tours-and-activities'))
            || is_search() || is_404() || is_feed() || is_trackback()
            || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url())) return false;
        if (is_page()) {
            $id=(int)get_queried_object_id();
            foreach(['woocommerce_cart_page_id','woocommerce_checkout_page_id',
                'woocommerce_myaccount_page_id','woocommerce_terms_page_id'] as $option){
                $private=(int)get_option($option,0);
                if ($private && ($id===$private
                    || ($id===(int)apply_filters('wpml_object_id',$private,'page',true)))) return false;
            }
            $slug=strtolower((string)get_post_field('post_name',$id));
            if (preg_match('~(?:cart|checkout|account|login|register|payment|order|booking|reservation|reserve|enquiry|inquiry|contact|plan-a-trip|planifica|cuenta|pago|registro|customer|privacy-request)~',$slug))return false;
        }
        foreach (headers_list() as $h){
            if (preg_match('/^Set-Cookie:/i',$h)
                || preg_match('/^Cache-Control:\s*(.*)$/i',$h,$m)
                   && preg_match('/(?:private|no-store|no-cache|max-age\s*=\s*0)/i',$m[1])) return false;
        }
        return true;
    }
    public static function opt_in():void {
        // Canary requires two deliberate diagnostic headers. Never allow
        // public cache storage for diagnostic requests.
        $mode=(string)get_option('mad4b_public_page_origin_cache_v1','canary');
        if ($mode==='off' || !self::ready())return;
        if ($mode==='canary') {
            if (($_SERVER['HTTP_X_MAD4B_PUBLIC_PAGE_AUDIT']??'')!=='1'
                || ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')!=='1')return;
            header('X-MAD4B-Public-Page-Audit: eligible-v1',true);
            return;
        }
        if ($mode!=='on')return;
        header('X-MAD4B-Cache-Eligibility: public-static-v1',true);
        // Cache freshness is set by Nginx; the browser-policy plugin retains
        // per-route TTL and Vary handling. Not a shared cache policy for tours.
    }
}
add_action('template_redirect',['MAD4B_General_Public_Page_Origin_Cache','opt_in'],9000);
