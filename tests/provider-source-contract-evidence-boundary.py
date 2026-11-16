#!/usr/bin/env python3
"""Exact source-backed contracts for provider catalog and generated evidence.

No provider install, network fetch, WordPress mutation, or certification.
Rules distinguish read from write and never convert inventory into authority.
"""
import copy
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "wp-content/plugins/mad4b-site-control-plane/config"
HEX64 = re.compile(r"^[a-f0-9]{64}$")
ALLOWED_RISKS = {"read", "bounded_write", "high_risk_write"}
WRITE_GUARDS = {"mad4b_guarded", "mad4b_expected_document_sha"}


def load(name):
    return json.loads((CONFIG / name).read_text(encoding="utf-8"))


def must(condition, why):
    if not condition:
        raise AssertionError(why)


def provider_catalog(d):
    must(d.get("contract") == "mad4b.provider-capability-contracts.v1",
         "Provider capability contract changed")
    providers = d.get("providers")
    must(isinstance(providers, dict) and len(providers) >= 10,
         "Source provider catalog unexpectedly contracted")
    count = 0
    for name, row in providers.items():
        must(isinstance(row.get("adapter_id"), str) and row["adapter_id"]
             and isinstance(row.get("capabilities"), dict)
             and row["capabilities"], f"Provider missing adapter/capabilities: {name}")
        for capability, cfg in row["capabilities"].items():
            risk = cfg.get("risk")
            must(risk in ALLOWED_RISKS, f"Unknown capability risk {name}:{capability}")
            probes = cfg.get("probes")
            must(isinstance(probes, list) and probes
                 and probes[0].get("type") == "adapter_available",
                 f"Unbound capability discovery probe {name}:{capability}")
            abilities = cfg.get("abilities")
            must(isinstance(abilities, list) and
                 all(isinstance(a, str) and a and "/" in a for a in abilities),
                 f"Invalid mapped native ability names {name}:{capability}")
            traits = cfg.get("traits")
            must(isinstance(traits, dict) and
                 traits.get("traits_scope") == "mad4b_adapter_semantics",
                 f"Missing capability-owned execution traits {name}:{capability}")
            if risk == "read":
                must(traits.get("idempotency_model") == "read_repeatable"
                     and traits.get("compensation_support") is False,
                     f"Read type cannot report write safety: {name}:{capability}")
                must(not any(s in capability for s in ("bounded-write", "high-risk-write")),
                     f"Write capability relabeled read: {name}:{capability}")
            else:
                must(traits.get("idempotency_model") in WRITE_GUARDS,
                     f"Mutation without guarded idempotency: {name}:{capability}")
                must(bool(abilities),f"Mutation without registered exact abilities: {name}:{capability}")
                must(not capability.endswith(".read"),
                     f"Read capability relabeled write: {name}:{capability}")
            count += 1
    return count


def certified_profiles(d):
    must(d.get("contract") ==
         "mad4b.site-control-plane.certified-provider-profiles.v1",
         "Certified provider source contract changed")
    profiles = d.get("providers")
    must(isinstance(profiles, dict) and {"mcp_adapter", "jetengine",
         "jetsmartfilters", "elementor"} <= set(profiles),
         "Certified provider inventory not site-neutral")
    # Certification may grow dynamically only when the independently sourced
    # exact archive has a completed semantic review, not from runtime inventory.
    attest = load("premium-provider-attestations.json")
    must(attest.get("contract") ==
         "mad4b.site-control-plane.premium-provider-attestation.v1"
         and attest.get("policy", {}).get("semantic_review_required") is True
         and attest.get("policy", {}).get("independent_from_runtime_required") is True,
         "Premium provider certification lost independent review policy")
    for slug in ("jetengine", "jetsmartfilters"):
        for version, profile in profiles[slug].items():
            entry = attest.get("attestations", {}).get(slug, {}).get(version)
            must(isinstance(entry, dict)
                 and entry.get("version") == version
                 and entry.get("independent_from_runtime") is True
                 and entry.get("semantic_review", {}).get("status") == "approved"
                 and entry.get("semantic_review", {}).get("approved") is True
                 and entry.get("archive_sha256") == profile.get("archive_sha256")
                 and entry.get("archive_bytes") == profile.get("archive_bytes")
                 and entry.get("critical_files") == profile.get("critical_files")
                 and profile.get("attestation_id") == entry.get("attestation_id")
                 and profile.get("certification_authority") ==
                     "premium-attestation:" + str(entry.get("attestation_id")),
                 f"Premium provider has no exact approved independent attestation: {slug}@{version}")
    rows = 0
    for slug, versions in profiles.items():
        must(isinstance(versions, dict),"Invalid versions map")
        for version, row in versions.items():
            must(row.get("version") == version, "Version certification mismatch")
            must(HEX64.fullmatch(str(row.get("archive_sha256", ""))) is not None
                 and type(row.get("archive_bytes")) is int
                 and row["archive_bytes"] > 0,
                 f"Unpinned archive identity: {slug}@{version}")
            must(isinstance(row.get("certification_authority"), str)
                 and row["certification_authority"],
                 f"Unattributed certificate: {slug}@{version}")
            must(isinstance(row.get("verified_contracts"), list)
                 and row["verified_contracts"],"No exact contract evidence")
            must(row.get("production_authorized") is not True
                 and row.get("auto_certification") is not True,
                 "Inventory cannot authorize Production or self-certify")
            rows += 1
    return rows


