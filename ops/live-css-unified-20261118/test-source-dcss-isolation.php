<?php
/** Hermetic original hook contract; no WordPress, network, or DB dependency. */
declare(strict_types=1);
function home_url($path='/'){return $GLOBALS['test_host'];}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function is_singular($type){return $GLOBALS['test_is_tour'] && $type==='tours-and-activities';}
function add_action(...$unused):void{}
function add_filter(...$unused):void{}
define('ABSPATH','/tmp/');
require __DIR__.'/source-css-bundler-under-test.php';
$ref=new ReflectionClass(MAD4B_Private_HTML_CSS_Bundler_V1::class);
$active=$ref->getProperty('active');
$tests=0;
$assert=static function(bool $pass,string $label)use(&$tests):void{
    if(!$pass)throw new RuntimeException('FAIL '.$label);
    echo "PASS $label\n";$tests++;
};
$test=function(string $host,bool $isTour,bool $admitted,$excludes)use($active):mixed{
    $GLOBALS['test_host']='https://'.$host.'/';
    $GLOBALS['test_is_tour']=$isTour;
    $active->setValue($admitted);
    return MAD4B_Private_HTML_CSS_Bundler_V1::preserve_volatile_tour_css($excludes);
};
$original=['fontawesome','keepme'];
$css=$test('tours.dev.mad4b.com',true,true,$original);
$assert($css===['fontawesome','keepme','elementor-dcss-'],'only source admitted public Tour excludes volatile JetEngine CSS');
$assert($original===['fontawesome','keepme'],'original native filter data unchanged');
$assert($test('tours.dev.mad4b.com',true,true,$css)===$css,'no duplicate excludes on a repeated filter pass');
$assert($test('tours.dev.mad4b.com',false,true,$original)===$original,'source public non-tour unaffected');
$assert($test('wayfatours.com',true,true,$original)===$original,'Wayfa original public-certified optimizer unchanged');
$assert($test('tours.dev.mad4b.com',true,false,$original)===$original,'unadmitted private/invalid Woo context unaffected');
$assert($test('tours.dev.mad4b.com',true,false,'keep-original')==='keep-original','unadmitted nonarray filter unaffected');
$assert($test('tours.dev.mad4b.com',true,true,null)===['elementor-dcss-'],'source-only CSS exclusion retains a valid native filter array');
echo "TOTAL $tests/8\n";
