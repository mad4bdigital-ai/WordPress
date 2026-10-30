<?php
/**
 * Integration smoke test; execute only on an isolated/authorized WP-CLI lab
 * with the exact installed candidate package while SEO Full Boot is held.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "WP_CLI required\n");
    exit(2);
}
$plugin=dirname(__DIR__,2);
require_once $plugin.'/includes/Presentation/PresentationResolver.php';
require_once $plugin.'/includes/JetEngine/ListingIntegration.php';
require_once $plugin.'/includes/Runtime/RuntimeLayers.php';
require_once $plugin.'/includes/Runtime/AdvancedPresentationRuntime.php';

use DynamicFilterSEOBridge\Runtime\RuntimeLayers;
use DynamicFilterSEOBridge\Runtime\BootGuard;
use DynamicFilterSEOBridge\Runtime\AdvancedPresentationRuntime;
use DynamicFilterSEOBridge\Elementor\DynamicTags\DynamicTagRuntime;

$seoHooks=array('rank_math/frontend/title','rank_math/frontend/robots',
                'rank_math/json_ld','rank_math/opengraph/facebook/og_title');
$counters=static function() use($seoHooks):array {
    global $wp_filter;
    $out=array();
    foreach($seoHooks as $name){
        $hook=$wp_filter[$name]??null;
        $out[$name]=$hook instanceof \WP_Hook
            ?array_map('count',$hook->callbacks):array();
    }
    return $out;
};
$initial=BootGuard::status();
if(empty($initial['effective_hold'])) {
    fwrite(STDERR,"SKIP: SEO Full Boot not held; no need for independent runtime\n");
    exit(0);
}
$writes=array();
$watch=static function($query)use(&$writes) {
    if(preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i',$query)){
        $writes[]=$query;
    }
    return $query;
};
add_filter('query',$watch,999);
$before=$counters();
$initialized=AdvancedPresentationRuntime::registerWhenHeld();
$duplicate=AdvancedPresentationRuntime::registerWhenHeld();
if(class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->dynamic_tags)) {
    do_action('elementor/dynamic_tags/register',\Elementor\Plugin::$instance->dynamic_tags);
}
$after=$counters();
$registeredTags=array_keys((array)\Elementor\Plugin::$instance->dynamic_tags->get_tags());
$advancedTags=array_values(array_filter($registeredTags,static fn($name)=>str_starts_with((string)$name,'etg-')||str_starts_with((string)$name,'dfsb-')));
echo 'TAG_NAMES='.wp_json_encode($advancedTags).PHP_EOL;
$listingHook=$GLOBALS['wp_filter']['jet-engine/listings/dynamic-repeater/pre-get-saved']??null;
$listingRegistered=false;
if($listingHook instanceof \WP_Hook){
    foreach($listingHook->callbacks as $callbacks){
        foreach($callbacks as $cb){
            $fn=$cb['function']??null;
            if(is_array($fn) && isset($fn[0]) && $fn[0] instanceof \DynamicFilterSEOBridge\JetEngine\ListingIntegration){
                $listingRegistered=true;
            }
        }
    }
}
$resolved=DynamicTagRuntime::resolver();
$context=$resolved?$resolved->context():array();
$metaReadCalls=0;
$readProbe=static function($value)use(&$metaReadCalls){$metaReadCalls++;return $value;};
add_filter('get_term_metadata',$readProbe,999);
$metaResult='not_tested';
if($resolved){
    $method=new \ReflectionMethod($resolved,'termMeta');
    $metaResult=$method->invoke($resolved,
        array('terms'=>array('location'=>array('term_id'=>1))),
        'location','private_auth_token');
}
remove_filter('get_term_metadata',$readProbe,999);

remove_filter('query',$watch,999);
$final=BootGuard::status();
$checks=array(
    'source_verified'=>RuntimeLayers::canPresent(),
    'advanced_registered'=>$initialized===true || (count($advancedTags)>=13 && $listingRegistered && $resolved!==null),
    'idempotent'=>$duplicate===false,
    'dynamic_resolver_connected'=>$resolved!==null,
 'advanced_tag_names_registered'=>count($advancedTags)>=13 && in_array('etg-filter-title',$advancedTags,true) && in_array('etg-filter-image',$advancedTags,true),
 'jetengine_listing_hook_registered'=>$listingRegistered,
 'unapproved_query_sources_disabled'=>$resolved!==null && $resolved->sourceCatalog()===array(),
 'direct_term_meta_blocked'=>$metaResult==='' && $metaReadCalls===0,
    'no_unverified_context'=>empty($context['active'])
        ?($resolved->value('title')===''):true,
    'no_seo_hook_changes'=>$before===$after,
    'seo_stays_held'=>!empty($final['effective_hold']),
    'no_seo_full_boot_event'=>!did_action('etg_filter_seo_bridge_booted'),
    'no_database_mutation'=>count($writes)===0,
);
foreach($checks as $name=>$pass){
    echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;
}
if(in_array(false,$checks,true))exit(1);

