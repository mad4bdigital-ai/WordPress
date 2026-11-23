<?php
/** Hermetic production-authority-preserving presentation memo tests. */
declare(strict_types=1);
define('ABSPATH','/tmp/');
$mode=$argv[1]??'tour-auth';
if($mode==='ajax')define('DOING_AJAX',true);
$_SERVER=['REQUEST_METHOD'=>($mode==='post'?'POST':'GET'),'QUERY_STRING'=>''];
$_GET=$mode==='query'?['q'=>'test']:[];$_POST=[];$_COOKIE=$mode==='tour-anonymous'?[]:['wordpress_logged_in_fake'=>'opaque'];
if($mode==='query')$_SERVER['QUERY_STRING']='q=test';
if($mode==='authheader')$_SERVER['HTTP_AUTHORIZATION']='Bearer diagnostic';
if($mode==='wpnonce')$_SERVER['HTTP_X_WP_NONCE']='forbidden';
$GLOBALS['mode']=$mode;
$GLOBALS['wpml_calls']=0;
function taxonomy_exists($s):bool{return $s==='destinations';}
function add_filter(...$a){}
function add_action(...$a){}
function is_admin():bool{return $GLOBALS['mode']==='admin';}
function is_singular($s):bool{return $GLOBALS['mode']!=='other-page' && $s==='tours-and-activities';}
function is_preview():bool{return $GLOBALS['mode']==='preview';}
function is_user_logged_in():bool{return in_array($GLOBALS['mode'],['tour-auth','preview','admin'],true);}
function apply_filters($name,$term,$tax,$fallback,$lang){$GLOBALS['wpml_calls']++;return $term;}
function get_term_meta($term,$key,$single){return $key==='destination_type'?'country':'';}
class FakeDB {
 public $term_taxonomy='wp_term_taxonomy';public $queries=0;
 function prepare($sql,...$args){return vsprintf(str_replace(['%s','%d'],["'%s'",'%d'],$sql),$args);}
 function get_row($sql,$format){$this->queries++;return ['term_id'=>100,'parent'=>0];}
}
const ARRAY_A='ARRAY_A';
$wpdb=new FakeDB();
require getenv('MAD4B_SCOPE_TEST_FILE') ?: __DIR__.'/../../wp-content/mu-plugins/020-mad4b-company-country-operating-scope-v1.php';
for($i=0;$i<6;$i++){
 $id=MAD4B_Company_Country_Operating_Scope_V1::canonical_country(100);
 if($id!==100){fwrite(STDERR,"BAD_CANONICAL_COUNTRY\n");exit(3);}
}
$expected=in_array($mode,['tour-auth','tour-unknown-cookie','tour-anonymous'],true)?1:6;

if($wpdb->queries!==$expected){fwrite(STDERR,"QUERY_POLICY_FAILURE mode=$mode got={$wpdb->queries} expected=$expected\n");exit(4);}
if($GLOBALS['wpml_calls']!==6){fwrite(STDERR,"WPML_REVALIDATION_BROKEN\n");exit(5);}
echo "PASS $mode parent_queries={$wpdb->queries} wpml_checks={$GLOBALS['wpml_calls']}\n";
