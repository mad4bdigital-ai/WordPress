<?php
/**
 * LOCAL-ONLY real model test with public country data (countries.dev).
 * This performs zero owner-domain mutations and issues zero approvals.
 * Run via an explicit CLI entrypoint in an enrolled disposable Local Lab.
 */
if ( PHP_SAPI !== 'cli' ) exit( 2 );
$_SERVER['HTTP_HOST']='localhost:8091';
require '/var/www/html/wp-load.php';
if ( ! function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'local'
     || !class_exists('MAD4B_SCP_AI_Model_Router')
     || !class_exists('MAD4B_SCP_AI_Domain_Executors')
     || !class_exists('MAD4B_SCP_AI_Review_Worker') ) exit( 3 );
$original=get_option(MAD4B_SCP_AI_Model_Router::OPTION,array());
$mode=MAD4B_SCP_AI_Model_Router::provider_status();
if (($mode['mode']??'')!=='local_ollama' ||empty($mode['enabled']) ||
    ($mode['model']??'')!==MAD4B_SCP_AI_Model_Router::LOCAL_OLLAMA_MODEL ) exit(4);
$source_file='/tmp/mad4b-free-eg-country-20261025.json';
$bytes=is_file($source_file)?file_get_contents($source_file):'';
if (strlen($bytes)<200 ||strlen($bytes)>10000)exit(5);
$c=json_decode($bytes,true);
if (!is_array($c) ||($c['alpha2Code']??'')!=='EG'
    ||($c['alpha3Code']??'')!=='EGY'
    ||($c['capital']??'')!=='Cairo') exit(6);
$currency=(string)($c['currencies'][0]['code']??'');
$lang=(string)($c['languages'][0]['iso639_1']??'');
if($currency!=='EGP'||$lang!=='ar')exit(7);
$source_hash=hash('sha256',$bytes);
$owners=array(
 'content_job'=>array('MAD4B_SCP_Content_Jobs','get_job',
     'Review factual evidence for an editorial draft; do not create a content job or approve publication.'),
 'product_catalog'=>array('MAD4B_SCP_Catalog_Object_Store','status',
     'Review country/currency catalog example; no tour price, availability, inventory or product exists in this public data.'),
 'seo_metadata'=>array('MAD4B_SCP_G5_SEO_Provider_Families','catalog',
     'Review an example SEO description; do not claim Rank Math, hreflang, publishing or indexing was tested.'),
 'localization'=>array('MAD4B_SCP_Translation_Bridge_Adapter','register_with_registry',
     'Review Arabic language code and Egypt name; do not claim a WPML translation was saved.'),
 'design_template'=>array('MAD4B_SCP_Browser_Acceptance_Core','plan',
     'Review a design-template content example; no Elementor page render or visual browser acceptance exists.'),
);
foreach($owners as $domain=>$owner) {
 if (!class_exists($owner[0]) || !method_exists($owner[0],$owner[1])) {
    fwrite(STDERR,"OWNER_UNAVAILABLE:$domain\n"); exit(8);
 }
}
$results=array();
foreach($owners as $domain=>$owner) {
 $lines=array(
  'Evidence source: https://countries.dev/alpha/EG',
  'Source sha256: '.$source_hash,
  'Country common name: '.(string)$c['name'].'; ISO alpha2 EG; alpha3 EGY.',
  'Capital: Cairo; region: '.(string)$c['region'].'.',
  'Currency ISO: EGP; language ISO: ar; native language: '.(string)$c['languages'][0]['nativeName'].'.',
  'Owning MAD4B class: '.$owner[0].'; exact owner method: '.$owner[1].'.',
  'Task: '.$owner[2],
  'Disclaimer: this is public-data test evidence, not an authorized owner approval or site content publication.',
 );
 $text=implode("\n",$lines);
 $instruction="You are a strict QA reviewer evaluating an UNTRUSTED, public-data LAB TEST. Domain: $domain. ".
   "Examine whether supplied facts and domain caveats are internally supported. ".
   "Do not invent citations, prices, live availability, privileged grants, or publication receipts. ".
   "Return ONLY a JSON object with these fields: ".
   "decision: approve or needs_changes; confidence: a number from 0 to 1; ".
   "review_note: a specific complete sentence of 45 to 180 characters; ".
   "evidence_lines: exactly two distinct integers between 1 and 8. ".
   "Choose needs_changes with confidence 0.65 if unsure. ".
   "Approving this LAB EVIDENCE does not approve anything in WordPress.\n".
   "Evidence:\n".$text;
 $attempts=0;$verdict=null;$faults=array();
 while($attempts<2) {
  $attempts++;
  $raw=MAD4B_SCP_AI_Model_Router::generate_text($instruction,array('response_format'=>'review_verdict_v1'));
  if(is_wp_error($raw)) {
   $faults[]=$raw->get_error_code();break;
  }
  $verdict=MAD4B_SCP_AI_Review_Worker::parse_verdict($raw,array('normalized_text'=>$text));
  if(!is_wp_error($verdict))break;
  $faults[]=$verdict->get_error_code();
  $instruction="Correct your previous malformed answer. Only a strict JSON object; ".
   "decision=needs_changes, confidence=0.65, review_note at least 45 characters, evidence_lines=[3,5]. ".
   "No Markdown fences. Cite only the supplied facts.\n".$text;
 }
 $valid=is_array($verdict);
 $results[$domain]=array(
    'owner_class'=>$owner[0],'owner_method_declared'=>$owner[1],
    'public_source_sha256'=>$source_hash,'evidence_sha256'=>hash('sha256',$text),
    'model'=>'qwen2.5:1.5b','inference_attempts'=>$attempts,
    'json_verdict_valid'=>$valid,'decision'=>$valid?$verdict['decision']:null,
    'evidence_lines'=>$valid?$verdict['evidence_lines']:array(),
    'review_note'=>$valid?$verdict['review_note']:null,'error_codes'=>$faults,
    'native_owner_review_submitted'=>false,'original_owner_receipt_verified'=>false,
    'content_mutated'=>false,'production_authorized'=>false
 );
 echo "$domain: ".($valid?'VALID_MODEL_EVIDENCE_ONLY':'FAILED_MODEL_VERDICT')."\n";
}
$final=array(
 'contract'=>'mad4b.ai-five-free-model-lab-test.v1',
 'site_environment'=>'local','source_url'=>'https://countries.dev/alpha/EG',
 'source_sha256'=>$source_hash,'model'=>'qwen2.5:1.5b',
 'reviewer'=>'open_local_model_unprivileged_lab','domains'=>$results,
 'owner_review_state'=>'not_submitted; requires original owner adapters and grants',
 'files_published'=>0,'grants_issued'=>0,'production_mutated'=>false);
$json=wp_json_encode($final,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$out='/tmp/mad4b-ai-five-free-model-report-20261025.json';
file_put_contents($out,$json,LOCK_EX);
echo 'REPORT='.$out."\n";
echo 'VALID='.count(array_filter($results,static function($v){return $v['json_verdict_valid'];})).'/5'."\n";
exit(count(array_filter($results,static function($v){return $v['json_verdict_valid'];}))===5?0:9);
