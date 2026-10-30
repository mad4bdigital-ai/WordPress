<?php
/**
 * WordPress administrative REST smoke tests (Staging only).
 * Restores draft-options after testing; does not modify native SEO settings.
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('Run under WP-CLI');
$base=dirname(__DIR__);
foreach(array('class-dynamic-qualification.php','class-smart-seo-rules.php',
    'class-smart-seo-admin-rest.php') as $name) {
    $class=array(
      'class-dynamic-qualification.php'=>'DynamicQualification',
      'class-smart-seo-rules.php'=>'SmartSeoRules',
      'class-smart-seo-admin-rest.php'=>'SmartSeoAdminRest',
    )[$name];
    if(!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$class,false))
        require_once $base.'/includes/'.$name;
}
use MAD4B\DFSB\NativePresentation\SmartSeoRules as S;
use MAD4B\DFSB\NativePresentation\SmartSeoAdminRest as API;
$admin=get_users(array('role'=>'administrator','fields'=>'ID','number'=>1));
if(empty($admin)) throw new RuntimeException('No Staging administrator found');
$oldId=get_current_user_id();
$oldDrafts=get_option(S::OPTION,null);
$before=get_option('jet-smart-filters-settings',array());
$tests=array();
try {
    // REST is registered only after rest_api_init.
    rest_get_server();
    API::register();
    wp_set_current_user(0);
    $x=rest_do_request(new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/status'));
    $tests['anonymous_get_denied']=$x->get_status()===401 || $x->get_status()===403;
    $x=rest_do_request(new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/proposals/generate'));
    $tests['anonymous_generate_denied']=$x->get_status()===401 || $x->get_status()===403;
    wp_set_current_user((int)$admin[0]);
    $x=rest_do_request(new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/status'));
    $tests['admin_status_ok']=$x->get_status()===200 && ($x->get_data()['auto_indexing']??true)===false;
    $request=new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/discover');
    $request->set_param('probe',false);
    $x=rest_do_request($request);
    $data=$x->get_data();
    $tests['admin_discovery_ok']=$x->get_status()===200
        && ($data['groups_discovered']??0)>5
        && ($data['native_rules_created']??true)===false;
    $x=rest_do_request(new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/proposals/generate'));
    $data=$x->get_data();
    $tests['smart_generate_ok']=$x->get_status()===200
        && ($data['native_rules_modified']??true)===false;
    $tests['native_seo_options_unchanged']=$before===get_option('jet-smart-filters-settings',array());
    $x=rest_do_request(new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/proposals'));
    $tests['draft_listing_ok']=$x->get_status()===200 && is_array($x->get_data()['drafts']??null);
    $x=rest_do_request(new WP_REST_Request('POST','/mad4b/v1/seo-intelligence/proposals/'.str_repeat('0',40).'/stage'));
    $tests['unknown_draft_denied']=$x->get_status()===404;
    $tests['one_or_more_smart_drafts']=count($data['drafts']??array())>=1;
} finally {
    wp_set_current_user($oldId);
    if($oldDrafts===null) delete_option(S::OPTION);
    else update_option(S::OPTION,$oldDrafts,false);
}
foreach($tests as $name=>$pass) echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($tests)).'/'.count($tests).PHP_EOL;
if(in_array(false,$tests,true)) exit(1);
