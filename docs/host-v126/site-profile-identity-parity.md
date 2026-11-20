# Host Authority v126 — Exact Tours.dev Staging Site Profile identity parity

**Base:** canonical master `1ed359a52ecd1c94574ddfd31c9327de5779b856`.
**Initial live source SHA-256:** `f643847a8dbf590e48dda561f29a7eae36da66ce4f079c16f2fd34be22f2a175`.
**Previous master SHA-256:** `cc3ace25b52a452a32031a8a6ce02157963de96eb0b563346aa5d33599cf36bf`.

This is the **identity-correctness half** of Host Authority runtime source
reconciliation; unlike source-only PR #975, it alters the actual SCP Site
Profile source tree in Git. There is **no live WordPress deployment**.

The master and source versions have the same **88 methods**. The exact
Staging source adds 77 lines and changes 5 within three tightly scoped
identity regions:

1. `current_origin()`: normalize only a verified registered WPML language
   suffix on the exact original host; deny unknown, hidden, cross-host and
   unverified locale paths. AJAX/REST fallback uses active language settings,
   never the HTTP Referer as a site identity.
2. `site_urls_match_enrollment()`: in genuine WP-CLI contexts only, accept
   an HTTP-converted `site_url()` if the **stored** home and siteurl both
   match the enrolled canonical HTTPS origin. No relaxation for ordinary
   HTTP/REST requests or altered stored origin.
3. `save_current_site()`: accept `reserved_site_uuid` only if valid and
   independently attested by the exact authorized
   `mad4b_scp_authorized_reserved_site_uuid` filter, with an explicit
   existing-identity conflict check. A caller-provided UUID alone has
   **no** enrollment authority.

## Acceptance, fail-closed

- Existing 5 Site Profile PHP 8.5 runtime fixtures pass in isolated
  read-only Docker, validating prior identity/re-enrollment gates.
- `test_site_profile_origin_contract.php` runs 13 HTTP and 5 CLI
  positive/negative origin/locale security checks without WordPress.
- `test_reserved_uuid_attestation.php` rejects four unauthorized/invalid
  UUID and admin requests, without any persistent write.
- `test_site_profile_source_contract.py` asserts exact source SHA and
  preserved original APIs and authority guards.

Do not merge or deploy until current head CI is complete, exact-head
Owner `OWNER_ATTEST_SINGLE_OWNER` authorization is present, and integration
checks prove no unexpected WPML callback, saved Site UUID, or siteurl
regression on site-specific Staging. Any Production rollout needs a
separate company-specific release approval and rollback.

**Scope limit:** This does not deploy Portfolio Manager, MU Router,
Commerce, or Original305/306/313/318/328 from master; PR #975 still
contains inert source evidence only. Live Staging Original318 Rev 1 and
signed Original313 receipt must remain untouched.

**Review-only comment correction:** The Site Profile review candidate is functionally identical to live Staging apart from replacing one URL-like fragment in a PHP comment, which the legacy site-neutrality scanner incorrectly interpreted as a literal host. Candidate SHA-256: `7698ea2882d95bcc8eab3a029d0d9c4814b206d9a1e1d20de4b4546318e8d7ee`. The comment-only correction changes no PHP behavior and must not be used to claim byte-for-byte equality with live Staging.
