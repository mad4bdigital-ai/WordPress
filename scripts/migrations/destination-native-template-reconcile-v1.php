<?php
/**
 * MAD4B Destination Term Elementor Original Template reconciler (v1).
 *
 * Usage: MAD4B_DEST_TEMPLATE_CONTRACT=/absolute/.../contract.json wp eval-file this.php --allow-root
 * Default: DRY RUN. To apply set MAD4B_DEST_TEMPLATE_APPLY=yes and
 * MAD4B_DEST_TEMPLATE_BACKUP=/persistent/non-public/directory.
 *
 * No new runtime provider, no template ID literals, no term/post writes.
 * Targets the existing WPML translation family of the canonical EN content
 * template, preserves every sibling element, and stops on unexpected shapes.
 */
if(!defined('ABSPATH') || !class_exists('WP_CLI'))return;
global $wpdb;
$path=(string)getenv('MAD4B_DEST_TEMPLATE_CONTRACT');
$raw=$path!=='' ? @file_get_contents($path) : false;
$spec=$raw ? json_decode($raw,true) : null;
if(!is_array($spec) || ($spec['contract']??'')!=='mad4b.destination.elementor-native/v1'
   || ($spec['taxonomy']??'')!=='destinations'
   || !is_array($spec['section']??null))WP_CLI::error('invalid or missing contract JSON');
$apply=getenv('MAD4B_DEST_TEMPLATE_APPLY')==='yes';
$backup=(string)getenv('MAD4B_DEST_TEMPLATE_BACKUP');
if($apply && ($backup==='' || !is_dir($backup) || !is_writable($backup)))
 WP_CLI::error('a writable, non-public backup directory is required for APPLY');
if($apply && str_starts_with(realpath($backup)?:'',realpath(ABSPATH)?:ABSPATH))
 WP_CLI::error('backup directory must be outside the WordPress public root');

function mad4b_dest_walk(array &$nodes,callable $visit):void{
 foreach($nodes as &$node){
  $visit($node);
  if(isset($node['elements'])&&is_array($node['elements']))
   mad4b_dest_walk($node['elements'],$visit);
 }unset($node);
}
function mad4b_dest_present(array $nodes,string $class):bool{
 $found=false;
 mad4b_dest_walk($nodes,static function($n)use($class,&$found){
  $classes=' '.(string)($n['settings']['css_classes']??'').' ';
  if(str_contains($classes,' '.$class.' '))$found=true;
 });
 return $found;
}
function mad4b_dest_localize(array &$section,array $labels):void{
 if(count($labels)!==6)WP_CLI::error('expected six localized labels');
 foreach($section['elements'] as $index=>&$entry){
  if($index===0){$entry['settings']['title']=$labels[0];continue;}
  $key=(string)($entry['settings']['css_classes']??'');
  $name=null;
  foreach(['why-visit'=>1,'highlights'=>2,'best-time-to-visit'=>3,'travel-tips'=>4,'internal-links'=>5] as $slug=>$idx)
   if(str_contains(' '.$key.' ',' destination-native-'.$slug.' ')){$name=$labels[$idx];break;}
  if($name===null)WP_CLI::error('unexpected native section marker');
  $entry['settings']['_title']=$name.' · JetEngine';
  foreach($entry['elements'] as &$widget){
   if(($widget['widgetType']??'')==='heading')$widget['settings']['title']=$name;
   elseif(($widget['widgetType']??'')==='nested-accordion'){
    if(!isset($widget['settings']['items'][0]))WP_CLI::error('accordion contract invalid');
    $widget['settings']['items'][0]['item_title']=$name;
    $widget['settings']['_title']=$name.' · Elementor accordion';
   }
  }unset($widget);
 }unset($entry);
}
function mad4b_dest_insert(array &$nodes,array $section,int &$matched):void{
 foreach($nodes as &$node){
  if(!isset($node['elements'])||!is_array($node['elements']))continue;
  $children=&$node['elements'];
  foreach($children as $i=>$child){
   if(($child['widgetType']??'')==='jet-listing-dynamic-field'
     && ($child['settings']['dynamic_field_post_object']??'')==='description'){
    array_splice($children,$i+1,0,[$section]);$matched++;
   }
  }
  if(!$matched)mad4b_dest_insert($children,$section,$matched);
 }unset($node);
}
function mad4b_dest_patch_map(array &$nodes,int &$found,int &$changed):void{
 mad4b_dest_walk($nodes,static function(&$n)use(&$found,&$changed){
  if(($n['widgetType']??'')!=='google_maps')return;
  $found++;
  $settings=&$n['settings'];
  $tag=(string)($settings['__dynamic__']['address']??'');
  if($tag==='' || !str_contains($tag,'jet-term-field'))WP_CLI::error('unknown native Google Maps dynamic source');
  $patched=$tag;
  if(str_contains($patched,'google_address'))$patched=str_replace('google_address','google_location',$patched);
  if(str_contains($patched,'%22destination%22'))$patched=str_replace('%22destination%22','%22destinations%22',$patched);
  if(!str_contains($patched,'google_location')||!str_contains($patched,'%22destinations%22'))WP_CLI::error('unrecognized map taxonomy binding');
  if($patched!==$tag){$settings['__dynamic__']['address']=$patched;$changed++;}
  if(($settings['jedv_enabled']??'')!=='yes'){
   $settings['jedv_enabled']='yes';
   $settings['jedv_type']='show';
   $settings['jedv_relation']='AND';
   $settings['jedv_conditions']=[[
    '_id'=>substr(hash('sha256','mad4b-destination-map-location'),0,7),
    'jedv_condition'=>'exists','jedv_field'=>'google_location','jedv_context'=>'current_listing'
   ]];
   $changed++;
  }else{
   $conditions=(array)($settings['jedv_conditions']??[]);
   if(count($conditions)!==1 || ($conditions[0]['jedv_field']??'')!=='google_location')
    WP_CLI::error('existing map visibility policy differs; review required');
  }
 });
}
$base=$wpdb->get_results(
 $wpdb->prepare("SELECT p.ID,tr.trid FROM {$wpdb->posts} p
 JOIN {$wpdb->prefix}icl_translations tr ON tr.element_id=p.ID AND tr.element_type=%s
 WHERE p.post_type='elementor_library' AND p.post_status='publish'
 AND p.post_title=%s AND tr.language_code='en'",
 'post_elementor_library','Destination Term Archive Content'),ARRAY_A);
