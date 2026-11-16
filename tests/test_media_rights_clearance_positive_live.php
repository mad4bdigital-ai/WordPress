<?php
if(!defined('WP_CLI')||!WP_CLI||!class_exists('MAD4B_Media_Rights_Extraction_Intake_V1'))throw new RuntimeException('original rights engine unavailable');
$path=getenv('MAD4B_RIGHTS_CLEARANCE_CANDIDATE')?:'/tmp/mad4b-rights-180-candidate.php';$src=file_get_contents($path);
$first=strpos($src,'final class MAD4B_Media_Rights_Extraction_Intake_V1 {');$end=strpos($src,"\nadd_action('save_post_tours-and-activities'");if($first===false||$end==false||$end<=$first)throw new RuntimeException('rights candidate class boundary changed');
$source=str_replace('MAD4B_Media_Rights_Extraction_Intake_V1','MAD4B_Media_Rights_Intake_Candidate_V180',substr($src,$first,$end-$first));eval($source);
$engine='MAD4B_Media_Rights_Intake_Candidate_V180';
function must($yes,$label){if(!$yes)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
foreach([70016,70019] as $id){$r=$engine::published_clearance($id);must(($r['cleared']??false)===true,'published_licensed_owner_'.$id);must((int)($r['published_credit_id']??0)>0,'original_credit_verified_'.$id);must(($r['cct_mutations']??true)===false,'no_CCT_writes_'.$id);}
foreach([17030,16992,69756] as $id){$r=$engine::published_clearance($id);must(($r['cleared']??true)===false,'unlicensed_original_or_derivative_held_'.$id);}
foreach([70017,70020] as $id){$r=$engine::published_clearance($id);echo '__WPML_LOCAL_LICENSE_DECISION__'.wp_json_encode(['attachment_id'=>$id,'cleared'=>$r['cleared']??false,'reason'=>$r['reason']??null,'licensed_owner'=>$r['licensed_origin_attachment_id']??null])."\n";}
echo '__RIGHTS_INDEPENDENT_CLEARANCE_VALIDATED__'.wp_json_encode(['source_status'=>'read_only_candidate','positive'=>2,'negative'=>3,'writes'=>false])."\n";
