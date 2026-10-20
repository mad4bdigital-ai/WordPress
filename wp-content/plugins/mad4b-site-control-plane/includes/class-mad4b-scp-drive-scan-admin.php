<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Native presentation for the same bounded planner and exact Apply, no new executor. */
final class MAD4B_SCP_Drive_Scan_Admin {
 public static function step( array $input ) {
  $source=is_string($input['source_id']??null)?$input['source_id']:'';
  $cursor=is_string($input['scan_cursor']??null)?$input['scan_cursor']:'';
  if(!preg_match('/^[a-f0-9]{64}$/D',$source)||strlen($cursor)>120)return new WP_Error('mad4b_scan_admin_input_invalid','Exact source and bounded cursor required.');
  if('apply'===($input['scan_operation']??'')){
   if('1'!==($input['confirm_complete_scan']??''))return new WP_Error('mad4b_scan_admin_confirmation_required','Review and confirm the complete inventory first.');
   return MAD4B_SCP_Brand_Context_Builder::source_scan_apply(array(
    'source_id'=>$source,'scan_cursor'=>$cursor,
    'expected_plan_sha256'=>$input['expected_plan_sha256']??'',
    'expected_provider_inventory_digest'=>$input['expected_provider_inventory_digest']??'',
    'expected_registry_revision'=>$input['expected_registry_revision']??-1));
  }
  return MAD4B_SCP_Brand_Context_Builder::source_scan_plan(array('source_id'=>$source,'resumable'=>true,'scan_cursor'=>$cursor,'restart_scan'=>'1'===($input['restart_scan']??'')));
 }
 public static function render( array $plan ) {
  echo '<div class="wrap"><h1>'.esc_html__('Source inventory progress','mad4b-site-control-plane').'</h1>';
  echo '<p>'.esc_html(empty($plan['scan_complete'])?'The inventory is incomplete. Continue the next bounded step; no Context assets have been changed.':'The inventory is complete. Review the changes before applying. Brand asset approval remains separate.').'</p>';
  foreach(array('new_files'=>'New files','changed_files'=>'Changed files','missing_files'=>'Missing files')as$key=>$label)
   echo '<p>'.esc_html($label).': '.esc_html((string)count($plan[$key]??array())).'</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field(MAD4B_SCP_Context_Admin_UI::ACTION_SCAN_SOURCE);
  $fields=array('action'=>MAD4B_SCP_Context_Admin_UI::ACTION_SCAN_SOURCE,'resumable'=>'1',
   'source_id'=>$plan['source_id'],'scan_cursor'=>$plan['continuation']['scan_cursor']??'');
  if(!empty($plan['scan_complete'])){
   $fields+=array('scan_operation'=>'apply','expected_plan_sha256'=>$plan['plan_sha256'],
    'expected_provider_inventory_digest'=>$plan['provider_inventory_digest'],'expected_registry_revision'=>$plan['registry_revision']);
   echo '<p><label><input type="checkbox" name="confirm_complete_scan" value="1" required> '.esc_html__('Apply this reviewed complete source inventory','mad4b-site-control-plane').'</label></p>';
   echo '<details><summary>'.esc_html__('Review file identities','mad4b-site-control-plane').'</summary><pre>'.esc_html(wp_json_encode(array_intersect_key($plan,array_flip(array('source_id','new_files','changed_files','missing_files','plan_sha256'))),JSON_PRETTY_PRINT)).'</pre></details>';
  }
  foreach($fields as$key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';
  submit_button(empty($plan['scan_complete'])?__('Continue inventory','mad4b-site-control-plane'):__('Apply reviewed inventory','mad4b-site-control-plane'));
  echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field(MAD4B_SCP_Context_Admin_UI::ACTION_SCAN_SOURCE);
  foreach(array('action'=>MAD4B_SCP_Context_Admin_UI::ACTION_SCAN_SOURCE,'source_id'=>$plan['source_id'],'scan_cursor'=>$plan['continuation']['scan_cursor']??'','resumable'=>'1','restart_scan'=>'1')as$key=>$value)
   echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';
  submit_button(__('Restart inventory from current source','mad4b-site-control-plane'),'secondary');
  echo '</form></div>';
 }
}
