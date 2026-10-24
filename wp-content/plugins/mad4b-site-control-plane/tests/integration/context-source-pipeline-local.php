<?php
/** Isolated Local Lab regression. Does not automatically approve documents. */
$_SERVER['REQUEST_URI']='/wp-json/mcp/mad4b-chatgpt';
require_once '/var/www/html/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) { echo "SKIP: local only\n"; exit(2); }
if ( ! class_exists( 'MAD4B_SCP_Context_Source_Pipeline' ) ) {
    require_once '/tmp/mad4b-wordpress-media-context.php';
    require_once '/tmp/mad4b-context-pipeline.php';
}
$fail=0; $pass=0;
$check=function($name,$ok)use(&$fail,&$pass){echo $name.': '.($ok?'PASS':'FAIL')."\n";$ok?$pass++:$fail++;};
wp_set_current_user(1);
$sources=MAD4B_SCP_Context_Authority::sources();$source_id='';
foreach($sources as $id=>$source)if(($source['provider']??'')==='wordpress_media'){$source_id=(string)$id;break;}
if(!$source_id){echo "SKIP: source not registered\n";exit(2);}
$plan=MAD4B_SCP_Context_Source_Pipeline::plan($source_id,['attachment_ids'=>[66145,66146,66147]]);
$check('plan_is_valid',is_array($plan)&&count($plan['changes']??[])===3);
if(!is_array($plan))exit(1);
$check('review_not_automatic',($plan['stages']['review']??'')==='pending'&&($plan['stages']['approve']??'')==='manual_only');
$bad=$plan;$bad['source_fingerprint']=str_repeat('0',64);
$check('source_tampering_denied',is_wp_error(MAD4B_SCP_Context_Source_Pipeline::sync($bad)));
$result=MAD4B_SCP_Context_Source_Pipeline::sync($plan);
$check('sync_three_assets',is_array($result)&&($result['synced']??0)===3);
$check('sync_no_auto_approval',is_array($result)&&($result['approval']??'')==='not_performed');
$check('unknown_source_denied',is_wp_error(MAD4B_SCP_Context_Source_Pipeline::plan('bad-source',['attachment_ids'=>[66145]])));
$check('duplicate_ids_deduplicated',is_array(MAD4B_SCP_Context_Source_Pipeline::plan($source_id,['attachment_ids'=>[66145,66145]])));
$check('oversized_batch_rejected',is_wp_error(MAD4B_SCP_Context_Source_Pipeline::plan($source_id,['attachment_ids'=>range(1,26)])));
$check('no_absence_mutation',empty($plan['absence_mutation_allowed']));
wp_set_current_user(0);
echo "SUMMARY pass=$pass fail=$fail\n";
exit($fail?1:0);
