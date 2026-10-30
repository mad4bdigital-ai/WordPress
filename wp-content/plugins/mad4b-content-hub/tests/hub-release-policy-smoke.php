<?php
define('ABSPATH', __DIR__ . '/');
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s)); }
function sanitize_text_field($s) { return trim((string)$s); }
function get_option($name,$default=false) { return $default; }
require dirname(__DIR__).'/includes/class-mad4b-hub-usage-rights.php';
require dirname(__DIR__).'/includes/class-mad4b-hub-release-gate.php';
foreach (array('display','publish','distribution') as $purpose) {
    $r=MAD4B_Hub_Usage_Rights::assess(
        array('origin'=>'external_reference','consumer_company_id'=>'wo-vacation'),
        array('quality_state'=>'passed','hard_blockers'=>array(),'external_media'=>true),
        $purpose
    );
    if (empty($r['allowed']) || $r['rights_metadata']['license_state']!=='not_verified') {
        throw new RuntimeException('company_policy_contract_failed_'.$purpose);
    }
}
if (!method_exists('MAD4B_Hub_Release_Gate','evaluate')
    || !method_exists('MAD4B_Hub_Release_Gate','release')) {
    throw new RuntimeException('canonical_release_contract_missing');
}
echo "HUB_RELEASE_POLICY_SMOKE=PASS\n";
