<?php
/* Hermetic provider-result feedback regression; no network, no saved credentials. */
define('ABSPATH', __DIR__ . '/');
function add_action() {}
function get_option($id, $default=array()) {
    return isset($GLOBALS['options'][$id]) ? $GLOBALS['options'][$id] : $default;
}
function wp_json_encode($data) { return json_encode($data); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$v)); }
function assert_result($condition,$name) { if(!$condition) throw new RuntimeException('FAIL: '.$name); }
require __DIR__ . '/../includes/class-mad4b-scp-firecrawl-provider.php';
require __DIR__ . '/../includes/class-mad4b-scp-provider-setup-coverage.php';

$profile = array('endpoint'=>'https://example.invalid','username'=>'svc','secret_cipher'=>'opaque-test-value');
$GLOBALS['options']['mad4b_firecrawl_provider_v1'] = array(
  'mode'=>'self_hosted','profiles'=>array('self_hosted'=>$profile));
$fp=hash('sha256',json_encode(array('self_hosted',$profile['endpoint'],$profile['username'],$profile['secret_cipher'])));
$GLOBALS['options']['mad4b_firecrawl_last_connection_test_v1'] = array(
  'mode'=>'self_hosted','profile_sha256'=>$fp,'result'=>'reachable','http_status'=>200,'at'=>'2026-10-30T10:00:00+00:00');

$last=MAD4B_SCP_Firecrawl_Provider::test_status();
assert_result($last['tested']===true,'Firecrawl existing result current');
assert_result($last['http_status']===200,'Firecrawl HTTP status kept');
$s=MAD4B_SCP_Firecrawl_Provider::test_feedback($last);
assert_result($s['level']==='success' && strpos($s['title'],'successful')!==false,'Firecrawl success notice');

$GLOBALS['options']['mad4b_firecrawl_last_connection_test_v1']['result']='authentication_failed';
$GLOBALS['options']['mad4b_firecrawl_last_connection_test_v1']['http_status']=401;
$s=MAD4B_SCP_Firecrawl_Provider::test_feedback(MAD4B_SCP_Firecrawl_Provider::test_status());
assert_result($s['level']==='error' && strpos($s['message'],'401')!==false,'Firecrawl error notice');

$GLOBALS['options']['mad4b_firecrawl_provider_v1']['profiles']['self_hosted']['secret_cipher']='rotated-secret';
$last=MAD4B_SCP_Firecrawl_Provider::test_status();
assert_result($last['tested']===false && $last['state']==='network_test_pending','Rotated credentials invalidate prior receipt');
assert_result(MAD4B_SCP_Firecrawl_Provider::test_feedback($last)['level']==='info','Pending state is not a success');

$cases=array(
 array('family'=>'search','connection_state'=>'VERIFIED','credential_state'=>'encrypted_saved','expect'=>'success'),
 array('family'=>'search','connection_state'=>'CHECK_FAILED','credential_state'=>'encrypted_saved','expect'=>'error'),
 array('family'=>'search','connection_state'=>'NOT_CONFIGURED','credential_state'=>'not_configured','expect'=>'warning'),
 array('family'=>'oauth','connection_state'=>'token_unreadable','credential_state'=>'unknown','expect'=>'error'),
 array('family'=>'ai','connection_state'=>'credential_present_not_live_tested','credential_state'=>'configured_database','expect'=>'info'),
 array('family'=>'ai','connection_state'=>'credential_missing','credential_state'=>'none','expect'=>'warning'),
 array('family'=>'custom','connection_state'=>'adapter_not_installed','credential_state'=>'credentials_not_enrolled','expect'=>'info')
);
foreach($cases as $case) {
 $result=MAD4B_SCP_Provider_Setup_Coverage::connection_feedback($case);
 assert_result($result['level']===$case['expect'],$case['family'].'/'.$case['connection_state']);
}
$raw=file_get_contents(__DIR__.'/../includes/class-mad4b-scp-firecrawl-provider.php');
assert_result(strpos($raw,'#mad4b-firecrawl-provider')!==false,'Redirect to visible feedback');
assert_result(strpos($raw,'notice notice-')!==false,'Uses WordPress notice styles');
echo "PROVIDER_FEEDBACK_REGRESSION: PASS (success, rejected credentials, stale test, provider states, redirect)\n";
