<?php
require $argv[1];
$c='MAD4B_Governor_Navigation_Card_Contract_V1';
function check($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
$ctx=['environment'=>'staging','anonymous'=>true,'native_navigation_scope'=>true,'original_governor_admitted'=>true,'market_attested'=>true,'route_kind'=>'tour','request_method'=>'GET','listing_id'=>67125,'taxonomy'=>'destinations','term_id'=>665,'site_uuid'=>'11111111-2222-3333-4444-555555555555','origin'=>'https://example.test/','language'=>'en','locale'=>'en_US','device'=>'mobile','country'=>'DE','currency'=>'USD','generation'=>'source:1','page_id'=>60623];
foreach(['template_sha','term_dependency_sha','runtime_dependency_sha','market_policy_sha','asset_manifest_sha'] as $k)$ctx[$k]=hash('sha256',$k);
$html='<div class="navigation-editor-card">'.str_repeat('Public term content ',5).'</div>';
$now=time();$receipt=['contract'=>$c::CONTRACT,'authority_verified'=>true,'full_renderer_bytes_verified'=>true,'asset_parity_verified'=>true,'browser_parity_verified'=>true,'identity'=>$c::identity($ctx),'evidence_sha'=>hash('sha256','isolated test only'),'html_sha'=>hash('sha256',$html),'expires_at'=>$now+120,'verified_page_ids'=>[60623,57562]];
check($c::admitted($ctx,$receipt,$now)!==null,'complete mocked receipt admitted');
$b=$ctx;$b['page_id']=57562;check($c::key($c::identity($ctx))===$c::key($c::identity($b))&&$c::admitted($b,$receipt,$now)!==null,'two certified pages share card identity');
$b['page_id']=111;check(!$c::admitted($b,$receipt,$now),'unverified route denied');
foreach(['country'=>'EG','currency'=>'EUR','language'=>'es','device'=>'desktop','generation'=>'source:2','term_dependency_sha'=>str_repeat('1',64),'runtime_dependency_sha'=>str_repeat('2',64),'template_sha'=>str_repeat('3',64),'market_policy_sha'=>str_repeat('4',64),'asset_manifest_sha'=>str_repeat('5',64)] as $k=>$v){$b=$ctx;$b[$k]=$v;check(!$c::admitted($b,$receipt,$now),'changed '.$k.' rejected');}
foreach(['anonymous','native_navigation_scope','original_governor_admitted','market_attested'] as $k){$b=$ctx;$b[$k]=false;check(!$c::admitted($b,$receipt,$now),'missing '.$k.' rejected');}
$b=$ctx;$b['environment']='production';check(!$c::admitted($b,$receipt,$now),'production disabled');
foreach(['authority_verified','full_renderer_bytes_verified','asset_parity_verified','browser_parity_verified'] as $k){$r=$receipt;$r[$k]=false;check(!$c::admitted($ctx,$r,$now),'missing '.$k.' denied');}
$r=$receipt;$r['expires_at']=$now;check(!$c::admitted($ctx,$r,$now),'expired receipt denied');
foreach(['<form></form>','aria-current="page"','elementor-dcss-1234567890123'] as $s)check(!$c::safe_html($html.$s),'sensitive or unstable output denied');
$storage=[];$calls=0;$writes=0;$ttl=0;
$native=function()use(&$calls,$html){$calls++;return $html;};
$read=function($k,$g)use(&$storage){return $storage[$g][$k]??null;};
$write=function($k,$v,$g,$t)use(&$storage,&$writes,&$ttl){$storage[$g][$k]=$v;$writes++;$ttl=$t;};
$fresh=fn()=>$ctx;$assets=fn($r)=>true;
check($c::render($ctx,[],$now,$native,$read,$write,$assets,$fresh)===$html&&$writes===0,'no receipt stays native with no cache writes');
$c::render($ctx,$receipt,$now,$native,$read,$write,$assets,$fresh);
check($writes===1&&$ttl>0&&$ttl<=120,'store exact certified output within receipt lifetime');
$b=$ctx;$b['page_id']=57562;$n=$calls;
check($c::render($b,$receipt,$now,$native,$read,$write,$assets,fn()=>$b)===$html&&$calls===$n,'certified second page reuses card');
$c::render($ctx,$receipt,$now,$native,$read,$write,fn($r)=>false,$fresh);
check($calls===$n+1,'asset failure falls back to original renderer');
$b=$ctx;$b['generation']='source:changed';$w=$writes;$n=$calls;
$c::render($ctx,$receipt,$now,$native,$read,$write,$assets,fn()=>$b);
check($calls===$n+1&&$writes===$w,'source change denies replay and write');
$b=$ctx;$b['page_id']=57562;$w=$writes;$n=$calls;
$c::render($ctx,$receipt,$now,$native,$read,$write,$assets,fn()=>$b);
check($calls===$n+1&&$writes===$w,'page mutation within request denied');
$key=$c::key($c::identity($ctx));$storage[$c::GROUP][$key]=$html.'corrupt';$n=$calls;
$c::render($ctx,$receipt,$now,$native,$read,$write,$assets,$fresh);
check($calls===$n+1,'corrupt cached bytes fall back to native');
echo "ALL_NAVIGATION_CARD_CONTRACT_TESTS_PASS\n";
