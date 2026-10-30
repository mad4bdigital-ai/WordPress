#!/usr/bin/env python3
"""Read-only two-site readiness audit; --refresh only saves a diagnostic option on central Staging."""
import argparse
import datetime
import fcntl
import hashlib
import json
import re
import os
import pathlib
import ssl
import subprocess
import sys
import tempfile
import urllib.request
import urllib.error

BASE = pathlib.Path("/opt/mad4b-company-provisioning")
CATALOG = BASE / "hub-trial/source-catalog.json"
REPORT = BASE / "reports/company-readiness-workflow-wayfatours.json"
CENTRAL = "etg-commerce-staging-php"
TENANT = "wayfatours-production-php"
READ_SCRIPT = "<?php\n// Read-only company readiness observation; run under WP-CLI, never as a web endpoint.\nglobal $wpdb;\n$source = wp_parse_url(home_url(), PHP_URL_HOST) === 'tours.dev.mad4b.com';\n$registry = get_option('mad4b_tourism_company_site_registry_v1', array());\n$prefs = get_option('mad4b_company_brand_hub_preferences_v1', array());\n$id = get_option('mad4b_company_site_identity_v1', array());\n$role = get_role('master_admin');\n$active = array_values((array)get_option('active_plugins', array()));\n$has = static function ($name) use ($active) {\n    foreach ($active as $path) {\n        if (strpos($path, $name . '/') === 0) return true;\n    }\n    return false;\n};\n$o = array(\n    'host' => wp_parse_url(home_url(), PHP_URL_HOST),\n    'environment' => wp_get_environment_type(),\n    'site_uuid' => is_array($id) ? ($id['site_uuid'] ?? '') : '',\n    'company_code' => is_array($id) ? ($id['company_code'] ?? '') : '',\n    'master_admin_capabilities' => $role ? count($role->capabilities) : 0,\n    'master_admin_manage_options' => (bool)($role && !empty($role->capabilities['manage_options'])),\n    'elementor_active_kit' => (int)get_option('elementor_active_kit'),\n    'site_indexable' => (bool)get_option('blog_public'),\n    'plugins' => array(\n        'elementor' => $has('elementor'),\n        'elementor-pro' => $has('elementor-pro'),\n        'jet-engine' => $has('jet-engine'),\n        'jet-smart-filters' => $has('jet-smart-filters'),\n        'woocommerce' => $has('woocommerce'),\n        'sitepress-multilingual-cms' => $has('sitepress-multilingual-cms')\n    )\n);\nif ($source) {\n    $o['registry'] = array();\n    foreach ((array)$registry as $code => $r) {\n        if (!is_array($r)) continue;\n        $o['registry'][$code] = array(\n            'company_code' => $r['company_code'] ?? '',\n            'company_site_uuid' => $r['company_site_uuid'] ?? '',\n            'native_site_profile_uuid' => $r['native_site_profile_uuid'] ?? '',\n            'host_binding_verified' => !empty($r['host_binding_verified']),\n            'canonical_origin' => $r['canonical_origin'] ?? ''\n        );\n    }\n    $o['selection'] = is_array($prefs) ? ($prefs['wayfatours'] ?? null) : null;\n} else {\n    $o['mirror'] = get_option('mad4b_hosted_trial_selection_v1', null);\n    $counts = $wpdb->get_results(\n        \"SELECT post_type,post_status,COUNT(*) AS qty FROM {$wpdb->posts}\n         WHERE post_type IN ('elementor_library','jet-smart-filters','tours-and-activities','product')\n         GROUP BY post_type,post_status\", ARRAY_A);\n    $o['post_counts'] = $counts;\n    $translations=$wpdb->prefix.'icl_translations';\n    $o['wpml_elementor_translations']=(int)$wpdb->get_var(\n        \"SELECT COUNT(*) FROM {$translations} WHERE element_type='post_elementor_library'\");\n    $o['wpml_elementor_languages']=$wpdb->get_results(\n        \"SELECT language_code,COUNT(*) AS n FROM {$translations}\n         WHERE element_type='post_elementor_library' GROUP BY language_code\",ARRAY_A);\n    $o['published_old_source_count']=(int)$wpdb->get_var(\n        \"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p\n         JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_elementor_data'\n         WHERE p.post_type='elementor_library' AND p.post_status='publish' AND\n         (m.meta_value LIKE '%tours.dev.mad4b.com%' OR\n          m.meta_value LIKE '%wovacation%' OR\n          m.meta_value LIKE '%allroyalegypt%' OR\n          m.meta_value LIKE '%egypttourgates%')\");\n    $rows = $wpdb->get_results($wpdb->prepare(\n        \"SELECT p.ID,p.post_status,m.meta_value FROM {$wpdb->posts} p\n         JOIN {$wpdb->postmeta} m ON m.post_id=p.ID\n         WHERE p.post_type='elementor_library' AND m.meta_key='_elementor_data'\n           AND m.meta_value LIKE %s\", '%tours.dev.mad4b.com%'));\n    $templates = array();\n    $links = array();\n    $fields = array('media' => 0, 'link' => 0, 'other' => 0);\n    $walk = function ($v, $key = '') use (&$walk, &$fields, &$links) {\n        if (is_array($v)) {\n            foreach ($v as $k => $child) $walk($child, (string)$k);\n            return;\n        }\n        if (!is_string($v) || stripos($v, 'tours.dev.mad4b.com') === false) return;\n        // Media cannot be rehosted by replacing the domain; license and asset are separate.\n        $kind = (strpos($v, '/wp-content/uploads/') !== false\n            || preg_match('/(?:image|icon|gallery|thumbnail|background)/i', $key))\n            ? 'media' : (preg_match('/(?:url|link|href)/i', $key) ? 'link' : 'other');\n        $fields[$kind]++;\n        if ($kind === 'link' && wp_parse_url($v, PHP_URL_HOST) === 'tours.dev.mad4b.com') {\n            $path = wp_parse_url($v, PHP_URL_PATH);\n            if (is_string($path) && preg_match('~^/[A-Za-z0-9/_-]{1,250}$~D', $path)) $links[$path] = true;\n        }\n    };\n    foreach ($rows as $row) {\n        $templates[$row->post_status][$row->ID] = true;\n        $decoded = json_decode($row->meta_value, true);\n        if (is_array($decoded)) $walk($decoded);\n        else $fields['other']++; // Invalid JSON is never silently repaired.\n    }\n    $o['template_legacy_draft'] = count($templates['draft'] ?? array());\n    $o['template_legacy_published'] = count($templates['publish'] ?? array());\n    $o['template_legacy_other_status'] = count($templates['trash'] ?? array());\n    $o['legacy_fields'] = $fields;\n    $o['legacy_link_paths'] = array_keys($links);\n}\necho wp_json_encode($o, JSON_UNESCAPED_SLASHES) . PHP_EOL;\n?>"

