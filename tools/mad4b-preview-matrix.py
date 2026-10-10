#!/usr/bin/env python3
"""Source-pinned Staging evidence: real Chromium, Customizer iframe, WordPress CLI.

Non-authorizing: never signs a work queue receipt, applies settings, publishes a
Customizer changeset, or upgrades internal observations to external acceptance.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
from urllib.parse import urlparse, urlencode

CONTRACT = "mad4b.preview-matrix-evidence.v1"
MODES = ("browser", "customizer", "native")
SHA = re.compile(r"^[a-f0-9]{40}$")
SAFE_PATH = re.compile(r"^/[a-zA-Z0-9/_.-]{0,159}$")
PROBE = re.compile(r"^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$")


def origin(text):
    u = urlparse(text)
    if (u.scheme != "https" or not u.hostname or u.hostname in ("localhost", "127.0.0.1")
            or u.port is not None or u.username or u.password or u.query or u.fragment
            or u.path not in ("", "/")):
        raise ValueError("EXACT_HTTPS_ORIGIN_REQUIRED")
    return "https://" + u.hostname.lower()


def checked_path(path):
    if not SAFE_PATH.fullmatch(path) or ".." in path or "//" in path:
        raise ValueError("UNSAFE_SAME_ORIGIN_PATH")
    return path


def checked_probe_url(text, base):
    u = urlparse(text)
    if (u.scheme != "https" or u.netloc.lower() != urlparse(base).netloc
            or u.fragment or u.username or u.password):
        raise ValueError("FOREIGN_PROBE_URL")
    checked_path(u.path or "/")
    items = u.query.split("&")
    if len(items) != 1 or not items[0].startswith("mad4b_frontend_probe="):
        raise ValueError("PROBE_REQUEST_NOT_BOUND")
    if not PROBE.fullmatch(items[0].split("=", 1)[1]):
        raise ValueError("INVALID_PROBE_IDENTIFIER")
    return text


def checked_auth_state(path, base):
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(data, dict) or not isinstance(data.get("cookies", []), list):
        raise ValueError("AUTH_STORAGE_INVALID")
    host = urlparse(base).hostname
    for item in data["cookies"]:
        if str(item.get("domain", "")).lower().lstrip(".") != host:
            raise ValueError("CROSS_SITE_AUTH_COOKIE")
    for item in data.get("origins", []):
        if origin(item.get("origin", "")) != base:
            raise ValueError("CROSS_SITE_LOCAL_STORAGE")


def report_summary(rows):
    groups = {mode: [row for row in rows if row["mode"] == mode] for mode in MODES}
    return {
        "observed_by_mode": {key: len(value) for key, value in groups.items()},
        "browser_http_200": bool(groups["browser"]) and all(
            row.get("http_status") == 200 for row in groups["browser"]),
        "customizer_iframe_same_origin": bool(groups["customizer"]) and all(
            row.get("iframe_same_origin") is True for row in groups["customizer"]),
        "native_cli_observed": bool(groups["native"]) and all(
            row.get("native_state") == "observed" for row in groups["native"]),
        "external_signed_receipt": False,
        "production_unchanged_proven": False,
        "release_certified": False,
    }


def browser_observations(args, base):
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:
        raise RuntimeError("PLAYWRIGHT_REQUIRED: pip install playwright; playwright install chromium") from exc
    urls = [base + checked_path(item) for item in args.paths]
    if args.probe_url:
        urls = [checked_probe_url(args.probe_url, base)]
    if "customizer" in args.modes and not args.storage_state:
        raise ValueError("CUSTOMIZER_REQUIRES_SAME_ORIGIN_AUTH_STATE")
    if args.storage_state:
        checked_auth_state(args.storage_state, base)

    output = []
    with sync_playwright() as play:
        browser = play.chromium.launch(headless=not args.headed)
        context = browser.new_context(storage_state=args.storage_state or None,
                                      service_workers="block", viewport={"width": 1365, "height": 900})
        context.set_default_timeout(args.timeout_ms)
        for mode in args.modes:
            if mode == "native":
                continue
            for target_url in urls:
                for index in range(args.samples):
                    page = context.new_page()
                    errors, xhr, foreign = [], [], [0]
                    def route_handler(route):
                        u = urlparse(route.request.url)
                        if u.scheme not in ("http", "https") or u.netloc.lower() != urlparse(base).netloc:
                            foreign[0] += 1
                            route.abort()
                        else:
                            route.continue_()
                    def response_handler(response):
                        if (response.request.resource_type in ("xhr", "fetch") and
                                urlparse(response.url).netloc.lower() == urlparse(base).netloc):
                            xhr.append(response.status)
                    page.route("**/*", route_handler)
                    page.on("pageerror", lambda ignored: errors.append(1))
                    page.on("response", response_handler)
                    started = time.perf_counter()
                    result = {
                        "mode": mode, "path": urlparse(target_url).path or "/",
                        "sample_index": index + 1, "http_status": None,
                        "iframe_same_origin": None, "js_error_count": None,
                        "ajax_request_count": None, "ajax_5xx_count": None,
                        "browser_elapsed_ms": None, "foreign_requests_blocked": None,
                        "server_elapsed_ms": None, "db_queries": None,
                        "peak_memory_bytes": None, "accepted_as_frontend_http": mode == "browser",
                        "accepted_as_external_signed_receipt": False,
                    }
                    try:
                        request_url = (base + "/wp-admin/customize.php?" +
                                       urlencode({"url": target_url})) if mode == "customizer" else target_url
                        response = page.goto(request_url, wait_until="domcontentloaded", timeout=args.timeout_ms)
                        result["http_status"] = response.status if response else None
                        if urlparse(page.url).netloc.lower() != urlparse(base).netloc:
                            raise ValueError("CROSS_ORIGIN_REDIRECT")
                        if mode == "customizer":
                            iframe = page.locator("#customize-preview-iframe")
                            iframe.wait_for(state="attached", timeout=args.timeout_ms)
                            frame = iframe.element_handle().content_frame()
                            result["iframe_same_origin"] = bool(
                                frame and urlparse(frame.url).netloc.lower() == urlparse(base).netloc)
                            if not result["iframe_same_origin"]:
                                raise ValueError("CUSTOMIZER_PREVIEW_NOT_SAME_ORIGIN")
                        elif args.scroll:
                            page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
                            page.wait_for_timeout(750)
                    except Exception as error:
                        result["observation_error"] = type(error).__name__[:64]
                    finally:
                        result["browser_elapsed_ms"] = round((time.perf_counter() - started) * 1000, 2)
                        result["js_error_count"] = len(errors)
                        result["ajax_request_count"] = len(xhr)
                        result["ajax_5xx_count"] = sum(code >= 500 for code in xhr)
                        result["foreign_requests_blocked"] = foreign[0]
                        output.append(result)
                        page.close()
        browser.close()
    return output


def native_observation(args, base):
    if not args.wp_root:
        raise ValueError("NATIVE_WP_ROOT_REQUIRED")
    root = Path(args.wp_root).resolve()
    if not (root / "wp-config.php").is_file():
        raise ValueError("WORDPRESS_ROOT_MISSING")
    target = Path(__file__).with_name("mad4b-preview-native-probe.php")
    if not target.is_file():
        raise ValueError("NATIVE_PROBE_SCRIPT_MISSING")
    env = dict(os.environ, MAD4B_PREVIEW_EXPECTED_ORIGIN=base,
               MAD4B_PREVIEW_EXPECTED_SHA=args.expected_source_sha,
               MAD4B_PREVIEW_NATIVE_REST="1" if args.native_rest else "0")
    try:
        process = subprocess.run(
            [args.wp_cli, "--path=" + str(root), "eval-file", str(target)],
            cwd=str(root), env=env, capture_output=True, text=True, timeout=60, check=False)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise RuntimeError("NATIVE_WP_CLI_UNAVAILABLE") from error
    if process.returncode:
        raise RuntimeError("NATIVE_PROBE_EXIT_" + str(process.returncode))
    try:
        observation = json.loads(process.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError("NATIVE_PROBE_NON_JSON_OUTPUT") from error
    if (observation.get("contract") != "mad4b.wp-native-preview-evidence.v1"
            or observation.get("source_commit_sha") != args.expected_source_sha):
        raise RuntimeError("NATIVE_IDENTITY_MISMATCH")
    return [{
        "mode": "native", "native_state": "observed", "http_status": None,
        "server_elapsed_ms": observation["server_elapsed_ms"],
        "db_queries": observation["db_queries"],
        "peak_memory_bytes": observation["peak_memory_bytes"],
        "theme_slug": observation["theme_slug"], "theme_mod_count": observation["theme_mod_count"],
        "rest": observation["rest"], "accepted_as_frontend_http": False,
        "accepted_as_external_signed_receipt": False,
    }]


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--expected-source-sha", required=True)
    parser.add_argument("--modes", nargs="+", choices=MODES, default=["browser"])
    parser.add_argument("--paths", nargs="+", default=["/"])
    parser.add_argument("--samples", type=int, default=3)
    parser.add_argument("--probe-url", help="Only exact same-origin UUID-bound queued probe URL")
    parser.add_argument("--storage-state", help="Local same-origin Playwright session; never in evidence")
    parser.add_argument("--wp-root", help="Staging WordPress root for on-host wp eval-file")
    parser.add_argument("--wp-cli", default="wp")
    parser.add_argument("--native-rest", action="store_true", help="Allowlisted core GET /wp/v2/types")
    parser.add_argument("--scroll", action="store_true")
    parser.add_argument("--headed", action="store_true")
    parser.add_argument("--timeout-ms", type=int, default=25000)
    parser.add_argument("--output", required=True)
    args = parser.parse_args(argv)
    if not SHA.fullmatch(args.expected_source_sha):
        parser.error("Exact lowercase 40-hex source SHA required")
    if not 1 <= args.samples <= 5 or len(args.paths) > 5 or not 5000 <= args.timeout_ms <= 60000:
        parser.error("Samples 1..5; paths up to 5; timeout 5..60 seconds")
    if len(set(args.modes)) != len(args.modes):
        parser.error("Duplicate modes")
    for item in args.paths:
        checked_path(item)
    origin(args.origin)
    return args


def main(argv=None):
    args = parse_args(argv)
    base = origin(args.origin)
    out_path = Path(args.output).resolve()
    if out_path.exists():
        raise ValueError("OUTPUT_ALREADY_EXISTS")
    rows = []
    if "browser" in args.modes or "customizer" in args.modes:
        rows += browser_observations(args, base)
    if "native" in args.modes:
        rows += native_observation(args, base)
    report = {
        "contract": CONTRACT, "observed_at": datetime.now(timezone.utc).isoformat(),
        "origin": base, "expected_source_sha": args.expected_source_sha,
        "modes": args.modes, "rows": rows, "summary": report_summary(rows),
        "evidence_class": "LOCAL_READ_ONLY_NON_AUTHORIZING",
        "remote_queue_completion_performed": False, "external_mcp_attestation_performed": False,
        "limitations": [
            "WordPress CLI startup and REST dispatch differ from a real HTTP frontend",
            "Customizer preview uses authenticated iframe and is not a visitor performance sample",
            "Browser navigation timing is not PHP server_elapsed_ms or TTFB",
            "Production unchanged and signed external work acceptance require separate authorities",
        ],
    }
    out_path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(report, sort_keys=True, ensure_ascii=False, indent=2) + "\n"
    out_path.write_text(payload, encoding="utf-8")
    print(json.dumps({"state": "EVIDENCE_CAPTURED_NOT_CERTIFIED", "path": str(out_path),
                      "rows": len(rows), "sha256": hashlib.sha256(payload.encode("utf-8")).hexdigest(),
                      "summary": report["summary"]}, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError) as error:
        print("BLOCKED: " + str(error), file=sys.stderr)
        sys.exit(2)
