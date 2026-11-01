<?php
/** Read-only policy validation, source registry and conflict safety on Staging. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI only');
$root=dirname(__DIR__);
foreach(array(
 'class-dynamic-qualification.php'=>'DynamicQualification',
 'class-smart-seo-policy-registry.php'=>'SmartSeoPolicyRegistry',
 'class-smart-seo-native-store.php'=>'SmartSeoNativeStore',
 'class-smart-seo-rules.php'=>'SmartSeoRules',
 'class-smart-seo-admin-rest.php'=>'SmartSeoAdminRest'
) as $file=>$name) {
    if (!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$name,false))
        require_once $root.'/includes/'.$file;
}
use MAD4B\DFSB\NativePresentation\SmartSeoPolicyRegistry as Registry;
use MAD4B\DFSB\NativePresentation\SmartSeoNativeStore as Native;
use MAD4B\DFSB\NativePresentation\SmartSeoRules as Smart;
use MAD4B\DFSB\NativePresentation\SmartSeoAdminRest as REST;
$beforePolicy=get_option(Registry::OPTION,null);
$nativeBefore=get_option('jet-smart-filters-settings',array());
$checks=array();
$registry=Registry::discovered();
$checks['dynamic_registry_sources']=count($registry['sources'])>=10
    && isset($registry['sources']['jet-engine/tours_query_archive']);
$checks['registry_scoped_current_site']=$registry['blog_id']===get_current_blog_id()
    && $registry['site']===home_url('/');
$checks['mu_owner_binding_present']=!empty($registry['sources']['jet-engine/tours_query_archive']['mu_binding']['matched']);
$checks['company_tour_only_not_indexable']=!empty($registry['sources']['jet-engine/property_query_archive']['company'])
    && $registry['sources']['jet-engine/property_query_archive']['company']['standalone_allowed']===false;
$policy=Registry::validate(array('global'=>array(
    'max_filters'=>2,'max_combinations'=>100,'require_result_evidence'=>true,
    'require_localized_copy'=>true,'allow_auto_indexing'=>false),
    'sources'=>array('jet-engine/tours_query_archive'=>array(
        'enabled'=>true,'max_filters'=>1,'allowed_filter_ids'=>array(16204),
        'weight_overrides'=>array('16204'=>90),
        'templates'=>array('en'=>array(
            'title'=>'{primary} {collection} | {site}',
            'description'=>'Explore {collection} in {primary}.')))),
));
$checks['strict_valid_configuration']=is_array($policy)
    && $policy['sources']['jet-engine/tours_query_archive']['max_filters']===1;
$checks['unsafe_autoindex_rejected']=is_wp_error(Registry::validate(array('global'=>array('allow_auto_indexing'=>true))));
$checks['oversized_combination_cap_rejected']=is_wp_error(Registry::validate(array('global'=>array('max_combinations'=>25000))));
$checks['script_template_rejected']=is_wp_error(Registry::validate(array('sources'=>array(
    'jet-engine/tours_query_archive'=>array('templates'=>array(
    'en'=>array('title'=>'<script>alert(1)</script>')))))));
$checks['unknown_policy_field_rejected']=is_wp_error(Registry::validate(array('sources'=>array('jet-engine/tours_query_archive'=>array('compliance_token'=>'bypass')))));
$checks['render_template_uses_data']=Registry::formatTemplate(
    '{primary} {collection} | {site}',
    array('primary'=>'{{filter_16204}}','collection'=>'Tours','site'=>'Wo Vacation'),'')
    ==='{{filter_16204}} Tours | Wo Vacation';
$checks['actual_policy_does_not_authorize_index']=Registry::effective('jet-engine','tours_query_archive')['allow_auto_indexing']===false;
$checks['native_rules_present']=count((array)($nativeBefore['seo_sitemap_rules']??array()))>=1;
$checks['native_duplicate_rejected_without_write']=is_wp_error(Native::appendDisabled((array)($nativeBefore['seo_sitemap_rules'][0]??array())));
$checks['native_rules_preserved']=$nativeBefore===get_option('jet-smart-filters-settings',array());
$report=Smart::recommend(false);
$checks['every_group_has_independent_scores']=array_reduce($report['recommendations'],
    static fn($ok,$r)=>$ok && array_key_exists('technical_score',$r)
     && array_key_exists('content_score',$r)
     && array_key_exists('seo_score',$r)
     && $r['index_ready']===false,true);
$checks['policy_option_unchanged']=$beforePolicy===get_option(Registry::OPTION,null);
rest_get_server();REST::register();
wp_set_current_user(0);
$req=rest_do_request(new WP_REST_Request('PUT','/mad4b/v1/seo-intelligence/policies'));
$checks['anonymous_policy_write_denied']=in_array($req->get_status(),array(401,403),true);
foreach ($checks as $name=>$ok) echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
if (in_array(false,$checks,true)) exit(1);
