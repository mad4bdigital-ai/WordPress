#!/usr/bin/env python3
"""MAD4B Company Management handoff: provision portable Elementor condition optimization.
Dry-run unless --apply. Never guesses tenants, languages, authority or activation.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import tempfile
from urllib.parse import urlsplit

HERE = Path(__file__).resolve().parent
PLUGIN = "mad4b-elementor-display-conditions-gate.php"
POLICY = "mad4b-elementor-condition-gate-policy.json"
CONTRACT = "mad4b.elementor.display-conditions-gate.v1"
COMPANY = re.compile(r"[a-z][a-z0-9-]{2,47}\Z")
HOST = re.compile(r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}\Z")
LANG = re.compile(r"[a-z]{2,3}(?:-[a-z]{2,3})?\Z")


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def plan(args: argparse.Namespace) -> dict:
    site = Path(args.site_root)
    if not site.is_absolute() or site.is_symlink() or site.resolve() != site:
        raise ValueError("site root must be an exact, non-symlink absolute path")
    if not str(site).startswith("/opt/") or site.name not in {"site-copy", "site"}:
        raise ValueError("not an approved VPS WordPress company site root")
    config = site / "wp-config.php"
    wp = site / "wp-includes" / "version.php"
    plugins = site / "wp-content" / "mu-plugins"
    if not config.is_file() or config.is_symlink() or not wp.is_file() or not plugins.is_dir():
        raise ValueError("site root is not an installed WordPress source")
    uri = urlsplit(args.origin)
    if (uri.scheme != "https" or not uri.hostname or uri.hostname != uri.netloc
            or uri.path not in {"", "/"} or uri.query or uri.fragment
            or not HOST.fullmatch(uri.hostname)):
        raise ValueError("exact canonical HTTPS origin required")
    if not COMPANY.fullmatch(args.company):
        raise ValueError("invalid company code")
    if args.environment not in {"staging", "production"}:
        raise ValueError("invalid company environment")
    languages = args.languages.split(",")
    if (not languages or len(languages) > 30
            or len(set(languages)) != len(languages)
            or any(not LANG.fullmatch(x) for x in languages)):
        raise ValueError("invalid WPML language list")

    # A declared WP_HOME is an additional identity guard. A DB-configured home
    # remains independently enforced by the MU plugin at every public request.
    conf = config.read_text(errors="replace")
    matches = re.findall(
        r"define\s*\(\s*['\"]WP_HOME['\"]\s*,\s*['\"](https://[^'\"]+)['\"]\s*\)",
        conf,
    )
    if matches and any(x.rstrip("/") != args.origin.rstrip("/") for x in matches):
        raise ValueError("WP_HOME site-origin mismatch")

    doc = {
        "contract": CONTRACT,
        "site_host": uri.hostname,
        "environment": args.environment,
        "company_code": args.company,
        "languages": languages,
        "status": "canary",
        "routes": "public",
        "active_route_scope": "front_page_only",
        "revision": 1,
    }
    # Works in the standalone company source-owned package and in the
    # WordPress Git repository (ops/elementor-condition-gate/).
    candidates = [
        HERE / PLUGIN,
        HERE.parents[1] / "wp-content" / "mu-plugins" / PLUGIN,
    ]
    plugin = next((f for f in candidates if f.is_file() and not f.is_symlink()), None)
    if plugin is None:
        raise ValueError("canonical source plugin is missing/unsafe")
    expected = plugin.read_bytes()
    if b"Version: 0.6.0" not in expected or len(expected) > 512000:
        raise ValueError("canonical runtime version mismatch")
    deployed_plugin = plugins / PLUGIN
    deployed_policy = plugins / POLICY
    for p in (deployed_plugin, deployed_policy):
        if p.is_symlink():
            raise ValueError("target symlink is not accepted")
    if deployed_plugin.exists() and deployed_plugin.read_bytes() != expected:
        raise ValueError("existing company runtime differs: manual reconciliation required")
    if deployed_policy.exists():
        old = json.loads(deployed_policy.read_text())
        # Existing company-specific status is preserved, never enabled implicitly.
        if (old.get("site_host") != doc["site_host"]
                or old.get("environment") != doc["environment"]
                or old.get("company_code") not in (None, doc["company_code"])):
            raise ValueError("existing policy belongs to another company/environment")
        doc["status"] = old.get("status", "canary")
        doc["revision"] = max(1, int(old.get("revision", 1)))
        if doc["status"] not in {"canary", "active"}:
            raise ValueError("unexpected existing company status")
    data = (json.dumps(doc, ensure_ascii=False, indent=2) + "\n").encode()
    return {
        "contract": "mad4b.company-elementor-gate-enrollment.v1",
        "site_root": str(site),
        "origin": args.origin,
        "company_code": args.company,
        "environment": args.environment,
        "languages": languages,
        "status": doc["status"],
        "runtime_php": str(deployed_plugin),
        "runtime_policy": str(deployed_policy),
        "sha256_runtime_php": sha(expected),
        "sha256_policy": sha(data),
        "_bytes": (expected, data),
    }


def atomic_create_or_verify(path: Path, data: bytes) -> None:
    if path.exists():
        if path.read_bytes() != data:
            # Existing policy is updated only after the caller passed exact
            # identity checks above; activation state was preserved.
            raise FileExistsError("existing file content differs; do not overwrite live policy: " + str(path))
        return
    fd, name = tempfile.mkstemp(prefix=".mad4b-elementor-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(name, 0o644)
        os.replace(name, path)
    finally:
        try:
            os.unlink(name)
        except FileNotFoundError:
            pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site-root", required=True)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--company", required=True)
    parser.add_argument("--environment", required=True, choices=["staging", "production"])
    parser.add_argument("--languages", required=True)
    parser.add_argument("--apply", action="store_true")
    opts = parser.parse_args()
    proof = plan(opts)
    expected, policy = proof.pop("_bytes")
    if opts.apply:
        atomic_create_or_verify(Path(proof["runtime_php"]), expected)
        atomic_create_or_verify(Path(proof["runtime_policy"]), policy)
    proof["state"] = "INSTALLED_OR_VERIFIED" if opts.apply else "READ_ONLY_PLAN"
    proof["no_forced_activation"] = True
    proof["other_company_files_unchanged"] = True
    print(json.dumps(proof, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError, OSError, json.JSONDecodeError, FileExistsError) as exc:
        print(json.dumps({"state": "REFUSED", "reason": str(exc)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(2)
