<?php
define( 'ABSPATH', __DIR__ . '/' );
class MAD4B_SCP_Adapter_Base {}
class WP_Error {
    private $code;
    public function __construct($code, $message='') { $this->code=$code; }
    public function get_error_code() { return $this->code; }
}
function wp_json_encode($v) { return json_encode($v); }
class MAD4B_SCP_Provider_Behavioral_Recertification {
    public static $active = false;
    public static function probe_context_allows($provider,$ability,$input=null) {
        return self::$active && $provider==='elementor'
            && $ability==='elementor/update-widget-settings'
            && is_array($input) && ($input['post_id']??0)===65969
            && ($input['widget_id']??'')==='test-heading';
    }
}
require dirname(__DIR__).'/includes/adapters/class-mad4b-scp-elementor-adapter.php';
function ensure($ok,$label) { if(!$ok) {fwrite(STDERR,"FAIL: $label\n");exit(1);} }
$adapter = new MAD4B_SCP_Elementor_Adapter();
$check = new ReflectionMethod('MAD4B_SCP_Elementor_Adapter','validate_settings_policy');
$check->setAccessible(true);
$input = array('post_id'=>65969,'widget_id'=>'test-heading','settings'=>array('title'=>'Temporary smoke test'));
$normal = $check->invoke($adapter,'heading',$input['settings'],array(),$input);
ensure($normal instanceof WP_Error && $normal->get_error_code()==='mad4b_elementor_widget_policy_denied','heading must fail without exact probe');
MAD4B_SCP_Provider_Behavioral_Recertification::$active = true;
ensure($check->invoke($adapter,'heading',$input['settings'],array(),$input) === true,'exact isolated heading probe allowed');
$wrong = $input; $wrong['post_id']=23210;
ensure($check->invoke($adapter,'heading',$input['settings'],array(),$wrong) instanceof WP_Error,'live template denied');
ensure($check->invoke($adapter,'heading',array('title'=>'<script>unsafe</script>'),array(),$input) instanceof WP_Error,'markup denied');
ensure($check->invoke($adapter,'heading',array('title'=>str_repeat('a',257)),array(),$input) instanceof WP_Error,'unbounded title denied');
ensure($check->invoke($adapter,'heading',array('title'=>'test'),array('title'),$input) instanceof WP_Error,'remove conflict denied');
ensure($check->invoke($adapter,'heading',array('unknown'=>'test'),array(),$input) instanceof WP_Error,'unreviewed setting denied');
MAD4B_SCP_Provider_Behavioral_Recertification::$active = false;
ensure($check->invoke($adapter,'heading',$input['settings'],array(),$input) instanceof WP_Error,'post-probe write must remain denied');
echo "ELEMENTOR_HEADING_PROBE_POLICY: PASS\n";
