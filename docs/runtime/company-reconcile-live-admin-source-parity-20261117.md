# Company Reconcile: live source and tenant Admin Experience parity, 2026-11-17

The registered Tours.dev source and Wayfatours Production Admin Experience were reconciled **selectively on the original live PHP/CSS/JS files first**. This is a human-authorized live code correction, **not** an Original305/312/313-signed job. The absence of a signed execution receipt is never concealed or inferred from parity.

## Exact manual operation

- Exact enrolled target: Wayfatours Production, UUID `a3a7cc36-db7e-4a14-8456-b41857def05c`, bound origin `https://wayfatours.com`. Source Tours.dev staging, current first-party company UI.
- An independently verified historical exact file Manifest (SHA256 `9177b8a9cd34194cc72e02c7f3e41003aaa73814e1397e515647080447bc41de`) declared **nine changed files**, with source/target checksums and a verified rollback archive. **4** files were replaced and **5** missing files were created using atomic per-file rename. Every preimage and postimage was SHA256 checked. Rollback retains all four old files and removes the five newly-created files.
- Site-bound Company Appearance, Admin Experience and Admin UI Auto Scan PHP classes are now actually loaded by Wayfatours WP-CLI; its PHP lint and EN/ES/login public HTTP checks passed. No WooCommerce order, Stripe payment, post, user, WordPress role, database option or Grant was written by the selective file installer.
- Original Admin UI Auto Scan only reported metadata about authenticated admin pages. Its client enqueue and receiving AJAX endpoint previously required global `manage_options`, excluding MAD4B `master_admin`. Both now allow **`manage_options` OR `mad4b_manage_company_operations`**; the original WordPress nonce, same-origin route validation, issue allowlists and bounded option retention remain unchanged. This does not grant general plugin activation or site settings rights. Tested against actual current WordPress administrator, Master Admin and anonymous user contexts on both sites.
- The complete source-owned Admin Experience scoped catalog has **11/11 file SHA256 matches** after this manual repair. A fresh official host-scanned **WordPress inventory** was published on 2026-11-17 at 12:24 UTC with **zero changed files**, zero plugin-install missing files and zero activation needs. The separate cheap advisory host observation also confirms zero drift. Neither proof is an independent Original313 write receipt.

## Correct no-drift state

`MAD4B_Company_Reconcile_Owner_Actions_V1::inventory_in_sync()` only reports `true` when a **fresh, scoped** inventory for the exact company/environment/origin/UUID contains 1–40 unique allowlisted paths with identical valid SHA256 source/target digests and `needs_sync=false`. It fails closed on stale, duplicate, cross-company, malformed or mismatched evidence.

The Company Reconcile screen now shows **"Source parity verified — no new Original305 job needed"** for that no-change case, rather than advertising an impossible new write job. Old expired jobs are retained as historical audit records and are never considered successful signed release evidence. When a *new* genuine drift arises, the original unchanged owner/browser Nonce, exact SHA-bound source review, signed native 312/313 and rollback admission requirements remain in force.

## Release/governance boundary

Current WordPress Command Intake and Transport are enrolled, but the dedicated `mad4b company-appearance apply` command and signed exact native host-write/rollback handler are **not available**. They are not needed to close *this specific zero-drift Admin Experience file scope*, and may not be retroactively certified. Future write operations require the original separately verified executor or an expressly scoped original signed code release; a full admin parity badge is not a general company 313 release.

Private live receipts/rollback: `/opt/mad4b-company-provisioning/reports/company-reconcile-live-first-20261117/`.

## CI

The exact original owner class is verified under PHP 8.3 and 8.5 (31 fail-closed scope assertions per version). The real private Auto Scan AJAX receiver has 12 assertion cases per version for owner role separation, anonymous/editor denials, WordPress Administrator continuity, nonce, unsafe-route rejection and bounded option writes. The workflow neither needs database state nor creates a host job.
