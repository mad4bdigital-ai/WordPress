#!/usr/bin/env python3
"""Verify byte-identical extraction of Content Readiness scan/reconcile source.
This is exact-head CI only, never an authority or publication certificate.
"""
from pathlib import Path
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

def verify(original: str, facade: str, scan: str, recon: str) -> None:
    assert all(original.count(a) == 1 for a in (START, MIDDLE, END, ORIGINAL_REQUIRE, CLASS))
    a, b, c = original.index(START), original.index(MIDDLE), original.index(END)
    assert 0 < a < b < c
    before, first, second = original[:a] + original[c:], original[a:b], original[b:c]
    assert scan == HEADER_SCAN + first + "}\n", "Scan trait changed from reviewed source bytes"
    assert recon == HEADER_RECON + second + "}\n", "Reconciliation trait changed from reviewed source bytes"
    expected = before.replace(ORIGINAL_REQUIRE, ORIGINAL_REQUIRE + NEW_REQUIRE)
    expected = expected.replace(CLASS, CLASS + NEW_USE)
    assert facade == expected, "Facade changed beyond exact require/use and extraction"
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
    print("CONTENT_READINESS_BYTE_PARITY: PASS exact 2 traits, bound facade, 900-line budgets, negative cases")

if __name__ == "__main__":
    main()
