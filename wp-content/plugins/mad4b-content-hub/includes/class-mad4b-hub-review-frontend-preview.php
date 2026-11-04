<?php
defined('ABSPATH')||exit;
/**
 * Authenticated frontend preview for the exact publication target.
 * Does not fabricate cross-company preview links from source URLs.
 */
final class MAD4B_Hub_Review_Frontend_Preview {
 const CONTRACT='mad4b.hub.frontend-preview.v1';
 private static function target_previews($job){
  if(!class_exists('MAD4B_Hub_Publication_Targets'))return [];
  $rows=MAD4B_Hub_Publication_Targets::get((string)$job['job_id']);
  if(!is_array($rows))return [];
  $out=[];
  $local_uuid=class_exists('MAD4B_SCP_Site_Profile')
    ?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
  foreach($rows as $row){
   $post_id=absint($row['target_post_id']??0);
   $dest=(string)($row['destination_site_uuid']??'');
   if($post_id===0 || $dest==='')continue;
   $mode=(string)($row['source_verified']??'');
   if($dest===$local_uuid && $mode==='local_wordpress'){
    $post=get_post($post_id);
    if(!$post || $post->post_type!==$row['target_post_type'] ||
       in_array($post->post_status,['trash','auto-draft'],true) ||
       !current_user_can('edit_post',$post_id))continue;
    $url=$post->post_status==='publish'?get_permalink($post_id):get_preview_post_link($post);
    $parts=wp_parse_url($url);$root=wp_parse_url(home_url('/'));
    if(!is_array($parts)||!is_array($root)||
      strtolower((string)($parts['scheme']??''))!=='https'||
      strtolower((string)($parts['host']??''))!==strtolower((string)($root['host']??'')))continue;
    $out[]=['contract'=>self::CONTRACT,'available'=>true,
      'reason'=>'bound_local_wordpress','url'=>esc_url_raw($url),
      'target'=>(string)$row['company_id'].' — '.$root['host'],
      'company_id'=>(string)$row['company_id'],
      'site_uuid'=>$dest,'publication_performed'=>false,'external'=>false];
   }elseif($mode==='verified_remote_connector'){
    $proof=apply_filters('mad4b_hub_review_verified_frontend_preview',null,[
      'job_id'=>$job['job_id'],'target_post_type'=>$row['target_post_type'],
      'target_post_id'=>$post_id,'site_uuid'=>$dest,
      'company_id'=>$row['company_id'],'language'=>$row['language']
    ]);
    if(!is_array($proof)||($proof['verified']??false)!==true ||
      ($proof['job_id']??'')!==$job['job_id']||
      ($proof['site_uuid']??'')!==$dest||
      ($proof['company_id']??'')!==$row['company_id']||
      absint($proof['target_post_id']??0)!==$post_id)continue;
    $url=(string)($proof['preview_url']??'');
    $root=(string)($proof['destination_site_url']??'');
    $a=wp_parse_url($url);$b=wp_parse_url($root);
    if(!is_array($a)||!is_array($b)||empty($a['host'])||
      strtolower((string)($a['scheme']??''))!=='https'||
      strtolower((string)($b['scheme']??''))!=='https'||
      strtolower((string)$a['host'])!==strtolower((string)($b['host']??''))||
      isset($a['user'])||isset($a['pass'])||isset($a['port'])||
      isset($b['port'])||isset($a['fragment']))continue;
    $out[]=['contract'=>self::CONTRACT,'available'=>true,
      'reason'=>'bound_verified_remote','url'=>esc_url_raw($url),
      'target'=>(string)$row['company_id'].' — '.$a['host'],
      'company_id'=>(string)$row['company_id'],
      'site_uuid'=>$dest,'publication_performed'=>false,'external'=>true];
   }
  }
  return $out;
 }
 public static function resolve($data){
  $none=['contract'=>self::CONTRACT,'available'=>false,'reason'=>'not_in_publication_stage',
   'url'=>'','target'=>'','publication_performed'=>false];
  if(!is_array($data)||empty($data['job_id'])||!get_current_user_id())return $none;
  if(!class_exists('MAD4B_SCP_Content_Jobs'))return $none;
  $result=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>(string)$data['job_id']]);
  if(is_wp_error($result)||empty($result['job']))return $none;
  $job=$result['job'];
  if(!hash_equals((string)$data['job_id'],(string)($job['job_id']??'')))return $none;
  $targets=self::target_previews($job);
  if($targets)return array_merge($targets[0],['targets'=>$targets]);
  if(class_exists('MAD4B_Hub_Publication_Targets')){
   $pending=MAD4B_Hub_Publication_Targets::get((string)$job['job_id']);
   if(is_array($pending)&&count($pending)>0)
    return array_merge($none,['reason'=>'bound_target_preview_evidence_pending',
      'target_count'=>count($pending)]);
  }
  $type=(string)($job['target_post_type']??'');
  $id=absint($job['target_post_id']??0);
  if($type==='' || $id===0)
   return array_merge($none,['reason'=>'publication_destination_not_bound']);
  // Remote publication targets may be supplied ONLY by a registered server-side
  // destination bridge with exact job and site bindings, never request payloads.
  $external=apply_filters('mad4b_hub_review_verified_frontend_preview',null,[
   'job_id'=>(string)$data['job_id'],'target_post_type'=>$type,
   'target_post_id'=>$id,'site_uuid'=>(string)($job['site_uuid']??'')
  ]);
  if(is_array($external)&&($external['verified']??false)===true
     &&(string)($external['job_id']??'')===(string)$data['job_id']
     &&absint($external['target_post_id']??0)===$id
     &&(string)($external['site_uuid']??'')===(string)($job['site_uuid']??'')
     &&!empty($external['destination_site_url'])&&!empty($external['preview_url'])){
   $url=(string)$external['preview_url'];
   $base=(string)$external['destination_site_url'];
   $parts=wp_parse_url($url);$origin=wp_parse_url($base);
   if(is_array($parts)&&is_array($origin)&&
      strtolower((string)($parts['scheme']??''))==='https'&&
      strtolower((string)($origin['scheme']??''))==='https'&&
      !empty($parts['host'])&&
      strtolower((string)$parts['host'])===strtolower((string)($origin['host']??''))&&
      empty($parts['user'])&&empty($parts['pass'])&&
      empty($parts['fragment'])&&
      !isset($parts['port'])&&!isset($origin['port'])){
     return ['contract'=>self::CONTRACT,'available'=>true,
      'reason'=>'verified_external_destination','url'=>esc_url_raw($url),
      'target'=>(string)($external['destination_label']??$origin['host']),
      'publication_performed'=>false,'external'=>true];
   }
  }
  // A remote company's post ID is NOT a local WordPress post identity.
  // Fall back to local preview only when the job is bound to this site's UUID.
  if(!class_exists('MAD4B_SCP_Site_Profile') ||
     (string)($job['site_uuid']??'')==='' ||
     !hash_equals(strtolower((string)MAD4B_SCP_Site_Profile::site_uuid()),
       strtolower((string)($job['site_uuid']??''))))
   return array_merge($none,['reason'=>'external_site_preview_bridge_required']);
  $post=get_post($id);
  if(!$post || $post->post_type!==$type ||
     in_array($post->post_status,['trash','auto-draft'],true) ||
     !current_user_can('edit_post',$id))
   return array_merge($none,['reason'=>'no_authorized_local_preview']);
  $url=$post->post_status==='publish'?get_permalink($id):get_preview_post_link($post);
  if(!is_string($url)||$url==='')return array_merge($none,['reason'=>'preview_link_unavailable']);
  $u=wp_parse_url($url);$h=wp_parse_url(home_url('/'));
  if(!is_array($u)||!is_array($h)||!in_array(strtolower((string)($u['scheme']??'')),['https'],true)
     ||strtolower((string)($u['host']??''))!==strtolower((string)($h['host']??''))
     ||isset($u['user'])||isset($u['pass']))
   return array_merge($none,['reason'=>'preview_origin_mismatch']);
  return ['contract'=>self::CONTRACT,'available'=>true,
   'reason'=>'authorized_local_wordpress_frontend','url'=>esc_url_raw($url),
   'target'=>wp_parse_url(home_url('/'),PHP_URL_HOST),
   'publication_performed'=>false,'external'=>false,
   'draft_preview'=>$post->post_status!=='publish'];
 }
 public static function render($data){
  $result=self::resolve($data);
  echo '<section class="mad4b-rv-panel mad4b-rv-frontend-preview" aria-label="Frontend publication preview">';
  echo '<h3><span class="dashicons dashicons-visibility" aria-hidden="true"></span> Frontend preview</h3>';
  if(!$result['available']){
   echo '<p class="mad4b-rv-secondary">External page preview is unavailable until a publication destination and exact target page are linked to this Content Job.</p>';
   echo '<p class="mad4b-rv-secondary">Status: <code>'.esc_html($result['reason']).'</code></p>';
  }else{
   $links=!empty($result['targets'])?(array)$result['targets']:[$result];
   foreach($links as $link){
    echo '<p><a class="button button-secondary" href="'.esc_url($link['url']).'" target="_blank" rel="noopener noreferrer">Preview on '.esc_html($link['target']).' <span class="dashicons dashicons-external" aria-hidden="true"></span></a></p>';
   }
   echo '<p class="mad4b-rv-secondary">'.($result['external']?
    'Verified connected destination preview.':'Authenticated WordPress frontend preview.')
    .' Opening the page does not publish the article.</p>';
  }
  echo '</section>';
 }
}
