<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$run_id=isset($args[0])?sanitize_key((string)$args[0]):'';
if(''===$run_id){fwrite(STDERR,"FAIL runtime-network-operation-setup: missing run id\n");exit(2);}
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL runtime-network-operation-setup: '.$m.(null===$v?'':' '.wp_json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$d=static function($label)use($run_id){return hash('sha256','mad4b.network-ci.v1|'.$run_id.'|'.$label);};
$target=static function($blog,$site_uuid,$name,$approval)use($d){
 return array(
  'target_blog_id'=>$blog,'target_site_uuid'=>$site_uuid,'origin_sha256'=>$d($name.':origin'),
  'authority_scope_sha256'=>$d($name.':authority'),'catalog_sha256'=>$d($name.':catalog'),
  'plan_sha256'=>$d($name.':plan'),'preparation_sha256'=>$d($name.':preparation'),
  'approval_ticket_id'=>$approval,'context_sha256'=>$d($name.':context'),
  'credential_binding_sha256'=>$d($name.':credential'),'receipt_binding_sha256'=>$d($name.':receipt-binding')
 );
};
$input=array(
 'origin_site_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','origin_blog_id'=>1,
 'authority_scope_sha256'=>$d('origin:authority'),'plan_sha256'=>$d('origin:plan'),
 'preparation_sha256'=>$d('origin:preparation'),'idempotency_key'=>$d('network:idempotency')
);
$targets=array(
 $target(101,'11111111-1111-4111-8111-111111111111','site-a','11111111-aaaa-4aaa-8aaa-111111111111'),
 $target(202,'22222222-2222-4222-8222-222222222222','site-b','22222222-bbbb-4bbb-8bbb-222222222222'),
 $target(303,'33333333-3333-4333-8333-333333333333','site-c','33333333-cccc-4ccc-8ccc-333333333333')
);
$operation_id=wp_generate_uuid4();
$created=MAD4B_SCP_Network_Operation_Journal::create($operation_id,$input,$targets);
$check(!is_wp_error($created)&&$operation_id===$created['network_operation_id']&&!empty($created['event_chain_valid']),'NetworkOperation creation failed.',$created);
$duplicate=MAD4B_SCP_Network_Operation_Journal::create(wp_generate_uuid4(),$input,$targets);
$check(!is_wp_error($duplicate)&&!empty($duplicate['deduplicated'])&&$operation_id===$duplicate['network_operation_id'],'Network idempotency did not deduplicate exact repeated plan.',$duplicate);
$conflict_input=$input;$conflict_input['plan_sha256']=$d('origin:conflicting-plan');
$conflict=MAD4B_SCP_Network_Operation_Journal::create(wp_generate_uuid4(),$conflict_input,$targets);
$check(is_wp_error($conflict),'Network idempotency accepted conflicting plan.',$conflict);
echo wp_json_encode(array('contract'=>'mad4b.network-operation-setup.v1','run_id'=>$run_id,'network_operation_id'=>$operation_id)).PHP_EOL;
