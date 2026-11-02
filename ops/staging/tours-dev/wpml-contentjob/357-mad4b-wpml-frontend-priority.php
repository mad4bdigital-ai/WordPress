<?php
/**
 * Plugin Name: MAD4B WPML Frontend-First Work Priority
 * Description: Self-updating priorities based on sampled public multilingual HTML and WPML object identity.
 * Version: 0.1.0-staging
 */
defined('ABSPATH')||exit;
final class MAD4B_WPML_Frontend_Priority_V1 {
 const SNAPSHOT='mad4b_wpml_frontend_visibility_v1';
 const HISTORY='mad4b_wpml_frontend_visibility_history_v1';
 private static $snapshot=null;
 private static $rank_cache=[];
 public static function ready(){
  return wp_get_environment_type()==='staging'
   && rtrim(home_url(),'/')==='https://tours.dev.mad4b.com'
   && defined('ICL_SITEPRESS_VERSION');
 }
 public static function import($path='/tmp/mad4b-wpml-frontend-visibility.json'){
  if(!self::ready()||!class_exists('MAD4B_SCP_Policy')||!MAD4B_SCP_Policy::can_mutate())
   return new WP_Error('wpml_priority_authority_required');
  if(!is_file($path)||!is_readable($path)||filesize($path)>250000)
   return new WP_Error('wpml_priority_source_unavailable');
  $source_bytes=file_get_contents($path);
  if(!is_string($source_bytes)||$source_bytes==='')return new WP_Error('wpml_priority_empty_source');
  $snapshot=json_decode($source_bytes,true);
  if(!is_array($snapshot)
    ||($snapshot['contract']??'')!=='mad4b.wpml.frontend-visibility.v1'
    ||($snapshot['site']??'')!==rtrim(home_url(),'/'))
   return new WP_Error('wpml_priority_wrong_contract_or_site');
  $seen=strtotime((string)($snapshot['observed_at']??''));
  if(!$seen||abs(time()-$seen)>3600)return new WP_Error('wpml_priority_observation_stale');
  $languages=['ar','fr','de','es','it'];$checked=0;$pages=0;$compact=[];
  foreach($languages as $lang){
   $links=(array)($snapshot['pages'][$lang]??[]);
   if(!$links||count($links)>6)continue;
   $valid=[];
   foreach($links as $page){
    $path=(string)($page['path']??'');
    if(!str_starts_with($path,'/'.$lang.'/')||strlen($path)>300
     ||(int)($page['http_status']??0)!==200)continue;
    $row=['path'=>$path,'kind'=>($page['kind']??'')==='home'?'home':'linked_page'];
    foreach(['elementor_ids','listing_ids','post_ids'] as $field){
     $ids=(array)($page[$field]??[]);
     if(count($ids)>400)continue 2;
     $row[$field]=array_values(array_unique(array_map('intval',array_filter($ids,
      static fn($v)=>is_scalar($v)&&ctype_digit((string)$v)&&(int)$v>0))));
    }
    $valid[]=$row;
   }
   if($valid){$compact[$lang]=$valid;$pages+=count($valid);$checked++;}
  }
  // A partial crawl cannot erase established evidence. Keep last good snapshot.
  if($checked<4||$pages<12)return new WP_Error('wpml_priority_coverage_incomplete');
  $data=['contract'=>'mad4b.wpml.frontend-priority.v1','at'=>gmdate('c',$seen),
   'lang_count'=>$checked,'pages_count'=>$pages,'pages'=>$compact,
   'source_sha256'=>hash('sha256',$source_bytes)];
  $old=get_option(self::SNAPSHOT,[]);
  if(is_array($old)&&$old)update_option(self::HISTORY,['at'=>gmdate('c'),'digest'=>$old['source_sha256']??null,
    'pages_count'=>$old['pages_count']??0],false);
  update_option(self::SNAPSHOT,$data,false);
  $read=get_option(self::SNAPSHOT,[]);
  if(!is_array($read)||$read['source_sha256']!==$data['source_sha256'])
   return new WP_Error('wpml_priority_persist_readback_failed');
  self::$snapshot=$data;self::$rank_cache=[];
  return ['contract'=>$data['contract'],'pages'=>$pages,'languages'=>$checked,
   'evidence_at'=>$data['at'],'sha256'=>$data['source_sha256'],
   'mutation'=>'priority_cache_only','published'=>false];
 }
 public static function snapshot(){
  if(self::$snapshot!==null)return self::$snapshot;
  $v=get_option(self::SNAPSHOT,[]);
  return self::$snapshot=is_array($v)?$v:[];
 }
 public static function rank($source,$type,$lang,$target=0) {
  $source=(int)$source;$lang=sanitize_key((string)$lang);
  $key=$source.'|'.$type.'|'.$lang.'|'.(int)$target;
  if(isset(self::$rank_cache[$key]))return self::$rank_cache[$key];
  $snap=self::snapshot();$since=strtotime((string)($snap['at']??''))?:0;
  $age=$since?(time()-$since):PHP_INT_MAX;
  $none=['priority'=>100,'visible'=>false,'evidence'=>'unknown',
   'evidence_path'=>null,'evidence_age_seconds'=>$age===PHP_INT_MAX?null:max(0,$age)];
  if(!$source||!isset($snap['pages'][$lang])||$age>172800)return self::$rank_cache[$key]=$none;
  if(!$target){
   $look=apply_filters('wpml_object_id',$source,$type,false,$lang);
   $target=(int)$look;
  }
  $ids=array_values(array_unique(array_filter([$target,$source])));
  $best=0;$path=null;$matches=0;
  foreach($snap['pages'][$lang] as $page) {
   $home=$page['kind']==='home';$base=$home?900:690;
   $el=(array)($page['elementor_ids']??[]);
   $listing=(array)($page['listing_ids']??[]);
   $posts=(array)($page['post_ids']??[]);
   foreach($ids as $id) {
    if(in_array($id,$el,true)){$score=$base+45;$matches++;}
    elseif(in_array($id,$listing,true)){$score=$base+30;$matches++;}
    elseif(in_array($id,$posts,true)){$score=$base-60;$matches++;}
    else continue;
    if($score>$best){$best=$score;$path=$page['path'];}
   }
  }
  $freshness=$age>7200?0.65:($age>3600?0.85:1);
  $result=$none;
  if($best) {
   $result=['priority'=>(int)round(($best+min(45,$matches*5))*$freshness),
    'visible'=>true,'evidence'=>'public_html_wpml_resolved',
    'evidence_path'=>$path,'evidence_age_seconds'=>max(0,$age)];
  }else {
   // "Not observed" is NOT "not published"; retain fair fallback.
   $result['priority']=90;
   $result['evidence']='not_observed_in_bounded_sample';
  }
  return self::$rank_cache[$key]=$result;
 }
 public static function sort_outbox($list,$limit=40){
  $list=array_values((array)$list);
  usort($list,static function($a,$b){
   $sourceA=(int)($a['source_post_id']??0);$sourceB=(int)($b['source_post_id']??0);
   $typeA=get_post_type($sourceA);$typeB=get_post_type($sourceB);
   $aRank=self::rank($sourceA,$typeA,(string)($a['target_language']??''),(int)($a['target_post_id']??0));
   $bRank=self::rank($sourceB,$typeB,(string)($b['target_language']??''),(int)($b['target_post_id']??0));
   if($aRank['priority']!==$bRank['priority'])return $bRank['priority']<=>$aRank['priority'];
   return strcmp((string)($a['issue_id']??$sourceA),(string)($b['issue_id']??$sourceB));
  });
  return array_slice($list,0,max(1,min(100,(int)$limit)));
 }
 public static function cli($args){
  $action=$args[0]??'status';
  if($action==='import')$r=self::import();
  elseif($action==='status'){
   $s=self::snapshot();$r=['contract'=>'mad4b.wpml.frontend-priority.v1',
    'evidence_at'=>$s['at']??null,'pages'=>$s['pages_count']??0,
    'languages'=>$s['lang_count']??0,'age_seconds'=>isset($s['at'])?max(0,time()-strtotime($s['at'])):null];
   if(class_exists('MAD4B_WPML_ContentJob_Adapter')){
    $rows=MAD4B_WPML_ContentJob_Adapter::discover()['items'];$ranked=[];
    foreach($rows as $row){
     if(!$row['eligible'])continue;
     $p=self::rank($row['src'],$row['type'],$row['lang'],$row['dst']);
     $ranked[]=['source'=>$row['src'],'type'=>$row['type'],'lang'=>$row['lang'],
      'target'=>$row['dst'],'priority'=>$p['priority'],'evidence'=>$p['evidence'],
      'path'=>$p['evidence_path']];
    }
    usort($ranked,static fn($a,$b)=>$b['priority']<=>$a['priority']);
    $r['top']=array_slice($ranked,0,15);
    $r['confirmed_visible']=count(array_filter($ranked,static fn($x)=>$x['evidence']==='public_html_wpml_resolved'));
   }
  }
  elseif($action==='rank')$r=self::rank((int)($args[1]??0),(string)($args[2]??''),(string)($args[3]??''),(int)($args[4]??0));
  else WP_CLI::error('Allowed: status, import, rank <source> <type> <lang> [target]');
  if(is_wp_error($r))WP_CLI::error($r->get_error_code());
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function boot(){
  if(!self::ready())return;
  if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b-wpml-priority',[__CLASS__,'cli']);
 }
}
add_action('plugins_loaded',['MAD4B_WPML_Frontend_Priority_V1','boot'],112);
