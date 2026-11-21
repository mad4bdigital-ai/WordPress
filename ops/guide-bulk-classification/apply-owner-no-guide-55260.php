<?php
/**
 * Owner decision: Tour 55260 Nile Dinner Cruise has NO Guide service in
 * the contracted package. Source-only Guide coverage review.
 *
 * Dry run by default. MAD4B_GUIDE_NO_GUIDE_APPLY=1 enables one exact write.
 */
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
wp_set_current_user(1);
$core='MAD4B_Guide_Service_Composition_V1';
$bulk='MAD4B_Guide_Bulk_Classification_V1';
$tour=55260;
$apply=getenv('MAD4B_GUIDE_NO_GUIDE_APPLY')==='1';
if(!$bulk::permitted()||!current_user_can('edit_post',$tour))WP_CLI::error('Company pricing permission denied');
$source=$core::source($tour);
$detail=$bulk::detail($tour);
if(is_wp_error($source)||is_wp_error($detail))WP_CLI::error('Unusable source');
if($source['source_kind']!=='post'||$source['tour_id']!==$tour||
    count($source['days'])!==1||!in_array($source['day_unit_basis'],
    ['explicit_single_day_duration_taxonomy','explicit_one_day_aggregated_stages'],true))
    WP_CLI::error('Nile dinner cruise structure not verified');
if($detail['revision']!==0||($detail['days'][0]['saved_status']??'')!=='unknown')
    WP_CLI::error('Existing coverage review; refuse overwrite');
$includes=get_post_meta($tour,'includes',true);
if(!is_array($includes)||!$includes)WP_CLI::error('Included services missing');
foreach($includes as $item){
 $v=is_array($item)?(string)($item['included_item']??''):'';
 if(preg_match('~(?:professional|licensed|english[- ]speaking|tour)\s+guide|guided\s+tour~iu',wp_strip_all_tags($v)))
    WP_CLI::error('Existing Included Guide source conflicts with owner no-guide decision');
}
global $wpdb;
$rates=$wpdb->get_results("SELECT post_status,COUNT(*) n FROM {$wpdb->posts} WHERE post_type='tour-rates' GROUP BY post_status",ARRAY_A);
$before=[
 'contract'=>'mad4b.owner-no-guide-55260.v1','site'=>home_url(),'time'=>gmdate('c'),
 'source_hash'=>$source['source_hash'],'source_basis'=>$source['day_unit_basis'],
 'tour_id'=>$tour,'tour_status'=>get_post_status($tour),
 'source_days'=>$source['days'],'includes'=>$includes,
 'coverage_meta'=>get_post_meta($tour,$core::META,true),
 'audit_meta'=>get_post_meta($tour,'_mad4b_guide_day_review_audit_v1',false),
 'tour_rates'=>$rates,
 'owner_intent'=>'Nile Dinner Cruise 55260 without a Guide; no guide cost in package',
 'dry_run'=>!$apply
];
$path='/tmp/owner-noguide-55260-before-'.gmdate('Ymd-His').'.json';
if(file_put_contents($path,wp_json_encode($before,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))===false)
 WP_CLI::error('Backup write failed');
WP_CLI::log(($apply?'APPLY':'DRY_RUN').' site='.home_url().' source='.$source['source_hash'].' backup='.$path);
if($apply){
 $note='Owner direction 2026-11-20: Tour 55260 Nile Dinner Cruise has no Guide. Verified Included services describe transport, yacht and dinner only; no Guide service. Classification is no-guide, not a half-day Guide charge.';
 $result=$bulk::approve([
  'tour_id'=>$tour,'program_id'=>0,'source_hash'=>$source['source_hash'],'revision'=>0,
  'mode'=>'manual_review','decisions'=>['day-1'=>'none'],'review_note'=>$note
 ]);
 if(is_wp_error($result)||$result['changed_days']!==1||$result['unknown_remaining']!==0)
   WP_CLI::error('Approval failed '.(is_wp_error($result)?$result->get_error_code():'missing changes'));
 $receipt=$core::saved($core::source($tour));
 if(is_wp_error($receipt)||($receipt['statuses']['day-1']??'')!=='none'||
    ($receipt['approval_channel']??'')!=='delegated_wp_cli_operator'||
    $receipt['revision']!==1)WP_CLI::error('Approval receipt mismatch');
 $zero=$core::guide($tour,'');
 if(is_wp_error($zero)||$zero['cost']!=0||
    $zero['effective_guide_days']!=0||$zero['checkout_ready']!==false)
    WP_CLI::error('No-guide cost not zero or commercial activation changed');
 WP_CLI::log('VERIFIED tour=55260 guide_day_status=none guide_cost_egp=0 supplier=self checkout_ready=false');
}
$after=$wpdb->get_results("SELECT post_status,COUNT(*) n FROM {$wpdb->posts} WHERE post_type='tour-rates' GROUP BY post_status",ARRAY_A);
if($after!==$rates)WP_CLI::error('Tour Rate status changed');
WP_CLI::success(($apply?'OWNER_NO_GUIDE_APPLIED':'OWNER_NO_GUIDE_DRYRUN_PASSED').' no_rate_change');
