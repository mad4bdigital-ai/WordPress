<?php
/* Hermetic CLI fixtures; the real site is not loaded or modified. */
define('ABSPATH', '/');
class WP_Error {
    private $code;
    public function __construct($code, $message) { $this->code=$code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_json_encode($x,$flags=0) { return json_encode($x,$flags); }
function wp_is_uuid($value) { return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$value); }
function home_url($path='/') { return 'https://tours.dev.mad4b.com'.$path; }
function untrailingslashit($x) { return rtrim($x, '/'); }
$GLOBALS['env'] = 'staging';
$GLOBALS['options'] = array();
$GLOBALS['option_read_count'] = 0;
$GLOBALS['abilities'] = array();
$GLOBALS['hooks'] = array();
function wp_get_environment_type() { return $GLOBALS['env']; }
function add_action($name,$callback,$priority=10) { $GLOBALS['hooks'][$name][]=$callback; }
function wp_has_ability($name) { return isset($GLOBALS['abilities'][$name]); }
function wp_register_ability($name,$args) { $GLOBALS['abilities'][$name]=$args; return true; }
function get_option($key,$default=false) {
    $GLOBALS['option_read_count']++;
    return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default;
}
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Site_Profile {
    public static $status = array(
       'contract'=>'mad4b.site-profile.v2',
       'site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
       'canonical_origin'=>'https://tours.dev.mad4b.com',
       'environment'=>'staging',
       'configured'=>true, 'origin_match'=>true,
       'environment_match'=>true, 'deployment_binding_match'=>true,
    );
    public static function status() { return self::$status; }
    public static function current_environment() { return $GLOBALS['env']; }
}
?>
