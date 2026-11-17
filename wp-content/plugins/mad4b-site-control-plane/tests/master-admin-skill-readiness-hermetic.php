<?php
define('ABSPATH','/fixture/');
require __DIR__.'/../includes/class-mad4b-scp-skill-runtime-certification.php';
$names=array('site_profile_enrolled','skills_feature_enabled','storage_initialized',
'storage_writable','seed_pack_ready','provider_reconciliation_ready','provider_mutation_absent','provider_skill_delete_absent','base_skills_enabled','read_abilities_registered','skill_write_abilities_absent','snapshot_has_enabled_skills','snapshot_identity_ready','snapshot_identity_skill_count_matches','build_provenance_identity_ready','profile_app_mapping_bound','editor_enabled','scripts_disabled');
$c=array_fill_keys($names,true);$c['profile_app_mapping_bound']=false;$c['editor_enabled']=false;
$n=0;function yes($v,$name){global $n;if(!$v){fwrite(STDERR,"FAIL: $name\n");exit(1);}echo "PASS: $name\n";++$n;}
$x=MAD4B_SCP_Skill_Runtime_Certification::capability_readiness($c);
yes($x['catalog_read_ready']===true,'production catalog read independent of editor');
yes($x['external_app_projection_ready']===false,'missing real App mapping blocks MCP projection');
yes($x['authoring_ready']===false,'editor remains disabled');
yes($x['editor_disabled_by_policy']===true,'disabled editor classified correctly');
yes($x['external_app_blockers']===['profile_app_mapping_mismatch'],'exact missing binding');
yes($x['execution_authority_granted']===false,'no skill execution grant');
$c['profile_app_mapping_bound']=true;
$x=MAD4B_SCP_Skill_Runtime_Certification::capability_readiness($c);
yes($x['external_app_projection_ready']===true&&!$x['authoring_ready'],'app binding alone never enables editor');
$c['editor_enabled']=true;
yes(MAD4B_SCP_Skill_Runtime_Certification::capability_readiness($c)['authoring_ready']===true,'explicit authoring gate visible');
$c['seed_pack_ready']=false;
$x=MAD4B_SCP_Skill_Runtime_Certification::capability_readiness($c);
yes(!$x['catalog_read_ready']&&!$x['external_app_projection_ready'],'actual seed defect blocks read');
yes(in_array('seed_pack_ready',$x['catalog_blockers'],true),'actual blocker explained');
echo "PASS $n source readiness assertions\n";
