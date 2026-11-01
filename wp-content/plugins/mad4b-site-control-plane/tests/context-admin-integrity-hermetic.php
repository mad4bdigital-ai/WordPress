<?php
/** Original WordPress admin handler fixture plus cross-request denial checks. */
require __DIR__ . '/context-admin-recovery-runtime.php';
$count=0;
function guard_admin($label,$ok){global $count;if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}$count++;}
$GLOBALS['allowed']=false;$GLOBALS['nonce_valid']=true;
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['source_id'=>'second-source','write_policy'=>'read_only'];
$before=MAD4B_SCP_Context_Authority::$sources;
try{MAD4B_SCP_Context_Admin_UI::handle_update_source_policy();guard_admin('denied handler must terminate',false);}
catch(JsonResult $response){guard_admin('unprivileged source edit denied',!$response->success);}
guard_admin('unprivileged edit leaves sources unchanged',$before===MAD4B_SCP_Context_Authority::$sources);
$GLOBALS['allowed']=true;$GLOBALS['nonce_valid']=false;
try{MAD4B_SCP_Context_Admin_UI::handle_update_source_policy();guard_admin('nonce denied handler must terminate',false);}
catch(JsonResult $response){guard_admin('nonce invalid source edit denied',!$response->success);}
guard_admin('nonce failure leaves stored sources unchanged',$before===MAD4B_SCP_Context_Authority::$sources);
$GLOBALS['nonce_valid']=true;
MAD4B_SCP_Google_Drive_Context::$mode='managed_google';
MAD4B_SCP_Google_Drive_Context::$connected=false;
MAD4B_SCP_Google_Drive_Context::$managed=true;
$google=render('render_google_drive');
guard_admin('safe Google login UI has primary form',strpos($google,'mad4b-google-primary-signin-form')!==false);
guard_admin('no secret fields rendered in Google sign in',strpos($google,'client_secret')===false&&strpos($google,'refresh_token')===false);
$rows=views(render('render_sources'));
guard_admin('source form readback remains independently typed',count($rows)===2);
MAD4B_SCP_Google_Drive_Context::$connected=true;
MAD4B_SCP_Staging_Write_Authority::$current=false;
guard_admin('stale runtime authority never silently accepted',strpos(render('render_write_governance_readiness'),'runtime_authority_not_reconciled')!==false);
echo 'CONTEXT_ADMIN_INTEGRITY: PASS '.$count.PHP_EOL;
