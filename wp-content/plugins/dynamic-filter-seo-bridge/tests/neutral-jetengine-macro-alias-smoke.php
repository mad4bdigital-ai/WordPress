<?php
namespace DynamicFilterSEOBridge\Presentation {
 class PresentationResolver {
  public function slot($id){return 'slot:'.$id;}
  public function value($key){return 'value:'.$key;}
  public function context(){return ['active'=>true,'in_scope'=>true,'runtime_ready'=>true,'filters'=>['location'=>12],'terms'=>['location'=>['term_id'=>12,'slug'=>'example']]];}
  public function slotRows($id,$alias){return [['id'=>$id,'alias'=>$alias]];}
 }
}
namespace {
 function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['filters'][$name][$priority][]=$callback;}
 function absint($x){return abs((int)$x);}
 function add_action($name,$callback,$priority=10,$args=1){}
 function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$x));}
 abstract class Jet_Engine_Base_Macros {
  public function __construct(){add_filter('jet-engine/listings/macros-list',[$this,'register_macros']);}
  abstract public function macros_tag();abstract public function macros_name();abstract public function macros_callback($args=[]);abstract public function macros_args();
  public function register_macros($list){$list[$this->macros_tag()]=['label'=>$this->macros_name(),'cb'=>[$this,'_macros_callback'],'args'=>$this->macros_args()];return $list;}
  public function _macros_callback($args=[]){return $this->macros_callback($args);}
 }
 function check($ok,$msg){if(!$ok)throw new \RuntimeException($msg);}
 require dirname(__DIR__).'/includes/JetEngine/ListingIntegration.php';
 $integration=new \DynamicFilterSEOBridge\JetEngine\ListingIntegration(new \DynamicFilterSEOBridge\Presentation\PresentationResolver());
 $integration->registerMacros();$filters=$GLOBALS['filters']['jet-engine/listings/macros-list'];ksort($filters);
 $list=[];foreach($filters as $group)foreach($group as $fn)$list=$fn($list);
 check(count($list)===18,'nine exact aliases and nine original entries');
 foreach($list as $id=>$entry){if(strpos($id,'etg_')!==0)continue;$new='dfsb_'.substr($id,4);check($list[$new]===$entry,'exact callback and schema: '.$id);}
 check(call_user_func($list['dfsb_content_slot']['cb'],['slot_id'=>'hero'])==='slot:hero','neutral callback works');
 check(call_user_func($list['dfsb_filter_term_id']['cb'],['role'=>'location'])==='12','neutral selected term works');
 $alias=$filters[20][0];$foreign=['label'=>'External','cb'=>static function(){return 'other';}];$list['dfsb_content_slot']=$foreign;$list['etg_filter_value']=$foreign;unset($list['dfsb_filter_value']);$list=$alias($list);
 check($list['dfsb_content_slot']===$foreign,'existing alias never overwritten');check(!isset($list['dfsb_filter_value']),'foreign legacy callback never aliased');
 $integration->register();
 $contexts=$integration->allowedContexts(['dfsb-filter-context'=>'External label']);
 check($contexts['dfsb-filter-context']==='External label','context label collision preserved');
 $contexts=$integration->allowedContexts([]);
 check(isset($contexts['dfsb-filter-context'],$contexts['etg-filter-context']),'both context names');
 $old=$integration->filterContextObject();$new=$integration->neutralFilterContextObject();
 check(is_object($old)&&is_object($new)&&$new->dfsb_context===true,'neutral context marker');
 unset($new->dfsb_context);check($old==$new&&$old->authorizing===false,'same context payload without authority');
 $foreignObject=(object)['external'=>true];check($integration->neutralFilterContextObject($foreignObject)===$foreignObject,'foreign context preserved');
 $fallback=[['original'=>true]];$expected=[['id'=>'hero','alias'=>'cards']];
 foreach([['etg_dfsb_slot'=>'hero','etg_dfsb_source'=>'cards'],['dfsb_slot'=>'hero','dfsb_source'=>'cards'],['_css_classes'=>'etg-source--hero--cards'],['_css_classes'=>'dfsb-source--hero--cards'],['source'=>'etg-slot:hero:cards'],['source'=>'dfsb-slot:hero:cards'],['dfsb_slot'=>'hero','dfsb_source'=>'cards','source'=>'etg-slot:hero:cards']] as $settings)check($integration->repeaterRows($fallback,$settings)===$expected,'same repeater rows');
 foreach([['dfsb_slot'=>'hero'],['source'=>'dfsb-slot:hero'],['dfsb_slot'=>'hero','dfsb_source'=>'cards','source'=>'etg-slot:other:cards'],['dfsb_slot'=>'hero','dfsb_source'=>'cards','source'=>'dfsb-slot:other:cards'],['_css_classes'=>'dfsb-source--hero--cards dfsb-source--other--cards']] as $settings)check($integration->repeaterRows($fallback,$settings)===$fallback,'invalid or conflicting identifiers preserve original items');
 check($integration::repeaterMarker('hero','cards')==='dfsb-source--hero--cards','new marker default');
 echo 'DFSB_NEUTRAL_LISTING_CONTEXT_REPEATER: PASS'.PHP_EOL;
 echo 'DFSB_NEUTRAL_JETENGINE_MACROS: PASS (9 aliases, original callbacks, collision guards)'.PHP_EOL;
}
