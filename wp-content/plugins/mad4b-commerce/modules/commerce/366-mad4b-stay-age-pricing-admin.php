<?php
/**
 * Plugin Name: MAD4B Accommodation Age Pricing Admin
 * Description: Configure validated per room-night and per cabin-night child supplements without publishing or mutating rates.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Stay_Age_Pricing_Admin {
 const PAGE='mad4b-stay-unit-age-pricing';
 public static function menu(){
   if(!class_exists('MAD4B_Stay_Unit_Age_Pricing'))return;
   add_submenu_page('woocommerce','Accommodation Age Pricing','Stay Age Pricing',
     'manage_woocommerce',self::PAGE,[__CLASS__,'view']);
 }
 private static function select($name,$choices,$value){
   echo '<select name="'.esc_attr($name).'">';
   foreach($choices as $key=>$label)
     echo '<option value="'.esc_attr($key).'" '.selected((string)$value,(string)$key,false).'>'.esc_html($label).'</option>';
   echo '</select>';
 }
 public static function view(){
   if(!current_user_can('manage_woocommerce'))return;
   $base=MAD4B_Stay_Unit_Age_Pricing::company_rules();
   $rows=$base;
   for($i=count($rows);$i<count($base)+4;$i++)$rows[]=[];
   echo '<div class="wrap"><h1>Stay Age Pricing — Rooms & Cabins</h1>';
   echo '<p>Uses the existing <strong>Age Occupancy Registry</strong>. Default age bands are infant 0–1.99 years, child 2–11.99, adult 12+, measured at check-in. Company overrides may replace any of these at the existing registry level.</p>';
   echo '<p><strong>Global and Egyptian Hotel Standard:</strong> the room/cabin base amount is shown as a provisional reference only. Children, cots, meals and extra beds are NOT presumed free; a complete family total requires property/company verified child supplements. No checkout authority is granted.</p>';
   echo '<p><strong>Formula:</strong> Published Property Rate × nights × units + explicit per-minor-per-night supplements. Values are read-only estimates; no inventory or supplier availability is inferred.</p>';
   if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Validated accommodation rules saved.</p></div>';
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   wp_nonce_field('mad4b_stay_age_rules_save');
   echo '<input type="hidden" name="action" value="mad4b_stay_age_rules_save">';
   echo '<p><label for="mad4b-stay-age-profile"><strong>Company pricing profile</strong></label> ';
   self::select('profile',[
     'standard'=>'Standard (default; company rules override inherited unit base)',
     'custom'=>'Custom only (no inherited standard unit rules)',
     'manual_request'=>'Manual / on request (no automatic minor supplement rule)'
   ],MAD4B_Stay_Unit_Age_Pricing::profile());
   echo '</p>';
   echo '<p><strong>Default commercial market</strong> ';
   self::select('default_market',[
     'GLOBAL'=>'Global — international accommodation age template',
     'EG'=>'Egypt — Egyptian hotel age template (property country overrides)'
   ],MAD4B_Stay_Unit_Age_Pricing::company_market());
   echo '</p>';
   echo '<p>Global template: infant under 2, child 2–17, adult 18+. Egyptian hotel template (rooms only): infant under 2, child 2–5, child 6–11, adult 12+. Cruise cabins use the neutral Global template unless their own rate or company policy specifies otherwise. These are planning age bands, never guaranteed prices or mandatory tariffs. Hotel and cruise contracts take precedence.</p>';
   echo '<h2>Company overrides</h2>';
   echo '<p>Changes below take precedence over the selected Standard. Missing child supplements remain unverified. Existing room/cabin rates are shown as provisional unit-base amounts, not family totals.</p>';
   echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
   foreach(['Source level','ID (for Rate/Unit/Property)','Property type slug','Price unit','Age band ID','Supplement mode','Per night value'] as $header)
     echo '<th>'.esc_html($header).'</th>';
   echo '</tr></thead><tbody>';
   foreach($rows as $i=>$r){
     $name='rules['.$i.']';
     echo '<tr><td>';
     self::select($name.'[scope]',[
       ''=>'Unused','rate'=>'Specific Property Rate',
       'unit'=>'Specific room/cabin Unit','property'=>'Specific Property',
       'property_type'=>'Property type taxonomy','unit_default'=>'Unit default'
     ],$r['scope']??'');
     echo '</td><td><input type="number" min="0" max="2147483647" style="width:105px" name="'.esc_attr($name.'[target_id]').'" value="'.esc_attr($r['target_id']??0).'"></td>';
     echo '<td><input type="text" maxlength="80" style="width:125px" placeholder="hotel / cruise" name="'.esc_attr($name.'[property_type]').'" value="'.esc_attr($r['property_type']??'').'"></td><td>';
     self::select($name.'[price_unit]',[
       'per_room_night'=>'Per room / night',
       'per_cabin_night'=>'Per cabin / night'
     ],$r['price_unit']??'per_room_night');
     echo '</td><td><input type="text" maxlength="64" style="width:110px" placeholder="child, infant" name="'.esc_attr($name.'[band]').'" value="'.esc_attr($r['band']??'').'"></td><td>';
     self::select($name.'[mode]',[
       'on_request'=>'On request','base_included'=>'Already in unit base (no automatic extra charge)',
       'percent'=>'% of unit-night price','fixed'=>'Fixed per minor / night',
       'free'=>'Explicit company-approved zero surcharge'
     ],$r['mode']??'on_request');
     echo '</td><td><input type="number" min="0" max="1000000" step=".01" style="width:110px" name="'.esc_attr($name.'[value]').'" value="'.esc_attr($r['value']??'').'"></td></tr>';
   }
   echo '</tbody></table></div><p><button class="button button-primary">Validate and save rules</button></p></form>';
   echo '<p>Precedence: Property Rate → Room/Cabin Unit → Property → Property Type → Unit Default → built-in Standard. A company may override the Standard with a verified amount, percentage, free, or on-request rule. Unverified Standard rows do not mean child stays are free. Capacity, extra beds and supplier acceptance still require evidence. These estimates never authorize a financial order.</p>';
   echo '<h2>Inherited Standard (read only)</h2><table class="widefat striped"><thead><tr><th>Price unit</th><th>Age band</th><th>Default</th></tr></thead><tbody>';
   foreach(MAD4B_Stay_Unit_Age_Pricing::standard_rules(
       MAD4B_Stay_Unit_Age_Pricing::company_market()) as $r){
     echo '<tr><td>'.esc_html($r['price_unit']).'</td><td>'.esc_html($r['band']).
       '</td><td>Provisional room/cabin base only; child supplement unverified</td></tr>';
   }
   echo '</tbody></table>';
   echo '</div>';
 }
 public static function save(){
   if(!current_user_can('manage_woocommerce'))wp_die('Forbidden',403);
   check_admin_referer('mad4b_stay_age_rules_save');
   if(!class_exists('MAD4B_Stay_Unit_Age_Pricing'))wp_die('Pricing provider unavailable',503);
   $raw=isset($_POST['rules'])&&is_array($_POST['rules'])?wp_unslash($_POST['rules']):[];
   $profile=sanitize_key((string)($_POST['profile']??''));
   if(!in_array($profile,['standard','custom','manual_request'],true))
     wp_die('Invalid company pricing profile',400);
   $market=strtoupper(sanitize_key((string)($_POST['default_market']??'')));
   if(!in_array($market,['GLOBAL','EG'],true))
     wp_die('Invalid hotel market standard',400);
   $valid=MAD4B_Stay_Unit_Age_Pricing::validate_rules($raw);
   if(is_wp_error($valid))wp_die(esc_html($valid->get_error_code()),400);
   update_option(MAD4B_Stay_Unit_Age_Pricing::OPTION,$valid,false);
   update_option(MAD4B_Stay_Unit_Age_Pricing::PROFILE_OPTION,$profile,false);
   update_option(MAD4B_Stay_Unit_Age_Pricing::MARKET_OPTION,$market,false);
   wp_safe_redirect(add_query_arg(['page'=>self::PAGE,'saved'=>1],admin_url('admin.php')));
   exit;
 }
}
add_action('admin_menu',['MAD4B_Stay_Age_Pricing_Admin','menu'],70);
add_action('admin_post_mad4b_stay_age_rules_save',['MAD4B_Stay_Age_Pricing_Admin','save']);