def generated_evidence(d):
    must(d.get("contract") == "mad4b.functional-gap-contract-evidence.v1",
         "Functional gap evidence contract changed")
    raw = (CONFIG / "functional-gap-policy.json").read_bytes()
    policy = json.loads(raw)
    must(policy["contract"] == d.get("policy_contract")
         and HEX64.fullmatch(d.get("policy_sha256", "")) is not None
         and hashlib.sha256(raw).hexdigest() == d["policy_sha256"],
         "Generated evidence detached from exact reviewed policy SHA")
    must(policy.get("default_mutation") == "deny"
         and policy.get("promotion_authorized") is False,
         "Functional gap policy must remain nonauthorizing")
    families = d.get("families")
    repository_backed = {name for name, row in policy.get("families", {}).items()
                         if row.get("repository_evidence") is True}
    # Runtime-only integrations intentionally have no archive evidence. Never
    # interpret their absence as a failed package scan or grant.
    must(isinstance(families, dict) and
         set(families) == repository_backed,
         "Repository-backed functional gap family evidence mismatch")
    sha = d.get("source_commit_sha")
    must(isinstance(sha, str) and re.fullmatch("[a-f0-9]{40}", sha) is not None,
         "Generated source revision must be explicit but is not an approval")
    artifacts = 0
    for family, row in families.items():
        must(row.get("evidence_only") is True
             and row.get("promotion_authorized") is False
             and row.get("evaluation_mode") == policy["families"][family].get("evaluation_mode"),
             f"Functional gap evidence claimed authority for {family}")
        assets = row.get("artifacts")
        must(isinstance(assets, list) and assets,
             f"Missing collected evidence for {family}")
        for a in assets:
            must(HEX64.fullmatch(str(a.get("archive_sha256", ""))) is not None
                 and type(a.get("archive_bytes")) is int and a["archive_bytes"] > 0,
                 f"Unpinned evidence archive {family}")
            tree = a.get("package_tree")
            must(isinstance(tree, dict)
                 and HEX64.fullmatch(str(tree.get("tree_sha256", ""))) is not None,
                 f"Unpinned internal artifact tree {family}")
            artifacts += 1
    return artifacts


def must_fail(fn, mutant, label):
    try:
        fn(mutant)
    except AssertionError:
        return 1
    raise AssertionError("Unsafe mutation not detected: " + label)


def main():
    caps = load("provider-capability-contracts.json")
    cert = load("certified-provider-profiles.json")
    evidence = load("functional-gap-contract-evidence.generated.json")
    n_caps = provider_catalog(caps)
    n_cert = certified_profiles(cert)
    n_artifacts = generated_evidence(evidence)
    mutations = 0
    m=copy.deepcopy(caps)
    m["providers"]["jetengine"]["capabilities"]["post_meta.bounded-write"]["risk"]="read"
    mutations+=must_fail(provider_catalog,m,"relabel write as read")
    m=copy.deepcopy(caps)
    m["providers"]["jetengine"]["capabilities"]["post_meta.bounded-write"]["traits"]["idempotency_model"]="read_repeatable"
    mutations+=must_fail(provider_catalog,m,"mutation losing guarded idempotency")
    m=copy.deepcopy(caps)
    m["providers"]["jetengine"]["capabilities"]["post_meta.bounded-write"]["probes"]=[]
    mutations+=must_fail(provider_catalog,m,"unbounded provider discovery")
    m=copy.deepcopy(cert)
    m["providers"]["jetengine"]["3.8.15"]={"version":"3.8.15","archive_sha256":"bad"}
    mutations+=must_fail(certified_profiles,m,"invented premium certificate")
    m=copy.deepcopy(cert)
    m["providers"]["mcp_adapter"]["0.7.1"]["archive_sha256"]="not_certified"
    mutations+=must_fail(certified_profiles,m,"unsafe MCP archive fingerprint")
    m=copy.deepcopy(cert)
    m["providers"]["mcp_adapter"]["0.7.1"]["version"]="successor"
    mutations+=must_fail(certified_profiles,m,"cross-version certificate")
    m=copy.deepcopy(evidence)
    m["families"]["jetengine"]["promotion_authorized"]=True
    mutations+=must_fail(generated_evidence,m,"generated evidence approving Premium")
    m=copy.deepcopy(evidence)
    m["policy_sha256"]="0"*64
    mutations+=must_fail(generated_evidence,m,"wrong policy source SHA")
    m=copy.deepcopy(evidence)
    m["families"]["jetengine"]["artifacts"][0]["package_tree"]["tree_sha256"]="0"
    mutations+=must_fail(generated_evidence,m,"unverified archive tree")
    m=copy.deepcopy(evidence)
    m["families"].pop("jetengine")
    mutations+=must_fail(generated_evidence,m,"lost family evidence")
    must(n_caps>=35 and n_cert>=3 and n_artifacts>=9 and mutations==10,
         "Unexpected coverage footprint or unexecuted negative cases")
    print(f"PROVIDER_SOURCE_CONTRACT_EVIDENCE_BOUNDARY: PASS {n_caps} capabilities, "
          f"{n_cert} certified versions, {n_artifacts} evidence archives, {mutations} negative mutations")


if __name__ == "__main__":
    main()
