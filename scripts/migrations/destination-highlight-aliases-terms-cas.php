<?php
// Seed explicit editorial alias rows for verified Destination terms.
// Dry-run by default; guarded write requires baseline backup and exact taxonomy.
defined('ABSPATH') || exit;
global $wpdb;
$raw=@file_get_contents((string)getenv('DEST_ALIAS_INPUT'));
$data=$raw?json_decode($raw,true):null;
if(!is_array($data)||($data['taxonomy']??'')!=='destinations'||empty($data['terms']))
 WP_CLI::error('Missing/invalid term-alias input');
$backDir=(string)getenv('DEST_ALIAS_TERM_BACKUP_DIR');
if($backDir===''||!is_dir($backDir)||!is_writable($backDir))WP_CLI::error('Nonpublic backup required');
$run=getenv('DEST_ALIAS_TERM_APPLY')==='yes';
$planned=[];
foreach($data['terms'] as $seed){
 $id=(int)($seed['id']??0);$expected=(string)($seed['name']??'');
 if($id<=0||$expected==='')WP_CLI::error('Bad seed term');
 $row=$wpdb->get_row($wpdb->prepare("SELECT t.name,tt.taxonomy FROM {$wpdb->terms} t
  JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE t.term_id=%d
  AND tt.taxonomy='destinations' LIMIT 1",$id),ARRAY_A);
 if(!$row||$row['name']!==$expected)WP_CLI::error('Term identity mismatch '.$id);
 $old=get_term_meta($id,'tour_highlight_aliases',true);
 if($old!==''&&$old!==false&&!is_array($old))WP_CLI::error('Unexpected alias encoding '.$id);
 $rows=(array)($seed['aliases']??[]);
 if(!$rows)WP_CLI::error('No aliases for '.$id);
 foreach($rows as $item){
  if(!is_array($item)||!in_array($item['tour_lang']??'', ['en','es','ar','fr','de','it'],true)
    || !is_string($item['tour_alias']??null) || mb_strlen(trim($item['tour_alias']),'UTF-8')<8)
    WP_CLI::error('Invalid alias row '.$id);
 }
 if(is_array($old)&&$old!==$rows)WP_CLI::error('Existing editorial aliases differ '.$id);
 $planned[]=['id'=>$id,'term'=>$expected,'old'=>$old,'new'=>$rows];
}
$manifest=$backDir.'/dest-alias-baseline-'.hash('sha256',$raw).'.json';
$backup=wp_json_encode(['data_sha256'=>hash('sha256',$raw),'baseline'=>$planned],
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if(!file_exists($manifest) && file_put_contents($manifest,$backup,LOCK_EX)===false)WP_CLI::error('Could not save baseline');
if(file_get_contents($manifest)!==$backup)WP_CLI::error('Baseline has changed');
@chmod($manifest,0600);
WP_CLI::log(wp_json_encode(['mode'=>$run?'apply':'dry-run','count'=>count($planned),
 'already'=>count(array_filter($planned,fn($r)=>$r['old']===$r['new']))],
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
if(!$run)return;
if(hash('sha256',$raw)!==(string)getenv('DEST_ALIAS_EXPECTED_DATA_SHA256'))WP_CLI::error('Seed SHA mismatch');
foreach($planned as $record){
 $id=$record['id'];
 if($record['old']===$record['new'])continue;
 if(!add_term_meta($id,'tour_highlight_aliases',$record['new'],true))WP_CLI::error('CAS alias insert failed '.$id);
 $actual=get_term_meta($id,'tour_highlight_aliases',true);
 if($actual!==$record['new'])WP_CLI::error('Alias readback mismatch '.$id);
 WP_CLI::log('ADDED editorial aliases '.$id);
}
WP_CLI::success('Alias source records reconciled');
