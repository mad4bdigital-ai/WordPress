<?php
define('ABSPATH','/');function add_action(...$a){}
class MAD4B_Page_Dependency_Cache {public static array $edges=[];public static function depend($key){self::$edges[$key]=true;}}
require $argv[1];require $argv[2];
function ok($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
foreach(['MAD4B_Compiled_Tour_Family_V1','MAD4B_Mixed_Family_Public_Islands_V1'] as $i=>$c){
 (new ReflectionProperty($c,'tour'))->setValue(60623);MAD4B_Page_Dependency_Cache::$edges=[];
 $m=new ReflectionMethod($c,'record_dependencies');
 $args=$i?[24784,[45929],[101,102]]:[24784,48366,[45929],[101,102]];
 $m->invokeArgs(null,$args);$first=MAD4B_Page_Dependency_Cache::$edges;
 $expected=['post:60623','template:24784','template:45929','listing:45929','term:destinations:101','term:destinations:102'];
 if(!$i)$expected[]='template:48366';
 ok(count($first)===count($expected)&&!array_diff($expected,array_keys($first)),'source-bound edge set '.$c);
 $m->invokeArgs(null,$args);ok($first===MAD4B_Page_Dependency_Cache::$edges,'repeat does not duplicate edges '.$c);
 $s=file_get_contents($argv[$i+1]);ok(strpos($s,'self::record_dependencies(')<strpos($s,"self::receipt('HIT'"),'edge registration precedes hit path '.$c);
}
echo "PASS 6/6 fragment graph invariants\n";
