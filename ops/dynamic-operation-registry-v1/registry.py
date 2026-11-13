#!/usr/bin/env python3
"""Root-enrolled, content-pinned MAD4B operation registry (source candidate).

The index is a *first-party installed* trust store, not a public operation
catalog. Manifests are declarative: no arbitrary shell, imports or callbacks.
Loading a manifest never issues a Job, Grant, Claim or central 313 receipt.
"""
from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import stat
from typing import Any

CONTRACT = "mad4b.dynamic-operation-registry.v1"
INDEX_CONTRACT = "mad4b.dynamic-operation-index.v1"
MANIFEST_CONTRACT = "mad4b.dynamic-operation-provider.v1"
OP = re.compile(r"^[a-z][a-z0-9_.-]{2,79}$")
SHA = re.compile(r"^[0-9a-f]{64}$")
WORD = re.compile(r"^[a-zA-Z0-9_.:-]{1,96}$")
CLI_WORD = re.compile(r"^[a-zA-Z0-9_./:-]{1,96}$")
REQUIRED = ("operation_id", "provider", "version", "plan_contract",
            "handler", "verifier", "evidence_schema", "evidence", "command")


class RegistryDenied(ValueError):
    pass


def require(valid: bool, reason: str) -> None:
    if not valid:
        raise RegistryDenied(reason)


def canonical(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, ensure_ascii=False,
                      separators=(",", ":")).encode("utf-8")


def sha(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def bounded_relative(value: str) -> str:
    require(isinstance(value, str) and 0 < len(value) <= 220,
            "INVALID_REGISTRY_PATH")
    p = PurePosixPath(value)
    require(not p.is_absolute() and ".." not in p.parts and
            "." not in p.parts and "\\" not in value and "//" not in value
            and p.as_posix() == value, "REGISTRY_PATH_TRAVERSAL")
    return value


def protected_file(path: Path, root: Path, *, maximum: int = 250_000) -> bytes:
    # Don't resolve a user-controlled path across symlinks.
    require(path.is_relative_to(root), "REGISTRY_FILE_OUTSIDE_ROOT")
    for part in [path, *path.parents]:
        if part == root.parent:
            break
        require(not part.is_symlink(), "REGISTRY_SYMLINK_DENIED")
        owner = part.stat()
        require(owner.st_uid == 0 and not owner.st_mode & 0o022,
                "REGISTRY_ANCESTOR_NOT_TRUSTED")
    st = path.stat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and
            not st.st_mode & 0o022 and 0 < st.st_size <= maximum,
            "REGISTRY_FILE_NOT_TRUSTED")
    return path.read_bytes()


def checked_json(path: Path, root: Path) -> dict:
    obj = json.loads(protected_file(path, root))
    require(isinstance(obj, dict), "REGISTRY_JSON_NOT_OBJECT")
    return obj


