#!/usr/bin/env python3
"""Read-only external ETG Staging origin/status/canonical/hreflang evidence.

No cookies, authentication, request bodies, redirects, site writes, or browser
acceptance assertions. HTTP 5xx and WAF challenges remain visible evidence,
not an automatic project-release acceptance or a CI-infrastructure failure.
"""
import json
import re
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

ORIGIN = "https://staging.egypttourgates.com"
ROUTES = (
    ("homepage", "/"),
    ("tours_archive", "/tours-and-activities/"),
    ("rest_root", "/wp-json/"),
    ("wpml_rest", "/wp-json/wpml/v1/rest/status?test_get_parameter=1"),
    ("robots", "/robots.txt"),
)
ALLOWED_HOST = "staging.egypttourgates.com"
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


def fetch(label, path):
    candidate = urllib.parse.urlsplit(ORIGIN + path)
    assert candidate.scheme == "https" and candidate.hostname == ALLOWED_HOST
    assert candidate.port is None and not candidate.username and not candidate.password
    assert path.startswith("/") and not path.startswith("//")
    start = time.monotonic()
    code = 0
    body = b""
    headers = {}
    error_class = ""
    try:
        request = urllib.request.Request(
            ORIGIN + path,
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
            urllib.parse.urlsplit(h).hostname == ALLOWED_HOST for h in parsed.canonicals
        ),
        "hreflang_count": len(parsed.hreflang),
        "hreflang_languages": sorted(set(parsed.hreflang))[:15],
        "robots_meta_present": bool(parsed.robots),
        "response_body_retained": False,
    }
    print(f"{label}: HTTP {code} {row['elapsed_ms']}ms error={error_class or 'none'} "
          f"canonical={row['canonical_count']} hreflang={row['hreflang_count']}")
    return row


def main():
    result = {
        "contract": "mad4b.etg-external-public-smoke.v1",
        "site": ORIGIN,
        "scope": "fixed_origin_public_http_only",
        "mutation_performed": False,
        "read_only": True,
        "browser_runtime_executed": False,
        "indexflow_browser_acceptance": False,
        "site_acceptance_certified": False,
        "authority_granted": False,
        "seo_publication_performed": False,
        "observations": [fetch(label, path) for label, path in ROUTES],
    }
    Path("etg-staging-public-smoke.json").write_text(json.dumps(result, indent=2) + "\n")
    assert len(result["observations"]) == len(ROUTES)
    print("EVIDENCE_ONLY: public HTTP observations do not authorize Staging or Production.")


if __name__ == "__main__":
    main()
