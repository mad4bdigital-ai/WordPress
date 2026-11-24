<?php
require $argv[1];$c='MAD4B_Navigation_CSS_Dependency_V1';
function check($x,$m){if(!$x)throw new RuntimeException($m);echo "PASS $m\n";}
$sha=hash('sha256','native bundler fixture');
$src=[];foreach([26212,67125,67124] as $id)$src[]=['handle'=>'elementor-post-'.$id,'body'=>'.elementor-'.$id.'{color:red}','media'=>'all','native_eligible'=>true];
$build=fn($rows)=>implode('',array_map(fn($s)=>"\n/* ".$s['handle']." */\n".$s['body']."\n",$rows));
$i=$c::identity($src,$sha);$b=$build($src);check(is_array($i),'original ordered sources admitted');
check($c::segment_matches($i,$b),'exact native CSS segment');
check($c::continuity($i,$c::identity($src,$sha),$b),'unchanged bytes independent of bundle URL');
$x=$src;$x[1]['body']=str_replace('red','tan',$x[1]['body']);check(!$c::continuity($i,$c::identity($x,$sha),$build($x)),'same-size listing CSS edit invalidates');
$x=$src;$x[0]['body'].='.header{color:blue}';check(!$c::continuity($i,$c::identity($x,$sha),$build($x)),'header CSS edit invalidates');
$x=$src;[$x[1],$x[2]]=[$x[2],$x[1]];check(!$c::continuity($i,$c::identity($x,$sha),$build($x)),'native CSS ordering changes invalidate');
check(!$c::continuity($i,$c::identity($src,str_repeat('a',64)),$b),'runtime dependency change invalidates');
check(!$c::segment_matches($i,$b.'extra'),'unexpected segment bytes rejected');
check(!$c::segment_matches($i,str_replace('red','tan',$b)),'corrupted segment rejected');
$x=$src;unset($x[1]);check($c::identity(array_values($x),$sha)===null,'missing listing CSS rejected');
$x=$src;$x[2]=$x[1];check($c::identity($x,$sha)===null,'duplicate handle rejected');
$x=$src;$x[1]['media']='print';check($c::identity($x,$sha)===null,'unsupported media rejected');
$x=$src;$x[1]['native_eligible']=false;check($c::identity($x,$sha)===null,'original bundler eligibility required');
$x=$src;$x[1]['body']='@import url(example.css);';check($c::identity($x,$sha)===null,'unqualified imports rejected');
check($c::bind($i,str_repeat('b',64))!==$c::bind($i,str_repeat('c',64)),'additional asset changes invalidate binding');
check($c::bind($i,'')===null,'missing other asset manifest denied');
echo "ALL_CSS_DEPENDENCY_TESTS_PASS\n";
