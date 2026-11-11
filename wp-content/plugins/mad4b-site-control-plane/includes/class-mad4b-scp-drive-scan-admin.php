<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Native presentation for the same bounded planner and exact Apply, no new executor. */
final class MAD4B_SCP_Drive_Scan_Admin {
 public static function step( array $input ) {
  $source=is_string($input['source_id']??null)?$input['source_id']:'';
  $cursor=is_string($input['scan_cursor']??null)?$input['scan_cursor']:'';
  if(!preg_match('/^[a-f0-9]{64}$/D',$source)||strlen($cursor)>120)return new WP_Error('mad4b_scan_admin_input_invalid','Exact source and bounded cursor required.');
  if('apply_partial'===($input['scan_operation']??'')){
   if('1'!==($input['confirm_verified_subset']??''))
    return new WP_Error('mad4b_scan_partial_explicit_confirmation_required',
     'Confirm the verified subset and deferred files before indexing.');
   return MAD4B_SCP_Brand_Context_Builder::source_scan_apply_verified_subset($input);
  }
  if('apply'===($input['scan_operation']??'')){
   if('1'!==($input['confirm_complete_scan']??''))return new WP_Error('mad4b_scan_admin_confirmation_required','Review and confirm the complete inventory first.');
   return MAD4B_SCP_Brand_Context_Builder::source_scan_apply(array(
    'source_id'=>$source,'scan_cursor'=>$cursor,
    'expected_plan_sha256'=>$input['expected_plan_sha256']??'',
    'expected_provider_inventory_digest'=>$input['expected_provider_inventory_digest']??'',
    'expected_registry_revision'=>$input['expected_registry_revision']??-1));
  }
  $result=MAD4B_SCP_Brand_Context_Builder::source_scan_plan(array('source_id'=>$source,'resumable'=>true,'scan_cursor'=>$cursor,'restart_scan'=>'1'===($input['restart_scan']??'')));
  if(!is_wp_error($result) && is_array($result)){
   $result['auto_scan']=!empty($input['auto_scan']);
   $result['auto_step_count']=min(100,max(0,(int)($input['auto_step_count']??0)));
  }
  return $result;
 }
 public static function render( array $plan ) {
  echo '<div class="wrap"><h1>'.esc_html__('Source inventory progress','mad4b-site-control-plane').'</h1>';
  $complete=!empty($plan['scan_complete']);
  $inventory_done=!empty($plan['inventory_complete']);
  $deferred=(array)($plan['deferred_files']??array());
  $partial_review=$inventory_done && !$complete && count($deferred)>0;
  $summary=$complete
   ? 'The full inventory and file normalization are complete. Review before applying. Brand approval remains separate.'
   : ($partial_review
       ? 'Folder enumeration completed, but some files cannot be fully normalized. Only verified files can be indexed as a partial source; deferred files will not become Brand Authority.'
       : 'The inventory is incomplete. Continue the next bounded step; no Context assets have been changed.');
  echo '<p>'.esc_html($summary).'</p>';
  require_once __DIR__.'/class-mad4b-scp-brand-review-routing.php';
  $review_route=MAD4B_SCP_Brand_Review_Routing_V1::summarize(
      (string)($plan['source_id']??''));
  $mode=(string)($review_route['effective_mode']??'human_only');
  $available=!empty($review_route['ai_review_eligible']);
  $mode_label=array(
      'human_only'=>'Human review',
      'human_and_ai'=>'Human + delegated AI Agent',
      'ai_only'=>'Delegated AI Agent only'
  )[$mode]??'Human review';
  echo '<div class="notice notice-info inline"><p><strong>'
    .esc_html__('Inherited review policy','mad4b-site-control-plane')
    .':</strong> '.esc_html($mode_label)
    .' <code>'.esc_html($mode).'</code>. '
    .esc_html__('Inherited from this Site Profile — not from the folder name or another company’s Agent grant.',
        'mad4b-site-control-plane')
    .'</p>';
  if($mode==='ai_only'&&!$available) {
    echo '<p><strong>'.esc_html__('AI approval pending exact site-bound delegation.',
         'mad4b-site-control-plane').'</strong> '
         .esc_html__('No automatic human fallback or unverified approval.',
          'mad4b-site-control-plane').'</p>';
  } elseif($available) {
    echo '<p>'.esc_html__('After verified inventory indexing, the existing delegated AI Agent may review and decide each exact content hash. Inventory does not itself approve assets.',
      'mad4b-site-control-plane').'</p>';
    if(empty($review_route['local_ai_worker_ready']))
      echo '<p><strong>'.esc_html__('On-site AI worker not ready:',
        'mad4b-site-control-plane').'</strong> <code>'
        .esc_html(implode(', ',(array)($review_route['local_ai_worker_blockers']??array())))
        .'</code>. '.esc_html__('An independently authenticated original Agent must supply the approval; no scan-generated approval is permitted.',
        'mad4b-site-control-plane').'</p>';
  } else echo '<p>'.esc_html__('After indexing, the existing human Review Queue remains the approval route.',
       'mad4b-site-control-plane').'</p>';
  if(!empty($review_route['blockers']))
     echo '<p><code>'.esc_html(implode(', ',array_slice($review_route['blockers'],0,7))).'</code></p>';
  echo '</div>';
  echo '<p><strong>'.esc_html__('Verified files','mad4b-site-control-plane')
   .': '.count((array)($plan['new_files']??array()))
   .' · '.esc_html__('Deferred files','mad4b-site-control-plane')
   .': '.count($deferred)
   .' · '.esc_html__('Checkpoint','mad4b-site-control-plane')
   .' #'.(int)($plan['continuation']['checkpoint_revision']??0).'</strong></p>';
  if($deferred){
    echo '<details open><summary>'.esc_html__('Files needing additional normalization','mad4b-site-control-plane').'</summary><ul>';
    foreach(array_slice($deferred,0,40) as $d)
     echo '<li>'.esc_html((string)($d['title']??'Unknown file'))
      .' — <code>'.esc_html((string)($d['normalization_reason']??'normalization_required')).'</code></li>';
    echo '</ul><p>'.esc_html__('These files are not approved, indexed or treated as complete content. A specialized extractor or bounded document review is needed.','mad4b-site-control-plane').'</p></details>';
  }
  foreach(array('new_files'=>'New files','changed_files'=>'Changed files','missing_files'=>'Missing files')as$key=>$label)
   echo '<p>'.esc_html($label).': '.esc_html((string)count($plan[$key]??array())).'</p>';
  $auto=!empty($plan['auto_scan']) && empty($plan['scan_complete']);
  $autocount=(int)($plan['auto_step_count']??0);
  if($auto && $autocount>=100)echo '<p>'.esc_html__('Automatic scan paused after 100 bounded steps; manually continue to restart another batch.','mad4b-site-control-plane').'</p>';
  echo '<form id="mad4b-drive-scan-continue" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field(MAD4B_SCP_Context_Admin_UI::ACTION_SCAN_SOURCE);
  $fields=array('action'=>MAD4B_SCP_Context_Admin_UI::ACTION_SCAN_SOURCE,'resumable'=>'1',
   'source_id'=>$plan['source_id'],'scan_cursor'=>$plan['continuation']['scan_cursor']??'');
  if($auto && $autocount<100){$fields['auto_scan']='1';$fields['auto_step_count']=(string)($autocount+1);}
  if(!empty($plan['scan_complete'])){
   $fields+=array('scan_operation'=>'apply','expected_plan_sha256'=>$plan['plan_sha256'],
    'expected_provider_inventory_digest'=>$plan['provider_inventory_digest'],'expected_registry_revision'=>$plan['registry_revision']);
   echo '<p><label><input type="checkbox" name="confirm_complete_scan" value="1" required> '.esc_html__('Apply this reviewed complete source inventory','mad4b-site-control-plane').'</label></p>';
   echo '<details><summary>'.esc_html__('Review file identities','mad4b-site-control-plane').'</summary><pre>'.esc_html(wp_json_encode(array_intersect_key($plan,array_flip(array('source_id','new_files','changed_files','missing_files','plan_sha256'))),JSON_PRETTY_PRINT)).'</pre></details>';
  }
  foreach($fields as$key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';
  submit_button(empty($plan['scan_complete'])?__('Continue inventory','mad4b-site-control-plane'):__('Apply reviewed inventory','mad4b-site-control-plane'));
  echo '</form>';
  if($auto && $autocount<100){
   echo '<p>'.esc_html__('Scanning one bounded page per request. Leave this tab open to continue; no assets are auto-approved.','mad4b-site-control-plane').'</p>';
   echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-control-plane-context&tab=sources')).'">'.esc_html__('Pause scan and return to Sources','mad4b-site-control-plane').'</a></p>';
   echo '<script>(function(){var f=document.getElementById("mad4b-drive-scan-continue");if(!f)return;setTimeout(function(){if(document.visibilityState==="visible"&&document.body.contains(f))f.submit();},1200);})();</script>';
  }
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field(MAD4B_SCP_Context_Admin_UI::ACTION_SCAN_SOURCE);
  foreach(array('action'=>MAD4B_SCP_Context_Admin_UI::ACTION_SCAN_SOURCE,'source_id'=>$plan['source_id'],'scan_cursor'=>$plan['continuation']['scan_cursor']??'','resumable'=>'1','restart_scan'=>'1')as$key=>$value)
   echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';
  submit_button(__('Restart inventory from current source','mad4b-site-control-plane'),'secondary');
  echo '</form></div>';
 }
}
