<?php
define('ABSPATH',__DIR__.'/');
$state=[];$mode='normal';$writes=0;
function get_option($key,$default=false){return $GLOBALS['state'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['writes']++;if($GLOBALS['mode']==='fail')return false;$GLOBALS['state'][$key]=$value;return $GLOBALS['mode']!=='false_after_write';}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
require dirname(__DIR__).'/includes/Runtime/LegacyOptionMirror.php';
require dirname(__DIR__).'/includes/Presentation/MediaDiscoveryRegistry.php';
use DynamicFilterSEOBridge\Presentation\MediaDiscoveryRegistry as Registry;
function verify($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$r=new Registry();$input=['image'=>['HeroImage'],'gallery'=>['GalleryKey']];
$first=$r->save($input);verify($first['saved']===true&&$first['settings']===$r->all(),'verified save');
$before=$writes;verify($r->save($input)['unchanged']===true&&$writes===$before,'no-op');
$mode='fail';$failed=$r->save(['image'=>['Different']]);verify($failed['saved']===false&&$failed['settings']===$first['settings'],'failed write returns observed values');
$mode='false_after_write';$observed=$r->save(['image'=>['NewImage']]);verify($observed['saved']===true&&$observed['settings']===$r->all(),'matching readback');
verify(!isset($state[Registry::NEUTRAL_OPTION_NAME]),'no separate writer');
echo 'DFSB_MEDIA_PERSISTENCE_READBACK: PASS'.PHP_EOL;
