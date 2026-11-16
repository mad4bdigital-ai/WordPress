<?php
/**
 * Isolated release compatibility fixture, without WordPress boot or writes.
 * Evaluates the newer original first-party CSS engine's private variant gate.
 */
declare(strict_types=1);
define('ABSPATH','/dev/null');
// WordPress ships the PHP 8 string helpers on its supported older runtimes.
// Hermetic PHP 7.4 tests supply those WordPress core polyfills explicitly.
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle==='' || strpos($haystack,$needle)!==false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return substr($haystack,0,strlen($needle))===$needle;
    }
}

function add_action(...$args): bool { return true; }
function wp_parse_url(string $url,int $component=-1) {
    return parse_url($url,$component);
}
function get_queried_object_id(): int { return 42; }
function get_option(string $name,$default=null) {
    return $name==='mad4b_css_ai_epoch_v1' ? 27 : $default;
}
if ($argc!==2 || !is_file($argv[1])) { fwrite(STDERR,"SOURCE_REQUIRED\n");exit(2); }
require $argv[1];
$method=new ReflectionMethod('MAD4B_Verified_Critical_CSS_V1','verified_variant');
if (PHP_VERSION_ID<80100) $method->setAccessible(true);
$tests=0;
function expect(bool $result,string $name): void {
    global $tests;
    $tests++;
    if (!$result) {fwrite(STDERR,"FAILED:".$name."\n");exit(3);}
}
$source='new-bundle.css';
$buffer='<html><body><div class="elementor-100 elementor-200">Hello</div></body></html>';
$meta=[
    'gate_status'=>'pass','route_path'=>'/','language'=>'en',
    'device'=>'mobile','page_id'=>42,'css_epoch'=>27,
    'elementor_doc_fingerprint'=>hash('sha256','100,200'),
    'evidence'=>['decision'=>'passed_evidence','requires_city_preload_deferred'=>true]
];
$manifest=[
 'version'=>2,'contract'=>'mad4b.mobile-critical-css.v2',
 'entries'=>[]];
$entry=['bundle_file'=>$source,'variants'=>['/|en|mobile'=>$meta]];
$_SERVER['REQUEST_URI']='/';
$invoke=static fn($m,$e,$file,$body,$lang)=>$method->invoke(null,$m,$e,$file,$body,$lang);
expect($invoke($manifest,$entry,$source,$buffer,'en')===$meta,'v2_good');
expect($invoke($manifest,$entry,'wrong-bundle.css',$buffer,'en')===null,'v2_bundle_identity');
expect($invoke($manifest,$entry,$source,$buffer,'es')===null,'v2_locale');
$_SERVER['REQUEST_URI']='/es/';
expect($invoke($manifest,$entry,$source,$buffer,'en')===null,'v2_route');
$_SERVER['REQUEST_URI']='/';
$bad=$entry;$bad['variants']['/|en|mobile']['css_epoch']=26;
expect($invoke($manifest,$bad,$source,$buffer,'en')===null,'v2_css_epoch');
$bad=$entry;$bad['variants']['/|en|mobile']['page_id']=99;
expect($invoke($manifest,$bad,$source,$buffer,'en')===null,'v2_page_id');
expect($invoke($manifest,$entry,$source,'<html><body>no template</body></html>','en')===null,'v2_elementor_document_graph');
expect($invoke($manifest,$entry,$source,$buffer.'<link data-mad4b-first-city="1">','en')===null,'v2_city_preload_veto');
$legacy=['version'=>1];
$legacyRow=['gate_status'=>'pass','languages'=>['en'],'devices'=>['mobile']];
expect($invoke($legacy,$legacyRow,$source,$buffer,'en')===$legacyRow,'v1_legacy_compatible');
expect($invoke($legacy,$legacyRow,$source,$buffer,'es')===null,'v1_locale_veto');
$legacyRow['gate_status']='hold';
expect($invoke($legacy,$legacyRow,$source,$buffer,'en')===null,'v1_invalid_gate_denied');
echo json_encode(['contract'=>'mad4b.css-v1-v2-native-compatibility.v1',
 'tests'=>$tests,'passed'=>$tests,'runtime_mutations'=>false,
 'signed_release'=>false],JSON_UNESCAPED_SLASHES),"\n";
