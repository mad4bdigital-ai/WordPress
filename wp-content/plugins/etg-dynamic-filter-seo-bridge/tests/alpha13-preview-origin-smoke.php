<?php
declare(strict_types=1);

function etg_preview_expect($condition,string$message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
if(!function_exists('wp_parse_url')){function wp_parse_url($url){return parse_url($url);}}
if(!function_exists('home_url')){function home_url($path=''){return'https://example.test'.('/'===substr((string)$path,0,1)?$path:'/'.$path);}}

$root=dirname(__DIR__);
require_once $root.'/includes/Bootstrap.php';

$reflection=new ReflectionClass(\ETG\DynamicFilterSEOBridge\Bootstrap::class);
$bootstrap=$reflection->newInstanceWithoutConstructor();
$builderProperty=$reflection->getProperty('builder');$builderProperty->setAccessible(true);
$builder=new class{public $uris=array();public function buildEvidence($uri){$this->uris[]=$uri;return array('uri'=>$uri,'evidence_only'=>true);}};
$builderProperty->setValue($bootstrap,$builder);
$method=$reflection->getMethod('previewEvidenceContext');$method->setAccessible(true);

$call=function(string$url)use($method,$bootstrap){return$method->invoke($bootstrap,$url);};

$r=$call('/tours-and-activities/jsf/jet-engine:myGrid/tax/location_jet:cairo/?foo=bar#ignored');
etg_preview_expect(($r['uri']??'')==='/tours-and-activities/jsf/jet-engine:myGrid/tax/location_jet:cairo/?foo=bar','relative preview URL remains allowed and fragment is excluded');
$r=$call('https://example.test/tours/?q=1');
etg_preview_expect(($r['uri']??'')==='/tours/?q=1','same-origin absolute URL allowed');
$r=$call('https://example.test:443/tours/');
etg_preview_expect(($r['uri']??'')==='/tours/','explicit default HTTPS port allowed');
$r=$call('https://EXAMPLE.TEST/tours/');
etg_preview_expect(($r['uri']??'')==='/tours/','host comparison is case-insensitive');

foreach(array(
    '//example.test/tours/',
    'http://example.test/tours/',
    'https://example.test:444/tours/',
    'https://user@example.test/tours/',
    'https://user:pass@example.test/tours/',
    'javascript:alert(1)',
    'https://other.test/tours/'
)as$blocked){etg_preview_expect(array()===$call($blocked),'blocked preview origin must fail closed: '.$blocked);}

$source=file_get_contents($root.'/includes/Bootstrap.php');
etg_preview_expect(false!==strpos($source,'previewOriginAllowed'),'preview origin helper missing');
etg_preview_expect(false!==strpos($source,'effectivePort'),'effective-port comparison missing');
etg_preview_expect(false!==strpos($source,'$scheme!==$homeScheme||$host!==$homeHost'),'scheme and host exact-origin guard missing');

echo "Alpha13 exact preview-origin smoke tests passed.\n";
