<?php
/**
 * Isolated contract test for JetEngine company-owned policies.
 * No WordPress or JetEngine installation is required to execute these checks.
 */
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
$GLOBALS['policy_hooks'] = [];
$GLOBALS['demo_company'] = 'Wo Vacation';
$GLOBALS['demo_locale'] = 'en';
$GLOBALS['demo_fields'] = [];

function add_action($hook, $cb, $priority=10) {
    $GLOBALS['policy_hooks'][$hook][] = $cb;
}
function add_shortcode($shortcode, $callback) {
    $GLOBALS['policy_hooks']['shortcodes'][$shortcode] = $callback;
}
function shortcode_atts($defaults, $atts, $tag='') {
    return array_merge($defaults, (array)$atts);
}
function get_option($name, $default=false) {
    if ('blogname' === $name) return $GLOBALS['demo_company'];
    if ('mad4b-company-policy-library' === $name) return $GLOBALS['demo_fields'];
    return $default;
}
function apply_filters($name, $value, ...$args) {
    if ($name === 'wpml_current_language') return $GLOBALS['demo_locale'];
    return $value;
}
function get_locale() {
    return 'en_US';
}
function esc_html($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}
function wp_strip_all_tags($html) {
    return strip_tags((string)$html);
}
function wp_kses_post($html) {
    return strip_tags((string)$html, '<p><strong><em><a>');
}
function test_equal($expected, $actual, $label) {
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: '.$label.' Expected='.var_export($expected,true).' Received='.var_export($actual,true).PHP_EOL);
        exit(1);
    }
}
class Demo_JetEngine_Pages {
    public $registered_pages=[];
    public function register_new_options_page($config) {
        $this->registered_pages[$config['slug']] = $config;
    }
}
$GLOBALS['jetengine_mock'] = (object)['options_pages'=>new Demo_JetEngine_Pages()];
function jet_engine() {
    return $GLOBALS['jetengine_mock'];
}

require dirname(__DIR__,2).'/wp-content/mu-plugins/mad4b-company-policy-library.php';

$policyTypes=mad4b_company_policy_types();
test_equal(8,count($policyTypes),'Eight company-owned policy categories');
test_equal(6,count(mad4b_company_policy_locales()),'Six independent policy languages');
foreach($GLOBALS['policy_hooks']['init'] as $register) $register();
$page=$GLOBALS['jetengine_mock']->options_pages->registered_pages['mad4b-company-policy-library'] ?? [];
test_equal(65,count($page['fields']??[]),'JetEngine admin page registers 65 source controlled fields');
test_equal('mad4b_manage_company_operations',$page['capability']??'','Role-based company policies capability');
test_equal('mad4b-company',$page['parent']??'','Company admin parent menu');
test_equal(false,$page['autoload_option']??null,'No automatic option autoload');

$fallback='Company-specific policy details have not yet been confirmed. Please verify terms before booking.';
test_equal('<p>'.$fallback.'</p>',mad4b_company_policy_html('cancellation'),'Without approval, no inherited fee');
$GLOBALS['demo_fields']=['cancellation_approval'=>'approved','cancellation_en'=>'<p>Charge 15%.</p>'];
test_equal('<p>'.$fallback.'</p>',mad4b_company_policy_html('cancellation'),'Approval without revision is not enough');
$GLOBALS['demo_fields']['cancellation_revision']='';
test_equal('<p>'.$fallback.'</p>',mad4b_company_policy_html('cancellation'),'Empty revision is rejected');
$GLOBALS['demo_fields']['cancellation_revision']='approved-v1';
$GLOBALS['demo_fields']['cancellation_en']='';
test_equal('<p>'.$fallback.'</p>',mad4b_company_policy_html('cancellation'),'Empty approved policy cannot display inherited fee');
$GLOBALS['demo_fields']['cancellation_en']='<p>For {{company_name}}, terms are governed.</p>';
test_equal('<p>For Wo Vacation, terms are governed.</p>',mad4b_company_policy_html('cancellation'),'Approved localized source and current public company token');
$GLOBALS['demo_company']='Other & Co';
test_equal('<p>For Other &amp; Co, terms are governed.</p>',mad4b_company_policy_html('cancellation'),'Company identity is resolved on each read');
$GLOBALS['demo_locale']='fr';
test_equal('<p>Les conditions propres à cette société ne sont pas encore confirmées. Veuillez les vérifier avant de réserver.</p>',mad4b_company_policy_html('cancellation'),'French without approved French terms cannot inherit English legal policy');
$GLOBALS['demo_locale']='ar';
test_equal('<p>لم يتم تأكيد تفاصيل سياسة الشركة بعد. يُرجى التحقق من الشروط قبل الحجز.</p>',mad4b_company_policy_html('cancellation'),'Arabic legal source omitted safely');
$GLOBALS['demo_locale']='en';
$GLOBALS['demo_fields']['cancellation_en']='<p><script>alert("x")</script>Safe text</p>';
test_equal('<p>alert("x")Safe text</p>',mad4b_company_policy_html('cancellation'),'Rendered policy has no executable script markup');
test_equal('',mad4b_company_policy_html('not-a-policy'),'Unregistered policy kinds fail closed');

$keys=array_column($page['fields'],'name');
foreach($policyTypes as $type=>$label) {
    test_equal(true,in_array($type.'_approval',$keys,true),'Approval field present: '.$type);
    test_equal(true,in_array($type.'_revision',$keys,true),'Version field present: '.$type);
    foreach(mad4b_company_policy_locales() as $locale=>$language) {
        test_equal(true,in_array($type.'_'.$locale,$keys,true),'Localized source field: '.$type.':'.$locale);
    }
}
echo "PASS: company options page, 8 policy categories, 6 languages, approval, revision, source isolation, markup sanitization\n";