JET_READ_SCRIPT = """<?php
global $wpdb;
$rows=$wpdb->get_results("SELECT post_type,post_status,COUNT(*) AS qty FROM {$wpdb->posts}
 WHERE post_type IN ('jet-engine','jet-form-builder') GROUP BY post_type,post_status",ARRAY_A);
$links=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations
 WHERE element_type='post_jet-engine'");
echo wp_json_encode(array('post_counts'=>$rows,'wpml_jet_translations'=>$links),
 JSON_UNESCAPED_SLASHES).PHP_EOL;
?>"""
COMPANY_OPTIONS_READ_SCRIPT = """<?php
$registry=get_option('mad4b_company_options_field_registry_v1',array());
$out=array('contract'=>$registry['contract']??'',
           'fingerprint'=>$registry['fingerprint']??'',
           'page_count'=>count($registry['pages']??array()),
           'field_count'=>$registry['field_count']??0,
           'values'=>array());
foreach(($registry['pages']??array()) as $page=>$definition){
  $data=get_option($page,null);$filled=0;$pending=0;
  foreach(($definition['fields']??array()) as $field){
    $key=$field['key']??'';
    if($key==='')continue;
    if(is_array($data) && array_key_exists($key,$data)
       && $data[$key]!==null && $data[$key]!=='' && $data[$key]!==array())$filled++;
    else $pending++;
  }
  $out['values'][$page]=array('filled'=>$filled,'pending'=>$pending);
}
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;
?>"""

