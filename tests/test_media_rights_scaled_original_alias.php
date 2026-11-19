<?php
/** Standalone controlled WordPress stubs; no site/database access or writes. */
namespace Jet_Engine\Modules\Custom_Content_Types {
 final class Module {public $manager;private static $instance;
  private function __construct() {$this->manager=new \TestRightsManager();}
  public static function instance(){return self::$instance??=new self();}
 }
}
namespace {
 define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');
 final class TestRightsCctDb {function is_table_exists(){return true;}function table(){return 'test_media_rights';}}
 final class TestRightsFactory {public $db;function __construct(){$this->db=new TestRightsCctDb();}}
 final class TestRightsManager {function get_content_types($name){return $name==='media_rights'?new TestRightsFactory():null;}}
 final class TestRightsWpdb {
  public $last_error=''; public $postmeta='test_postmeta'; public $queries=[]; public $lastArgs=[]; public $draft_rows=[];
  function prepare($sql,...$args){$this->queries[]=['sql'=>$sql,'args'=>$args];$this->lastArgs=$args;return $sql;}
  function get_var($sql){return 0;}
  function get_results($sql,$mode=null){
   $case=$GLOBALS['case'];
   if(str_contains($sql,'WHERE cct_status=%s ORDER BY _ID ASC LIMIT 1001'))return $this->draft_rows;
   if(empty($case['published']))return [];
   if(count($this->lastArgs)===3 && ($case['credit_attachment']??70016)!==(int)$this->lastArgs[2])return [];
   return [[' _unused'=>1,'_ID'=>222,'attachment_id'=>$case['credit_attachment']??70016,
     'source_sha256'=>$case['source_sha'],'source_url'=>$case['credit_source']??'https://commons.wikimedia.org/wiki/File:Nile_Cruise.jpg',
     'author'=>$case['credit_author']??'Licensed Photographer','license_label'=>$case['credit_label']??'CC BY 4.0',
     'license_url'=>$case['credit_license_url']??'https://creativecommons.org/licenses/by/4.0']];
  }
  function get_col($sql){return [];}
 }
 final class MAD4B_WPML_Attachment_Family_Readback_V1 {
  static function translations($id){
   if(!empty($GLOBALS['case']['wpml_invalid']))return [];
   if($id===70016&&!empty($GLOBALS['case']['wpml_not_reciprocal']))return ['en'=>(object)['element_id'=>70016]];
   return ['en'=>(object)['element_id'=>70016],'es'=>(object)['element_id'=>70017]];
  }
 }
 final class MAD4B_SCP_Distributed_Lock {
  public static $released=0;
  static function catalog_name($name){return $name;}
  static function acquire($lock){return ['lock'=>$lock];}
  static function release($lock){self::$released++;}
 }
 function add_action(...$args){}
 function is_wp_error($v){return false;}
 function wp_get_upload_dir(){return ['basedir'=>$GLOBALS['dir']];}
 function get_post_type($id){return in_array($id,[70016,70017],true)?'attachment':null;}
 function get_post_mime_type($id){return 'image/jpeg';}
 function get_post($id){return (object)['post_title'=>'Cruise photo'];}
 function get_attached_file($id){return $GLOBALS['dir'].'/'.($id===70017&&!empty($GLOBALS['case']['alias_different_served'])?'other-scaled.jpg':'photo-scaled.jpg');}
 function wp_get_attachment_metadata($id){return $GLOBALS['case']['metadata']??['original_image'=>'photo.jpg'];}
 function get_post_meta($id,$key,$single=true){
  $case=$GLOBALS['case'];if($id===70017)return match($key){
   '_mad4b_verified_source_sha256'=>$case['alias_meta_claim']??'',
   '_mad4b_creator'=>$case['alias_meta_author']??'',
   '_mad4b_license'=>$case['alias_meta_label']??'',
   '_mad4b_license_source_url'=>$case['alias_meta_source']??'',
   default=>''};
  return match($key){
   '_mad4b_verified_source_sha256'=>$case['claimed_source_sha']??$case['source_sha'],
   '_mad4b_license_source_url'=>$case['meta_source']??'https://commons.wikimedia.org/wiki/File:Nile_Cruise.jpg',
   '_mad4b_license'=>$case['meta_label']??'CC BY 4.0',
   '_mad4b_creator'=>$case['meta_author']??'Licensed Photographer',
   default=>''};
 }
 function wp_basename($value){return basename($value);}
 function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
 function wp_get_attachment_url($id){return 'https://example.test/photo.jpg';}
 function apply_filters($name,$value,...$args){return $value;}
 function callProcess($class,$id){$GLOBALS['calls']++;return $class::process($id);}
 function must($condition,$label){if(!$condition)throw new \RuntimeException('FAILED: '.$label);echo 'PASS '.$label."\n";}
 $GLOBALS['wpdb']=new TestRightsWpdb();$GLOBALS['calls']=0;
 $GLOBALS['dir']=sys_get_temp_dir().'/mad4b-licensed-derivative-harness-'.getmypid();
 mkdir($GLOBALS['dir'],0700,true);
 file_put_contents($GLOBALS['dir'].'/photo.jpg','known-licensed-unmodified-origin-bytes');
 file_put_contents($GLOBALS['dir'].'/photo-scaled.jpg','a-wordpress-rendered-derivative-with-different-bytes');
 file_put_contents($GLOBALS['dir'].'/other-scaled.jpg','a-different-scaled-file-must-not-get-rights');
 $sourceSha=hash_file('sha256',$GLOBALS['dir'].'/photo.jpg');$renderedSha=hash_file('sha256',$GLOBALS['dir'].'/photo-scaled.jpg');
 $GLOBALS['case']=['source_sha'=>$sourceSha,'published'=>true];
 $source=getenv('MAD4B_RIGHTS_TEST_SOURCE')?:dirname(__DIR__).'/wp-content/mu-plugins/mad4b-media-rights-extraction-intake.php';
 require $source;
 $class=\MAD4B_Media_Rights_Extraction_Intake_V1::class;
 try {
  $happy=callProcess($class,70016);
  must($happy['status']==='already_licensed_wordpress_scaled_derivative','published exact original + importer proves derivative');
  must($happy['licensed_original_sha256']===$sourceSha&&$happy['served_sha256']===$renderedSha,'distinct original and rendered hashes retained');
  must($happy['rights_granted_by_intake']===false&&$happy['new_draft_created']===false,'no new authorization or draft');
  $sibling=callProcess($class,70017);
  must($sibling['status']==='already_licensed_wordpress_scaled_derivative','reciprocal WPML ES sibling shares original license');
  must($sibling['verified_wpml_sibling']===true&&$sibling['licensed_attachment_id']===70016,'WPML alias resolves to verified original-language credit');
  $GLOBALS['wpdb']->draft_rows=[
   ['_ID'=>224,'cct_status'=>'draft','credit_key'=>'discovered_attachment_70016','attachment_id'=>70016,'source_sha256'=>$renderedSha,'author'=>'','source_url'=>'','license_url'=>'','license_label'=>''],
   ['_ID'=>225,'cct_status'=>'draft','credit_key'=>'discovered_attachment_70017','attachment_id'=>70017,'source_sha256'=>$renderedSha,'author'=>'','source_url'=>'','license_url'=>'','license_label'=>''],
   ['_ID'=>226,'cct_status'=>'draft','credit_key'=>'discovered_attachment_70016','attachment_id'=>70016,'source_sha256'=>$renderedSha,'author'=>'','source_url'=>'https://example.test/unverified','license_url'=>'','license_label'=>''],
   ['_ID'=>227,'cct_status'=>'draft','credit_key'=>'discovered_attachment_70016','attachment_id'=>70016,'source_sha256'=>str_repeat('f',64),'author'=>'','source_url'=>'','license_url'=>'','license_label'=>''],
  ];
  $triage=$class::review_queue_status();
  must($triage['status']==='complete'&&$triage['total_native_draft_rows']===4,'native CCT draft review queue read-only');
  must($triage['active_rights_review_drafts']===3&&$triage['historical_count']===1,'exact scaled duplicate excluded without changing CCT status');
  must($triage['historical_superseded_derivative_drafts'][0]['draft_id']===224&&$triage['historical_superseded_derivative_drafts'][0]['published_credit_id']===222,'exact published credit linked');
  must($triage['native_cct_statuses_unchanged']===true&&$triage['writes_performed']===false&&$triage['licenses_granted']===false,'no extra rights / no native CCT edits');

  $siblingCases=[
   'unregistered WPML family'=>['wpml_invalid'=>true],
   'nonreciprocal WPML family'=>['wpml_not_reciprocal'=>true],
   'wrong WPML image bytes'=>['alias_different_served'=>true],
   'different importer SHA declared on translated asset'=>['alias_meta_claim'=>str_repeat('f',64)],
   'conflicting translation creator'=>['alias_meta_author'=>'Forged Creator'],
   'conflicting translation license label'=>['alias_meta_label'=>'CC BY-SA'],
  ];
  foreach($siblingCases as $label=>$overrides){$GLOBALS['case']=array_merge(['source_sha'=>$sourceSha,'published'=>true],$overrides);
   must(callProcess($class,70017)['status']==='no_external_provenance',$label.' does not inherit rights');}
  $GLOBALS['case']=['source_sha'=>$sourceSha,'published'=>true];

  $cases=[
   'no published license'=>['published'=>false],
   'published for different attachment'=>['credit_attachment'=>999],
   'importer source hash differs'=>['claimed_source_sha'=>str_repeat('0',64)],
   'creator metadata differs'=>['meta_author'=>'Different Creator'],
   'license label differs'=>['meta_label'=>'CC BY-SA 4.0'],
   'source URL differs'=>['meta_source'=>'https://example.test/unknown'],
   'record author differs'=>['credit_author'=>'Different Creator'],
   'record license not HTTPS'=>['credit_license_url'=>'http://creativecommons.org/licenses/by/4.0'],
   'metadata has no original_image'=>['metadata'=>[]],
   'metadata traverses outside uploads'=>['metadata'=>['original_image'=>'../photo.jpg']],
   'metadata contains backslash'=>['metadata'=>['original_image'=>'folder\\photo.jpg']],
  ];
  foreach($cases as $label=>$overrides){$GLOBALS['case']=array_merge(['source_sha'=>$sourceSha,'published'=>true],$overrides);
   $check=callProcess($class,70016);must($check['status']==='no_external_provenance',$label.' fails closed');}
  file_put_contents($GLOBALS['dir'].'/photo.jpg','tampered-source-bytes');
  $GLOBALS['case']=['source_sha'=>$sourceSha,'published'=>true];
  must(callProcess($class,70016)['status']==='no_external_provenance','changed original bytes fail closed');
  must(MAD4B_SCP_Distributed_Lock::$released===$GLOBALS['calls'],'review queue takes no locks or invokes process');
 }finally{foreach(['photo.jpg','photo-scaled.jpg','other-scaled.jpg'] as $name)@unlink($GLOBALS['dir'].'/'.$name);@rmdir($GLOBALS['dir']);}
}
