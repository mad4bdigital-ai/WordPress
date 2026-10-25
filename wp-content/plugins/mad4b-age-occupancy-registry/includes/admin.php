<?php
/** Native WooCommerce administration for registry-backed age policies. */
if (!defined('ABSPATH')) exit;
final class MAD4B_Age_Occupancy_Admin {
 const PAGE='mad4b-age-occupancy';
 public static function init(){
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('admin_post_mad4b_age_policy_save',[__CLASS__,'save']);
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
  echo '</tbody></table><p><button type="submit" class="button button-primary">Save policy</button></p></form></div>';
 }
}
MAD4B_Age_Occupancy_Admin::init();