STORE_SCRIPT = r"""<?php
if (wp_get_environment_type() !== 'staging' || DB_NAME !== 'etg_commerce_staging'
    || wp_parse_url(home_url(), PHP_URL_HOST) !== 'tours.dev.mad4b.com')
    WP_CLI::error('NOT_CENTRAL_STAGING');
$report = json_decode((string)getenv('MAD4B_WORKFLOW_REPORT'), true);
$registry = get_option('mad4b_tourism_company_site_registry_v1', array());
if (!is_array($report) || ($report['contract'] ?? '') !== 'mad4b.company-readiness-workflow.v1'
    || ($report['company'] ?? '') !== 'wayfatours'
    || !isset($registry['wayfatours'])
    || ($report['site_uuid'] ?? '') !== ($registry['wayfatours']['company_site_uuid'] ?? null))
    WP_CLI::error('UNBOUND_REPORT');
$s = get_option('mad4b_company_readiness_workflow_status_v1', array());
if (!is_array($s)) WP_CLI::error('INVALID_REPORT_STORE');
$s['wayfatours'] = $report;
update_option('mad4b_company_readiness_workflow_status_v1', $s, false);
$read = get_option('mad4b_company_readiness_workflow_status_v1', array());
if (($read['wayfatours']['sha256'] ?? null) !== ($report['sha256'] ?? ''))
    WP_CLI::error('REPORT_READBACK_FAILED');
echo wp_json_encode(array('state'=>'READINESS_SNAPSHOT_STORED','sha256'=>$report['sha256']));
?>"""

def wp(container, source, env=None):
    command = ["docker", "exec", "-i", "-u", "33:33"]
    for k, v in (env or {}).items():
        command += ["-e", k + "=" + v]
    command += [container, "php", "-d", "memory_limit=512M",
                "/usr/local/bin/wp", "eval-file", "-", "--path=/var/www/html",
                "--skip-plugins", "--skip-themes"]
    p = subprocess.run(command, input=source, capture_output=True, text=True, timeout=100)
    if p.returncode:
        raise RuntimeError("wp_failed:" + container + ":" + p.stderr[-350:].replace("\n", " "))
    for line in reversed(p.stdout.splitlines()):
        if line.startswith("{"):
            return json.loads(line)
    raise RuntimeError("missing_json:" + container)

def https(url):
    try:
        with urllib.request.urlopen(url, context=ssl.create_default_context(), timeout=12) as r:
            return {"http": r.status, "tls_verified": True}
    except urllib.error.HTTPError as e:
        return {"http": e.code, "tls_verified": True}
    except Exception as e:
        return {"http": None, "tls_verified": False, "error": type(e).__name__}

