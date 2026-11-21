<?php
/**
 * Read-only, site-neutral native JetEngine Tour Page Options contract.
 * This is the installed configuration, not an invented control-plane repeater.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Tour_Highlights_V1', false)) {
    WP_CLI::error('Journey owner not active');
}
$expectedPath=WP_PLUGIN_DIR.'/mad4b-site-control-plane/data/journey-native-binding-field.json';
if (!is_readable($expectedPath) || is_link($expectedPath)) {
    WP_CLI::error('Versioned original JetEngine binding field missing');
}
$schema=json_decode((string)file_get_contents($expectedPath),true);
if (!is_array($schema) || ($schema['name']??null)!=='mad4b_journey_place_bindings_v1'
    || ($schema['type']??null)!=='repeater') {
    WP_CLI::error('Versioned original binding field malformed');
}
$boxes=get_option('jet_engine_meta_boxes',[]);
$box=is_array($boxes)?($boxes['mad4b_tour_layout_page_options']??null):null;
if (!is_array($box)) WP_CLI::error('Original Tour Page Options absent');
$matches=array_values(array_filter((array)($box['meta_fields']??[]),
    static fn($f)=>is_array($f)&&($f['name']??null)==='mad4b_journey_place_bindings_v1'));
if (count($matches)!==1) WP_CLI::error('Native binding field absent or duplicated');
$installed=$matches[0];
if (($installed['type']??'')!=='repeater') WP_CLI::error('Native field must be repeater');

$required=['item_number'=>'number','link_policy'=>'select',
    'destination_term_ids'=>'select','item_key'=>'hidden',
    'rebind_on_save'=>'switcher'];
$seen=[];
foreach ((array)($installed['repeater-fields']??[]) as $field) {
    if (!is_array($field)) continue;
    $name=(string)($field['name']??'');
    if (isset($required[$name])) {
        if (isset($seen[$name]) || ($field['type']??null)!==$required[$name]) {
            WP_CLI::error('Native field duplicated or type drift: '.$name);
        }
        $seen[$name]=$field;
    }
}
if (count($seen)!==count($required)) WP_CLI::error('Missing required native subfield');
if (empty($seen['destination_term_ids']['is_multiple'])
    || ($seen['destination_term_ids']['taxonomy']??null)!=='destinations'
    || ($seen['destination_term_ids']['return_value']??null)!=='term_id'
    || !empty($seen['destination_term_ids']['save_terms'])) {
    WP_CLI::error('Term selection changed canonical Tour taxonomy assignment');
}
$schemaFields=[];
foreach ((array)($schema['repeater-fields']??[]) as $f) {
    if (!is_array($f)) continue;
    $name=$f['name']??'';
    $schemaFields[$name]=$f['type']??'';
}
foreach ($required as $name=>$type) {
    if (($schemaFields[$name]??null)!==$type) {
        WP_CLI::error('Native source schema differs: '.$name);
    }
}
$patternSource=WP_PLUGIN_DIR.'/mad4b-site-control-plane/data/journey-look-and-feel-pattern-field.json';
$patternSourceMode='installed_original_source';
if (!is_readable($patternSource)) {
    // Before the next plugin package deploy, audit against a byte-reviewed
    // temporary copy; never claim this means the source file was deployed.
    $candidate=getenv('MAD4B_JOURNEY_LOOKFEEL_REVIEW_SOURCE');
    if (!defined('WP_CLI') || !WP_CLI ||
        $candidate!=='/tmp/mad4b-look-feel-review-source.json') {
        WP_CLI::error('Versioned Look & Feel source not yet deployed; pass a reviewed CLI source');
    }
    $patternSource=$candidate;
    $patternSourceMode='read_only_review_copy_not_deployed';
}
$patternExpected=is_readable($patternSource) && !is_link($patternSource)
    ?json_decode((string)file_get_contents($patternSource),true):null;
if (!is_array($patternExpected) ||
    ($patternExpected['name']??null)!=='tour_highlights_default_pattern' ||
    ($patternExpected['type']??null)!=='select' ||
    ($patternExpected['default_val']??null)!=='split') {
    WP_CLI::error('Versioned company Look & Feel field missing or malformed');
}
if (!function_exists('jet_engine') || !isset(jet_engine()->options_pages->data)) {
    WP_CLI::error('Native JetEngine Look & Feel pages unavailable');
}
$pages=jet_engine()->options_pages->data->get_items();
$companyPage=array_values(array_filter((array)$pages,
    static fn($p)=>is_array($p)&&($p['slug']??null)==='look-and-feel'));
if (count($companyPage)!==1) {
    WP_CLI::error('Native company Look & Feel page absent or duplicated');
}
$companyFields=maybe_unserialize($companyPage[0]['meta_fields']??null);
$patternFields=array_values(array_filter((array)$companyFields,
    static fn($f)=>is_array($f)&&($f['name']??null)==='tour_highlights_default_pattern'));
if (count($patternFields)!==1) {
    WP_CLI::error('Native company default pattern field absent or duplicated');
}
$pattern=$patternFields[0];
$choiceKeys=static function($field):array {
    return array_values(array_map(
        static fn($row)=>(string)($row['key']??''),
        (array)($field['options']??[])));
};
if (($pattern['type']??null)!=='select' ||
    ($pattern['default_val']??null)!=='split' ||
    $choiceKeys($pattern)!==['explorer','split','story'] ||
    $choiceKeys($patternExpected)!==['explorer','split','story']) {
    WP_CLI::error('Original company layout modes or Split default drift');
}
$company=get_option('look-and-feel',[]);
if (!is_array($company) ||
    ($company['tour_highlights_default_pattern']??null)!=='split') {
    WP_CLI::error('Company Split default drift');
}
WP_CLI::success(wp_json_encode([
    'site'=>home_url(),
    'native_jetengine_meta_box'=>'mad4b_tour_layout_page_options',
    'binding_field'=>'mad4b_journey_place_bindings_v1',
    'source_and_installed_schema_match'=>true,
    'subfields'=>array_keys($required),
    'split_default'=>'split',
    'look_and_feel_field'=>'tour_highlights_default_pattern',
    'native_layout_choices'=>$choiceKeys($pattern),
    'pattern_source_mode'=>$patternSourceMode,
    'read_only'=>true,
],JSON_UNESCAPED_SLASHES));
