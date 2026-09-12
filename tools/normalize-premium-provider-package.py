#!/usr/bin/env python3
"""Normalize an owner/vendor premium plugin ZIP into canonical WordPress layout.

This does not certify a package. It only re-roots one unambiguous plugin payload
under its canonical plugin directory, validates the embedded Version header, and
emits deterministic provenance for the source and normalized archives. Exact
provider attestation remains a separate fail-closed step.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import re
import shutil
import sys
import zipfile

PROVIDERS = {
    "jetengine": {
        "root": "jet-engine",
        "main": "jet-engine.php",
    },
    "jetsmartfilters": {
        "root": "jet-smart-filters",
        "main": "jet-smart-filters.php",
    },
}


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def safe_name(name: str) -> str:
    name = name.replace("\\", "/")
    if name.startswith("/"):
        raise SystemExit(f"absolute ZIP member refused: {name}")
    parts = pathlib.PurePosixPath(name).parts
    if ".." in parts:
        raise SystemExit(f"path traversal ZIP member refused: {name}")
    return "/".join(parts)


def ignored(name: str) -> bool:
    parts = pathlib.PurePosixPath(name).parts
    return not parts or parts[0] == "__MACOSX" or parts[-1] == ".DS_Store"


def version_from_header(data: bytes) -> str:
    text = data.decode("utf-8", errors="replace")[:32768]
    match = re.search(r"^\s*\*?\s*Version:\s*([^\r\n]+)", text, re.I | re.M)
    if not match:
        raise SystemExit("plugin Version header not found")
    return match.group(1).strip()


def copy_info(source: zipfile.ZipInfo, target_name: str) -> zipfile.ZipInfo:
    target = zipfile.ZipInfo(target_name, date_time=source.date_time)
    target.compress_type = source.compress_type
    target.comment = source.comment
    target.extra = source.extra
    target.create_system = source.create_system
    target.create_version = source.create_version
    target.extract_version = source.extract_version
    target.flag_bits = source.flag_bits
    target.volume = source.volume
    target.internal_attr = source.internal_attr
    target.external_attr = source.external_attr
    return target


def normalize(provider: str, source: pathlib.Path, output: pathlib.Path, expected: str) -> dict:
    spec = PROVIDERS[provider]
    canonical_root = spec["root"]
    main_file = spec["main"]
    canonical_main = f"{canonical_root}/{main_file}"

    if not source.is_file():
        raise SystemExit(f"package not found: {source}")
    raw = source.read_bytes()

    try:
        zf = zipfile.ZipFile(source)
    except zipfile.BadZipFile as exc:
        raise SystemExit(f"invalid ZIP: {source}") from exc

    with zf:
        entries = []
        by_name = {}
        for info in zf.infolist():
            name = safe_name(info.filename)
            if ignored(name):
                continue
            entries.append((info, name))
            by_name[name] = info

        main_candidates = [name for _, name in entries if not name.endswith("/") and pathlib.PurePosixPath(name).name == main_file]
        if len(main_candidates) != 1:
            raise SystemExit(
                f"{provider}: expected exactly one {main_file}; found={main_candidates}"
            )
        source_main = main_candidates[0]
        prefix = source_main[: -len(main_file)].rstrip("/")
        layout = "canonical" if source_main == canonical_main else ("rootless" if not prefix else "wrapped")

        if prefix:
            prefix_with_slash = prefix + "/"
            payload = [(info, name) for info, name in entries if name == prefix or name.startswith(prefix_with_slash)]
            foreign = [name for _, name in entries if name != prefix and not name.startswith(prefix_with_slash)]
            foreign = [name for name in foreign if not name.endswith("/")]
            if foreign:
                raise SystemExit(f"{provider}: wrapper contains foreign payload outside plugin root: {foreign[:20]}")
        else:
            payload = entries

        main_data = zf.read(by_name[source_main])
        observed_version = version_from_header(main_data)
        if observed_version != expected:
            raise SystemExit(
                f"{provider}: exact version mismatch; expected={expected} observed={observed_version}"
            )

        output.parent.mkdir(parents=True, exist_ok=True)
        if layout == "canonical":
            shutil.copyfile(source, output)
        else:
            seen = set()
            with zipfile.ZipFile(output, "w") as out:
                for info, name in sorted(payload, key=lambda item: item[1]):
                    if prefix:
                        if name == prefix:
                            relative = ""
                        else:
                            relative = name[len(prefix) + 1 :]
                    else:
                        relative = name
                    if not relative:
                        continue
                    target_name = f"{canonical_root}/{relative}"
                    if target_name in seen:
                        raise SystemExit(f"{provider}: duplicate normalized member: {target_name}")
                    seen.add(target_name)
                    target_info = copy_info(info, target_name)
                    if info.is_dir() or name.endswith("/"):
                        if not target_name.endswith("/"):
                            target_info.filename += "/"
                        out.writestr(target_info, b"")
                    else:
                        out.writestr(target_info, zf.read(info))

    normalized = output.read_bytes()
    with zipfile.ZipFile(output) as check:
        names = set(check.namelist())
        if canonical_main not in names:
            raise SystemExit(f"{provider}: normalization failed to create {canonical_main}")
        final_version = version_from_header(check.read(canonical_main))
        if final_version != expected:
            raise SystemExit(f"{provider}: normalized Version header drifted")

    return {
        "contract": "mad4b.premium-provider-package-normalization.v1",
        "provider": provider,
        "expected_version": expected,
        "observed_version": observed_version,
        "source_layout": layout,
        "source_main": source_main,
        "canonical_main": canonical_main,
        "source_archive_sha256": sha256_bytes(raw),
        "source_archive_bytes": len(raw),
        "normalized_archive_sha256": sha256_bytes(normalized),
        "normalized_archive_bytes": len(normalized),
        "normalized": layout != "canonical",
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--provider", required=True, choices=sorted(PROVIDERS))
    parser.add_argument("--input", required=True, type=pathlib.Path)
    parser.add_argument("--output", required=True, type=pathlib.Path)
    parser.add_argument("--expected-version", required=True)
    parser.add_argument("--report", required=True, type=pathlib.Path)
    args = parser.parse_args()

    result = normalize(args.provider, args.input, args.output, args.expected_version)
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
