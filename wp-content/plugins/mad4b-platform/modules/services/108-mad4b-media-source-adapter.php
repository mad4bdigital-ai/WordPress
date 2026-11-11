<?php
/** Local-only adapter; uses native MAD4B source, asset and human-review registries. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function shared_mad4b_media_source_plan($attachment_id){
 $id=absint($attachment_id);$post=$id?get_post($id):null;$file=$post?get_attached_file($id):'';
 if(!$post||$post->post_type!=='attachment'||!$file||!is_file($file))return new WP_Error('invalid_media','Media Library attachment missing');
 $mime=get_post_mime_type($id);if(!wp_attachment_is_image($id)&&!in_array($mime,['text/plain','text/markdown','application/pdf'],true))return new WP_Error('media_type','Unsupported Media Library type');
 $profile=class_exists('MAD4B_SCP_Context_Authority')?MAD4B_SCP_Context_Authority::profile():[];
 if(!$profile)return new WP_Error('brand_profile_required','Configure the native MAD4B Brand Context Profile first');
 $source=['provider'=>'wordpress_media','mode'=>'governed','external_root_id'=>'site_media_library','label'=>'WordPress Media Library (Local)','write_policy'=>'read_only'];
 $is_text=in_array($mime,['text/plain','text/markdown'],true);
 $size=filesize($file);$complete=$is_text&&$size!==false&&$size<=262144;
 $body=$complete?file_get_contents($file):'';
 if(!is_string($body)||!mb_check_encoding($body,'UTF-8')){$body='';$complete=false;}
 $asset=['file_id'=>(string)$id,'parent_folder_id'=>'site_media_library','title'=>get_the_title($id),'mimeType'=>$mime,'modifiedTime'=>get_post_modified_time('c',true,$id),'content_hash'=>hash_file('sha256',$file),'content_complete'=>$complete,'normalized_text'=>$complete?$body:'','content_bytes'=>(int)$size,'normalization_status'=>$complete?'ready':'incomplete','normalization_reason'=>$complete?'':'local_content_review_required'];
 return ['source'=>$source,'asset'=>$asset];
}
function shared_mad4b_media_register($attachment_id){
 if(!current_user_can('manage_options'))return new WP_Error('forbidden','Admin required');
 $plan=shared_mad4b_media_source_plan($attachment_id);if(is_wp_error($plan))return $plan;
 $source=MAD4B_SCP_Context_Authority::upsert_source($plan['source']);if(is_wp_error($source))return $source;
 $site=MAD4B_SCP_Site_Profile::profile();
 $source_id=hash('sha256',(string)($site['site_uuid']??'').'|wordpress_media|governed|site_media_library|');
 if(!MAD4B_SCP_Context_Authority::source($source_id))return new WP_Error('source_not_visible','MAD4B source registry readback failed');
 if(!$source_id)return new WP_Error('source_readback_required','MAD4B source registration requires readback before asset registration');
 return MAD4B_SCP_Context_Authority::upsert_asset_from_provider($source_id,$plan['asset']);
}
add_action('admin_post_shared_mad4b_media_register',static function(){
 if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('shared_mad4b_media_register');
 $id=absint($_POST['attachment_id']??0);$result=shared_mad4b_media_register($id);
 wp_safe_redirect(add_query_arg(['page'=>'shared-mad4b-media','result'=>is_wp_error($result)?sanitize_key($result->get_error_code()):'registered'],admin_url('admin.php')));exit;
});
add_action('admin_menu',static function(){add_media_page('MAD4B Brand Sources','MAD4B Brand Sources','manage_options','shared-mad4b-media',static function(){
 $profile=MAD4B_SCP_Context_Authority::profile();echo '<div class="wrap"><h1>MAD4B Media Context Sources</h1><p>Registers WordPress attachments using native MAD4B source → asset → human-review registries. Does not approve files automatically.</p>';
 echo '<p>Brand Profile: '.($profile?'configured':'not configured — registration blocked').'</p><p>Registration result: '.esc_html(sanitize_key($_GET['result']??'not_run')).'</p>';
 echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="shared_mad4b_media_register">';wp_nonce_field('shared_mad4b_media_register');echo '<label>Media attachment ID <input type="number" name="attachment_id" min="1" required></label>';submit_button('Register as pending Brand Context asset');echo '</form><h2>Create local text context file</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="shared_mad4b_media_create">';wp_nonce_field('shared_mad4b_media_create');echo '<p><input type="text" name="title" required placeholder="Document title"></p><p><textarea name="body" rows="10" cols="75" required></textarea></p>';submit_button('Create file and register pending review');echo '</form></div>';
 });});

/** AI review loop evidence is advisory until MAD4B's native agent authorization accepts it. */
function shared_mad4b_media_review_round($attachment_id,$findings,$proposed_decision='needs_changes'){
 if(!current_user_can('manage_options'))return new WP_Error('forbidden','Admin required');
 $plan=shared_mad4b_media_source_plan($attachment_id);if(is_wp_error($plan))return $plan;
 $r=shared_mad4b_media_register($attachment_id);if(is_wp_error($r))return $r;
 $hash=$plan['asset']['content_hash'];$id=absint($attachment_id);
 $history=get_post_meta($id,'_mad4b_media_ai_review_rounds',true);if(!is_array($history))$history=[];
 $decision=in_array($proposed_decision,['needs_changes','candidate_approved','rejected'],true)?$proposed_decision:'needs_changes';
 $round=['content_hash'=>$hash,'decision'=>$decision,'findings'=>mb_substr(sanitize_textarea_field($findings),0,3000),'actor'=>get_current_user_id(),'timestamp'=>gmdate('c'),'authority'=>'advisory_only'];
 $history[]=$round;$history=array_slice($history,-20);update_post_meta($id,'_mad4b_media_ai_review_rounds',$history);
 return ['rounds'=>count($history),'decision'=>$decision,'authority'=>'advisory_only','native_approval'=>false];
}
function shared_mad4b_media_review_state($attachment_id){
 $plan=shared_mad4b_media_source_plan($attachment_id);if(is_wp_error($plan))return $plan;
 $history=get_post_meta(absint($attachment_id),'_mad4b_media_ai_review_rounds',true);if(!is_array($history)||!$history)return ['state'=>'not_reviewed','rounds'=>0];
 $last=end($history);return ['state'=>hash_equals((string)$last['content_hash'],(string)$plan['asset']['content_hash'])?'current_advisory':'stale_due_to_content_change','rounds'=>count($history),'native_approval'=>false];
}
add_action('admin_post_shared_mad4b_media_create',static function(){
 if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('shared_mad4b_media_create');
 $title=sanitize_text_field(wp_unslash($_POST['title']??''));$body=wp_unslash($_POST['body']??'');
 if(!$title||!is_string($body)||strlen($body)>262144||!mb_check_encoding($body,'UTF-8'))wp_die('Invalid content');
 $upload=wp_upload_bits(sanitize_file_name('mad4b-context-'.gmdate('YmdHis').'-'.wp_generate_password(6,false).'.txt'),null,$body);
 if(!empty($upload['error']))wp_die(esc_html($upload['error']));
 $id=wp_insert_attachment(['post_title'=>$title,'post_mime_type'=>'text/plain','post_status'=>'inherit'],$upload['file']);
 if(is_wp_error($id)){wp_delete_file($upload['file']);wp_die(esc_html($id->get_error_message()));}
 $result=shared_mad4b_media_register($id);
 wp_safe_redirect(add_query_arg(['page'=>'shared-mad4b-media','created'=>(int)$id,'result'=>is_wp_error($result)?$result->get_error_code():'pending_review'],admin_url('upload.php')));exit;
});
add_action('admin_footer-upload_page_shared-mad4b-media',static function(){
 echo '<script>/* MAD4B local admin form is server rendered. */</script>';
});
add_action('admin_notices',static function(){
 if(($_GET['page']??'')!=='shared-mad4b-media'||!current_user_can('manage_options'))return;
 echo '<div class="notice notice-info"><p>Native MAD4B AI approval is restricted to Staging; local AI review evidence is advisory and never publishes or approves a context asset.</p></div>';
});

