<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function maybe_unserialize($value){return $value;}
function etg_tm_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function etg_tm_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: {$message}\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$GLOBALS['etg_tm_meta']=array(
    101=>array(
        'hero_Label'=>array('Pyramids Hero'),
        'secret_token'=>array('must-not-surface'),
        'structured_meta'=>array('{"nested":true}'),
        'empty_meta'=>array(''),
    ),
    102=>array(
        'secondary_key'=>array('Secondary'),
    ),
);

function get_terms($args=array()){
    return array(101,102);
}

function get_term_meta($termId,$key='',$single=false){
    $all=(array)($GLOBALS['etg_tm_meta'][(int)$termId]??array());
    if(''===$key){return $all;}
    $values=(array)($all[$key]??array());
    if(!$single){return $values;}
    return $values?reset($values):'';
}

$root=dirname(__DIR__);
require_once $root.'/includes/Identifiers/FieldKey.php';
require_once $root.'/includes/Identifiers/PresentationToken.php';
require_once $root.'/includes/Identifiers/QueryId.php';
require_once $root.'/includes/Presentation/InventoryContentCatalog.php';
require_once $root.'/includes/Presentation/MediaAssetValidator.php';
require_once $root.'/includes/Presentation/PresentationResolver.php';

use ETG\DynamicFilterSEOBridge\Presentation\InventoryContentCatalog;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

$catalog=(new InventoryContentCatalog())->build(
    array(
        'snapshot_fingerprint'=>'term-meta-smoke',
        'inventory'=>array(
            'taxonomies'=>array('location_jet'=>array()),
            'elementor_topology'=>array('bindings'=>array()),
        ),
    ),
    array(
        'tours'=>array(
            'id'=>'tours',
            'taxonomy_rules'=>array(
                'location_jet'=>array('role'=>'location'),
            ),
            'routes'=>array(),
        ),
    )
);

$tokens=(array)($catalog['tokens']??array());
etg_tm_expect(isset($tokens['termmeta:location:hero_Label']),'case-sensitive scalar Term meta key is discovered');
etg_tm_same('term-meta',(string)($tokens['termmeta:location:hero_Label']['source']??''),'Term meta selector source is explicit');
etg_tm_same('hero_Label',(string)($tokens['termmeta:location:hero_Label']['evidence']['field_key']??''),'catalog evidence preserves exact field key identity');
etg_tm_expect(isset($tokens['termmeta:location:secondary_key']),'bounded discovery includes scalar keys from sampled Terms');
etg_tm_expect(!isset($tokens['termmeta:location:secret_token']),'sensitive-looking Term meta key is excluded');
etg_tm_expect(!isset($tokens['termmeta:location:structured_meta']),'structured/non-scalar JSON Term meta is excluded');
etg_tm_expect(!isset($tokens['termmeta:location:empty_meta']),'empty Term meta is excluded');

$reflection=new ReflectionClass(PresentationResolver::class);
$resolver=$reflection->newInstanceWithoutConstructor();
$method=$reflection->getMethod('termMeta');
$method->setAccessible(true);
$context=array('terms'=>array('location'=>array('term_id'=>101)));
etg_tm_same('Pyramids Hero',$method->invoke($resolver,$context,'location','hero_Label'),'runtime Term meta value resolution preserves exact key and returns scalar value');
etg_tm_same('',$method->invoke($resolver,$context,'location','structured_meta'),'runtime Term meta value resolution rejects non-scalar values');
etg_tm_same('',$method->invoke($resolver,$context,'location','bad/key'),'runtime Term meta value resolution rejects malformed field keys');

$tagSource=file_get_contents($root.'/includes/Elementor/DynamicTags/TermMetaTag.php');
$runtimeSource=file_get_contents($root.'/includes/Elementor/DynamicTags/DynamicTagRuntime.php');
$endpointSource=file_get_contents($root.'/includes/Presentation/AjaxPresentationEndpoint.php');
$endpointCompact=preg_replace('/\s+/','',$endpointSource);

etg_tm_expect(false!==strpos($tagSource,'DynamicTagRuntime::termMetaOptions()'),'Elementor Term Meta selector is inventory-driven');
etg_tm_expect(false!==strpos($tagSource,"0!==strpos(\$token,'termmeta:')"),'Elementor Term Meta tag fails closed for non-Term-meta tokens');
etg_tm_expect(false!==strpos($runtimeSource,"tokenOptionsBySource('term-meta')"),'Dynamic Tag runtime exposes only Term-meta catalog entries to the selector');
etg_tm_expect(false!==strpos($endpointCompact,"if(0===strpos(\$token,'termmeta:'))"),'AJAX endpoint has an explicit Term-meta token branch');
etg_tm_expect(false!==strpos($endpointCompact,'$this->catalogContains($token)'),'AJAX Term-meta tokens must exist in the Runtime Inventory catalog');
etg_tm_expect(false!==strpos($endpointSource,'catalogTokenMeta'),'AJAX response typing remains catalog-driven');

echo "Alpha13 Term Meta Dynamic Tag selector/value/AJAX smoke tests passed.\n";
