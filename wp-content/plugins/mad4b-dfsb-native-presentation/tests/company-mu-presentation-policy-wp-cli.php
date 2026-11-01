<?php
/**
 * Site-scoped MU Presentation and Shared Product Core integration QA.
 * Never modifies service-activation, JSF native rules or source products.
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI required');
if (!class_exists('MAD4B\\DFSB\\NativePresentation\\DynamicQualification'))
    require_once dirname(__DIR__).'/includes/class-dynamic-qualification.php';
use MAD4B\DFSB\NativePresentation\DynamicQualification as D;
$checks=array();
$checks['service_policy_functions_loaded']=function_exists('service_channel_mode')
    && function_exists('service_channel_allowed')
    && function_exists('service_channel_owner');
$checks['mu_registry_function_loaded']=function_exists('shared_presentation_registry_v2')
    && function_exists('product_navigation_registry');
$before=get_option('service-activation',array());
$native=get_option('jet-smart-filters-settings',array());
foreach(array('tours'=>'tours-and-activities',
              'properties'=>'properties','transportations'=>'transportations') as $owner=>$postType) {
    $p=D::companyPolicy($postType);
    $checks[$owner.'_owner_exact']=($p['owner']??null)===$owner;
    $checks[$owner.'_mode_matches_mu']=($p['mode']??null)===service_channel_mode($owner);
    $checks[$owner.'_channels_match_mu']=($p['standalone_allowed']??null)===service_channel_allowed($owner,'standalone')
        && ($p['tour_component_allowed']??null)===service_channel_allowed($owner,'tour_component');
    $checks[$owner.'_current_site_scope']=($p['site_host']??'')===parse_url(home_url('/'),PHP_URL_HOST)
        && ($p['blog_id']??-1)===get_current_blog_id();
}
$r=D::muRegistryBinding('jet-engine','tours_query_archive','tours-and-activities');
$checks['mu_tours_registry_reused']=!empty($r['matched'])
    && count((array)($r['entity_mismatch']??array()))===0
    && in_array('tours.archive',array_column((array)$r['slots'],'slot'),true);
$report=D::scan(true);
$byId=array_column($report['groups'],null,'query_id');
foreach(array('tours'=>'tours_query_archive','properties'=>'property_query_archive',
              'transportations'=>'trans_query_archive') as $owner=>$queryId) {
    $policy=D::companyPolicy(array('tours'=>'tours-and-activities',
         'properties'=>'properties','transportations'=>'transportations')[$owner]);
    $group=$byId[$queryId]??null;
    $checks[$owner.'_group_discovered']=is_array($group);
    if (!$group) continue;
    $checks[$owner.'_company_policy_readable']=($group['company_policy']['owner']??null)===$owner;
    if ($policy['standalone_allowed']===false) {
        $checks[$owner.'_no_unauthorized_indexing']=empty($group['indexing_approved'])
            && ($group['proposed_rule']??null)===null
            && in_array($group['status'],array('company_component_only','company_service_disabled'),true)
            && ($group['route_http']??null)===null;
    }
}
$checks['service_activation_unchanged']=$before===get_option('service-activation',array());
$checks['native_jsf_settings_unchanged']=$native===get_option('jet-smart-filters-settings',array());
foreach($checks as $k=>$ok) echo ($ok?'PASS ':'FAIL ').$k.PHP_EOL;
echo 'CHECKS='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
echo 'STATES='.wp_json_encode($report['states']).PHP_EOL;
if(in_array(false,$checks,true)) exit(1);
