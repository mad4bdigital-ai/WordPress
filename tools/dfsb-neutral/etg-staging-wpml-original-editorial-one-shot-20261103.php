<?php
/**
 * Original WPML Editorial Review + native status reconciliation, ETG Staging.
 * One exact language per invocation; no Elementor data or published content writes.
 */
if (!defined('ABSPATH') || wp_get_environment_type()!=='staging' ||
 rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com' ||
 get_current_user_id()!==26 ||
 !current_user_can('manage_options') ||
 !MAD4B_SCP_Policy::can_mutate())
 throw new RuntimeException('original_wpml_staging_reviewer_or_site_not_authorized');
$loc=(string)getenv('MAD4B_EXACT_EDITORIAL_LOCALE');
$manifest=[
 'ar'=>['target'=>40547,'job'=>'45076890-9fc9-410d-9dba-7a4231729647',
  'heading'=>'أهم المدن التي يجب زيارتها','empty'=>'لم يتم العثور على أي بيانات',
  'note'=>'Arabic headline accurately communicates leading cities to visit; the empty-results message preserves the source meaning.'],
 'de'=>['target'=>34597,'job'=>'57679eb4-1843-46b4-99a7-294e30e4a67e',
  'heading'=>'Top-Städte zum Besuchen','empty'=>'Es wurden keine Daten gefunden',
  'note'=>'German heading conveys top cities to visit in compact headline style; the missing-data message matches the original state.'],
 'es'=>['target'=>34605,'job'=>'720cb9e8-ec58-4e0c-8c8c-2540927a1580',
  'heading'=>'Principales ciudades para visitar','empty'=>'No se encontraron datos',
  'note'=>'Spanish headline and missing-data message preserve the English meanings and avoid visible untranslated phrases.'],
 'fr'=>['target'=>34599,'job'=>'4a302e58-4e06-42b3-9563-4b052d21a511',
  'heading'=>'Les meilleures villes à visiter','empty'=>"Aucune donnée n'a été trouvée",
  'note'=>'French headline and no-results wording are coherent, accurately translated, and present in the published Elementor document.'],
 'it'=>['target'=>39502,'job'=>'1c946b72-1cbb-4f5e-b671-484db19cf5e7',
  'heading'=>'Le migliori città da visitare','empty'=>'Nessun dato trovato',
  'note'=>'Italian headline accurately conveys recommended cities to visit; missing-data message is idiomatic and matches the source.']
];
if(!isset($manifest[$loc]))throw new RuntimeException('exact_language_required');
$m=$manifest[$loc];
$source=25909;$target=$m['target'];$jobId=$m['job'];
$site=MAD4B_SCP_Site_Profile::site_uuid();
if($site!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')
 throw new RuntimeException('site_identity_drift');
$sourceRaw=(string)get_post_meta($source,'_elementor_data',true);
$targetRaw=(string)get_post_meta($target,'_elementor_data',true);
$sourceHash=hash('sha256',$sourceRaw);
$targetHash=hash('sha256',$targetRaw);
if(get_post_type($target)!=='elementor_library'||
 get_post_status($target)!=='publish'||
 get_post_status($source)!=='publish'||
 (int)apply_filters('wpml_object_id',$source,'elementor_library',false,$loc)!==$target ||
 (int)apply_filters('wpml_element_trid',null,$target,'post_elementor_library')!==261795 ||
 (int)apply_filters('wpml_element_trid',null,$source,'post_elementor_library')!==261795)
 throw new RuntimeException('original_wpml_family_or_publication_invalid');
$family=(array)apply_filters('wpml_post_language_details',null,$target);
if(($family['language_code']??'')!==$loc)
 throw new RuntimeException('original_locale_mismatch');
$mapped=(array)get_option(MAD4B_WPML_ContentJob_Adapter::MAP,[]);
$key=hash('sha256',$site.'|elementor_library|'.$source.'|'.$loc);
if(($mapped[$key]['job_id']??'')!==$jobId||
 (int)($mapped[$key]['target_post_id']??0)!==$target)
 throw new RuntimeException('current_native_job_binding_invalid');
$currentJob=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobId]);
if(is_wp_error($currentJob)||($currentJob['job']['state']??'')!=='QUEUED'||
 (int)($currentJob['job']['current_artifact_id']??0)!==$target)
 throw new RuntimeException('original_content_job_no_longer_reviewable');
