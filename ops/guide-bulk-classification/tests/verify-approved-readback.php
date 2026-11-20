<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$core='MAD4B_Guide_Service_Composition_V1';
$bulk='MAD4B_Guide_Bulk_Classification_V1';
$ids=[60451,60455,60457,60461];
$results=[];
foreach($ids as $id){
 $src=$core::source($id);$save=$core::saved($src);$detail=$bulk::detail($id);
 $quote=$core::guide($id,'english');
 $audits=get_post_meta($id,'_mad4b_guide_day_review_audit_v1',false);
 $valid=!is_wp_error($save)&&!is_wp_error($quote)&&!is_wp_error($detail)
    &&($save['statuses']['day-1']??'')==='full_day'
    &&($save['approval_channel']??'')==='delegated_wp_cli_operator'
    &&($save['revision']??0)===1
    &&($quote['cost']??null)==2500
    &&count($audits)===1
    &&$detail['unresolved_days']===0
    &&($quote['checkout_ready']??true)===false;
 $results[]=['tour'=>$id,'valid'=>$valid,'day_status'=>is_wp_error($save)?$save->get_error_code():$save['statuses']['day-1'],
 'days'=>is_wp_error($quote)?null:$quote['effective_guide_days'],
 'cost_EGP'=>is_wp_error($quote)?$quote->get_error_code():$quote['cost'],
 'revision'=>is_wp_error($save)?null:$save['revision'],
 'approval_channel'=>is_wp_error($save)?null:($save['approval_channel']??'missing'),'audit_count'=>count($audits)];
 if(!$valid)WP_CLI::error('Readback invalid for Tour '.$id.' '.wp_json_encode(end($results)));
}
echo 'APPROVED '.wp_json_encode($results,JSON_UNESCAPED_UNICODE)."\n";
$noGuideSource=$core::source(55260);
$noGuideRecord=$core::saved($noGuideSource);
$noGuideCost=$core::guide(55260,'');
$noGuideAudits=get_post_meta(55260,'_mad4b_guide_day_review_audit_v1',false);
if(is_wp_error($noGuideRecord)||is_wp_error($noGuideCost)||
   ($noGuideRecord['statuses']['day-1']??'')!=='none' ||
   ($noGuideRecord['approval_channel']??'')!=='delegated_wp_cli_operator' ||
   ($noGuideCost['cost']??null)!=0 ||
   ($noGuideCost['checkout_ready']??true)!==false ||
   count($noGuideAudits)!==1)
 WP_CLI::error('Owner-approved no-guide Tour 55260 invalid');
echo 'OWNER_NOGUIDE '.wp_json_encode([
 'tour'=>55260,'service'=>'none','cost_egp'=>0,
 'audit_count'=>count($noGuideAudits),
 'checkout_ready'=>false,
])."
";
$blocked=[];
foreach([55113,60443] as $id) {
 $d=$bulk::detail($id);
 if(is_wp_error($d)||$d['unresolved_days']!=$d['day_count']) WP_CLI::error('Unresolved source incorrectly marked reviewed '.$id);
 $blocked[]=['tour'=>$id,'unknown_days'=>$d['unresolved_days']];
}
echo 'STILL_UNRESOLVED '.wp_json_encode($blocked)."\n";
WP_CLI::success('APPROVED_SOURCES_VERIFIED_NO_COMMERCIAL_ACTIVATION');
