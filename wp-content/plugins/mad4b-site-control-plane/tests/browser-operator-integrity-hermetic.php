<?php
/** Reuse the executable original-class operator acceptance suite, add adversarial isolation. */
require __DIR__ . '/browser-acceptance-admin-setup-contract.php';
$c = 'MAD4B_SCP_Browser_Acceptance_Admin_UI';
$n = 0;
function check_additional($what,$yes) { global $n; if(!$yes) { fwrite(STDERR,'FAIL '. $what .PHP_EOL); exit(1); } $n++; }
$GLOBALS['browser_setting'][$c::OPTION] = array('executor'=>'auto','profile_id'=>'profile-a','site_provider_id'=>'site-a','configuration_revision'=>str_repeat('b',32));
check_additional('exact target authorized for matching operator choice',$c::runtime_target_guard('site-a','profile-a')===true);
check_additional('operator target is case sensitive',is_wp_error($c::runtime_target_guard('Site-A','profile-a')));
check_additional('operator profile cannot be broadened',is_wp_error($c::runtime_target_guard('site-a','profile-b')));
check_additional('an invalid persisted secret blocks runtime',is_wp_error($c::normalize(array('executor'=>'auto','secret'=>'DO_NOT_USE'))));
check_additional('a list-valued source profile cannot pass validation',is_wp_error($c::normalize(array('profile_id'=>array('profile-a')))));
check_additional('operator revision lowercase-only',is_wp_error($c::normalize(array('configuration_revision'=>str_repeat('A',32)))));
check_additional('snapshot revision matches stored revision',$c::public_selection()['configuration_revision']===str_repeat('b',32));
check_additional('operator preference never grants credentials',!$c::public_selection()['credential_verified']);
check_additional('operator preference never runs external browser',!$c::public_selection()['external_runner_connected']);
check_additional('operator preference never certifies a site provider',!$c::public_selection()['site_provider_registered_by_preference']);
check_additional('operator preference never permits mutation',!$c::public_selection()['authorizing']);
check_additional('stale expected revision always rejected',is_wp_error($c::revision_guard(str_repeat('a',32),$GLOBALS['browser_setting'][$c::OPTION])));
$selected=$c::selection();
check_additional('saved selection contains no auth token',!array_key_exists('token',$selected)&&!array_key_exists('api_key',$selected));
$GLOBALS['browser_setting'][$c::OPTION] = 'garbled';
check_additional('garbled option cannot authorize execution',is_wp_error($c::runtime_target_guard('site-a','profile-a')));
check_additional('garbled option reported invalid to operator',$c::public_selection()['preference_valid']===false);
echo 'BROWSER_OPERATOR_INTEGRITY: PASS '.$n.PHP_EOL;
