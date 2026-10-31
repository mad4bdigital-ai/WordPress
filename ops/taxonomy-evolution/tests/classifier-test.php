<?php
declare(strict_types=1);
require dirname(__DIR__).'/TaxonomyEvolutionGovernance.php';
function yes(bool $result,string $case):void{if(!$result)throw new RuntimeException($case);}
function denied(callable $fn):void{try{$fn();}catch(DomainException $e){return;}throw new RuntimeException('fail_closed_expected');}
for($i=1;$i<=4;$i++){
 $site=['company_id'=>'company-'.(string)$i,'site_uuid'=>'test-site-'.(string)$i,'origin'=>'https://site'.$i.'.example.test','environment'=>'staging'];
 $e=['from'=>'location_jet','to'=>'destinations','version'=>1,'source_digest'=>str_repeat('a',64)];
 $old=($i===1||$i===3);$new=($i===2||$i===3);
 $obs=['company_id'=>$site['company_id'],'site_uuid'=>$site['site_uuid'],'origin'=>$site['origin'],'environment'=>'staging',
 'complete'=>true,'provider'=>'enrolled_native_readback','inventory_revision'=>'snapshot-r1','registered_taxonomies'=>['location_jet'=>$old,'destinations'=>$new],
 'counts'=>['definitions'=>1,'terms'=>$old?438:0,'translation_rows'=>$old?434:0,'relationships'=>$old?2568:0,'unbound_wpml_terms'=>$old?4:0],
 'references'=>['filters'=>['count'=>12,'adapter'=>'certified'],'templates'=>['count'=>31,'adapter'=>'missing']],
 'languages'=>['active'=>['en','ar'],'hidden'=>['ar']],
 'preserve_rewrite_verified'=>true,'canonical_source_observed'=>false,'verified_restore_checkpoint'=>false,'native_executor_ready'=>false,'exact_runtime_provider_certified'=>false];
 $p=MAD4B_TaxonomyEvolutionGovernance::plan($site,$e,$obs);
 MAD4B_TaxonomyEvolutionGovernance::verify($p);
 $expected=[1=>'MIGRATE_LEGACY',2=>'ADOPT_CANONICAL',3=>'REVIEW_DUAL',4=>'PROVISION_SCHEMA'];
 yes($p['action']===$expected[$i],'exact_action_for_site_'.$i);
 yes($p['state']===[1=>'LEGACY_ONLY',2=>'CANONICAL_ONLY',3=>'DUAL_REGISTRATION',4=>'NONE_REGISTERED'][$i],'exact_state_for_site_'.$i);
 yes($p['authorizing']===false && $p['write_performed']===false,'non-authorizing');
 yes($p['wpml_hidden_languages_preserved']===['ar'],'hidden');
 yes(in_array('unresolved_provider_dependencies',$p['blockers'],true),'adapter_block');
 yes($p['unsupported_reference_counts']['templates']===31,'references');
 $receipt=['plan_sha256'=>$p['plan_sha256'],'company_id'=>$site['company_id'],'site_uuid'=>$site['site_uuid'],'origin'=>$site['origin'],'inventory_revision'=>'snapshot-r1','database_mode'=>'connection_local_temporary_tables','forward_counts_verified'=>true,'rollback_hashes_verified'=>true,'persistent_writes'=>false];
 MAD4B_TaxonomyEvolutionGovernance::verifyShadow($p,$receipt);
 $bad=$receipt;$bad['company_id']='other';denied(fn()=>MAD4B_TaxonomyEvolutionGovernance::verifyShadow($p,$bad));
 $bad=$receipt;$bad['inventory_revision']='stale';denied(fn()=>MAD4B_TaxonomyEvolutionGovernance::verifyShadow($p,$bad));
 $bad=$obs;$bad['company_id']='foreign-company';denied(fn()=>MAD4B_TaxonomyEvolutionGovernance::plan($site,$e,$bad));
 $bad=$receipt;$bad['site_uuid']='other';denied(fn()=>MAD4B_TaxonomyEvolutionGovernance::verifyShadow($p,$bad));
 $bad=$p;$bad['action']='forged';denied(fn()=>MAD4B_TaxonomyEvolutionGovernance::verify($bad));
 $bad=$obs;$bad['complete']=false;denied(fn()=>MAD4B_TaxonomyEvolutionGovernance::plan($site,$e,$bad));
 $bad=$obs;$bad['languages']['hidden']=['ja'];denied(fn()=>MAD4B_TaxonomyEvolutionGovernance::plan($site,$e,$bad));
 $bad=$obs;$bad['inventory_revision']='';denied(fn()=>MAD4B_TaxonomyEvolutionGovernance::plan($site,$e,$bad));
}
echo "PASS four-site taxonomy governance classification and evidence fences",PHP_EOL;
