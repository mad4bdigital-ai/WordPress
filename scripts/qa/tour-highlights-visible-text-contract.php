<?php
/**
 * Read-only verification for the existing MAD4B Tour Highlights presenter.
 * The visible text contract must tolerate UTF-8 and HTML whitespace without
 * changing editorially authored highlights, destinations or WPML relations.
 */
defined('ABSPATH')||exit;
if(!defined('WP_CLI')||!WP_CLI||
 !class_exists('MAD4B_Tour_Highlights_Presentation_V1',false)){
 WP_CLI::error('Original native highlights presenter unavailable');
}
$klass='MAD4B_Tour_Highlights_Presentation_V1';
$fixtures=[
 ['<p>&nbsp;</p>',0],
 ['<p>&#160;</p>',0],
 ['<p>&#xA0;</p>',0],
 ["<p>\u{00A0}</p>",0],
 ["<p>\u{200B}\u{FEFF}</p>",0],
 ["<p>\u{3000}</p>",0],
 ['<p><br /></p>',0],
 ["<p>Temple</p>\n<p>&nbsp;</p>",1],
 ["<p><strong>Egypt</strong></p>\n<p>&nbsp;</p><p>Visit Luxor</p>",2],
 ["Giza Pyramids\n\u{00A0}\nGreat Sphinx",2],
 ["<ul><li>&nbsp;</li><li>Pyramids</li></ul>",1],
];
foreach($fixtures as [$input,$expected]){
 $cards=$klass::cards($input);
 if(count($cards)!==$expected)
  WP_CLI::error('HTML/Unicode whitespace regression: '.$input);
 if(in_array('hl-e3b0c44298fc1c149afb',$klass::card_keys($cards),true))
  WP_CLI::error('Empty stable key allowed into display');
 if($expected===0&&$klass::render($input)!=='')
  WP_CLI::error('Formatting-only markup leaked into rendered output');
}
global $wpdb;
$meta=$wpdb->get_results($wpdb->prepare(
 "SELECT p.ID,pm.meta_key,pm.meta_value FROM {$wpdb->posts} p
  INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
  WHERE p.post_type=%s AND p.post_status IN (%s,%s)
   AND pm.meta_key IN (%s,%s)",
 'tours-and-activities','publish','draft','highlight_rows','tour_highlights_content'),ARRAY_A);
$scanned=0;$violations=[];$affected=0;
foreach((array)$meta as $row) {
 if(!is_string($row['meta_value'])||$row['meta_value']==='')continue;
 $cards=$klass::cards($row['meta_value']);
 $keys=$klass::card_keys($cards);
 $scanned++;
 if(in_array('hl-e3b0c44298fc1c149afb',$keys,true))
   $violations[]=(int)$row['ID'];
 if((int)$row['ID']===60439){
   $affected++;
   $extra=$klass::cards($row['meta_value']."\n<p>&nbsp;</p>");
   if(count($cards)!==17||$cards!==$extra||
       $keys!==$klass::card_keys($extra))
     WP_CLI::error('17 approved Highlights or existing stable keys changed');
 }
}
if($violations)WP_CLI::error('Blank cards in saved Tours: '.implode(',',array_unique($violations)));
if($affected!==2)WP_CLI::error('Regression Tour source fields not independently readable');
WP_CLI::success(wp_json_encode([
 'site'=>home_url(),'fixtures'=>count($fixtures),
 'source_fields_compared'=>$scanned,'approved_tour_card_count'=>17,
 'verified_saved_fields'=>$affected,'blank_card_count'=>0,
 'stable_keys_unchanged_when_whitespace_appended'=>true,
 'original_source_not_modified'=>true],JSON_UNESCAPED_SLASHES));
