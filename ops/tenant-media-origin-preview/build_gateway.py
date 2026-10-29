#!/usr/bin/env python3
"""Generate a fail-closed, LOOPBACK-ONLY tenant media gateway from a reviewed catalog."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re

HOST_RE = re.compile(r"^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$")
PART_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]*$")
ALLOWED_MIME = {
    ".png": "image/png", ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg", ".webp": "image/webp",
    ".gif": "image/gif", ".avif": "image/avif",
}

def require(condition, message):
    if not condition:
        raise ValueError(message)

def media_path(raw):
    require(isinstance(raw, str) and 0 < len(raw) <= 255, "bad media path")
    require("\\" not in raw and "%" not in raw and "?" not in raw, "encoded or ambiguous media path")
    parts = raw.split("/")
    require(all(p not in (".", "..") and PART_RE.fullmatch(p) and not p.startswith(".") for p in parts), "unsafe media path")
    suffix = Path(raw).suffix.lower()
    require(suffix in ALLOWED_MIME, "not an allowed image format")
    return raw, suffix

def escape_conf(s):
    require("\n" not in s and "\r" not in s and '"' not in s and "$" not in s, "nginx injection")
    return s

def generate(catalog, source_roots):
    require(catalog.get("contract") == "mad4b.tenant-media-catalog.preview.v1", "unknown catalog contract")
    require(catalog.get("mode") == "loopback-only", "public serving not supported")
    hosts = catalog.get("tenants")
    require(isinstance(hosts, list) and 1 <= len(hosts) <= 25, "bad tenant list")
    seen = set()
    config = [
        "worker_processes 1;",
        "pid /tmp/nginx.pid;",
        "events { worker_connections 256; }",
        "http {",
        "  access_log /dev/stdout;",
        "  error_log /dev/stderr warn;",
        "  server_tokens off;",
        "  sendfile on;",
        "  client_body_temp_path /tmp/client_temp;",
        "  proxy_temp_path /tmp/proxy_temp;",
        "  fastcgi_temp_path /tmp/fastcgi_temp;",
        "  uwsgi_temp_path /tmp/uwsgi_temp;",
        "  scgi_temp_path /tmp/scgi_temp;",
        "  default_type application/octet-stream;",
        "  server { listen 8080 default_server; server_name _; return 421; }",
    ]
    count = 0
    for tenant in hosts:
        require(isinstance(tenant, dict), "bad tenant entry")
        host = tenant.get("host")
        key = tenant.get("key")
        require(isinstance(host, str) and HOST_RE.fullmatch(host) and ".." not in host and "." in host and host == host.lower(), "bad hostname")
        require(host not in seen, "duplicate hostname")
        seen.add(host)
        require(isinstance(key, str) and re.fullmatch("[a-z][a-z0-9-]{0,38}", key), "bad tenant key")
        assets = tenant.get("assets", [])
        require(isinstance(assets, list) and len(assets) <= 1000, "bad asset list")
        require(key in source_roots or not assets, "tenant missing approved media root")
        root = Path(source_roots[key]).resolve() if key in source_roots else None
        config += [
            "  server {", "    listen 8080;",
            f"    server_name {host};",
            "    autoindex off;", "    disable_symlinks on;",
            "    add_header X-Content-Type-Options nosniff always;",
            '    location = /__health { default_type text/plain; return 200 "tenant-media-loopback-ok\\n"; }'
        ]
        seen_paths = set()
        for a in assets:
            require(isinstance(a, dict), "bad asset entry")
            rel, ext = media_path(a.get("path"))
            require(rel not in seen_paths, "duplicate asset")
            seen_paths.add(rel)
            require(a.get("preview_approved") is True and a.get("public_approved") is False, "pilot must not authorize public use")
            require(a.get("mime") == ALLOWED_MIME[ext], "MIME mismatch")
            expected = a.get("sha256")
            require(isinstance(expected, str) and re.fullmatch("[0-9a-f]{64}", expected), "missing asset SHA-256")
            require(root and root.is_dir(), "missing origin")
            candidate = root / rel
            require(candidate.is_file(), "missing asset")
            # Reject symlinks anywhere under the resolved source root.
            probe = root
            for part in rel.split("/"):
                probe = probe / part
                require(not probe.is_symlink(), "symlink in asset path")
            require(candidate.resolve().is_relative_to(root), "asset escaped root")
            with candidate.open("rb") as stream:
                digest_obj = hashlib.sha256()
                for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                    digest_obj.update(chunk)
                digest = digest_obj.hexdigest()
            require(digest == expected, "source hash changed")
            target = escape_conf(f"/srv/media/{key}/{rel}")
            config += [
                f"    location = /{escape_conf(rel)} {{",
                f"      alias {target};",
                f"      default_type {ALLOWED_MIME[ext]};",
                '      add_header Cache-Control "private, max-age=60" always;',
                "    }"
            ]
            count += 1
        config += ["    location / { return 404; }", "  }"]
    config += ["}"]
    return "\n".join(config) + "\n", {"tenants":len(hosts),"catalog_images":count,"hosts":sorted(seen),"mode":"loopback-only"}

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--catalog",required=True)
    parser.add_argument("--output",required=True)
    parser.add_argument("--roots-file",required=True,help="Local JSON mapping of registered tenant key to absolute origin root")
    args=parser.parse_args()
    data=json.loads(Path(args.catalog).read_text())
    roots=json.loads(Path(args.roots_file).read_text())
    require(isinstance(roots,dict) and all(isinstance(k,str) and isinstance(v,str) and Path(v).is_absolute() for k,v in roots.items()),"invalid roots mapping")
    rendered,summary=generate(data, roots)
    dst=Path(args.output)
    dst.parent.mkdir(parents=True,exist_ok=True)
    tmp=dst.with_suffix(".tmp")
    tmp.write_text(rendered)
    os.replace(tmp,dst)
    print(json.dumps(summary,sort_keys=True))
if __name__=="__main__":
    main()
