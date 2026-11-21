"""Generic per-site v3 host policy, with independent source/release qualification.

This module accepts an already authenticated, trusted company registry snapshot.
Neither the registry nor this policy is an issuer, enrollment authority, or
permission to install software. Values from user requests are only selectors.
"""
from __future__ import annotations
from dataclasses import dataclass
import re
from typing import Mapping
from persistent_enrollment import (
    DEFAULT_CONNECTION_MODE, MODES, ConnectionDenied,
    need, prepare_new_enrollment, verify_connection, verify_operation,
)

REGISTRY_CONTRACT = "mad4b.host-fleet-trusted-registry.v1"
QUALIFICATION_POLICY_CONTRACT = "mad4b.release-qualification-policy.v1"
QUALIFICATION_MODES = frozenset(("optional", "required"))
SHA256_RE = re.compile(r"[a-f0-9]{64}\Z")
UUID_RE = re.compile(r"[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\Z", re.I)
MANDATORY_BOUNDARIES = (
    "exact_site_identity",
    "independent_signed_host_enrollment",
    "fresh_independent_highwater",
    "operation_scoped_execution_grant",
    "owner_or_operator_authority",
    "rollback_and_verified_preimage",
    "production_separate_authorization",
)

@dataclass(frozen=True)
class SiteBinding:
    company: str
    environment: str
    site_uuid: str
    host_id: str
    ssh_principal: str
    host_key_sha256: str
    policy_sha256: str

    @classmethod
    def from_trusted_registry(cls, registry: dict, company: str,
                              environment: str) -> "SiteBinding":
        need(type(registry) is dict
             and registry.get("contract") == REGISTRY_CONTRACT
             and type(registry.get("sites")) is list,
             "TRUSTED_HOST_REGISTRY_REQUIRED")
        need(type(company) is str and company and type(environment) is str
             and environment in ("staging", "production"),
             "SITE_SELECTOR_INVALID")
        entries = registry["sites"]
        identities = set()
        keys = set()
        selected = []
        for item in entries:
            need(type(item) is dict
                 and set(item) == {
                    "company", "environment", "site_uuid", "host_id",
                    "ssh_principal", "host_key_sha256", "policy_sha256",
                    "enabled"
                 }, "HOST_REGISTRY_ENTRY_SHAPE_INVALID")
            key = (item["company"], item["environment"])
            need(key not in keys and all(type(x) is str and x for x in key),
                 "DUPLICATE_HOST_REGISTRY_SCOPE")
            keys.add(key)
            need(type(item["site_uuid"]) is str
                 and UUID_RE.fullmatch(item["site_uuid"]) is not None
                 and item["site_uuid"] not in identities,
                 "DUPLICATE_OR_INVALID_SITE_UUID")
            identities.add(item["site_uuid"])
            need(all(type(item[f]) is str and item[f] for f in
                     ("host_id", "ssh_principal"))
                 and all(type(item[f]) is str
                         and SHA256_RE.fullmatch(item[f]) is not None for f in
                         ("host_key_sha256", "policy_sha256"))
                 and type(item["enabled"]) is bool,
                 "HOST_REGISTRY_IDENTITY_INVALID")
            if key == (company, environment):
                selected.append(item)
        need(len(selected) == 1 and selected[0]["enabled"] is True,
             "HOST_SITE_NOT_ENROLLED_OR_DISABLED")
        s = selected[0]
        return cls(company=s["company"], environment=s["environment"],
                   site_uuid=s["site_uuid"], host_id=s["host_id"],
                   ssh_principal=s["ssh_principal"],
                   host_key_sha256=s["host_key_sha256"],
                   policy_sha256=s["policy_sha256"])

    def assert_enrollment_matches(self, enrollment: dict) -> None:
        need(type(enrollment) is dict and all(
             enrollment.get(field) == value for field, value in (
                ("company", self.company),
                ("environment", self.environment),
                ("site_uuid", self.site_uuid),
                ("target_host_id", self.host_id),
                ("ssh_principal", self.ssh_principal),
                ("host_key_sha256", self.host_key_sha256),
                ("policy_sha256", self.policy_sha256),
             )), "ENROLLMENT_TRUSTED_SITE_BINDING_MISMATCH")

