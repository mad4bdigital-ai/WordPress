<?php
// Read-only audit: coverage of live Journey Highlights vs eligible original Destination taxonomy.
// No writes, no inferred visited places, no extra HTTP or translation service.
defined('ABSPATH')||exit;
global $wpdb;
$max=(int)(getenv('JD_AUDIT_LIMIT')?:500);
$rows=$wpdb->get_results($wpdb->prepare("
 SELECT p.ID, p.post_title, tr.language_code
 FROM {$wpdb->posts} p
 JOIN {$wpdb->prefix}icl_translations tr
  ON tr.element_id=p.ID AND tr.element_type='post_tours-and-activities'
 WHERE p.post_type='tours-and-activities' AND p.post_status='publish'
 ORDER BY p.ID ASC LIMIT %d",$max),ARRAY_A);
if(!class_exists('MAD4B_Tour_Highlights_V1',false)
 || !class_exists('MAD4B_Tour_Highlights_Presentation_V1',false))WP_CLI::error('Original renderer missing');
$method=new ReflectionMethod('MAD4B_Tour_Highlights_Presentation_V1','related_links');
$arity=$method->getNumberOfParameters();
// A long mixed-locale scan is invalid if another agent replaces live PHP
// during the scan; never compare changing policy populations as alias gains.
$sourceFiles=[
    (new ReflectionClass('MAD4B_Tour_Highlights_V1'))->getFileName(),
    (new ReflectionClass('MAD4B_Tour_Highlights_Presentation_V1'))->getFileName()
];
$sourceHashes=array_map('hash_file',array_fill(0,count($sourceFiles),'sha256'),$sourceFiles);
$output=['site'=>site_url(),'mode'=>'read-only','renderer_arity'=>$arity,
  'audit_contract_version'=>2,'source_files'=>array_map('basename',$sourceFiles),
  'source_sha256'=>$sourceHashes,'tours'=>[],'counts'=>[]];
$summary=['tours'=>0,'cards'=>0,'cards_with_any_verified_link'=>0,
   'verified_links'=>0,'direct_editorial_mentions'=>0,'itinerary_companions'=>0,
   'explicit_editorial_bindings'=>0,'geographic_context_links'=>0,
   'eligible_places'=>0,'assigned_places'=>0,'inferred_geographic_places'=>0,
   'unlinked_place_mentions'=>0,'cards_with_no_places'=>0,'language_mismatch_count'=>0];
$currentLang=(string)apply_filters('wpml_current_language',null);
foreach($rows as $row){
 $id=(int)$row['ID'];$lang=(string)$row['language_code'];
 if($lang!=='' && (string)apply_filters('wpml_current_language',null)!==$lang)
     do_action('wpml_switch_language',$lang);
 if($lang!=='' && (string)apply_filters('wpml_current_language',null)!==$lang)
     WP_CLI::error('WPML context change failed for tour '.$id);
 $places=array_values(array_filter(
    MAD4B_Tour_Highlights_V1::related_places($id),
    static fn($p)=>in_array($p['kind'],['place_attraction','city','zone_area','state_governorate'],true)
 ));
 // Independent WordPress taxonomy-language readback. The renderer's
 // response is not proof of matching locale on its own.
 foreach($places as $place){
    $term=(int)$place['id'];
    $ttid=$wpdb->get_var($wpdb->prepare(
      "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='destinations' AND term_id=%d LIMIT 1",$term));
    $actual=$wpdb->get_var($wpdb->prepare(
      "SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='tax_destinations' AND element_id=%d LIMIT 1",(int)$ttid));
    if($lang!=='' && $actual!==$lang){
        $summary['language_mismatch_count']++;
        WP_CLI::error('Cross-locale Term leaked into tour '.$id.' ('.$lang.') term '.$term.' ('.$actual.')');
    }
    if(($place['evidence']??'assigned')==='exact-highlight-mention')
        $summary['inferred_geographic_places']++;
    else $summary['assigned_places']++;
 }
 $html=(string)get_post_meta($id,'tour_highlights_content',true);
 if(trim(wp_strip_all_tags($html))==='')continue;
 $cards=MAD4B_Tour_Highlights_Presentation_V1::cards($html);
 if(!$cards)continue;
 $record=['post_id'=>$id,'lang'=>$lang,'title'=>$row['post_title'],'places'=>$places,
   'cards'=>[],'mode'=>(string)get_post_meta($id,'tour_highlights_mode',true)];
 foreach($cards as $idx=>$card){
    $args=[$card,$places];
    if($arity>=4)$args=array_merge($args,[$idx+1,$id]);
    $links=$method->invokeArgs(null,$args);
    preg_match_all('/data-tour-term-id="(\d+)"[^>]*data-tour-match="([^"]+)"/',$links,$matches,PREG_SET_ORDER);
    // The current source order is evidence-match before term-id, so also scan
    // each individual link independently of attribute order.
    preg_match_all('/<a\b[^>]+class="tour-hl-ui__place"[^>]*>/u',$links,$tags);
    $matched=[];
    foreach($tags[0]??[] as $tag){
        if(preg_match('/data-tour-term-id="(\d+)"/',$tag,$m)){
            $quality=preg_match('/data-tour-match="([^"]+)"/',$tag,$q)?$q[1]:'unknown';
            $source=preg_match('/data-tour-evidence="([^"]+)"/',$tag,$ev)?$ev[1]:'mentioned';
            $place=array_values(array_filter($places,static fn($p)=>(int)$p['id']===(int)$m[1]));
            $kind=$place?(string)$place[0]['kind']:'unknown';
            $origin=$place?(string)($place[0]['evidence']??'assigned'):'unknown';
            if($source==='itinerary')$summary['itinerary_companions']++;
            elseif($quality==='explicit')$summary['explicit_editorial_bindings']++;
            elseif(in_array($kind,['country','state_governorate','city','zone_area'],true)
              || $origin==='exact-highlight-mention')$summary['geographic_context_links']++;
            else $summary['direct_editorial_mentions']++;
            $matched[]=['id'=>(int)$m[1],'evidence'=>$quality,
                'source'=>$source,'kind'=>$kind,'eligibility_origin'=>$origin];
        }
    }
    $text=trim(html_entity_decode(wp_strip_all_tags(($card['title']??'').' '.($card['body']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $record['cards'][]=['index'=>$idx+1,'text'=>mb_substr($text,0,350,'UTF-8'),'matched'=>$matched];
    $summary['cards']++;
    $summary['verified_links']+=count($matched);
    if($matched)$summary['cards_with_any_verified_link']++;
    elseif($places)$summary['cards_with_no_places']++;
 }
 $output['tours'][]=$record;
 $summary['tours']++;$summary['eligible_places']+=count($places);
}
if($currentLang!=='')do_action('wpml_switch_language',$currentLang);
$afterHashes=array_map('hash_file',array_fill(0,count($sourceFiles),'sha256'),$sourceFiles);
if($afterHashes!==$sourceHashes)
 WP_CLI::error('Live source changed during the audit: rerun against a stable release');
$output['counts']=$summary;
// Fingerprint the approved Terms + provenance separately from translation
// aliases/renderer code. Reports with different eligibility fingerprints
// must NOT attribute all new links to alias enrichment.
$eligibleRows=[];
$editorialRows=[];
foreach($output['tours'] as $tour){
    $places=array_map(static fn($p)=>[(int)$p['id'],(string)$p['kind'],
        (string)($p['evidence']??'assigned')],$tour['places']);
    usort($places,static fn($a,$b)=>$a[0]<=>$b[0]);
    $eligibleRows[]=[(int)$tour['post_id'],(string)$tour['lang'],$places];
    $editorialRows[]=[(int)$tour['post_id'],array_map(
        static fn($c)=>[(int)$c['index'],hash('sha256',(string)$c['text'])],$tour['cards'])];
}
$output['eligibility_fingerprint_sha256']=hash('sha256',(string)wp_json_encode($eligibleRows));
$output['editorial_fingerprint_sha256']=hash('sha256',(string)wp_json_encode($editorialRows));
$filename='/tmp/jd-term-alias-coverage-'.preg_replace('/[^a-z0-9]+/','-',parse_url(site_url(),PHP_URL_HOST)).'.json';
if(file_put_contents($filename,wp_json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))===false)WP_CLI::error('Report file write failed');
WP_CLI::success(wp_json_encode(['path'=>$filename,'counts'=>$summary],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
