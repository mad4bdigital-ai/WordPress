#!/usr/bin/env python3
import hashlib
import importlib.util
import json
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
TOOL_PATH = ROOT / "tools/verify_release_root_trust.py"
WORKFLOW_PATH = ROOT / ".github/workflows/mad4b-control-plane-package.yml"

spec = importlib.util.spec_from_file_location("root_trust_verifier", TOOL_PATH)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)

signer_digest = "a" * 40
command = module.build_attestation_command(
    Path("candidate.zip"),
    Path("attestation.json"),
    signer_digest,
)
required_args = {
    "--repo": module.REPOSITORY,
    "--signer-workflow": module.SIGNER_WORKFLOW,
    "--signer-digest": signer_digest,
}
for flag, value in required_args.items():
    pos = command.index(flag)
    if command[pos + 1] != value:
        raise SystemExit(f"wrong verifier binding for {flag}")
if "--deny-self-hosted-runners" not in command:
    raise SystemExit("root-trust verifier must reject self-hosted signer runners")
if "--bundle" not in command:
    raise SystemExit("root-trust verifier must support bundled offline attestation evidence")

workflow = WORKFLOW_PATH.read_text(encoding="utf-8")
for fragment in (
    "id-token: write",
    "attestations: write",
    "artifact-metadata: write",
    "uses: actions/attest@v4",
    "id: release_attest",
    "RELEASE-ATTESTATION.sigstore.json",
    "RELEASE-ATTESTATION-LOCATOR.json",
    "'runtime_self_attestation_authoritative': False",
    "'verification_boundary': 'external_release_verifier'",
):
    if fragment not in workflow:
        raise SystemExit(f"root-trust packaging workflow missing: {fragment}")
if workflow.index("uses: actions/attest@v4") > workflow.index("Upload reviewed General Distribution installation kit"):
    raise SystemExit("release attestation must be generated before artifact upload")

with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    source_sha = "b" * 40
    artifact = tmp / "mad4b-site-control-plane-0.4.0-rc.59.zip"
    install = tmp / "install-manifest.json"

    relative = "mad4b-site-control-plane.php"
    raw = b"<?php\n// root-trust fixture\n"
    raw_sha = hashlib.sha256(raw).hexdigest()
    package_digest = hashlib.sha256(
        f"{relative}\0{len(raw)}\0{raw_sha}\n".encode()
    ).hexdigest()
    build_fingerprint = hashlib.sha256(b"fixture-build").hexdigest()

    provenance = {
        "contract": module.PROVENANCE_CONTRACT,
        "source_commit_sha": source_sha,
        "build_fingerprint": build_fingerprint,
        "package_manifest_digest": package_digest,
        "package_files": [
            {"path": relative, "bytes": len(raw), "sha256": raw_sha}
        ],
    }

    with zipfile.ZipFile(artifact, "w", zipfile.ZIP_DEFLATED) as archive:
        archive.writestr(f"mad4b-site-control-plane/{relative}", raw)
        archive.writestr(
            "mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json",
            json.dumps(provenance, sort_keys=True),
        )

    install_data = {
        "contract": module.INSTALL_CONTRACT,
        "repository": module.REPOSITORY,
        "commit": source_sha,
        "build_fingerprint": build_fingerprint,
        "package_manifest_digest": package_digest,
        "control_plane": {
            "version": "0.4.0-rc.59",
            "archive": artifact.name,
            "sha256": module.sha256_file(artifact),
        },
    }
    install.write_text(json.dumps(install_data), encoding="utf-8")

    receipt = module.verify_local_identity(artifact, install, source_sha)
    if receipt["artifact_sha256"] != install_data["control_plane"]["sha256"]:
        raise SystemExit("local artifact identity verification did not bind archive digest")
    if receipt["package_manifest_digest"] != package_digest:
        raise SystemExit("local artifact identity verification did not bind package digest")

print("release root-trust contract: PASS")
