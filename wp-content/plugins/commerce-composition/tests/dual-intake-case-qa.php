<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\IntakeCaseRegistry as Cases;
global $wpdb;$pass=0;$fail=[];
$ok=static function($v,$label)use(&$pass,&$fail){$v?$pass++:$fail[]=$label;};
$t=static fn($n)=>$wpdb->prefix.$n;
$names=['fluentform_submissions','commerce_request_batches','commerce_contact_links','commerce_intake_cases','fc_subscribers','wc_orders','etg_booking_links'];
$before=[];foreach($names as $name)$before[$name]=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t($name));
$prior=get_current_user_id();$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$contacts=$wpdb->get_col('SELECT id FROM '.$t('fc_subscribers').' ORDER BY id LIMIT 2');
$policy=static fn()=>['enabled'=>true,'default_mode'=>'crm_first','channels'=>[],'services'=>[]];
$on=static fn()=>true;
$ok(Cases::ready()&&!Cases::enabled(),'case_registry_exists_but_off');
$wpdb->query('START TRANSACTION');
try{
 wp_set_current_user($admin);
 $wpdb->insert($t('fluentform_submissions'),['form_id'=>6,'status'=>'unread','response'=>'{}','user_id'=>0,
   'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')]);
 $sid=(int)$wpdb->insert_id;
 $snapshot=wp_json_encode([['source_id'=>60645,'form_id'=>6,'source_submission_id'=>$sid,'rate_id'=>61158,'components'=>[]]]);
 $wpdb->insert($t('commerce_request_batches'),['basket_sha256'=>hash('sha256',random_bytes(16)),
   'session_sha256'=>hash('sha256','case-qa'),'sales_order_id'=>null,
   'state'=>'pending_review','pricing_state'=>'not_quoted','snapshot_json'=>$snapshot,'created_utc'=>gmdate('Y-m-d H:i:s')]);
 $id=(int)$wpdb->insert_id;
 $wpdb->insert($t('commerce_contact_links'),['contact_id'=>(int)$contacts[0],
   'domain'=>'fluent_form','source_key'=>'inquiry','source_id'=>$sid,
   'role'=>'requester','actor_id'=>$admin,'created_utc'=>gmdate('Y-m-d H:i:s')]);
 $ok($sid>0&&$id>0,'original_form_and_request_proof');
 $blocked=Cases::ensure($id);
 $ok(is_wp_error($blocked),'default_off_refuses_case_creation');
 add_filter('pre_option_commerce_intake_routing_policy_v1',$policy,99);
 $plan=Cases::preview($id);
 $ok(!is_wp_error($plan)&&$plan['route_mode']==='crm_first'&&$plan['contact_id']===(int)$contacts[0],'original_crm_identity_only');
 add_filter('pre_option_commerce_intake_case_registry_enabled',$on,99);
 $first=Cases::ensure($id);
 $ok(!is_wp_error($first)&&$first['woo_order_id']===null&&$first['crm_native_task_created']===false,'crm_first_case_only');
 $again=Cases::ensure($id);
 $ok(!is_wp_error($again)&&$first===$again,'idempotent_case_replay');
 $wpdb->update($t('commerce_contact_links'),['contact_id'=>(int)$contacts[1]],['domain'=>'fluent_form','source_key'=>'inquiry','source_id'=>$sid,'role'=>'requester']);
 $conflict=Cases::ensure($id);
 $ok(is_wp_error($conflict)&&$conflict->get_error_code()==='intake_case_original_or_policy_conflict','reject_customer_change');
 $wpdb->update($t('commerce_contact_links'),['contact_id'=>(int)$contacts[0]],['domain'=>'fluent_form','source_key'=>'inquiry','source_id'=>$sid,'role'=>'requester']);
 $wpdb->update($t('commerce_request_batches'),['snapshot_json'=>wp_json_encode([['source_id'=>36474,'form_id'=>6,'source_submission_id'=>$sid,'components'=>[]]])],['id'=>$id]);
 $changed=Cases::ensure($id);
 $ok(is_wp_error($changed)&&$changed->get_error_code()==='intake_case_original_or_policy_conflict','reject_source_mutation');
 $wpdb->update($t('commerce_request_batches'),['snapshot_json'=>$snapshot],['id'=>$id]);
 $path='/commerce-composition/v1/intake-cases/'.$id;
 wp_set_current_user(0);
 $guest=rest_do_request(new WP_REST_Request('GET',$path));
 $ok(in_array($guest->get_status(),[401,403],true),'guest_cannot_read_route');
 wp_set_current_user($admin);
 $preview=rest_do_request(new WP_REST_Request('GET',$path));
 $ok($preview->get_status()===200&&($preview->get_data()['result']['route_mode']??'')==='crm_first',
  'operator_can_preview_server_owned_mode');
 $post=rest_do_request(new WP_REST_Request('POST',$path));
 $posted=$post->get_data()['result']??[];
 $ok($post->get_status()===200&&
  is_array($posted)&&array_key_exists('woo_order_id',$posted)&&
  $posted['woo_order_id']===null,
  'operator_post_reuses_original_case_without_order');
 $injected=new WP_REST_Request('POST',$path);
 $injected->set_header('Content-Type','application/json');
 $injected->set_body(wp_json_encode(['contact_id'=>(int)$contacts[1],'route_mode'=>'order_first']));
 $invalid=rest_do_request($injected);
 $ok($invalid->get_status()===400,'requester_cannot_inject_contact_or_route');
 $order=static fn()=>['enabled'=>true,'default_mode'=>'order_first','channels'=>[],'services'=>[]];
 remove_filter('pre_option_commerce_intake_routing_policy_v1',$policy,99);
 add_filter('pre_option_commerce_intake_routing_policy_v1',$order,99);
 $forbidden=Cases::ensure($id);
 $ok(is_wp_error($forbidden)&&$forbidden->get_error_code()==='intake_case_order_first_factory_not_certified',
  'order_first_cannot_forge_woo_order');
 remove_filter('pre_option_commerce_intake_routing_policy_v1',$order,99);
 add_filter('pre_option_commerce_intake_routing_policy_v1',$policy,99);
}finally{
 $wpdb->query('ROLLBACK');remove_filter('pre_option_commerce_intake_routing_policy_v1',$policy,99);
 remove_filter('pre_option_commerce_intake_case_registry_enabled',$on,99);wp_set_current_user($prior);
}
foreach($names as $name)$ok((int)$wpdb->get_var('SELECT COUNT(*) FROM '.$t($name))===$before[$name],'rollback_'.$name);
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','checks'=>$pass,'failed'=>$fail]).PHP_EOL;
if($fail)exit(2);
