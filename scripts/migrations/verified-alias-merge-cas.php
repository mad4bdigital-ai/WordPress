<?php
/** Evidence-aware append-only updater for native JetEngine term-alias repeater.
 * CAS by termmeta serialized value; no forced language, tour-taxonomy or copy edits.
 * Dry run by default; requires baseline file, input hash for apply.
 */
defined('ABSPATH') || exit;
global $wpdb;
$json=file_get_contents((string)getenv('JD_ALIAS_EVIDENCE_INPUT'));
$data=$json?json_decode($json,true):null;
if(!is_array($data)||($data['taxonomy']??'')!=='destinations'||!is_array($data['terms']??null))
 WP_CLI::error('Bad evidence payload');
$backupDir=(string)getenv('JD_ALIAS_EVIDENCE_BACKUP');
if(!is_dir($backupDir)||!is_writable($backupDir))WP_CLI::error('Nonpublic backup directory unavailable');
$apply=getenv('JD_ALIAS_EVIDENCE_APPLY')==='yes';
$sha=hash('sha256',$json);
if($apply&&((string)getenv('JD_ALIAS_EVIDENCE_EXPECTED_SHA256')!==$sha))
 WP_CLI::error('Evidence input SHA not approved');
if(!class_exists('MAD4B_Tour_Highlights_Presentation_V1',false))WP_CLI::error('Original Highlights renderer not loaded');
$planned=[];
$claimed=[];
$termMeta=$wpdb->termmeta;
foreach($data['terms'] as $seed){
 $id=(int)($seed['id']??0);
 $label=(string)($seed['name']??'');
 $tax=$wpdb->get_row($wpdb->prepare("SELECT t.name,tt.term_taxonomy_id FROM {$wpdb->terms} t
 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
 WHERE t.term_id=%d AND tt.taxonomy='destinations' LIMIT 1",$id),ARRAY_A);
 if(!$tax||$tax['name']!==$label)WP_CLI::error('Term metadata changed or missing '.$id);
 $locale=(string)$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations
 WHERE element_id=%d AND element_type='tax_destinations' LIMIT 1",(int)$tax['term_taxonomy_id']));
 if(!in_array($locale,['en','es','it','fr','de','ar'],true))WP_CLI::error('Translated term identity missing '.$id);
 $metaRows=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM $termMeta
 WHERE term_id=%d AND meta_key='tour_highlight_aliases' ORDER BY meta_id",$id),ARRAY_A);
 if(count($metaRows)>1)WP_CLI::error('Duplicate alias meta records '.$id);
 $original=$metaRows?(string)$metaRows[0]['meta_value']:null;
 $old=$metaRows?maybe_unserialize($original):[];
 if(!is_array($old))WP_CLI::error('Unexpected alias data encoding '.$id);
 $new=$old;
 foreach((array)($seed['aliases']??[]) as $entry){
   $lang=(string)($entry['tour_lang']??'');
   $alias=trim((string)($entry['tour_alias']??''));
   $eid=(int)($entry['evidence']['tour_id']??0);
   $step=(int)($entry['evidence']['highlight']??0);
   if($lang!==$locale||mb_strlen($alias,'UTF-8')<8||mb_strlen($alias,'UTF-8')>120
      || !preg_match('/\p{L}+\s+\p{L}+/u',$alias)||$step<=0||$step>60)
      WP_CLI::error('Invalid alias locale/shape for term '.$id);
   $nameKey=mb_strtolower($alias,'UTF-8');
   if(isset($claimed[$lang.'|'.$nameKey])&&$claimed[$lang.'|'.$nameKey]!==$id)
      WP_CLI::error('Evidence alias claimed by multiple terms: '.$alias);
   $claimed[$lang.'|'.$nameKey]=$id;
   $post=$wpdb->get_row($wpdb->prepare("SELECT ID,post_status FROM {$wpdb->posts}
     WHERE ID=%d AND post_type='tours-and-activities' LIMIT 1",$eid),ARRAY_A);
   if(!$post||$post['post_status']!=='publish')WP_CLI::error('Evidence tour missing or unpublished '.$eid);
   $tourLang=(string)$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations
      WHERE element_id=%d AND element_type='post_tours-and-activities' LIMIT 1",$eid));
   if($tourLang!==$lang)WP_CLI::error('Cross-language evidence not permitted '.$eid.' '.$id);
   $linked=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*)
      FROM {$wpdb->term_relationships} rel
      WHERE rel.object_id=%d AND rel.term_taxonomy_id=%d",$eid,(int)$tax['term_taxonomy_id']));
   if($linked!==1)WP_CLI::error('Evidence term not linked to original tour '.$id.' '.$eid);
   $html=(string)get_post_meta($eid,'tour_highlights_content',true);
   $cards=MAD4B_Tour_Highlights_Presentation_V1::cards($html);
   if(!isset($cards[$step-1]))WP_CLI::error('Evidence highlight index not found '.$eid.' '.$step);
   $card=$cards[$step-1];
   $text=html_entity_decode(wp_strip_all_tags(($card['title']??'').' '.($card['body']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
   if(mb_stripos($text,$alias,0,'UTF-8')===false)
      WP_CLI::error('Alias text absent from original editorial Highlight '.$id.' '.$eid.' step '.$step);
   $already=false;
   foreach($new as $prior){
      if(!is_array($prior))WP_CLI::error('Existing alias repeater malformed '.$id);
      if(($prior['tour_lang']??'')===$lang &&
          mb_strtolower(trim((string)($prior['tour_alias']??'')),'UTF-8')===$nameKey){$already=true;break;}
   }
   if(!$already)$new[]=['tour_lang'=>$lang,'tour_alias'=>$alias];
 }
 $planned[]=['id'=>$id,'name'=>$label,'lang'=>$locale,'old'=>$old,'new'=>$new,
   'meta_id'=>$metaRows?(int)$metaRows[0]['meta_id']:null,'original'=>$original];
}
$payload=['input_sha256'=>$sha,'site'=>site_url(),'baseline'=>$planned];
$backupFile=$backupDir.'/verified-merged-term-aliases-'.$sha.'.json';
$bytes=wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
if(!file_exists($backupFile)){
  if(file_put_contents($backupFile,$bytes,LOCK_EX)===false)WP_CLI::error('Cannot persist backup');
  @chmod($backupFile,0600);
}
if(file_get_contents($backupFile)!==$bytes)WP_CLI::error('Baseline drift since dry-run');
$inserted=0;
foreach($planned as $record)if($record['new']!==$record['old'])$inserted+=count($record['new'])-count($record['old']);
WP_CLI::log(wp_json_encode(['mode'=>$apply?'apply':'dry-run','terms'=>count($planned),
   'new_alias_rows'=>$inserted,'backed_up'=>basename($backupFile)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
if(!$apply)return;
foreach($planned as $record){
 if($record['new']===$record['old'])continue;
 $id=$record['id'];
 if($record['meta_id']){
    $updated=$wpdb->query($wpdb->prepare("UPDATE $termMeta SET meta_value=%s
      WHERE meta_id=%d AND BINARY meta_value=%s",maybe_serialize($record['new']),
      $record['meta_id'],$record['original']));
    if($updated!==1)WP_CLI::error('Alias CAS update failed '.$id);
 }else{
    if(!add_term_meta($id,'tour_highlight_aliases',$record['new'],true))
      WP_CLI::error('Alias unique insert failed '.$id);
 }
 wp_cache_delete($id,'term_meta');
 $actual=get_term_meta($id,'tour_highlight_aliases',true);
 if($actual!==$record['new'])WP_CLI::error('Alias independent readback failed '.$id);
 WP_CLI::log('ALIAS_APPENDED '.$id.' '.(count($record['new'])-count($record['old'])));
}
WP_CLI::success('Verified alias merge complete');