/** Reviewed via WP QA browser, but still calls the native MAD4B review authority. */
add_action('admin_post_shared_mad4b_native_review',static function(){
 if(!current_user_can('manage_options'))wp_die('Access denied');
 check_admin_referer('shared_mad4b_native_review');
 $asset_id=sanitize_text_field(wp_unslash($_POST['asset_id']??''));
 $asset=MAD4B_SCP_Context_Authority::asset($asset_id);
 if(!$asset||($asset['provider']??'')!=='wordpress_media')wp_die('Local media asset not found');
 $attachment_id=absint($asset['file_id']??0);
 $plan=shared_mad4b_media_source_plan($attachment_id);
 if(is_wp_error($plan)||!hash_equals((string)($asset['content_hash']??''),(string)$plan['asset']['content_hash']))wp_die('Content changed. Resync and review again.');
 $decision=sanitize_key(wp_unslash($_POST['decision']??''));
 if(!in_array($decision,['approve','reject','needs_changes'],true))wp_die('Invalid decision');
 $input=['category'=>$asset['category'],'authority_class'=>$asset['authority_class'],'required'=>!empty($asset['required']),
  'required_scope_confirmed'=>false,'classification_confirmed'=>!empty($_POST['classification_confirmed']),
  'quality_mode'=>'preserve','decision'=>$decision,'review_note'=>sanitize_textarea_field(wp_unslash($_POST['review_note']??'')),
  'expected_content_hash'=>$asset['content_hash'],'expected_registry_revision'=>MAD4B_SCP_Context_Authority::registry_revision(),
  'expected_authority_manifest_fingerprint'=>MAD4B_SCP_Context_Authority::authority_manifest_fingerprint()];
 $result=MAD4B_SCP_Context_Authority::review_asset($asset_id,$input);
 wp_safe_redirect(add_query_arg(['page'=>'shared-mad4b-media','review_result'=>is_wp_error($result)?$result->get_error_code():'success'],admin_url('upload.php')));exit;
});
add_action('admin_post_shared_mad4b_advisory_round',static function(){
 if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('shared_mad4b_advisory_round');
 $id=absint($_POST['attachment_id']??0);
 $result=shared_mad4b_media_review_round($id,wp_unslash($_POST['findings']??''),sanitize_key($_POST['decision']??'needs_changes'));
 wp_safe_redirect(add_query_arg(['page'=>'shared-mad4b-media','review_result'=>is_wp_error($result)?$result->get_error_code():'advisory_saved'],admin_url('upload.php')));exit;
});
add_action('admin_footer',static function(){
 if(!is_admin()||($_GET['page']??'')!=='shared-mad4b-media'||!current_user_can('manage_options'))return;
 echo '<section class="wrap"><h2>Native MAD4B Context Review</h2><p>Site-bound asset approval via native MAD4B registry. QA browser login is authentication only, not an AI agent grant.</p><p>Last result: '.esc_html(sanitize_key($_GET['review_result']??'not_run')).'</p>';
 foreach(MAD4B_SCP_Context_Authority::assets() as $a){if(($a['provider']??'')!=='wordpress_media')continue;
 $id=(int)($a['file_id']??0);$state=shared_mad4b_media_review_state($id);
 echo '<div style="padding:12px;border:1px solid #c3c4c7;margin:8px 0"><strong>'.esc_html($a['title']??'Asset').'</strong> (#'.$id.') — '.esc_html($a['review_status']??'').' — '.esc_html(is_array($state)?($state['state']??''):'unavailable');
 echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="shared_mad4b_native_review"><input type="hidden" name="asset_id" value="'.esc_attr($a['asset_id']).'">';wp_nonce_field('shared_mad4b_native_review');echo '<label>Decision <select name="decision"><option value="needs_changes">Needs changes</option><option value="reject">Reject</option><option value="approve">Approve</option></select></label> <label><input type="checkbox" name="classification_confirmed" value="1"> Confirm classification</label><p><textarea name="review_note" rows="2" cols="60" required placeholder="Review evidence"></textarea></p>';submit_button('Submit native review','secondary','submit',false);echo '</form>';
 echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="shared_mad4b_advisory_round"><input type="hidden" name="attachment_id" value="'.$id.'">';wp_nonce_field('shared_mad4b_advisory_round');echo '<p><label>Advisory AI review evidence <textarea name="findings" rows="2" cols="60" required></textarea></label> <select name="decision"><option value="needs_changes">Needs changes</option><option value="candidate_approved">Candidate (not approved)</option><option value="rejected">Reject</option></select> ';submit_button('Record advisory round','secondary','submit',false);echo '</p></form></div>';
 }
 echo '</section>';
});