class Registry:
    def __init__(self, root: Path):
        self.root = Path(root)
        require(self.root.is_absolute() and not self.root.is_symlink(),
                "REGISTRY_ROOT_NOT_PINNED")
        st = self.root.stat()
        require(st.st_uid == 0 and stat.S_ISDIR(st.st_mode) and
                not st.st_mode & 0o022, "REGISTRY_ROOT_NOT_TRUSTED")
        index = checked_json(self.root / "index.json", self.root)
        require(index.get("contract") == INDEX_CONTRACT and
                index.get("installed_by_first_party") is True and
                isinstance(index.get("providers"), list) and
                len(index["providers"]) <= 128,
                "REGISTRY_NOT_FIRST_PARTY_ENROLLED")
        try:
            self.independent_verifier_public_key = base64.b64decode(
                index["independent_verifier_public_key_b64"], validate=True
            )
        except (KeyError, TypeError, ValueError) as exc:
            raise RegistryDenied("INDEPENDENT_SIGNING_KEY_NOT_ENROLLED") from exc
        require(len(self.independent_verifier_public_key) == 32,
                "INDEPENDENT_SIGNING_KEY_INVALID")
        self.manifests: dict[str, dict] = {}
        for row in index["providers"]:
            require(isinstance(row, dict), "REGISTRY_ENTRY_INVALID")
            rel = bounded_relative(row.get("manifest"))
            pinned = row.get("sha256")
            require(isinstance(pinned, str) and SHA.fullmatch(pinned),
                    "REGISTRY_MANIFEST_HASH_MISSING")
            raw = protected_file(self.root / rel, self.root)
            require(sha(raw) == pinned, "REGISTRY_MANIFEST_DRIFT")
            m = json.loads(raw)
            self.validate_manifest(m)
            op = m["operation_id"]
            require(op not in self.manifests,
                    "REGISTRY_DUPLICATE_OPERATION")
            self.manifests[op] = m

    def validate_manifest(self, m: dict) -> None:
        require(isinstance(m, dict) and
                m.get("contract") == MANIFEST_CONTRACT and
                all(k in m for k in REQUIRED), "PROVIDER_CONTRACT_INVALID")
        for key in ("operation_id", "provider", "evidence_schema"):
            require(isinstance(m[key], str) and OP.fullmatch(m[key]),
                    "PROVIDER_IDENTIFIER_INVALID")
        require(isinstance(m["version"], int) and 0 < m["version"] < 10_000,
                "PROVIDER_VERSION_INVALID")
        require(isinstance(m["plan_contract"], str) and
                OP.fullmatch(m["plan_contract"]),
                "PROVIDER_PLAN_CONTRACT_INVALID")
        for entry in ("handler", "verifier"):
            component = m[entry]
            require(isinstance(component, dict) and
                    isinstance(component.get("path"), str) and
                    isinstance(component.get("sha256"), str) and
                    SHA.fullmatch(component["sha256"]),
                    "PROVIDER_COMPONENT_INVALID")
            rel = bounded_relative(component["path"])
            require(rel.endswith(".py"), "PROVIDER_PYTHON_ONLY")
            blob = protected_file(self.root / rel, self.root)
            require(sha(blob) == component["sha256"],
                    "PROVIDER_COMPONENT_DRIFT:" + entry)
        # A handler cannot be made immutable while its imported Python
        # dependencies are mutable. Independently content-pin every declared
        # first-party dependency before the Provider is considered enrolled.
        deps=m.get("dependencies",[])
        require(isinstance(deps,list) and len(deps)<=32,
                "PROVIDER_DEPENDENCY_LIST_INVALID")
        paths=set()
        for component in deps:
            require(isinstance(component,dict) and
                    set(component)=={"path","sha256"} and
                    isinstance(component["path"],str) and
                    isinstance(component["sha256"],str) and
                    SHA.fullmatch(component["sha256"]),
                    "PROVIDER_DEPENDENCY_SHAPE_INVALID")
            rel=bounded_relative(component["path"])
            require(rel.endswith(".py") and rel not in paths and
                    rel not in (m["handler"]["path"],m["verifier"]["path"]),
                    "PROVIDER_DEPENDENCY_PATH_INVALID")
            paths.add(rel)
            blob=protected_file(self.root/rel,self.root,maximum=2_000_000)
            require(sha(blob)==component["sha256"],
                    "PROVIDER_DEPENDENCY_DRIFT:"+rel)
        proof = m["evidence"]
        require(isinstance(proof, dict) and
                isinstance(proof.get("path"), str) and
                isinstance(proof.get("sha256"), str) and
                SHA.fullmatch(proof["sha256"]), "PROVIDER_EVIDENCE_INVALID")
        evidence_rel = bounded_relative(proof["path"])
        require(evidence_rel.endswith(".json"), "EVIDENCE_SCHEMA_PATH_INVALID")
        raw_evidence = protected_file(self.root / evidence_rel, self.root)
        require(sha(raw_evidence) == proof["sha256"],
                "EVIDENCE_SCHEMA_DIGEST_DRIFT")
        schema = json.loads(raw_evidence)
        require(isinstance(schema, dict) and
                schema.get("contract") == "mad4b.dynamic-evidence-schema.v1" and
                schema.get("schema_id") == m["evidence_schema"] and
                isinstance(schema.get("fields"), dict) and
                isinstance(schema.get("success_state"), str) and
                WORD.fullmatch(schema["success_state"]) and
                schema["fields"].get("result_status") == "string" and
                1 <= len(schema["fields"]) <= 40 and
                all(isinstance(name, str) and WORD.fullmatch(name) and
                    typ in ("string", "integer", "boolean", "sha256")
                    for name, typ in schema["fields"].items()),
                "EVIDENCE_SCHEMA_INVALID")
        command = m["command"]
        require(isinstance(command, dict) and
                command.get("format") == "wpcli.argv.json.v1" and
                command.get("mode") == "plan_sha256" and
                command.get("bound_operation_id") == m["operation_id"] and
                isinstance(command.get("prefix"), list) and
                2 <= len(command["prefix"]) <= 12 and
                all(isinstance(x, str) and CLI_WORD.fullmatch(x)
                    for x in command["prefix"]) and
                isinstance(command.get("flag"), str) and
                re.fullmatch(r"--[a-z][a-z0-9_-]{1,48}=", command["flag"]),
                "PROVIDER_COMMAND_INVALID")

    def evidence_definition(self, op: str) -> dict:
        m = self.resolve(op)
        path = self.root / m["evidence"]["path"]
        raw = protected_file(path, self.root)
        require(sha(raw) == m["evidence"]["sha256"],
                "EVIDENCE_SCHEMA_DIGEST_DRIFT")
        return json.loads(raw)

    def evidence_fields(self, op: str) -> dict[str, str]:
        return self.evidence_definition(op)["fields"]

    def command_argv(self, op: str, plan_sha256: str) -> list[str]:
        manifest = self.resolve(op)
        require(isinstance(plan_sha256, str) and SHA.fullmatch(plan_sha256),
                "INVALID_JOB_PLAN_HASH")
        cmd = manifest["command"]
        return [*cmd["prefix"], cmd["flag"] + plan_sha256]

    def command_sha256(self, op: str, plan_sha256: str) -> str:
        return sha(json.dumps(self.command_argv(op, plan_sha256),
                              separators=(",", ":"),
                              ensure_ascii=False).encode())

    def resolve(self, op: str) -> dict:
        require(isinstance(op, str) and op in self.manifests,
                "PROVIDER_NOT_ENROLLED")
        return self.manifests[op]

    def summary(self) -> dict:
        return {"contract": CONTRACT, "enrolled_operations":
                sorted(self.manifests), "grant_issued": False,
                "job_issued": False, "execution_performed": False,
                "central_313_receipt_issued": False}
