<?php
require $argv[1];require $argv[2];
$c='MAD4B_Governor_Navigation_Card_Contract_V1';$a='MAD4B_Governor_Navigation_Card_Authority_V1';
function check($x,$m){if(!$x)throw new RuntimeException($m);echo "PASS $m\n";}
$ctx=['environment'=>'staging','anonymous'=>true,'native_navigation_scope'=>true,'original_governor_admitted'=>true,'market_attested'=>true,'route_kind'=>'tour','request_method'=>'GET','listing_id'=>67125,'taxonomy'=>'destinations','term_id'=>665,'site_uuid'=>'11111111-2222-3333-4444-555555555555','origin'=>'https://example.test/','language'=>'en','locale'=>'en_US','device'=>'mobile','country'=>'DE','currency'=>'USD','generation'=>'source:1','page_id'=>60623];
foreach(['template_sha','term_dependency_sha','runtime_dependency_sha','market_policy_sha','asset_manifest_sha'] as $k)$ctx[$k]=hash('sha256',$k);
$i=$c::identity($ctx);$key=$c::key($i);$now=time();
$r=['contract'=>$c::CONTRACT,'full_renderer_bytes_verified'=>true,'asset_parity_verified'=>true,'browser_parity_verified'=>true,'identity'=>$i,'evidence_sha'=>hash('sha256','fixture only'),'html_sha'=>hash('sha256','fixture html'),'expires_at'=>$now+120,'verified_page_ids'=>[60623,57562]];
$cfg=['navigation_card_only'=>true,'tour_only'=>true,'activation'=>'active','elementor_sha256'=>$ctx['template_sha'],'taxonomies'=>['destinations']];
$p=['contract'=>'mad4b.render-query.public-fragments.v1','status'=>'active','environment'=>'staging','site_host'=>'example.test','ttl_seconds'=>300,'templates'=>[67125=>$cfg]];
$audit=fn($id,$cfg)=>$id===67125;
$f=fn($p,$x=null,$cb=null)=>$a::resolve($p,$x??$ctx,$cb??$audit,$now);
check($f($p)['reason']==='native_receipt_missing','no receipt remains native');
$p['navigation_card_receipts'][$key]=['status'=>'approved','receipt'=>$r,'receipt_sha256'=>hash('sha256',json_encode($r,JSON_UNESCAPED_SLASHES))];
check($f($p)['ready'],'original manifest pin and native audit admit fixture');
$x=$ctx;$x['original_governor_admitted']=false;check($f($p,$x)['ready'],'native audit derives admission rather than caller flag');
check($f($p,$ctx,fn()=>false)['reason']==='native_template_audit','failed native audit cannot be overridden');
$q=$p;unset($q['templates'][67125]);check($f($q)['reason']==='native_template_not_enrolled','unenrolled listing rejected');
$q=$p;$q['templates'][67125]['navigation_card_only']=false;check(!$f($q)['ready'],'generic fragment enrollment not enough');
$q=$p;$q['site_host']='other.test';check(!$f($q)['ready'],'tenant host mismatch');
$q=$p;$q['environment']='production';check(!$f($q)['ready'],'production denied');
$q=$p;$q['navigation_card_receipts'][$key]['receipt']['html_sha']=str_repeat('a',64);check($f($q)['reason']==='receipt_pin_mismatch','changed receipt denied');
$q=$p;$q['navigation_card_receipts'][$key]['receipt']['authority_verified']=true;$q['navigation_card_receipts'][$key]['receipt_sha256']=hash('sha256',json_encode($q['navigation_card_receipts'][$key]['receipt'],JSON_UNESCAPED_SLASHES));check($f($q)['reason']==='self_asserted_authority','receipt self assertion rejected');
$q=$p;$q['navigation_card_receipts'][$key]['status']='pending';check(!$f($q)['ready'],'pending proof not authority');
$x=$ctx;$x['page_id']=999;check($f($p,$x)['reason']==='receipt_ineligible','page outside certified cohort rejected');
$x=$ctx;$x['country']='EG';check(!$f($p,$x)['ready'],'market mismatch');
$x=$ctx;$x['native_navigation_scope']=false;check(!$f($p,$x)['ready'],'outside native navigation denied');
echo "ALL_AUTHORITY_TESTS_PASS\n";
