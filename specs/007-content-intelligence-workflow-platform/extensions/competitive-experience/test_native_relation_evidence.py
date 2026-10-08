#!/usr/bin/env python3
"""Non-authorizing Native/WPML relation evidence adversarial contracts."""
import copy
import importlib.util
from pathlib import Path

spec = importlib.util.spec_from_file_location("native_relation_evidence", Path(__file__).with_name("native-relation-evidence.py"))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
SITE = "49c562d1-8f2f-456f-b454-26816c6ba4cb"
ORIGIN = "https://staging.allroyalegypt.com"

def identity(id, kind="post", **kw):
    return dict(id=id, kind=kind, site_uuid=SITE, origin=ORIGIN, exists=True, **kw)

def fixture():
    return dict(
        contract=m.INPUT,
        scope=dict(site_uuid=SITE, origin=ORIGIN, runtime_generation="a"*64,
                   expected_source_ids=[45005,45007], batch_sealed=True, next_cursor=None),
        fields=[
            dict(key="related_tour_id", kind="post", post_type="tours-and-activities", cardinality="one", locale_policy="same_locale"),
            dict(key="related_package_term_id", kind="term", taxonomy="package_category", id_scheme="term_id", cardinality="one", locale_policy="same_locale"),
            dict(key="related_properties_id", kind="post", post_type="properties", cardinality="many", locale_policy="review")],
        records=[
            dict(id=45005, site_uuid=SITE, origin=ORIGIN, post_type="tour-rates", locale="en", translation_group="638158",
                 meta=dict(related_tour_id="25675", related_package_term_id="2288", related_properties_id=["36475","36005"])),
            dict(id=45007, site_uuid=SITE, origin=ORIGIN, post_type="tour-rates", locale="fr", translation_group="638158",
                 meta=dict(related_tour_id="27819", related_package_term_id="2294", related_properties_id=["36475","36005"]))],
        identities=[
            identity(45005, post_type="tour-rates", locale="en", translation_group="638158"),
            identity(45007, post_type="tour-rates", locale="fr", translation_group="638158"),
            identity(25675, post_type="tours-and-activities", locale="en", translation_group="tour"),
            identity(27819, post_type="tours-and-activities", locale="fr", translation_group="tour"),
            identity(36475, post_type="properties", locale="en", translation_group="props"),
            identity(36005, post_type="properties", locale="fr", translation_group="props"),
            identity(2288, kind="term", taxonomy="package_category", id_scheme="term_id", locale="en"),
            identity(2294, kind="term", taxonomy="package_category", id_scheme="term_id", locale="fr")]))

def invalid(bundle, reason):
    try:
        m.audit(bundle)
    except ValueError as error:
        assert str(error) == reason, (str(error), reason)
        return
    raise AssertionError("expected fail closed: " + reason)

checks = 0
def check(truth, label):
    global checks
    assert truth, label
    checks += 1

base = fixture()
r = m.audit(base)
check(r["summary"] == {"STRUCTURAL":4, "UNRESOLVED":4, "ISSUE":0}, "baseline")
check(r["coverage_complete"] and not r["complete"] and not r["eligible_for_mutation"], "no semantic promotion")
check(not r["snapshot_authenticity_verified"] and not r["authorizing"], "no evidence self-certification")
check(m.identifier("9"*5000) is None and m.identifier(True) is None, "unbounded ID denied")
check(m.identifier(str(2**63)) is None, "DB identifier overflow")
case = fixture(); case["records"] = []
check(not m.audit(case)["coverage_complete"] and not m.audit(case)["complete"], "empty batch")
case = fixture(); case["fields"] = []
invalid(case, "field_coverage_required"); checks += 1
case = fixture(); case["identities"][2]["site_uuid"] = "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"
invalid(case, "cross_site_identity_denied"); checks += 1
case = fixture(); case["records"][0]["origin"] = "https://other.example"
invalid(case, "cross_site_record_denied"); checks += 1
case = fixture(); case["records"][0]["meta"]["hidden_password"] = "secret"
invalid(case, "unexpected_meta_field_denied"); checks += 1
case = fixture(); case["identities"][2]["post_type"] = "properties"
check(any(x["reason"] == "target_type_mismatch" for x in m.audit(case)["findings"]), "post type")
case = fixture(); case["identities"][2]["locale"] = "fr"
check(any(x["reason"] == "locale_mismatch_or_unverified" for x in m.audit(case)["findings"]), "cross locale")
case = fixture(); case["identities"][0]["translation_group"] = "foreign"
check(any(x["reason"] == "source_locale_or_group_not_verified" for x in m.audit(case)["findings"]), "source group")
case = fixture(); case["identities"] = [x for x in case["identities"] if x["id"] != 2294]
check(any(x["reason"] == "target_namespace_identity_missing" for x in m.audit(case)["findings"]), "term missing")
case = fixture(); case["fields"][1]["id_scheme"] = "term_taxonomy_id"
check(any(x["reason"] == "target_namespace_identity_missing" for x in m.audit(case)["findings"]), "term scheme")
case = fixture(); case["identities"][6]["taxonomy"] = "foreign"
check(any(x["reason"] == "target_namespace_identity_missing" for x in m.audit(case)["findings"]), "taxonomy namespace")
case = fixture(); case["records"][0]["meta"]["related_properties_id"] = ["36475"]*2200
invalid(case, "relation_edge_budget_exceeded"); checks += 1
case = fixture(); case["records"][0]["meta"]["related_properties_id"] = ["36475","36475"]
check(any(x["reason"] == "target_duplicate" for x in m.audit(case)["findings"]), "duplicate relation")
case = fixture(); case["records"][0]["meta"]["related_properties_id"] = ["invalid"]
check(any(x["reason"] == "target_id_invalid" for x in m.audit(case)["findings"]), "invalid relation")
case = fixture(); case["scope"]["batch_sealed"] = False
check(not m.audit(case)["coverage_complete"], "unsealed batch")
case = fixture(); case["records"].pop()
check(not m.audit(case)["coverage_complete"], "missing source")
case = fixture(); case["scope"]["expected_source_ids"] = [45005]
check(not m.audit(case)["coverage_complete"], "extraneous source")
case = fixture(); case["records"][1]["locale"] = "en"
check(any(x["reason"] == "duplicate_translation_group_locale" for x in m.audit(case)["findings"]), "duplicate group locale")
case = fixture(); case["records"][0]["meta"]["related_tour_id"] = []
check(any(x["reason"] == "cardinality_mismatch" for x in m.audit(case)["findings"]), "cardinality")
case = fixture(); case["identities"][2]["exists"] = False
check(any(x["reason"] == "target_missing" for x in m.audit(case)["findings"]), "deleted target")
case = fixture(); case["fields"][0]["locale_policy"] = "shared"; case["identities"][2]["locale"] = "fr"
r = m.audit(case)
check(any(x["reason"] == "shared_policy_snapshot_only" for x in r["findings"]) and not r["complete"], "shared never semantic")
case = fixture(); case["records"][0]["id"] = case["records"][1]["id"]
invalid(case, "source_identity_duplicate_or_invalid"); checks += 1
case = fixture(); case["scope"]["origin"] = "http://staging.allroyalegypt.com"
invalid(case, "origin_invalid"); checks += 1

print("mad4b.native-relation-evidence.tests.v2: PASS", checks)
