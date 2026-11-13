"""Read-only whole-WordPress image coverage and mount-boundary assessment.

The original Runtime Publisher/Original312/313 remain the only authority.
This module is an inspector, never a deployment, chown or DB migration tool.
"""
from __future__ import annotations
import hashlib
import importlib.util
import json
from pathlib import Path, PurePosixPath
import re
import stat

CONTRACT = "mad4b.immutable-runtime.site-topology-audit.v1"
SLUG = re.compile(r"^[a-z0-9][a-z0-9._-]{0,150}$")
CODE_ROOT = "/var/www/html"
_model_spec = importlib.util.spec_from_file_location(
    "_mad4b_runtime_topology_model",
    Path(__file__).with_name("immutable_runtime.py"))
runtime = importlib.util.module_from_spec(_model_spec)
_model_spec.loader.exec_module(runtime)


class TopologyHold(ValueError):
    pass


def validate_target(path: str, *, code_root: str = CODE_ROOT) -> str:
    if not isinstance(path,str) or not path.startswith("/") or "\x00" in path or \
            "\\" in path or "//" in path:
        raise TopologyHold("RUNTIME_MOUNT_PATH_INVALID")
    p=PurePosixPath(path)
    if ".." in path.split("/") or p.as_posix()!=path:
        raise TopologyHold("RUNTIME_MOUNT_PATH_INVALID")
    if path==code_root or not path.startswith(code_root+"/"):
        raise TopologyHold("RUNTIME_DATA_MOUNT_NOT_CHILD_OF_CODE_ROOT")
    return path


def code_inventory(site_root: Path) -> dict:
    """Names/modes only. Never reads wp-config.php or secret file content."""
    if not site_root.is_dir() or site_root.is_symlink():
        raise TopologyHold("LIVE_WP_ROOT_UNAVAILABLE")
    source=[]
    symlinks=[]
    for family,directory in (
        ("plugins",site_root/"wp-content/plugins"),
        ("themes",site_root/"wp-content/themes"),
        ("mu-plugins",site_root/"wp-content/mu-plugins"),
    ):
        if not directory.is_dir() or directory.is_symlink():
            raise TopologyHold("LIVE_CODE_FAMILY_DIRECTORY_UNAVAILABLE:"+family)
        names=[]
        for child in directory.iterdir():
            if child.is_symlink():
                symlinks.append(family+"/"+child.name)
                continue
            # Plugins can be single-file PHP packages; MU packages can
            # have both a bootstrap PHP file and an included directory.
            if child.is_dir() and SLUG.fullmatch(child.name):
                names.append(child.name)
            elif child.is_file() and child.suffix==".php" and SLUG.fullmatch(child.name):
                names.append(child.name)
        source.append((family,sorted(names)))
    core=site_root/"wp-load.php"
    if not core.is_file() or core.is_symlink():
        raise TopologyHold("LIVE_WORDPRESS_CORE_FILE_MISSING")
    return {
        "core_present": True,
        "code_file_owner_uid": core.stat().st_uid,
        "code_file_mode": oct(stat.S_IMODE(core.stat().st_mode)),
        "families": {family:names for family,names in source},
        "unreviewed_code_symlink_count": len(symlinks),
        "structure_sha256": hashlib.sha256(json.dumps(source,
                  ensure_ascii=False,separators=(",",":"),sort_keys=True
                  ).encode()).hexdigest(),
    }


