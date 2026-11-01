<?php
if(!defined('ABSPATH'))exit;
final class MAD4B_SCP_Unified_Approval_Admin {
 public static function boot(){
  // Prevent duplicate REST registration if the previous standalone pilot is active.
  if (function_exists('mad4b_unified_approval_boot')) return;
  if (wp_get_environment_type()!=='local') return;
  add_action('admin_menu',[__CLASS__,'register_admin']);
  add_action('admin_post_mad4b_unified_policy_save',[__CLASS__,'save_policy']);
  add_action('admin_post_mad4b_unified_human_decision',[__CLASS__,'human_decision']);
  add_action('admin_post_mad4b_unified_human_start',[__CLASS__,'human_start']);
  add_action('rest_api_init',[__CLASS__,'register_rest']);
 }
 public static function register_admin(){
  add_management_page('MAD4B Approval Policies','MAD4B Approval Policies','manage_options',
   'mad4b-unified-approvals',[__CLASS__,'render']);
 }
 private static function owner(){return current_user_can('manage_options')&&
  MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id());}
 public static function render(){
  if(!self::owner())wp_die('Enrolled owner only');
  $p=MAD4B_SCP_Unified_Policy::registry();
  echo '<div class="wrap"><h1>MAD4B Unified Approval — Local</h1>';
  echo '<p>Review policy only. No ability grants, post publication, price updates, deployment or Breakglass.</p>';
  echo '<p>Revision: '.esc_html((string)$p['revision']).'</p><table class="widefat striped"><thead><tr>';
  foreach(['Environment','Module','Operation','Resource','Mode','Decision Rule'] as $h)echo '<th>'.esc_html($h).'</th>';
  echo '</tr></thead><tbody>';
  foreach($p['rules'] as $r){echo '<tr>';
   foreach(['environment','module','operation','resource','mode','decision_rule'] as $k)echo '<td>'.esc_html((string)$r[$k]).'</td>';
   echo '</tr>';}
  echo '</tbody></table><h2>Add a scoped review rule</h2>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="mad4b_unified_policy_save">';
  echo '<input type="hidden" name="revision" value="'.esc_attr((string)$p['revision']).'">';
  wp_nonce_field('mad4b_unified_policy_update');
  foreach(['environment'=>'local','module'=>'product_catalog','operation'=>'content_review','resource'=>'*'] as $key=>$val){
   echo '<p><label>'.esc_html($key).' <input name="'.esc_attr($key).'" value="'.esc_attr($val).'" required></label></p>';}
  echo '<p><label>Mode <select name="mode">';foreach(MAD4B_SCP_Unified_Policy::MODES as $v)echo '<option value="'.esc_attr($v).'">'.esc_html($v).'</option>';
  echo '</select></label></p><p><label>Final decision <select name="decision_rule">';
  foreach(MAD4B_SCP_Unified_Policy::RULES as $v)echo '<option value="'.esc_attr($v).'">'.esc_html($v).'</option>';
  echo '</select></label></p><p><button class="button button-primary">Save rule with original owner approval</button></p></form>';
  $sources=get_option('mad4b_scp_ai_native_owner_review_sources_v1',[]);
  echo '<h2>Open a review of an enrolled original source</h2>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="mad4b_unified_human_start">';
  wp_nonce_field('mad4b_unified_review_human_decide');
  echo '<select name="domain">';
  foreach((array)$sources as $domain=>$item){
   if(in_array($domain,MAD4B_SCP_Unified_Approval_Workflow::DOMAINS,true))
    echo '<option value="'.esc_attr($domain).'">'.esc_html($domain.' — '.$item).'</option>';
  }
  echo '</select><button class="button">Open enrolled source review</button></form>';
  $reviews=get_option(MAD4B_SCP_Unified_Approval_Workflow::OPTION,[]);
  echo '<h2>Human review stages</h2>';
  foreach((array)$reviews as $r){if(($r['state']??'')!=='WAITING_HUMAN')continue;
   echo '<p>'.esc_html($r['domain'].' '.$r['item_id']).' — '.esc_html($r['review_id']).'</p>';
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   echo '<input type="hidden" name="action" value="mad4b_unified_human_decision">';
   echo '<input type="hidden" name="review_id" value="'.esc_attr($r['review_id']).'">';
   wp_nonce_field('mad4b_unified_review_human_decide');
   echo '<select name="decision"><option value="approve">Approve review</option><option value="needs_changes">Needs changes</option><option value="reject">Reject</option></select>';
   echo '<button class="button">Record human review stage</button></form>';}
  echo '</div>';
 }
 public static function save_policy(){
  if(!self::owner())wp_die('Enrolled owner required',403);
  $raw=wp_unslash($_POST);
  $keys=['environment','module','operation','resource','mode','decision_rule'];$item=[];
  foreach($keys as $k)$item[$k]=sanitize_text_field((string)($raw[$k]??''));
  $old=MAD4B_SCP_Unified_Policy::registry();$rows=$old['rules'];$found=false;
  foreach($rows as $i=>$r){
   if($r['environment']===$item['environment']&&$r['module']===$item['module']&&
      $r['operation']===$item['operation']&&$r['resource']===$item['resource']){
       $rows[$i]=$item;$found=true;break;}}
  if(!$found)$rows[]=$item;
  $result=MAD4B_SCP_Unified_Policy::save($rows,(int)($raw['revision']??-1),(string)($raw['_wpnonce']??''));
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'Policy rejected',['response'=>403]);
  wp_safe_redirect(admin_url('tools.php?page=mad4b-unified-approvals'));exit;
 }
 public static function human_start(){
  if(!self::owner())wp_die('Enrolled owner required',403);
  $sources=get_option('mad4b_scp_ai_native_owner_review_sources_v1',[]);
  $domain=sanitize_key((string)($_POST['domain']??''));
  $item=is_array($sources)?($sources[$domain]??''):'';
  $nonce=sanitize_text_field((string)($_POST['_wpnonce']??''));
  $result=MAD4B_SCP_Unified_Approval_Workflow::open($domain,(string)$item,$nonce);
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'Review rejected',['response'=>403]);
  wp_safe_redirect(admin_url('tools.php?page=mad4b-unified-approvals'));exit;
 }
 public static function human_decision(){
  if(!self::owner())wp_die('Enrolled owner required',403);
  $result=MAD4B_SCP_Unified_Approval_Workflow::decide_human(
   sanitize_text_field((string)($_POST['review_id']??'')),
   sanitize_key((string)($_POST['decision']??'')),
   sanitize_text_field((string)($_POST['_wpnonce']??'')));
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'Decision rejected',['response'=>403]);
  wp_safe_redirect(admin_url('tools.php?page=mad4b-unified-approvals'));exit;
 }
 public static function rest_permission(){
  if(wp_get_environment_type()!=='local')return false;
  if(self::owner())return true;
  return !is_wp_error(MAD4B_SCP_Background_Authorization::current());
 }
 public static function register_rest(){
  foreach([
   ['/policy','GET','rest_policy'],['/gates/plan','GET','rest_gate_plan'],['/reviews/open','POST','rest_open'],
   ['/reviews/ai','POST','rest_ai'],['/reviews/read','GET','rest_read'],
   ['/browser/plan','POST','rest_browser_plan'],['/queue/gate','GET','rest_queue_gate'],['/extensions','GET','rest_extensions']
  ] as $route){
   register_rest_route('mad4b-unified/v1',$route[0],[
     'methods'=>$route[1],'callback'=>[__CLASS__,$route[2]],
     'permission_callback'=>[__CLASS__,'rest_permission']]);
  }
 }
 public static function rest_policy($req){
  return MAD4B_SCP_Unified_Policy::resolve([
    'module'=>$req->get_param('module')?:'generic','operation'=>$req->get_param('operation')?:'content_review',
    'resource'=>$req->get_param('resource')?:'']);
 }
 public static function rest_gate_plan($req){
  return MAD4B_SCP_Unified_Gate_Bridge::plan([
   'module'=>(string)$req->get_param('module'),
   'ability'=>(string)$req->get_param('ability'),
   'provider'=>(string)($req->get_param('provider')?:'core'),
   'operation'=>(string)($req->get_param('operation')?:'content_review'),
   'resource'=>(string)($req->get_param('resource')?:'')]);
 }
 public static function rest_open($req){
  $x=$req->get_json_params();if(!is_array($x)||array_diff(array_keys($x),['domain','item_id']))
   return new WP_Error('mad4b_unified_request_denied','Unknown request fields.',['status'=>400]);
  return MAD4B_SCP_Unified_Approval_Workflow::open((string)($x['domain']??''),(string)($x['item_id']??''));
 }
 public static function rest_ai($req){
  $x=$req->get_json_params();if(!is_array($x)||array_diff(array_keys($x),['review_id','receipt','confidence']))
   return new WP_Error('mad4b_unified_request_denied','Unknown request fields.',['status'=>400]);
  return MAD4B_SCP_Unified_Approval_Workflow::decide_ai((string)($x['review_id']??''),$x['receipt']??null,$x['confidence']??1.0);
 }
 public static function rest_read($req){
  return MAD4B_SCP_Unified_Approval_Workflow::inspect((string)$req->get_param('review_id'));
 }
 public static function rest_queue_gate($req){
  return MAD4B_SCP_Unified_Approval_Queue_Bridge::gate((string)$req->get_param('review_id'));
 }
 public static function rest_extensions($req){
  return MAD4B_SCP_Unified_Approval_Extensions::inventory();
 }
 public static function rest_browser_plan($req){
  $x=$req->get_json_params();if(!is_array($x)||array_diff(array_keys($x),['provider','operation','origin']))
   return new WP_Error('mad4b_unified_request_denied','Unknown request fields.',['status'=>400]);
  return MAD4B_SCP_Browser_Auth_Broker::plan($x);
 }
}
