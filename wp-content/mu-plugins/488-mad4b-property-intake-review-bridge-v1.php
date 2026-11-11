<?php
/** Native Content Hub UI extension for product intake review requests.
 * Creates real Content Jobs. Intake decisions never publish Products or Rates.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Property_Intake_Review_Bridge_V1')){
final class MAD4B_Property_Intake_Review_Bridge_V1 {
 const ACTION='mad4b_property_intake_create_review_v1';
 const DECIDE='mad4b_property_intake_review_decide_v1';
 private static function err($x){return new WP_Error('property_intake_review_'.$x);}
 private static function actor(){return is_user_logged_in()&&current_user_can('manage_options');}
 private static function scope_ok(){
  return class_exists('MAD4B_SCP_Site_Profile')
   &&MAD4B_SCP_Site_Profile::origin_enrolled()
   &&MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
 }
 public static function url($id){
  return admin_url('admin.php?page=mad4b-hub-master-review&intake_id='.rawurlencode($id));
 }
 public static function item($id) {
  if(!is_string($id)||!preg_match('/^[a-f0-9]{20}$/D',$id))return self::err('id_invalid');
  $rows=MAD4B_Property_Intake_Admin_V1::records();
  if(is_wp_error($rows))return $rows;
  $row=$rows[$id]??null;
  if(!is_array($row)||empty($row['plan'])||!is_array($row['plan']))return self::err('missing');
  return $row;
 }
 private static function job($row){
  if(empty($row['content_job_id']))return self::err('job_not_bound');
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$row['content_job_id']]);
  if(is_wp_error($job))return $job;
  $j=$job['job']??[];
  if(($j['job_id']??'')!==$row['content_job_id'])return self::err('job_identity_mismatch');
  return $j;
 }
 public static function create($id,$expectedDigest){
  if(!self::actor()||!self::scope_ok())return self::err('actor_or_scope_denied');
  if(!is_string($id)||!preg_match('/^[a-f0-9]{20}$/D',$id))return self::err('id_invalid');
  global $wpdb;
  $lock='mad4b_intake_review_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix.'|'.MAD4B_SCP_Site_Profile::site_uuid().'|'.$id),0,28);
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1)return self::err('busy_retry');
  try{return self::create_locked($id,$expectedDigest);}
  finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 private static function create_locked($id,$expectedDigest){
  if(!self::actor()||!self::scope_ok())return self::err('actor_or_scope_denied');
  $row=self::item($id);if(is_wp_error($row))return $row;
  $digest=(string)($row['plan']['candidate_digest']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$expectedDigest)||!hash_equals($digest,$expectedDigest))
   return self::err('source_changed_reload');
  if(!empty($row['content_job_id'])){
   $j=self::job($row);
   return is_wp_error($j)?$j:['state'=>'already_created','job_id'=>$row['content_job_id'],'review_url'=>self::url($id)];
  }
  $scope=MAD4B_SCP_Operational_Integrity::capture();
  if(is_wp_error($scope))return $scope;
  $trusted=$scope['scope']??[];
  if(!isset($trusted['brand_ref']))return self::err('trusted_brand_missing');
  $plan=$row['plan'];
  $subject='Product intake review: '.($plan['proposed_title']??'Untitled')
    .' ['.($plan['company_property_key']??'').']';
  $payload=[
    'subject'=>$subject,
    'reason'=>'Review company-submitted '.($row['product_family']??'property').' candidate, source evidence, units and unapproved prices. Intake digest '.$digest.'. NO PUBLISH.',
    'brand_id'=>$trusted['brand_ref'],'language'=>'en','country'=>'eg',
    'content_type'=>'product_catalog','target_post_type'=>'properties',
    'research_depth'=>'standard'
  ];
  $created=MAD4B_SCP_Content_Jobs::create_job($payload);
  if(is_wp_error($created))return $created;
  $jobId=(string)($created['job_id']??($created['job']['job_id']??''));
  if(!preg_match('/^[a-f0-9-]{36}$/D',$jobId))
    return self::err('create_receipt_missing_reconcile');
  // Re-read exact candidate immediately before binding, rejecting an edited source.
  $current=self::item($id);
  if(is_wp_error($current)||($current['plan']['candidate_digest']??'')!==$digest)
    return self::err('source_changed_after_job_creation_reconcile');
  $scopeKey=MAD4B_Property_Intake_Admin_V1::scope();
  if(is_wp_error($scopeKey))return $scopeKey;
  $all=MAD4B_Property_Intake_Admin_V1::records();
  if(is_wp_error($all)||!isset($all[$id]))return self::err('record_missing_after_job');
  if(!empty($all[$id]['content_job_id']))return self::err('concurrent_job_binding_reconcile');
  $all[$id]['content_job_id']=$jobId;
  $all[$id]['review_source_digest']=$digest;
  $all[$id]['review_created_at']=gmdate('c');
  $all[$id]['state']='REVIEW_REQUESTED';
  update_option($scopeKey,$all,false);
  $verified=self::item($id);
  if(is_wp_error($verified)||($verified['content_job_id']??'')!==$jobId)
    return self::err('job_binding_readback_failed');
  return ['state'=>'REVIEW_REQUESTED','job_id'=>$jobId,'review_url'=>self::url($id),'mutation_performed'=>true];
 }
 public static function create_post(){
  if(!self::actor())wp_die('Not authorized',403);
  check_admin_referer(self::ACTION,'intake_review_nonce');
  $id=sanitize_text_field(wp_unslash($_POST['intake_id']??''));
  $digest=sanitize_text_field(wp_unslash($_POST['candidate_digest']??''));
  $result=self::create($id,$digest);
  $params=['page'=>'mad4b-property-intake','tab'=>'review'];
  if(is_wp_error($result))$params['review_error']=$result->get_error_code();
  else{$params=['page'=>'mad4b-hub-master-review','intake_id'=>$id];}
  wp_safe_redirect(add_query_arg($params,admin_url('admin.php')),303);exit;
 }
 public static function decide($id,$expectedDigest,$decision,$note){
  if(!self::actor()||!self::scope_ok())return self::err('actor_or_scope_denied');
  if(!is_string($id)||!preg_match('/^[a-f0-9]{20}$/D',$id))return self::err('id_invalid');
  global $wpdb;
  $lock='mad4b_intake_review_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix.'|'.MAD4B_SCP_Site_Profile::site_uuid().'|'.$id),0,28);
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1)return self::err('busy_retry');
  try{return self::decide_locked($id,$expectedDigest,$decision,$note);}
  finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 private static function decide_locked($id,$expectedDigest,$decision,$note){
  if(!self::actor()||!self::scope_ok())return self::err('actor_or_scope_denied');
  if(!in_array($decision,['approve','needs_changes','reject'],true))return self::err('decision_invalid');
  if(!is_string($note)||mb_strlen(trim($note))<8||mb_strlen($note)>500)return self::err('note_required');
  $row=self::item($id);if(is_wp_error($row))return $row;
  if(($row['state']??'')!=='REVIEW_REQUESTED'||empty($row['content_job_id']))
   return self::err('request_not_pending');
  $digest=$row['plan']['candidate_digest']??'';
  if(!is_string($expectedDigest)||!hash_equals((string)$digest,$expectedDigest)
    ||!hash_equals((string)($row['review_source_digest']??''),(string)$digest))
   return self::err('source_changed');
  $job=self::job($row);if(is_wp_error($job))return $job;
  $key=MAD4B_Property_Intake_Admin_V1::scope();if(is_wp_error($key))return $key;
  $all=MAD4B_Property_Intake_Admin_V1::records();
  if(is_wp_error($all)||($all[$id]['state']??'')!=='REVIEW_REQUESTED'
    ||($all[$id]['content_job_id']??'')!==$row['content_job_id'])
   return self::err('concurrent_change');
  $now=gmdate('c');
  $all[$id]['review_decision']=$decision;
  $all[$id]['reviewed_by']=get_current_user_id();
  $all[$id]['reviewed_at']=$now;
  $all[$id]['review_note']=sanitize_textarea_field($note);
  $all[$id]['state']=match($decision){'approve'=>'CONTENT_REVIEW_APPROVED',
   'needs_changes'=>'CHANGES_REQUESTED','reject'=>'REJECTED'};
  // This is an intake editorial decision only; never a Rate approval or content-job completion.
  $all[$id]['commercial_authority']=false;
  update_option($key,$all,false);
  $checked=self::item($id);
  if(is_wp_error($checked)||($checked['review_decision']??'')!==$decision)
   return self::err('readback_failed');
  if(class_exists('MAD4B_SCP_Audit')){
    MAD4B_SCP_Audit::record('mad4b/property-intake-review-decision',[
      'job_id'=>$row['content_job_id'],'source_digest'=>$digest,
      'decision'=>$decision,'reviewer'=>get_current_user_id(),'commercial_authority'=>false
    ],'ok');
  }
  return ['decision'=>$decision,'job_id'=>$row['content_job_id'],'state'=>$all[$id]['state'],'publication_authorized'=>false];
 }
 public static function decide_post(){
  if(!self::actor())wp_die('Not authorized',403);
  check_admin_referer(self::DECIDE,'intake_decide_nonce');
  $id=sanitize_text_field(wp_unslash($_POST['intake_id']??''));
  $expected=sanitize_text_field(wp_unslash($_POST['candidate_digest']??''));
  $decision=sanitize_key(wp_unslash($_POST['decision']??''));
  $note=sanitize_textarea_field(wp_unslash($_POST['review_note']??''));
  $result=self::decide($id,$expected,$decision,$note);
  $params=['page'=>'mad4b-hub-master-review','intake_id'=>$id];
  if(is_wp_error($result))$params['intake_error']=$result->get_error_code();
  else $params['intake_saved']='1';
  wp_safe_redirect(add_query_arg($params,admin_url('admin.php')),303);exit;
 }
 public static function inbox_section(){
  if(!self::actor()||!self::scope_ok())return;
  $rows=MAD4B_Property_Intake_Admin_V1::records();
  if(is_wp_error($rows))return;
  $items=array_filter($rows,static fn($r)=>!empty($r['content_job_id']));
  if(!$items)return;
  $counts=['REVIEW_REQUESTED'=>0,'CONTENT_REVIEW_APPROVED'=>0,'CHANGES_REQUESTED'=>0,'REJECTED'=>0];
  foreach($items as $item){
   $state=(string)($item['state']??'');
   if(isset($counts[$state]))$counts[$state]++;
  }
  echo '<section class="mad4b-rv-list mad4b-intake-review-section"><div class="mad4b-rv-listhead"><h2>Product Intake Reviews</h2><span>'.count($items).' review requests</span></div>';
  echo '<div class="mad4b-intake-review-summary" aria-label="Product intake review statuses">';
  foreach([['REVIEW_REQUESTED','Pending'],['CONTENT_REVIEW_APPROVED','Editorially approved'],['CHANGES_REQUESTED','Changes requested'],['REJECTED','Rejected']] as $metric)
   echo '<span><strong>'.absint($counts[$metric[0]]).'</strong>'.esc_html($metric[1]).'</span>';
  echo '</div>';
  foreach(array_reverse($items,true) as $id=>$row){
   $p=$row['plan'];
   echo '<article class="mad4b-rv-item"><div class="mad4b-rv-fileicon"><span class="dashicons dashicons-building"></span></div><div class="mad4b-rv-item-main">';
   echo '<h3><a href="'.esc_url(self::url($id)).'">'.esc_html($p['proposed_title']).'</a></h3>';
   echo '<p>Property / Unit intake · '.esc_html($p['platform']).' · '.absint($p['unit_count']).' units · '.absint($p['price_observation_count']).' unapproved prices</p>';
   echo '<div class="mad4b-rv-chips"><span>Content Job: '.esc_html($row['content_job_id']).'</span></div></div>';
   echo '<div class="mad4b-rv-item-action"><span class="mad4b-rv-badge is-pending">'.esc_html($row['state']).'</span>';
   echo '<a class="button" href="'.esc_url(self::url($id)).'">Open review</a></div></article>';
  }
  echo '</section>';
 }
 public static function details_page($id) {
  if(!self::actor()||!self::scope_ok())wp_die('Not authorized',403);
  $row=self::item($id);
  if(is_wp_error($row))wp_die(esc_html($row->get_error_message()),'', ['response'=>404]);
  $job=self::job($row);
  if(is_wp_error($job))wp_die('Content Job unavailable in this company scope',403);
  $p=$row['plan'];
  echo '<div class="wrap mad4b-reviews mad4b-review-workspace mad4b-intake-detail">';
  echo '<header class="mad4b-rv-heading"><div><span class="mad4b-rv-kicker">MAD4B · Content &amp; Commerce</span>';
  echo '<h1>Product Intake Review</h1><p>Review the exact source, product details and price observations before an editorial decision.</p></div>';
  echo '<span class="mad4b-intake-status">'.esc_html($row['state']).'</span></header>';
  if(class_exists('MAD4B_Hub_Review_UI'))
    MAD4B_Hub_Review_UI::workspace_nav(MAD4B_Hub_Review_UI::MASTER);
  echo '<nav class="mad4b-intake-breadcrumb" aria-label="Review navigation"><a href="'.esc_url(admin_url('admin.php?page=mad4b-hub-reviews-inbox')).'">Reviews Inbox</a><span>›</span>';
  echo '<a href="'.esc_url(admin_url('admin.php?page=mad4b-property-intake&tab=review')).'">Import Candidates</a><span>›</span><strong>'.esc_html($p['proposed_title']).'</strong></nav>';
  if(!empty($_GET['intake_saved']))echo '<div class="notice notice-success inline"><p>Review decision recorded. No product or price was published.</p></div>';
  if(!empty($_GET['intake_error']))echo '<div class="notice notice-error inline"><p>'.esc_html(sanitize_key(wp_unslash($_GET['intake_error']))).'</p></div>';
  echo '<div class="mad4b-intake-review-grid"><main class="mad4b-intake-review-main">';
  echo '<section class="mad4b-intake-review-panel"><header><span class="dashicons dashicons-building"></span><div><small>01 · Product identity</small>';
  echo '<h2>'.esc_html($p['proposed_title']).'</h2></div></header>';
  echo '<dl class="mad4b-intake-review-facts"><div><dt>Company key</dt><dd><code>'.esc_html($p['company_property_key']).'</code></dd></div>';
  echo '<div><dt>Location</dt><dd>'.esc_html($p['proposed_address']).'</dd></div>';
  echo '<div><dt>Source</dt><dd>'.esc_html($p['platform'].' / '.$p['mode']).'</dd></div>';
  echo '<div><dt>Evidence</dt><dd>'.esc_html($p['proof_type'].' / '.($p['proof_reference']??'')).'</dd></div></dl>';
  if(!empty($p['source_url']))echo '<p><a href="'.esc_url($p['source_url']).'" target="_blank" rel="noopener noreferrer">Open original source ↗</a></p>';
  echo '</section>';
  echo '<section class="mad4b-intake-review-panel"><header><span class="dashicons dashicons-admin-home"></span><div><small>02 · Product units</small><h2>Unit candidates</h2></div></header>';
  if(empty($p['unit_candidates']))echo '<p>No units attached to this proposal.</p>';
  else foreach($p['unit_candidates'] as $unit){
   echo '<div class="mad4b-intake-review-row"><strong>'.esc_html($unit['name']).'</strong>';
   echo '<span>'.esc_html(ucfirst($unit['kind'])).' · capacity '.absint($unit['capacity']).'</span>';
   if(!empty($unit['amenities']))echo '<small>'.esc_html(implode(' · ',$unit['amenities'])).'</small>';
   echo '</div>';
  }
  echo '</section>';
  echo '<section class="mad4b-intake-review-panel"><header><span class="dashicons dashicons-chart-line"></span><div><small>03 · Rate source observations</small><h2>Prices for review</h2></div></header>';
  echo '<p class="mad4b-intake-warning">These are source observations, not approved supplier prices or bookable rates.</p>';
  if(empty($p['price_observation_candidates']))echo '<p>No price observations provided.</p>';
  else foreach($p['price_observation_candidates'] as $price){
   echo '<div class="mad4b-intake-review-row"><strong>'.esc_html(number_format_i18n($price['amount'],2).' '.$price['currency']).'</strong>';
   echo '<span>'.esc_html($price['basis']).' · '.esc_html($price['date_from'].' — '.$price['date_to']).'</span></div>';
  }
  echo '</section></main><aside class="mad4b-intake-review-aside">';
  echo '<section class="mad4b-intake-review-panel"><header><span class="dashicons dashicons-clipboard"></span><div><small>Review request</small><h2>Source &amp; job</h2></div></header>';
  echo '<dl class="mad4b-intake-review-facts"><div><dt>Content Job ID</dt><dd><code>'.esc_html($row['content_job_id']).'</code></dd></div>';
  echo '<div><dt>Job state</dt><dd>'.esc_html($job['state']??'Unknown').'</dd></div>';
  echo '<div><dt>Version</dt><dd>'.absint($row['revision']??1).'</dd></div></dl>';
  echo '<details><summary>Source fingerprint</summary><code class="mad4b-intake-hash">'.esc_html($p['candidate_digest']).'</code></details></section>';
  echo '<section class="mad4b-intake-review-panel mad4b-intake-review-decision"><header><span class="dashicons dashicons-yes-alt"></span><div><small>04 · Editorial review</small><h2>Review decision</h2></div></header>';
  if(($row['state']??'')==='REVIEW_REQUESTED'){
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::DECIDE).'">';
   echo '<input type="hidden" name="intake_id" value="'.esc_attr($id).'">';
   echo '<input type="hidden" name="candidate_digest" value="'.esc_attr($p['candidate_digest']).'">';
   wp_nonce_field(self::DECIDE,'intake_decide_nonce');
   echo '<label for="mad4b-intake-note">Review notes</label><textarea id="mad4b-intake-note" name="review_note" rows="4" required minlength="8" maxlength="500" placeholder="Explain your decision and any required changes"></textarea>';
   echo '<div class="mad4b-intake-review-buttons"><button class="button button-primary" name="decision" value="approve">Approve content only</button>';
   echo '<button class="button" name="decision" value="needs_changes">Request changes</button>';
   echo '<button class="button" name="decision" value="reject">Reject</button></div></form>';
  }else{
   echo '<p><strong>'.esc_html($row['review_decision']??'').'</strong></p>';
   echo '<p>'.esc_html($row['review_note']??'').'</p>';
  }
  echo '<p class="mad4b-intake-decision-note">Editorial review does not approve a Rate, publish a Property or change checkout readiness.</p>';
  echo '</section></aside></div></div>';
 }
 public static function register(){
  add_action('admin_post_'.self::ACTION,[__CLASS__,'create_post']);
  add_action('admin_post_'.self::DECIDE,[__CLASS__,'decide_post']);
  add_action('mad4b_hub_role_review_inbox_extension',[__CLASS__,'inbox_section']);
 }
}
MAD4B_Property_Intake_Review_Bridge_V1::register();
}
