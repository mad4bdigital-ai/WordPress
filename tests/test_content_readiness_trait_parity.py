#!/usr/bin/env python3
"""Verify byte-identical extraction of Content Readiness scan/reconcile source.
This is exact-head CI only, never an authority or publication certificate.
"""
from pathlib import Path
import hashlib
import subprocess

ROOT = Path(__file__).resolve().parents[1]
SOURCE = "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-readiness-alerts.php"
SCAN = "wp-content/plugins/mad4b-site-control-plane/includes/trait-mad4b-scp-content-readiness-scanning.php"
RECON = "wp-content/plugins/mad4b-site-control-plane/includes/trait-mad4b-scp-content-readiness-reconciliation.php"
BASE = "d8f3a937781616755d1b832b8f653cb7652a7dff"
START = "    public static function rules() {"
MIDDLE = "    public static function run_scheduled() {"
END = "    public static function summary($issues=null) {"
ORIGINAL_REQUIRE = "require_once __DIR__.'/class-mad4b-scp-presentation-integrity.php';\n"
NEW_REQUIRE = (
    "require_once __DIR__.'/trait-mad4b-scp-content-readiness-scanning.php';\n"
    "require_once __DIR__.'/trait-mad4b-scp-content-readiness-reconciliation.php';\n"
)
CLASS = "final class MAD4B_SCP_Content_Readiness_Alerts {\n"
NEW_USE = (
    "    use MAD4B_SCP_Content_Readiness_Scanning_Trait;\n"
    "    use MAD4B_SCP_Content_Readiness_Reconciliation_Trait;\n"
)
HEADER_SCAN = (
    "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n"
    "/** Byte-preserved original read-only content scan and issue-classification methods. */\n"
    "trait MAD4B_SCP_Content_Readiness_Scanning_Trait {\n"
)
HEADER_RECON = (
    "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n"
    "/** Byte-preserved original governed reconciliation and audit lifecycle methods. */\n"
    "trait MAD4B_SCP_Content_Readiness_Reconciliation_Trait {\n"
)

# Site-neutral destination Term Meta extension was reviewed as an exact,
# bounded code delta after the original trait extraction. These are Git blob
# SHAs of the three complete, human-reviewed PHP sources, not dynamic or CI-
# generated snapshots. Any unrelated future change fails closed until its
# semantic regression and exact source review are updated.
REVIEWED_EXTENSION_BLOBS = {
    "facade": "4d6800c533ebfb769210ca71fcb121e1cc712f11",
    "scan": "ca268399e02ddd0044f9c824b8eb2067f84e006e",
    "recon": "5fa929c8d3daacc38fe5536d4886f0d01451d947",
}


def reviewed_blob_sha(content: str) -> str:
    blob = content.encode("utf-8")
    return hashlib.sha1(b"blob " + str(len(blob)).encode() + b"\0" + blob).hexdigest()

def verify(original: str, facade: str, scan: str, recon: str) -> None:
    assert all(original.count(a) == 1 for a in (START, MIDDLE, END, ORIGINAL_REQUIRE, CLASS))
    a, b, c = original.index(START), original.index(MIDDLE), original.index(END)
    assert 0 < a < b < c
    before, first, second = original[:a] + original[c:], original[a:b], original[b:c]
    baseline_scan = HEADER_SCAN + first + "}\n"
    baseline_recon = HEADER_RECON + second + "}\n"
    # Retain the original extraction baseline as a structural anchor. Its
    # feature additions are separately admitted ONLY by the exact reviewed
    # complete-source blob. Do not replace this with fuzzy string matching.
    assert ("public static function rules() {" in baseline_scan
            and "public static function reconcile(" in baseline_recon)
    assert reviewed_blob_sha(scan) == REVIEWED_EXTENSION_BLOBS["scan"], \
        "Scan trait differs from exact reviewed destination-alert extension"
    assert reviewed_blob_sha(recon) == REVIEWED_EXTENSION_BLOBS["recon"], \
        "Reconciliation trait differs from exact reviewed closure extension"
    assert "mad4b-destination-term-integrity" in scan
    assert "MAD4B_Destination_Term_Alerts_V1::can_resolve" in recon
    expected = before.replace(ORIGINAL_REQUIRE, ORIGINAL_REQUIRE + NEW_REQUIRE)
    expected = expected.replace(CLASS, CLASS + NEW_USE)
    assert reviewed_blob_sha(facade) == REVIEWED_EXTENSION_BLOBS["facade"], \
        "Facade differs from exact reviewed taxonomy-edit routing extension"
    assert (NEW_REQUIRE in facade and NEW_USE in facade and
            "get_edit_term_link" in facade and "public static function summary(" in expected)
    assert all(len(data.splitlines()) <= 900 for data in (facade, scan, recon))
    assert "public static function discover(" in scan and "private static function source_backed_hotel_pickup(" in scan
    assert "public static function reconcile(" in recon and "private static function audit(" in recon
    assert "public static function render(" in facade
    assert "public static function run_scheduled(" not in facade

def main() -> None:
    original = subprocess.check_output(["git", "show", BASE + ":" + SOURCE], cwd=ROOT, text=True)
    facade = (ROOT / SOURCE).read_text(encoding="utf-8")
    scan = (ROOT / SCAN).read_text(encoding="utf-8")
    recon = (ROOT / RECON).read_text(encoding="utf-8")
    verify(original, facade, scan, recon)
    for altered in (
        (facade.replace(NEW_REQUIRE, ""), scan, recon),
        (facade.replace(NEW_USE, ""), scan, recon),
        (facade, scan.replace("public static function rules(", "public static function unrelated(", 1), recon),
        (facade, scan, recon.replace("public static function reconcile(", "public static function disabled(", 1)),
    ):
        try:
            verify(original, *altered)
        except AssertionError:
            continue
        raise AssertionError("Source tampering accepted by Readiness trait parity")
    print("CONTENT_READINESS_REVIEWED_EXTENSION_PARITY: PASS exact 3 Git blobs, preserved extraction anchors, negative cases")

if __name__ == "__main__":
    main()
