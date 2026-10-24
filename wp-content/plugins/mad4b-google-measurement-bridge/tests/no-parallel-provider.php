<?php
/**
 * Static architecture regression gate: this bridge must never own SERP HTTP or
 * provider credentials; Feature 007 owns capture/budget and evidence provenance.
 */
$root=dirname(__DIR__);
$core=dirname($root).'/mad4b-site-control-plane/includes';
$canonical=$core.'/search/adapters/class-mad4b-scp-search-serp-adapters.php';
$runtime=$core.'/class-mad4b-scp-adaptive-search-intelligence.php';
if(!is_file($canonical)||!is_file($runtime)){fwrite(STDERR,"Canonical Feature 007 provider source missing\n");exit(1);}
$php=glob($root.'/includes/*.php');$php[]=$root.'/mad4b-google-measurement-bridge.php';
$forbidden=array(
 '/api\\.dataforseo\\.com/i',
 '/serpapi\\.com/i',
 '/MAD4B_SERP_PROVIDER_(LOGIN|PASSWORD)/',
 '/keyword-serp-provider-live/',
 '/mad4b_serp_paid_request/'
);
foreach($php as $file){
 $body=file_get_contents($file);
 foreach($forbidden as $pattern)if(preg_match($pattern,$body)){
  fwrite(STDERR,"Parallel SERP transport/credential reference in $file\n");exit(1);
 }
}
$bridge=file_get_contents($root.'/includes/class-mad4b-keyword-search-bridge.php');
foreach(array('MAD4B_SCP_Search_Runtime::status','MAD4B_SCP_Search_Runtime::discover','MAD4B_SCP_Search_Runtime::cohort') as $call){
 if(strpos($bridge,$call)===false){fwrite(STDERR,"Missing canonical delegate: $call\n");exit(1);}
}
echo "PASS: no parallel SERP provider, canonical read delegation preserved\n";
