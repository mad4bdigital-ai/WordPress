<?php
define('ABSPATH', __DIR__.'/');
$state=array();$writes=0;$events=array();
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['state'])?$GLOBALS['state'][$key]:$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['writes']++;$GLOBALS['state'][$key]=$value;return true;}
function do_action($name,...$args){$GLOBALS['events'][]=$name;}
require dirname(__DIR__).'/includes/Runtime/LegacyOptionMirror.php';
use DynamicFilterSEOBridge\Runtime\LegacyOptionMirror as Mirror;
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$map=Mirror::map();check(count($map)===3,'complete original map');
foreach($map as $old=>$new){
 foreach([[[], 'both_absent'],[[$old=>['a'=>1]],'legacy_only'],[[$new=>['a'=>1]],'neutral_only'],[[$old=>['a'=>1],$new=>['a'=>1]],'equal'],[[$old=>['a'=>1],$new=>['a'=>2]],'conflict'],[[$old=>false],'invalid_value_type']] as [$fixture,$expected]){
  $state=$fixture;$before=$state;$report=Mirror::observe();$row=array_values(array_filter($report['rows'],static function($r)use($new){return $r['neutral_option']===$new;}))[0];
  check($row['state']===$expected,'state '.$expected);check($report['read_only']===true&&$report['authorizing']===false&&$report['cutover_authorized']===false,'non-authorizing');check($state===$before&&$writes===0,'no observation writes');check(count($row)===2,'no values exposed');
 }
 $state=[$new=>['stale'=>true]];check(Mirror::read($old,[])===[],'no resurrection');
 check(Mirror::readNeutral($new,[])===[],'neutral caller does not resurrect stale storage');
 $state=[$old=>['value'=>'current'],$new=>['value'=>'stale']];check(Mirror::readNeutral($new,[])===Mirror::read($old,[]),'conflict preserves original authority');
 $state=[$old=>['value'=>'current']];check(Mirror::readNeutral($new,[])===['value'=>'current'],'neutral caller resolves original-only storage');
 $state[$new]=$state[$old];check(Mirror::readNeutral($new,[])===$state[$old],'equal snapshot');
 check(Mirror::readNeutral($old,'fallback')==='fallback','strict neutral identity');
 check(Mirror::readNeutral('unknown','fallback')==='fallback','unknown identity rejected');
 check($writes===0,'neutral reads never write');
}
echo 'DFSB_OPTION_MIRROR_OBSERVATION: PASS (18 cases, no writes, no cutover)'.PHP_EOL;
