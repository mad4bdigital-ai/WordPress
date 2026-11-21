"""
Exact inverse for independently reviewed SCP public-kernel source successor.
Never relax or repin immutable historical CWV/PR813/Original305 certificates.
Each byte and the new non-authorizing capability review must match exactly.
"""
from pathlib import Path
import hashlib
import json

ROOT = Path(__file__).resolve().parents[1]
MAIN = "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
KERNEL = "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-public-document-kernel.php"
MANIFEST = "wp-content/plugins/mad4b-site-control-plane/config/public-document-kernel-manifest-v1.json"
PREVIOUS = "97887a449a3abf473a043d2da0737a3c2f2ab7ae"
SUCCESSOR = "4029009f5e621ae30b705d46b675220cd657c0c9"
KERNEL_SHA = "71577692bc031e65f0c822f3f29b1644d26dfb71"
MANIFEST_SHA = "aab5082e72ee6f1fb89f4cf4c16bbecece446203"
ADDITION = b"// Request-aware public singular kernel. OFF by default and restricted to\n// read-only, signed-site, cookie-free canonical routes; no authorization or\n// public cache admission is created by this optional source module.\nrequire_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-public-document-kernel.php';\nif ( MAD4B_SCP_Public_Document_Kernel::try_boot() ) {\n    return;\n}\n\n"

def blob(content):
    return hashlib.sha1(b"blob " + str(len(content)).encode("ascii") + b"\0" + content).hexdigest()


def strip_exact_public_kernel(content):
    sha = blob(content)
    if sha == PREVIOUS:
        return content
    if sha != SUCCESSOR:
        raise AssertionError("Unknown SCP public kernel source successor: " + sha)
    for path, expected in ((KERNEL, KERNEL_SHA), (MANIFEST, MANIFEST_SHA)):
        if blob((ROOT / path).read_bytes()) != expected:
            raise AssertionError("SCP public kernel dependency drift: " + path)
    coverage=json.loads((ROOT/"governance/production-readiness/capability-coverage.json").read_text())
    if coverage.get("authorizing") is not False or coverage.get("production_promotion_from_ci") is not False:
        raise AssertionError("SCP successor coverage must remain non-authorizing")
    for path, expected in ((MAIN,SUCCESSOR),(KERNEL,KERNEL_SHA),(MANIFEST,MANIFEST_SHA)):
        matches=[r for r in coverage["reviewed_changes"] if r.get("path")==path
            and r.get("source_blob_sha")==expected
            and r.get("status")=="reviewed_for_ci_only"
            and r.get("production_authorized") is False
            and r.get("live_evidence_required") is True
            and "tests/scp_public_document_kernel_source_contract.py" in r.get("regression_tests",[])]
        if len(matches)!=1:
            raise AssertionError("No exact, independently reviewed SCP source: " + path)
    service="service:"+KERNEL
    caps=[r for r in coverage["reviewed_capabilities"]
        if r.get("id")==service and r.get("status")=="reviewed_for_ci_only"
        and r.get("production_authorized") is False
        and r.get("live_evidence_required") is True]
    if len(caps)!=1:
        raise AssertionError("Unreviewed SCP public source capability")
    if content.count(ADDITION)!=1:
        raise AssertionError("SCP public kernel exact source insertion not unique")
    previous=content.replace(ADDITION,b"",1)
    if blob(previous)!=PREVIOUS:
        raise AssertionError("SCP public kernel inverse cannot reconstruct previous exact source")
    return previous
