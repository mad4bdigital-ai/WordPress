<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL runtime-provider-circuit-breaker: '.$m.(null===$v?'':' '.wp_json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$provider='ci-provider';$site='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$gen=hash('sha256','ci.breaker.generation.a');$gen_b=hash('sha256','ci.breaker.generation.b');

$initial=MAD4B_SCP_Provider_Circuit_Breaker::status($provider,$site,$gen);
$check(!is_wp_error($initial)&&'closed'===$initial['state']&&!empty($initial['transport_eligible'])&&empty($initial['authority_granted']),'Virtual CLOSED state invalid.',$initial);
for($i=1;$i<=3;$i++){
 $a=MAD4B_SCP_Provider_Circuit_Breaker::begin_attempt($provider,$site,$gen);
 $check(!is_wp_error($a)&&!empty($a['transport_eligible']),'CLOSED attempt denied before threshold.',$a);
 $r=MAD4B_SCP_Provider_Circuit_Breaker::record_result($a,false,'timeout');
 $check(!is_wp_error($r),'Transport failure persistence failed.',$r);
}
$open=MAD4B_SCP_Provider_Circuit_Breaker::status($provider,$site,$gen);
$check('open'===$open['state']&&empty($open['transport_eligible'])&&3===$open['failure_count']&&!empty($open['event_chain_valid']),'Failure threshold did not OPEN durable breaker.',$open);
$denied=MAD4B_SCP_Provider_Circuit_Breaker::begin_attempt($provider,$site,$gen);
$check(is_wp_error($denied)&&'mad4b_provider_circuit_breaker_open'===$denied->get_error_code(),'OPEN breaker admitted provider transport.',$denied);

global $wpdb;$t=MAD4B_SCP_Schema::tables();
$key=$open['breaker_key_sha256'];
$wpdb->query($wpdb->prepare("UPDATE {$t['provider_breakers']} SET open_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE BINARY breaker_key_sha256=BINARY %s",$key));
$probe=MAD4B_SCP_Provider_Circuit_Breaker::begin_attempt($provider,$site,$gen);
$check(!is_wp_error($probe)&&'half_open'===$probe['state']&&!empty($probe['half_open_probe'])&&!empty($probe['probe_token'])&&1===$probe['max_read_attempts'],'Elapsed OPEN window did not issue exactly one HALF_OPEN probe.',$probe);
$second=MAD4B_SCP_Provider_Circuit_Breaker::begin_attempt($provider,$site,$gen);
$check(is_wp_error($second)&&'mad4b_provider_circuit_breaker_probe_in_flight'===$second->get_error_code(),'Concurrent HALF_OPEN probe was admitted.',$second);
$stale=$probe;$stale['probe_token']=hash('sha256',$probe['probe_token'].'tampered');
$stale_result=MAD4B_SCP_Provider_Circuit_Breaker::record_result($stale,true,'');
$check(is_wp_error($stale_result),'Tampered HALF_OPEN probe token changed breaker state.',$stale_result);
$inconclusive_probe=$probe;
$wpdb->query($wpdb->prepare("UPDATE {$t['provider_breakers']} SET probe_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 SECOND) WHERE BINARY breaker_key_sha256=BINARY %s",$key));
$inconclusive=MAD4B_SCP_Provider_Circuit_Breaker::record_result($inconclusive_probe,false,'authorization');
$check(!is_wp_error($inconclusive)&&'open'===$inconclusive['state'],'Non-transport HALF_OPEN result incorrectly restored transport eligibility.',$inconclusive);
$wpdb->query($wpdb->prepare("UPDATE {$t['provider_breakers']} SET open_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE BINARY breaker_key_sha256=BINARY %s",$key));
$mutation_probe=MAD4B_SCP_Provider_Circuit_Breaker::begin_attempt($provider,$site,$gen,false);
$check(is_wp_error($mutation_probe)&&'mad4b_provider_circuit_breaker_read_probe_required'===$mutation_probe->get_error_code(),'Mutation path was allowed to become HALF_OPEN transport probe.',$mutation_probe);
$probe=MAD4B_SCP_Provider_Circuit_Breaker::begin_attempt($provider,$site,$gen,true);
$check(!is_wp_error($probe)&&!empty($probe['half_open_probe']),'Read/health probe could not enter HALF_OPEN after inconclusive result.',$probe);
$closed=MAD4B_SCP_Provider_Circuit_Breaker::record_result($probe,true,'');
$check(!is_wp_error($closed)&&'closed'===$closed['state']&&!empty($closed['transport_eligible'])&&0===$closed['failure_count'],'Successful HALF_OPEN probe did not restore transport-only CLOSED state.',$closed);
$check(!empty($closed['probe_restores_transport_only'])&&empty($closed['certification_granted'])&&empty($closed['authority_granted'])&&empty($closed['approval_granted'])&&empty($closed['production_eligibility_granted']),'Probe success escalated non-transport authority.',$closed);

$other_generation=MAD4B_SCP_Provider_Circuit_Breaker::status($provider,$site,$gen_b);
$other_site=MAD4B_SCP_Provider_Circuit_Breaker::status($provider,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$gen);
$check('closed'===$other_generation['state']&&'closed'===$other_site['state'],'Breaker state leaked across certification generation or site.',$other_generation);

echo "mad4b.provider-circuit-breaker.runtime.v1: PASS\n";
