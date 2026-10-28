<?php
namespace DynamicFilterSEOBridge\Context;

/** Diagnostic only. Hashes public scope, never stores/serves a cache entry. */
final class CacheScopeInspector {
    public const CONTRACT='dfsb.cache-scope-inspection.v1';
    public static function inspect(array $context,?string $requestUri=null): array {
        $kind=(string)($context['page_kind']??'unresolved');
        $url=(string)home_url('/');
        $lang=(string)($context['language']??get_locale());
        $currency=function_exists('get_woocommerce_currency')?
            (string)get_woocommerce_currency():'';
        $uri=$requestUri??(string)($_SERVER['REQUEST_URI']??'/');
        $path=(string)wp_parse_url($uri,PHP_URL_PATH);
        $query=(string)wp_parse_url($uri,PHP_URL_QUERY);
        $args=array();
        if($query!=='')parse_str($query,$args);
        $sensitiveKeys=array('nationality','adult','adults','children','infants',
            'rate','rate_id','pax','checkin','checkout','arrival','date',
            'rooms','room','currency','coupon','cart','session','token');
        $sensitive=false;
        foreach(array_keys($args) as $key){
            $key=strtolower((string)$key);
            foreach($sensitiveKeys as $protected){
                if($key===$protected || str_starts_with($key,$protected.'_')){$sensitive=true;break;}
            }
        }
        $faceted=str_contains($path,'/jsf/') || isset($args['jsf'])
            || isset($args['jet-smart-filters']);
        $blocked=in_array($kind,array('commerce_transaction','search_results','not_found',
            'author_archive','date_archive','unresolved'),true);
        $pagination=max(1,(int)($context['pagination_page']??1));
        // Hash the route rather than retaining URL query values or PII.
        $routeHash=hash('sha256',$path.'?'.$query);
        $fingerprint=hash('sha256',wp_json_encode(array(
            'tenant_url'=>$url,'blog_id'=>get_current_blog_id(),'lang'=>$lang,
            'currency'=>$currency,'kind'=>$kind,
            'post_id'=>(int)($context['post_id']??0),
            'term_id'=>(int)($context['term_id']??0),
            'pagination'=>$pagination,'route_hash'=>$routeHash,
        )));
        return array('contract'=>self::CONTRACT,'read_only'=>true,
            'scope_hash'=>$fingerprint,'request_hash'=>$routeHash,
            'site_blog_id'=>get_current_blog_id(),
            'language'=>$lang,'currency'=>$currency,'page_kind'=>$kind,
            'pagination'=>$pagination,'faceted'=>$faceted,
            'sensitive_request_fields'=>$sensitive,
            'shared_public_cache_eligible'=>!$blocked&&!$faceted&&!$sensitive&&$pagination===1,
            'cache_write_enabled'=>false,'authorizing'=>false,
            'native_cache_policy_unchanged'=>true);
    }
}
