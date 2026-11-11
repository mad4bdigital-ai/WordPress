<?php
if(!class_exists('MAD4B_Tenant_Typed_Export_V1',false))
 require_once '/tmp/mad4b-typed-export.php';
$checks=[];$sample=[54924,36260,37553];
$bundle=MAD4B_Tenant_Typed_Export_V1::create($sample);
$checks['source_contract']=$bundle['contract']==='mad4b.tenant-typed-content-bundle.v1';
$checks['source_site_verified']=$bundle['source_site']['site_uuid']===MAD4B_SCP_Site_Profile::status()['site_uuid'];
$checks['source_post_types']=array_column($bundle['posts'],'source_type')===['tours-and-activities','properties','units'];
$checks['source_posts_publish_never']=count($bundle['posts'])===3
 &&count(array_filter($bundle['posts'],static fn($p)=>$p['status']==='import_as_private_draft_only'))===3;
$checks['media_three']=count($bundle['media'])===3;
$checks['media_file_hashes']=true;
$root=wp_get_upload_dir()['basedir'];
foreach($bundle['media'] as $m) {
 $path=$root.'/'.$m['relative_upload_path'];
 if(!is_file($path)||filesize($path)!==$m['bytes']||hash_file('sha256',$path)!==$m['sha256'])
  $checks['media_file_hashes']=false;
}
$termIds=array_column($bundle['terms'],'source_term_id');
$checks['term_parents_complete']=count($bundle['terms'])>=50;
foreach($bundle['terms'] as $t)
 if($t['parent_source_term_id']&&!in_array($t['parent_source_term_id'],$termIds,true))
  $checks['term_parents_complete']=false;
$ids=array_column($bundle['posts'],'global_id');
$checks['relation_remappable']=count($bundle['relations'])===1
 &&in_array($bundle['relations'][0]['parent_post_global_id'],$ids,true)
 &&in_array($bundle['relations'][0]['child_post_global_id'],$ids,true);
$checks['wpml_language_lineage']=count(array_filter($bundle['posts'],static fn($p)=>$p['language']['code']==='en'))===3;
$checks['profiles_not_in_source_export']=!array_key_exists('users',$bundle)
 &&!array_key_exists('site_secrets',$bundle);
$checks['no_prices']=$bundle['financial_data_exported']===false
 &&$bundle['customer_data_exported']===false;
$checks['not_authorized']=$bundle['publication_authorized']===false
 &&$bundle['company_detach_authorized']===false;
$repeat=MAD4B_Tenant_Typed_Export_V1::create($sample);
$checks['stable_payload_hash']=$bundle['payload_sha256']===$repeat['payload_sha256'];
$checks['duplicate_id_rejected']=false;
try {MAD4B_Tenant_Typed_Export_V1::create([54924,54924]);}
catch(RuntimeException $e){$checks['duplicate_id_rejected']=str_contains($e->getMessage(),'POST_ID_INVALID');}
$checks['unpublished_rejected']=false;
$draft=get_posts(['post_type'=>'tours-and-activities','post_status'=>'draft',
  'posts_per_page'=>1,'fields'=>'ids']);
if($draft) {
 try {MAD4B_Tenant_Typed_Export_V1::create([(int)$draft[0]]);}
 catch(RuntimeException $e){$checks['unpublished_rejected']=str_contains($e->getMessage(),'POST_NOT_APPROVED');}
}
$checks['sample_limit_enforced']=false;
try {MAD4B_Tenant_Typed_Export_V1::create(range(1,13));}
catch(RuntimeException $e){$checks['sample_limit_enforced']=str_contains($e->getMessage(),'ITEM_LIMIT');}
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
 'checks'=>$checks,'posts'=>count($bundle['posts']),
 'terms'=>count($bundle['terms']),'media'=>count($bundle['media']),
 'relations'=>count($bundle['relations']),
 'payload_sha256'=>$bundle['payload_sha256']],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
