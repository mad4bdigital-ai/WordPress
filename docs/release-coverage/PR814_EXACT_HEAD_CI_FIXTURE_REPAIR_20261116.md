# PR814 exact-head CI correction — governance not weakened

Status: local source candidate only, Nov 16 2026.

GitHub CI on the previously published verified source tree identified
obsolete test models, not a missing production write admission:

- The MCP client test expected a single inline is_external_write_candidate
  expression. The hardened runtime now checks the candidate catalog is a
  real boolean, rejects unknown entries, and only then checks the governed
  write authority, exact capability eligibility and dedicated mount. The
  test verifies these source-order gates instead of blindly looking for the
  obsolete expression. It does not relax any permission.
- Two endpoint diagnostic tests contained synthetic Capability Descriptor
  Registry stubs from a prior contract. Their test bindings now include
  the exact GENERATION_CONTRACT and CONSUMER_BINDING_CONTRACT, SHA256
  shape, non-authorizing snapshot and revalidation fields. The failing
  descriptor remains unavailable rather than being silently bound.
- The real MCP Adapter runtime uses ordinary WordPress _deprecated_function
  and esc_html__ functions when initialized as a dependency in a pure PHP
  fixture. The test now supplies these core baseline functions; this neither
  forges WordPress privileges nor changes Adapter behavior.
- Rights service (186 lines) and remote media adapter (902 lines) exceeded
  existing 180/900 line budgets only because of blank separator lines.
  Removed only blank lines, exactly preserving every nonblank source line,
  including all explicit restrictions, invalid license/expiry checks and
  existing advisory-versus-enforced policy. No behavior changed.

Verification: MCP client compatibility v12 PASS; endpoint descriptor binding
PASS on PHP 7.4/8.5; endpoint diagnostics all 12 cases PASS on PHP 7.4/8.5
using an isolated read-only actual 0.7-series MCP Adapter copy, offline.
Full governed content operations contract PASS with isolated PHP 8.5 fixture.

No Master incident ticket, Original305 historic HOLD, Host312 grant or
Original313 signed release was issued or mutated. Repo-feature boundary
root and owner-attestation release gates still require independent review.
