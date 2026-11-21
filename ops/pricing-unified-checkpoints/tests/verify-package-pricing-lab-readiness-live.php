<?php
/**
 * WordPress CLI read-only acceptance for MAD4B Package Pricing Lab v1.
 *
 * Execute as an authorized Pricing admin:
 *   wp --user=1 eval-file ops/pricing-unified-checkpoints/tests/verify-package-pricing-lab-readiness-live.php
 *
 * No post/meta saves or commercial authorization.
 */
defined('ABSPATH') || exit;
if(!defined('WP_CLI')||!WP_CLI)return;
$cls='MAD4B_Package_Pricing_Lab_Readiness_V1';
if(!class_exists($cls,false))WP_CLI::error('Package Pricing Lab readback MU plugin not loaded');
$checks=0;
$assert=static function(bool $yes,string $message)use(&$checks):void{
    if(!$yes)WP_CLI::error('PACKAGE_PRICING_LAB_FAIL '.$message);
    $checks++;
};
$authorized=current_user_can('commerce_manage_pricing');
$assert($authorized,'pricing admin is required');
$tiers=$cls::tierIds();
$assert(count($tiers)===3,'three canonical Package tiers');
$assert(array_column($tiers,'label')===['Silver','Gold','Diamond'],'tier order');
$rows=$cls::packages();
$assert(count($rows)===count(array_unique(array_column($rows,'id'))),'WPML canonical dedup');
$wayfa=strpos(home_url(),'wayfatours.com')!==false;
$source=strpos(home_url(),'tours.dev.mad4b.com')!==false;
$assert($wayfa||$source,'expected company site');
$assert(is_wp_error($cls::inspect(60579,(int)$tiers[0]['id'])) || !$wayfa,
    'nonpackage cannot enter source inspector');

$beforePosts=(int)$GLOBALS['wpdb']->get_var(
    "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='tour-rates'");
$beforePreset=hash('sha256',wp_json_encode(get_option('mad4b_jetengine_pricing_lab_presets_v1',[])));
$summary=['tour_count'=>count($rows),'tier_inspections'=>0,
    'stages'=>0,'cruise_stages'=>0,'hotel_or_resort_stages'=>0,
    'hotel_stages_missing_candidates'=>0,'candidate_mentions'=>0,
    'unique_properties'=>[],'unique_units'=>[],
    'unit_relation_mismatches'=>[],'direct_cost_fields'=>0,
    'supplier_receipts_approved'=>0,'native_stage_rows_written'=>0,
    'unit_available_count'=>0,'published_property_rates'=>0];
foreach($rows as $entry){
    $id=(int)$entry['id'];
    $assert($cls::canonical($id)===$id,'canonical Package ID '.$id);
    foreach($tiers as $tier){
        $v=$cls::inspect($id,(int)$tier['id']);
        $assert(is_array($v),'valid Package readback '.$id.' '.$tier['label']);
        $assert($v['generated_rate_ready']===false &&
                $v['checkout_ready']===false &&
                $v['supplier_cost_total']===null &&
                $v['sale_price']===null && $v['margin']===null,
            'no synthetic commercial approval '.$id.' '.$tier['label']);
        $assert($v['sources']['tour_rate_relation']==='related_tour_id',
            'sole rate authority '.$id.' '.$tier['label']);
        $summary['tier_inspections']++;
        $summary['stages']+=(int)$v['summary']['stages'];
        $summary['hotel_or_resort_stages']+=(int)$v['summary']['hotel_or_resort_stages'];
        $summary['hotel_stages_missing_candidates']+=(int)$v['summary']['stages_without_candidates'];
        $summary['candidate_mentions']+=(int)$v['summary']['candidate_mentions'];
        $summary['direct_cost_fields']+=(int)$v['summary']['unit_cost_role_positive_prices'];
        $summary['unit_available_count']+=(int)$v['summary']['unit_available'];
        $summary['published_property_rates']+=(int)$v['summary']['published_property_rates'];
        $summary['supplier_receipts_approved']+=(int)$v['summary']['approved_supplier_stage_receipts'];
        $summary['native_stage_rows_written']+=(int)$v['summary']['native_rate_stages_written'];
        foreach($v['stages'] as $stage){
            if($stage['kind']==='cruise')$summary['cruise_stages']++;
            foreach($stage['candidates'] as $property){
                $summary['unique_properties'][(int)$property['id']]=true;
                foreach($property['units'] as $unit){
                    $summary['unique_units'][(int)$unit['id']]=true;
                    if(!$unit['source_relation_match'])
                        $summary['unit_relation_mismatches'][(int)$unit['id']]=true;
                }
            }
        }
    }
}
if($wayfa){
    $assert(count($rows)===8,'Wayfatours eight canonical packages');
    $assert($summary['tier_inspections']===24,'all 8 packages x 3 tiers audited');
    $assert($summary['candidate_mentions']===288,'actual per-stage tier candidate mentions');
    $assert(count($summary['unique_properties'])===18,'18 unique candidate hotels');
    $assert(count($summary['unique_units'])===134,'134 unique candidate room units');
    $assert($summary['hotel_stages_missing_candidates']===9,'three non-Cairo stages across tiers');
    $assert($summary['cruise_stages']===21,'7 cruise programs across tiers');
    $assert($summary['unit_relation_mismatches']===[],'all candidate units belong to native Properties');
    $assert($summary['direct_cost_fields']===0,'no unit cost source presented as approved');
    $assert($summary['unit_available_count']===0,'all source Units marked unavailable');
    $assert($summary['published_property_rates']===0,'QA Draft rates never become supplier cost');
    $assert($summary['supplier_receipts_approved']===0,'no invented supplier receipts');
    $assert($summary['native_stage_rows_written']===0,'no Rate stage written by audit');
}else{
    // Tours.dev Package source plans are independently reconciled at runtime.
    // Do not pin their count to an older empty-site checkpoint.
    $assert(is_array($rows),'Tours.dev saved Package catalog is readable');
    foreach($rows as $p){
        $tour=(int)$p['id'];
        $guard=MAD4B_Package_Stage_Rate_Guard_V1::review(
            $tour,(int)$tiers[0]['id'],'2027-02-01','2027-02-28');
        $assert(!empty($guard['applies'])&&$guard['ready']===false,
            'source Package remains commercially unverified '.$tour);
    }
}
$assert($beforePosts===(int)$GLOBALS['wpdb']->get_var(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='tour-rates'"),
    'Tour Rate post count unchanged');
$assert($beforePreset===hash('sha256',
    wp_json_encode(get_option('mad4b_jetengine_pricing_lab_presets_v1',[]))),
    'original Pricing Lab strategies untouched');
$oldUser=get_current_user_id();
wp_set_current_user(0);
$assert(!current_user_can('commerce_manage_pricing'),'anonymous pricing permission denied');
if($rows){
    $denied=$cls::inspect((int)$rows[0]['id'],(int)$tiers[0]['id']);
    $assert(is_wp_error($denied)&&$denied->get_error_code()==='package_lab_tour_unavailable',
        'non-pricing user denied even with known IDs');
}
wp_set_current_user($oldUser);
$summary['unique_properties']=count($summary['unique_properties']);
$summary['unique_units']=count($summary['unique_units']);
$summary['unit_relation_mismatches']=count($summary['unit_relation_mismatches']);
WP_CLI::line(wp_json_encode([
    'site'=>home_url(),'passed'=>$checks,'summary'=>$summary,
    'policy_or_rate_write_performed'=>false,'generation_activated'=>false,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
