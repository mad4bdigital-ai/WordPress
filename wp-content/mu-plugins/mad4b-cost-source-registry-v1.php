<?php
/** Plugin Name: MAD4B Dynamic Cost Source Registry
 * Tenant-scoped reference mappings. No receipt signing or commercial price authority.
 */
defined('ABSPATH') || exit;
final class MAD4B_Cost_Source_Registry_V1 {
 const OPTION='mad4b_cost_source_registry_v1';
 const PAGE='mad4b-cost-source-registry';
 const BASIS=['per_person','per_person_night','per_room_night','per_cabin_night','per_guide_day','per_vehicle','per_trip','per_group','per_day','per_item','per_stay','per_person_trip','per_cabin_trip'];
 static function manageable():bool {return current_user_can('manage_options')&&current_user_can('commerce_manage_pricing');}
 static function validKey($v):bool {return is_string($v)&&preg_match('/^[a-zA-Z0-9_:-]{1,100}$/D',$v)===1;}
 static function eligible(string $kind,string $slug):bool {
  if($kind==='post_type'){
   $o=get_post_type_object($slug);
   return $o&&$o->show_ui&&!in_array($slug,['attachment','shop_order','shop_order_refund','shop_coupon','tour_orders','tour_bookings','revision','user_request'],true);
  }
  if($kind==='taxonomy'){$o=get_taxonomy($slug);return $o&&$o->show_ui;}
  return false;
 }
 static function validate(array $in):array|WP_Error {
  $kind=$in['kind']??'';$slug=$in['slug']??'';
  if(!is_string($kind)||!is_string($slug)||!self::eligible($kind,$slug))return new WP_Error('cost_source_type_invalid');
  $amount=$in['amount_field']??'';$relation=$in['relation_field']??'';
  $currency=$in['currency']??'';$basis=$in['basis']??'';
  $currencyField=$in['currency_field']??'';$basisField=$in['basis_field']??'';
  if(!self::validKey($amount)||!is_string($relation)||($relation!==''&&!self::validKey($relation))||
   ($kind==='taxonomy'&&$relation!=='')||
   !is_string($currencyField)||($currencyField!==''&&!self::validKey($currencyField))||
   !is_string($basisField)||($basisField!==''&&!self::validKey($basisField))||
   !in_array($currency,['USD','EGP','EUR','GBP'],true)||!in_array($basis,self::BASIS,true))
   return new WP_Error('cost_source_mapping_invalid');
  // Only numeric cost references; never return arbitrary meta, secrets, callbacks or SQL.
  return ['kind'=>$kind,'slug'=>$slug,'amount_field'=>$amount,'relation_field'=>$relation,
   'currency'=>$currency,'basis'=>$basis,'currency_field'=>$currencyField,'basis_field'=>$basisField,'role'=>'unverified_cost_reference'];
 }
 static function mappings():array {
  $stored=get_option(self::OPTION,[]);$out=[];
  foreach(is_array($stored)?$stored:[] as $raw){
   if(!is_array($raw))continue;$v=self::validate($raw);
   if(!is_wp_error($v))$out[$v['kind'].':'.$v['slug'].':'.$v['amount_field']]=$v;
  }
  ksort($out);return $out;
 }
 static function types(array $native):array {
  foreach(self::mappings() as $m)if($m['kind']==='post_type'){
   $o=get_post_type_object($m['slug']);$native[$m['slug']]=$o->labels->singular_name??$m['slug'];
  }
  return $native;
 }
 static function amount($raw):?string {
  if(!is_string($raw)&&!is_int($raw)&&!is_float($raw))return null;
  $s=(string)$raw;
  return preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,4})?$/D',$s)&&(float)$s>0?$s:null;
 }
 static function rows(int $id,string $type):array {
  if(!current_user_can('commerce_manage_pricing')||!current_user_can('edit_post',$id))return [];
  $out=[];
  foreach(self::mappings() as $key=>$m){
   $slug=$m['slug'];$kind=$m['kind'];$ids=[];
   if($kind==='taxonomy'){
    if(!is_object_in_taxonomy($type,$slug))continue;
    $terms=wp_get_object_terms($id,$slug,['fields'=>'ids']);
    if(is_wp_error($terms))continue;
    $ids=$terms;
   }elseif($type===$slug)$ids=[$id];
   elseif($m['relation_field']!==''){
    $raw=get_post_meta($id,$m['relation_field'],true);
    $ids=is_array($raw)?$raw:[$raw];
   }
   $seen=[];
   foreach(array_slice($ids,0,50) as $raw){
    if(!is_scalar($raw)||!preg_match('/^[1-9][0-9]*$/D',(string)$raw))continue;
    $sid=(int)$raw;if(isset($seen[$sid]))continue;$seen[$sid]=true;
    if($kind==='taxonomy'){
     $term=get_term($sid,$slug);
     if(!$term||is_wp_error($term)||!current_user_can('edit_term',$sid))continue;
     $amount=self::amount(get_term_meta($sid,$m['amount_field'],true));$label=$term->name;
     $currency=$m['currency_field']!==''?get_term_meta($sid,$m['currency_field'],true):$m['currency'];
     $basis=$m['basis_field']!==''?get_term_meta($sid,$m['basis_field'],true):$m['basis'];
     $url=get_edit_term_link($sid,$slug);
    }else{
     if(get_post_type($sid)!==$slug||!current_user_can('edit_post',$sid))continue;
     $amount=self::amount(get_post_meta($sid,$m['amount_field'],true));$label=get_the_title($sid);
     $currency=$m['currency_field']!==''?get_post_meta($sid,$m['currency_field'],true):$m['currency'];
     $basis=$m['basis_field']!==''?get_post_meta($sid,$m['basis_field'],true):$m['basis'];
     $url=get_edit_post_link($sid,'raw');
    }
    $currency=in_array($currency,['USD','EGP','EUR','GBP'],true)?$currency:null;
    $basis=in_array($basis,self::BASIS,true)?$basis:null;
    $out[]=['component'=>wp_strip_all_tags($label).' · '.$m['amount_field'],'source'=>'registry:'.$key,
     'status'=>$amount===null||$currency===null||$basis===null?'missing':'reverify',
     'value'=>['entity_kind'=>$kind,'entity_type'=>$slug,'entity_id'=>$sid,
      'amount'=>$amount,'currency'=>$currency,'basis'=>$basis,
      'mapping'=>$m,'native_edit_url'=>$url?:null,'commercially_authorized'=>false,
      'cost_receipt_qualified'=>false,'included_in_commercial_total'=>false]];
    if(count($out)>=100)return $out;
   }
  }
  return $out;
 }
 static function save():void {
  if(!self::manageable())wp_die('Registry configuration denied','Denied',['response'=>403]);
  check_admin_referer('mad4b_cost_source_registry');
  $raw=[];foreach(['kind','slug','amount_field','relation_field','currency','basis','currency_field','basis_field'] as $k){
   $v=$_POST[$k]??'';if(!is_string($v))wp_die('Invalid mapping');
   $raw[$k]=wp_unslash($v);
  }
  $v=self::validate($raw);if(is_wp_error($v))wp_die(esc_html($v->get_error_code()));
  global $wpdb;$lock='mad4b_cost_source_registry_'.get_current_blog_id();
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)wp_die('Registry busy');
  try{
   $all=get_option(self::OPTION,[]);if(!is_array($all))wp_die('Registry needs repair');
   $key=$v['kind'].':'.$v['slug'].':'.$v['amount_field'];if(count($all)>=100&&!isset($all[$key]))wp_die('Registry limit reached');
   if(($_POST['operation']??'')==='remove')unset($all[$key]);else $all[$key]=$v;
   update_option(self::OPTION,$all,false);
   if(get_option(self::OPTION,[])!==$all)wp_die('Registry readback mismatch');
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
  wp_safe_redirect(admin_url('admin.php?page='.self::PAGE));exit;
 }
 static function page():void {
  if(!self::manageable())wp_die('Registry configuration denied');
  echo '<div class="wrap"><h1>Cost Source Registry</h1><p>Map existing numeric source fields; references only. Registered sources never authorize supplier cost, publishing or payment. Existing native providers remain authoritative.</p>';
  echo '<p>Post types: own cost or explicit related post IDs in a product meta field. Taxonomies: terms actually assigned to the product; no arbitrary cross-product discovery.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Kind / source</th><th>Amount field</th><th>Relation</th><th>Currency / basis</th></tr></thead><tbody>';
  foreach(self::mappings() as $m)echo '<tr><td>'.esc_html($m['kind'].':'.$m['slug']).'</td><td>'.esc_html($m['amount_field']).'</td><td>'.esc_html($m['relation_field']?:'Native assignment / own record').'</td><td>'.esc_html($m['currency'].' / '.$m['basis']).'</td></tr>';
  echo '</tbody></table><h2>Add / update / remove mapping</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field('mad4b_cost_source_registry');
  echo '<input type="hidden" name="action" value="mad4b_cost_source_registry_save"><p><label>Source kind <select name="kind"><option value="post_type">Post type</option><option value="taxonomy">Taxonomy</option></select></label></p>';
  echo '<p><label>Registered slug <input name="slug" required list="mad4b-cost-source-slugs"></label><datalist id="mad4b-cost-source-slugs">';
  foreach(get_post_types(['show_ui'=>true],'names') as $slug)if(self::eligible('post_type',$slug))echo '<option value="'.esc_attr($slug).'">Post type</option>';
  foreach(get_taxonomies(['show_ui'=>true],'names') as $slug)echo '<option value="'.esc_attr($slug).'">Taxonomy</option>';
  echo '</datalist></p><p><label>Numeric cost meta field <input required name="amount_field"></label></p><p><label>Related post IDs meta field (optional, post type only) <input name="relation_field"></label></p><p><label>Source currency <select name="currency">';
  foreach(['USD','EGP','EUR','GBP'] as $cur)echo '<option>'.esc_html($cur).'</option>';
  echo '</select></label> <label>Cost basis <select name="basis">';
  foreach(self::BASIS as $basis)echo '<option>'.esc_html($basis).'</option>';
  echo '</select></label></p><p><label>Currency meta field (optional, overrides fixed currency) <input name="currency_field"></label></p><p><label>Cost basis meta field (optional, overrides fixed basis) <input name="basis_field"></label></p><button class="button button-primary" name="operation" value="save">Save mapping</button> <button class="button" name="operation" value="remove">Remove mapping</button></form></div>';
 }
 static function boot():void {
  add_action('admin_post_mad4b_cost_source_registry_save',[self::class,'save']);
  add_action('admin_menu',static function(){if(self::manageable())add_submenu_page('mad4b-pricing-workspace','Cost Source Registry','Cost Source Registry','manage_options',self::PAGE,[self::class,'page']);},871);
 }
}
MAD4B_Cost_Source_Registry_V1::boot();
