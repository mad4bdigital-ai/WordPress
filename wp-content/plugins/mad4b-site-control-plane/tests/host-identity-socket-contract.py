#!/usr/bin/env python3
"""Host identity transport: local peer, framing and no alternate signer."""
import importlib.util
import pathlib
import sys

root = pathlib.Path(__file__).resolve().parents[4]
tools = root / "tools"
sys.path.insert(0, str(tools))
spec = importlib.util.spec_from_file_location("mad4b_host_identity_socket", tools / "mad4b_host_identity_socket.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def denies(raw, peer_uid, expected_uid, label):
    try:
        module.sign_request(raw, {"site_uuid": "11111111-1111-4111-8111-111111111111"}, peer_uid=peer_uid, expected_uid=expected_uid)
    except ValueError:
        return
    raise AssertionError("Expected blocked: " + label)


denies(b"", 1000, 1000, "empty request")
denies(b"{}" + b"x" * 3072 + b"\n", 1000, 1000, "oversized request")
denies(b"{}\n", 0, 0, "root peer")
denies(b"{}\n", 1000, 1001, "foreign peer")
denies(b"{}\n", 1000, 0, "invalid enrolled worker")
denies(b"{}\n", 1000, 1000, "invalid Host challenge")
denies(b'{"contract":"v1","contract":"v2"}\n', 1000, 1000, "ambiguous duplicate JSON keys")
denies(b'{"contract":"v1"}', 1000, 1000, "missing framing")
denies(b'[]\n', 1000, 1000, "JSON array")
denies(b'\xff\n', 1000, 1000, "invalid UTF-8")
for candidate in ["/tmp/mad4b-host-runner/identity.sock",
                  "/run/mad4b-host-runner/../other.sock",
                  "/run/mad4b-host-runner/capture.json",
                  "/run/mad4b-host-runner/identity.sock/other"]:
    try:
        module.verify_socket_location(pathlib.Path(candidate))
    except ValueError:
        continue
    raise AssertionError("Invalid Host Unix socket path accepted: " + candidate)
print("PASS: 14 local Host peer/socket failure cases")
