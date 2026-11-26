<?php
/**
 * Staging-only, exact-source licensed media intake into WordPress attachments.
 * All attachments remain unassigned until service/actual fleet confirmation.
 * Never copies social/news stock photos or modifies published content.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(2);
$site=rtrim(home_url('/'),'/');
$isProd=$site==='https://wayfatours.com';
if ($site!=='https://tours.dev.mad4b.com' &&
    !($isProd && defined('MAD4B_MEDIA_CANDIDATES_WAYFA_APPROVED') &&
       MAD4B_MEDIA_CANDIDATES_WAYFA_APPROVED===true))
    WP_CLI::error('site_authorization_missing');
$definitions=array(
  'talgo_carriages'=>array('File:Egyptian Railways (ENR) 6.jpg','Abdelrhman 1990',
     'Egyptian Talgo carriages in Alexandria railway yard — illustrative category photo',
     '742623077f1ca10ad971535927c898607f6071cf4f8a7d97aaae9ea7ed6c59eb'),
  'vip_carriages'=>array('File:Egyptian Railways (ENR) 4.jpg','Abdelrhman 1990',
     'Egyptian National Railways VIP carriages in Alexandria — illustrative category photo',
     '38fac6fa1be62402349aaeb4d74d1e5f68ba48167029deffaeaae0cbd55e42f5'),
  'talgo_seats'=>array('File:Seat of Talgo train from Cairo to Alexandria.jpg','Slyronit',
     'Seat aboard an Egyptian Talgo train — illustrative interior photo',
     'fa502dd5e6e6425942818a71457cd6e54150abd971a2cd5f9e3274759446c9d6'),
  'vinfast_fadil'=>array('File:Newone - VinFast Fadil red front.jpg','Newone',
     'VinFast Fadil vehicle model in Vietnam — illustrative only, not the contracted Egypt fleet',
     '1099bc2fb7fa51f00d384886af46f0e27a0be8dd962cf4f273cdf8de77aeb62b'),
);
$license='https://creativecommons.org/licenses/by-sa/4.0/';
$queue=array();$existing=array();
global $wpdb;
foreach ($definitions as $name=>$item) {
    $origin='https://commons.wikimedia.org/wiki/'.str_replace('%2F','/',rawurlencode($item[0]));
    $existingId=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",
        '_mad4b_transport_media_source',$origin));
    if ($existingId && get_post_type($existingId)==='attachment') {$existing[$name]=$existingId;continue;}
    $path='/tmp/'.$name.'.jpg';
    if (!is_file($path) || hash_file('sha256',$path)!==$item[3] ||
        mime_content_type($path)!=='image/jpeg') WP_CLI::error('missing_or_invalid_source_'.$name);
    $queue[$name]=array('path'=>$path,'source'=>$origin,'author'=>$item[1],
       'alt'=>$item[2],'sha256'=>$item[3]);
}
if (!(defined('MAD4B_MEDIA_CANDIDATE_APPLY') && MAD4B_MEDIA_CANDIDATE_APPLY===true)) {
  WP_CLI::log(wp_json_encode(array('status'=>'dry_run','pending'=>array_keys($queue),
      'existing'=>$existing,'license'=>'CC BY-SA 4.0',
      'publish_assigned'=>false,'images_used_as_supplier_evidence'=>false)));return;
}
$admin=get_user_by('id',1);
if (!$admin || !user_can($admin,'upload_files')) WP_CLI::error('staging_upload_auth_unavailable');
wp_set_current_user((int)$admin->ID);
require_once ABSPATH.'wp-admin/includes/image.php';
$imported=$existing;
foreach ($queue as $name=>$row) {
    $bits=wp_upload_bits('mad4b-transport-commons-'.$name.'.jpg',null,file_get_contents($row['path']));
    if (!empty($bits['error']) || empty($bits['file'])) WP_CLI::error('upload_bits_failed_'.$name);
    $caption='Image: '.$row['author'].' / Wikimedia Commons · CC BY-SA 4.0 · resized for web. '.
       'Source: '.$row['source'].' · License: '.$license;
    $id=wp_insert_attachment(array('post_mime_type'=>'image/jpeg',
       'post_title'=>'Transportation reference: '.$name,
       'post_excerpt'=>$caption,'post_content'=>$caption,
       'post_status'=>'inherit','post_parent'=>0),$bits['file']);
    if (is_wp_error($id) || !$id) WP_CLI::error('media_insert_failed_'.$name);
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$bits['file']));
    update_post_meta($id,'_wp_attachment_image_alt',$row['alt']);
    update_post_meta($id,'_mad4b_transport_media_source',$row['source']);
    update_post_meta($id,'_mad4b_transport_media_license','CC BY-SA 4.0');
    update_post_meta($id,'_mad4b_transport_media_license_url',$license);
    update_post_meta($id,'_mad4b_transport_media_creator',$row['author']);
    update_post_meta($id,'_mad4b_transport_media_changes','Resized to 1280px for web delivery');
    update_post_meta($id,'_mad4b_transport_media_review_status','candidate_only_not_assigned');
    update_post_meta($id,'_mad4b_transport_media_sha256',$row['sha256']);
    $imported[$name]=(int)$id;
}
WP_CLI::log(wp_json_encode(array('status'=>'imported_candidates','attachments'=>$imported,
    'source_license'=>'CC BY-SA 4.0','assigned_to_services'=>false,
    'existing_published_media_changed'=>false)));
