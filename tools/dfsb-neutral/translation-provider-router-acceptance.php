<?php
if(!defined('ABSPATH')||!class_exists('MAD4B_Translation_Provider_Router'))throw new RuntimeException('router_missing');
final class MAD4B_Translation_Provider_Fixture implements MAD4B_Translation_Synchronous_Adapter {
 private $id,$priority,$connected,$consent,$idem;
 public function __construct($id,$priority,$connected,$consent,$idem=true) {
  $this->id=$id;$this->priority=$priority;
  $this->connected=$connected;$this->consent=$consent;$this->idem=$idem;
 }
 public function provider_id():string{return $this->id;}
 public function priority():int{return $this->priority;}
 public function preflight(array $job):array{return [
  'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
  'connected'=>$this->connected,'authorized'=>$this->consent,
  'synchronous'=>true,'budget_allowed'=>true,
  'idempotency_enforced'=>$this->idem,
  'language_supported'=>in_array($job['locale'],['fr','ar','de','es','it'],true)
 ];}
 public function translate(array $job):array {throw new RuntimeException('fake_network_forbidden');}
}
$id='00644c9582cebb93cbd25d747ddbd8bf4864de19c0918e3917e017af796cbeea';
$fixture1=static fn($x)=>[
 new MAD4B_Translation_Provider_Fixture('connected_google',20,true,true),
 new MAD4B_Translation_Provider_Fixture('disconnected_deepl',10,false,true),
 new MAD4B_Translation_Provider_Fixture('unbudgeted_openai',5,true,false)
];
add_filter('mad4b/translation/synchronous_adapters',$fixture1);
$first=MAD4B_Translation_Provider_Router::plan(['issue_id'=>$id]);
remove_filter('mad4b/translation/synchronous_adapters',$fixture1);
if(is_wp_error($first))throw new RuntimeException('test1_'.$first->get_error_code());
$expect=($first['routing']['selected_provider']??null)==='connected_google'
 && count($first['routing']['order'])===4
 && ($first['routing']['order'][0]['provider_id']??'')==='chatgpt_mcp_session';
$fixture2=static fn($x)=>[
 new MAD4B_Translation_Provider_Fixture('api_without_idempotency',1,true,true,false),
 new MAD4B_Translation_Provider_Fixture('api_unconsented',2,true,false)
];
add_filter('mad4b/translation/synchronous_adapters',$fixture2);
$second=MAD4B_Translation_Provider_Router::plan(['issue_id'=>$id]);
remove_filter('mad4b/translation/synchronous_adapters',$fixture2);
if(is_wp_error($second))throw new RuntimeException('test2_'.$second->get_error_code());
$expect=$expect && array_key_exists('selected_provider',$second['routing']) && $second['routing']['selected_provider']===null
 && ($second['routing']['selection_state']??'')==='HOLD_PROVIDER_UNAVAILABLE';
$fixture3=static fn($x)=>[
 new MAD4B_Translation_Provider_Fixture('duplicated_api',1,true,true),
 new MAD4B_Translation_Provider_Fixture('duplicated_api',1,true,true)
];
add_filter('mad4b/translation/synchronous_adapters',$fixture3);
$third=MAD4B_Translation_Provider_Router::plan(['issue_id'=>$id]);
remove_filter('mad4b/translation/synchronous_adapters',$fixture3);
if(is_wp_error($third))throw new RuntimeException('test3_'.$third->get_error_code());
$expect=$expect&&count($third['routing']['order'])===1;
$mcp=MAD4B_Translation_Provider_Router::choose(true,[
 ['provider_id'=>'connected_google','priority'=>10,'status'=>'ready']]);
$expect=$expect && $mcp['selected_provider']==='chatgpt_mcp_session'
 && $mcp['no_implicit_background_queue']===true;
$run=MAD4B_Translation_Provider_Router::run(['issue_id'=>$id]);
$expect=$expect && !is_wp_error($run) && $run['status']==='HOLD_PROVIDER_UNAVAILABLE'
 && $run['paid_api_called']===false;
echo wp_json_encode([
 'contract'=>'mad4b.translation-provider-routing-acceptance.v1',
 'status'=>$expect?'PASS':'FAIL','real_issue_id'=>$id,
 'mcp_primary'=>$mcp['selected_provider'],
 'api_fallback'=>$first['routing']['selected_provider'],
 'consent_or_idempotency_missing'=>$second['routing']['selection_state'],
 'duplicate_id_policy'=>$third['routing']['selection_state'],
 'no_provider_execution'=>$run['status'],'no_background_queue'=>true,
 'no_network'=>true,'no_wpml_mutation'=>true
 ],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(!$expect)throw new RuntimeException('routing_acceptance_failed');
