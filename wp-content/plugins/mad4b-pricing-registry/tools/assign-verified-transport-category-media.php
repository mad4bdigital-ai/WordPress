<?php
/** Staging-only assignment of verified, licensed class illustrations; rollbackable. */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site=rtrim(home_url('/'),'/');
$isProd=$site==='https://wayfatours.com';
if ($site!=='https://tours.dev.mad4b.com' &&
    !($isProd && defined('MAD4B_CATEGORY_MEDIA_WAYFA_APPROVED') &&
      MAD4B_CATEGORY_MEDIA_WAYFA_APPROVED===true))
    WP_CLI::error('site_authorization_missing');
$creditsKey='mad4b_transport_public_photo_credits_v1';
$backupKey='mad4b_transport_category_media_assignment_backup_20261119_v1';
$uploads=array(
 'talgo_exterior'=>array('id'=>70508,'source'=>'File:Egyptian Railways (ENR) 6.jpg','author'=>'Abdelrhman 1990',
    'en'=>'Egyptian Talgo train cars at Alexandria station, illustrative category photo',
    'ar'=>'عربات قطار تالجو التابعة للسكك الحديدية المصرية بمحطة الإسكندرية - صورة توضيحية'),
 'vip_exterior'=>array('id'=>70509,'source'=>'File:Egyptian Railways (ENR) 4.jpg','author'=>'Abdelrhman 1990',
    'en'=>'Egyptian National Railways VIP train cars at Alexandria station, illustrative category photo',
    'ar'=>'عربات قطار VIP التابعة لسكك حديد مصر بمحطة الإسكندرية - صورة توضيحية'),
 'talgo_seats'=>array('id'=>70510,'source'=>'File:Seat of Talgo train from Cairo to Alexandria.jpg','author'=>'Slyronit',
    'en'=>'A seat on an Egyptian Talgo train, illustrative interior photo',
    'ar'=>'مقعد داخل قطار تالجو مصري - صورة داخلية توضيحية'),
);
if ($isProd) {
  $uploads['talgo_exterior']['id']=70986;
  $uploads['vip_exterior']['id']=70988;
  $uploads['talgo_seats']['id']=70990;
}
foreach($uploads as $name=>$x) {
  $id=$x['id'];
  if (get_post_type($id)!=='attachment' ||
      get_post_meta($id,'_mad4b_transport_media_license',true)!=='CC BY-SA 4.0' ||
      get_post_meta($id,'_mad4b_transport_media_creator',true)!==$x['author'] ||
      !str_contains((string)get_post_meta($id,'_mad4b_transport_media_source',true),rawurlencode($x['source'])) ||
      !wp_get_attachment_url($id)) WP_CLI::error('verified_media_provenance_invalid_'.$name);
}
$groups=array(
 'talgo'=>array(45202,54470,54535,54536),
 'vip'=>array(54554,54555,54560,54561),
);
if ($isProd) {
 $groups=array('talgo'=>array(45202,54470),'vip'=>array(54554,54555));
}
$fields=array('_thumbnail_id','trans_gellery','trans_grid_image_1_middle_upper','trans_image_vertical');
$targets=array();
foreach($groups as $kind=>$ids)foreach($ids as $id){
 $post=get_post($id);
 if (!$post||$post->post_type!=='transportations'||$post->post_status!=='publish'||
     get_post_meta($id,'_mad4b_transport_media_quarantine_v1',true)!=='known_mismatched_media')
    WP_CLI::error('transport_not_quarantined_'.$id);
 $type=(int)get_post_meta($id,'trans_type',true);
 if ($type!==($kind==='talgo'?2419:2420))WP_CLI::error('transport_type_mismatch_'.$id);
 foreach($fields as $f) {
  $oldValue=get_post_meta($id,$f,true);
  if ($f==='_thumbnail_id' && $kind==='vip' && in_array((int)$oldValue,array(54596,54597),true)) continue;
  if ($oldValue!=='') WP_CLI::error('media_field_not_blank_'.$id.'_'.$f);
 }
 $feature=$kind==='talgo'?$uploads['talgo_exterior']['id']:$uploads['vip_exterior']['id'];
 $gallery=$kind==='talgo'?implode(',',array(
    wp_get_attachment_url($uploads['talgo_exterior']['id']),wp_get_attachment_url($uploads['talgo_seats']['id'])))
   :wp_get_attachment_url($uploads['vip_exterior']['id']);
 $targets[$id]=array(
  '_thumbnail_id'=>$feature, 'trans_gellery'=>$gallery,
  'trans_grid_image_1_middle_upper'=>$kind==='talgo'?wp_get_attachment_url($uploads['talgo_seats']['id']):'',
  'trans_image_vertical'=>$kind==='talgo'?wp_get_attachment_url($uploads['talgo_seats']['id']):'',
  'group'=>$kind);
}
$credits=array();
foreach($uploads as $name=>$x) $credits[]=array(
 'label'=>$name==='talgo_exterior'?'Egyptian Talgo carriages':($name==='vip_exterior'?'Egyptian VIP train carriages':'Talgo seating'),
 'author'=>$x['author'],'source'=>get_post_meta($x['id'],'_mad4b_transport_media_source',true),'license'=>'CC BY-SA 4.0');
