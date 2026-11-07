<?php
if(!defined('WP_CLI') || !WP_CLI) {echo "CLI_ONLY\n"; return;}
global $wpdb;
$pages=$wpdb->prefix.'mad4b_pd_pages';
$edges=$wpdb->prefix.'mad4b_pd_edges';
$one=hash('sha256','pd-qa-unrelated-20261107-a');
$two=hash('sha256','pd-qa-related-20261107-b');
$three=hash('sha256','pd-qa-query-20261107-c');
$dep1='post:99999001';
$dep2='post:99999002';
$qdep='query_pt:mad4b_page_dep_qa_only_20261107';
$now=gmdate('Y-m-d H:i:s');
$expires=gmdate('Y-m-d H:i:s',time()+86400);
$pre=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pages WHERE page_hash IN (%s,%s,%s)",$one,$two,$three));
if($pre!==0){echo 'QA_KEY_COLLISION';return;}
$wpdb->query('START TRANSACTION');
$ok=true;
try {
 foreach([[$one,'/qa-unrelated/','page','observed_partial'],[$two,'/qa-related/','page','observed_partial'],[$three,'/_mad4b_query/qa','query','complete']] as [$id,$path,$kind,$coverage]){
   $n=$wpdb->insert($pages,['page_hash'=>$id,'page_path'=>$path,'language'=>'en',
      'device'=>'desktop','node_kind'=>$kind,'epoch'=>1,'hit_count'=>1,
      'coverage'=>$coverage,'updated_at'=>$now,'expires_at'=>$expires]);
   if($n!==1)throw new Exception('INSERT_PAGE_FAILED');
 }
 foreach([[$one,$dep2],[$two,$dep1],[$three,$qdep]] as [$id,$dep]){
   $n=$wpdb->insert($edges,['page_hash'=>$id,'dep_hash'=>hash('sha256',$dep),
      'dep_key'=>$dep,'expires_at'=>$expires]);
   if($n!==1)throw new Exception('INSERT_EDGE_FAILED');
 }
 // Emulate an already cached old epoch in persistent Redis, then prove
 // invalidate() removes that exact scoped key before reading the fresh SQL.
 $group='mad4b-page-dependency-v1';
 wp_cache_set('epoch-'.$two,1,$group,300);
 wp_cache_set('epoch-'.$three,1,$group,300);
 $receipt=MAD4B_Page_Dependency_Cache::invalidate([$dep1,$qdep],'qa_test');
 $redisEpochB=MAD4B_Page_Dependency_Cache::epoch($two);
 $redisEpochC=MAD4B_Page_Dependency_Cache::epoch($three);
 $redisEpochA=MAD4B_Page_Dependency_Cache::epoch($one);
 $ea=(int)$wpdb->get_var($wpdb->prepare("SELECT epoch FROM $pages WHERE page_hash=%s",$one));
 $eb=(int)$wpdb->get_var($wpdb->prepare("SELECT epoch FROM $pages WHERE page_hash=%s",$two));
 $ec=(int)$wpdb->get_var($wpdb->prepare("SELECT epoch FROM $pages WHERE page_hash=%s",$three));
 $ok=$receipt['affected']===2 && $ea===1 && $eb===2 && $ec===2
   && $redisEpochA===1 && $redisEpochB===2 && $redisEpochC===2
   && $receipt['fallback']===true;
 echo wp_json_encode(['test'=>'reverse-index-selective-qa','pass'=>$ok,
  'affected'=>$receipt['affected'],'unrelated_page_epoch'=>$ea,
  'related_page_epoch'=>$eb,'query_epoch'=>$ec,
  'unknown_fallback'=>$receipt['fallback'],
  'external_object_cache'=>wp_using_ext_object_cache(),
  'redis_epoch_unrelated'=>$redisEpochA,
  'redis_epoch_related_page'=>$redisEpochB,
  'redis_epoch_query'=>$redisEpochC,
  'real_content_modified'=>false],JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $t){$ok=false;echo 'ERROR='.get_class($t).':'.$t->getMessage()."\n";}
$wpdb->query('ROLLBACK');
foreach([$one,$two,$three] as $k){
 wp_cache_delete('epoch-'.$k,'mad4b-page-dependency-v1');
 wp_cache_delete('persist-throttle-'.$k,'mad4b-page-dependency-v1');
}
wp_cache_delete(MAD4B_Page_Dependency_Cache::RECEIPTS,'options');
wp_cache_delete('alloptions','options');
$after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pages WHERE page_hash IN (%s,%s,%s)",$one,$two,$three));
echo wp_json_encode(['rollback_clean'=>$after===0,'qa_rows_after'=>$after],JSON_UNESCAPED_SLASHES)."\n";
if(!$ok || $after!==0)WP_CLI::error('Reverse-index selective QA failed');
