#!/usr/bin/env python3
"""Synthetic dual-signature roundtrip. Does not enqueue/execute a live task."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives import serialization

BASE = Path(__file__).resolve().parents[1]
MODULES = BASE / "tools/are-staging-unified-remote-plane"


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, MODULES / filename)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


verify = module("operator_seal", "operator-seal-verifier.py")
receipt_mod = module("operator_receipt", "operator-worker-receipt.py")


def pair(where, name):
    private = rsa.generate_private_key(public_exponent=65537, key_size=3072)
    pem = private.private_bytes(
        serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
        serialization.NoEncryption())
    pub = private.public_key().public_bytes(
        serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo)
    priv_path = where / (name + "-private.pem")
    pub_path = where / (name + "-public.pem")
    priv_path.write_bytes(pem)
    os.chmod(priv_path, 0o600)
    pub_path.write_bytes(pub)
    os.chmod(pub_path, 0o600)
    return priv_path, pub_path


def main():
    with tempfile.TemporaryDirectory(prefix="are-operator-rsa-") as raw:
        tmp = Path(raw)
        site_priv, site_pub = pair(tmp, "site")
        worker_priv, worker_pub = pair(tmp, "worker")
        env = dict(os.environ, MAD4B_TEST_SIGNING_KEY_PATH=str(site_priv))
        php = subprocess.run(
            ["php", str(MODULES / "operator-signature-fixture.php")],
            env=env, capture_output=True, text=True, check=True)
        fixture = json.loads(php.stdout)
        submission, seal = fixture["submission"], fixture["seal"]
        admitted = verify.verify_job(
            submission, seal, site_pub.read_bytes(), "a"*64,
            lambda b, a: True, lambda b, a: False)
        result = receipt_mod.issue_receipt(
            admitted, seal, {
                "verdict": "PASS", "exit_code": 0, "stdout": "1",
                "execution_mode": "staging_read_only_sql",
                "mutation_performed": False,
            }, True, False, worker_priv.read_bytes(), worker_pub.read_bytes())
        job_path = tmp / "signed-job.json"
        receipt_path = tmp / "signed-receipt.json"
        job_path.write_text(json.dumps(fixture, separators=(",", ":")))
        receipt_path.write_text(json.dumps(result, separators=(",", ":")))
        subprocess.run([
            "php", str(BASE / "tests/are-operator-receipt-verify-offline.php"),
            str(job_path), str(receipt_path), str(site_pub), str(worker_pub),
        ], check=True)
        for label, mod, accepted in (
            ("tampered_sql", dict(submission, plan=dict(submission["plan"],
                arguments=dict(submission["plan"]["arguments"], payload={"sql":"SELECT 2"}))), False),
            ("valid_unmodified", submission, True),
        ):
            try:
                verify.verify_job(mod, seal, site_pub.read_bytes(), "a"*64,
                                  lambda b, a: True, lambda b, a: False)
                if not accepted:
                    raise AssertionError(label + " was admitted")
            except verify.AdmissionDenied:
                if accepted:
                    raise
        for label, checker in (
            ("revoked_session", lambda b, a: False),
            ("unavailable_check", None),
        ):
            try:
                verify.verify_job(submission, seal, site_pub.read_bytes(),
                    "a"*64, checker, lambda b, a: False)
                raise AssertionError(label + " was admitted")
            except verify.AdmissionDenied:
                pass
        print("PASS synthetic asymmetric roundtrip: site signature, worker signature,"
              " tampering, revoked session and missing authority. NO LIVE EXECUTION.")


if __name__ == "__main__":
    main()
