<?php
/** Reuse mock-only local client lifecycle with additional five-minute CAS attacks. */
require __DIR__ . '/local-client-credential-lifecycle-runtime.php';
$class='MAD4B_SCP_Local_Client_Admin';
$now=1792980000;
$pending=array('id'=>str_repeat('a',32),'intent'=>'local_client_provision',
  'site_uuid'=>'11111111-1111-4111-8111-111111111111','user_id'=>4,
  'created'=>$now-30,'expires'=>$now+270);
$count=0;
function check_bound($name,$yes){global $count;if(!$yes){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}$count++;}
check_bound('exact request only',$class::valid_pending($pending,str_repeat('a',32),4,$pending['site_uuid'],$now));
check_bound('wrong user denied',!$class::valid_pending($pending,str_repeat('a',32),5,$pending['site_uuid'],$now));
check_bound('wrong site denied',!$class::valid_pending($pending,str_repeat('a',32),4,'other',$now));
check_bound('wrong request nonce denied',!$class::valid_pending($pending,str_repeat('b',32),4,$pending['site_uuid'],$now));
check_bound('nonstring nonce denied',!$class::valid_pending($pending,array('bad'),4,$pending['site_uuid'],$now));
check_bound('corrupt request denied',!$class::valid_pending('corrupt',str_repeat('a',32),4,$pending['site_uuid'],$now));
check_bound('wrong intent denied',!$class::valid_pending(array_merge($pending,array('intent'=>'other')),str_repeat('a',32),4,$pending['site_uuid'],$now));
check_bound('future creation timestamp denied',!$class::valid_pending(array_merge($pending,array('created'=>$now+1)),str_repeat('a',32),4,$pending['site_uuid'],$now));
check_bound('expired request denied',!$class::valid_pending(array_merge($pending,array('created'=>$now-360,'expires'=>$now-1)),str_repeat('a',32),4,$pending['site_uuid'],$now));
check_bound('too long TTL denied',!$class::valid_pending(array_merge($pending,array('expires'=>$now+301)),str_repeat('a',32),4,$pending['site_uuid'],$now));
check_bound('TTL upper boundary allowed',$class::valid_pending(array_merge($pending,array('expires'=>$now+300)),str_repeat('a',32),4,$pending['site_uuid'],$now));
check_bound('no new real or mock credential created',$GLOBALS['lc_created']===1&&$GLOBALS['lc_revoked']===1);
echo 'LOCAL_CLIENT_REQUEST_BOUNDARY: PASS '.$count.PHP_EOL;
