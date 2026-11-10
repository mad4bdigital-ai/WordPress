<?php
/** Live Staging verified legacy-empty strategy rebind; no Production access. */
defined('WP_CLI')&&WP_CLI || exit(2);
$check=[];
$cls='MAD4B_Company_Strategy_V1';
$current= MAD4B_Company_Portfolio_Manager_V1::company_binding('wayfatours');
$oldSite='b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b';
$old=[ 'contract'=>$cls::CONTRACT,'company_code'=>'wayfatours',
 'company_site_uuid'=>$oldSite,'revision'=>0,'candidate'=>null,
 'active'=>null,'history'=>[],'updated_at'=>null ];
$oldSha=hash('sha256',maybe_serialize($old));
$archiveKey='mad4b_cs_legacy_empty_archive_v1_'.substr(hash('sha256',
 'wayfatours|'.$oldSite.'|'.$current['company_site_uuid'].'|'.$oldSha),0,40);
$archive=get_option($archiveKey,null);
$check['immutable_archive_bound_exact']=is_array($archive)
 &&($archive['prior_record_sha256']??null)===$oldSha
 &&($archive['prior_record']??null)===$old
 &&($archive['verified_new_site_uuid']??null)===$current['company_site_uuid'];
$check['strategy_current_binding_matches']=!is_wp_error($current)
 &&$current['company_site_uuid']==='a3a7cc36-db7e-4a14-8456-b41857def05c';
$before=get_option($cls::STORE,[]);
$row=$before['wayfatours']??[];
$check['strategy_empty_revision_zero']=is_array($row)
 &&array_key_exists('revision',$row)&&$row['revision']===0
 &&array_key_exists('candidate',$row)&&$row['candidate']===null
 &&array_key_exists('active',$row)&&$row['active']===null
 &&array_key_exists('history',$row)&&$row['history']===[];
$check['no_wrong_company_scope']=!$cls::is_empty_legacy_binding_record($old,'other-company');
$tainted=$old;$tainted['candidate']=['state'=>'approved'];
$check['approved_record_not_auto_rebound']=!$cls::is_empty_legacy_binding_record($tainted,'wayfatours');
$tainted=$old;$tainted['revision']=1;
$check['nonzero_revision_not_auto_rebound']=!$cls::is_empty_legacy_binding_record($tainted,'wayfatours');
global $wpdb;
$table=MAD4B_SCP_Schema::tables()['content_jobs'];
$countBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$membership=get_option('mad4b_company_network_memberships_v1',null);
$ids=get_users(['role'=>'administrator','fields'=>'ids','number'=>1]);
if(!$ids){echo wp_json_encode(['pass'=>false,'reason'=>'NO_ADMIN']),PHP_EOL;exit(8);}
wp_set_current_user((int)$ids[0]);
$result=$cls::reconcile_company('wayfatours');
$check['idempotent_reconcile_existing']=!is_wp_error($result)
 &&($result['state']??null)==='EXISTS'
 &&($result['contentjob_skill_preflight']??null)==='PREFLIGHT_PLAN_READY_APPLY_HOLD'
 &&($result['content_jobs_created']??1)===0;
$check['store_unchanged_on_repeat']=$before===get_option($cls::STORE,[]);
$check['archive_unchanged_on_repeat']=$archive===get_option($archiveKey,null);
$check['no_content_jobs_created']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$countBefore;
$check['no_membership_mutation']=$membership===get_option('mad4b_company_network_memberships_v1',null);
echo wp_json_encode(['pass'=>!in_array(false,$check,true),
 'checks'=>$check,'count'=>count($check),
 'current_site_uuid'=>$current['company_site_uuid'],
 'archived_record_sha256'=>$oldSha,
 'reconcile_state'=>is_wp_error($result)?$result->get_error_code():($result['state']??null),
 'jobs'=>$countBefore],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$check,true))exit(8);
