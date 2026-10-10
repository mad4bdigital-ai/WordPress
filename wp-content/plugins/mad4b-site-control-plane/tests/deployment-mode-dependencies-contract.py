#!/usr/bin/env python3
"""Read-only, fail-closed dependency audit for MAD4B WordPress Dedicated mode.

Never activates abilities, installs files, reads secrets or grants release authority.
The optional core seed and both git HEAD leases are needed for cross-repo acceptance.
"""
import argparse
import hashlib
import json
import zipfile
import re
import shutil
import subprocess
from pathlib import Path

MODES = ("shared_multi_tenant", "dedicated_isolated", "dedicated_autonomous", "wordpress_dedicated")
CONTRACT = "mad4b.context-deployment-mode.v1"
ADAPTER = "mad4b.deployment-mode-resolution.v1"

def git_head(path):
    try:
        r = subprocess.run(["git","-C",str(path),"rev-parse","HEAD"],
                           capture_output=True,text=True,timeout=8,check=False)
        return r.stdout.strip() if r.returncode==0 and re.fullmatch(r"[a-f0-9]{40}",r.stdout.strip()) else None
    except (OSError,subprocess.TimeoutExpired):
        return None

def inspect(plugin_root: Path, core_seed: Path|None=None, expected_wp_head=None, expected_core_head=None, run_php=False, package_zip: Path|None=None):
    plugin_root=plugin_root.resolve()
    checks={}
    def check(key,predicate):
        checks[key]=bool(predicate)

    cfg_path=plugin_root/"config/deployment-mode-dependencies.json"
    base_path=plugin_root/"includes/class-mad4b-scp-deployment-mode-resolver.php"
    bootstrap_path=plugin_root/"mad4b-site-control-plane.php"
    fixture=plugin_root/"tests/deployment-mode-resolver-runtime.php"
    if not all(p.is_file() for p in [cfg_path,base_path,bootstrap_path,fixture]):
        return {"status":"BLOCKED","reason":"MISSING_LOCAL_DEPENDENCIES","checks":{},"operational_acceptance":False}
    try:
        cfg=json.loads(cfg_path.read_text(encoding="utf-8"))
    except (OSError,ValueError):
        return {"status":"BLOCKED","reason":"INVALID_DEPENDENCY_MANIFEST","checks":{},"operational_acceptance":False}
    plugin=base_path.read_text(encoding="utf-8")
    bootstrap=bootstrap_path.read_text(encoding="utf-8")
    test_source=fixture.read_text(encoding="utf-8")
    check("four_modes",cfg.get("modes")==list(MODES) and len(set(cfg.get("modes",[])))==4)
    check("contract_match",cfg.get("common_contract")==CONTRACT and cfg.get("adapter_contract")==ADAPTER
          and "const COMMON_CONTRACT = '"+CONTRACT+"';" in plugin and "const CONTRACT = '"+ADAPTER+"';" in plugin)
    check("native_default",cfg.get("host_default")=="wordpress_dedicated" and
          "const MODE = 'wordpress_dedicated';" in plugin)
    check("bootstrap_wiring","class-mad4b-scp-deployment-mode-resolver.php" in bootstrap
          and "MAD4B_SCP_Deployment_Mode_Resolver::boot();" in bootstrap)
    check("scoped_site","MAD4B_SCP_Site_Profile::status()" in plugin
          and "MAD4B_SCP_Context_Authority::profile()" in plugin
          and "get_current_blog_id()" in plugin and "get_current_network_id()" in plugin)
    check("enrolled_binding","deployment_binding_bound" in plugin
          and "deployment_binding_configured" in plugin
          and "deployment_binding_match" in plugin)
    check("per_blog_cache_fence","SITE_BLOG_LOCAL_BINDING_MISMATCH" in plugin
          and "get_option( MAD4B_SCP_Site_Profile::OPTION" in plugin
          and "MAD4B_SCP_Site_Profile::current_origin()" in plugin)
    check("spoof_and_version_guard","REQUEST_SCOPE_MISMATCH" in plugin
          and "BRAND_PROFILE_REVISION_MISSING" in plugin)
    check("no_mode_authority","'execution_authorized' => false" in plugin
          and "'publication_authorized' => false" in plugin
          and cfg.get("invariants",{}).get("no_production_mutation_without_separate_grant") is True)
    check("mcp_policy_wiring","MAD4B_SCP_Policy" in plugin and "'can_read'" in plugin
          and "mad4b/deployment-mode-status" in plugin)
    check("identity_vs_provider_dependencies",
          len(cfg.get("required_for_identity",[]))==3
          and len(cfg.get("optional_capabilities",[]))>=4
          and cfg.get("failure_classification",{}).get("optional_provider_unavailable")=="CAPABILITY_UNAVAILABLE")
    check("negative_fixture",all(x in test_source for x in
          ("tenant spoof rejected","mode spoof rejected","switched blog may not reuse static site profile cache",
           "unbound legacy profile blocked","unversioned brand context blocked")))

    wp_git=git_head(plugin_root)
    check("wp_head_pinned",bool(expected_wp_head and wp_git==expected_wp_head))
    core_git=None
    if core_seed is not None:
        core_seed=core_seed.resolve()
        try:
            core_cfg=json.loads((core_seed/"deployment-mode-contract.json").read_text(encoding="utf-8"))
            core_manifest=json.loads((core_seed/"manifest.json").read_text(encoding="utf-8"))
            core_js=(core_seed/"runtime/resolve-deployment-context.mjs").read_text(encoding="utf-8")
            check("core_modes",list(core_cfg.get("modes",{}).keys())==list(MODES)
                  and core_manifest.get("deployment_modes")=="FOUR_FIRST_CLASS_VARIANTS")
            check("core_defaults",core_cfg.get("default_selection",{}).get("platform")=="shared_multi_tenant"
                  and core_cfg.get("default_selection",{}).get("wordpress_plugin")=="wordpress_dedicated")
            check("core_never_grants",core_cfg.get("execution_authorized") is False and
                  core_cfg.get("publication_authorized") is False and
                  "HOST_BINDING_UNVERIFIED" in core_js)
            core_git=git_head(core_seed)
            check("core_head_pinned",bool(expected_core_head and core_git==expected_core_head))
        except (OSError,ValueError):
            check("core_files_present",False)
    else:
        check("core_not_verified",False)

    php_status="NOT_REQUESTED"
    if run_php:
        php=shutil.which("php")
        if php:
            try:
                syntax=subprocess.run([php,"-l",str(base_path)],capture_output=True,text=True,timeout=15,check=False)
                fixture_result=subprocess.run([php,str(fixture)],cwd=plugin_root,capture_output=True,text=True,timeout=30,check=False)
                php_status="PASS" if syntax.returncode==0 and fixture_result.returncode==0 else "FAIL"
            except (OSError,subprocess.TimeoutExpired):
                php_status="BLOCKED"
        else:
            php_status="RUNTIME_UNAVAILABLE"
        check("php_native_tests",php_status=="PASS")
    else:
        check("php_native_not_run",False)

    archive_status="NOT_SUPPLIED"
    if package_zip is not None:
        try:
            package_zip=package_zip.resolve()
            keys=["mad4b-site-control-plane.php",
                  "includes/class-mad4b-scp-deployment-mode-resolver.php",
                  "config/deployment-mode-dependencies.json"]
            with zipfile.ZipFile(package_zip) as archive:
                names=set(archive.namelist())
                def matching_member(key):
                    variants=[key,"mad4b-site-control-plane/"+key]
                    return next((name for name in variants if name in names),None)
                if any(matching_member(key) is None for key in keys):
                    archive_status="MISSING_REQUIRED_PLUGIN_FILES"
                else:
                    match=all(hashlib.sha256(archive.read(matching_member(key))).digest() ==
                              hashlib.sha256((plugin_root/key).read_bytes()).digest() for key in keys)
                    archive_status="PASS_EXACT_SOURCE" if match else "ARCHIVE_SOURCE_MISMATCH"
        except (OSError,ValueError,zipfile.BadZipFile):
            archive_status="INVALID_ARCHIVE"
        check("packaged_adapter_exact_source",archive_status=="PASS_EXACT_SOURCE")
    passed=sum(checks.values())
    final=passed==len(checks)
    return {"contract":"mad4b.wordpress-dedicated-dependency-audit.v1",
            "status":"PASS_STATIC_PINNED" if final else "BLOCKED",
            "reason":"ALL_CHECKS_PASSED" if final else "MISSING_OR_UNVERIFIED_DEPENDENCIES",
            "expected_wp_head":expected_wp_head,"observed_wp_head":wp_git,
            "expected_core_head":expected_core_head,"observed_core_head":core_git,
            "counts":{"passed":passed,"total":len(checks)},
            "checks":checks,"php_status":php_status,"package_status":archive_status,
            "operational_acceptance":False,"publication_authorized":False,"production_authorized":False}

if __name__=="__main__":
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plugin-root",type=Path,default=Path(__file__).resolve().parents[1])
    parser.add_argument("--core-seed",type=Path)
    parser.add_argument("--wp-head")
    parser.add_argument("--core-head")
    parser.add_argument("--run-php",action="store_true")
    parser.add_argument("--package-zip",type=Path)
    args=parser.parse_args()
    out=inspect(args.plugin_root,args.core_seed,args.wp_head,args.core_head,args.run_php,args.package_zip)
    print(json.dumps(out,ensure_ascii=False,indent=2))
    raise SystemExit(0 if out["status"]=="PASS_STATIC_PINNED" else 1)
