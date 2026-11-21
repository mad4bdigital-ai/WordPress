# Host v137 — Follow the signed-in WordPress administrator's language

## User report and root cause

The live v136 Staging Admin rendered Arabic UI and forced RTL even when
the user's WordPress Administration interface displayed English. The v136
presentation adapter hardcoded Arabic labels, Arabic date formatting and
pane.dir=rtl. This is not driven by WordPress user's profile language.

## Exact contract

- Use WordPress authenticated get_user_locale() only, after confirming
  existing exact Staging owner/admin capabilities and site binding.
- Do not derive UI locale from WPML language switcher, public-site get_locale,
  browser language, URL query, currency or nationality.
- Per-user only: two authorized admins viewing same site can have
  different UI languages without changing site options or permissions.
- Supported user locales: English en, Arabic ar, French fr, German de,
  Spanish es, Italian it. Unsupported user locale -> English.
- Arabic uses RTL; English, French, German, Spanish and Italian use LTR.
- Use user's selected locale for date/time presentation (e.g. en-US,
  ar-EG, fr-FR). The locale is re-evaluated on each page load.
- All top-level labels, messages, steps, next actions, pending/expired
  requests, copy feedback, incomplete authority messages and common REST
  errors use one 49-key per-language dictionary. Technical REST errors may
  retain a machine-readable code, never a forced English server message.
- Persisted request IDs, hashes, state codes, REST paths, exact confirmation
  REQUEST REMOTE V3 OWNER REVIEW, Admin page slug and cryptographic
  contracts must not be translated.
- Preserve v136 owner-specific request recovery, one action button,
  advanced disclosure, and non-authorizing read-only behavior. No new WP
  options, Root signer, Original305/312/313 changes, enrollment or grants.
- v134/v135 raw diagnostics remain in the optional advanced technical
  section and their protocol contracts remain byte-exact.

## Tests and deployment

Tests execute the real PHP with WordPress stubs, simulate seven locales
(en_US, ar_EG, fr_FR, de_DE, es_ES, it_IT, ja_JP), execute the embedded JS
with Node, and verify translations, RTL/LTR, status, and REST errors. v136
regressions preserve request ownership and permissions.

Deploy only the changed v136 430 MU presentation plugin on Tours.dev
Staging with exact Git source SHA and protected v136 preimage. PHP lint,
root immutable protection, WordPress Admin registration, signed read-only
Host Runner current, home HTTP 200 and guest REST 403 must remain true.
Roll back exactly the preimage if verification fails. No Production or Host
Write mutation.

An English WordPress Profile language must show English even when public
WPML language is different. Browser validation uses real authorized sessions
rather than synthetic owner cookies.
