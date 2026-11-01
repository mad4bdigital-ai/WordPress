<?php
/** Deterministic request-local term HTML cache test; no WP bootstrap, no network. */
define('ABSPATH',__DIR__);
function add_action(...$args){}
function wp_json_encode($data){return json_encode($data,JSON_UNESCAPED_UNICODE);}
function wp_is_mobile(){return $GLOBALS['is_mobile']??false;}
function get_term_meta($id){return $GLOBALS['term_meta'][$id]??[];}
class WP_Term{
 public $term_id;public $term_taxonomy_id;public $taxonomy='destinations';
 public $name;public $slug;public $description='';public $parent=0;public $count=1;
 public function __construct($id,$name){$this->term_id=$id;$this->term_taxonomy_id=$id+100;$this->name=$name;$this->slug='test-'.$id;}
}
class DataStub{public $term;public function get_current_object(){return $this->term;}}
class ListingStub{public $data;public function __construct(){$this->data=new DataStub();}}
class EngineStub{public $listings;public function __construct(){$this->listings=new ListingStub();}}
function jet_engine(){return $GLOBALS['engine'];}
function must($condition,$reason){if(!$condition){fwrite(STDERR,"FAIL ".$reason."\n");exit(1);}echo "PASS ".$reason."\n";}
$root=dirname(__DIR__,4);
$file=$root.'/wp-content/mu-plugins/328-mad4b-destination-terms-request-memo.php';
if(!is_file($file))$file='/tmp/mad4b-dest-memo-source.php';
require $file;
$GLOBALS['engine']=new EngineStub();
$GLOBALS['term_meta']=[];
$klass='MAD4B_Destination_Term_Request_Memo_V1';
$calls=0;
$native=static function($content,$id)use(&$calls){$calls++;$term=$GLOBALS['engine']->listings->data->term;$id=$term instanceof WP_Term?$term->term_id:0;return '<a href="/term/'.$id.'">Destination #'.$calls.'</a>';};
$t=new WP_Term(800,'Luxor');
$GLOBALS['engine']->listings->data->term=$t;
$a=$klass::content('',48562,$native,'en');
$b=$klass::content('',48562,$native,'en');
must($a===$b&&$calls===1,'identical term HTML reused once in same request');
must($klass::stats()['native']===1&&$klass::stats()['reused']===1,'native and reused accounting');
$t->name='Different Lexeme';
$c=$klass::content('',48562,$native,'en');
must($c!==$a&&$calls===2,'term rename invalidates request memo');
$GLOBALS['term_meta'][800]=['new'=>['changed']];
$d=$klass::content('',48562,$native,'en');
must($d!==$c&&$calls===3,'term metadata edit invalidates memo');
$e=$klass::content('',48562,$native,'fr');
must($e!==$d&&$calls===4,'WPML locale isolates fragment');
$GLOBALS['is_mobile']=true;
$f=$klass::content('',48562,$native,'fr');
must($f!==$e&&$calls===5,'mobile device isolates fragment');
$GLOBALS['engine']->listings->data->term=new WP_Term(801,'Cairo');
$g=$klass::content('',48562,$native,'fr');
must($g!==$f&&$calls===6,'different destination cannot cross-reuse HTML');
$h=$klass::content('',14962,$native,'fr');
must($h!==$g&&$calls===7,'Featured Tours listing always runs original native renderer');
$GLOBALS['engine']->listings->data->term=(object)['id'=>801];
$i=$klass::content('',48562,$native,'fr');
must($i!==$h&&$calls===8,'invalid term/object does not reuse HTML');
$code=file_get_contents($file);
foreach([
 'wp_get_environment_type()', 'SITE_UUID', "TEMPLATE=48562",
 'JSON_SHA256', 'get_term_meta($term->term_id)', 'HTTP_COOKIE',
 'HTTP_AUTHORIZATION','HTTP_CF_IPCOUNTRY','HTTP_X_MAD4B_CURRENCY',
 'HTTP_X_FORWARDED_GEO_COUNTRY','HTTP_X_MAD4B_DEST_MEMO_CONTROL',
 "get_woocommerce_currency()!=='USD'",'wp_is_mobile()'
] as $guard){must(strpos($code,$guard)!==false,'source guard '.$guard);}
must(strpos($code,'wp_cache_set(')===false&&strpos($code,'set_transient(')===false,'never persists any HTML');
$rate=100;
$price_render=static function($html,$listing_id)use(&$rate){return '<strong>USD '.$rate.'</strong>';};
$first=$klass::content('',14962,$price_render,'en');$rate=155;
$second=$klass::content('',14962,$price_render,'en');
must($first!==$second&&strpos($second,'155')!==false,'Featured dynamic price is freshly rendered on every call');
