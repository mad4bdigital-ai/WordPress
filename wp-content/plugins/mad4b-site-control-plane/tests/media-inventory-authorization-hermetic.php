<?php
/** Bounded Media inventory source fixture; all file paths are ephemeral private /tmp. */
define('ABSPATH',__DIR__.'/');
$root=sys_get_temp_dir().'/mad4b-media-'.bin2hex(random_bytes(5));
mkdir($root,0700,true);
$textFile=$root.'/a.txt';$csvFile=$root.'/b.csv';
file_put_contents($textFile,"Travel brand context\nEnglish and Arabic.\n");
file_put_contents($csvFile,"Market,Visits\nEG,3\nUS,2\n");
$GLOBALS['files']=[11=>$textFile,12=>$csvFile];
$GLOBALS['mimes']=[11=>'text/plain',12=>'text/csv'];
$GLOBALS['ids']=[11,12];$GLOBALS['known']=[];$GLOBALS['writes']=0;
$GLOBALS['admin']=true;$GLOBALS['uploads']=true;$GLOBALS['policy']=true;
class WP_Error{private $code;function __construct($code,$m=''){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($name,$handler){}
function get_post($id){return isset($GLOBALS['files'][$id])?(object)['post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Fixture '.$id]:false;}
function get_attached_file($id){return $GLOBALS['files'][$id]??false;}
function get_post_mime_type($id){return $GLOBALS['mimes'][$id]??'';}
function get_post_meta($id,$key,$single=true){return 'uploads/fixture-'.$id;}
function get_post_modified_time($format,$gmt,$post){return '2026-10-26T03:00:00Z';}
function wp_check_invalid_utf8($v,$strip=false){return preg_match('//u',$v)?$v:'';}
function current_user_can($c){return $c==='manage_options'?$GLOBALS['admin']:($c==='upload_files'&&$GLOBALS['uploads']);}
function get_current_user_id(){return 17;}
function absint($v){return abs((int)$v);}
class FakeWPDB{
 public $posts='wp_posts';
 function prepare($query,$values){return $query;}
 function get_col($sql){return $GLOBALS['ids'];}
}
$wpdb=new FakeWPDB();
class MAD4B_SCP_Policy{static function can_connect_user($user){return $GLOBALS['policy'] && $user===17;}}
class MAD4B_SCP_Context_Authority{
 static function assets(){return $GLOBALS['known'];}
 static function upsert_source($args){$GLOBALS['writes']++;return ['source_id'=>'fake-source'];}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-wordpress-media-context.php';
$n=0;
function test($what,$valid){global $n;if(!$valid){fwrite(STDERR,'FAIL '.$what.PHP_EOL);exit(1);}$n++;}
function deny($v,$code){return is_wp_error($v)&&$v->get_error_code()===$code;}
$cls='MAD4B_SCP_WordPress_Media_Context';
test('missing attachment rejected',deny($cls::attachment_record(999),'mad4b_media_missing'));
$first=$cls::attachment_record(11);
test('attachment hash is exact local content',is_array($first)&&$first['content_hash']===hash_file('sha256',$GLOBALS['files'][11]));
test('attachment source metadata never claims complete',$first['content_complete']===false);
$GLOBALS['mimes'][11]='application/unknown';
test('unsupported MIME rejected',deny($cls::attachment_record(11),'mad4b_media_mime_unsupported'));
$GLOBALS['mimes'][11]='text/plain';
$inventory=$cls::inventory(['text/plain','text/csv'],'site-source');
test('scoped inventory complete',is_array($inventory)&&$inventory['complete']&&$inventory['content_complete']);
test('bounded two assets',count($inventory['assets'])===2);
test('normalizes WordPress TXT contents',$inventory['assets'][0]['normalization_reason']==='local_text');
test('normalizes CSV spreadsheet rows',$inventory['assets'][1]['normalization_reason']==='local_csv');
test('explicit MIME scope retained',$inventory['mime_types']===['text/plain','text/csv']&&$inventory['scoped_inventory']===true);
test('scoped inventory wrote zero WordPress records',$GLOBALS['writes']===0);
test('empty MIME scope refused',deny($cls::inventory([]),'mad4b_media_inventory_scope_invalid'));
test('unknown MIME scope refused',deny($cls::inventory(['application/zip']),'mad4b_media_inventory_scope_invalid'));
$GLOBALS['ids']=range(1,1001);
test('over-capacity scan refused before file reads',deny($cls::inventory(['text/plain']),'mad4b_media_inventory_capacity'));
$GLOBALS['ids']=[11];$GLOBALS['known'][hash('sha256','site-source|11')]=[
 'content_complete'=>true,'content_hash'=>$first['content_hash'],'version'=>'2026-10-26T03:00:00Z'];
$reuse=$cls::inventory(['text/plain'],'site-source');
test('unchanged already-complete asset reused',count($reuse['assets'])===1&&$reuse['assets'][0]['reuse_existing']===true);
test('reuse no DB mutations',$GLOBALS['writes']===0);
$GLOBALS['admin']=false;
test('administrator required for legacy sync IDs',deny($cls::sync_ids([11]),'mad4b_media_denied'));
$GLOBALS['admin']=true;$GLOBALS['uploads']=false;
test('upload_files required for sync',deny($cls::sync_ids([11]),'mad4b_media_denied'));
$GLOBALS['uploads']=true;$GLOBALS['policy']=false;
test('original enrolled policy required for sync',deny($cls::sync_ids([11]),'mad4b_media_denied'));
$GLOBALS['policy']=true;
test('empty ID sync rejected',deny($cls::sync_ids([]),'mad4b_media_id_limit'));
test('more than 25 sync IDs rejected',deny($cls::sync_ids(range(1,26)),'mad4b_media_id_limit'));
test('invalid attachment among selected stops before mutation',
 deny($cls::sync_ids([11,999]),'mad4b_media_missing')&&$GLOBALS['writes']===0);
test('all fixture files read-only in WordPress test scope',$GLOBALS['writes']===0);
unlink($textFile);unlink($csvFile);rmdir($root);
echo 'PASS: '.$n.' exact Media scope/normalization/authorization checks'.PHP_EOL;
