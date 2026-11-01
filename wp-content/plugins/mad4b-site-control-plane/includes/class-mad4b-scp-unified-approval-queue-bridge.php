<?php
if (!defined('ABSPATH')) exit;
/** Read-only join between review lifecycle and durable job/lease queues.
 * Does NOT claim a queue item, approve an execution ticket, or mutate content.
 */
final class MAD4B_SCP_Unified_Approval_Queue_Bridge {
 const CONTRACT='mad4b.unified-review-queue-gate.v1';
 public static function gate($review_id){
  if(!preg_match('/^[a-f0-9]{64}$/D',(string)$review_id))
   return new WP_Error('mad4b_review_queue_id_invalid','Exact review identifier required.');
  $review=MAD4B_SCP_Unified_Approval_Workflow::inspect($review_id);
  if(is_wp_error($review))return $review;
  $approved=($review['state']??'')==='REVIEW_APPROVED';
  return array('contract'=>self::CONTRACT,'review_id'=>$review_id,
   'state'=>$review['state'],'review_completed'=>$approved,
   'source_sha256'=>$review['content_hash'],
   'approval_policy_sha256'=>$review['policy_sha256'],
   'next_step'=>$approved?'original_owner_authorization_required':
    (($review['state']??'')==='WAITING_HUMAN'?'wait_for_human_review':'review_pending_or_rejected'),
   'provider_execution_authorized'=>false,
   'original_mutation_ticket_issued'=>false,'publication_authorized'=>false,
   'mutation_performed'=>false,'read_only'=>true);
 }
}
