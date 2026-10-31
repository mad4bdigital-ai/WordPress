<?php
/**
 * Source-only hermetic contract: provider certification is optional for
 * installed WordPress plugins with native update offers.
 * No WordPress mutation or network call takes place in this test.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
function add_action(...$args): void {}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Provider_Contracts {
    public static function all(): array { return array(); }
}
class MAD4B_SCP_Remote_Plugin_Update {
    public static $lastInput = null;
    public static $errorCode = '';
    public static $blockers = array();
    public static function plan($input) {
        self::$lastInput = $input;
        if (self::$errorCode !== '') return new WP_Error(self::$errorCode);
        return array(
            'contract' => 'mad4b.plugin-remote-update-plan.v1',
            'operation' => 'remote_update',
            'eligible' => empty(self::$blockers),
            'blockers' => self::$blockers,
            'plugin_file' => $input['plugin_file'],
            'plan_sha256' => str_repeat('b', 64),
        );
    }
}
require __DIR__ . '/../includes/class-mad4b-scp-plugin-update-recovery.php';
function check(bool $value, string $message): void {
    if (!$value) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}
$inventory = MAD4B_SCP_Plugin_Update_Recovery::inventory(
    array('sample/sample.php'=>array('Version'=>'1.0.0')),
    array()
);
check(count($inventory) === 1, 'noncertified plugin discoverable');
check($inventory[0]['certified_provider'] === false, 'noncertified plugin remains noncertified');
check($inventory[0]['provider_certificate_mandatory'] === false, 'certificate is optional');
check($inventory[0]['update_plan_ability'] === 'mad4b/plugin-update-recovery-plan', 'native recovery planner advertised');
check($inventory[0]['update_apply_ability'] === 'mad4b/plugin-remote-update-apply', 'existing executor advertised');
check($inventory[0]['native_package_offer_verified'] === false && $inventory[0]['automatic_install'] === false, 'discovery is not install permission');

$input = array('plugin_file'=>'sample/sample.php','reason'=>'Safe native WordPress update');
$plan = MAD4B_SCP_Plugin_Update_Recovery::plan($input);
check(!is_wp_error($plan) && $plan['eligible'] === true, 'native offer can plan without provider certificate');
check($plan['provider_certificate_mandatory'] === false, 'native offer certificate optional');
check($plan['certification_route'] === 'native_wordpress_update_offer', 'native route used');
check($plan['update_executor'] === 'mad4b/plugin-remote-update-apply', 'authorized native executor');
check($plan['plan_sha256'] === str_repeat('b',64), 'exact native plan identity retained');
check($plan['production_authorized_by_this_plan'] === false, 'no Production authorization');
check(MAD4B_SCP_Remote_Plugin_Update::$lastInput['plugin_file'] === 'sample/sample.php', 'exact plugin identity passed');

MAD4B_SCP_Remote_Plugin_Update::$blockers=array('wordpress_update_package_url_unsafe');
$blocked = MAD4B_SCP_Plugin_Update_Recovery::plan($input);
check($blocked['eligible'] === false, 'underlying source URL guard remains effective');
check(in_array('wordpress_update_package_url_unsafe',$blocked['blockers'],true), 'native blockers retained');
MAD4B_SCP_Remote_Plugin_Update::$blockers=array();

MAD4B_SCP_Remote_Plugin_Update::$errorCode='wordpress_update_offer_missing';
$error=MAD4B_SCP_Plugin_Update_Recovery::plan($input);
check(is_wp_error($error) && $error->get_error_code()==='wordpress_update_offer_missing', 'missing WordPress update offer remains denied');
MAD4B_SCP_Remote_Plugin_Update::$errorCode='';

$explicit=MAD4B_SCP_Plugin_Update_Recovery::plan($input+array('source'=>'certified_repository_archive'));
check(is_wp_error($explicit) && $explicit->get_error_code()==='mad4b_plugin_update_certified_provider_missing', 'explicit certified source remains bound to reviewed provider');

$invalid=MAD4B_SCP_Plugin_Update_Recovery::plan(array('plugin_file'=>'../escape.php','reason'=>'Unsafe'));
check(is_wp_error($invalid) && $invalid->get_error_code()==='mad4b_plugin_update_file_invalid', 'unsafe target rejected');

echo "MAD4B_NATIVE_UPDATE_RECOVERY_CERT_OPTIONAL: PASS".PHP_EOL;
