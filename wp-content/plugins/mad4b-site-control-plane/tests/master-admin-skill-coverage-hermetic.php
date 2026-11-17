<?php
define('ABSPATH','/fixture/');
require __DIR__.'/../includes/class-mad4b-scp-skills-admin-ui.php';
$n=0;function ok($cond,$msg){global $n;if(!$cond){fwrite(STDERR,"FAIL $msg\n");exit(1);}++$n;echo "PASS $msg\n";}
$manifest=[
 ['level'=>'site','target'=>'_site','name'=>'wordpress-site-diagnostics','enabled'=>true],
 ['level'=>'workflow','target'=>'nile-cruise-publish','name'=>'nile-cruise-rate-readiness','enabled'=>true],
 ['level'=>'workflow','target'=>'booking-to-cash','name'=>'booking-to-cash-reconciliation','enabled'=>true],
 ['level'=>'provider','target'=>'elementor','name'=>'elementor-dynamic-content','enabled'=>false],
];
$rows=[['level'=>'site','target'=>'_site','name'=>'wordpress-site-diagnostics','enabled'=>true]];
$base=MAD4B_SCP_Skills_Admin_UI::coverage_plan($rows,$manifest,false);
ok($base['read_only']&&!$base['execution_allowed'],'Non-authorizing advisory');
ok($base['manifest_rows']===4&&$base['available_rows']===1,'Exact counts');
ok($base['missing_required']===['workflow:booking-to-cash:booking-to-cash-reconciliation'],'Non-cruise organization not forced into Nile');
ok(count($base['missing_optional'])===2,'Optional packs separate');
ok($base['next_action']==='inspect_exact_seed_pack_before_governed_reconcile','Missing pack needs reviewed source');
$cruise=MAD4B_SCP_Skills_Admin_UI::coverage_plan($rows,$manifest,true);
ok(count($cruise['missing_required'])===2,'Cruise provider requires Nile coverage');
ok(count($cruise['missing_optional'])===1,'Disabled provider seed stays optional');
$rows[]=['level'=>'workflow','target'=>'booking-to-cash','name'=>'booking-to-cash-reconciliation','enabled'=>false];
ok(count(MAD4B_SCP_Skills_Admin_UI::coverage_plan($rows,$manifest,false)['missing_required'])===1,'Disabled required skill reported');
$rows[1]['enabled']=true;
$done=MAD4B_SCP_Skills_Admin_UI::coverage_plan($rows,$manifest,false);
ok(!$done['missing_required']&&$done['next_action']==='none','Ready catalog no remediation needed');
echo "PASS $n company-aware coverage assertions\n";
