<?php
namespace Elementor\Core\Base {
 class Document {
  public function get_json_meta($text,$id) {
   return \MAD4B_Elementor_Request_JSON_Memo_V1::intercept($text,$id,'_elementor_data',true,'post');
  }
 }
}
namespace {
 define('ABSPATH',__DIR__);
 function add_action(...$args){}
 function must($ok,$label){if(!$ok){fwrite(STDERR,"FAIL ".$label."\n");exit(2);}echo "PASS ".$label."\n";}
 $source=__DIR__.'/../../../../wp-content/mu-plugins/331-mad4b-elementor-request-json-memo.php';
 if(!is_file($source))$source='/var/www/html/wp-content/mu-plugins/331-mad4b-elementor-request-json-memo.php';
 require $source;
 $class='MAD4B_Elementor_Request_JSON_Memo_V1';
 $raw='[{"elType":"widget","id":"a1","settings":{"title":"English title"}}]';
 $decoded=json_decode($raw,true);
 foreach(['prepared'=>[25102=>$decoded],'hashes'=>[25102=>hash('sha256',$raw)]] as $key=>$data){
  $r=new \ReflectionProperty($class,$key);$r->setAccessible(true);$r->setValue(null,$data);
 }
 $doc=new \Elementor\Core\Base\Document();
 $result=$doc->get_json_meta($raw,25102);
 must(is_array($result)&&$result[0]===$decoded,'Elementor Document source data returned as decoded array');
 $single=$result[0];
 must($single===$decoded&&$single[0]['settings']['title']==='English title','WordPress single metadata semantics preserved');
 $direct=$class::intercept($raw,25102,'_elementor_data',true,'post');
 must($direct===$raw,'CSS/raw metadata callers preserve original JSON');
 $other=$doc->get_json_meta($raw,14962);
 must($other===$raw,'unlisted templates always native');
 $modified='[{"elType":"widget","id":"a1","settings":{"title":"Arabic title"}}]';
 must($doc->get_json_meta($modified,25102)===$modified,'WPML-translated or edited data hash mismatch returns native source');
 must($class::intercept(null,25102,'_elementor_data',true,'post')===null,'untranslated or missing metadata declines safely');
 must($class::intercept($raw,25102,'_elementor_page_settings',true,'post')===$raw,'unrelated metadata untouched');
 must($class::intercept($raw,25102,'_elementor_data',false,'post')===$raw,'multi-value reads not changed');
 $s=file_get_contents($source);
 foreach([
  "wp_get_environment_type()!=='staging'",
  "ELEMENTOR_VERSION!=='4.3.5'",
  "ICL_SITEPRESS_VERSION!=='4.9.5'",
  "HTTP_X_MAD4B_TEMPLATE_DATA_CONTROL",
  "get_woocommerce_currency()!=='USD'",
  "HTTP_COOKIE","HTTP_AUTHORIZATION","HTTP_CF_IPCOUNTRY",
  "is_post_type_archive('tours-and-activities')",
  'hash_equals($sha,hash(',
  "debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,11)",
  "get_json_meta","Document",
 ] as $needle)must(strpos($s,$needle)!==false,'fail-closed guard '.substr($needle,0,50));
 must(strpos($s,'wp_cache_set(')===false&&strpos($s,'set_transient(')===false,'no persistent cache or price HTML storage');
}
