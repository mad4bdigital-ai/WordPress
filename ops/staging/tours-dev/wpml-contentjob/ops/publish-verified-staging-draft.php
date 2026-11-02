<?php
/** Governed, single-draft WordPress Staging-only publication with rollback and WPML readback. */
defined('ABSPATH')||exit(1);
function staging_fail($s){WP_CLI::error('wpml_staging_publication_'.$s);}
$id=(int)getenv('MAD4B_TARGET_ID');
$apply=getenv('MAD4B_APPLY')==='yes';
if(!$id||wp_get_environment_type()!=='staging'
 ||rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com'
 ||get_current_user_id()!==26||!current_user_can('publish_pages')
 ||!current_user_can('manage_options')||true!==MAD4B_SCP_Policy::can_mutate())
 staging_fail('authorization');
$site=MAD4B_SCP_Operational_Integrity::capture();
if(is_wp_error($site)||($site['scope']['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')
 staging_fail('site_identity');
$dashboard=MAD4B_WPML_ContentJob_Adapter::review_dashboard(100);
if(is_wp_error($dashboard))staging_fail('qa_unavailable');
$matched=array_values(array_filter($dashboard['rows'],static fn($r)=>
 (int)($r['target_post_id']??0)===$id &&($r['target_post_status']??'')==='draft'
 &&($r['reference_qa']['verified']??false)===true
 &&($r['native_state']??'')==='WAITING_REVIEW'));
if(count($matched)!==1)staging_fail('not_verified_review_draft');
$row=$matched[0];$source=(int)$row['source_post_id'];$locale=(string)$row['target_language'];
$expected_by_source=[
 41731=>['fr'=>['Appliquer le filtre','Rechercher'],'de'=>['Filter anwenden','Suchen'],
  'es'=>['Aplicar filtro','Buscar'],'it'=>['Applica filtro','Cerca']],
 41738=>['fr'=>['Appliquer le filtre','Rechercher'],'de'=>['Filter anwenden','Suchen'],
  'es'=>['Aplicar filtro','Buscar'],'it'=>['Applica filtro','Cerca']],
 54880=>['fr'=>['Excursions à la journée','Découvrir'],'de'=>['Tagesausflüge','Entdecken'],
  'ar'=>['رحلات يومية','استكشف']],
 43704=>['de'=>['Reiseerlebnisse','Mehr lesen']]
];
$markers=$expected_by_source[$source][$locale]??null;
if(!$markers||get_post_type($source)!=='elementor_library'
 ||get_post_status($source)!=='publish'||get_post_status($id)!=='draft')
 staging_fail('scope_or_source');
$trid=(int)apply_filters('wpml_element_trid',null,$source,'post_elementor_library');
$translated_trid=(int)apply_filters('wpml_element_trid',null,$id,'post_elementor_library');
$lang_detail=(array)apply_filters('wpml_post_language_details',null,$id);
if(!$trid||$trid!==$translated_trid||($lang_detail['language_code']??'')!==$locale)
 staging_fail('wpml_trid_mismatch');
$raw=(string)get_post_meta($id,'_elementor_data',true);
$tree=json_decode($raw,true);
if(!is_array($tree)||strlen($raw)<250)staging_fail('bad_elementor_data');
$serialized=wp_json_encode($tree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
foreach($markers as $text)if(!str_contains($serialized,$text))staging_fail('missing_translation_marker');
do_action('wpml_switch_language',$locale);
ob_start();
$html=(string)\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id,false);
$h=ob_get_clean().$html;
$real_lang=(string)apply_filters('wpml_current_language',null);
do_action('wpml_switch_language',null);
if($real_lang!==$locale||strlen($h)<7000||!str_contains($h,'elementor-'.$id)
 ||str_contains($h,'Fatal error')||str_contains($h,'Uncaught Error')
 ||str_contains($h,'Warning:'))staging_fail('render_qa_failed');
$binding=MAD4B_WPML_ContentJob_Adapter::binding(['job_id'=>$row['job_id']]);
if(is_wp_error($binding)||($binding['native_job_state']??'')!=='WAITING_REVIEW'
 ||($binding['master_or_post_excluded']??true))staging_fail('job_excluded');
$prepared=['contract'=>'mad4b.wpml.staging-publish-plan.v1','target'=>$id,'source'=>$source,
 'lang'=>$locale,'trid'=>$trid,'elementor_sha256'=>hash('sha256',$raw),
 'render_sha256'=>hash('sha256',$h),'render_html_bytes'=>strlen($h),
 'markers'=>$markers,'job'=>$row['job_id'],'post_status'=>'draft',
 'review_ref'=>'MAD4B_STAGE_QA_20261102_'.$id,'mode'=>$apply?'commit':'dry-run'];
if(!$apply){echo wp_json_encode($prepared,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";return;}
global $wpdb;
$lock='mad4b_wpml_stage_publish_20261102';
if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!=='1')staging_fail('busy');
try{
 if(get_post_status($id)!=='draft'
  ||hash('sha256',(string)get_post_meta($id,'_elementor_data',true))!==hash('sha256',$raw))
  staging_fail('drift');
 $rollback_key='mad4b_wpml_staging_published_rollback_'.$id.'_20261102';
 $rollback=['target'=>$id,'previous_post_status'=>'draft','source'=>$source,'locale'=>$locale,
  'elementor_sha256'=>hash('sha256',$raw),'at'=>gmdate('c')];
 if(!add_option($rollback_key,$rollback,false) && get_option($rollback_key)!==$rollback)
  staging_fail('rollback_not_saved');
 $post=wp_update_post(['ID'=>$id,'post_status'=>'publish'],true);
 if(is_wp_error($post)||$post!=$id)staging_fail('wp_publish_failure');
 clean_post_cache($id);
 $lang2=(array)apply_filters('wpml_post_language_details',null,$id);
 $trid2=(int)apply_filters('wpml_element_trid',null,$id,'post_elementor_library');
 if(get_post_status($id)!=='publish'||$trid2!==$trid
  ||($lang2['language_code']??'')!==$locale
  ||hash('sha256',(string)get_post_meta($id,'_elementor_data',true))!==hash('sha256',$raw))
  staging_fail('wpml_postpublish_readback_failed');
 $proof=$prepared;
 $proof['contract']='mad4b.wpml.staging-publication-receipt.v1';
 $proof['status']='published';$proof['wpml_readback_passed']=true;
 $proof['source_and_translation_unchanged']=true;
 $proof['receipt_id']='mad4b.wpml.stage.'.substr(hash('sha256',
  $site['scope']['site_uuid'].'|'.$source.'|'.$id.'|'.$locale.'|'.hash('sha256',$raw)),0,32);
 $proof['published_at']=gmdate('c');
 $proof_key='mad4b_wpml_staging_publication_receipt_'.$id.'_v1';
 update_option($proof_key,$proof,false);
 if(get_option($proof_key,[])!==$proof)staging_fail('receipt_not_read_back');
 echo wp_json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