def audit(*, current: dict, live_mounts: list[dict],
          runtime_plan: dict, candidate_core_in_target_root: bool,
          candidate_wordpress_config_external: bool,
          proposed_writable_data_mounts: list[str],
          candidate_mounts: list[dict] | None = None,
          candidate_code_root_protected: bool = False,
          code_root: str = CODE_ROOT) -> dict:
    """Fail closed on incomplete code, unclassified writable mounts and rootfs."""
    if not isinstance(current,dict) or current.get("core_present") is not True:
        raise TopologyHold("LIVE_WORDPRESS_INVENTORY_INVALID")
    if not isinstance(live_mounts,list):
        raise TopologyHold("RUNTIME_TOPOLOGY_DATA_INVALID")
    try:
        runtime.verify_plan(runtime_plan)
    except runtime.RuntimeHold as exc:
        raise TopologyHold("RUNTIME_TOPOLOGY_PLAN_UNVERIFIED") from exc
    runtime_layers = runtime_plan["layers"]
    if not isinstance(proposed_writable_data_mounts,list):
        raise TopologyHold("RUNTIME_DATA_MOUNT_POLICY_MISSING")
    allowed=[validate_target(path,code_root=code_root)
             for path in proposed_writable_data_mounts]
    if len(set(allowed))!=len(allowed):
        raise TopologyHold("RUNTIME_DATA_MOUNT_DUPLICATE")
    for i,p in enumerate(allowed):
        if any(p.startswith(q+"/") or q.startswith(p+"/")
               for j,q in enumerate(allowed) if j!=i):
            raise TopologyHold("RUNTIME_DATA_MOUNTS_OVERLAP")
        if not p.startswith(code_root+"/wp-content/"):
            raise TopologyHold("RUNTIME_DATA_MOUNT_MUST_BE_WP_CONTENT")
    mounts=[]
    code_binding=None
    for mount in live_mounts:
        if not isinstance(mount,dict) or not isinstance(mount.get("Destination"),str):
            raise TopologyHold("LIVE_MOUNT_RECORD_INVALID")
        dest=mount["Destination"]
        if not dest.startswith("/") or dest.startswith("/../") or "\x00" in dest:
            raise TopologyHold("LIVE_MOUNT_DESTINATION_INVALID")
        readonly=mount.get("RW") is False
        mounts.append({"destination":dest,"readonly":readonly,
                       "type":mount.get("Type","unknown")})
        if dest==code_root:
            code_binding=mount
    blockers=[]
    legacy_risks=[]
    # A writable legacy A is a reason to migrate, not grounds to forbid
    # activating a verified immutable B. Preserve the former for rollback.
    if code_binding is not None and code_binding.get("RW") is True:
        legacy_risks.append("LIVE_WORDPRESS_CODE_ROOT_WRITABLE_BIND")
    if current["code_file_owner_uid"] != 0 or (
            int(current["code_file_mode"],8)&0o222):
        legacy_risks.append("LIVE_WORDPRESS_CORE_WRITABLE_BY_RUNTIME")
    if current.get("unreviewed_code_symlink_count",0):
        blockers.append("LIVE_CODE_SYMLINKS_REQUIRE_EXPLICIT_PROVENANCE")
    if candidate_code_root_protected is not True:
        blockers.append("CANDIDATE_CODE_ROOT_PROVENANCE_NOT_PROVEN")
    if not isinstance(candidate_mounts,list):
        blockers.append("CANDIDATE_MOUNT_TOPOLOGY_NOT_DECLARED")
    else:
        seen=set()
        for mount in candidate_mounts:
            if (not isinstance(mount,dict) or
                not isinstance(mount.get("Destination"),str) or
                type(mount.get("RW")) is not bool):
                raise TopologyHold("CANDIDATE_MOUNT_RECORD_INVALID")
            dest=mount["Destination"]
            if not dest.startswith("/") or ".." in dest.split("/") or "//" in dest:
                raise TopologyHold("CANDIDATE_MOUNT_DESTINATION_INVALID")
            if dest in seen:
                raise TopologyHold("CANDIDATE_MOUNT_DUPLICATED")
            seen.add(dest)
            if dest==code_root:
                blockers.append("CANDIDATE_IMAGE_CODE_ROOT_SHADOWED_BY_BIND")
            elif dest.startswith(code_root+"/") and mount["RW"] and dest not in allowed:
                blockers.append("CANDIDATE_UNDECLARED_WRITABLE_CODE_MOUNT")
            if dest in allowed:
                if not mount["RW"] or mount.get("Type") not in ("bind","volume"):
                    blockers.append("CANDIDATE_DATA_MOUNT_NOT_DURABLE_WRITABLE_STORAGE")
                for identity_key in ("source_fingerprint_sha256",
                                     "data_migration_receipt_sha256"):
                    value=mount.get(identity_key)
                    if not isinstance(value,str) or re.fullmatch(r"[a-f0-9]{64}",value) is None:
                        blockers.append("CANDIDATE_DATA_VOLUME_IDENTITY_NOT_PINNED")
        # Declaration alone is not proof that externally mutable data
        # has an independently scoped, preserved storage identity.
        if any(path not in seen for path in allowed):
            blockers.append("CANDIDATE_DECLARED_DATA_VOLUME_MISSING")
    if candidate_core_in_target_root is not True:
        blockers.append("CANDIDATE_CORE_NOT_IN_WORDPRESS_RUNTIME_PATH")
    if candidate_wordpress_config_external is not True:
        blockers.append("CANDIDATE_CONFIG_SECRETS_NOT_EXTERNALIZED")
    packages=current["families"].get("plugins",[])
    layers={x["plugin"] for x in runtime_layers
            if isinstance(x,dict) and isinstance(x.get("plugin"),str)}
    missing=sorted(set(packages)-layers)
    if missing:
        blockers.append("FULL_INSTALLED_PLUGIN_CODE_COVERAGE_MISSING")
    if current["families"].get("themes"):
        blockers.append("THEME_CODE_LAYERS_NOT_DECLARED")
    if current["families"].get("mu-plugins"):
        blockers.append("MU_PLUGIN_CODE_LAYERS_NOT_DECLARED")
    if not allowed:
        blockers.append("WRITABLE_DATA_VOLUME_POLICY_NOT_DECLARED")
    # Structural readiness is independent of original 312/313 authorization;
    # even a valid candidate NEVER grants itself permission to activate.
    original_requirements=[
        "ORIGINAL_NATIVE_SIGNED_RUNTIME_ACTIVATION_REQUIRED",
        "ORIGINAL312_EXACT_SCOPE_GRANT_REQUIRED",
        "ORIGINAL313_INDEPENDENT_FINALIZATION_REQUIRED",
        "REAL_HTTP_REQUEST_DRAIN_AND_DATABASE_PROOFS_REQUIRED",
    ]
    return {
        "contract": CONTRACT,
        "state": ("HOLD_INCOMPLETE_WORDPRESS_RUNTIME_TOPOLOGY" if blockers else
                  "CANDIDATE_STRUCTURALLY_READY_AWAITING_ORIGINAL_NATIVE_AUTHORITY"),
        "runtime_plan_sha256": runtime_plan["runtime_plan_sha256"],
        "code_root": code_root,
        "current_code_structure_sha256": current["structure_sha256"],
        "current_installed_plugin_count": len(packages),
        "candidate_plugin_layer_count": len(layers),
        "uncovered_installed_plugin_count": len(missing),
        "current_theme_code_count": len(current["families"].get("themes",[])),
        "current_mu_plugin_entry_count": len(current["families"].get("mu-plugins",[])),
        "unreviewed_code_symlink_count": current.get("unreviewed_code_symlink_count",0),
        "current_code_file_owner_uid": current["code_file_owner_uid"],
        "live_mounts_redacted": mounts,
        "proposed_writable_data_destinations": allowed,
        "legacy_risks": sorted(set(legacy_risks)),
        "blockers": sorted(set(blockers)),
        "original_native_requirements": original_requirements,
        "db_modified": False,
        "site_files_modified": False,
        "host_traffic_modified": False,
    }
