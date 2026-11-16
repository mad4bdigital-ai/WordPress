#!/usr/bin/env python3
"""Read-only mobile HTML integrity and edge cache evidence.
Do not infer PSI or LCP from FastCGI HIT; these are separate measurements.
"""
import argparse
import gzip
import io
import hashlib
import zlib
import http.client
import urllib.error
import json
import re
import sys
import time
import urllib.request
from pathlib import Path
from urllib.parse import urlsplit

CONTRACT="mad4b.performance-mobile-http-readback.v2"
MAX_BYTES=8*1024*1024
UA="Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36"
# Match the Chromium mobile browser used by the native Critical CSS capture.
# Browser Accept controls LiteSpeed's WebP URL rewriting inside CSS. The
# previous generic Accept silently observed the JPEG-only bundle and caused
# false OBSERVATION_TO_CAPTURE_DRIFT for a valid WebP-capable browser.
# This is ONLY a monitoring input, not a market/cache partition authority.
HTML_ACCEPT="text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8"
CSS_NEGOTIATION_PROFILE="chromium-mobile-webp-v1"

def _read_complete_response(request, expected_origin: str, path: str):
    """Bounded safe-GET retry for truncated/transport-broken 0.9MB HTML.

    Never accept partial HTML, never retry a valid HTTP 4xx/5xx as a success,
    and never change cookies, URL or user/market selection across retries.
    """
    errors=(http.client.IncompleteRead, http.client.RemoteDisconnected,
            urllib.error.URLError, ConnectionResetError, ConnectionAbortedError,
            TimeoutError, EOFError, gzip.BadGzipFile, zlib.error)
    for attempt in range(3):
        try:
            with urllib.request.urlopen(request,timeout=27) as response:
                final=urlsplit(response.geturl())
                if final.hostname!=urlsplit(expected_origin).hostname or final.path!=path:
                    raise ValueError("unexpected_redirect")
                code=response.getcode()
                headers=response.headers
                wire=response.read(MAX_BYTES+1)
            if len(wire)>MAX_BYTES:
                raise ValueError("oversized_wire_response")
            if code!=200:
                raise ValueError("unexpected_http_status")
            encoding=str(headers.get("Content-Encoding","")).lower().strip()
            if encoding=="gzip":
                # Python urllib does NOT decompress gzip automatically.
                # Bound inflated HTML and force EOF to validate gzip CRC
                # and prevent a truncated response from ever passing.
                with gzip.GzipFile(fileobj=io.BytesIO(wire),mode="rb") as gz:
                    body=gz.read(MAX_BYTES+1)
                    if len(body)>MAX_BYTES or gz.read(1):
                        raise ValueError("oversized_html")
            elif encoding in ("","identity"):
                body=wire
            else:
                raise ValueError("unsupported_content_encoding")
            # LiteSpeed legitimately appends short optimization comments
            # after </html>. Require the closing document plus only
            # bounded comments/whitespace, never scripts or extra markup.
            close=body.lower().rfind(b"</html>")
            trailer=body[close+7:] if close>=0 else b""
            if (close<0 or len(trailer)>2048 or
                re.fullmatch(rb"(?:\s*<!--[^<>]{0,1024}-->\s*)*",trailer) is None):
                raise EOFError("incomplete_html_document")
            return code, headers, body, attempt
        except errors:
            if attempt==2:
                raise
            # No silent truncation and no accepting mixed/cached fragments.
            time.sleep(0.25*(attempt+1))
    raise RuntimeError("unreachable")


def _native_full_css_markup(text: str, expected_origin: str) -> bool:
    """Independent HTML shape check in addition to the WordPress SHA proof."""
    host=urlsplit(expected_origin).hostname
    matches=[]
    for tag in re.findall(r'<link\b[^>]*>',text,re.I):
        rel=re.search(r'\brel=["\x27]([^"\x27]+)["\x27]',tag,re.I)
        if not rel or "stylesheet" not in rel.group(1).lower().split():
            continue
        href=re.search(r'\bhref=["\x27]([^"\x27]+)["\x27]',tag,re.I)
        if not href:continue
        url=urlsplit(href.group(1))
        if url.hostname!=host or not re.fullmatch(
            r"/wp-content/litespeed/css/[a-f0-9]{32}\.css",url.path
        ):continue
        if re.search(r"\bonload\s*=",tag,re.I):return False
        media=re.search(r'\bmedia=["\x27]([^"\x27]+)["\x27]',tag,re.I)
        if media and media.group(1).lower()!="all":return False
        matches.append(url.path)
    return len(matches)==1

