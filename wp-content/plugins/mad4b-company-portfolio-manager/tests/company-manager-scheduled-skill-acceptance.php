<?php
/** Confirm scheduled Company Manager Reconcile consumes native Skill owner. */
defined('WP_CLI')&&WP_CLI || exit(2);
$checks=[];
$jobTable=MAD4B_SCP_Schema::tables()['content_jobs'];
global $wpdb;
$jobsBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $jobTable");
$membershipBefore=get_option('mad4b_company_network_memberships_v1',null);
$strategyBefore=get_option('mad4b_company_strategy_records_v1',null);
$before=get_option(MAD4B_Manager_Auto_Write_V1::SNAPSHOT,[]);
$checks['central_staging']=MAD4B_Manager_Auto_Write_V1::site()===true;
$checks['correct_snapshot_contract']=($before['contract']??null)===MAD4B_Manager_Auto_Write_V1::CONTRACT;
$rows=$before['dynamic_reconcile_skill_sets']??[];
$checks['schedule_has_three_companies']=is_array($rows)
 &&isset($rows['all-royal-egypt'],$rows['egypt-tour-gates'],$rows['wayfatours']);
$checks['no_unenrolled_executions']=true;
foreach(['all-royal-egypt','egypt-tour-gates','wayfatours'] as $code){
 $row=$rows[$code]??[];
 $checks['manager_consumer_'.$code]=($row['company_manager_consumer_required']??false)===true
  &&($row['state']??'')==='PREFLIGHT_PLAN_READY_APPLY_HOLD'
  &&($row['pre_execution_selection_passed']??false)===true
  &&($row['closure_authorized']??true)===false
  &&($row['content_jobs_created']??-1)===0
  &&($row['selected_skill_count']??0)>=14;
 $r=MAD4B_Company_ContentJob_Skill_Preflight_V1::inspect($code);
 $checks['native_digest_'.$code]=is_array($r)
  &&($row['skill_set_sha256']??null)===$r['native_skill_set_sha256'];
}
MAD4B_Manager_Auto_Write_V1::reconcile();
$after=get_option(MAD4B_Manager_Auto_Write_V1::SNAPSHOT,[]);
$checks['repeat_idempotent_snapshot']=$before===$after;
$checks['jobs_unchanged']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $jobTable")===$jobsBefore;
$checks['membership_unchanged']=$membershipBefore===get_option('mad4b_company_network_memberships_v1',null);
$checks['strategy_unchanged']=$strategyBefore===get_option('mad4b_company_strategy_records_v1',null);
$checks['no_execution_permissions_granted']=count(array_filter($rows,static fn($r)=>
 ($r['closure_authorized']??false)!==false || ($r['content_jobs_created']??0)!==0))===0;
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
 'checks'=>$checks,'assertions'=>count($checks),
 'snapshots_equal'=>$before===$after,'jobs_before'=>$jobsBefore,
 'jobs_after'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $jobTable")],
 JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
