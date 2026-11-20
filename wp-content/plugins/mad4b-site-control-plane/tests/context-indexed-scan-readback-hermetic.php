<?php
/** Hermetic historical scan readback: no WordPress, DB, network, OAuth or writes. */
define('ABSPATH', __DIR__.'/');
require dirname(__DIR__).'/includes/class-mad4b-scp-context-authority.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-context-admin-ui.php';
function chk($value,$label) {
 if (!$value){fwrite(STDERR,"FAIL: $label\n");exit(1);}
 echo "$label: PASS\n";
}
$id=str_repeat('a',64);
$aid=str_repeat('b',64);
$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$brand=str_repeat('c',32);
$gen=str_repeat('d',64);
$hash=str_repeat('e',64);
$now=1795000000;
$source=array(
 'source_id'=>$id,'site_uuid'=>$uuid,'brand_id'=>$brand,
 'provider'=>'google_drive','mode'=>'governed','status'=>'partial_scan',
 'last_scan_complete'=>false, 'last_scan_generation'=>$gen,
 'last_scan_truncation_reasons'=>array('file_normalization_deferred'),
 'asset_count'=>1
);
$progress=array(
 'contract'=>'mad4b.scan-ui-progress.v1','source_id'=>$id,
 'site_uuid'=>$uuid,'readonly_evidence'=>true,
 'registry_mutation_performed'=>false,'inventory_complete'=>true,
 'verified_files'=>1,'deferred_files'=>4,'queued_folders'=>0,
 'pending_files'=>0,'checkpoint_revision'=>12,'expires_at'=>$now+1000
);
$site=array('site_uuid'=>$uuid,'configured'=>true,'origin_match'=>true,'environment_match'=>true);
$asset=array(
 'asset_id'=>$aid,'source_id'=>$id,'site_uuid'=>$uuid,'brand_id'=>$brand,
 'title'=>'Transport configuration snapshot','path'=>'Brand Core/Brand Strategy',
 'category'=>'brand_strategy','authority_class'=>'brand_authority','required'=>true,
 'classification_source'=>'automatic_heuristic','content_complete'=>true,'content_hash'=>$hash
);
$summary=array('source_id'=>$id,'scan_generation'=>$gen,
 'provider'=>'google_drive','mode'=>'governed','scan_complete'=>false,
 'observed_asset_count'=>1,'represented_asset_count'=>1,
 'truncation_reasons'=>array('file_normalization_deferred'));
$receipt=array('status'=>'partial','summary_json'=>json_encode($summary));
$run=static function($s,$p,$st,$audit,$assets)use($now){
 return MAD4B_SCP_Context_Admin_UI::indexed_scan_evidence($s,$p,$st,$audit,$assets,15,$now);
};
$r=$run($source,$progress,$site,array($receipt),array($aid=>$asset));
chk(is_array($r),'valid_historic_index');
chk($r['indexed']===1 && $r['deferred']===4 && $r['checkpoint_revision']===12,'counts_exact');
chk($r['historical_only']===true && $r['current_google_files_verified']===false
 && $r['registry_mutation_performed']===false && $r['review_modified']===false
 && $r['source_rescanned']===false && $r['authorizing']===false,
 'never_grant_freshness_or_authority');
chk($r['stale_classification']===1,'stale_automatic_classification_visible');
$manual=$asset;$manual['classification_source']='human';
chk($run($source,$progress,$site,array($receipt),array($aid=>$manual))['stale_classification']===0,
 'human_classification_not_stale_auto');
