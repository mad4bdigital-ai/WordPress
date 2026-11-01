#!/usr/bin/env python3
"""Offline checks for signed original Native VPS client v1 rotation.
No network, root, WordPress or source mutation.
"""
import base64
import contextlib
import importlib.util
import io
import json
from pathlib import Path
from urllib.error import HTTPError

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location(
    "original_vps_client", ROOT / "native-vps-client-principal.py"
)
client = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client)
key = Ed25519PrivateKey.generate()
client.authority = lambda: "a" * 64
client.original_signer = lambda: key
client.check_original_wordpress_signer = lambda _key: None
client.host_fingerprint = lambda: "SHA256:" + "A" * 43
client.command_file = lambda _path: "printf one-fresh-diagnostic"
calls = []

class Reply:
    def __init__(self, record):
        self.record = record
    def __enter__(self):
        return self
    def __exit__(self, *_):
        return False
    def read(self, _size):
        return json.dumps(self.record).encode("utf-8")

def verify(request):
    payload = json.loads(request.data)
    proof = payload["proof"]
    assert tuple(proof) == (*client.FIELDS, "signature")
    assert request.get_method() == "POST"
    assert request.full_url.startswith("https://tours.dev.mad4b.com/wp-json/")
    text = "\n".join(str(proof[k]) for k in client.FIELDS).encode()
    key.public_key().verify(base64.b64decode(proof["signature"]), text)
    calls.append((proof["action"], proof["command_sha256"], payload["command"]))
    return payload

def rotate_ok(request, timeout):
    payload = verify(request)
    assert payload["proof"]["action"] == "rotate"
    assert payload["proof"]["correlation_id"] == "enrollment"
    assert payload["proof"]["command_sha256"] == "0" * 64
    assert payload["command"] == ""
    return Reply({
        "contract": client.CONTRACT,
        "state": "SIGNED_STANDING_POLICY_ROTATED",
        "principal_id": "vps-native:srv2030908",
        "old_grant_revoked": True,
        "old_transport_revoked": True,
        "execution_authorized": False,
        "execution_performed": False,
        "grant_id": "b" * 32,
        "transport_id": "c" * 32,
    })

client.urlopen = rotate_ok
with contextlib.redirect_stdout(io.StringIO()) as out:
    client.run("rotate", "", None)
assert json.loads(out.getvalue())["shell_executed"] is False
assert len(calls) == 1 and calls[0][0] == "rotate"

def denied_policy(request, timeout):
    verify(request)
    raise HTTPError(
        request.full_url, 409, "Conflict", {},
        io.BytesIO(json.dumps({
            "code": "mad4b_native_vps_signed_policy_rotation_required"
        }).encode())
    )

client.urlopen = denied_policy
try:
    client.run("submit", "new-diagnostic-request", Path("fake"))
except RuntimeError as exc:
    assert str(exc) == "SIGNED_POLICY_ROTATION_REQUIRED_NO_REPLAY"
else:
    raise AssertionError("policy drift must deny without replay")
assert len(calls) == 2, "HTTP 409 must never produce an automatic second request"

def uncertain_rotate(request, timeout):
    verify(request)
    return Reply({
        "contract": client.CONTRACT,
        "state": "SIGNED_STANDING_POLICY_ROTATED",
        "principal_id": "vps-native:srv2030908",
        "old_grant_revoked": True,
        "old_transport_revoked": True,
        "execution_authorized": False,
        "execution_performed": True,
    })
client.urlopen = uncertain_rotate
try:
    client.run("rotate", "", None)
except RuntimeError as exc:
    assert str(exc) == "SIGNED_POLICY_ROTATION_READBACK_UNCERTAIN"
else:
    raise AssertionError("rotation without no-execution receipt must deny")
assert len(calls) == 3
# An already-current HTTP 409 is a verified no-op only if original Core
# readback confirms the same policy, host key and active grant/transport.
from types import SimpleNamespace
original_run = client.subprocess.run
def native_readback(command, **kwargs):
    assert command[:3] == ["docker","exec","-u"]
    return SimpleNamespace(stdout=json.dumps({
        "ledger_valid": True,
        "policy_sha256": "a" * 64,
        "fingerprint": "SHA256:" + "A" * 43,
        "grant_active": True,
        "transport_active": True
    }) + "\n")
client.subprocess.run = native_readback
assert client.verify_original_policy_readback("a"*64,"SHA256:"+"A"*43)
assert not client.verify_original_policy_readback("b"*64,"SHA256:"+"A"*43)
client.subprocess.run = original_run
def policy_current(request, timeout):
    verify(request)
    raise HTTPError(request.full_url,409,"Conflict",{},
        io.BytesIO(json.dumps({
            "code":"mad4b_native_vps_policy_already_current"
        }).encode("utf-8")))
client.urlopen = policy_current
client.verify_original_policy_readback = lambda policy,fingerprint: (
    policy=="a"*64 and fingerprint=="SHA256:"+"A"*43
)
with contextlib.redirect_stdout(io.StringIO()) as out:
    client.run("rotate","",None)
result=json.loads(out.getvalue())
assert result["state"]=="ORIGINAL_SIGNED_POLICY_ALREADY_CURRENT_VERIFIED"
assert result["grant_changed"] is False
assert result["shell_executed"] is False
assert len(calls)==4
client.verify_original_policy_readback = lambda *_: False
try:
    client.run("rotate","",None)
except RuntimeError as exc:
    assert str(exc)=="SIGNED_POLICY_ALREADY_CURRENT_READBACK_UNCERTAIN"
else:
    raise AssertionError("unverified already-current must remain denied")
assert len(calls)==5
print("PASS native client signed rotate, policy-drift 409 classification, no replay, uncertain-readback rejection")
