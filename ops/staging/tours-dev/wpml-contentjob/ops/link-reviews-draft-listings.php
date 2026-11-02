<?php
/** Scoped, idempotent review listing reference correction on MAD4B Staging. */
defined('ABSPATH')||exit(1);
function qa_error($code){WP_CLI::error('review_listing_'.$code);}
$src=43721;$listing_source=43727;
$lang=(string)getenv('MAD4B_LOCALE');$apply=getenv('MAD4B_APPLY')==='yes';
if(!in_array($lang,['ar','fr','de','es','it'],true)
 ||wp_get_environment_type()!=='staging'
 ||rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com'
 ||get_current_user_id()!==26||!current_user_can('manage_options')
 ||!MAD4B_SCP_Policy::can_mutate())qa_error('unauthorized');
$scope=MAD4B_SCP_Operational_Integrity::capture();
if(is_wp_error($scope)||($scope['scope']['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')
 qa_error('wrong_site');
$target=(int)apply_filters('wpml_object_id',$src,'elementor_library',false,$lang);
$listing=(int)apply_filters('wpml_object_id',$listing_source,'jet-engine',false,$lang);
if(!$target||!$listing||get_post_status($target)!=='draft'||get_post_status($listing)!=='draft'
 ||get_post_type($target)!=='elementor_library'||get_post_type($listing)!=='jet-engine')
 qa_error('source_or_target_status_changed');
foreach([[$src,$target,'elementor_library'],[$listing_source,$listing,'jet-engine']] as $pair){
 $one=(int)apply_filters('wpml_element_trid',null,$pair[0],'post_'.$pair[2]);
 $two=(int)apply_filters('wpml_element_trid',null,$pair[1],'post_'.$pair[2]);
 $details=(array)apply_filters('wpml_post_language_details',null,$pair[1]);
 if(!$one||$one!==$two||($details['language_code']??'')!==$lang)qa_error('trid_or_language');
}
$data=(string)get_post_meta($target,'_elementor_data',true);
$base=(string)get_post_meta($src,'_elementor_data',true);
$tree=json_decode($data,true);$source_tree=json_decode($base,true);
if(!is_array($tree)||!is_array($source_tree))qa_error('invalid_tree');
$find=function($nodes)use(&$find){
 $out=[];foreach((array)$nodes as $w){if(!is_array($w))continue;
 if(isset($w['settings']['lisitng_id']))$out[]=['widget'=>(string)($w['id']??''),'value'=>$w['settings']['lisitng_id']];
 foreach($find($w['elements']??[]) as $child)$out[]=$child;
 }return $out;
};
$sr=$find($source_tree);$existing=$find($tree);
if(count($sr)!==1||count($existing)!==1||$sr[0]['widget']!==$existing[0]['widget']
 ||(int)$sr[0]['value']!==$listing_source||!in_array((int)$existing[0]['value'],[$listing_source,$listing],true))
 qa_error('reference_drift');
$jobs=(array)get_option('mad4b_wpml_content_job_map_v1',[]);
$job_id='';
foreach($jobs as $row)if((int)($row['source_post_id']??0)===$src&&($row['lang']??'')===$lang)
 {$job_id=(string)($row['job_id']??'');break;}
if(!$job_id)qa_error('job_missing');
$job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
if(is_wp_error($job)||($job['job']['state']??'')!=='WAITING_REVIEW')qa_error('job_not_in_review');
if(!$apply){
 echo wp_json_encode(['contract'=>'mad4b.wpml.reviews-listing-link-plan.v1',
  'lang'=>$lang,'target'=>$target,'existing'=>$existing[0]['value'],'new_listing_draft_id'=>$listing,
  'target_sha256'=>hash('sha256',$data),'native_job_id'=>$job_id,'published'=>false],JSON_UNESCAPED_SLASHES)."\n";return;
}
global $wpdb;$lock='mad4b_wpml_reviews_listing_ref';
if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))!=='1')qa_error('locked');
try{
 $now=(string)get_post_meta($target,'_elementor_data',true);
 if(!hash_equals(hash('sha256',$data),hash('sha256',$now)))qa_error('target_changed');
 $backup='mad4b_wpml_reviews_ref_backup_'.$target.'_'.substr(hash('sha256',$data),0,14);
 if((int)$existing[0]['value']!==$listing){
  if(get_option($backup,false)===false)
   add_option($backup,['source'=>$src,'target'=>$target,'previous_data'=>$data,
    'timestamp'=>gmdate('c')],false);
  if(get_option($backup,false)===false)qa_error('rollback_unavailable');
  $updates=0;
  $edit=function(&$nodes)use(&$edit,&$updates,$sr,$listing_source,$listing){
   foreach($nodes as &$n){if(!is_array($n))continue;
    if(($n['id']??'')===$sr[0]['widget']&&array_key_exists('lisitng_id',(array)($n['settings']??[]))){
     if((int)$n['settings']['lisitng_id']!==$listing_source)qa_error('widget_drift');
     $n['settings']['lisitng_id']=is_int($n['settings']['lisitng_id'])?$listing:(string)$listing;
     $updates++;
    }
    if(isset($n['elements'])&&is_array($n['elements']))$edit($n['elements']);
   }unset($n);
  };
  $edit($tree);
  if($updates!==1)qa_error('non_unique_edit');
  update_post_meta($target,'_elementor_data',wp_slash(wp_json_encode($tree,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
 }
 $after=(string)get_post_meta($target,'_elementor_data',true);
 $actual=$find(json_decode($after,true));
 if(count($actual)!==1||(int)$actual[0]['value']!==$listing
  ||get_post_status($target)!=='draft'||get_post_status($listing)!=='draft')qa_error('readback');
 $proof=['contract'=>'mad4b.wpml.review-listing-link-proof.v1','source'=>$src,
  'target'=>$target,'locale'=>$lang,'listing_source'=>$listing_source,'listing_draft'=>$listing,
  'target_sha256'=>hash('sha256',$after),'native_job'=>$job_id,
  'reviews_published'=>(int)(wp_count_posts('custom-reviews')->publish??0),
  'still_draft'=>true,'auto_publish'=>false,'at'=>gmdate('c')];
 $key='mad4b_wpml_review_listing_link_proof_'.$target.'_v1';
 update_option($key,$proof,false);
 if(get_option($key,[])!==$proof)qa_error('proof_readback');
 echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES)."\n";
}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
