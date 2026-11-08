<?php
/** Scoped, reversible, WPML-aware property-types reconciliation. */
if(!defined('ABSPATH'))exit(1);
global $wpdb;
$phase=getenv('MAD4B_PHASE')?:'audit';
$site=parse_url(home_url(),PHP_URL_HOST);
$langs=$site==='wayfatours.com'?['en','es']:['en','ar','fr','de','es','it'];
if(!in_array($site,['tours.dev.mad4b.com','wayfatours.com'],true))throw new RuntimeException('unexpected_site');
if(!taxonomy_exists('property-types')||get_post_type(30537)!=='properties')throw new RuntimeException('unexpected_taxonomy_or_entity');
$tax=$wpdb->prefix.'jet_taxonomies';$term=$wpdb->terms;$tt=$wpdb->term_taxonomy;$rel=$wpdb->term_relationships;$icl=$wpdb->prefix.'icl_translations';
$groups=[270,2150,265,1446,1447,1448,1869,1870,1871,1872];
$translations=[];
foreach($groups as $enid){
 $trid=(int)$wpdb->get_var($wpdb->prepare("SELECT trid FROM $icl WHERE element_type='tax_property-types' AND element_id=%d",$enid));
 if(!$trid)throw new RuntimeException('wpml_trid_missing_'.$enid);
 $rows=$wpdb->get_results($wpdb->prepare("SELECT x.term_id,x.term_taxonomy_id,x.parent,x.description,x.count,t.name,t.slug,l.language_code
 FROM $icl l JOIN $tt x ON x.term_taxonomy_id=l.element_id JOIN $term t ON t.term_id=x.term_id
 WHERE l.trid=%d AND l.element_type='tax_property-types' AND x.taxonomy='property-types'",$trid),ARRAY_A);
 $translations[$enid]=[];
 foreach($rows as $r){
  $lng=$r['language_code'];
  if(isset($translations[$enid][$lng]))throw new RuntimeException('multiple_translation_'.$enid.'_'.$lng);
  $translations[$enid][$lng]=$r;
 }
 foreach($langs as $lng)if(!isset($translations[$enid][$lng]))throw new RuntimeException('translation_missing_'.$enid.'_'.$lng);
}
$taxrow=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tax WHERE id=%d AND slug=%s",17,'property-types'),ARRAY_A);
if(!$taxrow)throw new RuntimeException('jetengine_taxonomy_not_found');
$objects=maybe_unserialize($taxrow['object_type']);
if(!is_array($objects)||!in_array('properties',$objects,true))throw new RuntimeException('property_type_not_bound');
$nile=(int)$translations[2150]['en']['term_taxonomy_id'];$cruise=(int)$translations[270]['en']['term_taxonomy_id'];
$ids=get_objects_in_term((int)$translations[270]['en']['term_id'],'property-types');
$candidates=[];$excluded=[];$counters=[];
foreach($ids as $id){
 $id=(int)$id;if(get_post_type($id)!=='properties'||get_post_status($id)!=='publish')continue;
 $types=wp_get_post_terms($id,'property-types',['fields'=>'slugs']);
 $locs=wp_get_post_terms($id,'destinations',['fields'=>'slugs']);
 if(is_wp_error($types)||is_wp_error($locs))throw new RuntimeException('property_terms_unreadable_'.$id);
 $title=get_the_title($id);
 $reason=null;
 if(in_array('nile-cruise',$types,true))$reason='already_nile';
 elseif(in_array('lake-nasser-cruises',$types,true))$reason='lake_nasser';
 elseif(array_intersect($locs,['sharm-el-sheikh','hurghada','marsa-alam','alexandria']))$reason='sea_or_coastal';
 elseif(array_intersect($types,['dahabiya-nile-cruise','felucca-nile-cruise','standard-nile-cruises','deluxe-nile-cruises','luxury-nile-cruises','ultra-luxury-nile-cruises']))$reason='nile_subtype';
 elseif(preg_match('/nile|dahab|dahabey|felucca|luxor|aswan/i',(string)$title))$reason='nile_title';
 elseif(array_intersect($locs,['aswan-gov','luxor']))$reason='nile_location';
 else $reason='insufficient_nile_evidence';
 $counters[$reason]=($counters[$reason]??0)+1;
 if(in_array($reason,['nile_subtype','nile_title','nile_location'],true))$candidates[$id]=$reason;
 elseif($reason!=='already_nile')$excluded[$id]=['reason'=>$reason,'title'=>$title];
}
if($phase==='backup'){
 $tids=array_merge(...array_map(static fn($r)=>array_map('intval',array_column($r,'term_taxonomy_id')),$translations));
 $tids=array_unique($tids);
 $ids=array_map('intval',$ids);
 $ids=array_filter($ids);
 $snapshot=[
 'site'=>$site,'created_utc'=>gmdate('c'),'jet_engine_taxonomy_row'=>$taxrow,'wpml_terms'=>$translations,
 'properties_cruise_ids'=>$ids,
 'cruise_property_relationships'=>array_map(static fn($r)=>$r,
 $wpdb->get_results("SELECT object_id,term_taxonomy_id,term_order FROM $rel WHERE object_id IN (".implode(',',$ids).") AND term_taxonomy_id IN (SELECT term_taxonomy_id FROM $tt WHERE taxonomy='property-types')",ARRAY_A)),
 'planned_nile_candidates'=>$candidates,'excluded'=>$excluded
 ];
 $out='/tmp/mad4b-property-types-before-20261108.json';
 if(file_put_contents($out,wp_json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))===false)throw new RuntimeException('backup_failed');
 echo wp_json_encode(['action'=>'backup','site'=>$site,'snapshot'=>$out,'bytes'=>filesize($out),'sha256'=>hash_file('sha256',$out),'candidate_count'=>count($candidates),'excluded'=>$excluded])."\n";
 exit;
}
if($phase==='schema'){
 if($site==='wayfatours.com'&&$objects!==['properties']){
  if(array_diff($objects,['properties','Properties']))throw new RuntimeException('jetengine_unexpected_bindings');
  $changed=$wpdb->update($tax,['object_type'=>serialize(['properties'])],['id'=>17],['%s'],['%d']);
  if($changed!==1)throw new RuntimeException('jetengine_bindings_update_failed');
 }
 if($translations[265]['en']['name']==='Vila'){
  $changed=$wpdb->update($term,['name'=>'Villa'],['term_id'=>265],['%s'],['%d']);
  if($changed!==1)throw new RuntimeException('villa_name_update_failed');
 }elseif($translations[265]['en']['name']!=='Villa')throw new RuntimeException('villa_unexpected_name');
 $descriptions=[
 'en'=>'Floating Nile River cruise vessels in Egypt, including river cruise ships, dahabiyas and feluccas. Excludes ocean-going and transcontinental cruises. Lake Nasser cruises are classified separately.',
 'ar'=>'مراكب عائمة للرحلات النهرية على نهر النيل في مصر، وتشمل الفنادق العائمة والدهبيات والفلوكات. لا تشمل الرحلات البحرية المحيطية أو العابرة للقارات. رحلات بحيرة ناصر لها تصنيف مستقل.',
 'fr'=>'Bateaux de croisière fluviale sur le Nil en Égypte, notamment les bateaux-hôtels, dahabiyas et felouques. Hors croisières maritimes et intercontinentales. Les croisières sur le lac Nasser sont classées séparément.',
 'de'=>'Flusskreuzfahrtschiffe auf dem Nil in Ägypten, darunter Nilkreuzfahrtschiffe, Dahabiyas und Feluken. Keine Hochsee- oder interkontinentalen Kreuzfahrten. Kreuzfahrten auf dem Nassersee sind gesondert klassifiziert.',
 'es'=>'Embarcaciones de crucero fluvial por el Nilo en Egipto, incluidos barcos de crucero, dahabiyas y falucas. No incluye cruceros oceánicos ni intercontinentales. Los cruceros por el lago Nasser se clasifican por separado.',
 'it'=>'Imbarcazioni per crociere fluviali sul Nilo in Egitto, comprese navi da crociera fluviale, dahabiya e feluche. Esclude crociere oceaniche e intercontinentali. Le crociere sul lago Nasser sono classificate separatamente.'
 ];
 foreach($langs as $lng){
  $ancestor=$translations[270][$lng];$river=$translations[2150][$lng];
  $updates=[
   [2150,$river,(int)$ancestor['term_id'],$descriptions[$lng]],
  ];
  foreach([1446,1448,1869,1870,1871,1872] as $id){
   $child=$translations[$id][$lng];
   $updates[]=[$id,$child,(int)$river['term_id'],(string)$child['description']];
  }
  foreach($updates as [$id,$data,$newparent,$desc]){
   $oldparent=(int)$data['parent'];
   if($id===2150&&$oldparent!==0&&$oldparent!==$newparent)throw new RuntimeException('nile_unexpected_parent_'.$lng);
   if($id!==2150&&$oldparent!==(int)$ancestor['term_id']&&$oldparent!==$newparent)throw new RuntimeException('child_unexpected_parent_'.$id.'_'.$lng);
   if($oldparent===$newparent&&(string)$data['description']===$desc)continue;
   $updated=$wpdb->update($tt,['parent'=>$newparent,'description'=>$desc],['term_taxonomy_id'=>(int)$data['term_taxonomy_id']],['%d','%s'],['%d']);
   if($updated!==1)throw new RuntimeException('term_update_failed_'.$id.'_'.$lng);
  }
 }
 foreach($translations as $items)foreach($items as $row)clean_term_cache((int)$row['term_id'],'property-types');
 wp_cache_flush();
 echo wp_json_encode(['action'=>'schema_applied','site'=>$site,'nile_parent'=>'cruise','nile_subtypes_under_nile'=>[1446,1448,1869,1870,1871,1872],'lake_nasser_preserved_under_cruise'=>1447,'vila_name'=>'Villa','slug_kept'=>'vila','jetengine_object_type'=>maybe_unserialize($wpdb->get_var("SELECT object_type FROM $tax WHERE id=17"))])."\n";
 exit;
}
if($phase==='map'){
 $limit=min(300,max(1,(int)(getenv('MAD4B_LIMIT')?:100)));
 $selected=array_slice($candidates,0,$limit,true);
 $processed=[];$failed=[];
 if($selected){
  $args=[];$holders=[];
  foreach($selected as $id=>$reason){$holders[]='(%d,%d,0)';$args[]=(int)$id;$args[]=$nile;}
  $sql="INSERT IGNORE INTO $rel (object_id,term_taxonomy_id,term_order) VALUES ".implode(',',$holders);
  $changed=$wpdb->query($wpdb->prepare($sql,...$args));
  if($changed===false)throw new RuntimeException('mapping_bulk_insert_failed_'.$wpdb->last_error);
  foreach($selected as $id=>$reason){
   $has=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rel WHERE object_id=%d AND term_taxonomy_id=%d",(int)$id,$nile));
   if($has!==1){$failed[$id]='nile_relationship_not_persisted';break;}
   $processed[$id]=$reason;
  }
  wp_update_term_count_now([$nile],'property-types');
  clean_term_cache((int)$translations[2150]['en']['term_id'],'property-types');
  clean_object_term_cache(array_map('intval',array_keys($selected)),'properties');
 }
 echo wp_json_encode(['action'=>'mapped','site'=>$site,'processed'=>count($processed),'remaining_estimate'=>count($candidates)-count($processed),'failed'=>$failed,'by_reason'=>array_count_values($processed),'excluded'=>count($excluded)])."\n";
 if($failed)exit(1);
 exit;
}
if($phase==='verify'){
 $checks=[];$issues=[];
 $row=$wpdb->get_var("SELECT object_type FROM $tax WHERE id=17");
 $checks['jetengine_object_type']=maybe_unserialize($row);
 if($checks['jetengine_object_type']!==['properties'])$issues[]='jetengine_mapping_drift';
 $villa=$wpdb->get_var("SELECT name FROM $term WHERE term_id=265");
 $checks['villa_name']=$villa;if($villa!=='Villa')$issues[]='villa_name_not_fixed';
 foreach($langs as $lng){
  $ancestor=$translations[270][$lng];$river=$translations[2150][$lng];
  if((int)$river['parent']!==(int)$ancestor['term_id'])$issues[]='nile_parent_'.$lng;
  foreach([1446,1448,1869,1870,1871,1872] as $id)
   if((int)$translations[$id][$lng]['parent']!==(int)$river['term_id'])$issues[]='subtype_parent_'.$id.'_'.$lng;
  if((int)$translations[1447][$lng]['parent']!==(int)$ancestor['term_id'])$issues[]='lake_nasser_parent_'.$lng;
 }
 $assigned=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rel r INNER JOIN {$wpdb->posts} p ON p.ID=r.object_id WHERE r.term_taxonomy_id=%d AND p.post_type='properties' AND p.post_status='publish'",$nile));
 $checks['nile_direct_assigned']=$assigned;
 $checks['candidates_left']=count($candidates);
 $checks['exceptions']=$excluded;
 $checks['cruise_direct_count']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rel r INNER JOIN {$wpdb->posts} p ON p.ID=r.object_id WHERE r.term_taxonomy_id=%d AND p.post_type='properties' AND p.post_status='publish'",$cruise));
 if($assigned<260||$assigned>280)$issues[]='unexpected_nile_count';
 if(count($candidates)>0)$issues[]='migration_candidates_remain';
 if($checks['cruise_direct_count']!==283)$issues[]='cruise_root_count_changed';
 $checks['issues']=$issues;
 echo wp_json_encode(['action'=>'verify','site'=>$site,'checks'=>$checks])."\n";
 if($issues)exit(1);
 exit;
}
echo wp_json_encode(['action'=>'audit','site'=>$site,'candidate_count'=>count($candidates),'excluded'=>$excluded,'distribution'=>$counters])."\n";
