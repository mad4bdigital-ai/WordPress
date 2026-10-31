<?php
if (!defined('ABSPATH')) define('ABSPATH','/tmp/mad4b-test/');
class WP_Error {
    private $code;
    public function __construct($code,$message='') { $this->code=$code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
$GLOBALS['environment']='staging';
$GLOBALS['wp_environment']='staging';
function wp_get_environment_type() { return $GLOBALS['wp_environment']; }
$GLOBALS['remote_calls']=0;
$GLOBALS['remote_result']=array('sha'=>str_repeat('a',40));
class MAD4B_SCP_Site_Profile {
    public static function origin_enrolled() { return true; }
    public static function current_environment() { return $GLOBALS['environment']; }
}
function get_plugins() { return array(); }
function wp_safe_remote_get($url,$opts) {
    ++$GLOBALS['remote_calls'];
    if (0!==strpos($url,'https://api.github.com/repos/mad4bdigital-ai/WordPress/'))
        return new WP_Error('github_origin_unexpected');
    if (($opts['redirection']??-1)!==0 || ($opts['limit_response_size']??0)>65536)
        return new WP_Error('unsafe_http_options');
    return array('code'=>200,'body'=>json_encode($GLOBALS['remote_result']));
}
function wp_remote_retrieve_response_code($x) { return $x['code']; }
function wp_remote_retrieve_body($x) { return $x['body']; }
require_once dirname(__DIR__).'/includes/class-mad4b-scp-cross-plugin-deployment.php';
function check($yes,$reason) {
    if (!$yes) { fwrite(STDERR,"CROSS_PLUGIN_FAIL: ".$reason."\n"); exit(1); }
}
$cls='MAD4B_SCP_Cross_Plugin_Deployment';
$catalog=$cls::discover();
$registered=array();
foreach ($catalog['items'] as $record) {
    check(!isset($registered[$record['source_id']]),'duplicate source ID in discovery');
    $registered[$record['source_id']]=$record;
}
check($catalog['count']>=2 && $catalog['count']===count($registered)
    && !$catalog['mutation_performed'],'source registry inventory');
check($GLOBALS['remote_calls']===0,'passive discover triggers no GitHub request');
check(isset($registered['etg-dynamic-filter-seo-bridge'])
    && isset($registered['dynamic-filter-seo-bridge']),'both legacy and neutral source visible');
foreach (array('etg-dynamic-filter-seo-bridge','dynamic-filter-seo-bridge') as $sourceId) {
    check($registered[$sourceId]['repository']==='mad4bdigital-ai/WordPress',
        'registered same-repository source missing: '.$sourceId);
    check($registered[$sourceId]['package_certification']==='pending'
        && $registered[$sourceId]['review_state']==='source_reviewed_artifact_pending',
        'candidate must not promote package certification: '.$sourceId);
    check($registered[$sourceId]['installed']===false,'not-installed source detected: '.$sourceId);
}
check($registered['etg-dynamic-filter-seo-bridge']['plugin_file']
    !==$registered['dynamic-filter-seo-bridge']['plugin_file'],
    'legacy and neutral plugin files must remain distinct');
$sha=str_repeat('a',40);
$input=array('source_id'=>'etg-dynamic-filter-seo-bridge','ref_type'=>'commit','reference'=>$sha);
check(is_wp_error($cls::selection(array('source_id'=>'unknown','ref_type'=>'commit','reference'=>$sha))),'unknown repository admitted');
check(is_wp_error($cls::selection(array_merge($input,array('reference'=>'HEAD')))),'mutable non-sha commit admitted');
check(is_wp_error($cls::selection(array_merge($input,array('ref_type'=>'branch','reference'=>'../escape')))),'branch traversal admitted');
check(is_wp_error($cls::selection(array_merge($input,array('ref_type'=>'branch','reference'=>'a//b')))),'double slash branch admitted');
$plan=$cls::plan($input);
check(is_array($plan) && $plan['operation']==='install' && $plan['resolved_sha']===$sha,
    'new plugin install planning missing');
check(!$plan['eligible'] && empty($plan['native_executor'])
    && $plan['package_certification_required']===false
    && $plan['source_trust_mode']==='owner_registered_immutable_git_commit'
    && !in_array('exact_zip_artifact_not_certified',$plan['blockers'],true)
    && !in_array('source_review_promotion_required',$plan['blockers'],true),
    'Owner-registered private Git source must not require a package certificate');
check(in_array('managed_private_source_installer_not_registered', $plan['blockers'], true),
    'Missing private-source native installer must remain an explicit blocker');
check(!$plan['mutation_performed'] && !$plan['wpcron_auto_execute'],
    'read-only planning claimed execution');
$branch_input=$input;
$branch_input['ref_type']='branch';
$branch_input['reference']='main';
$GLOBALS['remote_result']=array('commit'=>array('sha'=>$sha));
$branch_plan=$cls::plan($branch_input);
check(!$branch_plan['eligible'] && in_array('immutable_private_source_commit_required',$branch_plan['blockers'],true),
    'mutable branch must not qualify as an owner-managed private deployment source');
$GLOBALS['remote_result']=array('sha'=>$sha);
$neutral=array('source_id'=>'dynamic-filter-seo-bridge',
    'ref_type'=>'commit','reference'=>$sha);
$neutral_plan=$cls::plan($neutral);
check(is_array($neutral_plan) && $neutral_plan['operation']==='install'
    && $neutral_plan['plugin_file']==='dynamic-filter-seo-bridge/dynamic-filter-seo-bridge.php',
    'neutral source should be independently selectable');
check(!$neutral_plan['eligible'] && empty($neutral_plan['native_executor'])
    && $neutral_plan['package_certification_required']===false
    && in_array('managed_private_source_installer_not_registered',$neutral_plan['blockers'],true)
    && !in_array('exact_zip_artifact_not_certified',$neutral_plan['blockers'],true),
    'registered MAD4B private source must use native installer rather than certificate');
check(!$neutral_plan['mutation_performed'] && !$neutral_plan['wpcron_auto_execute'],
    'neutral plan requested automatic mutation');
// Exact commit planning intentionally verifies the pinned commit via safe GitHub read.

$GLOBALS['wp_environment']='production';
$env_mismatch=$cls::plan($input);
check(!$env_mismatch['eligible'] && empty($env_mismatch['native_executor'])
    && in_array('environment_not_registered_for_source',$env_mismatch['blockers'],true),
    'MCP planning trusted a Staging profile while WordPress reported Production');
$GLOBALS['environment']='production';
$prod=$cls::plan($input);
check(!$prod['eligible'] && in_array('production_private_source_executor_not_registered',$prod['blockers'],true)
    && in_array('independent_production_promotion_attestation_required',$prod['blockers'],true),
    'Production applied Staging permissions');
$GLOBALS['environment']='staging';
$GLOBALS['wp_environment']='staging';
$pair=$cls::selection(array('source_id'=>'etg-dynamic-filter-seo-bridge',
    'ref_type'=>'branch_commit','reference'=>'feature/release@'.$sha));
check(is_array($pair),'valid branch+commit rejected');
$reply=array('status'=>'diverged','base_commit'=>array('sha'=>$sha),
    'merge_base_commit'=>array('sha'=>$sha),'head_commit'=>array('sha'=>str_repeat('b',40)));
check(is_wp_error($cls::verified_sha_from_response($pair,$reply)),'diverged commit accepted');
$reply['status']='ahead';
check($cls::verified_sha_from_response($pair,$reply)===$sha,'true ancestor was denied');
$reply['merge_base_commit']['sha']=str_repeat('b',40);
check(is_wp_error($cls::verified_sha_from_response($pair,$reply)),'wrong merge-base accepted');
check(is_wp_error($cls::selection(array('source_id'=>'etg-dynamic-filter-seo-bridge','ref_type'=>'pull_request','reference'=>'0'))),'invalid PR');
$request=$cls::onboarding_plan(array(
    'source_id'=>'external-demo', 'repository'=>'external-owner/another-plugin',
    'plugin_subdirectory'=>'plugin-directory', 'plugin_file'=>'plugin-directory/plugin.php',
    'provider_id'=>'external-demo'
));
check(is_array($request) && $request['state']==='NEEDS_SOURCE_REVIEW'
    && !$request['installation_authorized'] && !$request['production_authorized'],
    'unreviewed external plugin should be proposed, never installed');
check(is_wp_error($cls::onboarding_plan(array(
    'source_id'=>'bad', 'repository'=>'https://evil.example/file.zip',
    'plugin_subdirectory'=>'plugin-directory',
    'plugin_file'=>'plugin-directory/plugin.php'))), 'arbitrary package URL accepted');
check(is_wp_error($cls::onboarding_plan(array(
    'source_id'=>'bad', 'repository'=>'external-owner/good',
    'plugin_subdirectory'=>'../plugins',
    'plugin_file'=>'plugins/main.php'))), 'plugin traversal candidate accepted');
check(is_wp_error($cls::onboarding_plan(array(
    'source_id'=>'etg-dynamic-filter-seo-bridge',
    'repository'=>'external-owner/good',
    'plugin_subdirectory'=>'plugin-directory',
    'plugin_file'=>'plugin-directory/plugin.php'))), 'existing source overwritten by intake');
echo "CROSS_PLUGIN_DEPLOYMENT: PASS\n";
