<?php
/** Read-only WP-CLI contract for site-neutral missing-metadata advisory policy. */
if(!defined('WP_CLI')||!WP_CLI||!current_user_can('manage_options')){
 echo "SKIP ADMIN WP-CLI REQUIRED\n";return;
}
global $wpdb;
$id=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' ORDER BY ID DESC LIMIT 1");
if($id<1||get_post_type($id)!=='attachment'){echo "SKIP NO MEDIA LIBRARY IMAGE\n";return;}
$results=[];
$assert=static function($key,$ok)use(&$results){$results[$key]=(bool)$ok;};
$filter=static fn()=> 'enforced';
add_filter('mad4b_remote_media_rights_mode',$filter,999);
try {
 $s=['images'=>['kind'=>'image_gallery','storage'=>'ids'],
     'image_usage'=>['kind'=>'image_usage','references_field'=>'images',
      'publish_rights_policy'=>'require_valid','expiry_required_licenses'=>['licensed']]];
 $base=['images'=>[$id]];
 $ctx='MAD4B_SCP_Content_Experience_Media_Rights';
 $r=$ctx::publish_guard($s,$base,'2026-11-16');
 $assert('existing_library_image_missing_usage_advisory',!is_wp_error($r)&&$r['license_warning_count']>0&&$r['legal_reuse_permission']==='not_determined_by_system');
 $run=static fn($items)=>$ctx::publish_guard($s,array_merge($base,['image_usage'=>$items]),'2026-11-16');
 $r=$run([['attachment_id'=>$id,'license'=>'']]);
 $assert('missing_license_advisory',!is_wp_error($r)&&$r['license_warning_count']>0);
 $r=$run([['attachment_id'=>$id,'license'=>'licensed']]);
 $assert('missing_required_expiry_advisory',!is_wp_error($r)&&$r['license_warning_count']>0);
 $r=$run([['attachment_id'=>$id,'license'=>'','license_expires_on'=>'2020-01-01']]);
 $assert('expired_evidence_hard_block',is_wp_error($r)&&$r->get_error_code()==='mad4b_content_experience_media_rights_expired');
 $r=$run([['attachment_id'=>$id,'license'=>'','license_expires_on'=>'2026-02-30']]);
 $assert('invalid_evidence_hard_block',is_wp_error($r)&&$r->get_error_code()==='mad4b_content_experience_media_rights_expired');
 $r=$run([['attachment_id'=>$id,'license'=>'restricted']]);
 $assert('explicit_restriction_hard_block',is_wp_error($r)&&$r->get_error_code()==='mad4b_content_experience_media_rights_explicit_restriction');
 $r=$run([['attachment_id'=>999999999,'license'=>'']]);
 $assert('unresolved_media_hard_block',is_wp_error($r)&&$r->get_error_code()==='mad4b_content_experience_media_rights_license_required');
 $r=$run([['attachment_id'=>$id,'license'=>'owned']]);
 $assert('known_license_pass',!is_wp_error($r)&&($r['license_warning_count']??-1)===0);
 $d=$ctx::publish_guard(['images'=>['kind'=>'image_gallery','storage'=>'ids'], 'image_usage'=>['kind'=>'image_gallery_usage','references_field'=>'images','publish_rights_policy'=>'require_valid','expiry_required_licenses'=>['cc-by-4.0']]], ['images'=>[$id], 'image_usage'=>[['attachment_id'=>$id,'license'=>'cc-by-4.0']]], '2026-11-16');
 $assert('dotted_license_token_retains_expiry_policy',!is_wp_error($d)&&$d['license_warning_count']>0);
 $adapter=new ReflectionClass('MAD4B_SCP_Remote_Media_Adapter');
 $inst=$adapter->newInstanceWithoutConstructor();$normalize=$adapter->getMethod('normalize_remote_import_input');$normalize->setAccessible(true);
 $url='https://upload.wikimedia.org/wikipedia/commons/example.jpg';
 $baseImport=['source_url'=>$url,'rights_reference'=>'https://creativecommons.org/licenses/by/4.0/'];
 $bad=$normalize->invoke($inst,$baseImport+['rights_basis'=>'public_domain']);
 $assert('cc_by4_cannot_be_public_domain',is_wp_error($bad)&&$bad->get_error_code()==='mad4b_remote_media_cc_license_not_public_domain');
 $good=$normalize->invoke($inst,$baseImport+['rights_basis'=>'creative_commons']);
 $assert('cc_by4_retains_explicit_cc_basis',is_array($good)&&$good['rights_basis']==='creative_commons'&&untrailingslashit($good['rights_reference'])===untrailingslashit($baseImport['rights_reference']));
 $mark=$normalize->invoke($inst,['source_url'=>$url,'rights_basis'=>'public_domain','rights_reference'=>'https://creativecommons.org/publicdomain/mark/1.0/']);
 $assert('actual_public_domain_mark_is_not_by_license',is_array($mark)&&$mark['rights_basis']==='public_domain');
} finally {remove_filter('mad4b_remote_media_rights_mode',$filter,999);}
// Advisory is a missing-metadata policy, never a waiver of explicit denials.
$advisory=static fn()=> 'advisory';
add_filter('mad4b_remote_media_rights_mode',$advisory,999);
try {
 $ctx='MAD4B_SCP_Content_Experience_Media_Rights';
 $refs=['images'=>[$id]];
 $required=['images'=>['kind'=>'image_gallery','storage'=>'ids'],
            'image_usage'=>['kind'=>'image_usage','references_field'=>'images','publish_rights_policy'=>'require_valid']];
 $fn=static fn($items)=>$ctx::publish_guard($required,array_merge($refs,['image_usage'=>$items]),'2026-11-16');
 $x=$fn([['attachment_id'=>$id,'license'=>'']]);
 $assert('advisory_missing_license_warns',!is_wp_error($x)&&$x['license_warning_count']>0);
 foreach(['restricted','denied','no_reuse','all_rights_reserved'] as $state){
  $x=$fn([['attachment_id'=>$id,'license'=>$state]]);
  $assert('advisory_explicit_restriction_'.$state,is_wp_error($x)&&$x->get_error_code()==='mad4b_content_experience_media_rights_explicit_restriction');
 }
 $x=$fn([['attachment_id'=>$id,'license'=>'','license_expires_on'=>'2026-02-30']]);
 $assert('advisory_invalid_calendar_date_denied',is_wp_error($x)&&$x->get_error_code()==='mad4b_content_experience_media_rights_expired');
 $x=$fn([['attachment_id'=>$id,'license'=>'','license_expires_on'=>'2020-01-01']]);
 $assert('advisory_expired_rights_denied',is_wp_error($x)&&$x->get_error_code()==='mad4b_content_experience_media_rights_expired');
 $r='MAD4B_SCP_Remote_Media_Rights';
 $assert('legacy_cc_by4_public_domain_collision_detected',$r::conflicts_with_explicit_cc_license('public_domain','https://creativecommons.org/licenses/by/4.0/',''));
 $assert('legacy_cc_by_sa_collision_detected',$r::conflicts_with_explicit_cc_license('public_domain','', 'CC BY-SA 3.0 original photographer'));
 $assert('specific_cc_basis_not_flagged_as_public_domain',!$r::conflicts_with_explicit_cc_license('creative_commons','https://creativecommons.org/licenses/by/4.0/','CC BY 4.0'));
 $assert('pd_mark_does_not_equal_cc_by',!$r::conflicts_with_explicit_cc_license('public_domain','https://creativecommons.org/publicdomain/mark/1.0/',''));
} finally {remove_filter('mad4b_remote_media_rights_mode',$advisory,999);}

