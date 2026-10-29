<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/Channels.php';
require dirname(__DIR__).'/includes/Composer.php';

use Commerce\Composition\Channels;
use Commerce\Composition\Composer;

$passed=0;$fail=[];
function pass(bool $value,string $name):void { global $passed,$fail; $value ? $passed++ : $fail[]=$name; }
function rejects(callable $f,string $reason):bool {
    try {$f();return false;}
    catch (\DomainException $e) {return $e->getMessage()===$reason;}
}
$base=['active_services'=>['tours'], 'properties_channel_mode'=>'tour_only',
    'transportations_channel_mode'=>'tour_only'];
pass(Channels::allowed($base,'tours','standalone'),'tour_current_standalone');
pass(Channels::allowed($base,'properties','package'),'property_current_component');
pass(!Channels::allowed($base,'properties','standalone'),'property_current_standalone_denied');
pass(Channels::allowed($base,'transportations','package'),'transfer_current_component');
pass(!Channels::allowed($base,'transportations','standalone'),'transfer_current_standalone_denied');
pass(!Channels::allowed($base,'missing','package'),'unregistered_rejected');
foreach (['standalone_and_package','standalone_and_tour'] as $mode) {
    $o=$base+[]; $o['properties_channel_mode']=$mode;
    pass(Channels::allowed($o,'properties','standalone'),'property_future_sell_'.$mode);
    pass(Channels::allowed($o,'properties','package'),'property_future_bundle_'.$mode);
}
foreach (['package_only','tour_only'] as $mode) {
    $o=$base; $o['properties_channel_mode']=$mode;
    pass(!Channels::allowed($o,'properties','standalone'),'property_package_only_no_single_'.$mode);
    pass(Channels::allowed($o,'properties','package'),'property_package_only_bundle_'.$mode);
}
foreach (['standalone_only','inquiry_only','disabled'] as $mode) {
    $o=$base;$o['properties_channel_mode']=$mode;
    pass(Channels::allowed($o,'properties','standalone')===($mode==='standalone_only'),'property_standalone_only_'.$mode);
    pass(!Channels::allowed($o,'properties','package'),'property_no_bundle_'.$mode);
}
$services=['tours','properties','transportations','guides','visas','insurance','activities','rail'];
$all=['active_services'=>$services];
foreach ($services as $service) {
    pass(Channels::allowed($all,$service,'standalone'),'all_enabled_sell_'.$service);
    pass(Channels::allowed($all,$service,'package'),'all_enabled_bundle_'.$service);
}
$all['service_channel_modes']=['insurance'=>'standalone_only','rail'=>'package_only'];
pass(!Channels::allowed($all,'insurance','package'),'all_mode_override_standalone');
pass(!Channels::allowed($all,'rail','standalone'),'all_mode_override_package');
pass(in_array('legacy_enabled_but_standalone_denied_by_explicit_mode',
    Channels::diagnostics($all,$services)['rail']['warnings'],true),'activation_conflict_report');
pass(Channels::mode(['active_services'=>['guides'], 'guides_channel_mode'=>'unknown'],'guides')==='disabled','invalid_mode_fail_closed');

