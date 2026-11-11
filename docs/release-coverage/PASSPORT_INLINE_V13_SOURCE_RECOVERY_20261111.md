# Passport Search inline v1.3 — additive live-source recovery

Tours.dev Staging, 2026-11-11; isolated local Git only.

Two previously captured assets were changed again on the source site. The current inline Passport Search JS now reads its initial list from the **existing 195 Fluent Forms #6 native select options**. Search is normalized and filtered locally first. Requests for unfamiliar spellings retain the bounded AJAX fallback; the selected value is still applied to the original native select and dispatches the usual input/change signals. Duplicate AJAX responses are invalidated by the existing sequence/abort logic.

The CSS change is a scoped desktop first/last-name alignment fix inside the same Fluent Form #6. No form schema, saved country option, price, payment credential or company content was modified. The exact updated file hashes appear in passport-local-lookup-source-v13-20261111.json and the original 533-file live snapshot manifest.

Acceptance performed: JavaScript syntax parsing, five source tests (including original native value preservation, local-first lookup and bounded server fallback), and exact SHA-256 readback to the source filesystem.

Still unverified: a real mobile/browser keyboard and touch journey, Fluent Forms submit and native form confirmation. This source-only copy is not a site deployment. Original318/313 and separate company release approval remain HOLD.
