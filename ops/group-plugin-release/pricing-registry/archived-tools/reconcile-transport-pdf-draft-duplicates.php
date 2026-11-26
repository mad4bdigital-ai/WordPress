<?php
/** Reconcile exact duplicate drafts from 2026-11-19 WPML import retry.
 * Only targeted IDs, preflight source markers, and reversible WordPress Trash.
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
if (rtrim(home_url('/'),'/')!=='https://tours.dev.mad4b.com')
    WP_CLI::error('staging_only');
$targets=array(
  70670=>array('Aswan:13','en',1219850),
  70672=>array('Aswan:13','ar',1219850),
  70675=>array('Aswan:13','de',1219850),
  70677=>array('Aswan:13','es',1219850),
  70679=>array('Aswan:13','fr',1219850),
  70681=>array('Aswan:13','it',1219850),
  70682=>array('Aswan:14',null,null),
  70684=>array('Aswan:14',null,null),
  70686=>array('Aswan:14',null,null),
  70688=>array('Aswan:14',null,null),
  70690=>array('Aswan:14',null,null),
);
$option='mad4b_transfer_pdf_2026_draft_duplicate_backup_v1';
$apply=defined('MAD4B_TRANSFER_RECONCILE_APPLY')&&MAD4B_TRANSFER_RECONCILE_APPLY===true;
$limit=defined('MAD4B_TRANSFER_RECONCILE_LIMIT')?(int)MAD4B_TRANSFER_RECONCILE_LIMIT:0;
$present=array();
foreach ($targets as $id=>$expect) {
 $post=get_post($id);
 if (!$post||$post->post_type!=='transportations'||
    !in_array($post->post_status,array('draft','trash'),true) ||
    get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true)!==$expect[0] ||
    get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)!=1 ||
    get_post_meta($id,'_mad4b_transfer_pdf_2026_sha256',true)!==
     'c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd')
     WP_CLI::error('target_not_identical_'.$id);
 $details=apply_filters('wpml_post_language_details',null,$id);
 $code=$details['language_code']??null;
 $trid=apply_filters('wpml_element_trid',null,$id,'post_transportations');
 $trid=$trid!==null && $trid!==false?(int)$trid:null;
 if($code!==$expect[1] || $trid!==$expect[2])
    WP_CLI::error('target_translation_changed_'.$id);
 $present[$id]=array('source_key'=>$expect[0],'lang'=>$code,
    'trid'=>$trid,'status'=>$post->post_status);
}
if (!$apply) {WP_CLI::log(wp_json_encode(array('status'=>'dry_run','targets'=>$present,
    'reversible'=>true,'public_changes'=>0)));return;}
$backup=get_option($option,false);
if ($backup===false) {
 if (!add_option($option,array('original'=>$present,'at'=>gmdate('c')),'',false))
    WP_CLI::error('backup_store_failed');
} elseif (($backup['original']??array())!=$present) {
 // Readback status may now be trash. Verify immutable source and IDs,
 // but never accept a different set of originally authorized targets.
 if (array_keys($backup['original']??array())!==array_keys($present))
    WP_CLI::error('backup_target_conflict');
}
$admin=get_user_by('id',1);
if(!$admin||!user_can($admin,'delete_others_posts')) WP_CLI::error('admin_required');
wp_set_current_user($admin->ID);
$quarantined=0;$already=0;
foreach($targets as $id=>$expect) {
 if (get_post_status($id)==='trash'){$already++;continue;}
 $r=wp_trash_post($id);
 if (!$r || get_post_status($id)!=='trash') WP_CLI::error('trash_failed_'.$id);
 ++$quarantined;
 if($limit>0 && $quarantined>=$limit)break;
}
WP_CLI::log(wp_json_encode(array('status'=>'quarantined_duplicate_drafts',
    'just_trashed'=>$quarantined,'already_trashed'=>$already,
    'total_target_ids'=>count($targets),'post_bytes_deleted'=>0,
    'original_vendor_records_changed'=>false)));
