<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL runtime-projection-hotset-recommender: '.$m.(null===$v?'':' '.wp_json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};

$check(class_exists('MAD4B_SCP_Projection_Hotset_Recommender'),'Hot-set recommender runtime unavailable.');
$before=get_option(MAD4B_SCP_ChatGPT_Tool_Projection::OPTION,false);
$read='mad4b-ci/readonly-projection-fixture';
$privileged='mad4b/database-raw-query';
$check(function_exists('wp_has_ability')&&wp_has_ability($read),'Read recommendation fixture is unavailable.');
$check(wp_has_ability($privileged),'Privileged recommendation fixture is unavailable.');

for($i=0;$i<3;$i++){
 $r=MAD4B_SCP_Projection_Hotset_Recommender::record_usage($read,'fixed_dispatch');
 $check(!is_wp_error($r)&&empty($r['authorizing']),'Read usage telemetry failed or became authorizing.',$r);
}
for($i=0;$i<20;$i++){
 $r=MAD4B_SCP_Projection_Hotset_Recommender::record_usage($privileged,'fixed_dispatch');
 $check(!is_wp_error($r),'Privileged usage telemetry recording failed unexpectedly.',$r);
}
$recommend=MAD4B_SCP_Projection_Hotset_Recommender::recommend(5,24);
$check(!is_wp_error($recommend)&&!empty($recommend['read_only'])&&empty($recommend['mutation_performed'])&&empty($recommend['auto_apply'])&&'none'===$recommend['authority_effect'],'Recommendation boundary is not read-only/non-authorizing.',$recommend);
$check(in_array($read,$recommend['recommended_names'],true),'Observed read Ability was not ranked into bounded recommendations.',$recommend);
$check(!in_array($privileged,$recommend['recommended_names'],true),'High-telemetry privileged Ability entered recommendations.',$recommend);
foreach($recommend['recommended_abilities'] as $row)$check('read'===$row['lane']&&!empty($row['readonly']),'Recommendation contains a mutating/non-read lane.',$row);
$check(count($recommend['recommended_abilities'])<=5,'Recommendation quota was exceeded.',$recommend);

$status=MAD4B_SCP_ChatGPT_Tool_Projection::status(array('include_recommendations'=>true,'recommendation_quota'=>5,'recommendation_hours'=>24));
$check(!empty($status['hotset_recommendation_available'])&&!empty($status['hotset_recommendations']['read_only']),'Projection status did not expose opt-in recommendations.',$status);
$after=get_option(MAD4B_SCP_ChatGPT_Tool_Projection::OPTION,false);
$check($before===$after,'Recommendation/status mutated projection state.',array('before'=>$before,'after'=>$after));
echo "mad4b.projection-hotset-recommender.runtime.v1: PASS\n";
