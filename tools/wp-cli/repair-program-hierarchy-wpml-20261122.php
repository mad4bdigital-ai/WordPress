<?php
defined('ABSPATH')||exit;
/**
 * MAD4B WPML Programs term-hierarchy structural repair only.
 * Safe to re-run. Does not translate names, term itineraries or Tour values.
 */
global $wpdb;
if(!current_user_can('manage_options'))throw new RuntimeException('master_admin_required');
$site=home_url();
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 throw new RuntimeException('unexpected_site');
$dry=getenv('MAD4B_APPLY_PROGRAM_PARENT')!=='yes';
$rootEn=2351;
$childEn=[2348,2349,2350];
$locales=$site==='https://wayfatours.com'?['es']:['es','fr','de','it','ar'];
$termInfo=static function(int $id)use($wpdb):array{
 $tt=$wpdb->get_row($wpdb->prepare("SELECT term_taxonomy_id,term_id,parent,taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy=%s",$id,'programs'),ARRAY_A);
 if(!$tt)throw new RuntimeException('program_term_taxonomy_unavailable_'.$id);
 $t=get_term($id,'programs');
 if(!$t instanceof WP_Term)throw new RuntimeException('program_term_unavailable_'.$id);
 $w=$wpdb->get_row($wpdb->prepare("SELECT trid,language_code,source_language_code FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d",'tax_programs',$tt['term_taxonomy_id']),ARRAY_A);
 if(!$w)throw new RuntimeException('wpml_program_term_link_missing_'.$id);
 return [
  'term_id'=>(int)$id,'tt_id'=>(int)$tt['term_taxonomy_id'],
  'parent'=>(int)$tt['parent'],'slug'=>(string)$t->slug,'name'=>(string)$t->name,
  'lang'=>(string)$w['language_code'],'trid'=>(int)$w['trid'],
  'source_lang'=>$w['source_language_code']
 ];
};
$root=$termInfo($rootEn);
if($root['lang']!=='en'||$root['parent']!==0)throw new RuntimeException('source_root_modified');
foreach($childEn as $en){
 $original=$termInfo($en);
 if($original['parent']!==$rootEn||$original['lang']!=='en')
   throw new RuntimeException('source_child_changed_'.$en);
}
$rows=[];$seen=[];
foreach($locales as $locale){
 $rootTr=(int)apply_filters('wpml_object_id',$rootEn,'programs',false,$locale);
 $rt=$termInfo($rootTr);
 if($rt['trid']!==$root['trid']||$rt['lang']!==$locale||$rt['parent']!==0)
   throw new RuntimeException('localized_root_mismatch_'.$locale);
 foreach($childEn as $en){
  $translated=(int)apply_filters('wpml_object_id',$en,'programs',false,$locale);
  $original=$termInfo($en);$t=$termInfo($translated);
  if($original['trid']!==$t['trid'] || $t['lang']!==$locale || $en===$translated ||
     !in_array($t['parent'],[0,$rootEn,$rootTr],true))
    throw new RuntimeException('localized_child_requires_manual_review_'.$locale.'_'.$en);
  if(isset($seen[$translated]))throw new RuntimeException('duplicate_translation_id_'.$translated);
  $seen[$translated]=1;
  $rows[]=[
    'source_id'=>$en,'localized_id'=>$translated,
    'language'=>$locale,'localized_name_unchanged'=>$t['name'],
    'localized_slug_unchanged'=>$t['slug'],
    'term_taxonomy_id'=>$t['tt_id'],
    'old_parent'=>$t['parent'],'expected_localized_parent'=>$rootTr,
    'edit_required'=>$t['parent']!==$rootTr
  ];
 }
}
$out=['contract'=>'mad4b.wpml.program-parent-hierarchy.v1',
 'site'=>$site,'dry_run'=>$dry,'checked'=>count($rows),
 'pending_count'=>count(array_filter($rows,static fn($x)=>$x['edit_required'])),
 'rows'=>$rows,'saved_tour_meta_modified'=>false,'program_names_translated'=>false];
if($dry){echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;return;}
$wpdb->query('START TRANSACTION');
$modified=0;
try{
 foreach($rows as $row){
  if(!$row['edit_required'])continue;
  $ok=$wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->term_taxonomy} SET parent=%d WHERE term_taxonomy_id=%d AND parent=%d AND taxonomy=%s",
    $row['expected_localized_parent'],$row['term_taxonomy_id'],$row['old_parent'],'programs'
  ));
  if($ok!==1)throw new RuntimeException('unexpected_concurrent_parent_mutation_'.$row['localized_id']);
  $modified++;
 }
 if($wpdb->query('COMMIT')===false)throw new RuntimeException('term_parent_commit_failed');
}catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
$ids=array_map(static fn($x)=>(int)$x['localized_id'],$rows);
clean_term_cache($ids,'programs');
foreach($rows as $row){
 $after=$termInfo((int)$row['localized_id']);
 if($after['parent']!==(int)$row['expected_localized_parent'] ||
    $after['name']!==$row['localized_name_unchanged']||
    $after['slug']!==$row['localized_slug_unchanged'])
  throw new RuntimeException('parent_hierarchy_readback_changed_name_or_slug_'.$row['localized_id']);
}
$out['applied_changes']=$modified;
$out['readback_verified']=true;
echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

