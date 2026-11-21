"""Non-authorizing exact inverse for approved CWV per-company Bootstrap delta.

This does not modify the original Original305/PR813 immutable source review.
Unknown bytes always fail closed instead of being accepted as newer source.
"""
from pathlib import Path
import hashlib
import json
from scp_public_kernel_exact_source_overlay import strip_exact_public_kernel

ROOT = Path(__file__).resolve().parents[1]
MAIN = "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
NEW = "97887a449a3abf473a043d2da0737a3c2f2ab7ae"
OLD = "2c497ec72c57f420d1abf1eef242748be40f7bb2"
ADMISSION = "366b5b085976f87a633aa4a7737596eea14dcd6b"
ORIGINAL = b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-core-web-vitals.php';"
REPLACEMENT = (b"// Per-company admission: do not activate CWV hooks or Cron on another site\n"
    b"// just because its shared Control Plane source has the provider class.\n"
    b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-cwv-site-admission.php';\n"
    b"if ( MAD4B_SCP_CWV_Site_Admission::admitted() ) {\n"
    b"    require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-core-web-vitals.php';\n"
    b"}")

def blob(content):
    return hashlib.sha1(b"blob " + str(len(content)).encode('ascii') + b"\0" + content).hexdigest()

def strip_exact_site_admission(content):
    content = strip_exact_public_kernel(content)
    sha = blob(content)
    if sha == OLD:
        return content
    if sha != NEW:
        raise AssertionError("Unknown CWV Bootstrap successor: " + sha)
    helper = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cwv-site-admission.php"
    if blob(helper.read_bytes()) != ADMISSION:
        raise AssertionError("Unknown CWV Site Admission helper")
    ledger = json.loads((ROOT / "governance/production-readiness/capability-coverage.json").read_text())
    if ledger.get('authorizing') is not False or ledger.get('production_promotion_from_ci') is not False:
        raise AssertionError("CWV source review unexpectedly authorizing")
    capability='service:wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cwv-site-admission.php'
    reviewed=[entry for entry in ledger.get('reviewed_capabilities',[])
        if entry.get('id') == capability
        and entry.get('status') == 'reviewed_for_ci_only'
        and entry.get('production_authorized') is False
        and entry.get('live_evidence_required') is True
        and 'wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php'
            in entry.get('regression_tests',[])]
    if len(reviewed)!=1:
        raise AssertionError('CWV Service capability is not separately reviewed')
    for path, expected in ((MAIN, NEW), (helper.relative_to(ROOT).as_posix(), ADMISSION)):
        matches = [r for r in ledger['reviewed_changes']
            if r.get('path') == path and r.get('source_blob_sha') == expected
            and r.get('status') == 'reviewed_for_ci_only'
            and r.get('production_authorized') is False
            and r.get('live_evidence_required') is True
            and 'wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php' in r.get('regression_tests', [])]
        if len(matches) != 1:
            raise AssertionError('Missing CWV exact-path CI-only review: ' + path)
    if content.count(REPLACEMENT) != 1:
        raise AssertionError("CWV Bootstrap guarded block not unique")
    previous = content.replace(REPLACEMENT, ORIGINAL, 1)
    if blob(previous) != OLD:
        raise AssertionError("CWV inverse must reproduce reviewed preceding source exactly")
    return previous
