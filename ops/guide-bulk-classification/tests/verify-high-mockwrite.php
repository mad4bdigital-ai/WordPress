<?php
if (!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$core='MAD4B_Guide_Service_Composition_V1';
$bulk='MAD4B_Guide_Bulk_Classification_V1';
$source=$core::source(60451);
$realBefore=get_post_meta(60451,$core::META,true);
$realAuditsBefore=get_post_meta(60451,'_mad4b_guide_day_review_audit_v1',false);
$mock=null;$audits=[];
$get=static function($value,$id,$key,$single)use(&$mock,$source,$core){
 if((int)$id===$source['source_id']&&$key===$core::META)
  return [$mock===null?'':$mock];
 return $value;
};
add_filter('get_post_metadata',$get,25000,4);
$detail=$bulk::detail(60451);
if(is_wp_error($detail)||$detail['revision']!==0 || $detail['high_new_candidates']!==1)
 WP_CLI::error('High evidence source not simulated unreviewed');
$update=static function($value,$id,$key,$record)use(&$mock,$source,$core){
 if((int)$id===$source['source_id']&&$key===$core::META){$mock=$record;return true;}
 return $value;
};
$add=static function($value,$id,$key,$record,$unique)use(&$mock,&$audits,$source,$core){
 if((int)$id!==$source['source_id'])return $value;
 if($key===$core::META){$mock=$record;return true;}
 if($key==='_mad4b_guide_day_review_audit_v1'){$audits[]=$record;return true;}
 return $value;
};
add_filter('update_post_metadata',$update,25000,4);
add_filter('add_post_metadata',$add,25000,5);
$note='Delegated operator evidence verification via ChatGPT, not a browser click.';
$result=$bulk::approve([
 'tour_id'=>60451,'program_id'=>0,'mode'=>'high_only',
 'source_hash'=>$source['source_hash'],'revision'=>0,'review_note'=>$note
]);
if(is_wp_error($result))WP_CLI::error('High proof failed '.$result->get_error_code());
if($result['changed_days']!==1 || ($mock['statuses']['day-1']??'')!=='full_day' ||
   ($mock['approval_channel']??'')!=='delegated_wp_cli_operator' ||
   ($mock['review_evidence']['_delegation_note']??'')!==$note ||
   count($audits)!==1)WP_CLI::error('High receipt audit invalid');
WP_CLI::log('PASS delegated_high_approval_audited_in_memory');
remove_filter('get_post_metadata',$get,25000);
remove_filter('update_post_metadata',$update,25000);
remove_filter('add_post_metadata',$add,25000);
if(get_post_meta(60451,$core::META,true)!==$realBefore ||
   get_post_meta(60451,'_mad4b_guide_day_review_audit_v1',false)!==$realAuditsBefore)
 WP_CLI::error('Mock changed real Tour evidence');
WP_CLI::log('PASS no_real_tour_data_written');
WP_CLI::success('GUIDE_HIGH_MOCKWRITE_ACCEPTED');