def resolve_qualification(policy: dict, binding: SiteBinding,
                          global_release_qualification: dict) -> dict:
    """Release qualification is not authorization. Optional is global default.

    Per-company/per-environment overrides allow re-enabling a strict release
    certification, without enabling insecure operations or root writes.
    """
    need(type(policy) is dict and
         policy.get("contract") == QUALIFICATION_POLICY_CONTRACT
         and set(policy) == {"contract", "default_connection_mode",
                            "site_overrides", "mandatory_boundaries"},
         "RELEASE_POLICY_CONTRACT_INVALID")
    need(type(policy.get("mandatory_boundaries")) is list and
         tuple(policy["mandatory_boundaries"]) == MANDATORY_BOUNDARIES,
         "MANDATORY_SECURITY_BOUNDARIES_CHANGED")
    mode = policy["default_connection_mode"]
    need(mode in MODES, "RELEASE_POLICY_DEFAULT_INVALID")
    # Global release qualifier is single-source, shared by every company.
    need(type(global_release_qualification) is dict
         and global_release_qualification.get("contract") ==
         "mad4b.repository-release-qualification-mode.v1"
         and global_release_qualification.get("optional_scope") ==
         "manual_staging_only"
         and global_release_qualification.get("automatic_publication_when_optional") is False
         and global_release_qualification.get("qualification_mode") in QUALIFICATION_MODES,
         "GLOBAL_RELEASE_QUALIFICATION_INVALID")
    qualification = global_release_qualification["qualification_mode"]
    overrides = policy["site_overrides"]
    need(type(overrides) is dict, "RELEASE_POLICY_OVERRIDE_INVALID")
    for key, config in overrides.items():
        need(type(key) is str and re.fullmatch(r"[a-z0-9-]+:(staging|production)", key)
             and type(config) is dict
             and set(config) <= {"connection_mode", "release_qualification"}
             and all((name == "connection_mode" and value in MODES)
                     or (name == "release_qualification"
                         and value in QUALIFICATION_MODES)
                     for name, value in config.items()),
             "RELEASE_POLICY_OVERRIDE_INVALID")
    selected = overrides.get(binding.company + ":" + binding.environment, {})
    mode = selected.get("connection_mode", mode)
    qualification = selected.get("release_qualification", qualification)
    return {
        "contract": "mad4b.host-site-effective-policy.v1",
        "company": binding.company,
        "environment": binding.environment,
        "site_uuid": binding.site_uuid,
        "connection_mode": mode,
        "release_qualification": qualification,
        "release_qualification_optional": qualification == "optional",
        "mandatory_boundaries": list(MANDATORY_BOUNDARIES),
        "host_write_authorized": False,
        "production_authorized": False,
    }

def plan_new_site_enrollment(binding: SiteBinding, effective: dict, *,
                             revision: int, nonce: str,
                             issued_epoch: int) -> dict:
    need(effective.get("site_uuid") == binding.site_uuid
         and effective.get("company") == binding.company
         and effective.get("environment") == binding.environment
         and effective.get("connection_mode") in MODES,
         "EFFECTIVE_POLICY_SITE_DRIFT")
    unsigned = prepare_new_enrollment(
        company=binding.company, environment=binding.environment,
        site_uuid=binding.site_uuid, target_host_id=binding.host_id,
        ssh_principal=binding.ssh_principal,
        host_key_sha256=binding.host_key_sha256,
        policy_sha256=binding.policy_sha256,
        revision=revision, nonce=nonce, issued_epoch=issued_epoch,
        mode=effective["connection_mode"])
    return {
        "contract": "mad4b.host-v3-enrollment-source-plan.v1",
        "site_scope": (binding.company, binding.environment),
        "unsigned_enrollment": unsigned,
        "release_qualification": effective["release_qualification"],
        "approved_owner_signature_required": True,
        "current_signed_revision_preflight_required": True,
        "legacy_v2_auto_migration_allowed": False,
        "issuer_312_signature_required": True,
        "independent_313_signature_required": True,
        "original318_write_authorized": False,
        "executed": False,
    }

def read_site_connection(binding: SiteBinding, enrollment: dict,
                         checkpoint: dict, wp: dict, *args, **kwargs):
    binding.assert_enrollment_matches(enrollment)
    return verify_connection(enrollment, checkpoint, wp, *args, **kwargs)

def read_site_operation(binding: SiteBinding, enrollment: dict,
                        checkpoint: dict, wp: dict, *args, **kwargs):
    binding.assert_enrollment_matches(enrollment)
    return verify_operation(enrollment, checkpoint, wp, *args, **kwargs)
