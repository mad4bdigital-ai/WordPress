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
/**
 * Reconcile native Elementor styles only, without replacing original
 * widgets or overwriting translated content and local company settings.
 * Colors are Elementor Kit global references, not hardcoded CSS overrides.
 */
function mad4b_dest_sync_brand_style(array &$nodes,array $canonical,int &$changed):void{
 $seedWidgets=[];$seedCards=[];
 $accordionKeys=[
  'normal_title_color','hover_title_color','active_title_color',
  'normal_icon_color','hover_icon_color','active_icon_color',
  'accordion_background_normal_color','accordion_background_hover_color','accordion_background_active_color',
  'accordion_border_normal_color','accordion_border_hover_color','accordion_border_active_color',
  'content_border_color','content_background_color',
  'accordion_border_normal_border','accordion_border_normal_width',
  'accordion_border_hover_border','accordion_border_hover_width',
  'accordion_border_active_border','accordion_border_active_width',
  'accordion_border_radius','content_border_border','content_border_width',
  'content_border_radius','content_background_background'
 ];
 $cardKeys=['border_color','background_color','border_radius','border_width'];
 $tree=[$canonical];
 mad4b_dest_walk($tree,static function($n)use(&$seedWidgets,&$seedCards,$accordionKeys,$cardKeys){
  $settings=$n['settings']??[];
  $css=(string)($settings['_css_classes']??$settings['css_classes']??'');
  if(($n['widgetType']??'')==='nested-accordion' && $css==='destination-native-native-accordion'){
   if($seedWidgets)return;
   foreach($accordionKeys as $key){
    if(!array_key_exists($key,$settings))WP_CLI::error('incomplete Kit accordion contract: '.$key);
    $seedWidgets['settings'][$key]=$settings[$key];
   }
   $seedWidgets['settings']['__globals__']=(array)($settings['__globals__']??[]);
   $panel=$n['elements'][0]??null;
   if(($panel['elType']??'')!=='container' || !isset($panel['settings']['border_radius']))
    WP_CLI::error('missing native accordion panel-radius contract');
   $seedWidgets['panel_radius']=$panel['settings']['border_radius'];
  }
  foreach(['highlights','internal-links'] as $role){
   if(!str_contains(' '.$css.' ',' destination-native-'.$role.' '))continue;
   if(isset($seedCards[$role]))continue;
   foreach($cardKeys as $key){
    if(!array_key_exists($key,$settings))WP_CLI::error('incomplete Kit card contract: '.$key);
    $seedCards[$role]['settings'][$key]=$settings[$key];
   }
   $seedCards[$role]['settings']['__globals__']=(array)($settings['__globals__']??[]);
  }
 });
 if(!$seedWidgets||count($seedCards)!==2)WP_CLI::error('invalid Kit design contract');
 $seen=['accordion'=>0,'highlights'=>0,'internal-links'=>0];
 $sync=static function(array &$target,array $source,array $keys,int &$changed):void{
  foreach($keys as $k){
   if(!array_key_exists($k,$source))continue;
   if(!array_key_exists($k,$target)||$target[$k]!==$source[$k]){
    $target[$k]=$source[$k];$changed++;
   }
  }
  $globals=$source['__globals__']??[];
  if($globals){
   if(!isset($target['__globals__'])||!is_array($target['__globals__']))$target['__globals__']=[];
   foreach($globals as $key=>$ref){
    if(!isset($target['__globals__'][$key])||$target['__globals__'][$key]!==$ref){
      $target['__globals__'][$key]=$ref;$changed++;
    }
   }
  }
 };
 mad4b_dest_walk($nodes,static function(&$node)use($seedWidgets,$seedCards,$accordionKeys,$cardKeys,&$changed,&$seen,$sync){
  $settings=$node['settings']??[];
  $css=(string)($settings['_css_classes']??$settings['css_classes']??'');
  if(($node['widgetType']??'')==='nested-accordion'&&$css==='destination-native-native-accordion'){
   $seen['accordion']++;
   $sync($node['settings'],$seedWidgets['settings'],$accordionKeys,$changed);
   if(($node['elements'][0]['elType']??'')!=='container')
    WP_CLI::error('unexpected native accordion child');
   if(($node['elements'][0]['settings']['border_radius']??null)!==$seedWidgets['panel_radius']){
    $node['elements'][0]['settings']['border_radius']=$seedWidgets['panel_radius'];$changed++;
   }
  }else{
   foreach(['highlights','internal-links'] as $role){
    if(!str_contains(' '.$css.' ',' destination-native-'.$role.' '))continue;
    $seen[$role]++;
    $sync($node['settings'],$seedCards[$role]['settings'],$cardKeys,$changed);
   }
  }
 });
 if($seen!==['accordion'=>3,'highlights'=>1,'internal-links'=>1])
  WP_CLI::error('unexpected native Elementor block counts: '.wp_json_encode($seen));
}
function mad4b_dest_require_kit_colors(array $spec):void{
 if(empty($spec['brand_style']['roles']))return;
 if(!class_exists('\Elementor\Plugin')||!\Elementor\Plugin::$instance->kits_manager)
  WP_CLI::error('Elementor Kit unavailable');
 $kit=\Elementor\Plugin::$instance->kits_manager->get_active_kit();
 if(!$kit)WP_CLI::error('No active Elementor Kit');
 $colors=array_merge((array)$kit->get_settings_for_display('system_colors'),
                      (array)$kit->get_settings_for_display('custom_colors'));
 $available=[];
 foreach($colors as $color)if(!empty($color['_id']))$available[(string)$color['_id']]=true;
 foreach($spec['brand_style']['roles'] as $role=>$id){
  if(!isset($available[(string)$id]))WP_CLI::error("Brand Kit role $role token $id is missing; refusing fixed-color fallback");
 }
}
mad4b_dest_require_kit_colors($spec);

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
  $styleChanges=0;
  mad4b_dest_sync_brand_style($tree,$spec['section'],$styleChanges);
  $maps=0;$mapChanges=0;
  mad4b_dest_patch_map($tree,$maps,$mapChanges);
  if($maps!==1)WP_CLI::error("expected one original maps widget, found $maps for template $id");
  $altered=!$existing || $mapChanges>0 || $styleChanges>0;
  WP_CLI::log(wp_json_encode(['template'=>$id,'language'=>$lang,'enrichment'=>$existing?'present':'add',
    'map'=>$mapChanges?'patch':'present','kit_style'=>$styleChanges?'patch':'present',
    'kit_style_changes'=>$styleChanges,'action'=>$apply?'apply':'dry-run']));
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
