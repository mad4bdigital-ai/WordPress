<?php
/** Plugin Name: MAD4B Observed Entrance Tariff Catalog
 * Observations only; native authorized draft creation, never public publication.
 */
defined('ABSPATH')||exit;
final class MAD4B_Entrance_Tariff_Catalog_V1 {
 const OBSERVED='2026-11-24';
 static function records():array {
  return [
   'giza-plateau'=>['Giza Plateau',700,350,60,30,'https://egymonuments.com/en/locations/details/Pyramids'],
   'valley-of-the-kings'=>['Valley of the Kings',750,375,60,30,'https://egymonuments.gov.eg/en/archaeological-sites/valley-of-the-kings'],
   'karnak-temple'=>['Karnak Temple',600,300,40,20,'https://egymonuments.gov.eg/archaeological-sites/karnak'],
   'luxor-temple'=>['Luxor Temple',500,250,40,20,'https://egymonuments.gov.eg/monuments/luxor-temple'],
   'hatshepsut-temple'=>['Hatshepsut Temple',440,220,40,20,'https://egymonuments.gov.eg/en/monuments/hatshepsut-temple/']
  ];
 }
 static function rows(string $key):array {
  $r=self::records()[$key]??null;if(!$r)return [];
  $out=[];foreach([['foreign','adult',$r[1]],['foreign','student',$r[2]],['EG','adult',$r[3]],['EG','student',$r[4]]] as [$market,$cat,$amount]){
   $out[]=['fee_key'=>'observed-'.strtolower($market).'-'.$cat,'amount'=>(string)$amount,'currency'=>'EGP','basis'=>'per_person',
    'visitor_category'=>$cat,'market_scope'=>$market,'valid_from'=>self::OBSERVED,'valid_to'=>self::OBSERVED,
    'review_status'=>'draft','evidence_ref'=>$r[5].' | observed '.self::OBSERVED.'; future validity not verified',
    'coverage'=>'admission_only','age_min'=>'','age_max'=>$cat==='student'?'24':''];
  }
  return $out;
 }
 static function canCreate():bool {
  $t=get_post_type_object('attractions');
  return $t&&current_user_can('commerce_manage_pricing')&&current_user_can($t->cap->create_posts)&&
   get_current_user_id()>0&&!get_user_meta(get_current_user_id(),'mad4b_company_suspension_v1',true);
 }
 static function panel():void {
  if(!current_user_can('commerce_manage_pricing'))return;
  echo '<h2>Official observed entrance tariff catalog</h2><p>Observed 24 November 2026. EGP; current published tariffs are not guaranteed for future travel. Import creates a draft Attraction with draft rate references only. No ticket coverage or booking is certified.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Attraction</th><th>Foreign adult / student</th><th>Egyptian adult / student</th><th>Evidence</th><th>Import</th></tr></thead><tbody>';
  foreach(self::records() as $key=>$r){
   echo '<tr><td>'.esc_html($r[0]).'</td><td>'.esc_html($r[1].' / '.$r[2]).'</td><td>'.esc_html($r[3].' / '.$r[4]).'</td><td><a href="'.esc_url($r[5]).'">Official source</a></td><td>';
   if(self::canCreate()){
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_import_observed_entrance"><input type="hidden" name="catalog_key" value="'.esc_attr($key).'">';
    wp_nonce_field('mad4b_import_observed_'.$key);echo '<button class="button">Create draft source</button></form>';
   }else echo 'Native Attraction create permission required';
   echo '</td></tr>';
  }
  echo '</tbody></table><p>GEM: reported foreign adult EGP 1,830 and child/student EGP 920 from 1 November 2026. <a href="https://english.ahram.org.eg/UI/Front/NewsContent/1/1238/580158/Egypt/Tourism/Egypt-raises-GEM-ticket-prices-starting--November.aspx">Reported tariff</a> — verify the exact official dated ticket on <a href="https://tickets.gem.eg/">GEM ticketing</a> before import. Not admitted as an official observed fee row.</p>';
  echo '<p>Children under six and student-ID conditions must be validated against the exact ticket. Free entry is not assumed for an unclassified child.</p>';
 }
 static function action():void {
  if(!self::canCreate())wp_die('Native Attraction creation permission required','Denied',['response'=>403]);
  $key=sanitize_key((string)($_POST['catalog_key']??''));$r=self::records()[$key]??null;
  if(!$r)wp_die('Unknown catalog record');
  check_admin_referer('mad4b_import_observed_'.$key);
  global $wpdb;$lock='mad4b_tariff_import_'.get_current_blog_id().'_'.$key;
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)wp_die('Import busy');
  try{
   $p=get_page_by_path($key,OBJECT,'attractions');
   if($p)wp_die('Attraction already exists; use its original rate editor. No overwrite.');
   $id=wp_insert_post(['post_type'=>'attractions','post_status'=>'draft','post_title'=>$r[0],'post_name'=>$key,'post_author'=>get_current_user_id()],true);
   if(is_wp_error($id)||!$id)wp_die('Native draft creation failed');
   $saved=MAD4B_Entrance_Source_Rates_V1::save((int)$id,self::rows($key),0);
   if(is_wp_error($saved))wp_die('Draft created; rate save needs authorized review: '.esc_html($saved->get_error_code()));
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
  wp_safe_redirect(MAD4B_Entrance_Source_Rates_V1::url((int)$id));exit;
 }
}
add_action('admin_post_mad4b_import_observed_entrance',[MAD4B_Entrance_Tariff_Catalog_V1::class,'action']);
