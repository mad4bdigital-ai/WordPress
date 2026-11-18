<?php
// Isolated regression: no WP boot, Drive calls, Context mutation, or secrets.
error_reporting(E_ALL);
if(!defined('ABSPATH'))define('ABSPATH','/isolated/');
if(!class_exists('WP_Error')){class WP_Error {private $code;public function __construct($code,$msg=''){$this->code=$code;}public function get_error_code(){return $this->code;}}}
if(!function_exists('is_wp_error')){function is_wp_error($v){return $v instanceof WP_Error;}}
if(!function_exists('wp_check_invalid_utf8')){function wp_check_invalid_utf8($v,$strip=false){return (string)$v;}}
if(!function_exists('sanitize_key')){function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}}
if(!function_exists('wp_json_encode')){function wp_json_encode($v,$options=0){return json_encode($v,$options);}}
if(count($argv)===1){
 $plugin=dirname(__DIR__,2);
 $argv=array($argv[0],
  $plugin.'/includes/class-mad4b-scp-google-drive-context.php',
  $plugin.'/includes/class-mad4b-scp-drive-scan-continuation.php',
  $plugin.'/includes/class-mad4b-scp-brand-context-builder.php');
}
if(count($argv)!==4){fwrite(STDERR,"usage: php isolated-contract.php drive.php continuation.php builder.php\n");exit(2);}
foreach(array_slice($argv,1) as $p){if(!is_file($p)){fwrite(STDERR,"missing module\n");exit(2);}require_once $p;}
function check($ok,$label){echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)exit(3);}
$dr=new ReflectionMethod('MAD4B_SCP_Google_Drive_Context','normalize_binary_content');
$blank='<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40"><path d="M0 0"/></svg>';
$withText='<svg xmlns="http://www.w3.org/2000/svg"><text>Wayfa</text><path d="M0 0"/></svg>';
foreach([['blank SVG',$blank],['text SVG',$withText]] as [$label,$svg]){
 $r=$dr->invoke(null,'image/svg+xml',$svg,'wordmark.svg');
 check(is_array($r)&&empty($r['complete'])&&($r['normalization_reason']??'')==='visual_media_extraction_required',$label.' is visually deferred, not source-authoritative text');
}
$html=$dr->invoke(null,'text/html','<main><h1>Editorial guidance</h1></main>','index.html');
check(!empty($html['complete'])&&str_contains($html['content'],'Editorial guidance'),'HTML still indexes as real text');
$native=new ReflectionMethod('MAD4B_SCP_Google_Drive_Context','normalize_native_download_bytes');
$nativeSvg=$native->invoke(null,$withText);
check(is_array($nativeSvg)&&empty($nativeSvg['complete'])&&($nativeSvg['normalization_reason']??'')==='visual_media_extraction_required','SVG markup under generic Google native download is deferred');
$png=$dr->invoke(null,'image/png','\x89PNG','sample.png');
check(empty($png['complete']),'unextracted PNG remains deferred');
$c=new ReflectionMethod('MAD4B_SCP_Drive_Scan_Continuation','quarantine_legacy_visual_assets');
$one=['file_id'=>'svg-empty','title'=>'wordmark.svg','mimeType'=>'image/svg+xml','normalization_reason'=>'markup_local','content_complete'=>true,'content_hash'=>hash('sha256','')];
$two=['file_id'=>'svg-nonempty','title'=>'caption.svg','mimeType'=>'image/svg+xml','normalization_reason'=>'markup_local','content_complete'=>true,'content_hash'=>hash('sha256','Wayfa')];
$three=['file_id'=>'drawing','title'=>'drawing','mimeType'=>'application/vnd.google-apps.drawing','normalization_reason'=>'google_drawing_svg_export','content_complete'=>true,'content_hash'=>hash('sha256','')];
$doc=['file_id'=>'doc','title'=>'Brand Core','mimeType'=>'application/vnd.google-apps.document','normalization_reason'=>'google_doc_export','content_complete'=>true,'content_hash'=>hash('sha256','approved content')];
$extract=['file_id'=>'extracted-svg','title'=>'verified SVG','mimeType'=>'image/svg+xml','normalization_reason'=>'gemini_multimodal','content_complete'=>true,'content_hash'=>hash('sha256','verified extracted text')];
$state=['assets'=>[$one,$two,$three,$doc,$extract,'invalid-raw'], 'deferred'=>[], 'pending'=>[], 'queue'=>[]];
$fixed=$c->invoke(null,$state);
check(count($fixed['assets'])===3&&count($fixed['deferred'])===3,'legacy SVG/drawing quarantined without dropping verified docs or malformed records');
check(in_array($doc,$fixed['assets'],true)&&in_array($extract,$fixed['assets'],true),'genuinely extracted content hash retained');
foreach($fixed['deferred'] as $row)check($row['status']==='deferred_review_required'&&!$row['content_complete']&&$row['normalization_reason']==='visual_media_extraction_required','quarantined record cannot self-approve');
$repeat=$c->invoke(null,$fixed);
check($repeat===$fixed,'quarantine is idempotent with stable identities');
$guard=new ReflectionMethod('MAD4B_SCP_Brand_Context_Builder','legacy_visual_scan_asset');
check($guard->invoke(null,$one)&&$guard->invoke(null,$three)&&!$guard->invoke(null,$doc)&&!$guard->invoke(null,$extract),'apply admission recognizes only unverified legacy visual markup');
$admission=MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission(['scan_complete'=>true],['complete'=>true,'truncated'=>false,'partial_scan_requires_complete_rescan'=>false,'truncation_reasons'=>[],'assets'=>[$one]]);
check(is_wp_error($admission)&&$admission->get_error_code()==='mad4b_context_source_scan_asset_unverified','legacy empty-hash SVG rejected by full apply');
$admission=MAD4B_SCP_Brand_Context_Builder::source_scan_apply_admission(['scan_complete'=>true],['complete'=>true,'truncated'=>false,'partial_scan_requires_complete_rescan'=>false,'truncation_reasons'=>[],'assets'=>[$doc,$extract]]);
check($admission===true,'fully normalized unique docs and extractor-backed media still accepted');
echo "ALL_MEDIA_INTEGRITY_CONTRACTS_PASS\n";
