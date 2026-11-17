<?php
/**
 * MAD4B dynamic browser certificate issuer.
 * Auto-discovers published route/doc/widget/query relationships; no fixed
 * IDs, languages, countries, currencies, price sources or site keys.
 * Every write requires seven independent signed native JetEngine SSR/AJAX
 * browser pairs, matching active WCML, WPML, Elementor and Query Builder.
 */
if(!defined('WP_CLI') || !WP_CLI)exit(9);
global $wpdb;
$manifestFile='/tmp/mad4b-native-coverage-current.json';
$reportFile='/tmp/mad4b-native-coverage-report.jsonl';
$scanner='/tmp/mad4b-native-listing-discovery.php';
foreach([$manifestFile,$reportFile,$scanner] as $file){
 if(!is_file($file)||is_link($file))throw new RuntimeException('Missing local attested input');
}
if(filesize($reportFile)<500||filesize($reportFile)>180000
 ||time()-filemtime($reportFile)>12*60 ||filemtime($reportFile)>time()+120)
 throw new RuntimeException('Missing or stale complete browser QA');
$cfg=json_decode((string)file_get_contents($manifestFile),true);
if(!is_array($cfg)||!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',(string)($cfg['locale']??'')))
 throw new RuntimeException('Invalid candidate configuration');
$host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
$cfgHost=(string)wp_parse_url((string)($cfg['url']??''),PHP_URL_HOST);
if($host===''||$cfgHost!==$host||wp_get_environment_type()!=='staging')
 throw new RuntimeException('Unenrolled site or environment');
ob_start();
try{require $scanner;$fresh=json_decode((string)ob_get_clean(),true);}
catch(Throwable $t){ob_end_clean();throw $t;}
if(!is_array($fresh)||($fresh['contract']??'')!=='mad4b.native-lazy.auto-discovery.v1'
 ||($fresh['site_host']??'')!==$host)throw new RuntimeException('Discovery failed');
$matches=array_values(array_filter((array)($fresh['candidates']??[]),static function($row)use($cfg){
 foreach(['locale','url','page_id','document_id','widget_id','query_id',
   'listing_id','query_generation','listing_sha256','listing_css_sha256','market'] as $k){
  if(($row[$k]??null)!==($cfg[$k]??null))return false;
 }
 return true;
}));
if(count($matches)!==1)throw new RuntimeException('Candidate disappeared or changed');
$cohort=$matches[0];
if(!in_array($cohort['locale'],(array)$fresh['wpml_languages'],true)
 ||$cohort['query_type']!=='terms')throw new RuntimeException('No current public WPML terms document');
if((int)$cohort['css_epoch']!==(int)get_option('mad4b_css_ai_epoch_v1',-1)
 ||(int)$cohort['mobile_breakpoint']!==(int)$fresh['mobile_breakpoint'])
 throw new RuntimeException('Elementor/CSS context moved');
$marketRuntime=mad4b_render_query_market_identity();
$market=is_array($marketRuntime)&&isset($marketRuntime['country'],$marketRuntime['currency'])
 ?strtolower($marketRuntime['country'].'-'.$marketRuntime['currency']):'';
if($market===''||$market!==$cohort['market'])
 throw new RuntimeException('First-party market identity no longer matches');
$widget=(string)$cohort['widget_id'];
$query=(int)$cohort['query_id'];$listing=(int)$cohort['listing_id'];
$doc=(int)$cohort['document_id'];$locale=(string)$cohort['locale'];
if(!preg_match('/^[a-f0-9]{7,9}$/D',$widget)
 ||!mad4b_secondary_grid_lazy_parent_condition_proven($query,$listing,$doc)
 ||get_post_status($doc)!=='publish'||get_post_status($listing)!=='publish')
 throw new RuntimeException('Conditional native listing not enrolled');
$source=(string)get_post_meta($listing,'_elementor_data',true);
$style=WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$listing.'.css';
if(!is_file($style)||is_link($style)
 ||hash('sha256',$source)!==$cohort['listing_sha256']
 ||hash_file('sha256',$style)!==$cohort['listing_css_sha256'])
 throw new RuntimeException('Actual listing source changed');
$origin=(string)apply_filters('wpml_current_language','');
do_action('wpml_switch_language',$locale);
try{
 if((string)apply_filters('wpml_current_language','')!==$locale
  ||mad4b_render_query_generation_for_id($query)!==$cohort['query_generation'])
  throw new RuntimeException('Language/query generation changed');
 $threshold=max(18,min(100,(int)get_option('mad4b_secondary_grid_lazy_heavy_terms_threshold',24)));
 $widths=[320,390,767,768,1024,1440,1920];
 $items=[];
 foreach(explode("\n",(string)file_get_contents($reportFile)) as $line){
  if(!str_starts_with($line,'QA_RESULT '))continue;
  $x=json_decode(substr($line,10),true);
  if(!is_array($x)||($x['pass']??false)!==true
   ||($x['canary_signed_header']??false)!==true
   ||($x['native_signed_ajax']??false)!==true
   ||($x['market']??'')!==$market
   ||($x['locale']??'')!==$locale
   ||($x['site_host']??'')!==$host
   ||(int)($x['document_id']??0)!==$doc
   ||(int)($x['query_id']??0)!==$query
   ||(int)($x['listing_id']??0)!==$listing
   ||($x['widget_id']??'')!==$widget
   ||(int)($x['baseline_http']??0)!==200
   ||(int)($x['canary_http']??0)!==200
   ||($x['count_match']??false)!==true
   ||($x['links_match']??false)!==true
   ||($x['content_match']??false)!==true
   ||($x['errors']??null)!==[]||($x['failed_css']??null)!==[]
   ||!is_numeric($x['cls_canary']??null)
   ||(float)$x['cls_canary']<0||(float)$x['cls_canary']>0.10)
    throw new RuntimeException('Invalid browser sample');
  $w=(int)($x['width']??0);
  if(!in_array($w,$widths,true)||isset($items[$w]))
   throw new RuntimeException('Duplicate or unsupported viewport');
  if((int)($x['baseline_count']??0)<$threshold
   ||(int)$x['baseline_count']!==(int)($x['ajax_count']??-1)
   ||(int)($x['base_before']['items']??0)!==(int)$x['baseline_count']
   ||(int)($x['canary_before']['items']??-1)!==0
   ||(bool)($x['base_before']['lazy']??true)!==false
   ||(bool)($x['canary_before']['lazy']??false)!==true
   ||(int)($x['base_before']['height']??0)<100
   ||(int)($x['base_before']['height']??0)>1600
   ||(int)($x['base_before']['height']??0)!==(int)($x['canary_before']['height']??-1)
   ||(int)($x['base_before']['width']??0)<50
   ||(int)($x['base_before']['width']??0)!==(int)($x['canary_before']['width']??-1)
   ||(int)($x['base_overflow']??100)>1
   ||(int)($x['ajax_overflow']??100)>1
   ||(int)($x['fcp_base']??0)<=0
   ||(int)($x['fcp_canary']??0)<=0
   ||(float)$x['fcp_canary']>(float)$x['fcp_base']*1.20)
    throw new RuntimeException('Native SSR/item count/layout/performance mismatch '.$w);
  foreach(['content','link'] as $part){
   $digest=(string)($x[$part.'_hash']??'');
   if(!preg_match('/^[a-f0-9]{64}$/D',$digest))
    throw new RuntimeException('Invalid content hash');
  }
  $signed=false;
  foreach((array)($x['ajax_requests']??[]) as $r){
   if(($r['action']??'')==='jet_engine_ajax'
    &&($r['method']??'')==='POST'
    &&($r['signed_query']??false)===true
    &&($r['signed_widget']??false)===true
    &&($r['query_id']??'')===(string)$query
    &&($r['template_id']??'')===(string)$doc)$signed=true;
  }
  if(!$signed||!in_array(200,array_column((array)($x['jet_responses']??[]),'status'),true))
   throw new RuntimeException('Incorrect signed JetEngine request');
  $items[$w]=$x;
 }
 ksort($items,SORT_NUMERIC);
 if(array_keys($items)!==$widths)throw new RuntimeException('Seven independent required widths not yet verified');
 foreach(['mobile'=>[320,390,767],'wide'=>[768,1024,1440,1920]] as $band=>$test){
  $heights=array_unique(array_map(static fn($w)=>(int)$items[$w]['base_before']['height'],$test));
  if(count($heights)!==1)throw new RuntimeException('Breakpoint height not stable '.$band);
 }
 if(is_file(WPMU_PLUGIN_DIR.'/998-mad4b-auto-coverage-qa.php'))
  throw new RuntimeException('Temporary browser probe still active');
 $opt='mad4b_secondary_grid_lazy_layout_proofs_v1';
 $lock='mad4b_auto_coverage_issue_'.substr(hash('sha256',$host),0,10);
 if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1)
   throw new RuntimeException('Coverage issuer busy');
 try{
  $old=$wpdb->get_var($wpdb->prepare(
   "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$opt));
  if(!is_string($old))throw new RuntimeException('No source layout contract');
  $registry=maybe_unserialize($old);
  if(!is_array($registry)
   ||($registry['contract']??'')!=='mad4b.native-listing.layout-proof.v1'
   ||($registry['site_host']??'')!==$host
   ||(int)($registry['mobile_breakpoint']??0)!==(int)$cohort['mobile_breakpoint'])
   throw new RuntimeException('Stored Elementor proof registry incompatible');
  // A genuinely new CSS epoch invalidates EVERY existing layout certificate.
  // Once one cohort has passed complete fresh signed seven-width QA, rotate
  // the registry epoch in the SAME atomic transaction, but keep all other
  // locale/market/device proofs on HOLD until their own browser admission.
  // Never relabel the old proofs pass under a new epoch.
  $previousEpoch=(int)($registry['css_epoch']??-1);
  $nextEpoch=(int)$cohort['css_epoch'];
  if($nextEpoch<0 ||$nextEpoch!==(int)get_option('mad4b_css_ai_epoch_v1',-1))
   throw new RuntimeException('New CSS epoch no longer current');
  if($previousEpoch!==$nextEpoch){
   foreach((array)($registry['entries']??[]) as $entryKey=>$entry){
    if(!is_array($entry))continue;
    $registry['entries'][$entryKey]['status']='hold_css_epoch_rollover';
    foreach((array)($entry['ajax_parity_by_market']??[]) as $marketKey=>$proof){
     if(!is_array($proof))continue;
     $registry['entries'][$entryKey]['ajax_parity_by_market'][$marketKey]['status']='hold_css_epoch_rollover';
    }
   }
   $registry['css_epoch']=$nextEpoch;
   $registry['last_epoch_transition']=[
    'from'=>$previousEpoch,'to'=>$nextEpoch,'at'=>gmdate('c'),
    'decision'=>'hold_existing_until_per_cohort_signed_qa'
   ];
  }
  $stamp=time();
  foreach(['mobile'=>[320,390,767],'wide'=>[768,1024,1440,1920]] as $band=>$test){
   $first=$items[$test[0]];$max=0.0;$evidence=[];
   foreach($test as $w){
    $r=$items[$w];$max=max($max,(float)$r['cls_canary']);
    $evidence[(string)$w]=[
      'width'=>$w,'status'=>'pass','signed_ajax_verified'=>true,
      'ssr_items'=>(int)$r['baseline_count'],'ajax_items'=>(int)$r['ajax_count'],
      'ssr_height_px'=>(int)$r['base_before']['height'],
      'reserved_height_px'=>(int)$r['canary_before']['height'],
      'ssr_width_px'=>(int)$r['base_before']['width'],
      'reserved_width_px'=>(int)$r['canary_before']['width'],
      'max_cls'=>(float)$r['cls_canary'],
      'ssr_content_sha256'=>$r['content_hash'],
      'ajax_content_sha256'=>$r['content_hash'],
      'ssr_links_sha256'=>$r['link_hash'],
      'ajax_links_sha256'=>$r['link_hash']
    ];
   }
   $height=(int)$first['base_before']['height'];
   $key=$listing.'|'.$locale.'|'.$band;
   $entry=$registry['entries'][$key]??[];
   if(is_array($entry)&&isset($entry['ajax_parity_by_market'][$market])){
    $oldProof=$entry['ajax_parity_by_market'][$market];
    if(($oldProof['status']??'')==='pass'
      &&($oldProof['query_generation']??'')===$cohort['query_generation']
      &&(int)($oldProof['audited_at']??0)>$stamp-4*DAY_IN_SECONDS){
       echo "SKIP: healthy current certificate remains valid\n";
       return;
    }
   }
   if(!is_array($entry))$entry=[];
   $entry=array_merge($entry,[
      'listing_id'=>$listing,'locale'=>$locale,'device'=>$band,
      'status'=>'pass','listing_source_sha256'=>$cohort['listing_sha256'],
      'listing_css_sha256'=>$cohort['listing_css_sha256'],
      'sampled_widths'=>$test,'height_px'=>$height,
      'observed_utc'=>gmdate('c',$stamp),
      'evidence'=>['source'=>'MAD4B dynamic native signed Chromium SSR/AJAX proof']
   ]);
   $proof=[
      'contract'=>'mad4b.native-listing.ajax-parity.v2',
      'status'=>'pass','query_id'=>$query,'document_id'=>$doc,
      'document_sha256'=>$cohort['document_sha256'],
      'query_generation'=>$cohort['query_generation'],
      'market'=>$market,'locale'=>$locale,
      'signed_ajax_verified'=>true,'semantic_output_equivalent'=>true,
      'max_cls'=>$max,'audited_at'=>$stamp,
      'ssr_items'=>(int)$first['baseline_count'],
      'ajax_items'=>(int)$first['ajax_count'],
      'ssr_content_sha256'=>$first['content_hash'],
      'ajax_content_sha256'=>$first['content_hash'],
      'ssr_links_sha256'=>$first['link_hash'],
      'ajax_links_sha256'=>$first['link_hash'],
      'tested_widths'=>$test,'viewport_evidence'=>$evidence,
      'browser_evidence_sha256'=>hash('sha256',(string)file_get_contents($reportFile)),
      'renewed_by'=>'mad4b.native-lazy.auto-coverage.v1'
   ];
   $entry['ajax_parity_by_market'][$market]=$proof;
   $registry['entries'][$key]=$entry;
  }
  if(mad4b_render_query_generation_for_id($query)!==$cohort['query_generation']
    ||(int)get_option('mad4b_css_ai_epoch_v1',-1)!==$nextEpoch
    ||hash_file('sha256',$style)!==$cohort['listing_css_sha256'])
   throw new RuntimeException('Last moment query/CSS source changed');
  $new=maybe_serialize($registry);
  $updated=$wpdb->query($wpdb->prepare(
   "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",
   $new,$opt,$old));
  if($updated!==1)throw new RuntimeException('CAS failure or simultaneous proof update');
  wp_cache_delete($opt,'options');
  wp_cache_delete('alloptions','options');
  wp_cache_delete('notoptions','options');
  if(!mad4b_secondary_grid_lazy_ajax_parity_proven($query,$listing,$doc)){
   // The runtime validator is authoritative. If the proof format or any
   // live fingerprint disagrees, restore the exact previous registry only
   // when nobody has modified the just-written value in the meantime.
   $undo=$wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",
    $old,$opt,$new));
   wp_cache_delete($opt,'options');
   wp_cache_delete('alloptions','options');
   wp_cache_delete('notoptions','options');
   throw new RuntimeException($undo===1
    ?'Updated proof failed runtime readback and was rolled back'
    :'Updated proof failed runtime readback; concurrent change requires investigation');
  }
  echo "PASS newly enrolled locale=$locale market=$market query=$query listing=$listing document=$doc\n";
 }finally{
  $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
 }
}finally{
 if($origin!=='')do_action('wpml_switch_language',$origin);
}
