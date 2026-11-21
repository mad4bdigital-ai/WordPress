<?php
/** Read-only WP-CLI contract test; not a signed media apply. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WORDPRESS_CLI_REQUIRED');
$rights=file_get_contents('/tmp/mad4b-rights-nonblocking.php');
$first=strpos($rights,'final class MAD4B_Media_Rights_Extraction_Intake_V1 {');
$last=strpos($rights,"\nadd_action('save_post_tours-and-activities'");
if($first===false||$last==false||$last<=$first)throw new RuntimeException('RIGHTS_CLASS_BOUNDARY_CHANGED');
$clone=str_replace('MAD4B_Media_Rights_Extraction_Intake_V1','MAD4B_Rights_Library_Nonblocking_Proof',substr($rights,$first,$last-$first));eval($clone);
$unified=file_get_contents('/tmp/mad4b-unified-wayfa-101.php');
$start=strpos($unified,'final class MAD4B_Universal_Media_Apply_V1 {');
$end=strpos($unified,"\nadd_filter('mad4b/term_media/signed_transport_registered'");
if($start===false||$end===false)throw new RuntimeException('UNIFIED_CLASS_BOUNDARY_CHANGED');
$clone=str_replace('MAD4B_Universal_Media_Apply_V1','MAD4B_Unified_Local_Library_Proof',substr($unified,$start,$end-$start));eval($clone);
function proof($okay,$what){if(!$okay)throw new RuntimeException('FAILED '.$what);echo 'PASS '.$what."\n";}
$rightsClass='MAD4B_Rights_Library_Nonblocking_Proof';$apply='MAD4B_Unified_Local_Library_Proof';
$lib=$rightsClass::existing_library_publication_policy(17030);
proof(($lib['can_publish']??false)===true,'existing_library_image_without_license_not_blocked');
proof(($lib['status']??'')==='metadata_pending_nonblocking','missing_data_is_explicit_review_state');
proof(($lib['rights_verified']??true)===false,'missing_rights_are_not_falsely_verified');
proof(($lib['review_queue_required']??false)===true,'rights_research_remains_a_review_task');
$published=$rightsClass::existing_library_publication_policy(70016);
proof(($published['can_publish']??false)===true && ($published['rights_verified']??false)===true,'published_credit_remains_independently_verified');
proof(($published['status']??'')==='published_credit_verified','credits_have_true_status');
proof(($rightsClass::existing_library_publication_policy(0)['can_publish']??true)===false,'unknown_attachment_is_not_bypassed');
$restriction=static function($pre,$id,$key,$single){if((int)$id===17030 && $key==='_mad4b_media_use_restriction_v1')return $single?'revoked':['revoked'];return $pre;};
add_filter('get_post_metadata',$restriction,10,4);
$blocked=$rightsClass::existing_library_publication_policy(17030);
remove_filter('get_post_metadata',$restriction,10);
proof(($blocked['can_publish']??true)===false && ($blocked['status']??'')==='explicit_media_usage_restriction','explicit_revocation_still_blocks');
$valid=['decision'=>'approve','reviewed_sha256'=>str_repeat('a',64),'visual_match_verified'=>true,
        'subject_description'=>'An appropriate verified image of an Egyptian sightseeing location'];
proof($apply::existing_library_visual_review_valid($valid,str_repeat('a',64))===true,'visual_review_does_not_require_missing_license_fields');
$legacy=$valid+['rights_verified'=>true,'rights_evidence'=>'Existing archive provenance note'];
proof($apply::existing_library_visual_review_valid($legacy,str_repeat('a',64))===true,'existing_legacy_review_contract_still_accepted');
$bad=$valid;$bad['visual_match_verified']=false;
proof($apply::existing_library_visual_review_valid($bad,str_repeat('a',64))===false,'incorrect_subject_review_still_blocks');
proof($apply::existing_library_visual_review_valid($valid,str_repeat('b',64))===false,'attachment_SHA_mismatch_still_blocks');
$cards=do_shortcode('[mad4b_media_credits]');
proof(substr_count($cards,'class="wayfa-credits-card"')>=4,'published_CCT_credits_render_automatically');
echo '__WAYFA_LIBRARY_RIGHTS_NONBLOCKING_CANDIDATE__'.wp_json_encode(['passed'=>13,'new_media_writes'=>false,'license_records_modified'=>false,'public_page_modified'=>false,'new_source_import_approval_unchanged'=>true])."\n";
