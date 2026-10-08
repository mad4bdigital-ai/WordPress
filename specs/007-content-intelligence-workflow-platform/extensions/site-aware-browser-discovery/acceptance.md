# Site-aware Browser Acceptance — adversarial contract

| Case | Expected | Class |
|---|---|---|
| One recognized ETG plugin and reviewed driver | Resolve ETG adapter + declared `tours` profile; certificate remains false | Isolated test |
| All Royal site with no signed acceptance provider | List unmapped signals, block execution, no ETG fallback | Isolated + live readback |
| A pluginless provider declares CPT and taxonomy evidence | May be recognized; reviewed driver still required | Isolated |
| Multiple recognized provider candidates | Explicit selection required | Isolated |
| Provider metadata disagrees with observed source list | BLOCKED | Isolated |
| Discovery hash/site origin/profile/revision drifts during execution | BLOCKED; discard local result | Isolated + E2E |
| Signed plan references another origin or unmatched provider | BLOCKED before Playwright | Isolated |
| Provider contract has no locally bundled reviewed driver | BLOCKED, never dynamically import code | Isolated |
| Unsupported plugin with no adapter | `UNMAPPED`, never `PASS` | WP admin view |
| ETG existing supported cases | Existing adapter semantics preserved | Native Node/Playwright |
| New site plugin semantic parity | Requires real oracle/provider-specific signed reducer | Live |
| Staging source ZIP differs from GitHub exact HEAD | Refuse release certificate | Package/live |
| Browser evidence absent, untrusted, or wrong digest | BLOCKED or INCOMPLETE, never PASS | Native reducer |
| Production WordPress environment or non-exact Site Profile | Deny writes and promotion | Live Staging gate |

**Invariants**: all discovery paths are read-only; no `wp_insert_post`, plugin activation, arbitrary HTTP requests or code evaluation. No mutation is authorized or inferred by recognition.
