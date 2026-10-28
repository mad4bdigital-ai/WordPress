<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
$intent=[
'schema'=>1,'mode'=>'request_only',
'status'=>'validated_intent_not_cart_session_committed',
'cart_authority'=>false,'checkout_authority'=>false,
'form_id'=>6,'source_submission_id'=>99081001,
'source_parent_id'=>60645,'source_rate_id'=>61158,
'selection_digest'=>hash('sha256','concurrent-check-digest'),
'policy_revision'=>1,
'composition'=>[
 'schema'=>1,'intent'=>'request',
 'status'=>'structure_and_component_evidence_only_not_checkout_authority',
 'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>60645,'rate_id'=>61158],
 'components'=>[['group'=>'stay','source_type'=>'properties','source_id'=>36474]],
 'price_state'=>'price_pending'
]];
$r=\Commerce\Composition\ClaimLedger::stage($intent);
echo json_encode([
 'result'=>is_wp_error($r)?$r->get_error_code():$r['status'],
 'session'=>WC()->session->get_customer_id(),
 'error'=>is_wp_error($r)
],JSON_UNESCAPED_SLASHES).PHP_EOL;
