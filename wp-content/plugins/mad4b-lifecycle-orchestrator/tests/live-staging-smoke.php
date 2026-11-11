<?php
$report=['contract'=>'mad4b.lifecycle-orchestrator.live-smoke.v1','site'=>home_url('/'),
 'environment'=>wp_get_environment_type(),'version'=>'0.2.0-staging'];
$names=['mad4b/lifecycle-resolve','mad4b/lifecycle-record','mad4b/lifecycle-status'];
$report['registered_abilities']=array_combine($names,array_map('wp_has_ability',$names));
$report['permissions']=['read'=>MAD4B_Lifecycle_Orchestrator::can_read(),
 'write'=>MAD4B_Lifecycle_Orchestrator::can_write()];
if(!MAD4B_Lifecycle_Orchestrator::scoped())throw new RuntimeException('Scope invalid');
$input=['goal'=>'seo','target_kind'=>'post','target_id'=>67881,
 'event_type'=>'manual','event_key'=>'orchestrator-smoke-20261031-01'];
$plan=MAD4B_Lifecycle_Orchestrator::resolve($input);
$report['resolve']=is_wp_error($plan)?$plan->get_error_code():[
 'state'=>$plan['state'],'authority_blockers'=>$plan['authority_blockers'],
 'read_ability_count'=>count($plan['read_abilities']),
 'provider_mutation_authorized'=>$plan['mutation_authorized'],
 'origin'=>$plan['site']];
$created=MAD4B_Lifecycle_Orchestrator::record($input);
if(is_wp_error($created))throw new RuntimeException('Record: '.$created->get_error_code());
$replay=MAD4B_Lifecycle_Orchestrator::record($input);
$report['record']=['id'=>$created['event_id'],'duplicate_reused'=>($replay['idempotent_replay']??false),
 'scheduled'=>$created['scheduled']];
$complete=MAD4B_Lifecycle_Orchestrator::reconcile($created['event_id']);
$report['status']=is_wp_error($complete)?$complete->get_error_code():[
 'state'=>$complete['state'],'attempts'=>$complete['attempts'],
 'no_write'=>$complete['provider_mutation_performed']===false,
 'authority_blockers'=>$complete['resolution']['authority_blockers']??[]];
$bad=MAD4B_Lifecycle_Orchestrator::resolve(['goal'=>'seo','target_kind'=>'post','target_id'=>999999999,'event_type'=>'manual']);
$report['missing_target_denied']=is_wp_error($bad)?$bad->get_error_code():'NOT_DENIED';
$invalid=MAD4B_Lifecycle_Orchestrator::record(['goal'=>'seo','target_kind'=>'post','target_id'=>67881,'event_type'=>'manual','event_key'=>'<script>']);
$report['unsafe_event_key_denied']=is_wp_error($invalid)?$invalid->get_error_code():'NOT_DENIED';
echo wp_json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
