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
