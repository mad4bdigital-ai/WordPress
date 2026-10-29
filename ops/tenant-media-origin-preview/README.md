# VPS-hosted tenant media, company-owned hostnames

## Architecture
- Media files remain on the VPS (no copies to client WordPress hosting).
- Each company will have its own **media subdomain** (for example, `media.<company-domain>`) using DNS and TLS termination at the approved edge.
- Trusted host-to-tenant binding decides the source volume and media catalog; never trust a caller-supplied tenant ID or directory query.
- WordPress on each company host owns its content/DB and receives mapped media URLs; only public media catalog entries may be served. No route to private documents, uploads metadata, REST secrets, backups, PHP or arbitrary upload paths.
- The original staging `uploads` folder contains non-media and QA files; it must **not** be exposed wholesale.
- Distinct read-only origin mounts per company or media-authorized catalog prefixes; no cross-tenant path resolution, no autoindex, no directory traversal. At the edge, validate Host, TLS and certificate for each company before enabling the route.
- Preserve source URL/attachment metadata until mapping validation and rollback is ready. Avoid bulk rewriting serialized WordPress/Elementor/WPML content.

## Pilot
This repository contains **only** a local loopback prototype: a rootless, read-only nginx serving one known non-sensitive PNG fixture to demonstrate serving existing bytes without copying them. It binds to `127.0.0.1:8097`, not public 80/443, and it is *not* a full media gateway, a tenant catalog or an operational release.

Run only on an authorized VPS that has the reviewed fixture:
```sh
MEDIA_SOURCE_ROOT=/absolute/path/to/existing/uploads docker compose -f compose.yml up -d
curl -i http://127.0.0.1:8097/__health
curl -I http://127.0.0.1:8097/2026/10/placeholder-3-100x100.png
curl -I http://127.0.0.1:8097/2026/10/etg-brand-tone_of_voice-review-20261012.txt  # must 404
```

## Go-live gates
1. Verify authoritative per-tenant media catalog and visibility labels, tenancy isolation, safe media formats, anti-traversal, symlink denial and explicit fail-closed invalid Host behavior.
2. Move/anchor origin storage to independent persistent tenant volume (without copying media to company hosts), backed up with restore rehearsal; existing Staging must not become an accidental single point of deletion.
3. Confirm HTTPS DNS ownership and certificate issuance, then stage domain-to-tenant mappings in Traefik/Cloudflare. No live domain route or DNS change from this prototype.
4. Change WordPress attachment URL projection only after complete source-to-target URL audit and CDN cache/purge checks, with a rollback switch. New upload writes and generated thumbnails need explicit media-catalog support.
5. Owner and independent operating approval; no bypass of outstanding release/authority gates.

Scope: configuration source + read-only VPS pilot only. No Production mutation, DNS edits or global publishing permit.


## Phase 2: tenant-bound catalog and security gate (2026-10-29)

The pilot now uses a generated configuration, NOT the historical static nginx.conf file.
build_gateway.py compiles catalog.preview.json into generated/nginx.conf after verifying exact hosts, image extensions, paths, symlinks and source SHA-256.
compose.yml binds generated/nginx.conf and the ETG origin volume read-only.
Two catalog tenants: media.egypttourgates.com (one pilot image), media.allroyalegypt.com (zero images; no authoritative origin verified).
Unknown hosts respond 421, known hosts only expose exact allowlisted assets; no public DNS/HTTPS/WordPress route was enabled.
13/13 offline regressions and 16 local hostname/path requests returned expected statuses.
The live source still depends on the Staging uploads directory; a mount is NOT independent storage. Moving or replacing it is blocked until media backup and restore verification.
Catalog and QA control metadata are independently kept under /srv/mad4b-media-control/ with restricted permissions; no media bytes were copied.

Internal reproduction on the authorized VPS:
1. python3 -m unittest -v test_gateway
2. python3 build_gateway.py --catalog catalog.preview.json --output generated/nginx.conf --etg-root /path/to/source/uploads
3. MEDIA_SOURCE_ROOT=/path/to/source/uploads docker compose -f compose.yml up -d
4. curl -H 'Host: media.egypttourgates.com' http://127.0.0.1:8097/2026/10/placeholder-3-100x100.png (200)
5. curl -H 'Host: media.allroyalegypt.com' http://127.0.0.1:8097/2026/10/placeholder-3-100x100.png (404)
6. curl -H 'Host: unknown.example' http://127.0.0.1:8097/__health (421)

Next gates: independent persistent per-tenant media storage and backup/restore, certified ARE origin, public asset inventory, TLS/DNS owner verification, trusted SNI-to-tenant edge binding, WordPress attachment URL and upload/thumbnail lifecycle, independent owner release authorization.
Receipt: evidence/VPS-TENANT-BOUND-QA-20261029.json.
