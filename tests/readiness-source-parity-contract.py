#!/usr/bin/env python3
"""Source-scoped parity regression for Readiness, Geo and commercial intake.

This is an offline source-contract check, NOT a substitute for original live
WordPress readback, independent price proof, or Product/Production approval.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
INC = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes"
readiness = (INC / "class-mad4b-scp-content-readiness-alerts.php").read_text()
geo = (INC / "class-mad4b-scp-geo-quality.php").read_text()
locale = (INC / "class-mad4b-scp-locale-experience-alerts.php").read_text()
checks = {}

def check(name, condition):
    checks[name] = bool(condition)

check("original_geo_provider_required",
      "require_once __DIR__.'/class-mad4b-scp-geo-quality.php'" in readiness)
check("geo_discovery_uses_existing_ledger",
      "MAD4B_SCP_Geo_Quality::discover($cfg)" in readiness and
      "array_merge($issues,(array)$geo['issues'])" in readiness)
check("geo_enabled_per_company_not_global",
      "'geo_quality_enabled' => !empty($saved['geo_quality_enabled'])" in readiness and
      "empty($cfg['geo_quality_enabled'])" in geo)
check("origin_registry_remains_dynamic",
      "origin_source_fields'][$issueType]" in readiness and
      "location_taxonomies'][$issueType]" in readiness and
      "post_type_exists($issueType)" in readiness)
check("existing_native_tour_source_hash_backcompat",
      "'origin_term'=>get_post_meta($post_id,$sourceField,true)" in readiness and
      "'destination_terms'=>$term_ids" in readiness and
      "usesNativeTourOrigin" in readiness)
check("variable_guest_pickup_retains_source_authority",
      "source_backed_hotel_pickup($id,$type)" in readiness and
      "pickup_guest_location_capture_unverified" in readiness and
      "MAD4B_SCP_Research_Groups" in readiness and
      "hash_equals((string)($e['source_sha256']??''),$source)" in readiness)
check("pickup_never_assumed_fixed",
      "'fixed_origin_required'=>false" in readiness and
      "'guest_location_capture_verified'=>false" in readiness)
check("geo_intake_is_not_mutating",
      "'contract'=>'mad4b.geo-fact-verification-intake.v1'" in readiness and
      "'taxonomy_write_authorized'=>false" in readiness and
      "'publication_authorized'=>false" in readiness and
      "'commercial_booking_authorized'=>false" in readiness)
check("supplier_rate_evidence_not_claimed",
      "'requires_independent_supplier_evidence'=>!$configuration" in readiness and
      "'financial_authorization'=>false" in readiness and
      "'checkout_authorized'=>false" in readiness and
      "'external_dispatch_authorized'=>false" in readiness)
check("pricing_source_qualified",
      "$source==='mad4b-pricing-workbench'" in readiness and
      "get_post_status($object_id)==='publish'" in readiness and
      "'mad4b.pricing-source-contentjob-intake.v1'" in readiness)
check("pricing_rate_freshness_respected",
      "'SOURCE_CHANGED_REVIEW':'LINKED'" in readiness and
      "'source_sha256'=>$snapshot" in readiness)
check("existing_locale_alerts_preserved",
      "MAD4B_SCP_Locale_Experience_Alerts::discover()" in readiness and
      "'mad4b.locale-experience-contentjob-intake.v1'" in readiness and
      "class MAD4B_SCP_Locale_Experience_Alerts" in locale)
check("public_browser_alerts_cannot_autopublish",
      "'auto_translation_authorized'=>false" in readiness and
      "'auto_publication_authorized'=>false" in readiness)
check("priority_sort_only_within_severity",
      "apply_filters('mad4b/content_readiness/impact_score',null,$a)" in readiness and
      "if($ar!==$br)return $ar<=>$br" in readiness)
check("real_geo_source_owned_station_lookup",
      "$wpdb->term_relationships" in geo and
      "station_destinations_source_owned" in geo and
      "$wpdb->get_col($query)" in geo)
check("geo_missing_rate_remains_opportunity",
      "'geo_transport_price_evidence_unverified','low'" in geo and
      "'nonblocking'=>true" in geo)
check("geo_no_location_invention",
      "'auto_write_authorized'=>false" in geo and
      "'no_assumed_coordinates'=>true" in geo and
      "'no_assumed_berth'=>true" in geo)
check("geo_no_external_requests_or_mutations",
      not any(k in geo for k in ["wp_remote_get(", "wp_remote_post(", "wp_insert_post(", "wp_update_post(", "update_post_meta(", "wp_set_object_terms(", "add_term_meta("]))
expected_codes = {
    'geo_station_destination_unlinked',
    'geo_station_locality_unverified',
    'geo_station_coordinates_invalid',
    'geo_transport_route_stations_invalid',
    'geo_transport_price_evidence_unverified',
    'geo_transport_rate_expired',
    'geo_cruise_boarding_unverified',
    'geo_cruise_boarding_applicability_unverified',
    'geo_property_pickup_unverified',
}
found_codes = set(re.findall(r"'(geo_[a-z_]+)'", geo))
check("complete_exact_geo_alert_code_families",expected_codes.issubset(found_codes))
check("no_staging_hostname_baked_into_sources",
      all("tours.dev.mad4b.com" not in src for src in [geo, locale, readiness]))

# Fail-closed mutations must be detected by the static guard family.
mutations=[
  ("geo_publish", "geo_intake_is_not_mutating",
   "'taxonomy_write_authorized'=>false", "'taxonomy_write_authorized'=>true"),
  ("pricing_authorize", "supplier_rate_evidence_not_claimed",
   "'financial_authorization'=>false", "'financial_authorization'=>true"),
  ("pickup_source", "variable_guest_pickup_retains_source_authority",
   "hash_equals((string)($e['source_sha256']??''),$source)", "true"),
]
for name,guard,before,after in mutations:
    check("negative_mutation_anchor_"+name, readiness.count(before)>=1)
    simulated = readiness.replace(before,after)
    if name=="geo_publish":
        defended="'taxonomy_write_authorized'=>false" in simulated
    elif name=="pricing_authorize":
        defended="'financial_authorization'=>false" in simulated
    else:
        defended="hash_equals((string)($e['source_sha256']??''),$source)" in simulated
    check("negative_mutation_detected_"+name,not defended)

failed = [k for k,v in checks.items() if not v]
for key in failed:
    print("FAIL",key)
print("SUMMARY checks=%d failures=%d" % (len(checks),len(failed)))
if failed: raise SystemExit(1)
