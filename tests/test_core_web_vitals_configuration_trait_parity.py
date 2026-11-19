#!/usr/bin/env python3
"""Verify the Core Web Vitals configuration trait is a byte-identical relocation."""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
BASE = "f3fa2dcb4fc82e843d57338400729b21893c113e"
FAC = "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-core-web-vitals.php"
TRAIT = "wp-content/plugins/mad4b-site-control-plane/includes/trait-mad4b-scp-core-web-vitals-configuration.php"
START = "    private static function settings() {"
END = "    private static function site_url() {"
OLD_GUARD = "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
NEW_LOADER = "require_once __DIR__ . '/trait-mad4b-scp-core-web-vitals-configuration.php';\n"
CLASS = "final class MAD4B_SCP_Core_Web_Vitals {\n"
USE = "    use MAD4B_SCP_Core_Web_Vitals_Configuration_Trait;\n"
HEADER = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n/** Exact source-extracted CWV configuration, encrypted key and URL validation; no behavior changes. */\ntrait MAD4B_SCP_Core_Web_Vitals_Configuration_Trait {\n"
ORIGINAL_METHODS = ("settings", "encryption_key", "encrypt_api_key", "decrypt_api_key",
                    "server_api_key", "api_key", "validate_monitored_url")

def check(original, facade, trait):
    assert original.count(START) == 1 and original.count(END) == 1
    assert original.count(OLD_GUARD) == 1 and original.count(CLASS) == 1
    a, b = original.index(START), original.index(END)
    assert 0 < a < b
    original_block = original[a:b]
    assert all(original_block.count("function " + name + "(") == 1 for name in ORIGINAL_METHODS)
    assert trait == HEADER + original_block + "}\n", "CWV method body changed"
    residual = original[:a] + original[b:]
    wanted = residual.replace(OLD_GUARD, OLD_GUARD + NEW_LOADER).replace(CLASS, CLASS + USE)
    assert facade == wanted, "CWV facade differs beyond exact relocation and bound trait"
    assert all(len(part.splitlines()) <= 900 for part in (facade, trait))
    assert "function validate_monitored_url(" not in facade
    assert "function validate_monitored_url(" in trait
    assert "function decrypt_api_key(" in trait

def main():
    source = subprocess.check_output(["git", "show", BASE + ":" + FAC], cwd=ROOT, text=True)
    facade = (ROOT / FAC).read_text(encoding="utf-8")
    trait = (ROOT / TRAIT).read_text(encoding="utf-8")
    check(source, facade, trait)
    for bad in (
        (facade.replace(NEW_LOADER, ""), trait),
        (facade.replace(USE, ""), trait),
        (facade, trait.replace("function decrypt_api_key(", "function omit_decrypt(", 1)),
        (facade, trait.replace("function validate_monitored_url(", "function skip_url_guard(", 1)),
    ):
        try:
            check(source, *bad)
        except AssertionError:
            continue
        raise AssertionError("CWV trait regression accepted")
    print("CWV_CONFIGURATION_ORIGINAL_BYTE_PARITY: PASS; 7 original methods, require/use and negative cases")

if __name__ == "__main__":
    main()
