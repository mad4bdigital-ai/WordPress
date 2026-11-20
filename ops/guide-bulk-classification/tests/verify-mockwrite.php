<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$bulk='MAD4B_Guide_Bulk_Classification_V1';
$core='MAD4B_Guide_Service_Composition_V1';
$source=$core::source(60627);
$detail=$bulk::detail(60627);
if(is_wp_error($detail))WP_CLI::error('Cannot preview candidate '.$detail->get_error_code());
if($detail['revision']!==0)WP_CLI::error('Expected unreviewed test source');
$captured=null;$audits=[];
$getFn=static function($value,$id,$key,$single) use (&$captured,$source,$core) {
 if((int)$id===$source['source_id']&&$key===$core::META&&$captured!==null) return [$captured];
 return $value;
};
$addFn=static function($value,$id,$key,$record,$unique) use (&$captured,&$audits,$source,$core) {
 if((int)$id!==$source['source_id'])return $value;
 if($key===$core::META){$captured=$record;return true;}
 if($key==='_mad4b_guide_day_review_audit_v1'){$audits[]=$record;return true;}
 return $value;
};
$updateFn=static function($value,$id,$key,$record) use (&$captured,$source,$core) {
 if((int)$id===$source['source_id']&&$key===$core::META){$captured=$record;return true;}
 return $value;
};
add_filter('get_post_metadata',$getFn,25000,4);
add_filter('add_post_metadata',$addFn,25000,5);
add_filter('update_post_metadata',$updateFn,25000,4);
$decisions=[];
foreach($source['days'] as $key=>$row)$decisions[$key]='unknown';
$keys=array_keys($decisions);
$decisions[$keys[0]]='none';$decisions[$keys[1]]='full_day';
$result=$bulk::approve([
 'tour_id'=>60627,'program_id'=>0,'mode'=>'manual_review',
 'source_hash'=>$source['source_hash'],'revision'=>0,
 'decisions'=>$decisions,'review_note'=>'Tour supplier services verified by authorized package cost reviewer.'
]);
if(is_wp_error($result))WP_CLI::error('Mock approval denied '.$result->get_error_code());
if($result['changed_days']!==2 || $result['unknown_remaining']!==3 ||
   !is_array($captured) || $captured['statuses'][$keys[1]]!=='full_day' ||
   $captured['reviewed_by']!==1 || count($audits)!==1)
 WP_CLI::error('Mock approval did not persist audited/source-scoped changes');
WP_CLI::log('PASS 2 reviewed days 3 unknown non-commercial');
$stale=$bulk::approve([
 'tour_id'=>60627,'program_id'=>0,'mode'=>'high_only',
 'source_hash'=>$source['source_hash'],'revision'=>0
]);
if(!is_wp_error($stale)||$stale->get_error_code()!=='guide_bulk_review_revision_conflict')
 WP_CLI::error('Concurrent stale review accepted');
WP_CLI::log('PASS stale revision blocked after prior review');
remove_filter('get_post_metadata',$getFn,25000);
remove_filter('add_post_metadata',$addFn,25000);
remove_filter('update_post_metadata',$updateFn,25000);
$actual=get_post_meta($source['source_id'],$core::META,true);
if($actual!=='' && $actual!==false)WP_CLI::error('Mock inadvertently wrote real post metadata');
WP_CLI::log('PASS original tour records unchanged');
WP_CLI::success('BULK_MOCKWRITE_ACCEPTANCE_PASSED');
