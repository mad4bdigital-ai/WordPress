# Host v146: Exact company / external WordPress site / SSH host reconciliation

## Root-cause findings, 22 November 2026

Three enrolled SSH accounts exist on VPS. This does NOT imply that
all domains on each shared account are enrolled MAD4B companies.

The Central original company environment registry, Root native operator
policy and five direct pinned remote WP-CLI reads all agree:

| Company | Environment | SSH target |
|---|---|---|
| tourism-almallahgroup | production | hosting-1 |
| all-royal-egypt | production | hosting-2 |
| all-royal-egypt | staging | hosting-2 |
| egypt-tour-gates | production | hosting-3 |
| egypt-tour-gates | staging | hosting-3 |

Every remote WordPress home, siteurl, environment, Site Profile UUID
and Root expected WordPress directory matches the Central company.
No user identity or credential was copied and no company site mutated.

Only Tourism Almallah Group is enrolled on hosting-1. Unrelated domains
under that same shared hosting user must not inherit MAD4B authority.

Older Original305 grants point at srv2030908 for Egypt Tour Gates Staging,
although its physical SSH target is hosting-3. Such old grants are NOT
transferable and never authorize a new SSH host.

## Reconciler and dashboard

New Root-only external_host_evidence.py validates:
1. Exact SHA-pinned original v144 SSH adapter, 3/3 pinned host canaries;
2. Central canonical company environment and site UUID;
3. Root native operator exact per-site UUID, origin, host, root and env;
4. Pinned remote WP-CLI matching UUID, home, siteurl and environment;
5. Fail closed on any mismatch, and no additional sites on shared accounts.

check is pure remote read-only. publish writes a 0600 private Root
diagnostic in a 0700 directory, then mirrors ONLY the nonauthorizing
site evidence metadata into the Central WordPress option
mad4b_remote_external_host_readonly_v146 after exact Central DB, staging
origin and result readback validation. No grant or signer is changed.
If WordPress CLI is busy, the Root report is retained and dashboard
proof expires within two hours.

The Root systemd timer refreshes hourly at minute 25, away from
Original305 and SSH readiness timers.

The existing v145 Remote Operations Owner dashboard reads this exact
fresh status and displays all 5 external company site/host bindings.
Original305 grant choices are filtered to the same verified company,
environment, site UUID and physically bound host. Stale local VPS
transport choices are not presented as valid remote ones.

## Trust boundary

5/5 physical bindings are VERIFIED for read-only evidence.
That is not a first-party signed Original313 predecessor, Owner v3
step-up or Original312 exact signed operation enrollment.

Original v133 issuer manifest is still source STANDBY; Original305
ambiguous write HOLD stays preserved. Original318 v3 Write is not
authorized. No DB flag or SSH transport can replace those signatures.

All Production mutations require independently signed exact operation
grants, backup, rollback and independent execution readback.
Only original first-party issuer workflows may admit new grants.

## Rollback

Disable v146 hourly timer, restore only v145 431 admin PHP from its
private backup and stop updating v146's read-only WordPress option.
Keep the original 305, 306, 312, 313, 318 modules, Original305 HOLD and
all WordPress review/grant/transport records untouched.
