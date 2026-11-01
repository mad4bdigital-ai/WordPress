<?php
/** Native WooCommerce administration for registry-backed age policies. */
if (!defined('ABSPATH')) exit;
final class MAD4B_Age_Occupancy_Admin {
 const PAGE='mad4b-age-occupancy';
 public static function init(){
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('admin_post_mad4b_age_policy_save',[__CLASS__,'save']);
  add_action('admin_post_mad4b_age_forms_save',[__CLASS__,'save_forms']);
 }
 public static function menu(){add_submenu_page('woocommerce','Age & Occupancy Policies','Age & Occupancy','manage_woocommerce',self::PAGE,[__CLASS__,'render']);}
 public static function current_target(){
  $scope=sanitize_key((string)($_GET['scope']??'global'));
  $domain=sanitize_key((string)($_GET['domain']??'accommodation'));
  $id=absint($_GET['target_id']??0);
  if(!in_array($scope,['global','product_type','supplier','product','unit','rate'],true))$scope='global';
  return compact('scope','domain','id');
 }
 public static function read($scope,$domain,$id){
  if($scope==='global')return get_option(MAD4B_Age_Aware_Stay::OPTION,[]);
  if($scope==='product_type'){
   $all=get_option('mad4b_age_occupancy_policy_product_types_v1',[]);
   return is_array($all)?($all[$domain]??[]):[];
  }
  return $id?get_post_meta($id,MAD4B_Age_Aware_Stay::META,true):[];
 }
 public static function validate($payload){
  if(!is_array($payload))return new WP_Error('age_policy_payload_invalid');
  $basis=$payload['age_reference']??'';
  if(!in_array($basis,['checkin','service_start'],true))return new WP_Error('age_policy_reference_invalid');
  $raw=$payload['bands']??[];
  if(!is_array($raw)||count($raw)>30)return new WP_Error('age_policy_bands_invalid');
  $bands=[];$seen=[];$ids=[];
  foreach($raw as $row){
   if(!is_array($row))return new WP_Error('age_policy_band_invalid');
   $id=sanitize_key((string)($row['id']??''));if($id==='')continue;
   if(!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$id)||isset($ids[$id]))return new WP_Error('age_policy_band_id_invalid');
   $ids[$id]=1;
   foreach(['min_age','max_age'] as $field)if(!isset($row[$field])||!preg_match('/^\d{1,3}$/D',(string)$row[$field]))return new WP_Error('age_policy_range_invalid');
   $min=(int)$row['min_age'];$max=(int)$row['max_age'];
   if($min<0||$max>120||$min>$max)return new WP_Error('age_policy_range_invalid');
   for($n=$min;$n<=$max;$n++){if(isset($seen[$n]))return new WP_Error('age_band_overlap');$seen[$n]=1;}
   $factor=$row['price_factor']??'1';
   if(!is_numeric($factor)||!is_finite((float)$factor)||(float)$factor<0||(float)$factor>2)return new WP_Error('age_policy_price_factor_invalid');
   $bands[]=['id'=>$id,'min_age'=>$min,'max_age'=>$max,'price_factor'=>(float)$factor,'count_in_capacity'=>!empty($row['count_in_capacity'])];
  }
  return ['age_reference'=>$basis,'bands'=>$bands,'age_pricing_enabled'=>!empty($payload['age_pricing_enabled']),'count_in_capacity'=>!empty($payload['count_in_capacity'])];
 }
 public static function save(){
  if(!current_user_can('manage_woocommerce'))wp_die('Permission denied',403);
  check_admin_referer('mad4b_age_occupancy_save');
  $scope=sanitize_key((string)($_POST['scope']??''));$domain=sanitize_key((string)($_POST['domain']??''));$id=absint($_POST['target_id']??0);
  if(!in_array($scope,['global','product_type','supplier','product','unit','rate'],true)||($scope==='product_type'&&!$domain)||(!in_array($scope,['global','product_type'],true)&&(!$id||!get_post($id))))wp_die('Invalid target',400);
  $payload=isset($_POST['policy'])?wp_unslash($_POST['policy']):[];
  $policy=self::validate($payload);
  if(is_wp_error($policy))wp_die(esc_html($policy->get_error_code()),400);
  if($scope==='global')update_option(MAD4B_Age_Aware_Stay::OPTION,$policy,false);
  elseif($scope==='product_type'){
   $all=get_option('mad4b_age_occupancy_policy_product_types_v1',[]);if(!is_array($all))$all=[];
   $all[$domain]=$policy;update_option('mad4b_age_occupancy_policy_product_types_v1',$all,false);
  }else update_post_meta($id,MAD4B_Age_Aware_Stay::META,$policy);
  wp_safe_redirect(add_query_arg(['page'=>self::PAGE,'scope'=>$scope,'domain'=>$domain,'target_id'=>$id,'saved'=>1],admin_url('admin.php')));exit;
 }
 public static function save_forms(){
  if(!current_user_can('manage_woocommerce'))wp_die('Permission denied',403);
  check_admin_referer('mad4b_age_forms_save');
  $rows=isset($_POST['forms'])?wp_unslash($_POST['forms']):[];
  if(!is_array($rows)||count($rows)>50)wp_die('Invalid form mappings',400);
  global $wpdb;$table=$wpdb->prefix.'fluentform_forms';$final=[];
  foreach($rows as $row){
   if(!is_array($row))wp_die('Invalid mapping row',400);
   $id=absint($row['id']??0);if(!$id)continue;
   $children=sanitize_key((string)($row['children']??''));$infants=sanitize_key((string)($row['infants']??''));
   if(!$children||!$infants||$children===$infants||isset($final[$id]))wp_die('Invalid form field names',400);
   $form=$wpdb->get_row($wpdb->prepare("SELECT status,form_fields FROM $table WHERE id=%d",$id));
   if(!$form||$form->status!=='published')wp_die('Form is not published',400);
   $definition=json_decode($form->form_fields,true);$names=[];
   foreach(($definition['fields']??[]) as $field){
    if(($field['element']??'')==='input_number'&&isset($field['attributes']['name']))$names[]=$field['attributes']['name'];
   }
   // For local deployments, passenger counts are existing numeric Fluent Forms inputs.
   if(!in_array($children,$names,true)||!in_array($infants,$names,true))wp_die('Passenger count fields not found',400);
   $final[$id]=['children'=>$children,'infants'=>$infants,'strict'=>!empty($row['strict'])];
  }
  update_option(MAD4B_Age_Fluent_Forms::OPTION,$final,false);
  wp_safe_redirect(add_query_arg(['page'=>self::PAGE,'forms_saved'=>1],admin_url('admin.php')));exit;
 }
 public static function render(){
  if(!current_user_can('manage_woocommerce'))return;
  $target=self::current_target();$scope=$target['scope'];$domain=$target['domain'];$id=$target['id'];
  $policy=self::read($scope,$domain,$id);if(!is_array($policy))$policy=[];
  $bands=$policy['bands']??[];
  echo '<div class="wrap"><h1>Age &amp; Occupancy Policy</h1><p>Configure age bands, room capacity and per-person price factors. Empty bands preserve legacy occupancy and prices. Before enabling a policy for bookings with children, make sure the booking form collects their ages on the service date.</p>';
  if(!empty($_GET['saved']))echo '<div class="notice notice-success"><p>Policy saved.</p></div>';
  echo '<form method="get" action=""><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"/><label>Scope <select name="scope">';
  foreach(['global','product_type','supplier','product','unit','rate'] as $v)echo '<option value="'.esc_attr($v).'"'.selected($scope,$v,false).'>'.esc_html(ucwords(str_replace('_',' ',$v))).'</option>';
  echo '</select></label> <label>Product type <input name="domain" value="'.esc_attr($domain).'"/></label> <label>Post ID <input name="target_id" type="number" min="0" value="'.esc_attr($id).'"/></label> <button class="button">Load policy</button></form><hr/>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_age_policy_save"/>';
  wp_nonce_field('mad4b_age_occupancy_save');foreach(['scope'=>$scope,'domain'=>$domain,'target_id'=>$id] as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'"/>';
  echo '<p><label>Age measured on <select name="policy[age_reference]">';
  foreach(['service_start'=>'Service start','checkin'=>'Check-in'] as $v=>$label)echo '<option value="'.esc_attr($v).'"'.selected($policy['age_reference']??'service_start',$v,false).'>'.esc_html($label).'</option>';
  echo '</select></label> &nbsp; <label><input type="checkbox" name="policy[age_pricing_enabled]" value="1"'.checked(!empty($policy['age_pricing_enabled']),true,false).'/> Use age factors for per-person pricing</label> &nbsp; <label><input type="checkbox" name="policy[count_in_capacity]" value="1"'.checked($policy['count_in_capacity']??true,true,false).'/> Children count in capacity by default</label></p>';
  echo '<table class="widefat striped"><thead><tr><th>Band ID</th><th>Min age</th><th>Max age</th><th>Price factor</th><th>Counts in capacity</th></tr></thead><tbody>';
  for($i=0;$i<max(8,count($bands)+2);$i++){
   $v=$bands[$i]??[];$prefix='policy[bands]['.$i.']';
   echo '<tr>';
   foreach(['id'=>'text','min_age'=>'number','max_age'=>'number','price_factor'=>'number'] as $field=>$type){$default=$field==='price_factor'?'1':'';$value=$v[$field]??$default;echo '<td><input type="'.esc_attr($type).'" name="'.esc_attr($prefix.'['.$field.']').'" value="'.esc_attr($value).'" '.($type==='number'?'step="'.($field==='price_factor'?'0.01':'1').'"':'').'/></td>';}
   echo '<td><input type="checkbox" name="'.esc_attr($prefix.'[count_in_capacity]').'" value="1"'.checked($v['count_in_capacity']??true,true,false).'/></td></tr>';
  }
  echo '</tbody></table><p><button type="submit" class="button button-primary">Save policy</button></p></form>';
  $forms=class_exists('MAD4B_Age_Fluent_Forms')?MAD4B_Age_Fluent_Forms::forms():[];
  echo '<hr/><h2>Fluent Forms Passenger Age Mapping</h2><p>Specify existing published forms and their numeric child/infant count fields. IDs are site-specific, not hardcoded in the plugin.</p>';
  if(!empty($_GET['forms_saved']))echo '<div class="notice notice-success"><p>Form mappings saved.</p></div>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_age_forms_save"/>';
  wp_nonce_field('mad4b_age_forms_save');
  echo '<table class="widefat striped"><thead><tr><th>Form ID</th><th>Children count field</th><th>Infants count field</th><th>Require ages on booking submission</th></tr></thead><tbody>';
  $rows=array_values($forms);$ids=array_keys($forms);$rows[]=['children'=>'','infants'=>'','strict'=>true];$ids[]=0;
  foreach($rows as $i=>$row){echo '<tr><td><input type="number" min="0" name="forms['.$i.'][id]" value="'.esc_attr($ids[$i]).'"/></td>';
   foreach(['children','infants'] as $field)echo '<td><input name="forms['.$i.']['.$field.']" value="'.esc_attr($row[$field]??'').'"/></td>';
   echo '<td><input type="checkbox" name="forms['.$i.'][strict]" value="1"'.checked(!empty($row['strict']),true,false).'/></td></tr>';
  }
  echo '</tbody></table><p><button type="submit" class="button">Save form mappings</button></p></form></div>';
 }
}
MAD4B_Age_Occupancy_Admin::init();
