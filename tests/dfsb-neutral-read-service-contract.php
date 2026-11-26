<?php
/** Source signature review only; no WordPress bootstrap or authority issuance. */
declare(strict_types=1);
$root=dirname(__DIR__).'/wp-content/plugins/dynamic-filter-seo-bridge/includes';
define('ABSPATH', __DIR__.'/');
spl_autoload_register(static function($class)use($root){
    $prefix='DynamicFilterSEOBridge\\';
    if(strpos($class,$prefix)!==0)return;
    $file=$root.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});
$contracts=[
 'Bootstrap'=>['instance'=>[0,true],'readiness'=>[0,false]],
 'Config\\Configuration'=>['enabled'=>[0,false],'revision'=>[0,false],'validationErrors'=>[0,false],'all'=>[0,false]],
 'Config\\ProfileRegistry'=>['__construct'=>[1,false],'all'=>[0,false],'validationErrors'=>[0,false],'discovery'=>[0,false],'blueprint'=>[3,false]],
 'Diagnostics\\RuntimeInventory'=>['collect'=>[0,false]],
 'Diagnostics\\BuildIdentity'=>['collect'=>[0,true]],
 'Diagnostics\\InventoryProfilePlanner'=>['plan'=>[2,false]],
 'Presentation\\InventoryContentCatalog'=>['build'=>[2,false]],
 'Presentation\\ContentSlotRegistry'=>['normalize'=>[1,false],'get'=>[1,false],'revision'=>[0,false]],
];
$checked=0;
foreach($contracts as $name=>$methods){
 $class=new ReflectionClass('DynamicFilterSEOBridge\\'.$name);
 if(strpos((string)realpath($class->getFileName()),realpath($root).DIRECTORY_SEPARATOR)!==0)throw new RuntimeException('foreign class source: '.$name);
 foreach($methods as $method=>[$argc,$static]){
  $m=$class->getMethod($method);
  if(!$m->isPublic()||$m->isStatic()!==$static||$m->getNumberOfRequiredParameters()>$argc||$m->getNumberOfParameters()<$argc)throw new RuntimeException('method shape drift: '.$name.'::'.$method);
  ++$checked;
 }
}
echo json_encode(['contract'=>'mad4b.dfsb.neutral-read-source-shape-review.v1','classes_checked'=>count($contracts),'method_shapes_checked'=>$checked,'source_shape_compatible'=>true,'runtime_behavior_verified'=>false,'provider_certified'=>false,'authorizing'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
