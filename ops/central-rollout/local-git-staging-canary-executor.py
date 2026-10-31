#!/usr/bin/env python3
"""One bounded, real native Local Git Staging plugin release acceptance.

This utility ONLY operates on the exact, inactive MAD4B test plugin identified
below. It is not a generic installer, production capability or package signer.
No caller-controlled repo, path, site, command or PASS is accepted.
"""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import time
import zipfile

CONTAINER = "etg-commerce-staging-php"
WP = ["docker", "exec", "-u", "www-data", CONTAINER, "wp",
      "--path=/var/www/html", "--skip-themes", "--user=1"]
PLUGIN = "mad4b-deploy-canary"
MAIN = PLUGIN + "/" + PLUGIN + ".php"
REPO = Path("/opt/staging/mad4b-local-git-canary-20261031/source")
ARTIFACT_DIR = Path("/opt/staging/mad4b-local-git-canary-20261031/artifacts")
JOURNAL = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/native-ability-authority/central-release-readbacks")
PRIVATE_KEY = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/credentials/central-release-issuer-private.pem")
PUBLIC_KEY = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/native-ability-authority/central-release-issuer-public.pem")
STAGING = "https://tours.dev.mad4b.com"
RECEIPT = "mad4b.central-staging-release-readback.v1"
ENVELOPE = "mad4b.central-release-evidence-envelope.v1"

def command(args: list[str], *, input_bytes: bytes | None = None) -> str:
    result = subprocess.run(args, input=input_bytes, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=90)
    if result.returncode:
        raise RuntimeError("COMMAND_FAILED " + repr(args[:6]) + " " +
                           result.stderr.decode(errors="replace")[-800:])
    return result.stdout.decode().strip()

def wp(*args: str) -> str:
    return command(WP + list(args))

def canonical(data: object) -> bytes:
    return json.dumps(data, sort_keys=True, ensure_ascii=False,
                      separators=(",", ":")).encode("utf-8")

def assert_site() -> tuple[str, str]:
    site = json.loads(wp("eval",
        'echo wp_json_encode(["origin"=>home_url(),"env"=>wp_get_environment_type(),'
        '"php"=>PHP_VERSION,"wp"=>get_bloginfo("version")],JSON_UNESCAPED_SLASHES);'))
    if site["origin"] != STAGING or site["env"] != "staging":
        raise RuntimeError("Wrong WordPress site or environment")
    if not PRIVATE_KEY.is_file() or PRIVATE_KEY.stat().st_mode & 0o077:
        raise RuntimeError("Host private signing key missing or over-permissive")
    if not JOURNAL.is_dir():
        raise RuntimeError("Host-owned release readback journal missing")
    return site["php"], site["wp"]

def plugin_installed() -> bool:
    result = subprocess.run(WP + ["plugin", "is-installed", PLUGIN],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                            timeout=45)
    return result.returncode == 0

def independent_readback(expected_main_sha: str) -> None:
    script = (
      '$p=WP_PLUGIN_DIR."/mad4b-deploy-canary/mad4b-deploy-canary.php";'
      'require_once ABSPATH."wp-admin/includes/plugin.php";'
      '$x=get_plugins();'
      'echo wp_json_encode(["file_exists"=>is_file($p),'
      '"sha"=>is_file($p)?hash_file("sha256",$p):"",'
      '"version"=>$x["mad4b-deploy-canary/mad4b-deploy-canary.php"]["Version"]??"",'
      '"active"=>is_plugin_active("mad4b-deploy-canary/mad4b-deploy-canary.php")],'
      'JSON_UNESCAPED_SLASHES);'
    )
    obs = json.loads(wp("eval", script))
    if obs != {"file_exists": True, "sha": expected_main_sha,
               "version": "0.1.0", "active": False}:
        raise RuntimeError("Installed files/version/activation readback mismatch: " + repr(obs))

def build_artifact() -> tuple[str, Path, str, str]:
    if not REPO.is_dir() or not (REPO / ".git").is_dir() or REPO.is_symlink():
        raise RuntimeError("Owner-registered local Git source absent")
    if REPO.stat().st_uid != 0:
        raise RuntimeError("Local Git source must be host-root-owned")
    if command(["git", "-C", str(REPO), "status", "--porcelain"]) != "":
        raise RuntimeError("Local Git worktree is dirty")
    sha = command(["git", "-C", str(REPO), "rev-parse", "HEAD"])
    if len(sha) != 40 or any(c not in "0123456789abcdef" for c in sha):
        raise RuntimeError("Invalid exact Git source commit")
    ARTIFACT_DIR.mkdir(parents=True, exist_ok=True)
    temp_zip = ARTIFACT_DIR / ("canary-" + sha + ".zip")
    if not temp_zip.is_file():
        command(["git", "-C", str(REPO), "archive", "--format=zip",
                 "--prefix=" + PLUGIN + "/", "-o", str(temp_zip),
                 sha, PLUGIN + ".php"])
    with zipfile.ZipFile(temp_zip) as z:
        names = z.namelist()
        if names != [MAIN]:
            raise RuntimeError("Unexpected archive entries")
        info = z.getinfo(MAIN)
        if (info.external_attr >> 16) & 0o170000 == 0o120000:
            raise RuntimeError("Archive symlink forbidden")
        if info.file_size > 65536:
            raise RuntimeError("Canary source exceeds file size limit")
        source = z.read(MAIN)
    if not source.startswith(b"<?php") or b"Plugin Name: MAD4B Native Deploy Canary" not in source:
        raise RuntimeError("Unexpected plugin source")
    artifact_sha = hashlib.sha256(temp_zip.read_bytes()).hexdigest()
    file_sha = hashlib.sha256(source).hexdigest()
    manifest_sha = hashlib.sha256(canonical([{"path": MAIN, "sha256": file_sha}])).hexdigest()
    return sha, temp_zip, artifact_sha, manifest_sha