$cases=0;
$reject=static function($label,$s,$p,$st,$audit,$assets)use($run,&$cases) {
 chk($run($s,$p,$st,$audit,$assets)===false,$label.'_denied');++$cases;
};
$changed=$source;$changed['site_uuid']=str_repeat('0',36);
$reject('foreign_source_site',$changed,$progress,$site,array($receipt),array($aid=>$asset));
$changed=$progress;$changed['site_uuid']=str_repeat('0',36);
$reject('foreign_checkpoint_site',$source,$changed,$site,array($receipt),array($aid=>$asset));
$changed=$site;$changed['origin_match']=false;
$reject('origin_drift',$source,$progress,$changed,array($receipt),array($aid=>$asset));
$changed=$site;$changed['environment_match']=false;
$reject('environment_drift',$source,$progress,$changed,array($receipt),array($aid=>$asset));
$changed=$progress;$changed['expires_at']=$now;
$reject('expired_checkpoint',$source,$changed,$site,array($receipt),array($aid=>$asset));
$changed=$progress;$changed['readonly_evidence']=false;
$reject('non_readonly_checkpoint',$source,$changed,$site,array($receipt),array($aid=>$asset));
$changed=$progress;$changed['registry_mutation_performed']=true;
$reject('mutating_checkpoint',$source,$changed,$site,array($receipt),array($aid=>$asset));
$changed=$progress;$changed['inventory_complete']=false;
$reject('unfinished_inventory',$source,$changed,$site,array($receipt),array($aid=>$asset));
$changed=$progress;$changed['queued_folders']=1;
$reject('queued_folders',$source,$changed,$site,array($receipt),array($aid=>$asset));
$changed=$progress;$changed['pending_files']=1;
$reject('pending_files',$source,$changed,$site,array($receipt),array($aid=>$asset));
$changed=$progress;$changed['verified_files']=2;
$reject('count_mismatch',$source,$changed,$site,array($receipt),array($aid=>$asset));
$changed=$source;$changed['status']='ready';
$reject('not_partial',$changed,$progress,$site,array($receipt),array($aid=>$asset));
$changed=$source;$changed['last_scan_complete']=true;
$reject('completed_not_partial',$changed,$progress,$site,array($receipt),array($aid=>$asset));
$changed=$source;$changed['last_scan_generation']=str_repeat('f',64);
$reject('new_generation',$changed,$progress,$site,array($receipt),array($aid=>$asset));
$changed=$source;$changed['provider']='wordpress_media';
$reject('other_provider',$changed,$progress,$site,array($receipt),array($aid=>$asset));
$changed=$source;$changed['mode']='task_attachment';
$reject('task_mode',$changed,$progress,$site,array($receipt),array($aid=>$asset));
$changed=$source;$changed['last_scan_truncation_reasons']=array('scan_page_pending');
$reject('wrong_truncation',$changed,$progress,$site,array($receipt),array($aid=>$asset));
$changed=$asset;$changed['site_uuid']=str_repeat('0',36);
$reject('foreign_asset_site',$source,$progress,$site,array($receipt),array($aid=>$changed));
$changed=$asset;$changed['brand_id']=str_repeat('0',32);
$reject('foreign_asset_brand',$source,$progress,$site,array($receipt),array($aid=>$changed));
$changed=$asset;$changed['content_hash']='invalid';
$reject('invalid_content_sha',$source,$progress,$site,array($receipt),array($aid=>$changed));
$changed=$asset;$changed['content_complete']=false;
$reject('incomplete_original_text',$source,$progress,$site,array($receipt),array($aid=>$changed));
$changed=$summary;$changed['represented_asset_count']=2;
$reject('mismatched_audit_asset_count',$source,$progress,$site,array(array('status'=>'partial','summary_json'=>json_encode($changed))),array($aid=>$asset));
$changed=$summary;$changed['scan_generation']=str_repeat('f',64);
$reject('outdated_audit_generation',$source,$progress,$site,array(array('status'=>'partial','summary_json'=>json_encode($changed))),array($aid=>$asset));
$reject('no_audit',$source,$progress,$site,array(),array($aid=>$asset));
$reject('newer_failed_receipt',$source,$progress,$site,array(
 array('status'=>'error','summary_json'=>json_encode($summary)),$receipt
),array($aid=>$asset));
$reject('newer_wrong_generation_receipt',$source,$progress,$site,array(
 array('status'=>'partial','summary_json'=>json_encode(array_merge($summary,array('scan_generation'=>str_repeat('f',64))))),$receipt
),array($aid=>$asset));
$reject('assets_missing',$source,$progress,$site,array($receipt),array());
$otherSource=$summary;$otherSource['source_id']=str_repeat('f',64);
chk(is_array($run($source,$progress,$site,array(
 array('status'=>'partial','summary_json'=>json_encode($otherSource)),$receipt
),array($aid=>$asset))),'skip_other_source_audit_without_leak');
echo 'CONTEXT_INDEXED_SCAN_READBACK_HERMETIC: PASS ('.($cases+6).' assertions)'.PHP_EOL;
