#!/usr/bin/env python3
"""Hermetic negative and contract tests for root-owned Staging native auto-dispatch."""
import importlib.util
import json
from pathlib import Path
import tempfile

module_path=Path(__file__).resolve().parents[1]/"vps-native-central-auto-dispatch.py"
spec=importlib.util.spec_from_file_location("central_native_dispatch",module_path)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

assert m.CONTRACT=="mad4b.native-central-dispatch.v1"
assert m.ROOT==Path("/opt/mad4b-company-transport")
assert m.SIGNED_FIELDS[0]=="contract"
assert m.SIGNED_FIELDS[-1]=="nonce"
assert len(m.SIGNED_FIELDS)==20
assert m.JOB.fullmatch("a"*32)
assert not m.JOB.fullmatch("not-a-job")
assert m.CORR.fullmatch("staging-diagnostic-0001")
assert not m.CORR.fullmatch("..;rm -rf /")

with tempfile.TemporaryDirectory() as temp:
    m.COMMANDS=Path(temp)
    manifest=m.COMMANDS/"dispatch-inbox"/"test.json"
    path=lambda suffix:str(m.COMMANDS/("test."+suffix))
    record={
      "contract":m.CONTRACT,"company":"tours-central",
      "environment":"staging","correlation_id":"test-auto-pipeline-001",
      "command_file":path("command"),"readback_file":path("readback"),
      "expected_output_file":path("expected")}
    blobs={
      str(manifest):json.dumps(record).encode(),
      path("command"):b"printf TEST",
      path("readback"):b"printf TEST",
      path("expected"):b"TEST",
    }
    def owned(path,limit=4096):
        value=blobs.get(str(path))
        if value is None:raise RuntimeError("MOCK_ROOT_OWNERSHIP_DENIED")
        return value
    m.owned=owned
    valid,files=m.validate_manifest(manifest)
    assert valid["environment"]=="staging"
    assert files["expected_output_file"][1]==b"TEST"

    for key,value in [
      ("environment","production"),("company","wayfatours"),
      ("correlation_id","../outside;bad"),
      ("command_file","/tmp/not-root-owned"),
      ("readback_file","/tmp/not-root-owned"),
      ("expected_output_file","/tmp/not-root-owned")]:
        bad=dict(record,**{key:value})
        blobs[str(manifest)]=json.dumps(bad).encode()
        try:m.validate_manifest(manifest)
        except RuntimeError:pass
        else:raise AssertionError(f"unsafe {key} accepted")
    blobs[str(manifest)]=json.dumps(dict(record,irrelevant_capability=True)).encode()
    try:m.validate_manifest(manifest)
    except RuntimeError:pass
    else:raise AssertionError("extra manifest capability was accepted")
    blobs[str(manifest)]=json.dumps(record).encode()
    blobs[path("expected")]=b""
    try:m.validate_manifest(manifest)
    except RuntimeError:pass
    else:raise AssertionError("empty readback expected output accepted")

text=module_path.read_text()
for contract in (
  "NATIVE_CORRELATION_ALREADY_CONSUMED_DO_NOT_REEXECUTE",
  "SIGNED_312_AUTHORIZATION_INVALID",
  "ORIGINAL_SIGNED_314_COMPLETION_MISSING",
  "CENTRAL_314_READBACK_NOT_PERSISTED",
  "command_file",
  "readback_file",
  "expected_output_file",
  "INDEPENDENT_ROOT_SSH_REQUIRED",
  "LATE_EXECUTION_REQUIRES_HISTORICAL_RECONCILIATION"):
    assert contract in text, contract
assert "production" not in m.CONTRACT
print("PASS: exact Staging manifest, signed 306/312/314 pipeline contract, root-owned files and no replay")
