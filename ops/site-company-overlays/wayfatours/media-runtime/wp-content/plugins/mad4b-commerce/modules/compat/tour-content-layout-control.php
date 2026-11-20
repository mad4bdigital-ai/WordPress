<?php
/** JetEngine-native Tour Page Options and global Elementor section order. */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Layout_Control_V1 {
 const BOX='mad4b_tour_layout_page_options';
 const ORDER='mad4b_tour_content_sections_v1';
 const GLOBAL='mad4b_tour_content_global_order_v1';
 private static $registry=null;
 static function sections():array {
  if(self::$registry!==null)return self::$registry;
  self::$registry=['parent'=>'','sections'=>[]];
  $source=absint(get_option('mad4b_tour_content_master_template',24784));
  $tree=json_decode((string)get_post_meta($source,'_elementor_data',true),true);
  $candidates=[];
  $walk=function($nodes)use(&$walk,&$candidates){
   foreach((array)$nodes as $node){
    if(!is_array($node))continue;
    $children=(array)($node['elements']??[]);$named=[];
    foreach($children as $child){
     $id=(string)($child['id']??'');
     $title=trim((string)($child['settings']['_title']??''));
     if($id!==''&&$title!=='')$named[$id]=sanitize_text_field($title);
    }
    if(count($named)>=5)$candidates[]=['parent'=>(string)($node['id']??''),'sections'=>$named];
    $walk($children);
   }
  };
  $walk($tree);
  usort($candidates,static fn($a,$b)=>count($b['sections'])<=>count($a['sections']));
  if($candidates)self::$registry=$candidates[0];
  return self::$registry;
 }
 static function rate_styles():array {
  $template=absint(get_option('mad4b_tour_rate_archive_template',48317));
  $tree=json_decode((string)get_post_meta($template,'_elementor_data',true),true);
  $found=[];
  $walk=function($nodes)use(&$walk,&$found){
   foreach((array)$nodes as $node){
    $s=(array)($node['settings']??[]);
    if(($node['widgetType']??'')==='jet-listing-grid' && !empty($s['custom_query_id']))
     foreach((array)($s['jedv_conditions']??[]) as $c){
      $value=trim((string)($c['jedv_value']??''));
      if($value!=='')$found[$value]=$value;
     }
    $walk($node['elements']??[]);
   }
  };
  $walk($tree);
  if(!$found)$found=['Accordion'=>'Accordion','Grid Listing'=>'Grid Listing'];
  return (array)apply_filters('mad4b/tour-layout/rate-styles',$found,$template);
 }
 static function extra_modes():array {
  $id=absint(get_option('mad4b_tour_extra_presentation_template',53521));
  if(get_post_type($id)!=='elementor_library'||get_post_status($id)!=='publish')return [];
  $tree=json_decode((string)get_post_meta($id,'_elementor_data',true),true);
  $found=[];
  $walk=function($nodes)use(&$walk,&$found){
   foreach((array)$nodes as $node){
    $cls=preg_split('/\s+/',(string)($node['settings']['css_classes']??''),-1,PREG_SPLIT_NO_EMPTY);
    if(in_array('shared-extra-standard-listing',$cls,true))$found['listing']='Standard Listing';
    if(in_array('shared-extra-feature-carousel',$cls,true))$found['carousel']='Carousel';
    $walk($node['elements']??[]);
   }
  };
  $walk($tree);
  return (array)apply_filters('mad4b/tour-layout/extra-modes',$found,$id);
 }
 /** Discover every Elementor-bound Tour photo slot from the live template;
  * avoid assuming five fixed frames or a fixed widget ID. */
 static function photo_slots():array {
  $source=absint(get_option('mad4b_tour_hero_master_template',24780));
  $tree=json_decode((string)get_post_meta($source,'_elementor_data',true),true);
  $found=[];
  $walk=function($nodes)use(&$walk,&$found){
   foreach((array)$nodes as $node){
    if(!is_array($node))continue;
    $tag=(string)($node['settings']['__dynamic__']['background_image']??'');
    if($tag!=='' && preg_match('/"img_field"\s*:\s*"([a-zA-Z0-9_]{3,80})"/',rawurldecode($tag),$m)){
     $field=$m[1];
     if($field==='image_vertical'||preg_match('/^grid_image_[a-zA-Z0-9_]{1,80}$/D',$field))$found[$field]=true;
    }
    $walk($node['elements']??[]);
   }
  };
  $walk($tree);
  $registered=apply_filters('mad4b/tour_layout/media_frame_slots',array_keys($found),$source);
  if(!is_array($registered))return array_keys($found);
  $valid=[];
  foreach(array_slice($registered,0,80) as $slot){
   if(is_string($slot)&&($slot==='image_vertical'||preg_match('/^grid_image_[a-zA-Z0-9_]{1,80}$/D',$slot)))$valid[$slot]=true;
  }
  return array_keys($valid);
 }
 /** Editable JetEngine Repeater, attached to the original Tour Page Options box. */
 static function frame_focus_field():array {
  $field=static fn($name,$title,$type,$extra=[])=>array_merge([
   'object_type'=>'field','name'=>$name,'title'=>$title,'type'=>$type,'width'=>'100%',
   'options_source'=>'manual','options'=>[],'repeater-fields'=>[],'isNested'=>false],$extra);
  $options=[];foreach(self::photo_slots() as $slot)$options[]=['key'=>$slot,'value'=>str_replace('_',' ',ucwords($slot,'_'))];
  $focus=$field('mad4b_media_frame_focus_v2','Photo frame focal positions · Elementor / JetEngine','repeater',[
   'description'=>'One row per image slot. Focus is bound to the actual photographed original. No row = Elementor default. New Elementor image slots appear automatically.',
   'repeater-fields'=>[
    $field('slot','Photo slot (discovered from Elementor)','select',['options'=>$options]),
    $field('focus_x','Horizontal focus X (0–100%)','number',['min_value'=>0,'max_value'=>100,'step_value'=>1,'default_val'=>50]),
    $field('focus_y','Vertical focus Y (0–100%)','number',['min_value'=>0,'max_value'=>100,'step_value'=>1,'default_val'=>50]),
    $field('frame_aspect_ratio','Frame width ÷ height (optional, e.g. 1.5 for wide image)','number',['min_value'=>0.25,'max_value'=>5,'step_value'=>0.01,'default_val'=>'','description'=>'Use the actual visible frame ratio at the key desktop breakpoint when known. Empty uses a conservative landscape-only estimate for crop review; this does not alter Elementor layout.']),
    $field('fit','Image fit inside frame','select',['options'=>[['key'=>'cover','value'=>'Cover (fill frame)'],['key'=>'contain','value'=>'Contain (show complete image)']],'default_val'=>'cover']),
    $field('source_sha256','Image source binding (automatic)','hidden',['description'=>'Generated from the verified photo origin on save; stale values cannot apply to a different photo.']),
    $field('rebind_on_save','Rebind to replaced image · one-time approval','switcher',['default_val'=>false,'description'=>'OFF by default. Enable only after inspecting the changed photograph; automatically resets after saving.']),
   ],
  ]);
  return $focus;
 }
 static function ensure_photo_focus_schema():array {
  $all=get_option('jet_engine_meta_boxes',[]);
  if(!is_array($all)||!is_array($all[self::BOX]['meta_fields']??null))return ['status'=>'missing_original_jetengine_box'];
  foreach($all[self::BOX]['meta_fields'] as $i=>$f){
   if(($f['name']??'')!=='mad4b_media_frame_focus_v2')continue;
   $sub=$f['repeater-fields']??[];
   if(!is_array($sub))return ['status'=>'existing_repeater_incompatible'];
   $names=array_fill_keys(array_column($sub,'name'),true);
   $added=[];
   // Migrate existing JetEngine boxes by diffing actual fields, not by one
   // historical sentinel. Keep company-owned fields and existing values.
   foreach(self::frame_focus_field()['repeater-fields'] as $new){
    $key=(string)($new['name']??'');
    if($key===''||isset($names[$key]))continue;
    $sub[]=$new;$names[$key]=true;$added[]=$key;
   }
   if(!$added)return ['status'=>'already_registered'];
   $all[self::BOX]['meta_fields'][$i]['repeater-fields']=$sub;
   if(!update_option('jet_engine_meta_boxes',$all,false)||get_option('jet_engine_meta_boxes',[])!==$all)
    return ['status'=>'write_or_readback_failed'];
   return ['status'=>'existing_repeater_extended','new_fields'=>$added,'new_field'=>$added[0]];
  }
  $before=$all;
  $all[self::BOX]['meta_fields'][]=self::frame_focus_field();
  if(!update_option('jet_engine_meta_boxes',$all,false)||get_option('jet_engine_meta_boxes',[])!==$all)return ['status'=>'write_or_readback_failed'];
  return ['status'=>'registered_in_existing_box','before_count'=>count($before[self::BOX]['meta_fields']),
    'after_count'=>count($all[self::BOX]['meta_fields']),'slot_count'=>count(self::photo_slots())];
 }
 static function fields($fields):array {
  if(!is_array($fields))return $fields;
  foreach($fields as &$field){
   $name=(string)($field['name']??'');
   if($name==='tour_rates_display_style')$values=self::rate_styles();
   elseif($name==='extra_options_display_mode')$values=self::extra_modes();
   else $values=[];
   if($name==='mad4b_media_frame_focus_v2'){
    foreach((array)($field['repeater-fields']??[]) as $i=>$sub)if(($sub['name']??'')==='slot'){
     $options=[];foreach(self::photo_slots() as $slot)$options[]=['key'=>$slot,'value'=>str_replace('_',' ',ucwords($slot,'_'))];
     $field['repeater-fields'][$i]['options']=$options;
    }
   }
   if($values){$field['options']=[];foreach($values as $id=>$label)$field['options'][]=['key'=>(string)$id,'value'=>(string)$label];}
   foreach((array)($field['repeater-fields']??[]) as $index=>$sub){
    if(($sub['name']??'')!=='section_id')continue;
    $values=(array)(self::sections()['sections']??[]);
    $options=[];foreach($values as $id=>$label)$options[]=['key'=>$id,'value'=>$label];
    $field['repeater-fields'][$index]['options']=$options;
   }
  }unset($field);
  return $fields;
 }
 static function register_box():void {
  if(!current_user_can('manage_options'))return;
  $all=get_option('jet_engine_meta_boxes',[]);
  if(!is_array($all)||isset($all[self::BOX]))return;
  add_option('mad4b_tour_layout_meta_boxes_backup_20261104',$all,'',false);
  $field=static fn($name,$title,$type,$opts=[])=>[
   'object_type'=>'field','name'=>$name,'title'=>$title,'type'=>$type,
   'width'=>'100%','options_source'=>'manual','options'=>$opts,'repeater-fields'=>[],'isNested'=>false];
  $rows=[
   $field('tour_rates_display_style','Rates presentation (discovered from JetEngine archive)','select'),
   $field('extra_options_display_mode','Optional Trips / Extras presentation','select'),
   $field(self::ORDER,'Tour Content sections — drag repeater rows to reorder','repeater')
  ];
  $rows[2]['description']='Drag rows by their native JetEngine repeater handle. Leave empty to inherit global section order.';
  $rows[2]['repeater-fields']=[$field('section_id','Section from Tour Content template','select')];
  $all[self::BOX]=['id'=>self::BOX,'args'=>[
   'name'=>'Tour Page Options · Presentation & Section Order',
   'object_type'=>'post','allowed_post_type'=>['tours-and-activities'],
   'allowed_tax'=>[],'active_conditions'=>[],'position'=>'normal','priority'=>'high'],
   'meta_fields'=>$rows];
  update_option('jet_engine_meta_boxes',$all,false);
 }
 /** Resolve locale override before canonical source, then company-wide fallback. */
 private static function local_order(int $tour,array $known):array {
  $selected=[];
  $rows=get_post_meta($tour,self::ORDER,true);
  foreach(is_array($rows)?$rows:[] as $row){
   $id=is_array($row)?(string)($row['section_id']??''):'';
   if(in_array($id,$known,true)&&!in_array($id,$selected,true))$selected[]=$id;
  }
  return $selected;
 }
 static function order_for(int $tour):array {
  $known=array_keys((array)(self::sections()['sections']??[]));
  if(!$known||$tour<1||get_post_type($tour)!=='tours-and-activities')return [];
  $selected=self::local_order($tour,$known);
  if(!$selected){
   $language=(string)apply_filters('wpml_default_language','');
   $source=$language!==''?(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$language):0;
   if($source>0 && $source!==$tour && get_post_type($source)==='tours-and-activities')
    $selected=self::local_order($source,$known);
  }
  if(!$selected){
   $global=get_option(self::GLOBAL,[]);
   foreach(is_array($global)?$global:[] as $id){
    $id=(string)$id;
    if(in_array($id,$known,true)&&!in_array($id,$selected,true))$selected[]=$id;
   }
  }
  // A translation without a locale override MUST inherit the English
  // master's current structural order. An empty global setting means
  // "inherit the English template", not "disable ordering on translations".
  return $selected?array_merge($selected,array_values(array_diff($known,$selected))):$known;
 }
 /**
  * Reorder before Elementor renders section markup. Preserve every section,
  * widget ID, locale title, visibility rule, and JetEngine dynamic binding.
  * The existing DOM script remains only as a fallback for legacy cached HTML.
  */
 static function sort_builder_data($data,$post_id) {
  if(!mad4b_is_main_tour_singular()||!is_array($data))return $data;
  $master=absint(get_option('mad4b_tour_content_master_template',24784));
  $post_id=absint($post_id);
  if(!$master||!$post_id)return $data;
  $default=(string)apply_filters('wpml_default_language','en');
  $canonical=(int)apply_filters('wpml_object_id',$post_id,'elementor_library',false,$default);
  if($post_id!==$master&&$canonical!==$master)return $data;
  $order=self::active_order(); $parent=(string)(self::sections()['parent']??'');
  if(!$order||!$parent)return $data;
  $positions=array_flip($order);
  $walk=static function(&$nodes)use(&$walk,$parent,$positions) {
   foreach($nodes as &$node) {
    if(!is_array($node))continue;
    if(($node['id']??'')===$parent&&isset($node['elements'])&&is_array($node['elements'])){
     $children=$node['elements'];
     usort($children,static function($a,$b)use($positions) {
      $pa=$positions[(string)($a['id']??'')]??PHP_INT_MAX;
      $pb=$positions[(string)($b['id']??'')]??PHP_INT_MAX;
      return $pa<=>$pb;
     });
     $node['elements']=$children;
    } elseif(!empty($node['elements'])&&is_array($node['elements'])) {
     $walk($node['elements']);
    }
   }unset($node);
  };
  $walk($data);
  return $data;
 }
 /**
  * Do not write a tour-specific ordering into a shared, 24-hour Elementor
  * document cache used by another tour or another language.
  * Other Elementor templates retain their normal cache behavior.
  */
 static function content_cache_policy($pre) {
  if(!mad4b_is_main_tour_singular()||!class_exists('\\Elementor\\Plugin')
    ||!isset(\Elementor\Plugin::$instance)
    ||!is_object(\Elementor\Plugin::$instance->documents))return $pre;
  $doc=\Elementor\Plugin::$instance->documents->get_current();
  if(!is_object($doc)||!method_exists($doc,'get_id'))return $pre;
  $id=(int)$doc->get_id();
  $master=absint(get_option('mad4b_tour_content_master_template',24784));
  if($id===$master)return 'disable';
  $default=(string)apply_filters('wpml_default_language','en');
  $source=(int)apply_filters('wpml_object_id',$id,'elementor_library',false,$default);
  return $source===$master?'disable':$pre;
 }
 static function active_order():array {
  if(!mad4b_is_main_tour_singular())return [];
  return self::order_for(absint(get_queried_object_id()));
 }
 static function render_order($element):void {
  if(!mad4b_is_main_tour_singular())return;
  $order=self::active_order();if(!$order)return;
  $section=self::sections();$id=(string)$element->get_id();
  if($id===($section['parent']??'')){
   $element->add_render_attribute('_wrapper','class','mad4b-tour-content-sorted');
   return;
  }
  $position=array_search($id,$order,true);
  if($position!==false)$element->add_render_attribute('_wrapper','style','order:'.($position+1).'!important;');
 }
 static function css():void {
  if(!self::active_order())return;
  echo '<style id="mad4b-tour-content-order-css">.mad4b-tour-content-sorted>[data-id]{order:999!important}</style>';
 }
 static function frontend_order_script():void {
  $order=self::active_order();if(!$order)return;
  $parent=(string)(self::sections()['parent']??'');if($parent==='')return;
  $payload=wp_json_encode(['parent'=>$parent,'order'=>$order],JSON_UNESCAPED_SLASHES);
  echo '<script id="mad4b-tour-content-sort-runtime">(function(){"use strict";const c='.$payload.';
    function apply(){document.querySelectorAll("[data-id]").forEach(function(parent){
     if(parent.dataset.id!==c.parent || !parent.closest(".elementor[data-elementor-post-type=elementor_library]"))return;
     const children=Array.from(parent.children).filter(n=>n.dataset&&n.dataset.id);
     if(children.length<2)return;
     const mapping=new Map(children.map(n=>[n.dataset.id,n]));
     const wanted=c.order.map(id=>mapping.get(id)).filter(Boolean);
     const rest=children.filter(n=>!c.order.includes(n.dataset.id));
     const target=wanted.concat(rest);
     if(target.every((n,i)=>n===children[i]))return;
     target.forEach(n=>parent.appendChild(n));
     parent.dataset.mad4bLayoutSorted="1";
    });}
    if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",apply,{once:true});else apply();
    document.addEventListener("jet-filter-content-rendered",apply);
   })();</script>';
 }
 /** Invalidate the affected tour page after a JetEngine layout preference is saved. */
 static function on_layout_meta_change($meta_id,$post_id,$meta_key,$meta_value):void {
  if(!in_array((string)$meta_key,
     [self::ORDER,'tour_rates_display_style','extra_options_display_mode',
      'mad4b_tour_rates_locale_policy_v1','mad4b_tour_rate_fallback_policy_v1'],true))return;
  $post_id=absint($post_id);
  if($post_id<=0||get_post_type($post_id)!=='tours-and-activities')return;
  if(get_post_status($post_id)!=='publish')return;
  do_action('litespeed_purge_post',$post_id);
 }
 static function menu():void {
  add_submenu_page('edit.php?post_type=tours-and-activities','Tour Content Layout','Tour Content Layout','manage_options','mad4b-tour-layout',[__CLASS__,'page']);
 }
 static function page():void {
  if(!current_user_can('manage_options'))return;
  $sections=(array)(self::sections()['sections']??[]);
  $saved=(array)get_option(self::GLOBAL,[]);
  $ids=array_merge(array_values(array_intersect($saved,array_keys($sections))),array_values(array_diff(array_keys($sections),$saved)));
  echo '<div class="wrap"><h1>Tour Content · Global drag & drop</h1><p>Reorder the Elementor sections globally. Per-tour overrides are available inside JetEngine Page Options. Content and display conditions remain intact.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field('mad4b_tour_layout_global');
  echo '<input type="hidden" name="action" value="mad4b_tour_layout_global">';
  echo '<input type="hidden" name="expected_layout_sha256" value="'.esc_attr(hash('sha256',wp_json_encode($saved))).'">';
  echo '<ul id="mad4b-tour-sections" style="max-width:720px">';
  foreach($ids as $id)echo '<li style="padding:12px;margin:6px 0;border:1px solid #ccd0d4;background:#fff;cursor:move"><span class="dashicons dashicons-move"></span> '.esc_html($sections[$id]).' <code>'.esc_html($id).'</code><input type="hidden" name="sections[]" value="'.esc_attr($id).'"></li>';
  echo '</ul>';submit_button('Save global order');echo '<button type="submit" name="reset_layout" value="1" class="button button-secondary">Restore Elementor source order</button>';echo '</form></div>';
  wp_enqueue_script('jquery-ui-sortable');
  wp_add_inline_script('jquery-ui-sortable','jQuery(function($){$("#mad4b-tour-sections").sortable({axis:"y"});});');
 }
 static function save():void {
  if(!current_user_can('manage_options')||!check_admin_referer('mad4b_tour_layout_global'))wp_die('Unauthorized');
  $allowed=array_keys((array)(self::sections()['sections']??[]));$order=[];
  $previous=(array)get_option(self::GLOBAL,[]);
  $expected=isset($_POST['expected_layout_sha256'])
   ?sanitize_text_field(wp_unslash($_POST['expected_layout_sha256'])):'';
  if(!preg_match('/^[a-f0-9]{64}$/D',$expected)
   ||!hash_equals(hash('sha256',wp_json_encode($previous)),$expected))
   wp_die('The saved global layout changed. Reload before saving.','Stale layout',['response'=>409]);
  if(!isset($_POST['reset_layout']) && (!isset($_POST['sections']) || !is_array($_POST['sections']) || !$_POST['sections']))
   wp_die('No section order was submitted. Settings have not been reset.','Missing layout fields',['response'=>422]);
  foreach((array)($_POST['sections']??[]) as $raw){
   $id=sanitize_key(wp_unslash((string)$raw));
   if(in_array($id,$allowed,true)&&!in_array($id,$order,true))$order[]=$id;
  }
  if(isset($_POST['reset_layout']))$order=[];
  if(!$order && !isset($_POST['reset_layout']) && $allowed)
   wp_die('The submitted section IDs do not match the saved layout schema.','Invalid layout',['response'=>422]);
  $written=update_option(self::GLOBAL,$order,false);
  if((!$written && get_option(self::GLOBAL,null)!==$order)
    ||get_option(self::GLOBAL,null)!==$order)
   wp_die('Global layout could not be saved and verified.','Settings not saved',['response'=>500]);
  $changed=$previous!==$order;
  // Only purge full-page caches after a verified, real state change.
  if($changed&&has_action('mad4b/public_html/safe_purge_all'))do_action('mad4b/public_html/safe_purge_all', 'tour_content_layout');
  wp_safe_redirect(admin_url('edit.php?post_type=tours-and-activities&page=mad4b-tour-layout&updated='.($changed?'1':'unchanged')));exit;
 }
 static function init():void {
  add_filter('jet-engine/meta-boxes/raw-fields',[__CLASS__,'fields'],30);
  add_action('admin_init',[__CLASS__,'register_box'],100);
  add_action('admin_init',static function(){if(current_user_can('manage_options'))MAD4B_Tour_Layout_Control_V1::ensure_photo_focus_schema();},102);
  add_action('admin_menu',[__CLASS__,'menu'],30);
  add_action('added_post_meta',[__CLASS__,'on_layout_meta_change'],90,4);
  add_action('updated_post_meta',[__CLASS__,'on_layout_meta_change'],90,4);
  add_action('deleted_post_meta',[__CLASS__,'on_layout_meta_change'],90,4);
  add_action('admin_post_mad4b_tour_layout_global',[__CLASS__,'save']);
  add_filter('elementor/frontend/builder_content_data',[__CLASS__,'sort_builder_data'],85,2);
  add_filter('pre_option_elementor_element_cache_ttl',[__CLASS__,'content_cache_policy'],40,1);
  add_action('elementor/frontend/container/before_render',[__CLASS__,'render_order'],35);
  add_action('elementor/frontend/widget/before_render',[__CLASS__,'render_order'],35);
  add_action('wp_head',[__CLASS__,'css'],70);
  add_action('wp_footer',[__CLASS__,'frontend_order_script'],90);
 }
}
MAD4B_Tour_Layout_Control_V1::init();
