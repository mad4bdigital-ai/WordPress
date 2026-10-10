#!/usr/bin/env python3
"""Host Runner uses existing Ed25519 key: no second source and no DB secret."""
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

root = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location("mad4b_host_runner", root / "tools" / "mad4b_host_runner.py")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def denied(profile, challenge, reason):
    try:
        runner.sign_live_host_identity_challenge(profile, challenge, now=1791630000)
    except ValueError:
        return
    raise AssertionError("Expected rejection: " + reason)


with tempfile.TemporaryDirectory(prefix="mad4b-host-identity-") as temp:
    parent = Path(temp)
    wp_root = parent / "wordpress"
    wp_root.mkdir()
    key_file = parent / "host-private-identity.pem"
    private = Ed25519PrivateKey.generate()
    key_file.write_bytes(private.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.PKCS8,
        encryption_algorithm=serialization.NoEncryption(),
    ))
    os.chmod(key_file, 0o600)
    public = private.public_key().public_bytes(
        encoding=serialization.Encoding.Raw, format=serialization.PublicFormat.Raw,
    )
    site_uuid = "49c562d1-8f2f-456f-b454-26816c6ba4cb"
    profile = {
        "profile_id": "staging-runner-01",
        "site_uuid": site_uuid,
        "environment": "staging",
        "wordpress_root": str(wp_root),
        "target_fingerprint": "b" * 64,
        "host_environment_receipt_signing_key_file": str(key_file),
        "host_environment_receipt_signing_public_key_b64": base64.b64encode(public).decode(),
    }
    challenge = {
        "contract": "mad4b.host-identity-challenge.v1",
        "nonce": "1" * 64,
        "site_uuid": site_uuid,
        "origin": "https://staging.allroyalegypt.com",
        "environment": "staging",
        "profile_revision": 3,
        "profile_digest": "a" * 64,
    }
    proof = runner.sign_live_host_identity_challenge(profile, challenge, now=1791630000)
    assert proof["contract"] == "mad4b.host-identity-live.v1"
    assert proof["algorithm"] == "Ed25519"
    assert not any("private" in field or "secret" in field for field in proof)
    payload = proof["payload"]
    assert payload["nonce_sha256"] == hashlib.sha256(challenge["nonce"].encode()).hexdigest()
    assert payload["profile_revision"] == 3 and payload["expires_at"] == 1791630030
    assert payload["runner_profile_id"] == profile["profile_id"]
    private.public_key().verify(base64.b64decode(proof["signature_b64"]), runner.canonical_json(payload))
    assert runner.canonical_json(payload) == json.dumps(
        payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode()
    denied(profile, dict(challenge, environment="production"), "production cannot be signed")
    denied(profile, dict(challenge, site_uuid="11111111-1111-4111-8111-111111111111"), "foreign site UUID")
    denied(profile, dict(challenge, nonce="1"), "short nonce")
    denied(profile, dict(challenge, profile_revision=0), "missing revision")
    denied(profile, dict(challenge, profile_digest=""), "missing digest")
    denied(profile, dict(challenge, origin="http://staging.allroyalegypt.com"), "non HTTPS")
    denied(profile, dict(challenge, another_source="wordpress_option"), "extra source")
    denied(dict(profile, environment="production"), challenge, "non staging runner")
    denied(dict(profile, site_uuid="22222222-2222-4222-8222-222222222222"), challenge, "wrong host enrollment")
    denied(dict(profile, host_environment_receipt_signing_public_key_b64=base64.b64encode(b"X" * 32).decode()), challenge, "wrong pinned signer")
    os.chmod(key_file, 0o644)
    denied(profile, challenge, "world-readable signer")
    os.chmod(key_file, 0o600)
    fake_root = parent / "another-wordpress"
    fake_root.mkdir()
    (fake_root / "host-private-identity.pem").write_bytes(key_file.read_bytes())
    os.chmod(fake_root / "host-private-identity.pem", 0o600)
    denied(dict(profile, wordpress_root=str(fake_root), host_environment_receipt_signing_key_file=str(fake_root / "host-private-identity.pem")), challenge, "signer copied into site root")

print("PASS: 13 enrolled Host Runner identity signing and no-clone refusals")
