<?php
/** Run read-only via WP-CLI; compare original live resolver to candidate. */
if(!defined('WP_CLI')||!WP_CLI||!class_exists('MAD4B_Gallery_Resolution_Engine_V1'))
    throw new RuntimeException('WP CLI original engine required');
$source=getenv('MAD4B_RESOLVER_CANDIDATE_SOURCE')?:'/tmp/mad4b-gallery-resolver-v11-candidate.php';
$php=file_get_contents($source);$start=strpos($php,'final class MAD4B_Gallery_Resolution_Engine_V1 {');$end=strpos($php,"\nadd_action('add_attachment'");
if($start===false||$end===false||$end<=$start)throw new RuntimeException('candidate source structure changed');
$class=str_replace(['MAD4B_Gallery_Resolution_Engine_V1',"const CACHE_GROUP='mad4b_gallery_resolution';"],['MAD4B_Gallery_Resolution_Engine_Candidate_V11',"const CACHE_GROUP='mad4b_gallery_resolution_v11_contract_test';"],substr($php,$start,$end-$start));
eval($class);
$c='MAD4B_Gallery_Resolution_Engine_Candidate_V11';
function verify($ok,$msg){if(!$ok)throw new RuntimeException('FAILED:'.$msg);echo 'PASS '.$msg."\n";}
foreach([17030,16992] as $root){
 $family=wp_get_attachment_metadata($root);$file=get_attached_file($root);verify(is_file($file),'physical_root_'.$root);
 foreach(['medium_large','medium','thumbnail'] as $size){
  $source=wp_get_attachment_image_src($root,$size);if(!is_array($source))continue;
  $url=$source[0];$subfile=dirname($file).'/'.($family['sizes'][$size]['file']??'');
  if(!is_file($subfile))continue;
  $record=$c::inspect($url,60439);
  verify((int)($record['render_value']??0)===$root && (int)($record['unresolved']??1)===0,'signed_wp_size_'.$root.'_'.$size);
  verify(!empty(MAD4B_Media_Visual_Lineage_V1::origin($root)['verified']),'root_lineage_sha_verified_'.$root.'_'.$size);
 }
}
$traversal=$c::inspect('https://wayfatours.com/wp-content/uploads/2025/10/../secrets-768x576.jpg',60439);
verify(($traversal['unresolved']??0)===1,'traversal_source_fails_closed');
$remote=$c::inspect('https://other.invalid/wp-content/uploads/2025/10/unknown-768x576.jpg',60439);
verify(($remote['unresolved']??0)===1,'foreign_source_unknown_fails_closed');
$negative=$c::inspect('https://wayfatours.com/wp-content/uploads/2025/10/boat-ride-in-the-nile-passing-by-philae-temple-aswan-egypt-v0-4l5oswwsqrw81Philae-Temple-High-Dam-Unfinished-Obelisk-–-Aswan-Highlights-1-Day-Tour-767x576.jpg',60439);
verify(($negative['unresolved']??0)===1,'similar_filename_unregistered_variant_rejected');
echo '__RESOLVER_V11_LIVE_CANDIDATE__'.wp_json_encode(['status'=>'pass','existing_sources_unmodified'=>true,'attachment_meta_unmodified'=>true])."\n";
