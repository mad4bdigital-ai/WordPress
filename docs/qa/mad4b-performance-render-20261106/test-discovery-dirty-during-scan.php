<?php
// Staging integration test: a source-change signal injected DURING the first
// scan must survive; the second scan must acknowledge it atomically.
$host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
if ($host!=='tours.dev.mad4b.com' || wp_get_environment_type()!=='staging' || DB_NAME!=='etg_commerce_staging') throw new RuntimeException('wrong_tenant_or_environment');
if (!class_exists('MAD4B_SCP_Adaptive_Presentation_Discovery',false))throw new RuntimeException('discovery_not_loaded');
$receiptBefore=get_option('mad4b_perf_css_policy_receipt_v2',[]);
if (($receiptBefore['approved_count']??-1)!==0 || get_option('mad4b_perf_predictive_css_v2','off')==='on')throw new RuntimeException('unsafe_public_release_state');
$initialDirty=(int)get_option(MAD4B_SCP_Adaptive_Presentation_Discovery::DIRTY,0);
$initialJobCount=count(MAD4B_SCP_Adaptive_Asset_Intelligence::queue());
$wasInjected=false;
$callback=static function($sql)use(&$wasInjected){
  if (!$wasInjected && is_string($sql) && str_contains($sql,"'_elementor_data'") && str_contains($sql,'ORDER BY')) {
    $wasInjected=true;
    MAD4B_SCP_Adaptive_Presentation_Discovery::queue_refresh();
  }
  return $sql;
};
add_filter('query',$callback,15,1);
try {$first=MAD4B_SCP_Adaptive_Presentation_Discovery::scan();}
finally {remove_filter('query',$callback,15);}
$dirtyAfterFirst=(int)get_option(MAD4B_SCP_Adaptive_Presentation_Discovery::DIRTY,0);
$firstStatus=MAD4B_SCP_Adaptive_Presentation_Discovery::status();
echo 'FIRST_SCAN_READBACK='.json_encode(['injected'=>$wasInjected,'dirty_initial'=>$initialDirty,'dirty_after'=>$dirtyAfterFirst,'snapshot_scanned_at'=>$first['scanned_at']??null,'state'=>$firstStatus['state']??null],JSON_UNESCAPED_SLASHES)."\n";
if (!$wasInjected || $dirtyAfterFirst===0 || ($firstStatus['state']??'')!=='refresh_queued')throw new RuntimeException('concurrent_dirty_signal_was_not_retained');
$second=MAD4B_SCP_Adaptive_Presentation_Discovery::scan();
$dirtyAfterSecond=(int)get_option(MAD4B_SCP_Adaptive_Presentation_Discovery::DIRTY,0);
$finalStatus=MAD4B_SCP_Adaptive_Presentation_Discovery::status();
$receiptAfter=get_option('mad4b_perf_css_policy_receipt_v2',[]);
$result=[
  'contract'=>'mad4b.discovery-dirty-during-scan-qa.v1',
  'tenant'=>$host,'initial_dirty'=>$initialDirty,
  'injected_during_first_sql_scan'=>$wasInjected,
  'first_scan_state'=>$first['state']??null,
  'first_status'=>$firstStatus['state']??null,
  'dirty_preserved_after_first_scan'=>$dirtyAfterFirst!==0,
  'second_scan_state'=>$second['state']??null,
  'second_status'=>$finalStatus['state']??null,
  'dirty_after_second_scan'=>$dirtyAfterSecond,
  'queue_count_unchanged'=>$initialJobCount===count(MAD4B_SCP_Adaptive_Asset_Intelligence::queue()),
  'public_approved_before'=>$receiptBefore['approved_count']??null,
  'public_approved_after'=>$receiptAfter['approved_count']??null,
  'public_runtime'=>get_option('mad4b_perf_predictive_css_v2','off'),
  'current_discovery_digest'=>$finalStatus['published_graph_digest']??null,
];
$result['pass']=$result['first_status']==='refresh_queued'
  && $result['dirty_preserved_after_first_scan']
  && $result['second_status']==='observed'
  && $result['dirty_after_second_scan']===0
  && $result['queue_count_unchanged']
  && $result['public_approved_after']===0;
echo json_encode($result,JSON_UNESCAPED_SLASHES)."\n";
if(!$result['pass'])throw new RuntimeException('staging_concurrency_test_failed');
