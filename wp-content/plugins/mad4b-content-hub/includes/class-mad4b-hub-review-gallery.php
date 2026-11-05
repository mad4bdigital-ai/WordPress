<?php
defined('ABSPATH') || exit;
/** Source-backed gallery for Master Review. Never infer media by title or URL. */
final class MAD4B_Hub_Review_Gallery {
 const CONTRACT='mad4b.hub.review-gallery.v1';
 const LIMIT=45;
 private static function ids($value,$depth=0) {
  if($depth>3)return [];
  if(is_numeric($value) && (string)(int)$value===(string)$value)return [(int)$value];
  if(is_string($value)){
   $value=maybe_unserialize($value);
   if(is_string($value))return preg_match('/^\s*\d+(?:\s*,\s*\d+)*\s*$/D',$value)?array_map('intval',explode(',',$value)):[];
  }
  if(!is_array($value))return [];
  $out=[];
  // Never search arbitrary JSON for incidental numeric values.
  if(isset($value['attachment_id']))return self::ids($value['attachment_id'],$depth+1);
  if(isset($value['id']))return self::ids($value['id'],$depth+1);
  if(isset($value['ID']))return self::ids($value['ID'],$depth+1);
  if(isset($value['media_id']))return self::ids($value['media_id'],$depth+1);
  foreach($value as $key=>$item)if(is_int($key)||ctype_digit((string)$key))$out=array_merge($out,self::ids($item,$depth+1));
  return $out;
 }
 private static function linked_post($data) {
  if(empty($data['job_id'])||!class_exists('MAD4B_SCP_Content_Jobs'))return null;
  $result=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$data['job_id']]);
  if(is_wp_error($result)||empty($result['job'])||($result['job']['job_id']??'')!==$data['job_id'])return null;
  $id=absint($result['job']['target_post_id']??0);
  if(!$id||!current_user_can('edit_post',$id))return null;
  $post=get_post($id);
  return $post&&$post->post_type!== 'attachment'?$post:null;
 }
 private static function block_media($blocks,&$ids,$depth=0) {
  if($depth>5)return;
  foreach((array)$blocks as $block){
   if(!is_array($block))continue;
   $type=(string)($block['blockName']??'');
   $attr=(array)($block['attrs']??[]);
   if($type==='core/image'||$type==='core/cover')$ids=array_merge($ids,self::ids($attr['id']??null));
   if($type==='core/gallery')$ids=array_merge($ids,self::ids($attr['ids']??[]));
   if(!empty($block['innerBlocks']))self::block_media($block['innerBlocks'],$ids,$depth+1);
  }
 }
 private static function media($id,$group,$post_id=0) {
  $id=absint($id);
  if(!$id||get_post_type($id)!=='attachment'||!wp_attachment_is_image($id))return null;
  $src=wp_get_attachment_image_src($id,'large');
  $thumb=wp_get_attachment_image_src($id,'thumbnail');
  if(!is_array($src)||!$src[0])return null;
  $post=get_post($id);
  $file=class_exists('MAD4B_Editorial_Featured_Image_Gate')
    ?MAD4B_Editorial_Featured_Image_Gate::verify_image($id):['valid'=>false,'reason'=>'image_guard_missing'];
  $source=(string)get_post_meta($id,'_mad4b_import_source_url',true);
  $rights=get_post_meta($id,'_mad4b_media_operator_responsibility_v1',true);
  $context=$post_id>0?get_post_meta($post_id,'_mad4b_hub_scraped_media_context_v1',true):null;
  $related=is_array($context)&&absint($context['attachment_id']??0)===$id;
  $verified=is_array($rights)&&($rights['rights_status']??'')==='verified'
    && !empty($rights['reuse_permitted'])
    && in_array($rights['rights_basis']??'',['owned','licensed','permission','public_domain','creative_commons'],true)
    && (($rights['rights_basis']??'')==='owned'||!empty($rights['rights_reference']));
  // Detect exact file replacement without hashing every large image per page.
  // Imported media may carry its own trusted content hash.
  $file_path=get_attached_file($id,true);
  $stat=is_string($file_path)&&is_file($file_path)
    ?[(int)@filesize($file_path),(int)@filemtime($file_path)]:[0,0];
  $asset_version=hash('sha256',wp_json_encode([
    'id'=>$id,'file'=>$stat,'modified'=>$post->post_modified_gmt,
    'ingest_sha'=>(string)get_post_meta($id,'_mad4b_remote_media_content_sha256',true)
  ]));
  $rights_version=hash('sha256',wp_json_encode($rights));
  $description=(string)$post->post_content;
  $metadata=[
    'title'=>(string)$post->post_title,
    'alt'=>(string)get_post_meta($id,'_wp_attachment_image_alt',true),
    'caption'=>(string)$post->post_excerpt,
    'description'=>$description,
    'credit'=>(string)get_post_meta($id,'_mad4b_image_credit',true),
  ];
  $missing=[];
  foreach(['title','alt','caption','description'] as $k)if(trim($metadata[$k])==='')$missing[]=$k;
  return [
   'id'=>$id,'group'=>$group,'src'=>(string)$src[0],
   'thumb'=>(string)($thumb[0]??$src[0]),
   'width'=>(int)($src[1]??0),'height'=>(int)($src[2]??0),
   'metadata'=>$metadata,'missing_metadata'=>$missing,
   'file_valid'=>!empty($file['valid']),
   'file_reason'=>(string)($file['reason']??'not_checked'),
   'provenance_url'=>$source,
   'rights_evidence_status'=>$verified?'verified_record':'not_confirmed',
   'rights_basis'=>$verified?(string)$rights['rights_basis']:'',
   'context_bound'=>$related,
   'observed_subjects'=>$related?(array)($context['observed_subjects']??[]):[],
   'metadata_source'=>'wordpress_attachment',
   'asset_version_sha256'=>$asset_version,
   'rights_version_sha256'=>$rights_version,
   'company_seo_owned_separately'=>true,
  ];
 }
 public static function package($data) {
  $output=is_array($data['output']??null)?$data['output']:[];
  $target=self::linked_post($data);
  // First read the central hub manifest for exactly this reviewed content
  // version. Company post IDs are OPTIONAL at this stage.
  $manifest=null;
  if(!empty($data['job_id']) && !empty($data['output_sha256'])
    &&class_exists('MAD4B_Hub_Media_Manifest')){
   $m=MAD4B_Hub_Media_Manifest::read(
     (string)$data['job_id'],(string)$data['output_sha256']);
   if(is_array($m))$manifest=$m;
  }
  $pending=[];
  $groups=['featured'=>[],'inline'=>[],'gallery'=>[]];
  foreach((array)($manifest['items']??[]) as $entry){
   $role=(string)($entry['role']??'');
   if(!array_key_exists($role,$groups))continue;
   if(($entry['kind']??'')==='local_attachment' && !empty($entry['attachment_id']))
    $groups[$role][]=(int)$entry['attachment_id'];
   elseif(($entry['kind']??'')==='scraped_candidate'){
    $pending[]=['role'=>$role,'source_url'=>(string)($entry['source_url']??''),
      'review_state'=>'capture_visual_and_rights_not_verified'];
   }
  }
  foreach(['featured_media_id','featured_image_id'] as $key)
   if(!empty($output[$key]))$groups['featured']=array_merge($groups['featured'],self::ids($output[$key]));
  foreach(['inline_image_ids','content_image_ids','inline_images'] as $key)
   if(!empty($output[$key]))$groups['inline']=array_merge($groups['inline'],self::ids($output[$key]));
  foreach(['gallery_ids','gallery','gallery_images','image_gallery','media_items','images'] as $key)
   if(!empty($output[$key]))$groups['gallery']=array_merge($groups['gallery'],self::ids($output[$key]));
  if($target){
   $groups['featured']=array_merge($groups['featured'],self::ids(get_post_thumbnail_id($target->ID)));
   foreach(['gallery','tour_gallery','tour_gallery_images','property_gallery','gallery_images','gallery_ids'] as $key)
    $groups['gallery']=array_merge($groups['gallery'],self::ids(get_post_meta($target->ID,$key,true)));
   if(function_exists('parse_blocks'))self::block_media(parse_blocks($target->post_content),$groups['inline']);
  }
  // Server-confirmed membership for editing: the post's own media, never
  // an attachment identifier asserted solely by an AI-generated output.
  $boundIds=[];
  // Manifest membership alone does not prove visual review or reuse rights.
  // Only the destination post's own bound media (or governed post context)
  // may be edited; pending central assets are review-only.

  if($target){
   $boundIds=self::ids(get_post_thumbnail_id($target->ID));
   foreach(['gallery','tour_gallery','tour_gallery_images','property_gallery','gallery_images','gallery_ids'] as $key)
    $boundIds=array_merge($boundIds,self::ids(get_post_meta($target->ID,$key,true)));
   if(function_exists('parse_blocks'))self::block_media(parse_blocks($target->post_content),$boundIds);
  }
  $boundIds=array_map('intval',array_unique($boundIds));
  $seen=[];$media=[];
  foreach($groups as $group=>$ids)foreach($ids as $id){
   $id=absint($id);if(!$id||isset($seen[$id])||count($media)>=self::LIMIT)continue;
   $seen[$id]=true;$item=self::media($id,$group,$target?(int)$target->ID:0);
   if($item){
    $item['editable_bound']=in_array($id,$boundIds,true)||$item['context_bound'];
    $media[]=$item;
   }
  }
  $fingerprint=[];
  foreach($media as $item)$fingerprint[]=[
    'id'=>$item['id'],'group'=>$item['group'],'meta'=>$item['metadata'],
    'file_valid'=>$item['file_valid'],'rights'=>$item['rights_evidence_status'],
    'source'=>$item['provenance_url'],'context'=>$item['context_bound'],
    'editable_bound'=>$item['editable_bound'],
    'file_identity'=>$item['asset_version_sha256'],
    'rights_identity'=>$item['rights_version_sha256']
  ];
  $hash=hash('sha256',wp_json_encode([
    'output'=>(string)($data['output_sha256']??''),
    'target_post_id'=>$target?(int)$target->ID:0,
    'images'=>$fingerprint,
    'manifest_sha256'=>(string)($manifest['manifest_sha256']??''),
    'pending_candidates'=>$pending
  ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return [
   'contract'=>self::CONTRACT,
   'sha256'=>$hash,'target_post_id'=>$target?(int)$target->ID:0,
   'manifest_sha256'=>(string)($manifest['manifest_sha256']??''),
   'pending_candidates'=>$pending,'pending_count'=>count($pending),
   'has_media_binding'=>count(array_filter($media,static fn($m)=>!empty($m['editable_bound'])))>0,
   'featured_image_linked'=>count(array_filter($media,
     static fn($m)=>$m['group']==='featured'&&!empty($m['editable_bound'])))>0,
   'media'=>$media,'count'=>count($media),
   'incomplete_metadata_count'=>count(array_filter($media,static fn($m)=>count($m['missing_metadata'])>0)),
   'file_missing_count'=>count(array_filter($media,static fn($m)=>!$m['file_valid'])),
   'rights_unverified_count'=>count(array_filter($media,static fn($m)=>$m['editable_bound']&&$m['rights_evidence_status']!=='verified_record')),
   'publication_authorized'=>false,
   'seo_fields_included'=>false,
   'attachment_metadata_writes_performed'=>false
  ];
 }
 /** Explicit admin action only: immutable review-package identity and exact
  * attachment membership are checked before editing shared media metadata.
  * No SEO meta, no publishing or rights escalation. */
 public static function save_metadata($input){
  if(wp_get_environment_type()!=='staging' ||
    !current_user_can('upload_files') ||
    !(current_user_can('manage_options')||current_user_can('mad4b_manage_company_operations')))
    return new WP_Error('mad4b_review_media_permission','Media editor required',['status'=>403]);
  $batch=sanitize_text_field((string)($input['batch_id']??''));
  $data=MAD4B_Hub_Role_Review::preview(['batch_id'=>$batch]);
  if(is_wp_error($data))return $data;
  $package=self::package($data);
  $expected=(string)($input['expected_media_package_sha256']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$expected) || !hash_equals($package['sha256'],$expected))
    return new WP_Error('mad4b_review_media_stale','Images changed since this review loaded',['status'=>409]);
  $id=absint($input['attachment_id']??0);$item=null;
  foreach($package['media'] as $row)if($row['id']===$id){$item=$row;break;}
  if(!$item || !$item['editable_bound'] || !current_user_can('edit_post',$id) || !$item['file_valid'])
    return new WP_Error('mad4b_review_media_not_linked','Image is not a valid attachment in this review',['status'=>403]);
  $fields=['title'=>220,'alt'=>220,'caption'=>2000,'description'=>2000,'credit'=>220];
  $values=[];
  foreach($fields as $key=>$max){
   if(!isset($input['image_'.$key]) || !is_scalar($input['image_'.$key]))
    return new WP_Error('mad4b_review_media_field_missing','All editable metadata keys are required',['status'=>400]);
   $values[$key]=trim(sanitize_textarea_field((string)$input['image_'.$key]));
   if(mb_strlen($values[$key],'UTF-8')>$max)
    return new WP_Error('mad4b_review_media_field_too_long','Image metadata field too long',['status'=>400]);
  }
  if($values['title']==='')return new WP_Error('mad4b_review_media_title_required','Title must not be empty',['status'=>400]);
  $result=wp_update_post([
   'ID'=>$id,
   'post_title'=>sanitize_text_field($values['title']),
   'post_excerpt'=>$values['caption'],
   'post_content'=>$values['description'],
   'meta_input'=>[
    '_wp_attachment_image_alt'=>sanitize_text_field($values['alt']),
    '_mad4b_image_credit'=>sanitize_text_field($values['credit'])
   ]
  ],true);
  if(is_wp_error($result))return $result;
  $after=get_post($id);
  if(!$after || $after->post_title!==sanitize_text_field($values['title'])
    || (string)get_post_meta($id,'_wp_attachment_image_alt',true)!==sanitize_text_field($values['alt']))
    return new WP_Error('mad4b_review_media_readback_failed','Metadata saved but readback failed',['status'=>500]);
  return ['contract'=>self::CONTRACT,'attachment_id'=>$id,
   'metadata_updated'=>true,'company_seo_unchanged'=>true,'published'=>false];
 }
 public static function render($data) {
  $package=self::package($data);$images=$package['media'];
  echo '<section class="mad4b-rv-panel mad4b-rv-gallery" data-mad4b-gallery aria-label="Article image gallery">';
  echo '<div class="mad4b-rv-gallery-header"><h3><span class="dashicons dashicons-format-gallery" aria-hidden="true"></span> '.esc_html__('Images & galleries','mad4b').'</h3><span class="mad4b-rv-gallery-hash">Review package: <code>'.esc_html(substr($package['sha256'],0,16)).'</code></span></div>';
  if($package['pending_count']){
   echo '<div class="mad4b-rv-gallery-empty"><strong>'.(int)$package['pending_count'].' scraped image candidate(s) awaiting verification</strong>';
   echo '<p>No image is imported, licensed or approved merely because it appears in this manifest.</p><ol>';
   foreach($package['pending_candidates'] as $candidate){
    echo '<li>'.esc_html(ucfirst($candidate['role'])).' — <code>'.
      esc_html(wp_parse_url($candidate['source_url'],PHP_URL_HOST)?:'unverified source').
      '</code> (capture, visual review and reuse rights pending)</li>';
   }
   echo '</ol></div>';
  }
  if(!$images){
   echo '<div class="mad4b-rv-gallery-empty" role="status"><strong>'.esc_html__('No gallery media is linked to this review yet.','mad4b').'</strong><p>'.esc_html__('A candidate image from scraping is not a reviewed attachment. Bind verified media through the source workflow to populate this carousel. No placeholder images are shown.','mad4b').'</p></div></section>';
   return;
  }
  echo '<div class="mad4b-rv-gallery-nav"><button type="button" class="button" data-rv-prev aria-label="Previous image">‹</button><span data-rv-count aria-live="polite">1 / '.count($images).'</span><button type="button" class="button" data-rv-next aria-label="Next image">›</button></div>';
  echo '<div class="mad4b-rv-gallery-stage" role="region" aria-label="Gallery slideshow" tabindex="0">';
  foreach($images as $i=>$image){
   echo '<figure class="mad4b-rv-gallery-slide" data-rv-slide'.($i?' hidden':'').'>';
   echo '<div class="mad4b-rv-gallery-photo"><img tabindex="0" role="button" aria-label="Enlarge image" '.($i?'loading="lazy"':'loading="eager"').' src="'.esc_url($image['src']).'" alt="'.esc_attr($image['metadata']['alt']?:'Unreviewed image: '.$image['id']).'" width="'.(int)$image['width'].'" height="'.(int)$image['height'].'"></div>';
   echo '<figcaption><strong>'.esc_html($image['metadata']['title']?:'Image #'.$image['id']).'</strong><span> '.esc_html(ucfirst($image['group'])).' · #'.(int)$image['id'].'</span></figcaption>';
   echo '<dl class="mad4b-rv-gallery-metadata">';
   foreach(['alt'=>'Alt text','caption'=>'Caption','description'=>'Description','credit'=>'Credit'] as $key=>$label){
    $value=trim($image['metadata'][$key]);
    echo '<div><dt>'.esc_html($label).'</dt><dd>'.esc_html($value?:'Not provided').'</dd></div>';
   }
   echo '<div><dt>Image file</dt><dd>'.($image['file_valid']?'Valid':'Missing / unverified').'</dd></div>';
   echo '<div><dt>Reuse rights</dt><dd>'.esc_html($image['rights_evidence_status']).'</dd></div>';
   echo '<div><dt>Source</dt><dd>'.($image['provenance_url']?'<a href="'.esc_url($image['provenance_url']).'" target="_blank" rel="noopener noreferrer">Original image source</a>':'No traceable import URL').'</dd></div>';
   echo '<div><dt>Metadata gaps</dt><dd>'.esc_html(implode(', ',$image['missing_metadata'])?:'None').'</dd></div>';
   echo '</dl>';
   if(!$image['editable_bound'])echo '<p class="mad4b-rv-secondary"><strong>Candidate only:</strong> attachment is not yet bound to the server-verified article. Metadata editing and publish approval are blocked.</p>';
   if($image['editable_bound'] && current_user_can('edit_post',$image['id']) && current_user_can('upload_files') &&
      (current_user_can('manage_options')||current_user_can('mad4b_manage_company_operations'))){
    echo '<details class="mad4b-rv-media-editor"><summary>Edit shared image metadata (not SEO)</summary>';
    echo '<form method="post" class="mad4b-rv-media-form">';
    wp_nonce_field('mad4b_hub_review_media_save');
    echo '<input type="hidden" name="rv_media_save" value="1">';
    echo '<input type="hidden" name="batch_id" value="'.esc_attr($data['batch_id']).'">';
    echo '<input type="hidden" name="attachment_id" value="'.(int)$image['id'].'">';
    echo '<input type="hidden" name="expected_media_package_sha256" value="'.esc_attr($package['sha256']).'">';
    foreach(['title'=>'Title','alt'=>'Alt text','caption'=>'Caption','description'=>'Description','credit'=>'Credit / attribution'] as $field=>$label){
      echo '<label><span>'.esc_html($label).'</span>';
      if(in_array($field,['description','caption'],true)){
       echo '<textarea name="image_'.esc_attr($field).'" maxlength="2000" rows="2">'.esc_textarea($image['metadata'][$field]).'</textarea>';
      }else{
       echo '<input type="text" name="image_'.esc_attr($field).'" maxlength="220" value="'.esc_attr($image['metadata'][$field]).'">';
      }
      echo '</label>';
    }
    if($image['context_bound'] && $image['observed_subjects']){
     $hint=implode(', ',array_slice(array_filter($image['observed_subjects'],'is_string'),0,8));
     echo '<button class="button" type="button" data-rv-media-suggest="'.esc_attr($hint).'">Suggest from verified visual observations</button>';
    }
    echo '<p class="mad4b-rv-secondary">Updates shared WordPress image metadata only. Company SEO and Rank Math are not changed. Publishing remains separate.</p>';
    echo '<button class="button button-primary" type="submit">Save image metadata</button></form></details>';
   }
   echo '</figure>';
  }
  echo '</div><div class="mad4b-rv-gallery-thumbs" role="group" aria-label="Choose gallery image">';
  foreach($images as $i=>$image)echo '<button type="button" data-rv-thumb="'.(int)$i.'" aria-label="Show image '.($i+1).'" aria-current="'.($i?'false':'true').'"><img loading="lazy" src="'.esc_url($image['thumb']).'" alt=""><span>'.esc_html(ucfirst($image['group'])).'</span></button>';
  echo '</div><p class="mad4b-rv-secondary">Image metadata is shared; SEO title, SEO description, keywords and company-specific optimization are maintained separately for each company. Media review does not publish.</p></section>';
 }
}