if (defined('MAD4B_ASSIGN_CATEGORY_MEDIA_ROLLBACK') && MAD4B_ASSIGN_CATEGORY_MEDIA_ROLLBACK) {
 $backup=get_option($backupKey,false);
 if (!is_array($backup)||empty($backup['targets']))WP_CLI::error('backup_missing');
 foreach($backup['targets'] as $id=>$row) {
   foreach($fields as $field) {
     $current=get_post_meta((int)$id,$field,true);
     if ((string)$current !==(string)$targets[$id][$field])WP_CLI::error('rollback_conflict_'.$id.'_'.$field);
   }
 }
 foreach($backup['targets'] as $id=>$row)foreach($fields as $field) {
   if ($row[$field]['exists'])update_post_meta((int)$id,$field,$row[$field]['value']);
   else delete_post_meta((int)$id,$field);
 }
 if ($backup['credits_before']===false)delete_option($creditsKey);
 else update_option($creditsKey,$backup['credits_before']);
 WP_CLI::log(wp_json_encode(array('status'=>'rolled_back','transport_pages'=>count($targets))));return;
}
if (!(defined('MAD4B_ASSIGN_CATEGORY_MEDIA_APPLY') && MAD4B_ASSIGN_CATEGORY_MEDIA_APPLY)) {
 WP_CLI::log(wp_json_encode(array('status'=>'dry_run','targets'=>array_keys($targets),
    'media_assets'=>array_column($uploads,'id'),'verified_license'=>'CC BY-SA 4.0',
    'checkout_changed'=>false,'supplier_vehicle_verified'=>false)));return;
}
if (get_option($backupKey,false)!==false)WP_CLI::error('prior_backup_present');
$before=array();
foreach($targets as $id=>$values)foreach($fields as $field){
  $before[$id][$field]=array('exists'=>metadata_exists('post',$id,$field),
     'value'=>get_post_meta($id,$field,true));
}
$admin=get_user_by('id',1);
if(!$admin||!user_can($admin,'upload_files'))WP_CLI::error('admin_not_available');
wp_set_current_user((int)$admin->ID);
$creditsBefore=get_option($creditsKey,false);
if (!add_option($backupKey,array('targets'=>$before,'credits_before'=>$creditsBefore,'created_at'=>gmdate('c')),'',false))
    WP_CLI::error('backup_store_failed');
foreach($targets as $id=>$values)foreach($fields as $field){
  if($values[$field]==='' || $values[$field]===null)delete_post_meta($id,$field);
  else update_post_meta($id,$field,$values[$field]);
}
foreach($uploads as $x) {
 update_post_meta($x['id'],'_mad4b_transport_media_alt_ar',$x['ar']);
 update_post_meta($x['id'],'_mad4b_transport_media_review_status','approved_category_illustration_not_supplier_asset');
}
update_option($creditsKey,$credits,false);
foreach($targets as $id=>$values)foreach($fields as $field){
 if ((string)get_post_meta($id,$field,true)!==(string)$values[$field])
   WP_CLI::error('verification_failed_'.$id.'_'.$field);
}
WP_CLI::log(wp_json_encode(array('status'=>'assigned','transportations'=>count($targets),
    'credits'=>count($credits),'photo_source'=>'Wikimedia Commons CC BY-SA 4.0',
    'fleet_confirmation'=>false,'rates_changed'=>false)));
