<?php
/**
 * Plugin Name: MAD4B Rail Supplier Evidence Admin UI
 * Version: 0.1.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Rail_Supplier_Proof_Admin_V1 {
 static function menu(){
  if(!class_exists('MAD4B_Rail_Supplier_Proof_V1'))return;
  add_management_page('Rail Supplier Proofs','Rail Supplier Proofs',
    'manage_woocommerce','mad4b-rail-proofs',[__CLASS__,'page']);
 }
 static function page(){
  if(!current_user_can('manage_woocommerce')||
     !class_exists('MAD4B_Rail_Supplier_Proof_V1'))wp_die('Forbidden');
  $current=MAD4B_Rail_Supplier_Proof_V1::request_rows();
  $records=(array)get_option(MAD4B_Rail_Supplier_Proof_V1::STORE,[]);
  $state=sanitize_key(wp_unslash((string)($_GET['rail_proof_result']??'')));
  echo '<div class="wrap"><h1>Rail Supplier Quote Evidence</h1>';
  echo '<p>Supplier quotes are evidence for review, not authoritative inventory or a published Transport Rate. Remove personal/passport details from any attached document.</p>';
  if($state!=='')echo '<div class="notice notice-info"><p>'.esc_html($state).'</p></div>';
  echo '<p><a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=booking_experience')).'">WooCommerce → Booking Experience</a> | ';
  echo '<a href="'.esc_url(admin_url('tools.php?page=mad4b-rail-fares')).'">Rail Fare Requests</a></p>';
  echo '<h2>Source requests ready for supplier evidence</h2>';
  if(!$current)echo '<p>No dated requests yet. Select date, class and market in Rail Fare Requests first.</p>';
  foreach($current as $key=>$req){
    $s=(array)($req['snapshot']??[]);
    $cfg=MAD4B_Rail_Fare_Quote_Intake_V1::config((int)($s['transport_id']??0));
    if(is_wp_error($cfg))continue;
    echo '<div class="postbox" style="max-width:930px;padding:12px 20px">';
    echo '<h3>Train '.esc_html($s['train_number']).' · '.esc_html($s['travel_date']).
      ' · '.esc_html(str_replace('_',' ',$s['service_class'])).' · '.esc_html($s['market']).'</h3>';
    echo '<p>Route: '.esc_html($cfg['from_station'].' → '.$cfg['to_station']).' · Supplier: <a target="_blank" rel="noopener noreferrer" href="'.
      esc_url($cfg['provider_source']).'">'.esc_html(strtoupper($cfg['provider'])).'</a></p>';
    echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
    wp_nonce_field('mad4b_rail_evidence_'.$key,'mad4b_rail_evidence_nonce');
    echo '<input type="hidden" name="action" value="mad4b_rail_evidence_submit">';
    echo '<input type="hidden" name="request_key" value="'.esc_attr($key).'">';
    echo '<p><label>Supplier quote reference <input name="operator_reference" type="text" maxlength="120" required></label></p>';
    echo '<p><label>Amount <input name="amount" type="number" min="0.01" step="0.01" required></label> ';
    echo '<label>Currency <select name="currency">';
    foreach(['EGP','USD','EUR','GBP'] as $cur)
     echo '<option value="'.esc_attr($cur).'" '.selected($cur,get_woocommerce_currency(),false).'>'.esc_html($cur).'</option>';
    echo '</select></label></p>';
    echo '<p><label>Official supplier source URL <input name="source_url" type="url" size="62" required placeholder="https://..."></label></p>';
    echo '<p><label>Redacted WordPress Media attachment ID (optional) <input name="attachment_id" type="number" min="0"></label></p>';
    echo '<p><label>Quote expires at (site timezone) <input name="expires_at" type="datetime-local" required></label></p>';
    echo '<button class="button button-primary" type="submit" '.
      (MAD4B_Rail_Supplier_Proof_V1::can_submit()?'':'disabled').'>Save evidence for independent review</button>';
    echo '</form></div>';
  }
  echo '<h2>Previously submitted evidence</h2>';
  echo '<table class="widefat striped" style="max-width:930px"><thead><tr><th>Train</th><th>Travel date</th><th>Claimed amount</th><th>Status</th><th>Source</th></tr></thead><tbody>';
  foreach(array_reverse($records,true) as $r){
    $s=(array)($r['request_snapshot']??[]);
    echo '<tr><td>'.esc_html($s['train_number']??'').'</td><td>'.esc_html($s['travel_date']??'').'</td>';
    echo '<td>'.esc_html(($r['supplier_claimed_amount']??'').' '.($r['supplier_currency']??'')).'</td>';
    echo '<td>'.esc_html($r['status']??'').'</td><td><a target="_blank" rel="noopener noreferrer" href="'.
     esc_url($r['source_url']??'').'">Supplier page</a></td></tr>';
  }
  if(!$records)echo '<tr><td colspan="5">No submitted supplier proof yet</td></tr>';
  echo '</tbody></table></div>';
 }
 static function submit(){
  if(!class_exists('MAD4B_Rail_Supplier_Proof_V1')||
     !MAD4B_Rail_Supplier_Proof_V1::can_submit())wp_die('Forbidden');
  $key=sanitize_text_field(wp_unslash((string)($_POST['request_key']??'')));
  $nonce=sanitize_text_field(wp_unslash((string)($_POST['mad4b_rail_evidence_nonce']??'')));
  if(!wp_verify_nonce($nonce,'mad4b_rail_evidence_'.$key))wp_die('Forbidden');
  $data=['request_key'=>$key];
  foreach(['operator_reference','amount','currency','source_url','expires_at','attachment_id'] as $k)
   $data[$k]=sanitize_text_field(wp_unslash((string)($_POST[$k]??'')));
  $result=MAD4B_Rail_Supplier_Proof_V1::submit($data);
  $state=is_wp_error($result)?$result->get_error_code():'evidence_saved_review_required';
  wp_safe_redirect(add_query_arg(['page'=>'mad4b-rail-proofs','rail_proof_result'=>$state],
    admin_url('tools.php')));
  exit;
 }
}
add_action('admin_menu',['MAD4B_Rail_Supplier_Proof_Admin_V1','menu']);
add_action('admin_post_mad4b_rail_evidence_submit',['MAD4B_Rail_Supplier_Proof_Admin_V1','submit']);
