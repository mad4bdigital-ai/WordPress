<?php
/** Reversible Staging-only native-rule staging test, never enables SEO. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI required');
$base=dirname(__DIR__);
foreach(array('class-dynamic-qualification.php'=>'DynamicQualification',
              'class-smart-seo-rules.php'=>'SmartSeoRules',
              'class-smart-seo-admin-rest.php'=>'SmartSeoAdminRest') as $file=>$class) {
    if (!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$class,false))
        require_once $base.'/includes/'.$file;
}
use MAD4B\DFSB\NativePresentation\SmartSeoRules as S;
use MAD4B\DFSB\NativePresentation\SmartSeoAdminRest as API;
$admins=get_users(array('role'=>'administrator','fields'=>'ID','number'=>1));
if (!$admins) throw new RuntimeException('No admin');
if (filter_var(jet_smart_filters()->settings->get('use_seo_sitemap',false),FILTER_VALIDATE_BOOLEAN))
    throw new RuntimeException('Native SEO already enabled. Test will not stage.');
if (count((array)jet_smart_filters()->settings->get('seo_sitemap_rules',array()))>0) {
    echo "SKIP: existing native SEO rules; run the isolated CAS-option test instead.\\n";
    return;
}
$before=get_option('jet-smart-filters-settings',array());
$backup=get_option('mad4b_jsf_smart_seo_native_backup_v1',null);
$drafts=get_option(S::OPTION,null);
$oldUser=get_current_user_id();
$checks=array();
try {
    rest_get_server(); API::register();
    wp_set_current_user((int)$admins[0]);
    $generate=rest_do_request(new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/proposals/generate'));
    $data=$generate->get_data();
    $checks['qualified_smart_generation']=$generate->get_status()===200 && ($data['qualified']??0)>=1;
    $id=(string)($data['drafts'][0]['id']??'');
    $checks['valid_draft_id']=(bool)preg_match('/^[a-f0-9]{40}$/',$id);
    if ($checks['valid_draft_id']) {
        $stage=rest_do_request(new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/proposals/'.$id.'/stage'));
        $staged=$stage->get_data();
        $checks['native_rule_staged_disabled']=$stage->get_status()===200 &&
            ($staged['status']??'')==='staged_disabled';
        $settings=get_option('jet-smart-filters-settings',array());
        $native=(array)($settings['seo_sitemap_rules']??array());
        $checks['native_has_draft_rule']=count($native)===count((array)($before['seo_sitemap_rules']??array()))+1;
        $checks['native_feature_still_disabled']=!filter_var($settings['use_seo_sitemap']??false,FILTER_VALIDATE_BOOLEAN);
        $again=rest_do_request(new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/proposals/'.$id.'/stage'));
        $checks['duplicate_stage_rejected']=$again->get_status()===404;
    }
} finally {
    // Guaranteed cleanup even when a test or REST callback fails.
    update_option('jet-smart-filters-settings',$before,false);
    if($drafts===null) delete_option(S::OPTION); else update_option(S::OPTION,$drafts,false);
    if($backup===null) delete_option('mad4b_jsf_smart_seo_native_backup_v1');
    else update_option('mad4b_jsf_smart_seo_native_backup_v1',$backup,false);
    wp_set_current_user($oldUser);
}
$checks['native_settings_restored']=$before===get_option('jet-smart-filters-settings',array());
foreach($checks as $k=>$v) echo ($v?'PASS ':'FAIL ').$k.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
if (in_array(false,$checks,true)) exit(1);
