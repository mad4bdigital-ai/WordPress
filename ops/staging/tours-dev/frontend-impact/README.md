# MAD4B Frontend Impact Priority — rollout and acceptance

## Sources

- Control Plane read-only scanner: `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-frontend-impact-priority.php`
- Optional MU bootstrap: `wp-content/mu-plugins/mad4b-frontend-impact-loader.php`
- Shared Product / Presentation source-of-truth adapter: `wp-content/mu-plugins/mad4b-frontend-impact-shared-presentation-bridge.php`
- Source-backed rate, booking form and catalog issue adapter: `wp-content/mu-plugins/mad4b-frontend-impact-commerce-evidence.php`
- Acceptance checks: `wp-content/plugins/mad4b-site-control-plane/tests/frontend-impact-component-evidence-test.php`

## Existing Control Plane comparator

The *live* Staging Control Plane contains `class-mad4b-scp-content-readiness-alerts.php`; that file was not present at the corresponding GitHub branch path. Rather than inventing a new full source file from partial data, the narrowly scoped, staged and tested change is preserved in `readiness-comparator.patch`. Reconcile it into the canonical source once its exact path is tracked. Do **not** overwrite other live runtime changes.

Priority overlay is read-only and affects **sorting within existing severity**; it never replaces the provider score in the issue ledger or changes readiness/ContentJob states.

## Live Staging acceptance (2026-11-02)

- Initial six-language home scan: 6 pages, 105 WPML destination alerts, 28 parent groups.
- Extended scan from live WP-Cron readback: **18 HTML pages**, **121 scored existing alerts**, 39 groups, no HTTP failures.
- Scoring categories: 105 destination navigation; 10 verified expired Native Rate-to-tour relations with published listing visibility; 1 form follow-up; 1 price-locale parity; 1 catalog layout; 1 rate CSS; 1 site performance; 1 stale probe.
- Cross-language archive filters: EN/FR/DE/ES/IT/AR present. No ungrounded JSF parity alerts.
- Real Spanish tour static DOM: Native Shared Product Form 6 present via the MU booking adapter, price surfaces present. This does **not** verify a transaction.
- 11/11 standalone contract assertions PASS, including fail-closed for unobserved rate and form issues and no booking/publication authorization.
- `GET /`, `/fr/`, `/es/`: HTTP 200, TLS valid.
- New manual full-scan retries sometimes return `STAGING_WPCLI_BUSY` due to concurrent WordPress CLI sessions. Do not bypass the lock. The last successful persisted readback remains authoritative; do not claim a subsequent scan succeeded without checking its timestamp.

## Exposure / safety limits

- Live evidence is server-rendered HTML, not computed browser visibility. A DOM element may still be hidden by CSS or disabled by JS. Candidate parity differences are **not** automatically published as issues.
- Native booking Form 6 identification comes from availability of the Shared Product form6 quote adapter, not from generic text guessing. An HTML presence check is **not** a successful booking or payment proof.
- Only eligible and exactly mapped pre-existing alerts are scored. Other alerts keep their normal severity/priority; source and issue IDs are never fabricated.
- No Production deployment, supplier transactions, price changes, or job mutations.
- The latest snapshot is isolated in `mad4b_frontend_impact_priority_v1`; failed coverage does not erase a last good snapshot. The REST summary is authenticated.
