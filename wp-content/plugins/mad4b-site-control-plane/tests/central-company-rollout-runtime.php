<?php
/** Hermetic central-Staging company compatibility matrix. No site writes. */
declare(strict_types=1);
if ( ! defined('ABSPATH') ) define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $error;
    public function __construct($error, $message='') { $this->error=$error; }
    public function get_error_code() { return $this->error; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_plugins() { return array('woocommerce/woocommerce.php'=>array('Version'=>'10.1.0')); }
function is_plugin_active($plugin) { return $plugin==='woocommerce/woocommerce.php'; }
function wp_get_environment_type() { return 'production'; }
function get_bloginfo($key) { return $key==='version' ? '6.9.0' : ''; }
function home_url() { return 'https://company.example'; }
class MAD4B_SCP_Site_Profile {
    public static function status() { return array('site_uuid'=>'11111111-2222-4333-8444-555555555555'); }
    public static function origin_enrolled() { return true; }
    public static function current_environment() { return 'production'; }
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-central-release-registry.php';
require dirname(__DIR__) . '/includes/class-mad4b-scp-central-company-rollout.php';
function ck($ok, $msg) {
    if (!$ok) { fwrite(STDERR, 'FAIL: '.$msg.PHP_EOL); exit(1); }
}
$c='MAD4B_SCP_Central_Company_Rollout';
$release=str_repeat('a', 64);
$site=array(
    'environment'=>'production', 'enrolled_environment'=>'production',
    'origin_enrolled'=>true, 'site_uuid'=>'11111111-2222-4333-8444-555555555555',
    'origin'=>'https://company.example', 'php_version'=>'8.3.22',
    'wordpress_version'=>'6.9.0',
    'plugins'=>array('woocommerce/woocommerce.php'=>array('version'=>'10.1.0','active'=>true),
        'mad4b-company/entry.php'=>array('version'=>'1.0.0','active'=>true)),
);
$receipt=array(
    'contract'=>'mad4b.central-staging-release-readback.v1',
    'staging_origin'=>'https://tours.dev.mad4b.com',
    'environment'=>'staging',
    'registry_readback_verified'=>true,
    'source_type'=>'local_git',
    'source_commit'=>str_repeat('b',40),
    'artifact_sha256'=>$release,
    'file_manifest_sha256'=>str_repeat('c',64),
    'staging_pass'=>true,
    'checks'=>array('install'=>true,'smoke'=>true,'rollback'=>true,'schema_rollback'=>true),
    'tested_at'=>time(),
    'requirements'=>array(
        'target_plugin_file'=>'mad4b-company/entry.php',
        'target_version'=>'1.1.0',
        'php_min'=>'8.1.0',
        'wordpress_min'=>'6.5.0',
        'required_plugins'=>array(
            array('file'=>'woocommerce/woocommerce.php','min_version'=>'9.0.0','active_required'=>true)
        ),
        'requires_schema_migration'=>false
    ),
);
$good=$c::assess($release,$receipt,$site,true);
ck($good['ready_for_separate_owner_authorization']===true,'compatible company target with central pass should plan');
ck($good['company_staging_required']===false && $good['company_staging_evaluated']===false,'company Staging must not be a gate');
ck($good['package_certification_required']===false,'no package certificate required');
ck($good['executor']==='' && $good['production_mutation_authorized']===false && $good['mutation_performed']===false,'no deployment authority from planner');
ck($good['company_site_uuid']===$site['site_uuid'] && $good['company_environment']==='production','tenant identity bound');

$untrusted=$c::assess($release,$receipt,$site,false);
ck(!$untrusted['ready_for_separate_owner_authorization'] && in_array('trusted_central_release_readback_unavailable',$untrusted['blockers'],true),'caller-supplied pass is not trusted');

$wrong=$receipt; $wrong['staging_origin']='https://company-staging.example';
ck(in_array('central_staging_identity_unverified',$c::assess($release,$wrong,$site,true)['blockers'],true),'company staging origin cannot replace central');

$wrong=$receipt; $wrong['artifact_sha256']=str_repeat('d',64);
ck(in_array('central_release_artifact_mismatch',$c::assess($release,$wrong,$site,true)['blockers'],true),'artifact drift refused');

$wrong=$receipt; $wrong['staging_pass']=false;
ck(in_array('central_staging_tests_not_passed',$c::assess($release,$wrong,$site,true)['blockers'],true),'failed central test blocked');

$wrong=$receipt; $wrong['checks']['rollback']=false;
ck(in_array('central_staging_tests_not_passed',$c::assess($release,$wrong,$site,true)['blockers'],true),'rollback rehearsal required');

$wrong=$receipt; $wrong['tested_at']=time()-8*86400;
ck(in_array('central_staging_evidence_stale',$c::assess($release,$wrong,$site,true)['blockers'],true),'stale central evidence blocked');

$wrong=$receipt; $wrong['source_commit']='latest';
ck(in_array('immutable_source_identity_missing',$c::assess($release,$wrong,$site,true)['blockers'],true),'mutable source not accepted');

$wrong=$site; $wrong['environment']='staging';
ck(in_array('company_production_identity_unverified',$c::assess($release,$receipt,$wrong,true)['blockers'],true),'company environment validated');

$wrong=$site; $wrong['php_version']='7.4.0';
ck(in_array('company_php_min_incompatible',$c::assess($release,$receipt,$wrong,true)['blockers'],true),'PHP minimum enforced');

$wrong=$site; $wrong['wordpress_version']='6.1.0';
ck(in_array('company_wordpress_min_incompatible',$c::assess($release,$receipt,$wrong,true)['blockers'],true),'WP minimum enforced');

$wrong=$site; $wrong['plugins']['woocommerce/woocommerce.php']['active']=false;
ck(in_array('company_plugin_dependency_incompatible',$c::assess($release,$receipt,$wrong,true)['blockers'],true),'company dependency checked');

$wrong=$site; $wrong['plugins']['mad4b-company/entry.php']['version']='1.2.0';
ck(in_array('company_target_downgrade_denied',$c::assess($release,$receipt,$wrong,true)['blockers'],true),'downgrade denied');

$wrong=$receipt; $wrong['requirements']['requires_schema_migration']=true; $wrong['checks']['schema_rollback']=false;
ck(in_array('company_schema_migration_rollback_unverified',$c::assess($release,$wrong,$site,true)['blockers'],true),'database migration rollback proof required');

$wrong=$receipt; $wrong['requirements']['required_plugins'][] = array('file'=>'../escape.php','min_version'=>'1.0.0');
ck(in_array('company_plugin_requirements_invalid',$c::assess($release,$wrong,$site,true)['blockers'],true),'path traversal rejected');

$wrong=$receipt; $wrong['requirements']['target_plugin_file']='../hack.php';
ck(in_array('release_requirements_invalid',$c::assess($release,$wrong,$site,true)['blockers'],true),'release root escape rejected');
$fromApi=$c::plan(array('release_sha256'=>$release));
ck(!$fromApi['ready_for_separate_owner_authorization'] &&
    in_array('trusted_central_release_readback_unavailable',$fromApi['blockers'],true),
    'server cannot treat client release hash as a central PASS when the verified provider is absent');
ck($fromApi['read_only']===true && $fromApi['production_mutation_authorized']===false,
    'read-only endpoint cannot grant Production authority');
$forged=$c::plan(array('release_sha256'=>$release,'staging_pass'=>true));
ck(is_wp_error($forged) && $forged->get_error_code()==='mad4b_central_rollout_input_invalid',
    'user-provided PASS is rejected at API boundary');

echo "CENTRAL_STAGING_COMPANY_ROLLOUT: PASS".PHP_EOL;
