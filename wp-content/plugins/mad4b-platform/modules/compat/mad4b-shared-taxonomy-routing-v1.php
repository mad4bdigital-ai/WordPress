<?php
/**
 * Plugin Name: MAD4B Shared Taxonomy Routing v1
 * Description: Policy-scoped JetEngine taxonomy permalinks, WPML cache & canonical routing.
 *
 * Opt-in, no changes to taxonomy registration by this runtime. JetEngine's
 * persisted taxonomy rewrite settings are the source of truth.
 */
defined('ABSPATH') || exit;

function mad4b_shared_taxonomy_routing_policy_v1(): array {
    $policy=get_option('mad4b_shared_taxonomy_routing_v1',[]);
    if (!is_array($policy) || ($policy['version']??null)!==1 || empty($policy['enabled'])
        || !is_array($policy['managed']??null)) return [];
    return array_values(array_filter(array_unique(array_map('sanitize_key',$policy['managed']))));
}
function mad4b_shared_taxonomy_is_managed_v1(string $name): bool {
    if (!in_array($name,mad4b_shared_taxonomy_routing_policy_v1(),true)) return false;
    $tax=get_taxonomy($name);
    return $tax instanceof WP_Taxonomy && $tax->public && $tax->publicly_queryable
        && is_array($tax->rewrite) && !empty($tax->rewrite['slug']);
}
/**
 * Resolve term IDs (not term_taxonomy IDs) for the same WPML term family.
 * Keep the selected term even when the WPML table has incomplete translations.
 */
function mad4b_shared_taxonomy_wpml_term_ids_v1(string $name,int $term_id):array {
    $ids=$term_id>0?[$term_id=>$term_id]:[];
    $term=$term_id>0?get_term($term_id,$name):null;
    if (!$term instanceof WP_Term)return array_values($ids);
    $trid=(int)apply_filters('wpml_element_trid',null,(int)$term->term_taxonomy_id,'tax_'.$name);
    if (!$trid)return array_values($ids);
    $translations=apply_filters('wpml_get_element_translations',[],$trid,'tax_'.$name);
    if(!is_array($translations) || count($translations)>12)return array_values($ids);
    global $wpdb;
    foreach($translations as $row) {
        $ttid=is_object($row)?absint($row->element_id??0):0;
        if(!$ttid)continue;
        $id=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id=%d AND taxonomy=%s LIMIT 1",
            $ttid,$name));
        if($id>0)$ids[$id]=$id;
    }
    return array_values($ids);
}
function mad4b_shared_taxonomy_wpml_permalink_invalidate_v1(string $name,int $term_id=0): void {
    if (!mad4b_shared_taxonomy_is_managed_v1($name)) return;
    // WPML stores the term permalink filters in a TTL-less persistent cache.
    if (class_exists('WPML_WP_Cache',false)) {
        (new WPML_WP_Cache('icl_tax_permalink_filter'))->flush_group_cache(true);
    }
    if (function_exists('mad4b_render_query_invalidate')) {
        mad4b_render_query_invalidate(['kind'=>'taxonomy_links','taxonomy'=>$name,'term_id'=>$term_id]);
    }
    // Target the actual term dependency instead of purging every FastCGI page.
    if ($term_id>0 && class_exists('MAD4B_Page_Dependency_Cache',false)
        && MAD4B_Page_Dependency_Cache::ready()) {
        $term_ids=mad4b_shared_taxonomy_wpml_term_ids_v1($name,$term_id);
        $deps=array_map(static fn($id)=>'term:'.$name.':'.$id,$term_ids);
        MAD4B_Page_Dependency_Cache::invalidate($deps,'taxonomy_route_term');
    }
    do_action('mad4b/shared_taxonomy_routing/changed',$name,$term_id);
}
foreach (['created_term','edited_term','delete_term'] as $hook) {
    add_action($hook,static function($term_id,$term_taxonomy_id,$taxonomy):void {
        if (is_string($taxonomy)) {
            mad4b_shared_taxonomy_wpml_permalink_invalidate_v1($taxonomy,(int)$term_id);
        }
    },60,3);
}
// An external sync or reconciler can explicitly request invalidation without a term edit.
add_action('mad4b/shared_taxonomy_routing/invalidate',static function($name,$term_id=0):void {
    if(is_string($name))mad4b_shared_taxonomy_wpml_permalink_invalidate_v1($name,(int)$term_id);
},20,2);

// The WPML/JetEngine native rewrite rules handle ordinary term pages.
// Add only missing JSF suffix routes, in the same format as the existing
// destination-specific bridge. This does not widen query visibility.
add_filter('rewrite_rules_array',static function($rules) {
    $add=[];
    foreach(mad4b_shared_taxonomy_routing_policy_v1() as $name) {
        if(!mad4b_shared_taxonomy_is_managed_v1($name))continue;
        $tax=get_taxonomy($name);
        if (!empty($tax->rewrite['hierarchical']))continue; // no flat route assumption
        $base=trim((string)$tax->rewrite['slug'],'/');
        if ($base==='' || !preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~D',$base))continue;
        $pattern=$base; // strict allowlist regex above makes this safe without escaped hyphens
        $rule=$pattern.'/([^/]+)/jsf/(.+?)/?$';
        // Do not displace an existing rule from WPML or another provider.
        if (!isset($rules[$rule])) {
            $add[$rule]='index.php?taxonomy='.$name.'&term=$matches[1]&jsf=$matches[2]';
        }
    }
    return $add+$rules;
},2000);

// Refuse redirect_to_self where WPML yields the exact same Arabic URL
// with different percent encoding. Leave all other canonical redirects alone.
add_filter('redirect_canonical',static function($target,$requested) {
    if (!is_string($target) || !is_string($requested) || !is_tax())return $target;
    $term=get_queried_object();
    if (!$term instanceof WP_Term || !mad4b_shared_taxonomy_is_managed_v1($term->taxonomy))return $target;
    $t=wp_parse_url($target);$r=wp_parse_url($requested);
    if (!is_array($t)||!is_array($r))return $target;
    foreach(['host','scheme','port'] as $key) {
        if ((string)($t[$key]??'')!==(string)($r[$key]??''))return $target;
    }
    if (rawurldecode((string)($t['path']??''))===rawurldecode((string)($r['path']??''))
        && (string)($t['query']??'')===(string)($r['query']??'')) return false;
    return $target;
},1100,2);

// Only simple legacy taxonomy queries receive 301 redirects. Dynamic facets,
// language/currency query parameters, REST and signed AJAX remain untouched.
add_action('template_redirect',static function():void {
    if(is_admin() || wp_doing_ajax() || is_preview() || !is_tax()
       || !in_array(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')),['GET','HEAD'],true)
       || count($_GET)!==1)return;
    $term=get_queried_object();
    if(!$term instanceof WP_Term || !mad4b_shared_taxonomy_is_managed_v1($term->taxonomy))return;
    $parameter=$term->taxonomy;
    $value=$_GET[$parameter]??null;
    if(!is_string($value)||$value!==$term->slug)return;
    $url=get_term_link($term);
    if(is_wp_error($url)||!is_string($url)||!wp_http_validate_url($url))return;
    $current=home_url(wp_unslash((string)($_SERVER['REQUEST_URI']??'/')));
    if(rawurldecode($current)===rawurldecode($url))return;
    if(wp_parse_url($url,PHP_URL_HOST)!==wp_parse_url(home_url('/'),PHP_URL_HOST))return;
    wp_safe_redirect($url,301,'MAD4B-Shared-Taxonomy-Routing');exit;
},9);
