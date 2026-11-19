<?php
/**
 * Hermetic native PHP release fixture for the enrolled runtime candidate.
 * It uses synthetic Elementor breakpoint objects and never boots WordPress.
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

final class MockElementorBreakpoint {
    private $px; public function __construct(int $px=767) {$this->px=$px;}
    public function get_value(): int { return $this->px; }
}
final class MockElementorBreakpoints {
    public $px; public function __construct(int $px=767) {$this->px=$px;}
    public function get_breakpoints(): array {
        return ['mobile'=>new MockElementorBreakpoint($this->px)];
    }
}
final class MockElementorPlugin {
    public static $instance=null;
}
class_alias('MockElementorPlugin','Elementor\\Plugin');
MockElementorPlugin::$instance=(object)['breakpoints'=>new MockElementorBreakpoints()];
function add_action(...$args): bool { return true; }
function wp_parse_url(string $url,int $component=-1) {
    return parse_url($url,$component);
}
function get_queried_object_id(): int { return 42; }
function get_option(string $name,$fallback=null) {
    return $name==='mad4b_css_ai_epoch_v1'?27:$fallback;
}
if ($argc!==2 || !is_file($argv[1])) {fwrite(STDERR,"SOURCE_REQUIRED\n");exit(2);}
require $argv[1];
$ref=new ReflectionMethod('MAD4B_Verified_Critical_CSS_V1','verified_variant');
if (PHP_VERSION_ID<80100) $ref->setAccessible(true);
$check=static fn($m,$e,$file,$body,$locale)=>$ref->invoke(null,$m,$e,$file,$body,$locale);
$count=0;
function verify(bool $condition,string $test): void {
    global $count;
    $count++;
    if (!$condition) {fwrite(STDERR,"FAILED: ".$test."\n");exit(3);}
}
$manifest=['version'=>2,'contract'=>'mad4b.mobile-critical-css.v2'];
$html='<html><body><div class="elementor-100 elementor-200">Visit Egypt</div></body></html>';
$source='abcd.css';
$policy=[
    'route_path'=>'/','language'=>'en','device'=>'mobile',
    'page_id'=>42,'css_epoch'=>27,'gate_status'=>'pass',
    'elementor_doc_fingerprint'=>hash('sha256','100,200'),
    'css_critical_mobile_breakpoint'=>767,
    'evidence'=>[
      'decision'=>'passed_evidence',
      'adaptive_mobile_breakpoint_px'=>767,
      'viewports'=>[320,390,414,767,768]
    ]
];
$row=['bundle_file'=>$source,'variants'=>['/|en|mobile'=>$policy]];
$_SERVER['REQUEST_URI']='/';
verify($check($manifest,$row,$source,$html,'en')===$policy,'responsive native 767 admitted');
$row['variants']['/|en|mobile']['css_critical_mobile_breakpoint']=768;
verify($check($manifest,$row,$source,$html,'en')===null,'different breakpoint rejects');
$row['variants']['/|en|mobile']['css_critical_mobile_breakpoint']='767';
verify($check($manifest,$row,$source,$html,'en')===null,'text breakpoint rejects');
$row['variants']['/|en|mobile']=$policy;
$row['variants']['/|en|mobile']['evidence']['adaptive_mobile_breakpoint_px']=768;
verify($check($manifest,$row,$source,$html,'en')===null,'mismatched QA proof rejects');
$row['variants']['/|en|mobile']=$policy;
$row['variants']['/|en|mobile']['evidence']['viewports']=[320,390,414,767];
verify($check($manifest,$row,$source,$html,'en')===null,'missing 768 QA rejects');
$row['variants']['/|en|mobile']=$policy;
verify($check($manifest,$row,$source,$html,'es')===null,'locale mismatch rejects');
verify($check($manifest,$row,$source,$html.'data-mad4b-first-city="yes"','en')===null,'city competition rejects');
MockElementorPlugin::$instance=(object)['breakpoints'=>new MockElementorBreakpoints(760)];
verify($check($manifest,$row,$source,$html,'en')===null,'native breakpoint drift rejects');
MockElementorPlugin::$instance=(object)['breakpoints'=>new MockElementorBreakpoints(767)];
verify($check($manifest,$row,$source,$html,'en')===$policy,'native restored accepted');
$old=['gate_status'=>'pass','languages'=>['en'],'devices'=>['mobile']];
verify($check(['version'=>1],$old,$source,$html,'en')===$old,'v1 existing unchanged');
$classic=$policy;
unset($classic['css_critical_mobile_breakpoint']);
$row['variants']['/|en|mobile']=$classic;
verify($check($manifest,$row,$source,$html,'en')===$classic,'v2 nonadaptive unchanged');
// Final FastCGI cacheability proof must certify matching adaptive CSS media.
$style=str_repeat('h1{padding:0;margin:0}',850);
$prefix='<html><head></head><body>';
$suffix='<link data-mad4b-css-async-lib="inline-v1"><div>content</div></body></html>';
$crit='<style id="mad4b-critical-css-v1" media="(max-width:767px)">'.$style.'</style>';
$rest='<link id="mad4b-css-rest-v1" rel="stylesheet" href="https://example.com/wp-content/litespeed/css/a.css" media="(min-width:768px)" onload="this.media=&#39;all&#39;">';
$complete=$prefix.$crit.$rest.$suffix.str_repeat(' ',26000);
verify(MAD4B_Verified_Critical_CSS_V1::mobile_home_markup_complete($complete),'adaptive correct markup cache proof');
verify(!MAD4B_Verified_Critical_CSS_V1::mobile_home_markup_complete(
    str_replace('media="(min-width:768px)"','media="print"',$complete)),'adaptive wrong complete CSS media denies');
verify(!MAD4B_Verified_Critical_CSS_V1::mobile_home_markup_complete(
    str_replace('media="(max-width:767px)"','media="(max-width:760px)"',$complete)),'adaptive wrong breakpoint denies');
$classicHtml=str_replace(' media="(max-width:767px)"','',$complete);
$classicHtml=str_replace('media="(min-width:768px)"','media="print"',$classicHtml);
verify(MAD4B_Verified_Critical_CSS_V1::mobile_home_markup_complete($classicHtml),'classic markup still accepted');
echo json_encode(['contract'=>'mad4b.css-native-adaptive-compatibility.v1',
   'checks'=>$count,'passed'=>$count,'wordpress_mutated'=>false,'release_granted'=>false]),"\n";
