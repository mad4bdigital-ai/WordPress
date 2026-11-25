<?php
require dirname(__DIR__).'/includes/Presentation/AjaxPresentationEndpoint.php';
require dirname(__DIR__).'/includes/Acceptance/BrowserAcceptanceProvider.php';
use DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceProvider;
use DynamicFilterSEOBridge\Presentation\AjaxPresentationEndpoint;
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$r=new ReflectionClass(BrowserAcceptanceProvider::class);$obj=$r->newInstanceWithoutConstructor();$method=$r->getMethod('endpointMatches');
$path='/wp-json/'.AjaxPresentationEndpoint::restPath();
check($method->invoke($obj,$path),'canonical path observed');
check($method->invoke($obj,'https://example.test'.$path),'canonical absolute URL observed');
foreach(['/wp-json/'.AjaxPresentationEndpoint::legacyRestPath(),$path.'-extra','/wp-json/other/v1/ajax-presentation',''] as $wrong)check(!$method->invoke($obj,$wrong),'wrong endpoint rejected');
$cap=$obj->capabilities();check($cap['required_network']===['POST '.$path],'capability route matches');
check(in_array('dfsb/ajax-presentation-updated',$cap['required_events'],true),'actual observer event');
echo 'DFSB_NEUTRAL_BROWSER_ROUTE: PASS'.PHP_EOL;
