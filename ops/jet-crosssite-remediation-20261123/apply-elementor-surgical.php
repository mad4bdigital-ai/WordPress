<?php
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI_ONLY');
$site=getenv('MAD4B_SITE');$mode=getenv('MAD4B_MODE');
if(!in_array($site,['source','wayfa'],true)||!in_array($mode,['check','apply'],true))throw new RuntimeException('INVALID_MODE');
$file='/tmp/mad4b-crosssite-'.$site.'-plan-20261123.json';$plan=json_decode(file_get_contents($file),true);
$host=($site==='source'?'tours.dev.mad4b.com':'wayfatours.com');
if(!is_array($plan)||($plan['host']??'')!==$host || (string)wp_parse_url(home_url('/'),PHP_URL_HOST)!==$host)throw new RuntimeException('HOST_MISMATCH');
$all=$plan['documents']??[];if(!is_array($all)||count($all)<8||count($all)>35)throw new RuntimeException('UNEXPECTED_SCOPE');
$checkNode=function(array $tree,string $nodeId):array {
 $found=[];
 $walk=function($nodes)use(&$walk,&$found,$nodeId){foreach((array)$nodes as $n){if(!is_array($n))continue;if(($n['id']??'')===$nodeId)$found[]=$n;
  if(!empty($n['elements']))$walk($n['elements']);}};$walk($tree);
 if(count($found)!==1)throw new RuntimeException('NODE_ID_NOT_UNIQUE_'.$nodeId);
 return $found[0];
};
$records=[];$report=[];$backups=[];
foreach($all as $id=>$config){
 $id=absint($id);
 if(!$id||get_post_type($id)!=='elementor_library'||get_post_status($id)!=='publish')throw new RuntimeException('NOT_PUBLISHED_ELEMENTOR_'.$id);
 $raw=get_post_meta($id,'_elementor_data',true);
 if(!is_string($raw)||strlen($raw)<500||hash('sha256',$raw)!==($config['sha256']??''))throw new RuntimeException('SOURCE_CAS_DRIFT_'.$id);
 $tree=json_decode($raw,true);if(!is_array($tree))throw new RuntimeException('BAD_ORIGINAL_JSON_'.$id);
 $new=$raw;$operations=[];$seen=[];
 foreach(($config['operations']??[]) as $op){
  $nodeId=(string)($op['node']??'');$field=(string)($op['field']??'');$old=(string)($op['from']??'');$want=(string)($op['to']??'');
  if(isset($seen[$nodeId.':'.$field]))throw new RuntimeException('REPEATED_NODE_OP_'.$id);
  $seen[$nodeId.':'.$field]=true;
  $node=$checkNode($tree,$nodeId);$settings=(array)($node['settings']??[]);
  if((string)($settings[$field]??'')!==$old)throw new RuntimeException('SETTING_CHANGED_'.$id.'_'.$field);
  if($field==='_animation_mobile'){
   if(($settings['_animation']??'')!=='fadeInUp'||$want!=='none'||$old!=='')throw new RuntimeException('INVALID_ANIMATION_POLICY');
   $search='"_animation":"fadeInUp"';$replacement=$search.',"_animation_mobile":"none"';
  }elseif($field==='_element_id'){
   if($nodeId!=='b51f7bd'||$old!=='destination-featured-attraction-listing'||$want!=='home-wonderful-locations-listing'||$site!=='source'||(int)($settings['lisitng_id']??0)!==45929)throw new RuntimeException('INVALID_LOCATIONS_ID_POLICY');
   $search='"_element_id":"'.$old.'"';$replacement='"_element_id":"'.$want.'"';
  }elseif($field==='button_css_id'){
   if(($node['widgetType']??'')!=='button'||$old!=='tours_query_archive'||!preg_match('/^mad4b-(?:archive-filter-trigger|filter-popup-action)-[a-f0-9]{7,8}$/D',$want))throw new RuntimeException('INVALID_BUTTON_ID_POLICY');
   $search='"button_css_id":"'.$old.'"';$replacement='"button_css_id":"'.$want.'"';
  }else throw new RuntimeException('FIELD_NOT_WHITELISTED');
  if(isset($op['expected_listing_id']) && (int)($settings['lisitng_id']??0)!==(int)$op['expected_listing_id'])throw new RuntimeException('LISTING_DRIFT_'.$id);
  if(isset($op['expected_query_id']) && (int)($settings['custom_query_id']??0)!==(int)$op['expected_query_id'])throw new RuntimeException('QUERY_DRIFT_'.$id);
  if(isset($op['expected_dom_id']) && (string)($settings['_element_id']??'')!==(string)$op['expected_dom_id'])throw new RuntimeException('DOM_DRIFT_'.$id);
  $marker='"id":"'.$nodeId.'"';
  if(substr_count($new,$marker)!==1)throw new RuntimeException('SOURCE_NODE_RAW_NOT_UNIQUE_'.$id.'_'.$nodeId);
  $start=strpos($new,$marker);$end=strpos($new,'"elements":',$start);
  if($end===false||$end-$start>12000)throw new RuntimeException('NODE_BOUNDARY_MISSING_'.$id);
  $offset=strpos($new,$search,$start);
  if($offset===false||$offset>=$end)throw new RuntimeException('EXACT_FIELD_NOT_INSIDE_NODE_'.$id.'_'.$nodeId.'_'.$field);
  $new=substr_replace($new,$replacement,$offset,strlen($search));
  $operations[]=['id'=>$nodeId,'field'=>$field,'before'=>$old,'after'=>$want];
 }
 if(!$operations||$raw===$new)throw new RuntimeException('EMPTY_CHANGE_'.$id);
 $after=json_decode($new,true);if(!is_array($after))throw new RuntimeException('BAD_PATCH_JSON_'.$id);
 foreach($operations as $op){$node=$checkNode($after,$op['id']);$s=(array)($node['settings']??[]);if((string)($s[$op['field']]??'')!==$op['after'])throw new RuntimeException('READBACK_PATCH_BAD_'.$id);}
 $reversed=$new;
 foreach(array_reverse($operations) as $op){
  if($op['field']==='_animation_mobile'){
   $before='"_animation":"fadeInUp","_animation_mobile":"none"';$afterStr='"_animation":"fadeInUp"';
  }else{$before='"'.$op['field'].'":"'.$op['after'].'"';$afterStr='"'.$op['field'].'":"'.$op['before'].'"';}
  $needle='"id":"'.$op['id'].'"';$start=strpos($reversed,$needle);$end=strpos($reversed,'"elements":',$start);$offset=strpos($reversed,$before,$start);
  if($offset===false||$offset>=$end)throw new RuntimeException('REVERSAL_PROOF_BAD_'.$id);
  $reversed=substr_replace($reversed,$afterStr,$offset,strlen($before));
 }
 if($reversed!==$raw)throw new RuntimeException('CHANGED_UNAUTHORIZED_FIELDS_'.$id);
 global $wpdb;
 $data=$wpdb->get_row($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",$id,'_elementor_data'));
 if(!is_object($data)||$data->meta_value!==$raw)throw new RuntimeException('DB_COMPARE_FAILED_'.$id);
 $cache=$wpdb->get_row($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",$id,'_elementor_element_cache'));
 $backups[$id]=['data_b64'=>base64_encode($raw),'data_sha256'=>hash('sha256',$raw),'element_cache_b64'=>is_object($cache)?base64_encode($cache->meta_value):null,'cache_sha256'=>is_object($cache)?hash('sha256',$cache->meta_value):null];
 $records[$id]=['original'=>$data,'new'=>$new,'cache'=>$cache];
 $report[$id]=['count'=>count($operations),'operations'=>$operations,'after_sha256'=>hash('sha256',$new)];
}
$backup='/tmp/mad4b-crosssite-'.$site.'-elementor-backup-20261123.json';
if(file_exists($backup))throw new RuntimeException('BACKUP_COLLISION');
$fp=fopen($backup,'x');if(!$fp)throw new RuntimeException('BACKUP_UNAVAILABLE');chmod($backup,0600);
fwrite($fp,wp_json_encode(['contract'=>'mad4b.elementor.selective.backup.v1','host'=>$host,'when_utc'=>gmdate('c'),'documents'=>$backups],JSON_UNESCAPED_SLASHES));fflush($fp);fclose($fp);
if($mode==='check'){echo 'MAD4B_PRECHECK_PASS '.wp_json_encode(['site'=>$site,'docs'=>count($records),'backup_path'=>$backup,'changes'=>$report])."\n";exit;}
$lock='mad4b-crosssite-elementor-fix-'.$site;
if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,8)',$lock))!==1)throw new RuntimeException('OWNER_CAS_LOCK_BUSY');
try{
 $wpdb->query('START TRANSACTION');
 foreach($records as $id=>$rec){
  $r=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND BINARY meta_value=%s",$rec['new'],$rec['original']->meta_id,$rec['original']->meta_value));
  if($r!==1){$wpdb->query('ROLLBACK');throw new RuntimeException('META_UPDATE_CONFLICT_'.$id);}
  if(is_object($rec['cache'])){
   $c=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_id=%d AND BINARY meta_value=%s",$rec['cache']->meta_id,$rec['cache']->meta_value));
   if($c!==1){$wpdb->query('ROLLBACK');throw new RuntimeException('CACHE_DELETE_CONFLICT_'.$id);}
  }
 }
 $wpdb->query('COMMIT');
 foreach($records as $id=>$rec){wp_cache_delete($id,'post_meta');$actual=(string)get_post_meta($id,'_elementor_data',true);if($actual!==$rec['new'])throw new RuntimeException('POST_COMMIT_READBACK_FAILED_'.$id);}
 echo 'MAD4B_SELECTIVE_ELEMENTOR_APPLIED '.wp_json_encode(['host'=>$host,'site'=>$site,'docs'=>count($records),'backup_path'=>$backup,'changes'=>$report])."\n";
}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
