<?php
namespace DynamicFilterSEOBridge\Context { class FilterContextBuilder {} }
namespace DynamicFilterSEOBridge\Presentation { class PresentationResolver {} class ContentSlotRegistry {} }
namespace {
 function register_rest_route($namespace,$route,$args){$GLOBALS['routes'][$namespace.$route]=$args;}
 function check($ok,$msg){if(!$ok)throw new \RuntimeException($msg);}
 require dirname(__DIR__).'/includes/Presentation/AjaxPresentationEndpoint.php';
 use DynamicFilterSEOBridge\Presentation\AjaxPresentationEndpoint as Endpoint;
 $controller=new Endpoint(new \DynamicFilterSEOBridge\Context\FilterContextBuilder(),new \DynamicFilterSEOBridge\Presentation\PresentationResolver(),new \DynamicFilterSEOBridge\Presentation\ContentSlotRegistry());
 $controller->routes();$routes=$GLOBALS['routes'];
 check(Endpoint::restPath()==='dfsb/v1/ajax-presentation','canonical path');
 check(Endpoint::legacyRestPath()==='etg-dfsb/v1/ajax-presentation','historical path');
 check(count($routes)===2&&$routes[Endpoint::restPath()]===$routes[Endpoint::legacyRestPath()],'exact same route descriptor');
 check($routes[Endpoint::restPath()]['callback']===[$controller,'handle'],'single native handler');
 check($routes[Endpoint::restPath()]['methods']==='POST'&&$routes[Endpoint::restPath()]['permission_callback']==='__return_true','unchanged route contract');
 check(Endpoint::CONTRACT==='etg.dfsb.ajax-presentation.v1'&&Endpoint::CANONICAL_CONTRACT==='dfsb.ajax-presentation.v1','wire identity continuity');
 echo 'DFSB_NEUTRAL_REST_ROUTE: PASS'.PHP_EOL;
}
