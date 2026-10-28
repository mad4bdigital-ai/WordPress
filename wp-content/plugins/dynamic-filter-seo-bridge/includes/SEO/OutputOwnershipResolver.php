<?php
namespace DynamicFilterSEOBridge\SEO;

/** Read-only evidence resolver; never changes external plugin output or SEO publication. */
final class OutputOwnershipResolver {
    const CONTRACT = 'etg.dfsb.seo-output-ownership.v1';
    public function resolve(array $route,array $evidence):array {
        $fields=array('title','description','canonical','robots','sitemap');
        $owners=array_fill_keys($fields,'unresolved');
        $blocking=array();
        $rule=(string)($evidence['jsf_seo_rule']??'unknown');
        $rank=(string)($evidence['rank_math_frontend']??'unknown');
        if(empty($route['active'])||empty($route['in_scope'])||!empty($route['ajax_only'])
            ||'ajax'===(string)($route['state_transport']??''))$blocking[]='route_not_public';
        if('matched'===$rule) {
            foreach(array('title','description','canonical')as$f)$owners[$f]='jet_smart_filters';
            if('disabled'!==$rank)$blocking[]='rank_math_integration_unresolved';
        }elseif('unmatched'===$rule&&'enabled'===$rank){
            foreach(array('title','description','canonical','robots')as$f)$owners[$f]='rank_math';
        }else $blocking[]='jsf_rank_math_rule_unobserved';
        foreach(array('robots','sitemap')as$f) {
            if('unresolved'!==$owners[$f])continue;
            $observed=(string)($evidence[$f.'_owner']??'');
            if(in_array($observed,array('jet_smart_filters','rank_math','wordpress_core','etg'),true))
                $owners[$f]=$observed;
        }
        $emissions=is_array($evidence['emissions']??null)?$evidence['emissions']:array();
        foreach($fields as$f){
            if('unresolved'===$owners[$f]){$blocking[]=$f.'_owner_unresolved';continue;}
            if('sitemap'===$f&&empty($route['index_eligible'])&&!empty($evidence['sitemap_excluded']))continue;
            $actual=is_array($emissions[$f]??null)?array_values($emissions[$f]):array();
            if(count($actual)!==1||$actual[0]!==$owners[$f])
                $blocking[]=$f.'_duplicate_missing_or_wrong_owner';
        }
        if(!empty($route['index_eligible'])){
            if(empty($evidence['robots_index_confirmed']))$blocking[]='robots_index_unverified';
            if(empty($evidence['sitemap_inclusion_verified']))$blocking[]='sitemap_inclusion_unverified';
        }elseif(empty($evidence['robots_noindex_confirmed']))$blocking[]='robots_noindex_unverified';
        foreach(array('direct_ajax_parity_verified','url_alias_parity_verified','wpml_hreflang_verified')as$key)
            if(true!==($evidence[$key]??false))$blocking[]=$key.'_missing';
        $blocking=array_values(array_unique($blocking));
        return array('contract'=>self::CONTRACT,'route_id'=>(string)($route['route_id']??''),
            'owners'=>$owners,'blocking_reasons'=>$blocking,'review_ready'=>empty($blocking),
            'authorizing'=>false,'seo_published'=>false,'production_authorized'=>false);
    }
}
