# PR #743 — REST recovery acceptance and live/source reconciliation

Scope: `tours.dev.mad4b.com` **Staging only**. This report is a bounded read-only source comparison and does not authorize deployment, Production change, changing the WordPress authentication model, or reopening completed ContentJobs.

## External and internal acceptance

- Real TLS-verified HTTPS `POST /wp-json/mad4b-localization/v1/jobs/recover` with no authentication: `401 rest_forbidden`, TLS verify result `0`.
- Authorized internal WordPress REST dispatch through `wp --user=26`, with `manage_options=true` and `MAD4B_SCP_Policy::can_mutate()=true`: empty payload returns `400 mad4b_localization_recovery_invalid_input`.
- User 26 has **zero existing WP login sessions** and **zero Application Password records**; `wp_is_application_passwords_available()=false`. Therefore this report **does not certify an authenticated external HTTPS request**. No credentials or login sessions were created.
- Native Hero FR job `89c69db1-d12e-4812-a198-bb9e3441e944` and Hero DE job `2eb24538-fab8-4cc0-b63c-5d2aaa42fbb9` remain `COMPLETED` on live readback.
- Outbox has 722 rows (`resolved_after_readback=622`, `queued=74`, `verified=21`, `needs_review=5`). **All 622 resolved rows lack lease timestamps**; zero pass the minimum Recovery eligibility preflight. No live existing eligible row was altered to manufacture a `422`.
- `tests/wpml-recovery-receipt-isolation-acceptance.php` loads the **actual** `recover_claim` method from the source and tests a separate deterministic non-WordPress fixture. On the live MU source, **5/5 assertions PASS**: wrong receipt denied with `mad4b_localization_recovery_receipt_mismatch`, valid receipt advances to native-job check, source REST error map contains `422`, no outbox change, no database writes. **This is NOT an external HTTPS or a real-eligible-row certification.**

## GitHub vs live Control Plane bootstrap

Compared `wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php` in the PR branch, master, and the currently running Staging container.

| Version | Blob | Predicate recognizes `/mad4b-localization/v1/` |
| --- | --- | --- |
| GitHub master, `0.4.0-rc.104` | `4a83dc4b3ad5df67d9e43bea8e8b52d4eae80c0d` | No |
| PR #743 branch, `0.4.0-rc.104` | `8075cbc6a4d1cd092ff42d105d3682a2634af934` | Yes |
| Live Staging, `0.4.0-rc.118` | `bc9a01fd2412b19388d972f0352de81b0ebe14e7` | Yes |

The branch adds exactly the missing owned-REST prefix to master. The live `rc.118` file also includes **many independent changes** not present in either master or PR #743: additional REST namespaces, company communication/operations modules, readiness/front-end impact, provider integrations, and authority/AI runtime modules. The branch and live core differ beyond the intended route fix.

**Reconciliation rule:** preserve live Staging source, retain the small owned-route fix in PR #743, and converge the broader live `rc.118` changes through their actual owning source changes/dependencies. Do not copy the full live file into this PR, overwrite the live file with the older PR core, or deploy an `rc.104` artifact over `rc.118`. The localized MU plugin matches Git blob `4609b45a2aed2221d728d1a231710ae2d9f858a8` on branch and live.

## Closure conditions

1. Use an existing authorized external WordPress REST identity (real interactive admin cookie + valid REST nonce, or an already supported authenticated provider) to prove an HTTPS request reaches input validation; do not invent credentials or bypass mutation principal checks.
2. The receipt mismatch outcome is proven **offline only**. If external `422` is required, wait for a real non-completed and eligible recovery row or use a properly isolated test environment, never a completed Hero job. Report separately whether the test is external or simulated.
3. Keep PR #743 Draft until any remaining certification/review gates are completed; do not merge/deploy an older Control Plane over live without cross-PR reconciliation.


## Follow-up root-cause fix and accurate HTTPS authentication diagnosis

- **Root cause in queue_scan:** when a previously leased row's issue disappeared after lease expiry, `queue_scan()` changed status to `resolved_after_readback` and explicitly set `lease_until`, `claimed_at`, and `claimed_by_user` to null. `recover_claim()` requires all three. This removed the only evidence necessary for a valid future recovery. Live pre-fix preflight: 622 resolved rows, 622 with missing claim timestamps and lease expiry, 0 claimed-by-user; 2 with attempts>0, 0 eligible.
- **Code fix (Staging and PR only):** preserve the exact expired claim actor and timestamps, retaining the existing expiry and 24-hour window checks. Do not create or renew a lease, change any completed job, or reconstruct old missing proof. Patched MU source is Git blob `cc3ac36c429085f349c70935b72a40de9edd968c`, **byte-identical on GitHub and live Staging**.
- **Tests:** new isolated test `tests/wpml-recovery-expired-claim-provenance.php` run against the actually deployed file passed **9/9**, proving expired claim provenance retained, unclaimed rows not fabricated, and active leases unchanged. The existing receipt fixture still passed **5/5**. PHP lint PASS before and after installation. Public HTTPS REST root `200` and unauthenticated recovery `401` with TLS verify `0`; WP-CLI REST invalid input `400`. FR/DE Hero ContentJobs still `COMPLETED`. Backup in Staging container: `/tmp/mad4b-743-localization-4609b45a.backup`.
- **Authentication correction:** WP-CLI `wp_is_application_passwords_available()=false` is caused by `is_ssl()=false` in that CLI context, not proof that the public HTTPS website disables the feature. The public `GET /wp-json/` advertises `authentication.application-passwords` and its authorization endpoint. However admin user 26 has **zero existing login sessions and zero Application Password records**; this operator cannot reuse an existing credential in this test. A properly authorized application password/client session can be provisioned via a normal WordPress administrative workflow and revoked after use, without bypassing auth gates.
- **Live `rc.118` vs repository `rc.104`:** live `MAD4B_SCP_Policy::can_mutate()` accepts a verified native WordPress administrator (real signed cookie + active session + `X-WP-Nonce` on REST, or explicit local `--user` for CLI) as well as the independent agent route. The older GitHub policy required NHI, so it must **not** be used to infer the current live gate. Do not overwrite newer live Control Plane code.
- The earlier complex external request with an invented/synthetic receipt was blocked **before command execution** by the tool safety channel. A short no-credential HTTPS call in the same authorized `shell_access` channel succeeds; this distinguishes tool-level refusal from syntax/SSH/connectivity errors. The internal reason for the tool refusal is not exposed and should not be guessed.
- **Remaining two certification cases:** externally authenticated HTTPS administrative request, and actual HTTP `422` on a genuinely eligible row. Neither was fabricated. The code fix prevents loss of evidence on *future* legitimate expired claims but does not retroactively make the 622 historical rows eligible.