// Check the real normalizer, not only the downstream publish guard.
$norm='MAD4B_SCP_Content_Experience_Media';
$usageSpec=['kind'=>'image_gallery_usage','storage'=>'ids','max_items'=>4,
  'usage_fields'=>['license'],'licenses'=>['owned','licensed'],'references_field'=>'images'];
$blank=$norm::normalize_meta_value('image_usage',[['attachment_id'=>$id,'license'=>'']],$usageSpec);
$assert('local_blank_license_survives_normalization',is_array($blank)&&count($blank)===1&&array_key_exists('license',$blank[0])&&$blank[0]['license']==='');
$allowDotted=$usageSpec;$allowDotted['licenses']=['cc-by-4.0'];
$exact=$norm::normalize_meta_value('image_usage',[['attachment_id'=>$id,'license'=>'cc-by-4.0']],$allowDotted);
$assert('dotted_cc_by4_license_survives_normalization',is_array($exact)&&count($exact)===1&&($exact[0]['license']??null)==='cc-by-4.0');
$bad=$norm::normalize_meta_value('image_usage',[['attachment_id'=>$id,'license'=>'nonexistent_license']],$usageSpec);
$assert('unknown_nonempty_license_rejected_upstream',is_wp_error($bad)&&$bad->get_error_code()==='mad4b_content_experience_media_usage_license_denied');
$fields=['images'=>['kind'=>'image_gallery','storage'=>'ids','max_items'=>4], 'image_usage'=>$usageSpec];
$empty=$norm::validate_usage_bindings($fields,['images'=>[$id],'image_usage'=>[]]);
$assert('missing_whole_local_usage_list_is_advisory',$empty===true);
$swapped=$norm::validate_usage_bindings($fields,['images'=>[$id],'image_usage'=>[['attachment_id'=>999999999,'license'=>'owned']]]);
$assert('wrong_photo_license_binding_still_rejected',is_wp_error($swapped)&&$swapped->get_error_code()==='mad4b_content_experience_media_usage_reference_drift');
$unresolved=$norm::validate_usage_bindings($fields,['images'=>[999999999],'image_usage'=>[]]);
$assert('missing_usage_does_not_permit_unresolved_photo',is_wp_error($unresolved)&&$unresolved->get_error_code()==='mad4b_content_experience_media_usage_reference_drift');
foreach($results as $label=>$passed)echo ($passed?'PASS ':'FAIL ').$label."\n";
echo 'TESTS='.count($results).' PASSED='.count(array_filter($results))."\n";
if(count($results)!==count(array_filter($results)))throw new RuntimeException('MEDIA_RIGHTS_POLICY_CONTRACT_FAILED');
