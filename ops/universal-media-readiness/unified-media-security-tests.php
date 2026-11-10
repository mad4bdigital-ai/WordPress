<?php
$valid=json_decode(file_get_contents('/tmp/mad4b-media-post-preflight.json'),true);
$cases=[];
$check=static function(string $name,array $v)use(&$cases){$r=MAD4B_Universal_Media_Apply_V1::dispatch($v);$cases[$name]=is_wp_error($r)?$r->get_error_code():$r['status'];};
$check('valid_post',$valid);
$b=$valid;$b['payload']['field']='tour_mode';$check('reject_business_field',$b);
$b=$valid;$b['payload']['site_uuid']='00000000-0000-0000-0000-000000000000';$check('reject_cross_tenant',$b);
$b=$valid;$b['payload']['sha256']=str_repeat('0',64);$check('reject_attachment_drift',$b);
$b=$valid;$b['payload']['expected_value_sha256']=str_repeat('0',64);$check('reject_value_drift',$b);
$b=$valid;$b['target']['type']='product';$check('reject_wrong_type',$b);
$b=$valid;$b['adapter']='arbitrary_meta';$check('reject_unregistered_adapter',$b);
$b=$valid;$b['action']='apply';$check('reject_unreviewed_write',$b);
$pass=$cases['valid_post']==='existing_attachment_preflight_valid'
 && $cases['reject_business_field']==='mad4b_unified_media_field_not_registered'
 && $cases['reject_cross_tenant']==='mad4b_unified_media_site_mismatch'
 && $cases['reject_attachment_drift']==='mad4b_unified_media_attachment_hash_mismatch'
 && $cases['reject_value_drift']==='mad4b_unified_media_value_drift'
 && $cases['reject_wrong_type']==='mad4b_unified_media_post_not_public'
 && $cases['reject_unregistered_adapter']==='mad4b_unified_media_adapter_not_registered'
 && $cases['reject_unreviewed_write']==='mad4b_unified_media_attachment_review_not_approved';
echo wp_json_encode(['all_pass'=>$pass,'cases'=>$cases],JSON_UNESCAPED_SLASHES);
if(!$pass)WP_CLI::error('security_contract_regression');
