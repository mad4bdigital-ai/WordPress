#!/usr/bin/env python3
"""Resolve, verify, and materialize the exact MCP Adapter certified by MAD4B policy."""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
import tempfile
import urllib.parse
import urllib.request
from pathlib import Path

MAX_ADAPTER_BYTES = 16 * 1024 * 1024
def is_certified_release_url(version: str, url: str) -> bool:
    """Allow only the exact official archive path for this certified profile."""
    return url in (
        f"https://github.com/WordPress/mcp-adapter/releases/download/v{version}/mcp-adapter.zip",
        f"https://downloads.wordpress.org/plugin/mcp-adapter.{version}.zip",
    )
ALLOWED_FINAL_HOSTS = {
    "github.com",
    "downloads.wordpress.org",
    "objects.githubusercontent.com",
    "release-assets.githubusercontent.com",
    "github-releases.githubusercontent.com",
}


def fail(message: str) -> "NoReturn":
    raise SystemExit(message)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plugins-dir", required=True)
    parser.add_argument("--timeout", type=int, default=30)
    parser.add_argument("--version", default=None,
                        help="Exact reviewed provider profile to materialize for this CI job, not a runtime minimum")
    args = parser.parse_args()

    root = Path(__file__).resolve().parents[1]
    config = root / "config"
    policy = json.loads((config / "runtime-release-policy.json").read_text(encoding="utf-8"))
    profiles = json.loads((config / "certified-provider-profiles.json").read_text(encoding="utf-8"))

    if policy.get("contract") != "mad4b.runtime-release-policy.v1":
        fail("runtime release policy contract mismatch")

    # The materializer explicitly downloads a reviewed release artifact for
    # a CI fixture; it does not impose its version on installed runtimes.
    protocol = json.loads((config / "mcp-protocol-profiles.json").read_text(encoding="utf-8"))
    reference = (protocol.get("certified_adapter") or {}).get("version")
    version = str(args.version if args.version is not None else reference or "").strip()
    profile = ((profiles.get("providers") or {}).get("mcp_adapter") or {}).get(version) or {}
    if not version or profile.get("version") != version:
        fail("target MCP Adapter profile missing")

    expected_sha = str(profile.get("archive_sha256") or "").lower()
    expected_bytes = int(profile.get("archive_bytes") or 0)
    url = str(profile.get("package_url") or "")
    if (
        len(expected_sha) != 64
        or expected_bytes < 1
        or expected_bytes > MAX_ADAPTER_BYTES
        or not is_certified_release_url(version, url)
    ):
        fail("target MCP Adapter profile identity invalid")

    parsed = urllib.parse.urlparse(url)
    if parsed.scheme != "https" or parsed.hostname not in {"github.com", "downloads.wordpress.org"}:
        fail("target MCP Adapter package URL is outside the certified GitHub release origin")

    request = urllib.request.Request(url, headers={"User-Agent": "MAD4B-Certified-Adapter-CI/1"})
    with urllib.request.urlopen(request, timeout=max(5, min(args.timeout, 60))) as response:
        final = urllib.parse.urlparse(response.geturl())
        if final.scheme != "https" or (final.hostname or "").lower() not in ALLOWED_FINAL_HOSTS:
            fail(f"target MCP Adapter redirect host is not certified: {final.hostname!r}")
        payload = response.read(expected_bytes + 1)

    if len(payload) != expected_bytes:
        fail(f"target MCP Adapter byte-size mismatch: {len(payload)} expected={expected_bytes}")
    actual_sha = hashlib.sha256(payload).hexdigest()
    if actual_sha != expected_sha:
        fail(f"target MCP Adapter SHA-256 mismatch: {actual_sha}")

    plugins_dir = Path(args.plugins_dir).resolve()
    materializer = Path(__file__).with_name("materialize-packaged-plugin.py")
    with tempfile.NamedTemporaryFile(prefix="mad4b-certified-mcp-adapter-", suffix=".zip", delete=False) as tmp:
        tmp.write(payload)
        archive = Path(tmp.name)

    try:
        proc = subprocess.run(
            [
                sys.executable,
                str(materializer),
                "--archive",
                str(archive),
                "--plugins-dir",
                str(plugins_dir),
                "--slug",
                "mcp-adapter",
            ],
            check=True,
            text=True,
            capture_output=True,
        )
        materialization = json.loads(proc.stdout)
    finally:
        archive.unlink(missing_ok=True)

    report = {
        "contract": "mad4b.certified-mcp-adapter-materialization.v1",
        "version": version,
        "archive_sha256": actual_sha,
        "archive_bytes": len(payload),
        "source": "explicit_or_protocol_reference+certified_provider_profile",
        "materialization": materialization,
        "status": "passed",
    }
    print(json.dumps(report, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
