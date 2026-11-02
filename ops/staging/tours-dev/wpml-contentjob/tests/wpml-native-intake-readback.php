<?php
/** Readback acceptance for the staging-only WPML ContentJob adapter.
 * Usage: wp eval-file tests/wpml-native-intake-readback.php --user=<enrolled WP operator> --path=/var/www/html --skip-themes
 * Executes one idempotent readback for a previously mapped source; does not publish/translate.
 */
if (!defined('ABSPATH')) exit(1);
function wpml_intake_check($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: ".$label.PHP_EOL); exit(1); }
    echo "PASS: ".$label.PHP_EOL;
}
wpml_intake_check(class_exists('MAD4B_WPML_ContentJob_Adapter'), 'adapter loaded');
wpml_intake_check(MAD4B_WPML_ContentJob_Adapter::ready(), 'bound to staging');
$preview=MAD4B_WPML_ContentJob_Adapter::preview();
wpml_intake_check(!is_wp_error($preview), 'read-only discovery');
$before=(int)$preview['summary']['eligible'];
wpml_intake_check($before>=1, 'active intake sources');
$off=static function(){return 'no';};
add_filter('pre_option_mad4b_wpml_design_auto_sync_enabled_v1', $off, 20);
$disabled=MAD4B_WPML_ContentJob_Adapter::preview();
wpml_intake_check($disabled['summary']['eligible']===0, 'master off disables all automatic intake');
remove_filter('pre_option_mad4b_wpml_design_auto_sync_enabled_v1',$off,20);
$exclude=static function($v,$id,$key,$single){
    if((int)$id===30843&&$key==='_mad4b_wpml_auto_sync_excluded') return 'yes';
    return $v;
};
add_filter('get_post_metadata',$exclude,10,4);
$excluded=MAD4B_WPML_ContentJob_Adapter::preview();
wpml_intake_check($excluded['summary']['eligible']<$before, 'post exclusion suppresses individual intake');
remove_filter('get_post_metadata',$exclude,10);
global $wpdb;
$sc=MAD4B_SCP_Operational_Integrity::capture();
wpml_intake_check(!is_wp_error($sc), 'native scope');
$scope=$sc['scope'];
$sql=$wpdb->prepare('SELECT COUNT(*) FROM wp_mad4b_content_jobs WHERE site_uuid=%s AND tenant_id=%s AND brand_id=%s AND content_type=%s',
    $scope['site_uuid'],$scope['tenant_ref'],$scope['brand_ref'],'translation');
$count_before=(int)$wpdb->get_var($sql);
$map=(array)get_option(MAD4B_WPML_ContentJob_Adapter::MAP,[]);
$has_source=false;
foreach($map as $m) if((int)($m['source_post_id']??0)===30843) $has_source=true;
if($has_source) {
    $r=MAD4B_WPML_ContentJob_Adapter::run(1,30843);
    wpml_intake_check(!is_wp_error($r) && $r['created']===0 && $r['reused']===1 && !$r['errors'],
        'idempotent repeat uses previously created native job');
    wpml_intake_check($count_before===(int)$wpdb->get_var($sql), 'no duplicate ContentJob');
} else {
    echo 'SKIP: source 30843 has no existing mapped job'.PHP_EOL;
}
$map=(array)get_option(MAD4B_WPML_ContentJob_Adapter::MAP,[]);
$seen=[];
foreach($map as $key=>$m) {
    if(empty($m['job_id'])) continue;
    wpml_intake_check(!isset($seen[$m['job_id']]), 'unique native mapping '.$m['job_id']);
    $seen[$m['job_id']]=true;
    $native=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$m['job_id']]);
    wpml_intake_check(!is_wp_error($native) && ($native['job']['content_type']??'')==='translation', 'native job readback '.$m['job_id']);
}
wpml_intake_check(count($seen)>0, 'at least one governed content job');
echo 'PASS: WPML staging intake readback and safety checks'.PHP_EOL;