function mad4b_exact_wpml_translatable($raw){
 $tree=json_decode($raw,true);
 if(!is_array($tree))throw new RuntimeException('original_elementor_tree_invalid');
 $out=[];
 $walk=function($nodes)use(&$walk,&$out){
  foreach((array)$nodes as $node){
   if(!is_array($node))continue;
   $settings=(array)($node['settings']??[]);
   if(($node['widgetType']??'')==='heading' && isset($settings['title']))
    $out['heading'][]=(string)$settings['title'];
   if(($node['widgetType']??'')==='jet-listing-grid' && isset($settings['not_found_message']))
    $out['no_data'][]=(string)$settings['not_found_message'];
   $walk($node['elements']??[]);
  }
 };
 $walk($tree);
 return $out;
}
$english=mad4b_exact_wpml_translatable($sourceRaw);
$localized=mad4b_exact_wpml_translatable($targetRaw);
if(($english['heading']??[])!==['Top Cities to visit']||
 ($english['no_data']??[])!==['No data was found']||
 ($localized['heading']??[])!==[$m['heading']]||
 ($localized['no_data']??[])!==[$m['empty']])
 throw new RuntimeException('editorial_original_two_fields_changed');
$final=get_option('mad4b_cj_structural_final_browser_'.hash('sha256',$site.'|'.$jobId),[]);
$normalized=hash('sha256',wp_json_encode(json_decode($targetRaw,true),
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
if(($final['state']??'')!=='POSTWRITE_BROWSER_VERIFIED'||
 ($final['normalized_document_sha256']??'')!==$normalized||
 ($final['target_post_id']??0)!==$target ||
 empty($final['original_cwa_receipt_sha256']))
 throw new RuntimeException('real_original_structural_postwrite_unverified');
$browser=file('/tmp/mad4b-wpml-editorial-browser.jsonl',
 FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$matched=[];
foreach((array)$browser as $json){
 $row=json_decode($json,true);
 if(($row['locale']??'')!==$loc)continue;
 $matched[]=$row;
}
if(count($matched)!==1)throw new RuntimeException('real_browser_evidence_ambiguous');
$browser=$matched[0];$when=strtotime((string)($browser['captured_at']??''));
$html=['ar'=>'ar','de'=>'de-DE','es'=>'es-ES','fr'=>'fr-FR','it'=>'it-IT'];
if(($browser['contract']??'')!=='mad4b.wpml.editorial-browser-readback.v1'||
 ($browser['target_post_id']??0)!==$target||
 ($browser['status']??0)!==200||
 ($browser['html_lang']??'')!==$html[$loc]||
 ($browser['template_present']??false)!==true ||
 ($browser['heading_texts']??[])!==[$m['heading']]||
 ($browser['headings_count']??0)!==1||
 ($browser['anchor_count']??0)!==1||
 !empty($browser['script_errors'])||
 !$when||$when<time()-1800||
 !MAD4B_SCP_Central_Write_Acceptance::sha($browser['screenshot_sha256']??null))
 throw new RuntimeException('native_live_editorial_browser_not_current');
global $wpdb;
$translation=$wpdb->get_row($wpdb->prepare(
 "SELECT t.translation_id,t.trid,t.element_id,t.language_code,t.source_language_code,
 s.rid,s.status,s.needs_update,s.md5,s.translator_id,s.translation_service
 FROM {$wpdb->prefix}icl_translations t
 JOIN {$wpdb->prefix}icl_translation_status s ON s.translation_id=t.translation_id
 WHERE t.element_type=%s AND t.element_id=%d LIMIT 1",
 'post_elementor_library',$target),ARRAY_A);
if(!$translation || (int)$translation['trid']!==261795 ||
 $translation['language_code']!==$loc||
 $translation['source_language_code']!=='en' ||
 (int)$translation['status']!==ICL_TM_COMPLETE ||
 (int)$translation['needs_update']!==1 ||
 $translation['translation_service']!=='local')
 throw new RuntimeException('original_wpml_completed_update_missing_or_changed');
$rid=(int)$translation['rid'];
$nativeJobs=$wpdb->get_results($wpdb->prepare(
 "SELECT job_id,translated FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d",$rid),ARRAY_A);
if(!$nativeJobs ||
 count(array_filter($nativeJobs,static fn($j)=>(int)$j['translated']!==1))>0)
 throw new RuntimeException('native_wpml_translation_job_not_finished');
$fields=$wpdb->get_results($wpdb->prepare(
 "SELECT field_type,field_format,field_translate,field_finished,field_data,field_data_translated
 FROM {$wpdb->prefix}icl_translate WHERE job_id=%d",max(array_column($nativeJobs,'job_id'))),ARRAY_A);
if(count($fields)<4)throw new RuntimeException('native_translation_package_incomplete');
$package=[];
foreach($fields as $field){
 if((int)$field['field_translate']!==1)continue;
 $type=(string)$field['field_type'];
 if(!in_array($type,['title','URL','excerpt','package-string-101-17895','package-string-101-17896'],true))
  throw new RuntimeException('unreviewed_native_wpml_package_field_'.$type);
 $decode=static function($s) {
  $value=base64_decode((string)$s,true);
  if($value===false)return null;
  $plain=@gzuncompress($value);
  return $plain===false?$value:$plain;
 };
 $a=$decode($field['field_data']);$b=$decode($field['field_data_translated']);
 if($a===null||$b===null)throw new RuntimeException('native_translation_package_decode_failed');
 if(in_array($type,['package-string-101-17895','package-string-101-17896'],true)){
  $expected=$type==='package-string-101-17895'?
   ['Top Cities to visit',$m['heading']]:['No data was found',$m['empty']];
  if([(string)$a,(string)$b]!==$expected || (int)$field['field_finished']!==1)
   throw new RuntimeException('native_wpml_package_semantics_mismatch_'.$type);
 }
 if($type==='title' && ((string)$a!=='Home Sub-destination: cities Section' ||
    (string)$b!=='Home Sub-destination: cities Section'))
  throw new RuntimeException('admin_only_native_template_title_changed');
 if($type==='excerpt' && ((string)$a!==''||(string)$b!==''))
  throw new RuntimeException('native_excerpt_has_unreviewed_content');
 $package[$type]=hash('sha256',$type.'|'.$a.'|'.$b);
}
if(!isset($package['package-string-101-17895'],$package['package-string-101-17896'],$package['title']))
 throw new RuntimeException('native_translatable_fields_missing');
$md5=apply_filters('wpml_tm_element_md5',get_post($source));
if(!is_string($md5)||!preg_match('/^[a-f0-9]{32}$/D',$md5))
 throw new RuntimeException('source_wpml_md5_provider_missing');
$reviewKey='mad4b_wpml_editorial_receipt_'.hash('sha256',$site.'|'.$jobId);
if(get_option($reviewKey,null)!==null)
 throw new RuntimeException('editorial_review_already_attempted_no_blind_retry');
$preflight=['contract'=>'mad4b.wpml.original-editorial-review.v1',
 'site_uuid'=>$site,'source_post_id'=>$source,
 'target_post_id'=>$target,'locale'=>$loc,'content_job_id'=>$jobId,
 'source_elementor_sha256'=>$sourceHash,'target_elementor_sha256'=>$targetHash,
 'wpml_translation_id'=>(int)$translation['translation_id'],
 'wpml_rid'=>$rid,
 'wpml_source_md5'=>$md5,'native_translated_jobs'=>array_map('intval',array_column($nativeJobs,'job_id')),
 'original_package_review_sha256'=>hash('sha256',wp_json_encode($package)),
 'visible_heading'=>$m['heading'],'empty_results_text'=>$m['empty'],
 'browser_screenshot_sha256'=>$browser['screenshot_sha256'],
 'original_structural_cwa_receipt'=>$final['original_cwa_receipt_sha256'],
 'review_method'=>'AI_ASSISTED_EDITORIAL_VERIFICATION',
 'editorial_scope'=>'exact_two_user_facing_elementor_strings',
 'review_note'=>$m['note'],'wordPress_operator_user_id'=>get_current_user_id(),
 'original_wpml_before_needs_update'=>(int)$translation['needs_update'],
 'state'=>'IN_PROGRESS','created_at'=>gmdate('c')];
if(!add_option($reviewKey,$preflight,'',false))
 throw new RuntimeException('editorial_intent_reservation_failed');
$tm=$GLOBALS['iclTranslationManagement']??null;
if(!is_object($tm)||!method_exists($tm,'update_translation_status'))
 throw new RuntimeException('original_wpml_translation_api_unavailable');
$updated=$tm->update_translation_status([
 'translation_id'=>(int)$translation['translation_id'],
 'status'=>ICL_TM_COMPLETE,
 'needs_update'=>0,
 'md5'=>$md5,
 'translator_id'=>(int)$translation['translator_id'],
 'translation_service'=>'local',
],$rid);
$now=$wpdb->get_row($wpdb->prepare(
 "SELECT translation_id,rid,status,needs_update,md5,translator_id
 FROM {$wpdb->prefix}icl_translation_status WHERE rid=%d",$rid),ARRAY_A);
$afterRaw=(string)get_post_meta($target,'_elementor_data',true);
$verified=is_array($updated) &&
 (int)($updated[0]??0)===$rid &&
 $now&&(int)$now['translation_id']===(int)$translation['translation_id'] &&
 (int)$now['status']===ICL_TM_COMPLETE &&
 (int)$now['needs_update']===0 &&
 (string)$now['md5']===$md5 &&
 (int)$now['translator_id']===(int)$translation['translator_id'] &&
 hash_equals($targetHash,hash('sha256',$afterRaw));
$receipt=$preflight;
$receipt['state']=$verified?'ORIGINAL_WPML_EDITORIAL_REVIEW_VERIFIED':'ORIGINAL_WPML_RECONCILIATION_UNKNOWN';
$receipt['original_wpml_after_needs_update']=(int)($now['needs_update']??-1);
$receipt['native_translation_status']=isset($now['status'])?(int)$now['status']:null;
$receipt['native_api_used']='TranslationManagement::update_translation_status';
$receipt['content_changed']=false;
$receipt['manual_human_editor_attestation_claimed']=false;
$receipt['review_reference']='wpml-editorial-'.substr(hash('sha256',
 $site.'|'.$jobId.'|'.$targetHash.'|'.$md5.'|'.wp_json_encode($package)),0,40);
$receipt['verified_at']=gmdate('c');
$receipt['evidence_sha256']=hash('sha256',wp_json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
update_option($reviewKey,$receipt,false);
if(get_option($reviewKey,null)!==$receipt)
 throw new RuntimeException('editorial_receipt_native_readback_failed');
echo wp_json_encode(['locale'=>$loc,'job_id'=>$jobId,'target'=>$target,
 'state'=>$receipt['state'],'old_needs_update'=>1,
 'new_needs_update'=>$receipt['original_wpml_after_needs_update'],
 'original_wpml_status'=>$receipt['native_translation_status'],
 'review_reference'=>$receipt['review_reference'],'evidence_sha256'=>$receipt['evidence_sha256'],
 'post_changed'=>false,'legacy_outbox_unchanged'=>true],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(!$verified)throw new RuntimeException('original_wpml_api_readback_uncertain_no_replay');