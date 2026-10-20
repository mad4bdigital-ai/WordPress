#!/usr/bin/env python3
"""Read-only external ETG Staging origin/status/canonical/hreflang evidence.

No cookies, authentication, request bodies, redirects, site writes, or browser
acceptance assertions. HTTP 5xx and WAF challenges remain visible evidence,
not an automatic project-release acceptance or a CI-infrastructure failure.
"""
import argparse
import json
import re
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

SITES = {
    "etg": {
        "origin": "https://staging.egypttourgates.com",
        "routes": (
            ("homepage", "/"), ("tours_archive", "/tours-and-activities/"),
            ("rest_root", "/wp-json/"),
            ("wpml_rest", "/wp-json/wpml/v1/rest/status?test_get_parameter=1"),
            ("robots", "/robots.txt"),
        ),
    },
    "are": {
        "origin": "https://staging.allroyalegypt.com",
        "routes": (("homepage", "/"), ("rest_root", "/wp-json/"), ("robots", "/robots.txt")),
    },
}
# Preserve the earlier ETG-only API as a stable default for imports.
ORIGIN = SITES["etg"]["origin"]
ROUTES = SITES["etg"]["routes"]
ALLOWED_HOST = urllib.parse.urlsplit(ORIGIN).hostname
MAX_BODY = 200_000


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


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def fetch(label, path, *, origin=None):
    origin = ORIGIN if origin is None else origin
    allowed_hosts = {item["origin"] for item in SITES.values()}
    if origin not in allowed_hosts:
        raise ValueError("Site identity not enrolled in fixed read-only origins")
    host = urllib.parse.urlsplit(origin).hostname
    candidate = urllib.parse.urlsplit(origin + path)
    assert candidate.scheme == "https" and candidate.hostname == host
    assert candidate.port is None and not candidate.username and not candidate.password
    assert path.startswith("/") and not path.startswith("//")
    start = time.monotonic()
    code = 0
    body = b""
    headers = {}
    error_class = ""
    try:
        request = urllib.request.Request(
            origin + path,
            method="GET",
            headers={
                "User-Agent": "MAD4B-ETG-Staging-ReadOnly/1.0",
                "Accept": "text/html,application/json;q=0.8",
                "Cache-Control": "no-cache, no-store",
            },
        )
        client = urllib.request.build_opener(
            NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context())
        )
        try:
            with client.open(request, timeout=12) as result:
                code, body, headers = result.status, result.read(MAX_BODY), dict(result.headers)
        except urllib.error.HTTPError as exc:
            code, body, headers = exc.code, exc.read(MAX_BODY), dict(exc.headers)
    except Exception as exc:
        error_class = type(exc).__name__[:90]
    low = {k.lower(): v for k, v in headers.items()}
    is_html = "text/html" in low.get("content-type", "").lower()
    parsed = LinkMetadata()
    if is_html and body:
        parsed.feed(body.decode("utf-8", "replace"))
    challenge = bool(re.search(b"hcdn-cgi/jschallenge|Checking your browser before accessing", body[:2500], re.I))
    row = {
        "route": label,
        "http_status": code,
        "elapsed_ms": round((time.monotonic() - start) * 1000, 1),
        "error_class": error_class,
        "content_type": low.get("content-type", "").split(";")[0][:80],
        "cache_headers_present": any(k in low for k in ("x-hcdn-cache-status", "x-litespeed-cache", "x-cache")),
        "hcdn_challenge": challenge,
        "canonical_count": len(parsed.canonicals),
        "canonical_same_origin": bool(parsed.canonicals) and all(
            urllib.parse.urlsplit(h).hostname == host for h in parsed.canonicals
        ),
        "hreflang_count": len(parsed.hreflang),
        "hreflang_languages": sorted(set(parsed.hreflang))[:15],
        "robots_meta_present": bool(parsed.robots),
        "response_body_retained": False,
    }
    print(f"{label}: HTTP {code} {row['elapsed_ms']}ms error={error_class or 'none'} "
          f"canonical={row['canonical_count']} hreflang={row['hreflang_count']}")
    return row


def main(site="etg"):
    if site not in SITES:
        raise ValueError("Unknown fixed Staging site")
    site_config = SITES[site]
    origin = site_config["origin"]
    routes = site_config["routes"]
    result = {
        "contract": "mad4b.staging-external-public-smoke.v2",
        "site": origin,
        "site_id": site,
        "scope": "fixed_origin_public_http_only",
        "mutation_performed": False,
        "read_only": True,
        "browser_runtime_executed": False,
        "indexflow_browser_acceptance": False,
        "site_acceptance_certified": False,
        "authority_granted": False,
        "seo_publication_performed": False,
        "observations": [fetch(label, path, origin=origin) for label, path in routes],
    }
    Path(f"{site}-staging-public-smoke.json").write_text(json.dumps(result, indent=2) + "\n")
    assert len(result["observations"]) == len(routes)
    print("EVIDENCE_ONLY: public HTTP observations do not authorize Staging or Production.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Read-only fixed-route Staging HTTP observations")
    parser.add_argument("--site", choices=sorted(SITES), default="etg")
    main(parser.parse_args().site)