if(count((array)$base)!==1)WP_CLI::error('canonical original Elementor content template is ambiguous');
$trid=(int)$base[0]['trid'];
$docs=$wpdb->get_results($wpdb->prepare("SELECT p.ID,tr.language_code FROM {$wpdb->posts} p
 JOIN {$wpdb->prefix}icl_translations tr ON tr.element_id=p.ID
 WHERE tr.trid=%d AND tr.element_type=%s AND p.post_status='publish' AND p.post_type='elementor_library'
 ORDER BY p.ID",$trid,'post_elementor_library'),ARRAY_A);
if(!$docs)WP_CLI::error('no published WPML destination content templates');
$count=0;
foreach($docs as $doc){
 $id=(int)$doc['ID'];$lang=(string)$doc['language_code'];
 if(!isset($spec['locales'][$lang])){WP_CLI::warning("Unsupported locale $lang for template $id");continue;}
 $lock='mad4b_dest_template_'.$id;
 if($apply && (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,4)',$lock))!==1)
  WP_CLI::error('lock unavailable for template '.$id);
 try{
  $row=$wpdb->get_row($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta}
    WHERE post_id=%d AND meta_key='_elementor_data' LIMIT 1",$id),ARRAY_A);
  if(!$row)WP_CLI::error('no Elementor data for template '.$id);
  $tree=json_decode($row['meta_value'],true);
  if(!is_array($tree))WP_CLI::error('invalid existing Elementor data');
  $existing=mad4b_dest_present($tree,$spec['section_marker']);
  $inserted=0;
  if(!$existing){
   $section=$spec['section'];
   mad4b_dest_localize($section,$spec['locales'][$lang]);
   mad4b_dest_insert($tree,$section,$inserted);
   if($inserted!==1)WP_CLI::error("ambiguous About location in template $id");
  }
  $maps=0;$mapChanges=0;
  mad4b_dest_patch_map($tree,$maps,$mapChanges);
  if($maps!==1)WP_CLI::error("expected one original maps widget, found $maps for template $id");
  $altered=!$existing || $mapChanges>0;
  WP_CLI::log(wp_json_encode(['template'=>$id,'language'=>$lang,'enrichment'=>$existing?'present':'add',
    'map'=>$mapChanges?'patch':'present','action'=>$apply?'apply':'dry-run']));
  if(!$apply||!$altered)continue;
  $before=$row['meta_value'];
  $after=wp_json_encode($tree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!$after||!is_array(json_decode($after,true)))WP_CLI::error('invalid candidate document');
  $saved=$backup.'/destination-template-'.$id.'-'.hash('sha256',$before).'.json';
  if(!file_exists($saved) && file_put_contents($saved,$before,LOCK_EX)===false)
   WP_CLI::error('cannot persist baseline before update');
  @chmod($saved,0600);
  if(hash_file('sha256',$saved)!==hash('sha256',$before))WP_CLI::error('baseline backup mismatch');
  $affected=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s
    WHERE meta_id=%d AND BINARY meta_value=%s",$after,(int)$row['meta_id'],$before));
  if($affected!==1)WP_CLI::error('CAS update conflict '.$id);
  wp_cache_delete($id,'post_meta');
  if(class_exists('\Elementor\Core\Files\CSS\Post'))
   (new \Elementor\Core\Files\CSS\Post($id))->delete();
  clean_post_cache($id);
  $readback=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta}
    WHERE meta_id=%d",(int)$row['meta_id']));
  if($readback!==$after)WP_CLI::error('readback mismatch on template '.$id);
  $count++;
 }finally{
  if($apply)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
 }
}
WP_CLI::success("Destination original template reconciliation checked ".count($docs)." docs; changed $count; mode ".($apply?'apply':'dry-run'));
