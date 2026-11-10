<?php
$report=['contract'=>'mad4b.central-admin-company-proxy.smoke.v1','staging'=>wp_get_environment_type(),
 'user'=>get_current_user_id()];
$context=MAD4B_Central_Admin_Company_Proxy::context(['company_code'=>'wayfatours']);
if(is_wp_error($context))throw new RuntimeException($context->get_error_code());
$report['context']=['principal'=>$context['principal_user_id'],
    'mode'=>$context['representation_mode'],
    'company'=>$context['company_code'],
    'owner_approval_substituted'=>$context['owner_approval_substituted'],
    'remote_write_authority_granted'=>$context['remote_write_authority_granted']];
$input=['company_code'=>'wayfatours','operation'=>'company_strategy',
    'reason'=>'Staging central administrator representative mandate acceptance test',
    'event_key'=>'company-platform-proxy-smoke-20261031',
    'expected_binding_sha256'=>$context['company_binding_sha256']];
$first=MAD4B_Central_Admin_Company_Proxy::request($input);
if(is_wp_error($first))throw new RuntimeException('request:'.$first->get_error_code());
$again=MAD4B_Central_Admin_Company_Proxy::request($input);
$read=MAD4B_Central_Admin_Company_Proxy::read_intent(['event_id'=>$first['event_id']]);
$life=MAD4B_Lifecycle_Orchestrator::manual_reconcile(['event_id'=>$first['event_id']]);
$report['request']=['event_id'=>$first['event_id'],
    'first_state'=>$first['state'],
    'replay_same_event'=>!is_wp_error($again)&&$again['event_id']===$first['event_id']&&!empty($again['idempotent_replay']),
    'readback'=>!is_wp_error($read),
    'native_execute'=>!empty($first['native_execution_performed'])];
$report['lifecycle']=is_wp_error($life)?$life->get_error_code():
    ['state'=>$life['state'],'principal_user_id'=>$life['administrator_actor_user_id'],
     'company_code'=>$life['company_code'],'mode'=>$life['representation_mode'],
     'provider_mutation_performed'=>$life['provider_mutation_performed']];
$stale=MAD4B_Central_Admin_Company_Proxy::request($input+[]);
$input['expected_binding_sha256']=str_repeat('f',64);
$denied=MAD4B_Central_Admin_Company_Proxy::request($input);
$report['stale_binding_refused']=is_wp_error($denied)?$denied->get_error_code():'FAIL';
$report['registered_ability']=wp_has_ability('mad4b/central-admin-company-context');
echo wp_json_encode($report,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
