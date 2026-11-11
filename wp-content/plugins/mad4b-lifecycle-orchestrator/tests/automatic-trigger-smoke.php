<?php
$id=67881;$post=get_post($id);
if(!$post)throw new RuntimeException('Test post missing');
$rev=hash('sha256',(string)$post->post_modified_gmt.'|'.$post->post_status.'|'.
 $post->post_title.'|'.hash('sha256',(string)$post->post_content));
$input=['goal'=>'seo','target_kind'=>'post','target_id'=>$id,
 'event_type'=>'post_updated','event_key'=>$rev];
$manual=MAD4B_Lifecycle_Orchestrator::record($input);
do_action('save_post',$id,$post,true);
$again=MAD4B_Lifecycle_Orchestrator::record($input);
$st=MAD4B_Lifecycle_Orchestrator::manual_reconcile(['event_id'=>$manual['event_id']]);
echo wp_json_encode([
 'contract'=>'mad4b.lifecycle-auto-trigger-smoke.v1',
 'registered'=>wp_has_ability('mad4b/lifecycle-reconcile'),
 'event_id'=>$manual['event_id'],
 'post_event_idempotent'=>!empty($again['idempotent_replay']),
 'state'=>is_wp_error($st)?$st->get_error_code():$st['state'],
 'provider_write_performed'=>is_wp_error($st)?null:$st['provider_mutation_performed'],
 'stored_post_modified_gmt'=>$post->post_modified_gmt,
 'post_unchanged'=>get_post_field('post_modified_gmt',$id)===$post->post_modified_gmt
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
