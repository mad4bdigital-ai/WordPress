#!/usr/bin/env python3
"""Resolve, verify, and materialize the exact MCP Adapter certified by MAD4B policy."""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
import tempfile
import re
import urllib.parse
import urllib.request
from pathlib import Path

MAX_ADAPTER_BYTES = 16 * 1024 * 1024
OFFICIAL_GITHUB_REDIRECT_HOSTS = frozenset({
    "github.com",
    "objects.githubusercontent.com",
    "release-assets.githubusercontent.com",
    "github-releases.githubusercontent.com",
})
OFFICIAL_WP_ORG_REDIRECT_HOSTS = frozenset({"downloads.wordpress.org"})


def trusted_url_parts(value: str):
    try:
        parts = urllib.parse.urlsplit(value)
        if (parts.scheme != "https" or parts.username is not None
                or parts.password is not None or parts.port is not None
                or parts.query or parts.fragment or not parts.hostname):
            return None
        return parts
    except ValueError:
        return None


def reviewed_source(profile: dict, version: str) -> frozenset[str]:
    # Match the independent archive-generator identity policy. Version, host,
    # official distribution authority and exact URL path must all agree.
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        fail("invalid exact MCP Adapter version")
    location = trusted_url_parts(str(profile.get("package_url") or ""))
    authority = str(profile.get("certification_authority") or "")
    if location is None:
        fail("target MCP Adapter profile identity invalid")
    host = location.hostname.lower()
    if (host == "github.com"
            and location.path == f"/WordPress/mcp-adapter/releases/download/v{version}/mcp-adapter.zip"
            and authority == f"github.com/WordPress/mcp-adapter/releases/tag/v{version}"):
        return OFFICIAL_GITHUB_REDIRECT_HOSTS
    if (host == "downloads.wordpress.org"
            and location.path == f"/plugin/mcp-adapter.{version}.zip"
            and authority == f"https://wordpress.org/plugins/mcp-adapter/ (official {version} distribution)"):
        return OFFICIAL_WP_ORG_REDIRECT_HOSTS
    fail("target MCP Adapter profile identity invalid")


class CertifiedRedirectPolicy(urllib.request.HTTPRedirectHandler):
    def __init__(self, hosts: frozenset[str]):
        super().__init__()
        self.hosts = hosts

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        destination = trusted_url_parts(newurl)
        if destination is None or destination.hostname.lower() not in self.hosts:
            fail("target MCP Adapter redirect host is not certified")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def fetch_certified_archive(url: str, hosts: frozenset[str], timeout: int, expected_bytes: int) -> bytes:
    opener = urllib.request.build_opener(CertifiedRedirectPolicy(hosts))
    request = urllib.request.Request(url, headers={"User-Agent": "MAD4B-Certified-Adapter-CI/1"})
    with opener.open(request, timeout=max(5, min(timeout, 60))) as response:
        final = trusted_url_parts(response.geturl())
        if final is None or final.hostname.lower() not in hosts:
            fail("target MCP Adapter final origin is not certified")
        return response.read(expected_bytes + 1)


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
    if (len(expected_sha) != 64 or not re.fullmatch(r"[a-f0-9]{64}", expected_sha)
            or expected_bytes < 1 or expected_bytes > MAX_ADAPTER_BYTES):
        fail("target MCP Adapter profile identity invalid")
    allowed_hosts = reviewed_source(profile, version)
    payload = fetch_certified_archive(url, allowed_hosts, args.timeout, expected_bytes)

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
