#!/usr/bin/env python3
"""Explicitly configured public HTTPS GET observations; no default sites or acceptance claims."""
import argparse
import http.client
import ipaddress
import json
import os
import re
import socket
import ssl
import time
import urllib.parse
from html.parser import HTMLParser
from pathlib import Path

MAX_BODY = 200_000
MAX_TARGETS = 5
MAX_REQUESTS = 20
DEFAULT_ROUTES = (("homepage", "/"), ("rest_root", "/wp-json/"), ("robots", "/robots.txt"))


def validate_targets(value):
    if not isinstance(value, list) or len(value) > MAX_TARGETS:
        raise ValueError("Expected at most five explicitly approved Staging targets")
    ids, origins, result, total = set(), set(), [], 0
    for item in value:
        if not isinstance(item, dict) or set(item) - {"id", "origin", "environment", "routes"}:
            raise ValueError("Invalid target fields")
        ident, origin = item.get("id"), item.get("origin")
        if not isinstance(ident, str) or not re.fullmatch(r"[a-z][a-z0-9_-]{0,39}", ident) or ident in ids:
            raise ValueError("Unique safe target ID required")
        if item.get("environment") != "staging" or not isinstance(origin, str):
            raise ValueError("Explicit Staging identity required")
        u = urllib.parse.urlsplit(origin)
        if (u.scheme != "https" or not u.hostname or u.netloc != u.hostname
                or u.path or u.query or u.fragment or origin != "https://" + u.hostname
                or not re.fullmatch(r"[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?", u.hostname)
                or ".." in u.hostname or origin in origins):
            raise ValueError("Exact HTTPS origin without credentials, port or path required")
        routes = item.get("routes", [list(r) for r in DEFAULT_ROUTES])
        if not isinstance(routes, list) or not 1 <= len(routes) <= 6:
            raise ValueError("One to six explicitly approved public routes required")
        clean, labels, paths = [], set(), set()
        for route in routes:
            if not isinstance(route, (list, tuple)) or len(route) != 2:
                raise ValueError("Route must contain a label and relative path")
            label, path = route
            if not isinstance(label, str) or not re.fullmatch(r"[a-z][a-z0-9_-]{0,39}", label) or label in labels:
                raise ValueError("Invalid or duplicate route label")
            if (not isinstance(path, str) or not re.fullmatch(r"/[A-Za-z0-9/_.-]*", path)
                    or "//" in path or any(part in (".", "..") for part in path.split("/")) or path in paths or len(path) > 240
                    or path.startswith(("/wp-admin", "/wp-login", "/wp-cron", "/xmlrpc"))):
                raise ValueError("Bounded public relative paths only; no admin, query, fragment or escapes")
            labels.add(label); paths.add(path); clean.append((label, path))
        total += len(clean)
        if total > MAX_REQUESTS:
            raise ValueError("Request budget exceeded")
        ids.add(ident); origins.add(origin)
        result.append({"id": ident, "origin": origin, "environment": "staging", "routes": clean})
    return result


def public_endpoint(host):
    rows = socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)
    addresses = [row[4][0] for row in rows]
    if not addresses or any(not ipaddress.ip_address(ip).is_global for ip in addresses):
        raise ValueError("Public DNS addresses required")
    return addresses[0]


class PinnedHTTPSConnection(http.client.HTTPSConnection):
    """Pin the validated address while retaining TLS hostname verification."""
    def __init__(self, host, address):
        super().__init__(host, timeout=12, context=ssl.create_default_context())
        self.address = address

    def connect(self):
        sock = socket.create_connection((self.address, 443), self.timeout)
        try:
            self.sock = self._context.wrap_socket(sock, server_hostname=self.host)
        except Exception:
            sock.close()
            raise


