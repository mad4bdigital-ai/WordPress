<?php
// One-time source-verified enrollment, not a new layout certificate.
if(!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='production'
 || (string)wp_parse_url(home_url('/'),PHP_URL_HOST)!=='wayfatours.com'
 || !class_exists('MAD4B_Native_Layout_Epoch_Continuity_V1')
 || (int)get_option('mad4b_css_ai_epoch_v1',0)!==261
 || (int)get_option('elementor_active_kit',0)!==60050)
 throw new RuntimeException('WRONG_ENROLLMENT_TARGET');
$key=MAD4B_Native_Layout_Epoch_Continuity_V1::BASELINE;
if(get_option($key,false)!==false)throw new RuntimeException('ALREADY_ENROLLED');
$proof=get_option(MAD4B_Native_Layout_Epoch_Continuity_V1::PROOF,[]);
if(!is_array($proof) || ($proof['contract']??'')!=='mad4b.native-listing.layout-proof.v1'
 || ($proof['site_host']??'')!=='wayfatours.com' || (int)($proof['css_epoch']??-1)!==261
 || (int)($proof['mobile_breakpoint']??0)!==767 || count($proof['entries']??[])!==4)
 throw new RuntimeException('ORIGINAL_BROWSER_PROOF_NOT_CURRENT');
$expected=['45938|en|mobile','45938|es|mobile','45938|en|wide','45938|es|wide'];
foreach($expected as $name) {
 $e=$proof['entries'][$name]??[];
 if(($e['status']??'')!=='pass'
  || (int)($e['listing_id']??0)!==45938
  || ($e['listing_source_sha256']??'')!=='b67f5ca6a165df9690b246597510cde3d8b01ad98e2a186bf6835cd47c2dc499'
  || ($e['listing_css_sha256']??'')!=='a3820d5ea3438ebcffb8f1c251fedd74c87ab38beb9c7c6c8c8c61f0bcc52fd2'
  || count($e['sampled_widths']??[])<4)throw new RuntimeException('UNTRUSTED_ORIGINAL_VIEWPORT_PROOF_'.$name);
}
$rel=[
'litespeed/css/139a38779f0cbd54702bb6a280a2ed31.css',
'litespeed/css/652d44a9253714001bed6bd67bcee1ae.css',
'mu-plugins/396-wayfa-first-paint-controls.php',
'mu-plugins/421-mad4b-public-critical-css-coverage.php',
'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php',
'mu-plugins/assets/header-context-controls.css',
'mu-plugins/mad4b-secondary-listing-lazy.php',
'themes/astra-child/style.css',
'themes/astra/style.css',
'uploads/elementor/css/post-25927.css',
'uploads/elementor/css/post-25928.css',
'uploads/elementor/css/post-26001.css',
'uploads/elementor/css/post-26010.css',
'uploads/elementor/css/post-33747.css',
'uploads/elementor/css/post-34644.css',
'uploads/elementor/css/post-35072.css',
'uploads/elementor/css/post-35138.css',
'uploads/elementor/css/post-43775.css',
'uploads/elementor/css/post-45938.css',
'uploads/elementor/css/post-60050.css',
];
$assets=[];
foreach($rel as $path) {
 $full=WP_CONTENT_DIR.'/'.$path;
 if(is_link($full)||!is_file($full)||filesize($full)<100||filesize($full)>8388608)
  throw new RuntimeException('CSS_SOURCE_MISSING_'.$path);
 $assets[$path]=hash_file('sha256',$full);
}
$docs=[25917,35110,25927,26001,25928,26010,35138,43775,45938,33747,34644,35072];
$documents=[];
foreach($docs as $id) {
 $raw=(string)get_post_meta($id,'_elementor_data',true);
 if(strlen($raw)<200)throw new RuntimeException('INVALID_DOCUMENT_'.$id);
 $documents[$id]=hash('sha256',$raw);
}
$kit_settings=get_post_meta(60050,'_elementor_page_settings',true);
$manifest=[
 'contract'=>MAD4B_Native_Layout_Epoch_Continuity_V1::CONTRACT,
 'site_host'=>'wayfatours.com',
 'enrolled_css_epoch'=>261,
 'registry_sha256'=>MAD4B_Native_Layout_Epoch_Continuity_V1::proof_digest($proof),
 'mobile_breakpoint'=>767,
 'kit_id'=>60050,
 'kit_settings_sha256'=>hash('sha256',maybe_serialize($kit_settings)),
 'theme_stylesheet'=>(string)get_stylesheet(),
 'theme_template'=>(string)get_template(),
 'theme_mods_sha256'=>hash('sha256',maybe_serialize(get_theme_mods())),
 'astra_options_sha256'=>hash('sha256',maybe_serialize(get_option('astra-settings',[]))),
 'wp_custom_css_sha256'=>hash('sha256',(string)wp_get_custom_css()),
 'elementor_docs'=>$documents,
 'css_dependencies'=>$assets,
 'evidence_contract'=>'2026-11-23 original 16-size EN/ES browser layout QA plus full signed AJAX readback',
];
if(!MAD4B_Native_Layout_Epoch_Continuity_V1::matches($proof,$manifest,262))
 throw new RuntimeException('DRY_RUN_EPOCH_262_MUST_RENEW_IDENTICAL');
$changed=$manifest;
$changed['css_dependencies']['uploads/elementor/css/post-45938.css']=str_repeat('0',64);
if(MAD4B_Native_Layout_Epoch_Continuity_V1::matches($proof,$changed,262))
 throw new RuntimeException('BAD_CSS_MUTATION_ACCEPTED');
$changed=$manifest;
$changed['elementor_docs'][25927]=str_repeat('0',64);
if(MAD4B_Native_Layout_Epoch_Continuity_V1::matches($proof,$changed,262))
 throw new RuntimeException('BAD_ELEMENTOR_MUTATION_ACCEPTED');
$changed=$manifest;
$changed['site_host']='evil.example';
if(MAD4B_Native_Layout_Epoch_Continuity_V1::matches($proof,$changed,262))
 throw new RuntimeException('BAD_TENANT_MUTATION_ACCEPTED');
if(!add_option($key,$manifest,'',false))throw new RuntimeException('ENROLLMENT_CREATE_CAS_FAILED');
$readback=get_option($key,[]);
if(serialize($readback)!==serialize($manifest))throw new RuntimeException('MANIFEST_READBACK_FAILED');
echo 'MAD4B_SCOPED_LAYOUT_ENROLLED '.wp_json_encode([
 'site'=>$manifest['site_host'],'css_epoch'=>$manifest['enrolled_css_epoch'],
 'assets'=>count($manifest['css_dependencies']),'documents'=>count($manifest['elementor_docs']),
 'positive_simulated_next_epoch'=>true,'css_drift_rejected'=>true,
 'document_drift_rejected'=>true,'cross_tenant_rejected'=>true,
 'manifest_sha256'=>hash('sha256',serialize($manifest)),
])."\n";
