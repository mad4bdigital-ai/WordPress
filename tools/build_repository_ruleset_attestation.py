#!/usr/bin/env python3
"""Build a durable attestation for a privileged, exact ruleset readback."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "mad4b_ruleset_template_verifier",
    HERE / "verify_repository_ruleset_template.py",
)
module = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(module)

CONTRACT = "mad4b.repository-ruleset-attestation.v1"


def load(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"expected JSON object: {path}")
    return data


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build(readback: dict, policy: dict, template: dict, repository: str, policy_path: Path, template_path: Path) -> dict:
    repository = str(repository or "").strip()
    if "/" not in repository:
        raise ValueError("repository must use owner/name form")

    verified = module.verify(template, policy, readback)
    if verified.get("ready") is not True or verified.get("readback_verified") is not True:
        raise ValueError("canonical ruleset readback is not verified")

    attestation_policy = policy.get("ruleset_attestation") or {}
    if attestation_policy.get("contract") != CONTRACT:
        raise ValueError("ruleset attestation policy contract mismatch")
    if attestation_policy.get("require_zero_bypass_actors") is not True:
        raise ValueError("ruleset attestation policy must require zero bypass actors")
    if attestation_policy.get("bind_ruleset_updated_at") is not True:
        raise ValueError("ruleset attestation policy must bind ruleset updated_at")
    if attestation_policy.get("bind_policy_sha256") is not True:
        raise ValueError("ruleset attestation policy must bind policy SHA-256")
    if attestation_policy.get("bind_template_sha256") is not True:
        raise ValueError("ruleset attestation policy must bind template SHA-256")

    if str(readback.get("source_type") or "") != "Repository":
        raise ValueError("ruleset attestation requires a repository-owned ruleset")
    if str(readback.get("source") or "") != repository:
        raise ValueError("ruleset attestation source mismatch")
    if "bypass_actors" not in readback:
        raise ValueError("privileged ruleset readback does not expose bypass actors")
    bypass = readback.get("bypass_actors") or []
    if not isinstance(bypass, list) or bypass:
        raise ValueError("ruleset attestation requires zero bypass actors")

    ruleset_id = int(readback.get("id") or 0)
    updated_at = str(readback.get("updated_at") or "")
    if ruleset_id < 1 or not updated_at:
        raise ValueError("ruleset attestation requires id and updated_at")

    return {
        "contract": CONTRACT,
        "repository": repository,
        "ruleset_id": ruleset_id,
        "ruleset_name": str(readback.get("name") or ""),
        "ruleset_source_type": "Repository",
        "ruleset_source": repository,
        "ruleset_updated_at": updated_at,
        "bypass_actor_count": 0,
        "policy_sha256": sha256(policy_path),
        "template_sha256": sha256(template_path),
        "required_status_checks": verified.get("required_status_checks", []),
        "rule_types": verified.get("rule_types", []),
        "verified_readback": True,
        "mutation_performed": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--readback", required=True, type=Path)
    parser.add_argument("--policy", required=True, type=Path)
    parser.add_argument("--template", required=True, type=Path)
    parser.add_argument("--repository", required=True)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        result = build(
            load(args.readback),
            load(args.policy),
            load(args.template),
            args.repository,
            args.policy,
            args.template,
        )
    except (ValueError, OSError, json.JSONDecodeError) as exc:
        print(f"RULESET_ATTESTATION: FAIL: {exc}")
        return 1
    encoded = json.dumps(result, indent=2, sort_keys=True) + "\n"
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(encoded, encoding="utf-8")
    print("RULESET_ATTESTATION: PASS")
    print(encoded, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
