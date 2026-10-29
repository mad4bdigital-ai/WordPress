<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\RequestContactEvidence;
global $wpdb;
$checks=0;$errors=[];
$check=static function($condition,$label)use(&$checks,&$errors){
    if($condition)$checks++;else $errors[]=$label;
};
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$prior=get_current_user_id();
$tabs=[
 'forms'=>$wpdb->prefix.'fluentform_submissions',
 'requests'=>$wpdb->prefix.'commerce_request_batches',
 'links'=>$wpdb->prefix.'commerce_contact_links',
 'contacts'=>$wpdb->prefix.'fc_subscribers',
 'orders'=>$wpdb->prefix.'wc_orders',
 'jobs'=>$wpdb->prefix.'commerce_workflow_jobs',
];
$before=[];foreach($tabs as $n=>$table)
    $before[$n]=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$contactIds=$wpdb->get_col("SELECT id FROM {$tabs['contacts']} ORDER BY id LIMIT 2");
$check(count($contactIds)===2,'qa_has_two_preexisting_contact_ids');
$check(RequestContactEvidence::enabled()===false,'owner_has_not_enabled_projection');
$flag=static fn($value)=>true;
$id=0;$originalId=0;$otherId=0;
$wpdb->query('START TRANSACTION');
try{
    wp_set_current_user($admin);
    $wpdb->insert($tabs['forms'],[
        'form_id'=>6,'user_id'=>0,'status'=>'unread','response'=>'{}',
        'source_url'=>'http://example.invalid/request-contact-evidence-qa',
        'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
    ]);
    $originalId=(int)$wpdb->insert_id;
    $wpdb->insert($tabs['requests'],[
        'basket_sha256'=>hash('sha256','crm-projection-'.bin2hex(random_bytes(16))),
        'session_sha256'=>hash('sha256','crm-projection-session'),
        'state'=>'pending_review','pricing_state'=>'not_quoted',
        'sales_order_id'=>null,
        'snapshot_json'=>wp_json_encode([[
            'source_id'=>60645,'form_id'=>6,'source_submission_id'=>$originalId,
            'rate_id'=>61158,'components'=>[]
        ]]),
        'created_utc'=>gmdate('Y-m-d H:i:s')
    ]);
    $id=(int)$wpdb->insert_id;
    $check($id>0&&$originalId>0,'original_form_and_request_created_in_transaction');
    $missing=RequestContactEvidence::preview($id);
    $check(is_wp_error($missing)&&$missing->get_error_code()==='request_contact_original_identity_unverified',
        'no_native_identity_refuses_contact_guessing');
    $disabled=RequestContactEvidence::ensure($id);
    $check(is_wp_error($disabled)&&$disabled->get_error_code()==='request_contact_projection_disabled',
        'default_feature_off_denies_actual_link');
    $wpdb->insert($tabs['links'],[
        'contact_id'=>(int)$contactIds[0],
        'domain'=>'fluent_form','source_key'=>'inquiry',
        'source_id'=>$originalId,'role'=>'requester',
        'actor_id'=>$admin,'created_utc'=>gmdate('Y-m-d H:i:s')
    ]);
    $check((int)$wpdb->insert_id>0,'synthetic_verified_form_contact_relation');
    $preview=RequestContactEvidence::preview($id);
    $check(!is_wp_error($preview)&&
        (int)($preview['existing_contact_id']??0)===(int)$contactIds[0]&&
        $preview['already_linked']===false,
        'existing_original_contact_proof_identified_without_email');
    $getRoute='/commerce-composition/v1/request-reviews/'.$id.'/contact';
    wp_set_current_user(0);
    $denied=rest_do_request(new WP_REST_Request('GET',$getRoute));
    $check(in_array($denied->get_status(),[401,403],true),
        'anonymous_identity_preview_denied');
    wp_set_current_user($admin);
    $restPreview=rest_do_request(new WP_REST_Request('GET',$getRoute));
    $check($restPreview->get_status()===200,
        'admin_identity_preview_authorized');
    $restOff=rest_do_request(new WP_REST_Request('POST',$getRoute));
    $check($restOff->get_status()===422&&
        ($restOff->get_data()['code']??'')==='request_contact_projection_disabled',
        'rest_write_refused_when_owner_feature_off');
    add_filter('pre_option_commerce_request_contact_projection_enabled',$flag,99);
    ob_start();
    Commerce\Composition\RequestReviewAdmin::page();
    $inbox=ob_get_clean();
    $check(str_contains($inbox,'commerce_review_link_contact')&&
       str_contains($inbox,'Link verified CRM contact'),
       'admin_inbox_has_nonce_scoped_verified_link_action');
    $check(!str_contains($inbox,'qa-consent-sink@example.invalid')&&
       !str_contains($inbox,'fake@example.invalid'),
       'admin_inbox_no_contact_email_projection');
    $injected=new WP_REST_Request('POST',$getRoute);
    $injected->set_header('Content-Type','application/json');
    $injected->set_body(wp_json_encode(['email'=>'a@example.invalid','contact_id'=>(int)$contactIds[1]]));
    $invalid=rest_do_request($injected);
    $check($invalid->get_status()===400,'client_email_and_contact_override_rejected');
    $requestResponse=rest_do_request(new WP_REST_Request('POST',$getRoute));
    $data=$requestResponse->get_data()['result']??[];
    $check($requestResponse->get_status()===200&&
        $data['status']==='linked'&&
        (int)$data['contact_id']===(int)$contactIds[0],
        'request_link_created_through_existing_contactbridge');
    $repeat=rest_do_request(new WP_REST_Request('POST',$getRoute));
    $check($repeat->get_status()===200&&
        ($repeat->get_data()['result']['status']??'')==='already_linked',
        'request_crm_relation_replay_idempotent');
    $existing=$wpdb->get_col($wpdb->prepare(
        "SELECT contact_id FROM {$tabs['links']} WHERE domain='request'
         AND source_key='request' AND source_id=%d AND role='requester'",$id));
    $check(count($existing)===1&&(int)$existing[0]===(int)$contactIds[0],
        'exact_one_authoritative_requester_relation');
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$tabs['contacts']}")===$before['contacts'],
        'no_contact_created_or_removed');
    // Bundled trips may contain independently submitted components.
    // All original forms must agree on ONE pre-proven existing CRM identity.
    $wpdb->insert($tabs['forms'],[
        'form_id'=>3,'user_id'=>0,'status'=>'unread','response'=>'{}',
        'source_url'=>'http://example.invalid/request-contact-evidence-qa',
        'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
    ]);
    $secondOriginal=(int)$wpdb->insert_id;
    $wpdb->insert($tabs['links'],[
        'contact_id'=>(int)$contactIds[0],'domain'=>'fluent_form',
        'source_key'=>'inquiry','source_id'=>$secondOriginal,
        'role'=>'requester','actor_id'=>$admin,
        'created_utc'=>gmdate('Y-m-d H:i:s')
    ]);
    $wpdb->update($tabs['requests'],['snapshot_json'=>wp_json_encode([
        ['source_id'=>60645,'form_id'=>6,'source_submission_id'=>$originalId,
            'rate_id'=>61158,'components'=>[]],
        ['source_id'=>60700,'form_id'=>3,'source_submission_id'=>$secondOriginal,
            'rate_id'=>61000,'components'=>[]]
    ])],['id'=>$id]);
    $bundle=RequestContactEvidence::preview($id);
    $check(!is_wp_error($bundle)&&
        $bundle['original_source_count']===2&&
        $bundle['already_linked']===true,
        'multi_service_bundle_requires_and_accepts_same_contact');
    $wpdb->update($tabs['links'],['contact_id'=>(int)$contactIds[1]],
        ['domain'=>'fluent_form','source_key'=>'inquiry',
         'source_id'=>$secondOriginal,'role'=>'requester']);
    $mixed=RequestContactEvidence::preview($id);
    $check(is_wp_error($mixed)&&
        $mixed->get_error_code()==='request_contact_mixed_customer_identity',
        'different_customer_in_second_component_rejected');
    $wpdb->update($tabs['links'],['contact_id'=>(int)$contactIds[0]],
        ['domain'=>'fluent_form','source_key'=>'inquiry',
         'source_id'=>$secondOriginal,'role'=>'requester']);
    $statesBefore=$wpdb->get_results("SELECT id,status FROM {$tabs['contacts']} ORDER BY id",ARRAY_A);
    $wpdb->insert($tabs['links'],[
        'contact_id'=>(int)$contactIds[1],'domain'=>'fluent_form','source_key'=>'inquiry',
        'source_id'=>$originalId,'role'=>'requester','actor_id'=>$admin,
        'created_utc'=>gmdate('Y-m-d H:i:s')
    ]);
    $ambiguous=RequestContactEvidence::preview($id);
    $check(is_wp_error($ambiguous)&&
        $ambiguous->get_error_code()==='request_contact_original_identity_unverified',
        'two_contact_source_conflict_fails_closed');
    $statesAfter=$wpdb->get_results("SELECT id,status FROM {$tabs['contacts']} ORDER BY id",ARRAY_A);
    $check($statesBefore===$statesAfter,'native_crm_subscription_states_unchanged');
}finally{
    $wpdb->query('ROLLBACK');
    remove_filter('pre_option_commerce_request_contact_projection_enabled',$flag,99);
    wp_set_current_user($prior);
}
foreach($tabs as $name=>$table)
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$before[$name],
        'transaction_rollback_preserves_'.$name);
echo wp_json_encode(['result'=>$errors?'FAIL':'PASS','checks'=>$checks,
  'failed'=>$errors,'real_contact_updates'=>false,'emails_sent'=>false,
  'original_records_unchanged'=>true]).PHP_EOL;
if($errors)exit(2);
