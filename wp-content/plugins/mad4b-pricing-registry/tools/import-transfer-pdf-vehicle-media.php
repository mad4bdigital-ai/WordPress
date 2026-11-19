<?php
/**
 * Idempotent licensed imagery intake for vendor PDF vehicle classes.
 * Site-bound and intentionally detached from supplier fleet and public posts.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(2);
$site=rtrim(home_url('/'),'/');
$isProd=$site==='https://wayfatours.com';
if ($site!=='https://tours.dev.mad4b.com' &&
    !($isProd && defined('MAD4B_TRANSFER_MEDIA_WAYFA_APPROVED') &&
      MAD4B_TRANSFER_MEDIA_WAYFA_APPROVED===true)) WP_CLI::error('site_not_approved');
$manifest=require WP_PLUGIN_DIR.'/mad4b-pricing-registry/data/transport-pdf-2026-media-manifest.php';
if (!is_array($manifest) || count($manifest['items']??[])!==5 ||
    ($manifest['publish_to_service_authorized']??true)!==false) WP_CLI::error('media_manifest_invalid');
$apply=defined('MAD4B_TRANSFER_MEDIA_APPLY') && MAD4B_TRANSFER_MEDIA_APPLY===true;
$option='mad4b_transfer_pdf_2026_vehicle_media_map_v1';
$ids=array();$pending=array();
foreach ($manifest['items'] as $key=>$item) {
    if (!in_array($item['license'],array('CC BY-SA 4.0','CC BY 2.0'),true)
        || count($item['alt_localized']??[])!==6
        || !preg_match('/^[a-f0-9]{64}$/D',(string)($item['sha256']??'')))
        WP_CLI::error('unverified_media_'.$key);
    $found=get_posts(array('post_type'=>'attachment','post_status'=>'inherit',
        'posts_per_page'=>2,'fields'=>'ids','suppress_filters'=>true,
        'meta_key'=>'_mad4b_transfer_pdf_2026_media_key','meta_value'=>$key));
    if (count($found)>1) WP_CLI::error('duplicate_media_'.$key);
    if ($found) {
        $id=(int)$found[0];
        if (get_post_meta($id,'_mad4b_transfer_pdf_2026_media_sha256',true)!==$item['sha256'])
            WP_CLI::error('existing_media_checksum_changed_'.$key);
        $ids[$key]=$id;
        continue;
    }
    $path='/tmp/mad4b-pdf-transfer-licensed-media/'.$item['file_name'];
    if (!is_file($path) || hash_file('sha256',$path)!==$item['sha256'] ||
        mime_content_type($path)!=='image/jpeg') WP_CLI::error('photo_bytes_unverified_'.$key);
    $pending[$key]=$path;
}
if (!$apply) {
    WP_CLI::log(wp_json_encode(array('status'=>'dry_run','site'=>$site,
        'pending'=>array_keys($pending),'existing'=>$ids,'license_verified'=>true,
        'post_assignment'=>false,'fleet_verification'=>false)));
    return;
}
$admin=get_user_by('id',1);
if (!$admin || !user_can($admin,'upload_files')) WP_CLI::error('admin_upload_required');
wp_set_current_user((int)$admin->ID);
require_once ABSPATH.'wp-admin/includes/image.php';
foreach ($pending as $key=>$path) {
    $item=$manifest['items'][$key];
    $bits=wp_upload_bits('mad4b-transfer-vehicle-'.$key.'.jpg',null,file_get_contents($path));
    if (!empty($bits['error']) || !$bits['file']) WP_CLI::error('upload_failed_'.$key);
    $credit='Illustrative category photo only; not verified supplier fleet. '.
        $item['creator'].' / Wikimedia Commons / '.$item['license'].
        ' / Source: '.$item['source'].' / License: '.$item['license_url'].
        ' / Modified: resized to 1000px for web.';
    $id=wp_insert_attachment(array('post_mime_type'=>'image/jpeg',
        'post_title'=>'Transport vehicle illustration: '.$key,
        'post_excerpt'=>$credit,'post_content'=>$credit,
        'post_status'=>'inherit','post_parent'=>0),$bits['file'],0,true);
    if (is_wp_error($id)||!$id) WP_CLI::error('attachment_insert_failed_'.$key);
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$bits['file']));
    update_post_meta($id,'_wp_attachment_image_alt',$item['alt_localized']['en']);
    foreach ($item['alt_localized'] as $lang=>$alt)
        update_post_meta($id,'_mad4b_transport_media_alt_'.$lang,$alt);
    update_post_meta($id,'_mad4b_transfer_pdf_2026_media_key',$key);
    update_post_meta($id,'_mad4b_transfer_pdf_2026_media_sha256',$item['sha256']);
    update_post_meta($id,'_mad4b_transport_media_source',$item['source']);
    update_post_meta($id,'_mad4b_transport_media_license',$item['license']);
    update_post_meta($id,'_mad4b_transport_media_license_url',$item['license_url']);
    update_post_meta($id,'_mad4b_transport_media_creator',$item['creator']);
    update_post_meta($id,'_mad4b_transport_media_changes','Resized to 1000px for web');
    update_post_meta($id,'_mad4b_transport_media_review_status','category_illustration_supplier_not_verified');
    $ids[$key]=(int)$id;
}
$expanded=array('limousine'=>$ids['limousine'],
  'luxury_7_seats'=>$ids['luxury7_h1'],'h1'=>$ids['luxury7_h1'],
  'hiace'=>$ids['hiace'],'coaster'=>$ids['coaster'],'bus'=>$ids['bus']);
$old=get_option($option,false);
if ($old!==false && $old!==$expanded) WP_CLI::error('media_map_conflict');
if ($old===false && !add_option($option,$expanded,'',false)) WP_CLI::error('media_map_write_failed');
$read=get_option($option,array());
if ($read!==$expanded) WP_CLI::error('media_map_verify_failed');
WP_CLI::log(wp_json_encode(array('status'=>'imported','site'=>$site,
    'attachments'=>$ids,'vehicle_class_map'=>$expanded,
    'langs'=>array('en','ar','es','fr','de','it'),
    'public_posts_changed'=>0,'pricing_changed'=>false,'fleet_verified'=>false)));
