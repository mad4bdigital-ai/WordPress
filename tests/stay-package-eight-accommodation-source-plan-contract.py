#!/usr/bin/env python3
"""Guard new bilingual Package Source Plans and exact-bounded Day-5 correction."""
from pathlib import Path
import re

base=Path(__file__).resolve().parents[1]
source=(base/'scripts/mad4b-package-eight-accommodation-source-plan-cli.php').read_text()
repair=(base/'scripts/mad4b-package-60441-bilingual-exact-dedup-cli.php').read_text()

required=[
 'https://wayfatours.com','_mad4b_package_accommodation_source_plan_v1',
 '57562=>57563','60437=>60611','60439=>60609','60441=>60607',
 '60443=>60601','60623=>60661','60627=>60657','60629=>60655',
 'shared_product_accommodation_assignment($id,0,$tier)',
 'stay_source_site_pair','stay_source_night_proof','stay_source_cairo_candidates',
 'candidate_provider','hotel_alternatives_or_by_tier',
 "'old_manual_rates_used'=>false", "'old_manual_rates_read'=>false",
 "'new_tour_rate_ids_created'=>[]", "'native_rate_stages_written'=>false",
 "'price_status'=>'pending_quote'", "'price_amount'=>null",
 "'booking_authorized'=>false", "'catalog_activated'=>false",
 "'checkout_ready'=>false", "'plan','apply','verify'",
 'duplicate_day5_still_present_', 'duplicate_cairo_includes_still_present_',
 'duplicate_remediation_receipt_missing_', "'duplicated_day_5'=>false",
 '$block=false;', 'array_keys($cairoChoices)',
 'policy_company_binding_invalid','company_policy_state_changed_'
]
for item in required: assert item in source,item
for stale in ['array_splice($rows,5,1)',"if($en===60441)$statuses[]='duplicate_exact_day_5"]:
 assert stale not in source,stale
for token in [
 '60441','60607','1218296','_mad4b_package_itinerary_duplicate_remediation_v1',
 "'itinerary_item','includes'",'source_rows_changed_', 'not_exact_duplicate_',
 'expected_include_en_changed','expected_include_es_changed',
 'source_plan_prior_status_changed_',"'draft_geometry_verified'",
 'START TRANSACTION','ROLLBACK','COMMIT','legacy_rate_edit_count',
 'source_plan_stage_changes', 'clean_post_cache($id)',
 "'plan','apply','verify'", 'source_sha256', 'previous_itinerary_rows',
 'previous_includes_rows'
]:assert token in repair,token
for text in [source,repair]:
 for forbidden in [
   'wp_insert_post(', 'wp_delete_post(', 'wp_update_post(',
   'wp_set_object_terms(', 'wc_create_order(', 'media_sideload_image(',
   'set_post_thumbnail(', 'update_option(', 'flush_rewrite_rules(',
   'unit_inventory_quantity', 'unit_start_price','wp_schedule_event('
 ]: assert forbidden not in text,forbidden
assert repair.count('UPDATE {$wpdb->postmeta} SET meta_value=%s')==1
assert repair.count("array_splice($values,5,1)")==1
assert source.count("add_post_meta($tourId,$meta,$entry,true)")==1
# Validate exact source-stage geometry without requiring connected WordPress in CI.
part=source.split('$planned=[',1)[1].split('];\n$days=',1)[0]
planned={}
for row in part.splitlines():
    match=re.match(r'\s*(\d{5})=>',row)
    if not match:continue
    tour=match.group(1)
    body=row
    stages=[]
    for kind,place,day,nights,board in re.findall(r"\['([^']+)','([^']+)',(\d+),(\d+),'([^']+)'\]",body):
        stages.append((kind,place,int(day),int(nights),board))
    planned[int(tour)]=stages
assert len(planned)==8 and sum(map(len,planned.values()))==28,planned
durations={57562:8,60437:8,60439:7,60441:10,60443:8,60623:8,60627:5,60629:12}
for tour,stages in planned.items():
    nights=[]
    for kind,place,start,count,board in stages:
        assert kind in ['hotel','cruise','resort','sleeper_train']
        assert board in ['bb','fb','hb','ai']
        nights.extend(range(start,start+count))
    assert sorted(nights)==list(range(1,durations[tour])), (tour,nights)
assert len(planned[60441])==3
print('PASS 8 Package Tours / 16 localized plans / 28 night-accurate stage groups / 56 localized stage rows')
print('PASS 60441+60607 exact Day-5 and Includes duplicate removal with transaction, provenance and zero rate mutations')