def policy(source, tenant, catalog, health):
    reg = source.get("registry", {}).get("wayfatours", {})
    selection = source.get("selection")
    mirror = tenant.get("mirror")
    identity_ok = bool(reg.get("host_binding_verified")
        and reg.get("canonical_origin") == "https://wayfatours.mad4b.com"
        and tenant.get("host") == "wayfatours.mad4b.com"
        and tenant.get("site_uuid") == reg.get("company_site_uuid")
        and tenant.get("company_code") == "wayfatours")
    counters = {f"{x.get('post_type')}:{x.get('post_status')}":int(x.get("qty",0))
                for x in tenant.get("post_counts", [])}
    stages = {}
    def stage(key, state, detail, action):
        stages[key] = {"state":state, "detail":detail, "next_action":action}
    stage("company_identity", "PASS" if identity_ok else "BLOCKED",
          "Independent tenant UUID and central registry binding",
          "RECONCILE_COMPANY_BINDING")
    role_ok = tenant.get("master_admin_capabilities")==84 and not tenant.get("master_admin_manage_options")
    stage("master_admin_role", "PASS" if role_ok else "BLOCKED",
          f"role_capabilities={tenant.get('master_admin_capabilities')}; no WordPress manage_options",
          "RECONCILE_MASTER_ADMIN_ROLE")
    # URLs inside Elementor media references may require licensed copy, never blind string replacement.
    draft_legacy = tenant.get("template_legacy_draft", 0)
    live_legacy = tenant.get("template_legacy_published", 0)
    stage("legacy_template_references",
          "BLOCKED" if live_legacy else ("REMEDIATION_REQUIRED" if draft_legacy else "PASS"),
          f"draft_templates={draft_legacy}; published_templates={live_legacy}; references={tenant.get('legacy_fields')}",
          "CLASSIFY_REMAP_LINKS_AND_VERIFY_MEDIA_LICENSE")
    missing_links = tenant.get("missing_link_targets", [])
    stage("internal_link_target_readback", "REMEDIATION_REQUIRED" if missing_links else "PASS",
          f"unresolved_target_paths={len(missing_links)}; paths={missing_links}",
          "CREATE_OR_MAP_COMPANY_LANGUAGE_PAGES_THEN_RECHECK_TARGET_URLS")
    wpml_translations = tenant.get("wpml_elementor_translations", 0)
    stage("wpml_template_translation_resolution",
          "PASS" if wpml_translations == 471 and len(tenant.get("wpml_elementor_languages", [])) == 6 else "BLOCKED",
          f"tenant_elementor_wpml_rows={wpml_translations}; languages={len(tenant.get('wpml_elementor_languages', []))}",
          "REBUILD_TENANT_SCOPED_WPML_TRANSLATION_GROUPS")
    source_refs_live = tenant.get("published_old_source_count", -1)
    brand_ok = (tenant.get("company_code")=="wayfatours" and
                tenant.get("elementor_active_kit")==4 and source_refs_live==0)
    stage("brand_identity_template_resolution", "PASS" if brand_ok else "BLOCKED",
          f"tenant_kit={tenant.get('elementor_active_kit')}; published_old_brand_source_refs={source_refs_live}",
          "RESOLVE_COMPANY_BRAND_KIT_MEDIA_AND_PAGE_TARGETS")
    draft_total = counters.get("elementor_library:draft", 0)
    live_templates = counters.get("elementor_library:publish", 0)
    stage("template_activation", "PENDING" if draft_total else "PASS",
          f"draft={draft_total}; published={live_templates}; company_kit_excluded_from_template_progress=True",
          "CONTINUE_SOURCE_STATE_AWARE_BRAND_NEUTRAL_TEMPLATE_QUALIFICATION")
    activated=max(0,live_templates-1)
    stage("selective_design_activation",
          "PARTIAL" if activated else "PENDING",
          f"source_neutral_templates_published={activated}; remaining_drafts={draft_total}",
          "RESOLVE_QUERY_WIDGET_MEDIA_AND_COMPANY_OPTIONS_PER_TEMPLATE_FAMILY")
    jet_pub=counters.get("jet-engine:publish",0)
    jet_draft=counters.get("jet-engine:draft",0)
    stage("jetengine_schema_auto_activation",
          "PARTIAL" if jet_pub and jet_draft else ("PASS" if jet_pub and not jet_draft else "PENDING"),
          f"active_listings={jet_pub}; deferred_for_real_differences={jet_draft}",
          "RUN_JETENGINE_CONFIG_AUTO_WITH_ON_DIFF_PROVENANCE")
    jet_wpml=tenant.get("wpml_jet_translations",0)
    stage("jetengine_tenant_wpml", "PASS" if jet_wpml==332 else "BLOCKED",
          f"tenant_linked_jet_designs={jet_wpml}; expected=332",
          "CREATE_COMPANY_NATIVE_JET_TRANSLATION_GROUPS")
    filter_count = counters.get("jet-smart-filters:publish",0)
    stage("jet_filter_inventory", "PASS" if filter_count else "BLOCKED",
          f"published={filter_count}; live filtered-archive smoke test still required",
          "RUN_FILTER_QUERY_ID_LISTING_SMOKE")
    approved = (source.get("canonical_content_hub_database_readback_verified") is True
                and not catalog.get("catalog_discovery_only")
                and catalog.get("distribution_status") == "AUTHORIZED_FOR_TENANT_PUBLICATION"
                and all(item.get("share_state") != "candidate_only" for item in catalog.get("items",[])))
    stage("canonical_hub_ingestion", "PASS" if approved else "BLOCKED",
          f"candidate_count={len(catalog.get('items',[]))}; candidate_only={catalog.get('catalog_discovery_only')}; canonical_db_readback_verified={source.get('canonical_content_hub_database_readback_verified',False)}",
          "INGEST_QUALIFY_DEDUP_CANONICAL_HUB")
    selection_ok = isinstance(selection,dict) and len(selection.get("tours",[]))>0
    stage("company_selection", "PASS" if selection_ok else "WAITING_SELECTION",
          f"central_revision={selection.get('revision') if selection_ok else 0}",
          "SAVE_REAL_COMPANY_PREFERENCES")
    mirrored = bool(selection_ok and isinstance(mirror,dict)
        and mirror.get("central_managed") is True
        and mirror.get("central_revision")==selection.get("revision")
        and mirror.get("central_catalog_sha256")==selection.get("catalog_sha256"))
    stage("preference_sync_readback", "PASS" if mirrored else "PENDING",
          "Central selection revision must match tenant readback",
          "TEST_REAL_PREFERENCE_SYNC_AND_IDEMPOTENCE")
    stage("company_scoped_access", "PENDING",
          "Multi-company operator ACL and isolation needs live negative cross-company test",
          "VERIFY_COMPANY_OPERATOR_ASSIGNMENT_ACL")
    stage("dynamic_pricing_rates", "PENDING",
          "Company rates, product rates, dynamic pricing and generator require source-backed commercial tests",
          "VERIFY_SOURCE_RATE_AND_COMPANY_PRICING")
    stage("mcp_external_projection", "PENDING",
          "WordPress Ability registration does not prove external MCP projection",
          "VERIFY_EXTERNAL_MCP_TOOL_DISCOVERY")
    stage("reusable_bootstrap", "PENDING",
          "Foundation blueprint exists but new-tenant idempotent bootstrap not yet accepted",
          "CERTIFY_REPLAYABLE_BOOTSTRAP_ON_ISOLATED_TENANT")
    is_up = all(v.get("http")==200 and v.get("tls_verified") for v in health.values())
    stage("site_https", "PASS" if is_up else "BLOCKED",
          "Real HTTPS CA validation on both named sites", "REPAIR_SITE_HTTPS")
    stage("wp_cli_memory", "PENDING", "Audit executed PHP WP-CLI using 512M; default 256M still requires optimization",
          "OPTIMIZE_DEFAULT_CLI_MEMORY_LOAD")
    # No route from this workflow directly to publish, checkout or tenant asset rewriting.
    can_distribute = all(x["state"]=="PASS" for x in stages.values())
    return stages, can_distribute, counters, bool(mirrored)

