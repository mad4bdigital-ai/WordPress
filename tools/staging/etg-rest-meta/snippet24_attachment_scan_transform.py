#!/usr/bin/env python3
"""Offline, exact-source transform; never talks to WordPress or a database."""
import argparse
import hashlib
from pathlib import Path

EXPECTED_ORIGINAL_SHA256 = "bedb685b8e2de8734f5af8bc4d8f9aac63644a8194f55bf68fb332699006b5bf"
MARKER = "\t/**\n\t * Register post meta.\n\t */\n\tforeach ( $wp_post_types as $post_type => $obj ) {\n"
PUBLIC_GUARD = ("\t\tif ( empty( $obj->public ) ) {\n"
                "\t\t\tcontinue;\n"
                "\t\t}\n")
SKIP = ("\n\t\t// Only for the exact reviewed ETG Staging snippet: attachment has zero\n"
        "\t\t// currently allowed keys. Keep core /wp/v2/media unchanged.\n"
        "\t\tif ( 'attachment' === $post_type ) {\n"
        "\t\t\tcontinue;\n"
        "\t\t}\n")


def transform(raw: bytes, *, enforce_source_sha: bool = True) -> bytes:
    if enforce_source_sha and hashlib.sha256(raw).hexdigest() != EXPECTED_ORIGINAL_SHA256:
        raise ValueError("SNIPPET24_SOURCE_SHA_MISMATCH: no patch permitted")
    text = raw.decode("utf-8")
    if text.count(MARKER) != 1:
        raise ValueError("SNIPPET24_POST_META_MARKER_NOT_UNIQUE")
    start = text.index(MARKER) + len(MARKER)
    if not text[start:].startswith(PUBLIC_GUARD):
        raise ValueError("SNIPPET24_PUBLIC_GUARD_CHANGED")
    if "if ( 'attachment' === $post_type )" in text:
        raise ValueError("SNIPPET24_ALREADY_PATCHED")
    if text.count("Version: 1.2.0") != 1:
        raise ValueError("SNIPPET24_VERSION_MISMATCH")
    after_guard = start + len(PUBLIC_GUARD)
    updated = text[:after_guard] + SKIP + text[after_guard:]
    updated = updated.replace("Version: 1.2.0", "Version: 1.2.1", 1)
    # This is for a Code Snippets editor, not a new stand-alone WordPress plugin.
    if updated.startswith("<?php") and not text.startswith("<?php"):
        raise ValueError("UNEXPECTED_OPENING_TAG")
    return updated.encode("utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True, help="site-authorized export of Code Snippets #24 code, without PHP tag")
    parser.add_argument("--output", type=Path, required=True, help="reviewed replacement code for approved deployment")
    args = parser.parse_args()
    if args.source.resolve() == args.output.resolve():
        raise ValueError("CANNOT_OVERWRITE_ORIGINAL")
    raw = args.source.read_bytes()
    output = transform(raw)
    if args.output.exists():
        raise FileExistsError("OUTPUT_MUST_BE_NEW_FILE")
    args.output.write_bytes(output)
    print("REVIEW_ONLY_REPLACEMENT_READY sha256=" + hashlib.sha256(output).hexdigest())
    print("This command does NOT update WordPress, Code Snippets, or any database.")


if __name__ == "__main__":
    main()
