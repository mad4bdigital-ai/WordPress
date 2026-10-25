<?php
/**
 * ETG unified traveller configuration & identity resolver.
 * Keeps product units, pricing, nationality, market, language and payment separate.
 * No payment/order mutation. Fail closed until a server-certified booking exists.
 */
if (!defined('ABSPATH')) exit;
final class ETG_Traveller_Management {
 const CONTRACT='etg.traveller-management.v1';
 const COUNT_CONTRACT='etg.traveller-count.v1';
 const OPTION='etg_traveller_management_v1';
 const META='_etg_traveller_preset_id';
 const CERTIFIED_MAX=20;
 const TYPES=['tour-rates','tours-and-activities','properties','transportations','product'];
 public static function builtins(){
  return [
   'automatic'=>['label'=>'Automatic — Verified Source','source'=>'verified','inherit'=>true,'nationality'=>'service','max'=>20],
   'tour'=>['label'=>'Tours & Activities','source'=>'verified','inherit'=>true,'nationality'=>'service','max'=>20],
   'accommodation'=>['label'=>'Accommodation','source'=>'verified','inherit'=>true,'nationality'=>'service','max'=>20],
   'transport'=>['label'=>'Transport','source'=>'verified','inherit'=>true,'nationality'=>'service','max'=>20],
   'extra'=>['label'=>'Optional Service','source'=>'verified','inherit'=>true,'nationality'=>'service','max'=>20],
  ];
 }
 public static function initial(){
  return ['revision'=>0,'default'=>'automatic','custom'=>[],'overrides'=>[]];
 }
 public static function state(){
  $raw=get_option(self::OPTION,[]);
  if(!is_array($raw))$raw=[];
  $state=array_replace(self::initial(),$raw);
  foreach(['custom','overrides'] as $k)if(!is_array($state[$k]))$state[$k]=[];
  return $state;
 }
 public static function clean_fields($raw){
  if(!is_array($raw))return new WP_Error('etg_traveller_preset_invalid');
  $label=trim(sanitize_text_field((string)($raw['label']??'')));
  $source=(string)($raw['source']??'verified');
  $nationality=(string)($raw['nationality']??'service');
  $max=filter_var($raw['max']??null,FILTER_VALIDATE_INT);
  if($label===''||strlen($label)>90||!in_array($source,['verified','manual_server_validated'],true)
   ||!in_array($nationality,['service','required','optional'],true)||!in_array($max,[20,50,100],true))
    return new WP_Error('etg_traveller_preset_invalid');
  return ['label'=>$label,'source'=>$source,'inherit'=>filter_var($raw['inherit']??false,FILTER_VALIDATE_BOOLEAN),
   'nationality'=>$nationality,'max'=>$max];
 }
 public static function presets(){
  $s=self::state();$all=self::builtins();
  foreach($s['overrides'] as $id=>$value){
   if(isset($all[$id])&&is_array($value)){
    $clean=self::clean_fields($value);
    if(!is_wp_error($clean))$all[$id]=$clean;
   }
  }
  foreach($s['custom'] as $id=>$entry){
   if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_-]{2,40}$/D',$id)||isset($all[$id])||!is_array($entry))continue;
   $clean=self::clean_fields($entry['fields']??null);
   if(!is_wp_error($clean))$all[$id]=$clean;
  }
  return $all;
 }
 public static function preset_for($post_id){
  $id=(string)get_post_meta((int)$post_id,self::META,true);
  $all=self::presets();
  if($id===''||!isset($all[$id])){
   $default=(string)self::state()['default'];
   $id=isset($all[$default])?$default:'automatic';
  }
  return ['id'=>$id,'fields'=>$all[$id]];
 }
 /** A requested 50/100-person limit never overrides the 20-person certified checkout cap. */
 public static function certified_limit($post_id){
  return min(self::CERTIFIED_MAX,(int)self::preset_for($post_id)['fields']['max']);
 }
 private static function verified_tour_rate($line,$product){
  $saved=$line['shared_booking_configuration']??null;
  if(!is_array($saved)||!function_exists('shared_product_booking_configuration_matches') ||
    (int)($line['quantity']??0)!==1||(int)($saved['rate_id']??0)!==$product->get_id())return 0;
  if(!empty($line['shared_product_quote']))return 0; // competing price authority
  $fresh=shared_product_booking_configuration_matches($saved);
  if(is_wp_error($fresh)||!is_array($fresh)||(int)($fresh['rate_id']??0)!==$product->get_id())return 0;
  $raw=$fresh['quantities']['persons']??null;
  $count=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>self::certified_limit($product->get_id())]]);
  if($count===false||$count===null)return 0;
  $other=$fresh['options']['travelers']??null;
  if($other!==null){
   $other=filter_var($other,FILTER_VALIDATE_INT);
   if($other!==$count)return 0;
  }
  return $count;
 }
 /**
  * Extensible provider contract for FUTURE verified adapters only.
  * Adapter MUST perform its own server-side re-quote, product binding and inventory validation.
  * Plain form data, checkout request, draft v2, and accommodation shadow quotes never qualify.
  */
 public static function resolve($line){
  $product=$line['data']??null;
  if(!$product instanceof WC_Product)return ['status'=>'unavailable','count'=>0,'source'=>'not_a_woo_product'];
  $id=$product->get_id();$type=get_post_type($id);
  if(!in_array($type,self::TYPES,true))return ['status'=>'not_applicable','count'=>0,'source'=>'unsupported_product'];
  if($type==='product')return ['status'=>'not_applicable','count'=>0,'source'=>'ordinary_product'];
  $preset=self::preset_for($id);
  if($type==='tour-rates'){
   $count=self::verified_tour_rate($line,$product);
   if($count)return ['status'=>'verified','count'=>$count,'source'=>'shared_booking_configuration','preset'=>$preset['id']];
  }
  // At present these types lack a certified cart/financial booking bridge.
  // Provider hook is inactive unless a registered server adapter explicitly certifies its count.
  $candidate=apply_filters('etg_traveller_management/verified_count',null,$line,$type);
  if(is_array($candidate)&&($candidate['contract']??'')===self::COUNT_CONTRACT
   &&($candidate['authority']??'')==='server_verified'
   &&(int)($candidate['product_id']??0)===$id
   &&(int)($candidate['cart_quantity']??0)===(int)($line['quantity']??0)
   &&is_string($candidate['source']??null)
   &&in_array($candidate['source'],['booking_v2_certified','accommodation_checkout_certified','transport_checkout_certified'],true)
   &&((['tours-and-activities'=>'booking_v2_certified','properties'=>'accommodation_checkout_certified','transportations'=>'transport_checkout_certified'][$type]??null)===$candidate['source'])
   &&($candidate['checkout_ready']??false)===true){
   $raw=$candidate['count']??null;
   $count=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>self::certified_limit($id)]]);
   if($count!==false&&$count!==null)
    return ['status'=>'verified','count'=>$count,'source'=>$candidate['source'],'preset'=>$preset['id']];
  }
  return ['status'=>'unverified','count'=>0,'source'=>$type==='tour-rates'?'missing_or_stale_server_quote':'checkout_adapter_not_certified','preset'=>$preset['id']];
 }
 /** Booking scoped ephemeral traveller IDs; no nationality or passport PII is persisted. */
 public static function registry($cart){
  $roots=[];$services=[];$inputs=is_array($cart)?$cart:[];
  foreach($inputs as $key=>$line){
   $result=self::resolve($line);
   if(($result['status']??'')!=='verified')continue;
   $k=(string)$key;$n=$result['count'];$people=[];
   for($i=0;$i<$n;$i++)$people[]=['id'=>substr(hash_hmac('sha256',$k.':'.$i,wp_salt('auth')),0,24),'index'=>$i];
   $roots[$k]=['cart_key'=>$k,'registry_id'=>substr(hash_hmac('sha256','booking:'.$k,wp_salt('auth')),0,24),
    'product_id'=>(int)$line['product_id'],'people'=>$people,'count'=>$n,'source'=>$result['source']];
  }
  // Service links are supplied by an authorized PHP server adapter, not by browser/cart metadata.
  $links=apply_filters('etg_traveller_management/verified_service_links',[],$inputs);
  if(is_array($links))foreach($links as $link){
   if(!is_array($link)||($link['authority']??'')!=='server_verified'
    ||($link['contract']??'')!==self::CONTRACT)continue;
   $service=(string)($link['service_key']??'');$parent=(string)($link['booking_key']??'');
   if($service===''||!array_key_exists($service,$inputs)||!isset($roots[$parent])||$service===$parent||isset($services[$service]))continue;
   $parent_preset=self::preset_for($roots[$parent]['product_id']);
   if(!$parent_preset['fields']['inherit'])continue;
   $service_product=(int)$inputs[$service]['product_id'];
   if($service_product<1 || !get_post_type($service_product))continue;
   $root=$roots[$parent];
   // A server-set flag alone cannot make an arbitrary item a dependent service.
   // Verify the existing Tour Rate -> Optional Tour relationship at its source.
   $relationship=false;
   if(get_post_type($root['product_id'])==='tour-rates' &&
      get_post_type($service_product)==='tours-and-activities' &&
      function_exists('shared_product_allowed_optional_tour_ids')){
    $allowed=shared_product_allowed_optional_tour_ids($root['product_id']);
    $relationship=!is_wp_error($allowed)&&is_array($allowed)&&in_array($service_product,$allowed,true);
   }else{
    // Accommodation / Transport cross-service providers must prove their relationship.
    $relationship=apply_filters('etg_traveller_management/certified_relationship',false,$root,$inputs[$service],$link)===true;
   }
   if(!$relationship)continue;
   $indices=$link['traveller_indices']??null;
   if(!is_array($indices)||!array_is_list($indices)||!$indices||count($indices)>$root['count'])continue;
   $normalized=[];$valid=true;
   foreach($indices as $idx){
    if(!is_int($idx)||$idx<0||$idx>=$root['count']||isset($normalized[$idx])){$valid=false;break;}
    $normalized[$idx]=$root['people'][$idx]['id'];
   }
   if(!$valid)continue;
   $services[$service]=['booking_key'=>$parent,'parent_registry_id'=>$root['registry_id'],'product_id'=>(int)$inputs[$service]['product_id'],
    'traveller_indices'=>array_keys($normalized),'traveller_ids'=>array_values($normalized),'count'=>count($normalized),'inherited'=>true];
  }
  return ['contract'=>self::CONTRACT,'bookings'=>$roots,'linked_services'=>$services,'status'=>'in_memory_only'];
 }
 /** Optimistic revision and nonce are enforced by the admin endpoint, including reset. */
 public static function mutate($mode,$id,$raw,$expected){
  $s=self::state();if((int)$expected!==(int)$s['revision'])return new WP_Error('etg_traveller_stale_revision');
  $built=self::builtins();$id=sanitize_key((string)$id);
  if($mode==='global'){
   if(!isset(self::presets()[$id]))return new WP_Error('etg_traveller_unknown_preset');
   $s['default']=$id;
  }elseif($mode==='save'){
   $fields=self::clean_fields($raw);if(is_wp_error($fields))return $fields;
   if(isset($built[$id]))$s['overrides'][$id]=$fields;
   elseif(isset($s['custom'][$id]))$s['custom'][$id]['fields']=$fields;
   else return new WP_Error('etg_traveller_unknown_preset');
  }elseif($mode==='clone'){
   $source=sanitize_key((string)($raw['source_preset']??''));
   $new=sanitize_key((string)($raw['new_id']??''));
   if(!isset(self::presets()[$source])||!preg_match('/^[a-z][a-z0-9_-]{2,40}$/D',$new)
    ||isset(self::presets()[$new])||count($s['custom'])>=30)return new WP_Error('etg_traveller_clone_invalid');
   $fields=self::presets()[$source];
   if(isset($raw['label']))$fields['label']=(string)$raw['label'];
   $fields=self::clean_fields($fields);if(is_wp_error($fields))return $fields;
   $s['custom'][$new]=['origin'=>$source,'fields'=>$fields];
  }elseif($mode==='reset'){
   if(isset($built[$id]))unset($s['overrides'][$id]);
   elseif(isset($s['custom'][$id])){
    $origin=(string)($s['custom'][$id]['origin']??'automatic');
    $s['custom'][$id]['fields']=self::presets()[$origin]??$built['automatic'];
   }else return new WP_Error('etg_traveller_unknown_preset');
  }else return new WP_Error('etg_traveller_action_invalid');
  $s['revision']++;update_option(self::OPTION,$s,false);
  $check=self::state();
  return (int)$check['revision']===(int)$s['revision'] ? $s : new WP_Error('etg_traveller_write_verification_failed');
 }
 public static function admin_url(){return admin_url('admin.php?page=etg-traveller-management');}
 public static function admin_action(){
  if(!current_user_can('manage_woocommerce'))wp_die('Forbidden','',['response'=>403]);
  check_admin_referer('etg_traveller_management_save','_etg_traveller_nonce');
  $mode=sanitize_key((string)wp_unslash($_POST['mode']??''));
  $id=sanitize_key((string)wp_unslash($_POST['preset_id']??''));
  $expected=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
  if($expected===false||$expected===null)wp_die('Revision required','',['response'=>400]);
  $raw=is_array($_POST['fields']??null)?wp_unslash($_POST['fields']):[];
  $result=self::mutate($mode,$id,$raw,$expected);
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'',['response'=>409]);
  wp_safe_redirect(add_query_arg('updated',rawurlencode($mode),self::admin_url()));exit;
 }
 public static function admin_page(){
  if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');
  $s=self::state();$all=self::presets();$selected=sanitize_key((string)($_GET['preset']??$s['default']));
  if(!isset($all[$selected]))$selected='automatic';$current=$all[$selected];
  $action=esc_url(admin_url('admin-post.php'));
  echo '<div class="wrap"><h1>ETG Traveller Management</h1>';
  echo '<p>Booking persons are not WooCommerce Quantity. Only verified server booking sources can enable checkout collection. No payment or pricing settings are changed.</p>';
  if(isset($_GET['updated']))echo '<div class="notice notice-success"><p>Traveller settings updated.</p></div>';
  echo '<h2>Global Default</h2><form method="post" action="'.$action.'"><input type="hidden" name="action" value="etg_tm_action"><input type="hidden" name="mode" value="global"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">';
  wp_nonce_field('etg_traveller_management_save','_etg_traveller_nonce');
  echo '<select name="preset_id">';foreach($all as $id=>$p)echo '<option value="'.esc_attr($id).'" '.selected($s['default'],$id,false).'>'.esc_html($p['label']).'</option>';
  echo '</select> ';submit_button('Set global preset','primary','submit',false);echo '</form>';
  echo '<hr><h2>Customize, Clone or Reset Preset</h2><p>Presets are optional. Reset restores built-in defaults without changing stored bookings.</p>';
  echo '<form method="get"><input type="hidden" name="page" value="etg-traveller-management"><label>Template <select name="preset">';
  foreach($all as $id=>$p)echo '<option value="'.esc_attr($id).'" '.selected($selected,$id,false).'>'.esc_html($p['label']).'</option>';
  echo '</select></label> <button class="button">Open</button></form>';
  echo '<form method="post" action="'.$action.'"><input type="hidden" name="action" value="etg_tm_action"><input type="hidden" name="preset_id" value="'.esc_attr($selected).'"><input type="hidden" name="revision" value="'.(int)$s['revision'].'">';
  wp_nonce_field('etg_traveller_management_save','_etg_traveller_nonce');
  echo '<table class="form-table"><tr><th>Display name</th><td><input name="fields[label]" type="text" maxlength="90" class="regular-text" value="'.esc_attr($current['label']).'"></td></tr>';
  echo '<tr><th>Count authority</th><td><select name="fields[source]">';
  foreach(['verified'=>'Automatic — Verified Source','manual_server_validated'=>'Manual — SERVER validated only'] as $value=>$label)echo '<option value="'.$value.'" '.selected($current['source'],$value,false).'>'.esc_html($label).'</option>';
  echo '</select></td></tr><tr><th>Linked service inheritance</th><td><select name="fields[inherit]"><option value="1" '.selected($current['inherit'],true,false).'>Enabled</option><option value="0" '.selected($current['inherit'],false,false).'>Disabled</option></select></td></tr>';
  echo '<tr><th>Nationality policy</th><td><select name="fields[nationality]">';
  foreach(['service'=>'Service requirements (recommended)','required'=>'Required','optional'=>'Optional only when service permits'] as $value=>$label)echo '<option value="'.$value.'" '.selected($current['nationality'],$value,false).'>'.esc_html($label).'</option>';
  echo '</select><p class="description">These labels do not bypass checkout-required nationalities until the service adapter is certified.</p></td></tr>';
  echo '<tr><th>Requested capacity</th><td><select name="fields[max]">';
  foreach([20,50,100] as $limit)echo '<option value="'.$limit.'" '.selected($current['max'],$limit,false).'>'.$limit.($limit>self::CERTIFIED_MAX?' (requires extended QA)':' (currently certified)').'</option>';
  echo '</select><p>Checkout remains capped at '.self::CERTIFIED_MAX.' until expanded acceptance.</p></td></tr></table>';
  echo '<p><button class="button button-primary" type="submit" name="mode" value="save">Save changes</button> <button class="button" type="submit" name="mode" value="reset">Reset preset</button></p>';
  echo '</form>';
  echo '<h2>Save a New Copy</h2><form method="post" action="'.$action.'"><input type="hidden" name="action" value="etg_tm_action"><input type="hidden" name="mode" value="clone"><input type="hidden" name="preset_id" value="'.esc_attr($selected).'"><input type="hidden" name="revision" value="'.(int)$s['revision'].'"><input type="hidden" name="fields[source_preset]" value="'.esc_attr($selected).'">';
  wp_nonce_field('etg_traveller_management_save','_etg_traveller_nonce');
  echo '<label>Unique slug <input name="fields[new_id]" required pattern="[a-z][a-z0-9_-]{2,40}" placeholder="family-tour"></label> <label>Name <input name="fields[label]" value="'.esc_attr($current['label'].' Copy').'"></label> ';
  submit_button('Save as new preset','secondary','submit',false);echo '</form>';
  echo '<p><strong>Per-product override:</strong> use the Traveller Preset box in the product/CPT editor. Ordinary products never become traveller bookings through a preset.</p></div>';
 }
 public static function box($post){
  $current=(string)get_post_meta($post->ID,self::META,true);
  wp_nonce_field('etg_tm_product_'.$post->ID,'_etg_tm_product_nonce');
  echo '<p><label for="etg_tm_product_preset">Traveller preset</label><select id="etg_tm_product_preset" name="etg_tm_product_preset" style="width:100%"><option value="">Inherit global default</option>';
  foreach(self::presets() as $id=>$preset)echo '<option value="'.esc_attr($id).'" '.selected($current,$id,false).'>'.esc_html($preset['label']).'</option>';
  echo '</select></p><p>Count stays server-verified. Currency and pricing do not change.</p>';
 }
 public static function save_product($id){
  if(!in_array(get_post_type($id),self::TYPES,true)||!current_user_can('edit_post',$id)
   ||defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE||wp_is_post_revision($id))return;
  $nonce=$_POST['_etg_tm_product_nonce']??null;
  if(!is_string($nonce)||!wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),'etg_tm_product_'.$id))return;
  $value=sanitize_key((string)wp_unslash($_POST['etg_tm_product_preset']??''));
  if($value===''||!isset(self::presets()[$value]))delete_post_meta($id,self::META);
  else update_post_meta($id,self::META,$value);
 }
 public static function boot(){
  add_action('admin_menu',static function(){
   if(class_exists('WooCommerce'))add_submenu_page('woocommerce','Traveller Management','Traveller Management','manage_woocommerce','etg-traveller-management',[__CLASS__,'admin_page']);
  },96);
  add_action('woocommerce_settings_tabs_booking_experience',static function(){
   if(!current_user_can('manage_woocommerce'))return;
   echo '<hr><h2>ETG · Traveller Management</h2><p>Verified passenger counts, reusable presets, per-product configuration and linked-service assignments. <a href="'.esc_url(self::admin_url()).'">Manage Traveller Presets</a>.</p>';
  },35);
  add_action('admin_post_etg_tm_action',[__CLASS__,'admin_action']);
  add_action('add_meta_boxes',static function(){
   foreach(self::TYPES as $type)if(post_type_exists($type))add_meta_box('etg-tm-preset','Traveller Preset',[__CLASS__,'box'],$type,'side','default');
  });
  add_action('save_post',[__CLASS__,'save_product'],30);
 }
}
add_action('plugins_loaded',['ETG_Traveller_Management','boot'],55);
