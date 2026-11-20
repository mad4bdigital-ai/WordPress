<?php
defined('ABSPATH') || exit;
/** MAD4B Recipe Workspace — human labels, preview and source-backed target discovery. */
final class MAD4B_JetEngine_Recipe_Workspace_V2 {
 private static function esc_options(array $values, string $selected=''): void {
  foreach($values as $value=>$title){
   echo '<option value="'.esc_attr((string)$value).'" '.selected((string)$value,$selected,false).'>'.esc_html($title).'</option>';
  }
 }
 public static function targets():array {
  $all=['options'=>[],'cpt'=>[],'form'=>[],'jetform'=>[]];
  if(class_exists('MAD4B_JetEngine_Field_Modes_V1')){
   foreach(MAD4B_JetEngine_Field_Modes_V1::pages() as $slug=>$data){
    $all['options'][$slug]=$data['label']??$slug;
   }
  }
  foreach(get_post_types(['show_ui'=>true],'objects') as $slug=>$type){
   if(in_array($slug,['attachment','jet-form-builder'],true))continue;
   $all['cpt'][$slug]=$type->labels->name??$slug;
  }
  $jfb=get_posts(['post_type'=>'jet-form-builder','post_status'=>['publish','draft','private'],
    'posts_per_page'=>150,'orderby'=>'title','order'=>'ASC','suppress_filters'=>true]);
  foreach($jfb as $post)$all['jetform'][(string)$post->ID]=get_the_title($post->ID).' (#'.$post->ID.')';
  // Native JetEngine legacy forms use a separate CPT or built-in Forms module.
  foreach(get_posts(['post_type'=>'jet-engine-form','post_status'=>['publish','draft'],
       'posts_per_page'=>150,'suppress_filters'=>true]) as $post)
   $all['form'][(string)$post->ID]=get_the_title($post->ID).' (#'.$post->ID.')';
  return $all;
 }
 public static function option_fields():array {
  $fields=[];
  if(!class_exists('MAD4B_JetEngine_Field_Modes_V1'))return $fields;
  foreach(MAD4B_JetEngine_Field_Modes_V1::pages() as $slug=>$data){
   foreach(($data['fields']??[]) as $field){
    $name=$field['name']??'';
    if(!$name)continue;
    $fields[$slug][$name]=$field['label']??$field['title']??$name;
   }
  }
  // JetFormBuilder field names come from its canonical Gutenberg blocks.
  // No form submission or render-time API calls are needed to discover them.
  foreach(get_posts(['post_type'=>'jet-form-builder','post_status'=>['publish','draft','private'],
       'posts_per_page'=>150,'suppress_filters'=>true]) as $post) {
   $walk=function(array $blocks) use (&$walk,&$fields,$post):void {
    foreach($blocks as $block) {
     $attrs=$block['attrs']??[];
     $name=$attrs['name']??$attrs['field_name']??'';
     if(is_string($name)&&preg_match('/^[a-zA-Z0-9_-]{1,110}$/D',$name))
      $fields[(string)$post->ID][$name]=(string)($attrs['label']??$name);
     if(!empty($block['innerBlocks']))$walk($block['innerBlocks']);
    }
   };
   $walk(parse_blocks($post->post_content));
  }
  return $fields;
 }
 public static function render():void {
  $data=MAD4B_JetEngine_Recipe_Registry_V1::registry();
  $targets=self::targets();$fields=self::option_fields();
  $count=count($data['interfaces'])+count($data['targets'])+count($data['groups'])+count($data['fields']);
  $status=sanitize_key($_GET['recipe_status']??'');
  echo '<div class="wrap mad4b-recipe-workspace" id="mad4b-recipe-workspace">';
  echo '<header class="mad4b-rw-hero"><div><p class="mad4b-rw-eyebrow">MAD4B · JETENGINE UI</p>';
  echo '<h1>Interface Recipe Workspace</h1><p>Choose how JetEngine fields look across settings, products and forms. ';
  echo 'Appearance is inherited automatically; original fields and Save actions remain unchanged.</p></div>';
  echo '<div class="mad4b-rw-current"><span>GLOBAL RECIPE</span><strong>'.esc_html(ucfirst($data['global'])).'</strong>';
  echo '<small>'.esc_html((string)$count).' custom rules</small></div></header>';
  if($status)echo '<div class="notice notice-'.esc_attr(in_array($status,['saved','deleted'],true)?'success':'error').'"><p>'.esc_html(ucfirst($status)).'</p></div>';
  echo '<div class="mad4b-rw-grid"><main class="mad4b-rw-main">';
  echo '<section class="mad4b-rw-panel"><div class="mad4b-rw-panel-head"><span class="mad4b-rw-step">01</span><div><h2>Choose where to apply the design</h2><p>Start with a product type, settings page or form.</p></div></div>';
  echo '<div class="mad4b-rw-family" role="group" aria-label="Interface type">';
  $families=['options'=>['Settings Pages','sliders-horizontal'],'cpt'=>['Products & CPTs','layers'],
    'form'=>['JetEngine Forms','file-text'],'jetform'=>['JetFormBuilder','layout-template']];
  foreach($families as $type=>$info){
   echo '<button type="button" class="mad4b-rw-family-btn'.($type==='options'?' is-active':'').'" data-interface="'.esc_attr($type).'" aria-pressed="'.($type==='options'?'true':'false').'"><strong>'.esc_html($info[0]).'</strong><small>'.esc_html((string)count($targets[$type])).' available</small></button>';
  }
  echo '</div></section>';
  echo '<form id="mad4b-rw-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="mad4b_jetengine_recipe_save">';
  wp_nonce_field('mad4b_jetengine_recipe_save');
  echo '<input type="hidden" name="interface" id="mad4b-rw-interface" value="options">';
  echo '<section class="mad4b-rw-panel"><div class="mad4b-rw-panel-head"><span class="mad4b-rw-step">02</span><div><h2>Choose the scope</h2><p>Apply everywhere or customize a specific interface and its fields.</p></div></div>';
  echo '<div class="mad4b-rw-levels" role="radiogroup" aria-label="Inheritance level">';
  $levels=['global'=>['Global','All supported interfaces'],'interface'=>['Interface','All of this interface type'],
   'target'=>['Page / Product','One settings page, CPT or form'],'group'=>['Field Group','One group of fields'],
   'field'=>['Individual Field','Just one field']];
  foreach($levels as $id=>$labels)echo '<label><input type="radio" name="layer" value="'.esc_attr($id).'" '.checked($id,'target',false).'><span><strong>'.esc_html($labels[0]).'</strong><small>'.esc_html($labels[1]).'</small></span></label>';
  echo '</div>';
  echo '<div class="mad4b-rw-fields">';
  echo '<label class="mad4b-rw-field" data-requires="target"><span>Which page or product?</span><select name="target" id="mad4b-rw-target">';
  echo '<option value="">Choose from available JetEngine interfaces…</option>';
  foreach($targets as $type=>$options)foreach($options as $id=>$name)
   echo '<option data-interface="'.esc_attr($type).'" value="'.esc_attr((string)$id).'" '.($type==='options'?'':'hidden').'>'.esc_html($name).'</option>';
  echo '</select></label>';
  echo '<label class="mad4b-rw-field" data-requires="group"><span>Field group</span>';
  echo '<input name="group" id="mad4b-rw-group" placeholder="Choose native field group (advanced)" list="mad4b-rw-groups">';
  echo '<datalist id="mad4b-rw-groups"></datalist></label>';
  echo '<label class="mad4b-rw-field" data-requires="field"><span>Field</span>';
  echo '<select name="field" id="mad4b-rw-field"><option value="">Choose a field…</option></select>';
  echo '<input type="text" name="field" id="mad4b-rw-field-manual" maxlength="110" placeholder="Native field name (advanced)" hidden disabled></label>';
  echo '</div></section>';
  echo '<section class="mad4b-rw-panel"><div class="mad4b-rw-panel-head"><span class="mad4b-rw-step">03</span><div><h2>Pick the visual style</h2><p>Preview the result before saving. Inherit follows the closest parent.</p></div></div>';
  echo '<div class="mad4b-rw-modes">';
  foreach(['cards'=>['Cards','Comfortable, rounded field cards'],'compact'=>['Compact','Higher density, smaller spaces'],'accordion'=>['Accordion','Expandable groups and fields'],'inherit'=>['Inherit','Use the parent recipe']] as $mode=>$labels)
   echo '<label class="mad4b-rw-mode"><input type="radio" name="style" value="'.esc_attr($mode).'" '.checked($mode,'cards',false).'><span class="mad4b-rw-mode-copy"><strong>'.esc_html($labels[0]).'</strong><small>'.esc_html($labels[1]).'</small></span><span class="mad4b-rw-mode-art mad4b-rw-art-'.esc_attr($mode).'"><i></i><i></i></span></label>';
  echo '</div><div class="mad4b-rw-actions"><button type="submit" class="button button-primary button-hero">Save Recipe</button>';
  echo '<p>This saves interface appearance only. It does not change pricing, content or bookings.</p></div>';
  echo '</section></form>';
  echo '</main><aside class="mad4b-rw-side"><section class="mad4b-rw-preview">';
  echo '<p class="mad4b-rw-eyebrow">LIVE STYLE PREVIEW</p><h2 id="mad4b-rw-preview-title">Cards</h2>';
  echo '<div class="mad4b-rw-demo mad4b-rw-demo-cards" id="mad4b-rw-demo">';
  echo '<div class="mad4b-rw-demo-field"><label>Example Field</label><input type="text" value="Private Egypt Tour" readonly tabindex="-1"></div>';
  echo '<div class="mad4b-rw-demo-field"><label>Booking Mode</label><select disabled><option>Request to Book</option></select></div>';
  echo '<div class="mad4b-rw-demo-field mad4b-rw-demo-toggle"><label>Show advanced settings</label><span>Expand</span></div>';
  echo '</div><p class="mad4b-rw-help">Illustrative preview, not an editable JetEngine form.</p></section>';
  echo '<section class="mad4b-rw-panel"><h2>Active overrides</h2>';
  if(!$count)echo '<p class="mad4b-rw-help">No overrides yet. Everything currently inherits the Cards default.</p>';
  echo '<div class="mad4b-rw-rules">';
  foreach(['interfaces'=>'Interface','targets'=>'Page / CPT / Form','groups'=>'Field Group','fields'=>'Field'] as $bucket=>$label){
   foreach($data[$bucket] as $key=>$mode){
    echo '<div class="mad4b-rw-rule"><div><small>'.esc_html($label).'</small><strong>'.esc_html($key).'</strong><span>'.esc_html(ucfirst($mode)).'</span></div>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_jetengine_recipe_delete">';
    echo '<input type="hidden" name="bucket" value="'.esc_attr($bucket).'"><input type="hidden" name="rule_key" value="'.esc_attr($key).'">';
    wp_nonce_field('mad4b_jetengine_recipe_delete');
    echo '<button class="button button-small" type="submit" aria-label="Remove '.esc_attr($key).'">Remove</button></form></div>';
   }
  }
  echo '</div></section></aside></div>';
  MAD4B_JetEngine_Recipe_Field_Matrix_V1::panel();
  echo '<script type="application/json" id="mad4b-rw-fields-data">'.wp_json_encode($fields,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
  echo '</div>';
 }
}