$pol=[
  'schema'=>1,
  'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>200,'rate_id'=>201],
  'groups'=>[
    ['key'=>'transfer','role'=>'fixed_included','options'=>[['service'=>'transportations','source_type'=>'transportations','source_id'=>801]]],
    ['key'=>'hotel','role'=>'choose_one_included','options'=>[
        ['service'=>'properties','source_type'=>'properties','source_id'=>401],
        ['service'=>'properties','source_type'=>'properties','source_id'=>402]]],
    ['key'=>'activities','role'=>'optional_attached','max'=>2,'options'=>[
        ['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>601],
        ['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>602]]],
    ['key'=>'upgrade','role'=>'upgrade_delta','options'=>[
        ['service'=>'properties','source_type'=>'properties','source_id'=>502]]],
    ['key'=>'ticket','role'=>'separately_accounted_extra','max'=>1,'options'=>[
        ['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>700]]],
  ]
];
$choices=['intent'=>'request','channel'=>'standalone','parent_source_id'=>200,
 'parent_rate_id'=>201,'groups'=>[
    'hotel'=>[['source_type'=>'properties','source_id'=>402]],
    'activities'=>[['source_type'=>'tours-and-activities','source_id'=>601,'quantity'=>2]],
    'upgrade'=>[['source_type'=>'properties','source_id'=>502]],
    'ticket'=>[['source_type'=>'tours-and-activities','source_id'=>700]],
]];
$quote=static function($parent,$group,$option,$qty,$pricing,$intent):array {
    return match($pricing) {
        'included'=>['state'=>'included'],
        'delta'=>['state'=>'priced','amount'=>25.0,'currency'=>'USD','reference'=>'server-upgrade'],
        'additional'=>['state'=>'priced','amount'=>40.0*$qty,'currency'=>'USD','reference'=>'server-extra'],
        'separate'=>['state'=>'priced','amount'=>10.0,'currency'=>'USD','reference'=>'server-ticket'],
        default=>['state'=>'pending']
    };
};
$withoutUpgrade=$choices; $withoutUpgrade['groups']['upgrade']=[];
$skip=Composer::compose($pol,$withoutUpgrade,$base,$quote);
pass(count($skip['components'])===4 && $skip['additional_amount']===80.0,'upgrade_is_optional_and_removable');
$plan=Composer::compose($pol,$choices,$base,$quote);
pass(count($plan['components'])===5,'package_nested_component_count');
pass(count($plan['dependent_financial_lines'])===1,'only_separate_accounting_line');
pass($plan['additional_amount']===105.0,'no_double_count_parent_extra_upgrade');
pass($plan['status']==='structure_and_component_evidence_only_not_checkout_authority','not_financially_certified');
pass($plan['components'][0]['source_id']===801,'mandatory_component_autoderived');
pass($plan['components'][0]['valuation_state']==='included','mandatory_no_duplicate_charge');
pass($plan['components'][1]['source_id']===402,'chosen_hotel_exactly_one');
pass($plan['components'][2]['quantity']===2,'quantity_recorded');
$next=$base;$next['properties_channel_mode']='standalone_and_tour';
pass(count(Composer::compose($pol,$choices,$next,$quote)['components'])===5,'standalone_expansion_keeps_package');
$next['transportations_channel_mode']='standalone_and_tour';
pass(count(Composer::compose($pol,$choices,$next,$quote)['components'])===5,'standalone_transport_keeps_package');
$allWithOwners=['active_services'=>['tours','properties','transportations']];
pass(count(Composer::compose($pol,$choices,$allWithOwners,$quote)['components'])===5,'all_services_on_keeps_same_package');

$wrong=$choices;$wrong['groups']['hotel']=[];
pass(rejects(fn()=>Composer::compose($pol,$wrong,$base,$quote),'selection_cardinality_invalid'),'hotel_required');
$wrong=$choices;$wrong['groups']['hotel'][]=['source_type'=>'properties','source_id'=>401];
pass(rejects(fn()=>Composer::compose($pol,$wrong,$base,$quote),'selection_cardinality_invalid'),'hotel_exclusive_choice');
$wrong=$choices;$wrong['groups']['hotel']=[['source_type'=>'properties','source_id'=>999]];
pass(rejects(fn()=>Composer::compose($pol,$wrong,$base,$quote),'unlinked_option'),'hotel_unlinked');
$wrong=$choices;$wrong['groups']['transfer']=[['source_type'=>'transportations','source_id'=>801]];
pass(rejects(fn()=>Composer::compose($pol,$wrong,$base,$quote),'fixed_component_not_customer_editable'),'fixed_cannot_be_client_changed');
$wrong=$choices;$wrong['groups']['activities'][]=['source_type'=>'tours-and-activities','source_id'=>601];
pass(rejects(fn()=>Composer::compose($pol,$wrong,$base,$quote),'duplicate_selection'),'duplicate_extra');
$wrong=$choices;$wrong['groups']['activities'][0]['amount']=0;
pass(rejects(fn()=>Composer::compose($pol,$wrong,$base,$quote),'selection_untrusted_field'),'browser_amount_denied');
$wrong=$choices;$wrong['groups']['made_up']=[];
pass(rejects(fn()=>Composer::compose($pol,$wrong,$base,$quote),'unknown_group'),'unknown_group_denied');
$blocked=$base;$blocked['properties_channel_mode']='disabled';
pass(rejects(fn()=>Composer::compose($pol,$choices,$blocked,$quote),'component_channel_denied'),'disabled_package_component_denied');
$wrong=$choices;$wrong['intent']='sale';
pass(count(Composer::compose($pol,$wrong,$base,$quote)['components'])===5,'priced_components_still_not_sold');
$pending=static fn()=>['state'=>'pending'];
$wrong=$choices;$wrong['intent']='sale';
pass(rejects(fn()=>Composer::compose($pol,$wrong,$base,$pending),'sale_requires_approved_price'),'unpriced_sale_denied');
$req=Composer::compose($pol,$choices,$base,$pending);
pass($req['price_state']==='price_pending' && $req['additional_amount']===null,'unpriced_request_allowed_no_fake_zero');

$solo=['schema'=>1,'parent'=>['service'=>'properties','source_type'=>'properties','source_id'=>401], 'groups'=>[]];
$future=$base;$future['properties_channel_mode']='standalone_and_tour';
pass(count(Composer::compose($solo,['intent'=>'request','channel'=>'standalone'], $future,$pending)['components'])===0,'same_property_standalone_without_duplication');
pass(rejects(fn()=>Composer::compose($solo,['intent'=>'request','channel'=>'standalone'], $base,$pending),'parent_channel_denied'),'legacy_property_no_standalone');

echo json_encode(['result'=>$fail?'FAIL':'PASS','passed'=>$passed,'failed'=>$fail,'scenarios'=>'legacy_modes,future_standalone,all_on,bundled_components,extras,pricing,eligibility,mixed_cart_representation'],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($fail)exit(1);
