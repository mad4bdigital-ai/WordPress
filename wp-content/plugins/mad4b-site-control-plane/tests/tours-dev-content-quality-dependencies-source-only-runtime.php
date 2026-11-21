<?php
/** Source-only original content-quality dependency fixture. No WordPress execution. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
function check_quality($v,$what){if(!$v){fwrite(STDERR,'FAIL: '.$what.PHP_EOL);exit(1);}}
$GLOBALS['writes']=0;
function add_action(){++$GLOBALS['writes'];throw new RuntimeException('No boot allowed');}
function register_rest_route(){++$GLOBALS['writes'];throw new RuntimeException('No REST registration');}
function update_option(){++$GLOBALS['writes'];throw new RuntimeException('No option write');}
function wp_remote_post(){++$GLOBALS['writes'];throw new RuntimeException('No network call');}
class MAD4B_SCP_Site_Profile {
 public static $enrolled=false;public static $matched=false;
 public static function origin_enrolled(){return self::$enrolled;}
 public static function site_urls_match_enrollment(){return self::$matched;}
}
$GLOBALS['quality_post_type']='';
function apply_filters($name,$default){
 return $name==='mad4b_scp_brand_experience_post_type'?$GLOBALS['quality_post_type']:$default;
}
function post_type_exists($name){return in_array($name,array('custom_experience','post'),true);}
function get_post_type_object($name){
 if($name==='custom_experience')return (object)array('_builtin'=>false);
 if($name==='post')return (object)array('_builtin'=>true);
 return null;
}
$root=dirname(__DIR__);
$main=file_get_contents($root.'/mad4b-site-control-plane.php');
check_quality(is_string($main),'Main Bootstrap required');
$files=array(
    'class-mad4b-scp-readiness-workflow-options.php'=>array('b99f4b8fd77c8f9578b104258dd624aa12a78eaa44857ffaf15fc00d39819e8c','MAD4B_SCP_Readiness_Workflow_Options'),
    'class-mad4b-scp-content-completeness.php'=>array('75c5a73e1a91e9b356f7f5adad494789daf8a774d754f33620d4c395b152bee8','MAD4B_SCP_Content_Completeness'),
    'class-mad4b-scp-geo-quality.php'=>array('220d953c9576b615b9ff8b82406e9674d1e6d11f96de468973a552c152ea2ebb','MAD4B_SCP_Geo_Quality'),
    'class-mad4b-scp-locale-experience-alerts.php'=>array('ca05fccee813f2b614d9bedbfdf9d51620e24ef2d56338211fc85d280d1362f9','MAD4B_SCP_Locale_Experience_Alerts'),
    'class-mad4b-scp-brand-experience-quality.php'=>array('183a282254251deb116194f3ef7c8863f2f2836b45235ca27e639c4b0b98ac63','MAD4B_SCP_Brand_Experience_Quality'),
    'class-mad4b-scp-presentation-integrity.php'=>array('4d22f00e4a1ef2e5ef4cdf9a5ccc7f3e519891586f4982a06ed243e143b7cd6a','MAD4B_SCP_Presentation_Integrity_V1'),
    'class-mad4b-scp-brand-integrity.php'=>array('969b1ef0033e01bf2bb0919f5b2d29aa84d10732d87dd4ef906a8523c1f3a933','MAD4B_SCP_Brand_Integrity'),
    'class-mad4b-scp-presentation-browser-evidence.php'=>array('20bb927951251ec356e2c6f1a8c135a22d1e7785c51eb9ce4f11ef8e98376159','MAD4B_SCP_Presentation_Browser_Evidence_V1'),
);

foreach($files as $name=>$item) {
 $path=$root.'/includes/'.$name;
 check_quality(is_file($path)&&hash_equals($item[0],hash_file('sha256',$path)),
   'Original or reviewed successor SHA drift: '.$name);
 check_quality(strpos($main,$name)===false,'Unapproved module included by Bootstrap: '.$name);
}
$geo=file_get_contents($root.'/includes/class-mad4b-scp-geo-quality.php');
check_quality(is_string($geo)&&hash('sha256',$geo."\n")==='79a3f48ee3563338d2f185770df29614b2910d78eb6561499dac078ba2c74098',
 'Geo Quality successor differs only by one terminal blank line');
check_quality(count($files)===8,'Exactly eight direct/recursive support modules');
foreach($files as $name=>$item) {
 require_once $root.'/includes/'.$name;
 check_quality(class_exists($item[1],false),'Expected source-only class missing: '.$name);
}
check_quality($GLOBALS['writes']===0,'No registered hook, REST operation, option write or HTTP request');
// The brand-experience scanner is company-neutral and fail closed.
$method=new ReflectionMethod('MAD4B_SCP_Brand_Experience_Quality','experience_post_type');
$method->setAccessible(true);
check_quality($method->invoke(null)==='','Unenrolled site has no implicit business model');
MAD4B_SCP_Site_Profile::$enrolled=true;
check_quality($method->invoke(null)==='','Unmatched site origin fails closed');
MAD4B_SCP_Site_Profile::$matched=true;
check_quality($method->invoke(null)==='','No default experience CPT');
$GLOBALS['quality_post_type']='post';
check_quality($method->invoke(null)==='','Built-in WordPress posts cannot be implicit experience model');
$GLOBALS['quality_post_type']='invalid/slash';
check_quality($method->invoke(null)==='','Malformed CPT rejected');
$GLOBALS['quality_post_type']=array('custom_experience');
check_quality($method->invoke(null)==='','Non-string CPT rejected');
$GLOBALS['quality_post_type']='custom_experience';
check_quality($method->invoke(null)==='custom_experience','Verified explicit custom CPT opt-in admitted');
$brand=file_get_contents($root.'/includes/class-mad4b-scp-brand-experience-quality.php');
check_quality(strpos($brand,"'tours-and-activities'")===false,
 'No tourism CPT preset in shared source');
check_quality(strpos($brand,"mad4b_scp_brand_experience_post_type")!==false,
 'Only explicit company post-type opt-in may enable experience scan');
check_quality($GLOBALS['writes']===0,'All source-only tests were non-mutating');
echo "PASS: 8 sealed quality dependencies, 6 identical originals, 2 reviewed successors, no boot".PHP_EOL;
