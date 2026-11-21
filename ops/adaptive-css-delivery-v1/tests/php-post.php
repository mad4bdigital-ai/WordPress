function check($v,$description){if (!$v)throw new Exception($description);echo 'PASS '.$description.PHP_EOL;}
$c='MAD4B_Adaptive_CSS_RUM_V1';
check($c::site()==='source','site environment and host are enrolled');
check($c::policy()['mode']==='observe' && $c::policy()['sample_bps']===100,
      'explicit observe mode and 1pct sampling only');
$values=['source',str_repeat('a',64),str_repeat('b',64),'mobile','14','0','0'];
$token=$c::signed_proof($values,time());
check(strlen($token)>180 && $c::verify_proof($token)['site']==='source',
      'first-party signed route and variant proof verifies');
check($c::verify_proof($token.'A')===null,'altered MAC is refused');
check($c::verify_proof($c::signed_proof($values,time()-8000))===null,
      'stale cache header proof expires');
check($c::verify_proof($c::signed_proof($values,time()+200))===null,
      'future proof cannot be accepted');
$old=$c::signed_proof($values,time());
$GLOBALS['genListing']=1;
check($c::verify_proof($old)===null,
      'source listing generation changed after cached page makes proof stale');
$GLOBALS['genListing']=0;
$GLOBALS['genGlobal']=1;
check($c::verify_proof($old)===null,
      'source Elementor global generation changed after cached page makes proof stale');
$GLOBALS['genGlobal']=0;
$values[4]='15';
check($c::verify_proof($c::signed_proof($values,time()))===null,
      'new CSS epoch revokes old source identity');
check($c::bucket('lcp',2500)===4 && $c::bucket('lcp',2501)===5,
      'LCP histogram uses conservative upper bounds');
check($c::bucket('inp',201)===4 && $c::bucket('cls',101)===5,
      'INP and CLS histogram thresholds are bounded');
try{$c::bucket('lcp',-1);throw new Exception('not rejected');}
catch(InvalidArgumentException $e){check(true,'negative telemetry denied');}
try{$c::bucket('invalid',20);throw new Exception('not rejected');}
catch(InvalidArgumentException $e){check(true,'unknown metric denied');}
check($c::p75_bound('lcp',[4=>5,5=>3])===3000,
      'LCP sitewide p75 is conservative histogram bucket upper');
check($c::p75_bound('inp',[0=>3,1=>10])===100,
      'INP sitewide p75 is not claimed beyond bucket precision');
check($c::p75_bound('cls',[])===null,
      'missing CLS samples stay unknown, not zero');
check($c::install_schema()===false,'public path cannot execute schema migration');
echo 'PASS 17 PHP policy and integrity invariants'.PHP_EOL;
?>