class LinkMetadata(HTMLParser):
    def __init__(self):
        super().__init__()
        self.canonicals = []
        self.hreflang = []
        self.robots = []

    def handle_starttag(self, tag, attrs):
        if tag.lower() not in ("link", "meta"):
            return
        a = dict((k.lower(), v or "") for k, v in attrs)
        if tag.lower() == "link":
            rel = a.get("rel", "").lower().split()
            if "canonical" in rel:
                self.canonicals.append(a.get("href", "")[:1000])
            if "alternate" in rel and a.get("hreflang"):
                self.hreflang.append(a["hreflang"][:40])
        elif a.get("name", "").lower() == "robots":
            self.robots.append(a.get("content", "")[:180])


def fetch(label, path, *, target):
    # Validate at the I/O boundary too; callers cannot substitute a route/origin.
    approved = validate_targets([target])[0]
    if (label, path) not in approved["routes"]:
        raise ValueError("Route not in this target's approved scope")
    host = urllib.parse.urlsplit(approved["origin"]).hostname
    start, code, body, headers, error_class = time.monotonic(), 0, b"", {}, ""
    client = None
    try:
        client = PinnedHTTPSConnection(host, public_endpoint(host))
        client.request("GET", path, headers={"User-Agent": "MAD4B-Staging-ReadOnly/2.0",
                       "Accept": "text/html,application/json;q=0.8", "Cache-Control": "no-cache, no-store"})
        response = client.getresponse()
        code, body, headers = response.status, response.read(MAX_BODY), dict(response.getheaders())
    except Exception as exc:
        error_class = type(exc).__name__[:90]
    finally:
        if client is not None:
            client.close()
    low = {k.lower(): v for k, v in headers.items()}
    parsed = LinkMetadata()
    if "text/html" in low.get("content-type", "").lower() and body:
        parsed.feed(body.decode("utf-8", "replace"))
    row = {"route": label, "http_status": code,
           "elapsed_ms": round((time.monotonic() - start) * 1000, 1), "error_class": error_class,
           "content_type": low.get("content-type", "").split(";")[0][:80],
           "cache_headers_present": any(k in low for k in ("x-hcdn-cache-status", "x-litespeed-cache", "x-cache")),
           "hcdn_challenge": bool(re.search(b"hcdn-cgi/jschallenge|Checking your browser before accessing", body[:2500], re.I)),
           "canonical_count": len(parsed.canonicals),
           "canonical_same_origin": bool(parsed.canonicals) and all(urllib.parse.urlsplit(h).hostname == host for h in parsed.canonicals),
           "hreflang_count": len(parsed.hreflang), "hreflang_languages": sorted(set(parsed.hreflang))[:15],
           "robots_meta_present": bool(parsed.robots), "response_body_retained": False}
    print(f"{label}: HTTP {code} error={error_class or 'none'}")
    return row


def main(targets, output_dir="."):
    targets = validate_targets(targets)  # Validate the entire batch before any request.
    if not targets:
        print("NOT_CONFIGURED: no approved targets; zero network requests")
        return []
    folder = Path(output_dir)
    folder.mkdir(parents=True, exist_ok=True)
    records = []
    for target in targets:
        record = {"contract": "mad4b.staging-external-public-smoke.v3", "site": target["origin"],
                  "site_id": target["id"], "scope": "configured_origin_public_https_only",
                  "mutation_performed": False, "read_only": True, "browser_runtime_executed": False,
                  "site_acceptance_certified": False, "authority_granted": False, "seo_publication_performed": False,
                  "observations": [fetch(label, path, target=target) for label, path in target["routes"]]}
        (folder / (target["id"] + "-staging-public-smoke.json")).write_text(json.dumps(record, indent=2) + "\n")
        records.append(record)
    print("EVIDENCE_ONLY: observations do not authorize Staging or Production")
    return records


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", default=".")
    args = parser.parse_args()
    raw = (os.environ.get("MAD4B_STAGING_PUBLIC_PROBE_TARGETS_JSON") or "[]")
    if len(raw) > 16384:
        raise SystemExit("Target configuration exceeds size limit")
    main(json.loads(raw), args.output_dir)