def audit(policy, rounds=2):
    from performance_release_gate_v2 import canonical_origin
    origin=canonical_origin(policy["origin"])
    if policy.get("contract")!="mad4b.performance-http-policy.v2" or not policy.get("enabled") is True:
        raise ValueError("inactive_or_unknown_company_policy")
    if not isinstance(policy.get("routes"),list) or not 1<=len(policy["routes"])<=8:
        raise ValueError("invalid_routes")
    if rounds not in (1,2):
        raise ValueError("invalid_repetition")
    items=[]
    for entry in policy["routes"]:
        path=entry.get("path")
        if not isinstance(path,str) or not re.fullmatch(r"/(?:[a-z]{2}/)?",path):
            raise ValueError("unsafe_route")
        expected=entry.get("expect_cache")
        if expected not in ("shared_public","private_market_veto"):
            raise ValueError("unknown_cache_mode")
        for run in range(1,rounds+1):
            request=urllib.request.Request(origin+path,headers={
                "User-Agent":UA,"Accept":HTML_ACCEPT,
                # Server-side gzip reduces 0.9MB HTML transport on cold
                # uncached source requests. This does not admit HTML caching.
                "Accept-Encoding":"gzip"})
            start=time.monotonic()
            code,headers,body,retries=_read_complete_response(request,origin,path)
            text=body.decode("utf-8","replace")
            m=re.search(r'<style[^>]*id=["\']mad4b-critical-css-v1["\'][^>]*>(.*?)</style>',text,re.I|re.S)
            status=headers.get("X-Wayfa-Origin-Cache") or headers.get("X-MAD4B-Preset-Cache") or "unknown"
            cache_control=headers.get("Cache-Control","")
            # Match the production Critical CSS document-set fingerprint.
            # A digest, never the rendered HTML or visitor data, enters the ledger.
            body_start=re.search(r'<body\b',text,re.I)
            rendered_doc_ids=sorted({int(x) for x in re.findall(
                r'(?<![a-z0-9_-])elementor-(\d+)\b',
                text[body_start.start():] if body_start else '',re.I)})
            document_sha=hashlib.sha256(','.join(map(str,rendered_doc_ids)).encode()).hexdigest() if body_start else None
            record={
                "path":path,"locale":entry.get("locale"),"run":run,"http_status":code,
                "elementor_doc_fingerprint":document_sha,
                "mobile_css_ready":headers.get("X-MAD4B-Mobile-CSS-Ready"),
                "mobile_css_native_complete":headers.get("X-MAD4B-Mobile-CSS-Native-Complete"),
                "response_total_ms":round((time.monotonic()-start)*1000),
                "edge_cache":status,
                "cache_control_private":"private" in cache_control.lower() or "no-store" in cache_control.lower(),
                "critical_count":text.count('id="mad4b-critical-css-v1"'),
                "async_rest_count":text.count('id="mad4b-css-rest-v1"'),
                "inline_loader_count":text.count('data-mad4b-css-async-lib="inline-v1"'),
                "legacy_extra_loader_count":text.count('data-mad4b-mobile-async="v1"'),
                "critical_css_gzip_bytes":len(gzip.compress((m.group(1) if m else "").encode("utf-8"))),
                "html_bytes":len(body),
                "transport_attempts":retries+1,
                "html_transport_encoding":str(headers.get("Content-Encoding","identity")).lower().strip() or "identity",
                "css_negotiation_profile":CSS_NEGOTIATION_PROFILE,
                "has_hero": "home-mix-mad4b-1600.webp" in text if policy.get("hero_marker") else None,
                "css_bundle":(re.search(r'/litespeed/css/([a-f0-9]{32}\.css)',text).group(1)
                             if re.search(r'/litespeed/css/([a-f0-9]{32}\.css)',text) else None),
            }
            # Critical CSS certificates expire with bundle/epoch changes; the
            # native complete-CSS route is independently checked by the
            # original WordPress CSS-SHA verifier. Never call native fallback
            # a freshly certified Critical CSS variant.
            record["critical_css_contract_ok"]=(code==200 and record["critical_count"]==1 and
                record["async_rest_count"]==1 and record["inline_loader_count"]==1 and
                record["legacy_extra_loader_count"]==0 and record["css_bundle"] is not None and
                headers.get("X-MAD4B-Mobile-CSS-Ready")=="1")
            record["native_css_contract_ok"]=(code==200 and
                headers.get("X-MAD4B-Mobile-CSS-Native-Complete")=="1" and
                headers.get("X-MAD4B-Mobile-CSS-Ready")=="0" and
                record["critical_count"]==0 and record["async_rest_count"]==0 and
                record["legacy_extra_loader_count"]==0 and
                _native_full_css_markup(text,origin))
            record["css_contract_ok"]=(
                record["critical_css_contract_ok"] or record["native_css_contract_ok"])
            record["css_delivery_mode"]=(
                "critical_certified" if record["critical_css_contract_ok"]
                else "native_complete" if record["native_css_contract_ok"]
                else "unverified")
            if policy.get("hero_marker"):
                record["hero_present"]=policy["hero_marker"] in text
            if expected=="shared_public":
                record["cache_contract_ok"]=status in {"HIT","STALE","REVALIDATED"} or (run==1 and status=="MISS")
            else:
                record["cache_contract_ok"]=status in {"MISS","BYPASS"} and record["cache_control_private"]
            record["passed"]=bool(record["css_contract_ok"] and record["cache_contract_ok"] and
                (not policy.get("hero_marker") or record["hero_present"]))
            items.append(record)
    return {"contract":CONTRACT,"company":policy["company"],"origin":origin,
            "state":"passed" if all(x["passed"]for x in items)else "hold",
            "read_only":True,"observations":items,"mutation_performed":False,
            "google_psi_score_inferred":False}

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--policy",type=Path,required=True)
    ap.add_argument("--output",type=Path)
    args=ap.parse_args()
    try:
        if args.policy.stat().st_size>8192:raise ValueError("oversized_policy")
        p=json.loads(args.policy.read_text())
        data=audit(p)
    except Exception as exc:
        data={"contract":CONTRACT,"state":"hold","read_only":True,
              "reason":type(exc).__name__+":"+str(exc)[:160]}
    value=json.dumps(data,indent=2,sort_keys=True)+"\n"
    if args.output:
        args.output.parent.mkdir(parents=True,exist_ok=True)
        args.output.write_text(value)
    print(value)
    return 0 if data["state"]=="passed" else 2

if __name__=="__main__":sys.exit(main())
