<?php
/** Owner-approved company guide daily base-cost policy; dry-run by default. */
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
if (!class_exists('MAD4B_Guide_Daily_Base_Cost_V1', false)) {
    WP_CLI::error('Guide daily base-cost resolver not installed');
}
$desired=MAD4B_Guide_Daily_Base_Cost_V1::template();
$key=MAD4B_Guide_Daily_Base_Cost_V1::OPTION;
$before=get_option($key,null);
if ($before!==null && $before!==$desired) {
    WP_CLI::error('Conflicting pre-existing guide policy. Manual reconciliation required.');
}
if ($before===$desired) {
    WP_CLI::success('ALREADY_CONFIGURED site='.home_url().' sha256='.hash('sha256',wp_json_encode($before)));
    return;
}
$apply=getenv('MAD4B_GUIDE_COST_APPLY')==='1';
WP_CLI::log(($apply?'APPLY':'DRY_RUN').' site='.home_url().' policy='.wp_json_encode($desired));
if (!$apply) return;
$backup='/tmp/mad4b-guide-base-cost-before-'.gmdate('Ymd-His').'.json';
$snapshot=wp_json_encode(['previous_option_exists'=>false,'site'=>home_url(),
                          'new_policy'=>$desired],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if (!$snapshot || file_put_contents($backup,$snapshot)===false) WP_CLI::error('Backup failed');
if (!add_option($key,$desired,'',false)) WP_CLI::error('Insert failed; backup '.$backup);
$actual=MAD4B_Guide_Daily_Base_Cost_V1::read();
if (is_wp_error($actual) || $actual!==$desired) WP_CLI::error('Readback invalid; backup '.$backup);
WP_CLI::success('GUIDE_COST_POLICY_VERIFIED backup='.$backup.' sha256='.hash('sha256',wp_json_encode($actual)));
