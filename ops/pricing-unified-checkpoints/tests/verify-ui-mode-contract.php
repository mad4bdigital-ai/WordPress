<?php
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
wp_set_current_user(1);
$cls='MAD4B_JetEngine_Field_Modes_V1';
if (!class_exists($cls,false)) WP_CLI::error('JetEngine UI Mode class unavailable');
$modes=$cls::modes();
foreach (['standard','toggle','slider','select-cards','date','rules','source-cards','field-card','code'] as $m) {
    if (!isset($modes[$m])) WP_CLI::error('Missing registered mode: '.$m);
}
if ($cls::compatible('source-cards','number') || !$cls::compatible('source-cards','repeater') ||
    $cls::compatible('slider','repeater') || !$cls::compatible('slider','number'))
    WP_CLI::error('Mode compatibility contract incorrect');

$pages=$cls::pages();
$pricing=$pages['etg-dynamic-pricing-controls']??null;
$services=$pages['service-activation']??null;
if(!is_array($pricing) || !is_array($services))
    WP_CLI::error('Native JetEngine Options Page discovery incomplete');
if(count($pricing['fields'])<50 || count($services['fields'])<25)
    WP_CLI::error('Native field discovery dropped required dynamic fields');
$priceFields=$pricing['fields'];
$expected=[
    'shared_mode_policy_rows'=>['type'=>'repeater','mode'=>'rules','subfields'=>21],
    'shared_package_pricing_rows'=>['type'=>'repeater','mode'=>'rules','subfields'=>6],
    'mad4b_provider_source_policy_rows'=>['type'=>'repeater','mode'=>'source-cards','subfields'=>11],
    'booking_fulfillment_mode'=>['type'=>'select','mode'=>'select-cards'],
];
$summary=[];
foreach($expected as $name=>$want){
    if(!isset($priceFields[$name])) WP_CLI::error('Missing JetEngine field '.$name);
    $original=$priceFields[$name];
    $resolved=$cls::resolved('etg-dynamic-pricing-controls',$original);
    if(($original['type']??'')!==$want['type'] || ($resolved['mode']??'')!==$want['mode'] ||
       ($resolved['layout']['source']??'')!=='jetengine' ||
       ($resolved['layout']['width']??'')!==($original['width']??''))
        WP_CLI::error('Native UI/Mode field mapping incorrect: '.$name);
    if(isset($want['subfields']) && count($original['repeater_fields']??[])!==$want['subfields'])
        WP_CLI::error('Native JetEngine Repeater schema changed: '.$name);
    $summary[$name]=['native_type'=>$original['type'],'mode'=>$resolved['mode'],
        'subfields'=>count($original['repeater_fields']??[])];
}
$serviceResolved=$cls::resolved('service-activation',$services['fields']['properties_channel_mode']);
if($serviceResolved['mode']!=='select-cards')
    WP_CLI::error('Non-Pricing JetEngine options inherited wrong field recipe');

if(!class_exists('MAD4B_JetEngine_Pricing_Controls_Access_V1',false) ||
   !MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized())
    WP_CLI::error('Authoritative Pricing Controls access denied for test admin');
$_GET['page']='dynamic-pricing-controls';
$cls::enqueue();
$scripts=wp_scripts();
$handle='mad4b-jetengine-functional-modes';
$script=$scripts->registered[$handle]??null;
if(!$script || !wp_script_is($handle,'enqueued')) WP_CLI::error('JetEngine mode UI failed to enqueue');
foreach($script->deps as $dependency) if(!wp_script_is($dependency,'registered'))
    WP_CLI::error('Mode UI script has unregistered dependency '.$dependency);
$pricingDeps=$script->deps;
$_GET['page']='service-activation';
$cls::enqueue();
$serviceScript=$scripts->registered[$handle]??null;
foreach($serviceScript->deps as $dependency) if(!wp_script_is($dependency,'registered'))
    WP_CLI::error('Service Activation inherited Pricing-only missing dependency '.$dependency);

echo 'JETENGINE_MODE_ACCEPTANCE '.wp_json_encode([
    'site'=>home_url(),
    'mode_count'=>count($modes),'pricing_fields'=>count($priceFields),
    'service_activation_fields'=>count($services['fields']),
    'pricing_script_dependencies'=>$pricingDeps,
    'fields'=>$summary,
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('JETENGINE_NATIVE_UI_MODE_CONTRACT_VERIFIED');
