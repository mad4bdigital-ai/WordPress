<?php
/** Read-only source sample schema acceptance. */
if(!class_exists('MAD4B_Tenant_Typed_Schema_V1',false))
 require_once '/tmp/mad4b-typed-schema-candidate.php';
$checks=[];
$a=MAD4B_Tenant_Typed_Schema_V1::create();
$checks['contract']=$a['contract']==='mad4b.tenant-typed-schema-bundle.v1';
$checks['source_bound']=$a['source_site']['site_uuid']
 ===MAD4B_SCP_Site_Profile::status()['site_uuid'];
$checks['jet_schema_count']=count($a['schemas']['jetengine'])===7;
$checks['taxonomy_schema_count']=count($a['schemas']['jet_taxonomies'])===3;
$checks['filter_count']=count($a['schemas']['jetsmartfilters'])===1;
$checks['fluent_form_count']=count($a['schemas']['fluent_forms'])===1;
$checks['elementor_count']=count($a['schemas']['elementor'])===1;
$checks['wpml_three']=count($a['schemas']['wpml_translation_lineage'])===3;
$checks['no_form_submissions']=$a['form_submissions_exported']===false;
$checks['no_credentials']=$a['credentials_exported']===false;
$checks['no_finance']=$a['financial_data_exported']===false;
$checks['no_options_values']=$a['raw_company_option_values_exported']===false;
$checks['no_activation']=$a['schema_activation_authorized']===false
 &&$a['company_detach_authorized']===false;
$checks['fixed_selection']=$a['selection']['jetengine']=== [15,26,223,273,310,362,363];
$checks['source_raw_sha']=true;
$checks['total_fields_bounded']=true;
$total=0;
foreach($a['schemas'] as $provider=>$rows){
 foreach($rows as $r) {
  foreach(array_merge($r['source_fields']??[], $r['postmeta']??[]) as $key=>$blob){
   $values=isset($blob['sha256'])?[$blob]:$blob;
   foreach($values as $item) {
    if(!is_array($item) || !isset($item['base64']))continue;
    $raw=base64_decode($item['base64'],true);
    if($raw===false || strlen($raw)!==$item['bytes']
      || hash('sha256',$raw)!==$item['sha256'])
     $checks['source_raw_sha']=false;
    $total++;
    if(strlen($raw)>160000)$checks['total_fields_bounded']=false;
   }
  }
 }
}
$checks['raw_fields_positive']=$total>40;
$checksum=$a['payload_sha256'];unset($a['payload_sha256']);
$checks['whole_manifest_hash']=hash('sha256',
 wp_json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))===$checksum;
$b=MAD4B_Tenant_Typed_Schema_V1::create();
$checks['reproducible']=$b['payload_sha256']===$checksum;
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
 'checks'=>$checks,'verified_source_fields'=>$total,
 'manifest_sha256'=>$checksum,
 'source_counts'=>array_map('count',$b['schemas'])],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
