#!/usr/bin/env python3
"""Fail-closed unit tests for company runtime onboarding dependency gates."""
import importlib.util
from pathlib import Path
p=Path(__file__).resolve().parent/"company-governed-runtime-gates.py"
spec=importlib.util.spec_from_file_location("runtime_gates",p)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
cfg={"company_code":"wayfatours","site_host":"wayfatours.mad4b.com",
 "site_uuid":"test-uuid","database":"wayfatours_production","company_name":"Wayfatours",
 "required_runtime_plugins":["elementor","woocommerce"],
 "required_commercial_plugins":["mad4b-pricing-registry","woocommerce-travel-bridge"]}
site={"company":"wayfatours","host":"wayfatours.mad4b.com",
 "site_uuid":"test-uuid","database":"wayfatours_production",
 "plugins":{k:True for k in cfg["required_runtime_plugins"]+cfg["required_commercial_plugins"]},
 "core":{"cpt_tours":True,"cpt_properties":True,"cpt_transport":True,
         "wc_available":True,"wpml_available":True,"company_options_registry":"digest"},
 "providers":{"functions":{k:True for k in
 ["pricing_audience_quote","pricing_currency_convert","tour_rate_native_card_rate_id",
 "tour_component_price_evidence","transport_component_price_current_id"]}},
 "template":{"missing_dynamic_tag_instances":{},
  "missing_dynamic_tag_unique_templates":{},
  "source_media_references":{},"source_internal_links":{},
  "theme_builder":{"published_headers":1,"published_singles":1,
                   "published_with_conditions":2,"draft_with_conditions":0}}}
login={"status":200,"tls_verified":True,"presentation_loaded":True,
 "layout_card":True,"company_label":True,"source_brand_leaked":False}
good=module.evaluate(cfg,site,login)
assert all(x["state"]=="PASS" for x in good.values()),good
from copy import deepcopy
bad=deepcopy(site)
bad["plugins"]["mad4b-pricing-registry"]=False
bad["providers"]["functions"]["pricing_audience_quote"]=False
bad["template"]["missing_dynamic_tag_instances"]["jet-engine:publish"]=14
bad["template"]["missing_dynamic_tag_unique_templates"]["jet-engine:publish"]=2
issues=module.evaluate(cfg,bad,login)
assert issues["commercial_pricing_and_rate_engines"]["state"]=="BLOCKED"
assert issues["published_template_runtime_dependencies"]["state"]=="BLOCKED"
assert "affected_published_templates=2" in issues["published_template_runtime_dependencies"]["detail"]
bad=deepcopy(site)
bad["template"]["source_media_references"]["elementor_library:draft"]=7
issues=module.evaluate(cfg,bad,login)
assert issues["company_page_url_and_wpml_links"]["state"]=="BLOCKED"
assert issues["company_media_asset_mappings"]["state"]=="PASS"
bad=deepcopy(site)
bad["template"]["theme_builder"]["published_headers"]=0
assert module.evaluate(cfg,bad,login)["elementor_theme_builder_conditions"]["state"]=="BLOCKED"
login_legacy=dict(login,source_brand_leaked=True)
assert module.evaluate(cfg,site,login_legacy)["company_login_code_and_identity"]["state"]=="BLOCKED"
try:
 module.evaluate(cfg,dict(site,company="source-company"),login)
 raise AssertionError("tenant identity should be enforced")
except RuntimeError:pass
print("PASS: commercial provider, media, conditions, login identity and tenant isolation fail-closed")
