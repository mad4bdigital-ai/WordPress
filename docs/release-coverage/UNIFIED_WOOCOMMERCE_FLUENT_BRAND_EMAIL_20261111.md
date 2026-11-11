# Unified company email experience — WooCommerce + Fluent Forms

**Date:** 2026-11-11. **Sites checked:** tours.dev.mad4b.com (Wo Vacation source) and wayfatours.com. **Central composite release:** HOLD. The email presentation patch was applied to the two named WordPress runtimes; this does not certify or authorize the wider Original318/313 release process.

## Boundaries and integration

- Reuses the existing company-bound Brand Context, company Look & Feel, and Elementor active kit via the reviewed shared company-email resolver. No second brand profile, shared tenant logo, SMTP credential changes or WooCommerce option writes.
- Registers presentation hooks for native WooCommerce email header/footer and styles. Native WC_Email templates, notification states, recipients, order totals, customer account/verification links, payment eligibility and original dispatcher remain intact.
- For a native WooCommerce email backed by an order, the stored order language is carried from header to footer; links are resolved only to same-site published WPML language pages.
- The existing MAD4B manual request lifecycle email remains in charge of its own translated content and links: the new Woo footer intentionally does not append the default-language footer to that email.
- Fluent Forms uses its own rendering hooks for logo, header, style, company legal and same-site links. An original, saved submission source_url supplies locale only after site and submission verification. The plugin does not enable disabled notifications or add a new mail sender.
- Fluent Forms emits four 600px tables in HTML. The post-inlining hook converts only those known template tables to responsive widths, applies an email-safe header color, gold divider, legible legal copy and a 154px same-site logo; submitted user table markup is preserved.
- No customer/order records used in visual QA. No payment capture or loyalty activation.

## Verification completed

- Offline bridge: 26 cases including site ownership, palette, native/manual Woo footer isolation, submission language, disabled notification defaults, responsive Fluent tables and original content preservation.
- Earlier offline suites in the same source tree: 18 company-brand, 11 verified request-locale, 25 Woo lifecycle, 51 Early Payment Benefits, plus Python tamper guard suites. No real email or network in offline suites.
- Live renders through WordPress and Fluent on BOTH sites produced company-branded HTML. Source has no optional Look & Feel email logo, so falls back to company text; Wayfa uses its own 300px media derivative displayed at 154px.
- Actual Wayfa medium logo URL served HTTP 200 image/png.
- Chromium visual QA on Wayfa rendered HTML at widths 320, 390, 768 and 1200: both Woo and Fluent had no horizontal overflow (8/8); logo loaded in visual-only screenshots by substituting the same site-owned PNG into local QA fixture. Actual live emails reference public HTTPS image URLs and do not embed data URIs.
- Native Woo order preview with UNSAVED synthetic order flagged Spanish yielded only matching Spanish site URLs on both sites. The manual RequestLifecycle Spanish email similarly had no English footer duplication and no unapproved payment link.
- Two separate synthetic brand-preview messages were submitted once to the earlier authorized QA inbox; FluentSMTP logged both as sent on November 11, 2026. SMTP acceptance does not verify actual Gmail inbox display.
- Wayfa has 23 registered Woo mail types including disabled types. Its two currently configured Fluent Forms notification records (forms 2 and 12) were disabled and remain disabled. Form 6 uses the separately governed original RequestLifecycle flow.

## Not claimed

- Native Gmail Mobile or Microsoft Outlook visual inspection is not confirmed: Chromium viewport QA checks layout, not the Gmail/Outlook proprietary renderers. Outlook Word rendering must be checked in an Outlook client before claiming final compatibility.
- Some company-specific transport, dynamic content, and authorization issues remain outside this presentation-only release. Original318/313 composite authority remains HOLD; this change does not mint its receipts, approve security gates or authorize automatic Stripe live payments.
- Earlier failed lifecycle jobs have not been resent or deleted.
- The bridge-only source manifest and isolated provenance verifier are provided separately and fail closed if content/approval bits drift. The original five-file branded email V2 source manifest remains independently applicable.
