<?php
namespace MAD4B\DFSB\NativePresentation;
if(!defined('ABSPATH')) { exit; }

/**
 * Single SEO owner: Rank Math hooks for explicitly approved exact filtered URLs.
 * No blanket JetSmartFilters sitemap rules or duplicate meta tags.
 */
final class SmartSeoExactPublisher {
    const INDEX_PREFIX='mad4b_seo_exact_url_v1_';
    public static function indexKey(string $url): string {
        $parts=wp_parse_url($url);
        $args=array();
        parse_str((string)($parts['query']??''),$args);
        if(!isset($args['jsf'],$args['tax']) || !is_string($args['jsf'])
            || !is_string($args['tax']))return self::INDEX_PREFIX.'invalid';
        return self::INDEX_PREFIX.hash('sha256',strtolower((string)($parts['host']??'')).
            '|'.(string)($parts['path']??'/').'|'.$args['jsf'].'|'.$args['tax']);
    }
    public static function resolve() {
        if(is_admin()||wp_doing_ajax() || !function_exists('rank_math')
            || !function_exists('jet_smart_filters')
            || jet_smart_filters()->settings->is_seo_enabled)return null;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        $parts=wp_parse_url($uri);$params=array();
        parse_str((string)($parts['query']??''),$params);
        if(count($params)!==2 || !isset($params['jsf'],$params['tax'])
            || !is_string($params['jsf']) || !is_string($params['tax']))return null;
        $candidate=home_url((string)($parts['path']??'/')).'?'
            .http_build_query($params,'','&',PHP_QUERY_RFC3986);
        $id=get_option(self::indexKey($candidate),'');
        if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/',$id))return null;
        $record=SmartSeoCombinations::get($id);
        if(is_wp_error($record)||($record['status']??'')!=='published'
            || ($record['url']??'')==='' || self::indexKey($record['url'])!==self::indexKey($candidate)
            || empty($record['approval']) || empty($record['seo_probe']['pass_prepublication']))
            return null;
        $locale=(string)apply_filters('wpml_current_language','en');
        if($locale!==$record['language'])return null;
        $policy=SmartSeoPolicyRegistry::effective($record['provider'],$record['query_id']);
        if(!$policy['enabled'])return null;
        $source=SmartSeoCombinations::source($record['provider'],$record['query_id']);
        if(is_wp_error($source))return null;
        $review=$record['locale_reviews'][$locale]??null;
        if(empty($review['manual_review_complete']))return null;
        return $record;
    }
    public static function title($old) {
        $item=self::resolve();
        return $item?$item['locale_reviews'][$item['language']]['title']:$old;
    }
    public static function description($old) {
        $item=self::resolve();
        return $item?$item['locale_reviews'][$item['language']]['description']:$old;
    }
    public static function canonical($old) {
        $item=self::resolve();
        return $item?$item['url']:$old;
    }
    public static function register(): void {
        add_filter('rank_math/frontend/title',array(__CLASS__,'title'),35,1);
        add_filter('rank_math/frontend/description',array(__CLASS__,'description'),35,1);
        add_filter('rank_math/frontend/canonical',array(__CLASS__,'canonical'),35,1);
    }
}
