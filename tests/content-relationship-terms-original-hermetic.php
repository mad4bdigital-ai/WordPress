<?php
/** Exact original ContentJob term-provider readback: no WordPress writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_post($id){return $GLOBALS['post_fixtures'][$id]??null;}
function get_post_type($id){$post=get_post($id);return $post?$post->post_type:null;}
function wp_attachment_is_image($id){return false;}
function taxonomy_exists($name){return $name==='destinations';}
function is_object_in_taxonomy($type,$tax){return $type==='tour'&&$tax==='destinations';}
function apply_filters($hook,$defaults,$job){return $GLOBALS['typed_descriptors']??[];}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function term_assert($yes,$label){
 if(!$yes){fwrite(STDERR,"FAIL: ".$label.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class FakeWpDb {
 public $term_relationships='wp_term_relationships';
 public $term_taxonomy='wp_term_taxonomy';
 public $postmeta='wp_postmeta';
 private $reading_id;
 function prepare($sql,...$args){$this->reading_id=(int)$args[0];return (string)$sql;}
 function get_col($sql){return array_key_exists($this->reading_id,$GLOBALS['term_data'])?$GLOBALS['term_data'][$this->reading_id]:[];}
 function get_var($sql){return $GLOBALS['post_meta'][$this->reading_id]??'';}
}
$wpdb=new FakeWpDb();
class MAD4B_SCP_Content_Relationship_Graph {
 static $missing=[];
 public static function resolve($kind,$id,$type,$lang){
  if(isset(self::$missing[$id]))return ['state'=>'HOLD','reason'=>'translation_missing'];
  return ['state'=>'RESOLVED','target_id'=>$id];
 }
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-relationship-providers.php';
$c='MAD4B_SCP_Content_Relationship_Providers';
$method=new ReflectionMethod($c,'terms');
if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$terms=function($source,$target)use($method){
 $GLOBALS['term_data']=[10=>$source,20=>$target];
 return $method->invoke(null,10,20,'destinations','es');
};
$exact=$terms([1,2],[1,2]);
term_assert($exact['status']==='VERIFIED'&&!$exact['write_authorized']
 && !$exact['write_performed'],'Exact localized source and target taxonomy matches');
$empty=$terms([],[]);
term_assert(in_array($empty['status'],['VERIFIED','NOT_APPLICABLE'],true),
 'Both empty taxonomy sets require no provider writes');
$extra=$terms([],[99]);
term_assert($extra['status']==='LOCAL_OVERRIDE_PRESERVED'
 &&$extra['target_count']===1,
 'Target-only original editorial taxonomy must not be reported verified parity');
$extra=$terms([1],[1,99]);
term_assert($extra['status']==='LOCAL_OVERRIDE_PRESERVED'
 &&$extra['target_count']===2
 &&$extra['automatic_replace_entire_term_set']===false,
 'Already translated source term plus locally-authored target term is not exact parity');
$missing=$terms([1,2],[1]);
term_assert($missing['status']==='REVIEW_DIFF'&&count($missing['resolved'])===2,
 'Missing localized assignment requires owner review, never silent overwrite');
MAD4B_SCP_Content_Relationship_Graph::$missing=[2=>true];
$pending=$terms([1,2],[1]);
term_assert($pending['status']==='WAITING_TERM_TRANSLATIONS'
 &&count($pending['missing'])===1,
 'Untranslated source taxonomy never guesses numeric term IDs');
MAD4B_SCP_Content_Relationship_Graph::$missing=[];
$large=range(1,201);
$overflow=$terms($large,$large);
term_assert($overflow['status']==='HOLD_TERM_SCAN_INCOMPLETE'
 &&$overflow['scan_truncated']===true
 &&$overflow['source_count']>=201,
 'Matching first 200 terms never incorrectly proves complete 201-term parity');
$overflow=$terms([1],range(1,201));
term_assert($overflow['status']==='HOLD_TERM_SCAN_INCOMPLETE'
 &&$overflow['scan_truncated']===true,
 'Target greater than original scan budget cannot certify exact parity');
$unavailable=$terms(new WP_Error('db_not_ready'),[]);
term_assert($unavailable['status']==='HOLD_TERM_READBACK_UNAVAILABLE',
 'Error-shaped original SQL result cannot become an empty verified term set');
$unavailable=$terms([1],null);
term_assert($unavailable['status']==='HOLD_TERM_READBACK_UNAVAILABLE',
 'Missing DB term readback cannot masquerade as no target taxonomy');
$GLOBALS['post_fixtures']=[
 10=>(object)['ID'=>10,'post_type'=>'tour','post_parent'=>0],
 20=>(object)['ID'=>20,'post_type'=>'tour','post_parent'=>0],
 40=>(object)['ID'=>40,'post_type'=>'post','post_parent'=>0]
];
$GLOBALS['term_data']=[10=>[11,12],20=>[11,12,13]];
$GLOBALS['post_meta']=[10=>'',20=>''];
$job=['job_id'=>'fixture-job','job_revision'=>3];
$family=['target_id'=>20,'target_locale'=>'es',
 'identity_sha256'=>str_repeat('e',64)];
$source=['id'=>10,'kind'=>'post'];
$composite=$c::inspect($job,$family,$source);
term_assert($composite['state']==='INSPECTED'
 &&$composite['relationships']['taxonomy_destinations']['status']==='LOCAL_OVERRIDE_PRESERVED'
 &&$composite['relationships']['featured_image']['status']==='NOT_APPLICABLE'
 &&$composite['relationships']['elementor_template_refs']['status']==='PROVIDER_NOT_ACTIVE'
 &&$composite['relationships']['typed_meta']['status']==='NO_REGISTERED_MAPPABLE_FIELDS'
 &&$composite['provider_write_required']===true
 &&$composite['mutation_performed']===false
 &&$composite['automatic_overwrite_existing_authoring']===false,
 'Complete first-party ContentJob read-only inspect preserves target taxonomy and templates');
term_assert(preg_match('/^[a-f0-9]{64}$/',$composite['graph_sha256'])===1
 &&$c::inspect($job,$family,$source)['graph_sha256']===$composite['graph_sha256'],
 'Read-only ContentJob graph identity is stable over the same observed source');
$invalid=$c::inspect($job,['target_id'=>40,'target_locale'=>'es'],$source);
term_assert($invalid['state']==='HOLD' &&$invalid['write_performed']===false,
 'Source target post-type mismatch is never a mapped relationship');
$invalid=$c::inspect($job,['target_id'=>10,'target_locale'=>'es'],$source);
term_assert($invalid['state']==='HOLD','Same source and target record is not a translation pair');
$invalid=$c::inspect($job,['target_id'=>999,'target_locale'=>'es'],$source);
term_assert($invalid['state']==='NO_POST_PAIR','Missing target post is not a writable relation');
$GLOBALS['typed_descriptors']=['bad_type'=>'unknown','otherField'=>'page'];
$meta=$c::inspect($job,$family,$source);
term_assert($meta['relationships']['typed_meta']['status']==='NO_REGISTERED_MAPPABLE_FIELDS',
 'Unknown and empty meta type descriptors cannot trigger numeric-ID guessing');
echo 'PASS: CONTENT_RELATIONSHIP_TERMS '.($GLOBALS['checks']??0).
 ' original source-target exactness and bounded scan cases'.PHP_EOL;
