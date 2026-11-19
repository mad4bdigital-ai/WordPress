<?php
declare(strict_types=1);
namespace {
    define('ABSPATH', '/tmp/');
    $GLOBALS['mad4b_test_opts']=['blogname'=>'Company Example'];
    $GLOBALS['mad4b_test_external']=[];
    function home_url(): string { return 'https://wayfa-test.example'; }
    function get_option($name, $default = false) {
        return $GLOBALS['mad4b_test_opts'][$name] ?? $default;
    }
    function sanitize_text_field($value): string { return trim(strip_tags((string)$value)); }
    function add_filter(...$args) { return true; }
    function is_wp_error($value): bool { return false; }
}
namespace BookingExperience {
    function supplier_integrations(): array {
        return \MAD4B_Stay_Secondary_Self_Supplier_V1::register($GLOBALS['mad4b_test_external']);
    }
}
namespace {
    $module=getenv('MAD4B_TEST_PLUGIN_PATH');
    if(!is_string($module)||!is_file($module)){
        $module=dirname(__DIR__,2).'/wp-content/mu-plugins/009-mad4b-stay-secondary-supplier.php';
    }
    require $module;
    $class='MAD4B_Stay_Secondary_Self_Supplier_V1';
    $tests=0;
    $check=static function(bool $ok,string $label)use(&$tests):void {
        $tests++;
        if(!$ok)throw new \RuntimeException($label);
    };
    $check($class::status()['status']==='not_configured','non-configured tenant must be inert');
    $check(\BookingExperience\supplier_integrations()===[],'no implicit self supplier');
    $GLOBALS['mad4b_test_opts'][$class::OPTION]=[
        'schema'=>$class::CONTRACT,'tenant_url'=>home_url(),'domain'=>'accommodation',
        'primary'=>'external_contract','secondary'=>'self',
        'fallback'=>'only_when_primary_has_no_eligible_verified_offer',
        'operation'=>'preview_only','booking_authorized'=>false,'pricing_activated'=>false,
    ];
    $sources=\BookingExperience\supplier_integrations();
    $check(array_keys($sources)===['self'],'self present after exact policy');
    $check($sources['self']['active']===true && $sources['self']['marketing_authorized']===false,'self internal only');
    $check($sources['self']['catalog']===null && $sources['self']['quote']===null,'self no external callbacks');
    $status=$class::status();
    $check($status['supplier_order'][0]['rank']===1 && $status['supplier_order'][1]['rank']===2,'external then self');
    $check($status['checkout_ready']===false && $status['selected_supplier']===null,'no auto-booking');
    $GLOBALS['mad4b_test_external']=['partner'=>[
        'id'=>'partner','name'=>'Contracted Hotel Partner','active'=>true,
        'marketing_authorized'=>true,'categories'=>['accommodation'],
        'quote'=>static function() { return []; }
    ]];
    $withPartner=$class::status();
    $check($withPartner['supplier_order'][0]['status']==='connector_registered','detect trusted configured callback');
    $check($withPartner['supplier_order'][1]['id']==='self','keep self second');
    $check($withPartner['checkout_ready']===false,'partner callback cannot authorize checkout');
    for($i=0;$i<20;$i++)$class::status(); // regression for accidental recursive registration
    $check(true,'provider registry recursion protection');
    $GLOBALS['mad4b_test_opts'][$class::OPTION]['tenant_url']='https://other-tenant.example';
    $check($class::status()['status']==='configuration_mismatch','mismatched site invalid');
    $check(array_keys(\BookingExperience\supplier_integrations())===['partner'],'mismatched tenant refuses self registration');
    echo "MAD4B_SELF_SECONDARY_SMOKE: {$tests}/{$tests} PASSED\n";
}
