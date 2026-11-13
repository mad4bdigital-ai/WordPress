"""Source-only immutable Runtime plan layered on existing Native Source Publisher v2.

Builds candidate metadata only. Neither this module nor its CLI can switch
traffic, issue an Original312 grant, enroll a provider or finalize Original313.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import re

CONTRACT = "mad4b.immutable-runtime.candidate.v1"
PACKAGE_CONTRACT = "mad4b.original-publisher.plugin-source-package.v1"
SITE_UUID = re.compile(r"^[a-f0-9-]{36}$")
HEX = re.compile(r"^[a-f0-9]{64}$")
SHA1 = re.compile(r"^[a-f0-9]{40}$")
SLUG = re.compile(r"^[a-z][a-z0-9-]{2,90}$")
MAX_LAYERS = 32


class RuntimeHold(ValueError):
    pass


def require(condition: bool, code: str):
    if not condition:
        raise RuntimeHold(code)


def canonical(obj: dict) -> bytes:
    return json.dumps(obj, ensure_ascii=False, sort_keys=True,
                      separators=(",", ":")).encode()


def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def verify_artifact(doc: dict) -> dict:
    require(isinstance(doc, dict) and doc.get("contract") == PACKAGE_CONTRACT,
            "IMMUTABLE_SOURCE_ARTIFACT_INVALID")
    require(doc.get("state") == "SOURCE_ARTIFACT_REVIEW_ONLY_NOT_DEPLOYABLE",
            "IMMUTABLE_SOURCE_ARTIFACT_STATE_INVALID")
    require(isinstance(doc.get("immutable_source_commit"), str) and
            SHA1.fullmatch(doc["immutable_source_commit"]) is not None,
            "IMMUTABLE_SOURCE_COMMIT_INVALID")
    require(isinstance(doc.get("plugin"), str) and
            SLUG.fullmatch(doc["plugin"]) is not None,
            "IMMUTABLE_PLUGIN_ID_INVALID")
    require(isinstance(doc.get("artifact_sha256"), str) and
            HEX.fullmatch(doc["artifact_sha256"]) is not None and
            isinstance(doc.get("artifact_bytes"), int) and
            0 < doc["artifact_bytes"] <= 100_000_000,
            "IMMUTABLE_ARTIFACT_DIGEST_INVALID")
    body = {k: v for k, v in doc.items() if k not in (
        "manifest_sha256", "artifact_sha256", "artifact_bytes")}
    require(isinstance(doc.get("manifest_sha256"), str) and
            sha(canonical(body)) == doc["manifest_sha256"],
            "IMMUTABLE_ARTIFACT_MANIFEST_TAMPERED")
    rows = doc.get("source_runtime_files")
    require(isinstance(rows, list) and 1 <= len(rows) <= 1024,
            "IMMUTABLE_RUNTIME_LAYER_EMPTY")
    seen = set()
    for row in rows:
        require(isinstance(row, dict) and
                set(row) == {"path", "size", "sha256", "source_git_blob"} and
                isinstance(row["path"], str) and
                not row["path"].startswith("/") and
                ".." not in Path(row["path"]).parts and
                isinstance(row["size"], int) and 0 <= row["size"] <= 4_000_000 and
                isinstance(row["sha256"], str) and HEX.fullmatch(row["sha256"])
                and row["path"] not in seen,
                "IMMUTABLE_LAYER_FILE_INVALID")
        seen.add(row["path"])
    require(doc.get("production_writes") is False and
            doc.get("source_publisher_enrolled_by_this_artifact") is False and
            doc.get("original313_certified") is False and
            doc.get("coherent_activation_proven") is False,
            "IMMUTABLE_ARTIFACT_MUST_NOT_SELF_AUTHORIZE")
    return {"plugin": doc["plugin"],
            "immutable_source_commit": doc["immutable_source_commit"],
            "manifest_sha256": doc["manifest_sha256"],
            "artifact_sha256": doc["artifact_sha256"],
            "runtime_file_count": len(rows)}


def runtime_plan(*, company: str, environment: str, site_uuid: str,
                 origin: str, image_base_id: str,
                 plugin_manifests: list[dict]) -> dict:
    """Derive a policy-neutral, content-pinned runtime candidate."""
    require(isinstance(company, str) and
            re.fullmatch(r"[a-z][a-z0-9-]{1,63}", company) is not None and
            isinstance(environment, str) and
            re.fullmatch(r"[a-z][a-z0-9-]{1,63}", environment) is not None and
            isinstance(site_uuid, str) and
            SITE_UUID.fullmatch(site_uuid) is not None and
            isinstance(origin, str) and
            re.fullmatch(r"https://[A-Za-z0-9.-]+", origin) is not None,
            "IMMUTABLE_RUNTIME_SITE_SCOPE_INVALID")
    require(isinstance(image_base_id, str) and
            re.fullmatch(r"sha256:[a-f0-9]{64}", image_base_id) is not None,
            "IMMUTABLE_RUNTIME_BASE_IMAGE_UNPINNED")
    require(isinstance(plugin_manifests, list) and
            1 <= len(plugin_manifests) <= MAX_LAYERS,
            "IMMUTABLE_RUNTIME_LAYER_COUNT_INVALID")
    layers = [verify_artifact(row) for row in plugin_manifests]
    require(len({item["plugin"] for item in layers}) == len(layers),
            "IMMUTABLE_RUNTIME_DUPLICATE_PLUGIN_LAYER")
    result = {
        "contract": CONTRACT,
        "site": {"company": company, "environment": environment,
                 "site_uuid": site_uuid, "origin": origin},
        "base_image_id": image_base_id,
        "layers": sorted(layers, key=lambda x: x["plugin"]),
        "original_execution_operation": "DISCOVER_FROM_ENROLLED_SOURCE_PUBLISHER",
        "source_publisher_authorized": False,
        "original312_signed": False,
        "original313_finalized": False,
        "cutover_verified": False,
        "rollback_verified": False,
        "state": "BUILD_CANDIDATE_NOT_AUTHORIZED_FOR_TRAFFIC",
    }
    result["runtime_plan_sha256"] = sha(canonical(result))
    return result


def verify_plan(doc: dict) -> None:
    require(isinstance(doc, dict) and doc.get("contract") == CONTRACT,
            "IMMUTABLE_RUNTIME_PLAN_INVALID")
    payload = {k: v for k, v in doc.items() if k != "runtime_plan_sha256"}
    require(doc.get("runtime_plan_sha256") == sha(canonical(payload)),
            "IMMUTABLE_RUNTIME_PLAN_TAMPERED")
    require(doc.get("source_publisher_authorized") is False and
            doc.get("original312_signed") is False and
            doc.get("original313_finalized") is False and
            doc.get("cutover_verified") is False,
            "IMMUTABLE_RUNTIME_PRETEND_AUTHORIZATION")


def select_plugin_layer(doc: dict, plugin: str) -> dict:
    verify_plan(doc)
    selected = [row for row in doc["layers"] if row["plugin"] == plugin]
    require(len(selected) == 1, "IMMUTABLE_PLUGIN_LAYER_NOT_IN_PLAN")
    return selected[0]
