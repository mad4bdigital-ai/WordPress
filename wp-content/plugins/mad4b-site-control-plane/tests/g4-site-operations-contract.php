<?php
require __DIR__ . '/g4-domain-fixture.php';
$hash=str_repeat('a',64);
$backup=array('action'=>'restore_readiness','package_sha256'=>$hash,'relative_path'=>'backups/site.zip','restore_epoch'=>2);
$facts=array('package_sha256'=>$hash,'restore_epoch'=>2,'protected_receipts_outside_backup'=>true,'allowed_artifact_paths'=>array('backups/site.zip'));
$out=g4_valid('operations_backup',$backup,$facts);g4_assert(false===$out['execution_supported'] && $out['restore_never_replays_authority'],'Restore readiness became restore authority.');
$bad=$backup;$bad['restore_epoch']=1;g4_deny('operations_backup',$bad,$facts,'backup_package_or_epoch');
$bad=$facts;$bad['protected_receipts_outside_backup']=false;g4_deny('operations_backup',$backup,$bad,'backup_package_or_epoch');
$bad=$backup;$bad['package_sha256']=str_repeat('b',64);g4_deny('operations_backup',$bad,$facts,'backup_package_or_epoch');
foreach(array('../installer.php','/tmp/archive.zip','backups/../installer.php','backups/%2e%2e/archive.zip','https://outside.invalid/archive.zip','backups\\archive.zip') as $path){$bad=$backup;$bad['relative_path']=$path;g4_deny('operations_backup',$bad,$facts,'backup_artifact_path');}
$bad=$backup;$bad['installer_password']='unadmitted';g4_deny('operations_backup',$bad,$facts,'fields_invalid');
$cache=array('targets'=>array('/one'),'whole_site'=>false);
$cache_facts=array('frontend_readback_contract'=>true,'allowed_cache_targets'=>array('/one','/two'));
g4_valid('operations_cache',$cache,$cache_facts);
$bad=$cache;$bad['whole_site']=true;g4_deny('operations_cache',$bad,$cache_facts,'cache_blast_radius');
$bad=$cache;$bad['targets']=array('/');g4_deny('operations_cache',$bad,$cache_facts,'cache_target');
$bad=$cache;$bad['targets']=array('/one','/one');g4_deny('operations_cache',$bad,$cache_facts,'cache_target');
$bad=$cache;$bad['targets']=array('/foreign');g4_deny('operations_cache',$bad,$cache_facts,'cache_target');
$bad=$cache_facts;$bad['frontend_readback_contract']=false;g4_deny('operations_cache',$cache,$bad,'cache_blast_radius');
$redirect=array('rules'=>array(array('source'=>'/old','target'=>'/new','status'=>301)));
$redirect_facts=array('redirect_inventory_complete'=>true,'language_scope_authorized'=>true,'existing_redirects'=>array('/new'=>'/final'),'allowed_redirect_sources'=>array('/old','/new'));
g4_valid('operations_redirect',$redirect,$redirect_facts);
$bad=$redirect_facts;$bad['existing_redirects']['/new']='/old';g4_deny('operations_redirect',$redirect,$bad,'redirect_loop_or_chain');
foreach(array('//outside.invalid/path','https://outside.invalid/path','/new?url=outside','/new#fragment','/%252foutside','/a/../new') as $target){$bad=$redirect;$bad['rules'][0]['target']=$target;g4_deny('operations_redirect',$bad,$redirect_facts,'redirect_path_status_or_grant');}
$bad=$redirect;$bad['rules'][0]['status']='301';g4_deny('operations_redirect',$bad,$redirect_facts,'redirect_path_status_or_grant');
$bad=$redirect_facts;$bad['redirect_inventory_complete']=false;g4_deny('operations_redirect',$redirect,$bad,'redirect_scope_or_inventory');
$bad=$redirect_facts;$bad['language_scope_authorized']=false;g4_deny('operations_redirect',$redirect,$bad,'redirect_scope_or_inventory');
$security=array('action'=>'ban_review','subject_sha256'=>$hash,'masked'=>true);
$security_facts=array('object_access'=>true,'log_redaction_verified'=>true,'local_provider_scope'=>true,'current_actor_sha256'=>str_repeat('b',64));
$out=g4_valid('operations_security',$security,$security_facts);g4_assert(false===$out['execution_supported'],'Ban review dispatched a ban.');
$bad=$security_facts;$bad['current_actor_sha256']=$hash;g4_deny('operations_security',$security,$bad,'security_self_lockout');
$bad=$security_facts;unset($bad['current_actor_sha256']);g4_deny('operations_security',$security,$bad,'security_self_lockout');
$bad=$security_facts;$bad['local_provider_scope']=false;g4_deny('operations_security',$security,$bad,'security_scope_or_privacy');
$bad=$security;$bad['masked']=false;g4_deny('operations_security',$bad,$security_facts,'security_scope_or_privacy');
g4_done('site-operations');
