# MAD4B Universal Media Readiness v1

A single multi-tenant WordPress MU adapter that inspects **all registered public taxonomies and published public post types**. It does not assume every type needs an image. Each type's media requirement and field name are controlled by the dynamic policy filter `mad4b/universal_media/policy`; unknown visual fields can be registered through `mad4b/universal_media/media_keys`.

Example company-level policy:

```php
add_filter('mad4b/universal_media/policy', static function($policy,$kind,$type){
    if ($kind==='post' && $type==='properties') {
        $policy['required']=true;
        $policy['field']='property_featured_photo';
    }
    if ($kind==='term' && $type==='tour-styles') {
        $policy['required']=true;
        $policy['field']='taxonomy_hero_image';
    }
    return $policy;
},10,3);
```

`wp mad4b universal-media scan` is read-only. `object term destinations 2357` or `object post properties 46502` returns eligibility and priority. `candidates term destinations 2357 --limit=12` searches the tenant's own WordPress attachment metadata only, returning review candidates without mutating ownership. `external term destinations 2357 --limit=8` looks up Wikimedia Commons through a bounded HTTPS API, reads license/author/source-page metadata, and **does not download or publish anything**. Other licensed suppliers may be integrated with explicit provider adapters and company ownership checks. Search results are never proof of accurate photo subject, usage rights, or license compliance.

Priorities: P0 = required image missing or invalid for a public *eligible* content item; P1 = optional but referenced image broken for an eligible item; P2 = required image missing but currently no qualifying commercial offer; P3 = optional reference broken with no qualifying offer. Eligibility is source-specific: for `destinations`, the existing native sale index is authoritative. For generic taxonomies, a published associated post is editorial relevance only, not proof of commercial or checkout readiness. For published post types, `publish` is publication eligibility; more restrictive eligibility rules are provided via `mad4b/universal_media/eligible`.

Alerts use the existing tenant Configuration Jobs ledger, with review-only status and no automatic image publishing. The old destination-only alert adapter is suppressed while this general adapter is available. Historical alerts are reconciled by the native scan; no duplicate family is created. Original images, WPML, rights, supplier agreements, and asset provenance require verification before any governed apply.

`scheduled-fleet-scan.py` reads only enrolled local company containers from the existing Configuration Fleet Registry JSON, verifies each exact Site UUID, runs the company scan every 30 minutes in a systemd one-shot service, and writes private receipts. It never executes in visitor page requests and never performs `Purge All`. Remote external-host companies must enroll separately using authorized cross-SSH transport and equivalent test evidence; the fleet timer does not claim their rollout.

Safety: strict pagination bounds; incomplete scans do not silently resolve outstanding alerts; no arbitrary URL crawling, bulk scraping, remote-file downloads, or unsanctioned attachment writes. The reference Wikimedia API is restricted to a known HTTPS host, bounded response and request timeouts. All candidate matches are unverified until reviewed.

## Governed media candidate discovery queue

`intake-discovery-queue.py` uses the same enrolled company fleet registry and creates private per-object review artifacts for up to four highest-priority gaps per company per run. It searches local WordPress attachments first; if no suitable local candidates are present, it asks the read-only Wikimedia API for a bounded list of license/source/artist metadata. Results carry `visual_subject_verified=false`, `photo_rights_verified=false`, and `auto_apply_authorized=false`; importing or publishing requires a separate governed approval path. Cache evidence for 24 hours by `(kind,type,id,priority)`; escalation from P2 to P0 generates a fresh artifact.

WordPress admission refusal and remote API failures are **retryable unknown**, not evidence that no photos exist. Candidate metadata can be geographically misleading (e.g. Esna in Egypt versus a place elsewhere). Never substitute image metadata similarity for visual and geographic confirmation.

## Semantic precision correction

The original loose media-library search used OR across all destination words, causing false matches: Crocodile Museum matched Cairo museums and an Alabaster Factory matched alabaster statues elsewhere. The corrected library search requires all primary, non-generic entity anchors within attachment title or alt; only explicit editorial aliases (e.g., `sphinx avenue` / `rams alley`) support near-exact alternatives. This is still a candidate, **not** a verified image. External results carry geographical evidence states; Esna in Estonia must not be mistaken for Esna in Egypt. Changed candidate logic produces new intake evidence filenames using a resolver-version suffix; earlier reports remain immutable for forensic comparison and are no longer actionable selections.

## Browser QA for images used as CSS backgrounds

On the Beni Suef Elementor taxonomy page, the canonical JetEngine `featured_image` is rendered as `background-image` on container `.elementor-element-9716866`, not as an `<img>`. Testing `document.images` alone incorrectly classified the image as missing. `term-hero-css-image-qa.py` verifies the computed CSS URL, visible element dimensions, image decoding via `new Image()`, and visible licensed-photo attribution on the two enrolled companies; it makes no content changes. Other sites should configure their own template selector, rather than adding company-specific selectors to the WordPress MU module.
