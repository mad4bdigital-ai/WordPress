<?php
/** QA31 W1 — Hermetic native owner browser gate, fail-closed outside approved context. */
declare(strict_types=1);
define('ABSPATH', '/tmp/qa31-owner-boundary');
define('WP_ENVIRONMENT_TYPE', 'staging');
define('LOGGED_IN_COOKIE', 'qa31_cookie');
$GLOBALS['qa31_env'] = 'staging';
$GLOBALS['qa31_caps'] = ['manage_options'=>true, 'activate_plugins'=>true];
$GLOBALS['qa31_user'] = 7;
$GLOBALS['qa31_cookie_valid'] = 7;
$GLOBALS['qa31_nonce_valid'] = true;
$_COOKIE[LOGGED_IN_COOKIE] = 'fixture-cookie-not-real';
$_SERVER['HTTP_X_WP_NONCE'] = 'fixture-nonce-not-real';
function wp_get_environment_type(): string { return $GLOBALS['qa31_env']; }
function current_user_can($cap): bool { return !empty($GLOBALS['qa31_caps'][$cap]); }
function wp_validate_auth_cookie($cookie, $scheme) { return $cookie && $scheme === 'logged_in' ? $GLOBALS['qa31_cookie_valid'] : false; }
function get_current_user_id(): int { return $GLOBALS['qa31_user']; }
function wp_get_session_token(): string { return 'hermetic-session'; }
function wp_verify_nonce($nonce, $action): bool { return $GLOBALS['qa31_nonce_valid'] && $nonce === 'fixture-nonce-not-real' && $action === 'wp_rest'; }
function add_action($hook,$callback,$priority=10,$args=1): bool{return true;}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-company-owner-activation.php';
function qa31_assert(bool $condition, string $name): void { if(!$condition){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n"; }
$c='MAD4B_SCP_Company_Owner_Activation';
qa31_assert($c::browserOwnerAuthorized(),'matching logged-in owner with nonce accepted in staging');
$GLOBALS['qa31_env']='production';
qa31_assert(!$c::browserOwnerAuthorized(),'production denied');
$GLOBALS['qa31_env']='staging';
unset($_SERVER['HTTP_X_WP_NONCE']);
qa31_assert(!$c::browserOwnerAuthorized(),'missing REST nonce denied');
$_SERVER['HTTP_X_WP_NONCE']='fixture-nonce-not-real';
$GLOBALS['qa31_nonce_valid']=false;
qa31_assert(!$c::browserOwnerAuthorized(),'invalid nonce denied');
$GLOBALS['qa31_nonce_valid']=true;
$GLOBALS['qa31_caps']['activate_plugins']=false;
qa31_assert(!$c::browserOwnerAuthorized(),'missing plugin activation permission denied');
$GLOBALS['qa31_caps']['activate_plugins']=true;
$GLOBALS['qa31_cookie_valid']=8;
qa31_assert(!$c::browserOwnerAuthorized(),'different authenticated cookie user denied');
$GLOBALS['qa31_cookie_valid']=false;
qa31_assert(!$c::browserOwnerAuthorized(),'invalid authentication cookie denied');
echo "QA31_W1_OWNER_BROWSER_GATE: PASS\n";
