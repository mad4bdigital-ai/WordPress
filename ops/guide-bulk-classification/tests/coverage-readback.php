<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Guide_Bulk_Classification_V1';
$all=$cls::inventory();
$counts=['all'=>count($all),'ready'=>0,'blocked'=>0,'high_sources'=>0,'high_days'=>0,'medium_days'=>0,'low_days'=>0,'unknown_days'=>0,'day_total'=>0];
$examples=[];$blocks=[];
foreach($all as $item){
  $data=$cls::detail($item['tour_id'],$item['program_id']);
  if(is_wp_error($data)){
    $counts['blocked']++;
    $blocks[$data->get_error_code()]=($blocks[$data->get_error_code()]??0)+1;
    continue;
  }
  $counts['ready']++;
  $counts['day_total']+=$data['day_count'];
  if($data['high_new_candidates']>0)$counts['high_sources']++;
  foreach($data['days'] as $row){
    $confidence=$row['confidence'];$counts[$confidence.'_days']=($counts[$confidence.'_days']??0)+1;
    if($confidence==='high'){
      $counts['high_days']++;
      if(count($examples)<12) $examples[]=[
        'source'=>$data['source_key'],'tour'=>$item['tour_id'],'day'=>$row['key'],
        'suggestion'=>$row['suggested_status'],'reason'=>$row['reason'],'evidence'=>$row['evidence']
      ];
    }
  }
}
echo 'COVERAGE '.wp_json_encode($counts,JSON_UNESCAPED_UNICODE)."\n";
echo 'BLOCKS '.wp_json_encode($blocks)."\n";
echo 'HIGH_EXAMPLES '.wp_json_encode($examples,JSON_UNESCAPED_UNICODE)."\n";
WP_CLI::success('BULK_COVERAGE_READBACK');