def run(refresh=False):
    with open(CATALOG, encoding="utf-8") as f: catalog=json.load(f)
    src=wp(CENTRAL, READ_SCRIPT)
    tenant=wp(TENANT, READ_SCRIPT)
    jet=wp(TENANT, JET_READ_SCRIPT)
    options_central=wp(CENTRAL, COMPANY_OPTIONS_READ_SCRIPT)
    options_tenant=wp(TENANT, COMPANY_OPTIONS_READ_SCRIPT)
    tenant["post_counts"].extend(jet["post_counts"])
    tenant["wpml_jet_translations"]=jet["wpml_jet_translations"]
    design_policy_file = BASE / "policies/company-design-activation-v2.json"
    design_policy = json.loads(design_policy_file.read_text())
    design_policy_sha256 = hashlib.sha256(design_policy_file.read_bytes()).hexdigest()
    if design_policy.get("contract") != "mad4b.company-design-activation-policy.v2" or design_policy.get("default_action") != "DENY_UNTIL_QUALIFIED":
        raise RuntimeError("DESIGN_GOVERNANCE_POLICY_INVALID")
    health={u:https("https://"+u+"/") for u in
            ("tours.dev.mad4b.com","wayfatours.mad4b.com")}
    # Only fixed company host and URL paths obtained from its own Elementor drafts.
    target_paths = [x for x in tenant.get("legacy_link_paths", [])
                    if isinstance(x,str) and re.fullmatch(r"/[A-Za-z0-9/_-]{1,250}",x)]
    links = {p:https("https://wayfatours.mad4b.com"+p) for p in target_paths}
    tenant["missing_link_targets"] = [p for p,v in links.items() if v.get("http") != 200]
    stages,eligible,counters,mirrored=policy(src,tenant,catalog,health)
    options_match=(options_central.get("contract")=="mad4b.company-options-field-registry.v1"
      and options_central.get("fingerprint")==options_tenant.get("fingerprint")
      and options_central.get("page_count")==4
      and options_tenant.get("field_count")==112)
    stages["company_options_schema_registry"]={
       "state":"PASS" if options_match else "BLOCKED",
       "detail":f"registry_schema_equal={options_match}; pages={options_tenant.get('page_count')}; "
                f"fields={options_tenant.get('field_count')}; source_business_values_imported=False",
       "next_action":"RECONCILE_CENTRAL_JETENGINE_PAGE_SCHEMAS_WITH_TENANT"}
    options_filled=sum(v.get("filled",0) for v in options_tenant.get("values",{}).values())
    stages["company_options_owner_customization"]={
       "state":"AVAILABLE_FOR_OWNER_INPUT",
       "detail":f"filled={options_filled}; "
                f"total_fields={options_tenant.get('field_count')}; "
                "owner_edit_via_jetengine_options_pages=True",
       "next_action":"OWNER_ENTERS_COMPANY_SPECIFIC_BRAND_MEDIA_CONTACT_AND_OFFERS_AFTER_WORKFLOW"}
    compatibility_file=BASE/"reports/wayfatours-schema-compatibility.json"
    if compatibility_file.exists():
        compatibility=json.loads(compatibility_file.read_text())
        fresh=(datetime.datetime.now(datetime.timezone.utc)-
               datetime.datetime.fromisoformat(compatibility["generated_utc"])).total_seconds()<3600
        parity=fresh and compatibility.get("state")=="SCHEMA_PARITY_CONFIRMED"
        stages["live_schema_compatibility"]={
          "state":"PASS" if parity else "RECHECK_REQUIRED",
          "detail":f"config_parity={parity}; portable_models=307; taxonomy_models=34; "
                   f"source_only_exclusions={compatibility.get('source_only_exclusions',{})}; fresh={fresh}",
          "next_action":"PROBE_BOTH_SITE_SCHEMAS_BEFORE_TEMPLATE_APPROVAL"}
    diffs_file=BASE/"reports/wayfatours-compatibility-diff-resolution.json"
    if diffs_file.exists():
        diffs=json.loads(diffs_file.read_text())
        issues=len(diffs.get("resolutions",[]))
        stages["conditional_resolution_engine"]={
          "state":"ACTIVE" if issues else "PASS",
          "detail":f"on_diff=true; grounded_issues={issues}; unsafe_rewrites=false",
          "next_action":"RESOLVE_BRAND_MEDIA_PROVIDERS_AND_DISPLAY_CONDITIONS"}
    reg=src.get("registry",{}).get("wayfatours",{})
    report={
      "contract":"mad4b.company-readiness-workflow.v1",
      "company":"wayfatours",
      "site_uuid":reg.get("company_site_uuid",""),
      "generated_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
      "result":"READY" if eligible else "BLOCKED_FOR_COMMERCIAL_DISTRIBUTION",
      "template_activation_allowed":eligible,
      "content_distribution_allowed":eligible,
      "checkout_enabled_by_workflow":False,
      "auto_template_rewrite":False,
      "auto_media_copy":False,
      "central_selection_present":bool(src.get("selection")),
      "sync_mirrored":mirrored,
      "catalog_candidate_count":len(catalog.get("items",[])),
      "template_counts":counters,
      "stages":stages,
      "https":health,
      "design_policy": {"contract":design_policy["contract"],"sha256":design_policy_sha256},
      "target_link_readback":links
    }
    fingerprint=json.dumps(report,sort_keys=True,separators=(",",":")).encode()
    report["sha256"]=hashlib.sha256(fingerprint).hexdigest()
    if refresh:
        REPORT.parent.mkdir(parents=True, exist_ok=True)
        fd, temp=tempfile.mkstemp(dir=REPORT.parent, prefix=".wayfa-ready-", text=True)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as f:
                json.dump(report,f,indent=2,ensure_ascii=False)
                f.write("\n")
            os.chmod(temp, 0o640)
            os.replace(temp, REPORT)
        finally:
            if os.path.exists(temp):os.unlink(temp)
        outcome=wp(CENTRAL,STORE_SCRIPT,{"MAD4B_WORKFLOW_REPORT":json.dumps(report,separators=(",",":"))})
        report["stored"]=outcome.get("state")=="READINESS_SNAPSHOT_STORED"
    return report

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--refresh",action="store_true",help="Save receipt and central Staging status only")
    parser.add_argument("--selftest",action="store_true",help="Validate core deny rules without network")
    args=parser.parse_args()
    if args.selftest:
        baseline={"catalog_discovery_only":True,"items":[{"share_state":"candidate_only"}]}
        a,b,_,_=policy({"registry":{},"selection":None},{"post_counts":[]},baseline,{})
        assert b is False and a["canonical_hub_ingestion"]["state"]=="BLOCKED"
        assert a["company_identity"]["state"]=="BLOCKED"
        v={"post_counts":[],"missing_link_targets":["/missing/"]}
        stages,eligible,_,_=policy({"registry":{},"selection":None},v,baseline,{})
        assert not eligible and stages["internal_link_target_readback"]["state"]=="REMEDIATION_REQUIRED"
        print(json.dumps({"selftest":"PASS","production_mutation":False}))
        return
    lock=BASE / "reports/.company-readiness.lock"
    lock.parent.mkdir(parents=True,exist_ok=True)
    with lock.open("a+") as f:
        fcntl.flock(f.fileno(),fcntl.LOCK_EX)
        report=run(args.refresh)
    print(json.dumps({"state":report["result"],"sha256":report["sha256"],
        "stages":{k:v["state"] for k,v in report["stages"].items()},
        "stored":report.get("stored",False),"tenant_mutated":False,
        "media_copied":False,"published":False},ensure_ascii=False))
if __name__ == "__main__":
    main()