def perform_native_cycle(artifact: Path, expected_sha: str) -> None:
    if plugin_installed():
        raise RuntimeError("Canary plugin already installed; never overwrite")
    container_zip = "/tmp/mad4b-native-git-canary-" + artifact.name
    command(["docker", "cp", str(artifact), CONTAINER + ":" + container_zip])
    try:
        # All changes are limited to the exact uninstalled canary plugin.
        wp("plugin", "install", container_zip)
        independent_readback(expected_sha)
        wp("plugin", "delete", PLUGIN)  # Real rollback to absent baseline.
        if plugin_installed():
            raise RuntimeError("Rollback failed: canary still installed")
        wp("plugin", "install", container_zip)
        independent_readback(expected_sha)  # Final same-artifact reinstallation.
    except Exception:
        if plugin_installed():
            try:
                wp("plugin", "delete", PLUGIN)
            except Exception:
                pass
        raise
    finally:
        subprocess.run(["docker", "exec", CONTAINER, "rm", "-f", container_zip],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)

def sign_verified_receipt(commit: str, archive_sha: str, manifest_sha: str,
                          php_min: str, wp_min: str) -> dict:
    record = {
        "contract": RECEIPT,
        "staging_origin": STAGING,
        "environment": "staging",
        "source_type": "local_git",
        "source_commit": commit,
        "artifact_sha256": archive_sha,
        "file_manifest_sha256": manifest_sha,
        "staging_pass": True,
        "tested_at": int(time.time()),
        "checks": {"install": True, "smoke": True, "rollback": True},
        "requirements": {
            "target_plugin_file": MAIN,
            "target_version": "0.1.0",
            "php_min": php_min,
            "wordpress_min": wp_min,
            "required_plugins": [],
            "requires_schema_migration": False,
        },
    }
    digest = canonical(record)
    signature = subprocess.run(["openssl", "dgst", "-sha256", "-sign", str(PRIVATE_KEY)],
                               input=digest, stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, timeout=20, check=True).stdout
    result = subprocess.run(["openssl", "dgst", "-sha256", "-verify", str(PUBLIC_KEY),
                             "-signature", "/dev/stdin"], input=signature,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    # /dev/stdin cannot serve both data and signature; use a separate temp
    # signature file and verify exactly the canonical bytes.
    import tempfile
    with tempfile.TemporaryDirectory(prefix="mad4b-canary-sign-") as tmp:
        sp = Path(tmp) / "signature"
        dp = Path(tmp) / "payload"
        sp.write_bytes(signature); dp.write_bytes(digest)
        command(["openssl", "dgst", "-sha256", "-verify", str(PUBLIC_KEY),
                 "-signature", str(sp), str(dp)])
    import base64
    envelope = {"contract": ENVELOPE, "receipt": record,
                "signature_b64": base64.b64encode(signature).decode("ascii")}
    dest = JOURNAL / (archive_sha + ".json")
    if dest.exists():
        raise RuntimeError("Readback already exists; never overwrite signed evidence")
    tmp = JOURNAL / ("." + archive_sha + "." + str(os.getpid()) + ".tmp")
    try:
        with open(tmp, "x", encoding="utf-8") as out:
            os.fchmod(out.fileno(), 0o644)
            json.dump(envelope, out, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
            out.write("\n")
            out.flush(); os.fsync(out.fileno())
        os.replace(tmp, dest)
    finally:
        if tmp.exists(): tmp.unlink()
    return {"artifact_sha256": archive_sha, "source_commit": commit,
            "journal_path": str(dest), "receipt_contract": RECEIPT}

def main() -> None:
    if sys.argv[1:] != ["run-registered-staging-canary"]:
        raise RuntimeError("Only the bounded registered Staging canary operation is supported")
    php_version, wp_version = assert_site()
    commit, archive, archive_sha, manifest_sha = build_artifact()
    if (JOURNAL / (archive_sha + ".json")).exists():
        raise RuntimeError("Release record exists; no replay")
    with zipfile.ZipFile(archive) as z:
        file_sha = hashlib.sha256(z.read(MAIN)).hexdigest()
    perform_native_cycle(archive, file_sha)
    receipt = sign_verified_receipt(commit, archive_sha, manifest_sha, php_version, wp_version)
    receipt["installed_canary_inactive"] = True
    receipt["actual_install_smoke_rollback_reinstall"] = "PASS"
    print(json.dumps(receipt, sort_keys=True))

if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("BOUNDED_CANARY_FAILED: " + str(error), file=sys.stderr)
        sys.exit(1)
