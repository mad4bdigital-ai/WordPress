<?php
namespace Jet_Engine\Query_Builder {
 class Manager {
  public static $q;
  public static function instance(){return new self;}
  public function get_query_by_id($id){return $id===291?self::$q:null;}
 }
}
namespace {
 class MAD4B_Published_Content_Scope_Cache {public static $active=false;public static function hybrid_eligible(){return self::$active;}}
 $GLOBALS['revision']='rev-123';
 function get_option($name,$default){return $GLOBALS['revision'];}
 function get_taxonomy($tax){return $tax==='destinations'?(object)['public'=>true]:null;}
 function mad4b_render_query_sensitive_semantics($q){return $q->name==='Pricing';}
 $raw=file_get_contents(getenv('MAD4B_GOVERNOR_SOURCE') ?: dirname(__DIR__,3).'/ops/tenants/wayfatours/mu-plugins/mad4b-render-query-governor.php');
 $a=strpos($raw,'function mad4b_render_query_generation_for_id(');
 $b=strpos($raw,"\nadd_action( 'jet-engine/query-builder/query/after-query-setup'",$a);
 if($a===false||$b===false)throw new \RuntimeException('missing exact function');
 eval(substr($raw,$a,$b-$a));
 $q=(object)['id'=>291,'query_type'=>'terms','query'=>['taxonomy'=>['destinations']],'name'=>'Attractions'];
 \Jet_Engine\Query_Builder\Manager::$q=$q;
 $known=[20=>[['function'=>['MAD4B_Published_Content_Scope_Cache','query_version']]],30=>[['function'=>['MAD4B_Page_Dependency_Cache','query_version']]]];
 $wp_filter=['mad4b/render_query/query_version'=>(object)['callbacks'=>$known]];
 function chk($v,$name){if(!$v)throw new \RuntimeException($name);echo "PASS $name\n";}
 chk(mad4b_render_query_generation_for_id(291)==='rev-123','native global revision returned');
 $GLOBALS['revision']='rev-456';chk(mad4b_render_query_generation_for_id(291)==='rev-456','new native revision invalidates');
 chk(mad4b_render_query_generation_for_id(999)==='','unknown query rejected');
 chk(mad4b_render_query_generation_for_id(0)==='','invalid query rejected');
 $q->query['taxonomy']=['prices'];chk(mad4b_render_query_generation_for_id(291)==='','commercial taxonomy rejected');
 $q->query['taxonomy']=['private'];chk(mad4b_render_query_generation_for_id(291)==='','nonpublic taxonomy rejected');
 $q->query['taxonomy']=['destinations'];$q->name='Pricing';chk(mad4b_render_query_generation_for_id(291)==='','sensitive semantics rejected');
 $q->name='Attractions';$q->query_type='posts';chk(mad4b_render_query_generation_for_id(291)==='','unreviewed query type rejected');
 $q->query_type='terms';MAD4B_Published_Content_Scope_Cache::$active=true;chk(mad4b_render_query_generation_for_id(291)==='','scoped owner not guessed');
 MAD4B_Published_Content_Scope_Cache::$active=false;$wp_filter['mad4b/render_query/query_version']->callbacks[40]=[['function'=>'unknown']];chk(mad4b_render_query_generation_for_id(291)==='','unknown version filter rejected');
 $wp_filter['mad4b/render_query/query_version']->callbacks=$known;unset($wp_filter['mad4b/render_query/query_version']->callbacks[30]);chk(mad4b_render_query_generation_for_id(291)==='','missing owner filter rejected');
 $wp_filter['mad4b/render_query/query_version']->callbacks=$known;$GLOBALS['revision']='';chk(mad4b_render_query_generation_for_id(291)==='','empty revision rejected');
 echo "PASS 12/12 native generation compatibility invariants\n";
}
