<?php
namespace Elementor\Core\Files\CSS {
 class Post {
  public static int $enqueues=0;
  public static function create($post_id) { return new self(); }
  public function enqueue() { ++self::$enqueues; }
 }
}
namespace {
 define('ABSPATH', __DIR__);
 function add_action(...$args) {}
 class WP_Term {
  public int $term_id;
  public int $term_taxonomy_id;
  public string $taxonomy;
  public string $name;
  public string $slug;
  public string $description='';
  public int $parent=0;
  public int $count=7;
  public function __construct(int $id, string $type='destinations') {
   $this->term_id=$id;$this->term_taxonomy_id=$id+10;
   $this->taxonomy=$type;$this->name='Destination-'.$id;$this->slug='destination-'.$id;
  }
 }
 $GLOBALS['term']=new WP_Term(101);
 $GLOBALS['term_meta']=['image'=>[42]];
 $GLOBALS['mobile']=false;
 $GLOBALS['cache']=[];
 $GLOBALS['writes']=[];
 function jet_engine(){
  return (object)['listings'=>(object)['data'=>new class {
   public function get_current_object(){return $GLOBALS['term'];}
  }]];
 }
 function get_term_meta($id){return $GLOBALS['term_meta'];}
 function wp_json_encode($v){return json_encode($v);}
 function wp_is_mobile(){return $GLOBALS['mobile'];}
 function wp_cache_get($key,$group){return $GLOBALS['cache'][$group][$key]??false;}
 function wp_cache_set($key,$value,$group,$ttl){
  $GLOBALS['cache'][$group][$key]=$value;
  $GLOBALS['writes'][]=[$group,$ttl];
  return true;
 }
 $source=dirname(__DIR__,4).'/wp-content/mu-plugins/323-mad4b-nav-term-fragment-cache.php';
 if(!is_file($source))$source='/tmp/mad4b-nav-cache.php';
 require $source;
 function assertion($cond,$label){if(!$cond){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
 $class='MAD4B_Nav_Term_Fragment_Cache_V1';
 $native_calls=0;
 $native=static function($markup,$id)use(&$native_calls){++$native_calls;return 'rendered-'.(string)$id.'-'.$native_calls;};
 $first=$class::render_term('',67125,$native,'en');
 assertion($native_calls===1 && $first==='rendered-67125-1','first native fragment generated');
 $second=$class::render_term('',67125,$native,'en');
 assertion($first===$second && $native_calls===1,'subsequent fragment reuses original content');
 assertion(\Elementor\Core\Files\CSS\Post::$enqueues===1,'CSS enqueued when content reused');
 $GLOBALS['term']->count++;
 $class::render_term('',67125,$native,'en');
 assertion($native_calls===2,'term count invalidates fragment');
 $GLOBALS['term_meta']=['image'=>[43]];
 $class::render_term('',67125,$native,'en');
 assertion($native_calls===3,'term metadata invalidates fragment');
 $class::render_term('',67125,$native,'fr');
 assertion($native_calls===4,'WPML locale isolates fragment');
 $GLOBALS['mobile']=true;
 $class::render_term('',67125,$native,'fr');
 assertion($native_calls===5,'device type isolates fragment');
 $class::render_term('',25102,$native,'en');
 assertion($native_calls===6,'price listing never cached');
 $GLOBALS['term']=new WP_Term(202,'tour-types');
 $class::render_term('',67125,$native,'en');
 assertion($native_calls===7,'unexpected taxonomy never cached');
 assertion(count($GLOBALS['writes'])===5 &&
  count(array_filter($GLOBALS['writes'],static fn($x)=>$x[1]!==45))===0,'short 45s TTL only');
 assertion((new \ReflectionMethod($class,'eligible'))->isPrivate(),'scoped eligibility is private');
 $src=file_get_contents($source);
 foreach(['HTTP_COOKIE','HTTP_AUTHORIZATION','HTTP_X_MAD4B_CURRENCY','HTTP_X_GEO_COUNTRY','HTTP_CF_IPCOUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY','is_user_logged_in()','wp_doing_ajax()','wp_get_environment_type()','is_post_type_archive','HTTP_X_MAD4B_NAV_CACHE_CONTROL'] as $token) {
  assertion(strpos($src,$token)!==false,'source contains fail-closed guard '. $token);
 }
}
