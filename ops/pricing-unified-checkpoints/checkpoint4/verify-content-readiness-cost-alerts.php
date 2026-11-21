<?php
/**
 * Read-only integration/regression check; invoke using the native WordPress
 * runtime with a legitimate pricing-authorized user. No price writes or alerts created.
 */
if(!defined('WP_CLI') || !WP_CLI) return;
if(!current_user_can('commerce_manage_pricing')) WP_CLI::error('CP4_ALERT_QA_AUTH_REQUIRED');
if(!class_exists('MAD4B_CP4_Cost_Alerts_V1',false) ||
   !class_exists('MAD4B_SCP_Content_Readiness_Alerts',false))
   WP_CLI::error('CP4_ALERT_SOURCE_MISSING');
$one=MAD4B_CP4_Cost_Alerts_V1::discover();
if(empty($one['complete']) || !isset($one['issues']) ||
   !is_array($one['issues']) || count($one['issues'])>600)
   WP_CLI::error('CP4_ALERT_DISCOVERY_INCOMPLETE');
$two=MAD4B_CP4_Cost_Alerts_V1::discover();
if($one['issues']!==$two['issues']) WP_CLI::error('CP4_ALERT_NOT_DETERMINISTIC');
$seen=[];$kinds=[];$invalid=0;
foreach($two['issues'] as $row) {
    $key=$row['id'];
    if(isset($seen[$key]) || !MAD4B_CP4_Cost_Alerts_V1::admissible($row))
        WP_CLI::error('CP4_ALERT_ID_OR_OWNER_INVALID');
    $seen[$key]=true;
    if(MAD4B_CP4_Cost_Alerts_V1::can_resolve($row))
        WP_CLI::error('CP4_ALERT_FALSELY_AUTO_RESOLVABLE');
    $bad=$row;
    $bad['severity']='low';
    if(MAD4B_CP4_Cost_Alerts_V1::admissible($bad))
        WP_CLI::error('CP4_ALERT_TAMPERED_SEVERITY_ADMITTED');
    $bad=$row;
    $bad['source_evidence']['owner_group']='Untrusted external sender';
    if(MAD4B_CP4_Cost_Alerts_V1::admissible($bad))
        WP_CLI::error('CP4_ALERT_OWNER_GROUP_SPOOF_ACCEPTED');
    $kind=(string)($row['source_evidence']['kind']??'');
    $kinds[$kind]=1+($kinds[$kind]??0);
}
$ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
$registered=array_values(array_filter($ledger,static fn($row)=>is_array($row) &&
  ($row['source']??'')===MAD4B_CP4_Cost_Alerts_V1::SOURCE &&
  ($row['status']??'open')==='open'));
echo 'CP4_ALERT_READONLY_ACCEPTANCE '.wp_json_encode([
    'site'=>home_url(),'source'=>MAD4B_CP4_Cost_Alerts_V1::SOURCE,
    'canonical_package_count'=>$two['tours']??null,
    'stages'=>$two['stages']??null,'pending_source_alerts'=>count($two['issues']),
    'native_ledger_open_count'=>count($registered),'by_kind'=>$kinds,
    'signed_cost_approved_by_test'=>false,
    'full_alert_ledger_mutated'=>false,'no_external_emails_sent'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;
WP_CLI::success('CP4_ALERT_SOURCE_READONLY_PASS');
