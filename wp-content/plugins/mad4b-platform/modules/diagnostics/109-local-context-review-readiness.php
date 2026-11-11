<?php
/** ETG local lab: advisory review evidence is never an authorization credential. */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') return;
function local_review_readiness($attachment_id) {
 $id=absint($attachment_id);
 if(!function_exists('shared_mad4b_media_source_plan')) return ['ready'=>false,'reason'=>'provider_unavailable'];
 $plan=shared_mad4b_media_source_plan($id);
 if(is_wp_error($plan)) return ['ready'=>false,'reason'=>$plan->get_error_code()];
 $hash=$plan['asset']['content_hash'];
 $rounds=get_post_meta($id,'_mad4b_media_ai_review_rounds',true);
 if(!is_array($rounds) || !$rounds)return ['ready'=>false,'reason'=>'review_rounds_missing'];
 $matching=[];
 foreach($rounds as $round){
  if(!is_array($round) || !isset($round['content_hash'],$round['decision'],$round['findings']))continue;
  if(hash_equals((string)$hash,(string)$round['content_hash']))$matching[]=$round;
 }
 if(!$matching)return ['ready'=>false,'reason'=>'review_evidence_stale'];
 $last=end($matching);
 if(($last['decision']??'')!=='candidate_approved')return ['ready'=>false,'reason'=>'latest_round_not_candidate'];
 if(trim((string)$last['findings'])==='')return ['ready'=>false,'reason'=>'findings_missing'];
 // Candidate status does not grant native approval or publication permission.
 return ['ready'=>true,'reason'=>'advisory_candidate_only','rounds'=>count($matching),'content_hash'=>$hash,'native_approval'=>false];
}
