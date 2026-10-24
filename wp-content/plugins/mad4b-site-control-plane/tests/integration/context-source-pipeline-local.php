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
$unsafe=MAD4B_SCP_Context_Source_Pipeline::sync($plan);
$check('partial_media_upsert_denied',is_wp_error($unsafe)&&$unsafe->get_error_code()==='mad4b_pipeline_media_use_complete_scan');
$before=MAD4B_SCP_Context_Authority::assets();
$inventory=MAD4B_SCP_WordPress_Media_Context::inventory(['application/pdf','text/plain','text/markdown'],$source_id);
$check('complete_inventory_verified',is_array($inventory)&&true===($inventory['content_complete']??false)&&true===($inventory['complete']??false));
$badtype=MAD4B_SCP_WordPress_Media_Context::inventory(['application/x-unexpected'],$source_id);
$check('unknown_mime_denied',is_wp_error($badtype));
$scan=MAD4B_SCP_Context_Source_Pipeline::scan_complete($source_id,['mime_types'=>['application/pdf','text/plain','text/markdown']]);
$check('complete_scan_applied',is_array($scan)&&!empty($scan['scan_complete']));
$after=MAD4B_SCP_Context_Authority::assets();
$preserved=true;
foreach([66145,66146,66147] as $file_id){
    $key=hash('sha256',$source_id.'|'.$file_id);
    if(!isset($before[$key],$after[$key])||!$after[$key]['content_complete']||($before[$key]['content_hash']??'')!==($after[$key]['content_hash']??'')||($before[$key]['category']??'')!==($after[$key]['category']??'')||($before[$key]['review_status']??'')!==($after[$key]['review_status']??''))$preserved=false;
}
$check('mandatory_documents_preserved',$preserved);
$check('scan_no_auto_approval',isset($after[hash('sha256',$source_id.'|66145')])&&($after[hash('sha256',$source_id.'|66145')]['review_status']??'')==='unreviewed');
$check('unknown_source_denied',is_wp_error(MAD4B_SCP_Context_Source_Pipeline::plan('bad-source',['attachment_ids'=>[66145]])));
$check('duplicate_ids_deduplicated',is_array(MAD4B_SCP_Context_Source_Pipeline::plan($source_id,['attachment_ids'=>[66145,66145]])));
$check('oversized_batch_rejected',is_wp_error(MAD4B_SCP_Context_Source_Pipeline::plan($source_id,['attachment_ids'=>range(1,26)])));
$check('no_absence_mutation',empty($plan['absence_mutation_allowed']));
$missingScope = MAD4B_SCP_WordPress_Media_Context::inventory([], $source_id);
$check('empty_mime_scope_denied', is_wp_error($missingScope));
$reflect = new ReflectionMethod('MAD4B_SCP_Context_Authority', 'scan_includes_absence');
$check('scoped_pdf_missing_eligible', $reflect->invoke(null, ['scoped_inventory'=>true, 'mime_types'=>['application/pdf']], ['mime_type'=>'application/pdf']) === true);
$check('scoped_txt_excluded', $reflect->invoke(null, ['scoped_inventory'=>true, 'mime_types'=>['application/pdf']], ['mime_type'=>'text/plain']) === false);
$check('unscoped_absence_eligible', $reflect->invoke(null, [], ['mime_type'=>'text/plain']) === true);
ob_start();
MAD4B_SCP_WordPress_Media_Context::page();
$page = ob_get_clean();
$check('media_admin_mime_controls', strpos($page, 'mime_types[]') !== false && strpos($page, '_wpnonce') !== false);
$check('media_admin_no_id_list', strpos($page, 'attachment_ids') === false);

wp_set_current_user(0);
echo "SUMMARY pass=$pass fail=$fail\n";
exit($fail?1:0);
