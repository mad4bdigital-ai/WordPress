# Production Site Profile in one authenticated action (17 Nov 2026)

## Verified live installation
- https://egypttourgates.com
- https://tourism.almallahgroup-mg.com
- tours.dev.mad4b.com (source only, disabled on Staging)

Both Production sites have the original Site Profile plugin and audit storage
ready. The common MU-plugin is installed on each site through pinned SSH, with
the exact same SHA256 as the source. It renders a short new action only for
their own WordPress Administrator on the official Site Profile admin page,
and only while the Site Profile option is truly absent.

## Owner workflow
1. Log into the Production WordPress admin and open the existing Site Profile
   page under wp-admin/admin.php?page=mad4b-control-plane-site-profile.
2. Click Create Production identity (no write access).
3. The existing admin-post Site Profile handler, using Administrator
   capability and WordPress nonce, generates its own unique Site UUID with
   compare-and-swap, append-only audit, rollback and independent persisted
   readback. The quickstart plugin never directly updates the database.

There is no OAuth, App mapping, privileged WordPress shell, manual UUID,
environment file edit, Production write toggle or payment modification.
A logged-in human Administrator remains necessary for this single action.
After identity creation, the Central owner can approve the exact Production
UUID and manager using the native existing Central admin workflow.

## Fail-closed boundaries
Only the two exact Production origins can display the form. WordPress home
and site URL must agree, Production environment and Site Profile must be
loaded, the profile option must be absent, audit must be ready and the actor
must have manage_options in wp-admin. Any previous, cloned, invalid,
pending-audit, or otherwise configured Site Profile stays in the full
original review flow. No existing profile is silently overwritten.

The form uses the original mad4b_site_profile_save action with the existing
nonce. It submits environment production, profile_only sync mode, initial
revision zero and empty expected digest. It does not submit write_enabled or
production_write_confirmed. No new owner approval or Original313 receipt is
fabricated. Wayfatours currently has 11/11 matching source assets and
needs no Original305/312/313 job merely to certify zero file delta.

## Evidence and rollback
PHP 7.4, 8.3 and 8.5 each passed 23/23 hermetic tests. Source and two
Production MU-plugin files have SHA256:
c6f052e9b2d8227b5646ad0005dcd8701b8833ce4a9bab892b388cc0a449ad1a

Live remote WordPress bootstrap confirms MU-plugin loaded and no Site Profile
created or governed write enabled as a result of installation. Source
Staging bootstrap confirms the form is ineligible. Private installation
receipt: /opt/mad4b-company-provisioning/reports/production-identity-quickstart-install-20261117.json.
Rollback is deletion of ONLY this newly added MU-plugin, and only if
its SHA256 still matches. Do not delete any saved Site Profile after an
actual Administrator enrollment.
