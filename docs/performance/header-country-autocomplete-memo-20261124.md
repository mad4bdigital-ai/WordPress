# Header market-country autocomplete: cache locale names within one page

On Wayfatours Tour #60623, each header copy exposes 250 country choices plus its placeholder. The previous first-party autocomplete rebuilt and translated the full native `select.options` list on every keystroke, including repeated `Intl.DisplayNames` calls and repeated Unicode normalization.

## Bounded fix
- In each individual enhanced header form, cache the ISO2/name/normalized-name inventory once per current locale and option count.
- Recompute if locale changes, option count changes, or another component dispatches `country-search:catalogue-updated`.
- Keep the actual native `<select>` options, submit values, Woo catalogue, WPML, visitor market state, price eligibility, nonces and existing events completely unchanged.
- This is a UI-input efficiency fix **not** a DOM option-count reduction, pricing fix, or cure for full-page unresponsiveness.

## Tests
- `node --check` passed for the exact live candidate.
- An isolated browser A/B using one real native header form, 195 synthetic input events (15 cycles × 13 search strings), tested original/candidate/candidate/original: original 81.3 / 66.6ms; candidate 7.8 / 10.7ms. These are tight-loop DOM event benchmarks and **not** real typing INP or population gains.
- Both versions found “Egypt”, kept 251 native country options per copy, and did not submit preference changes.
- After source-first and Wayfatours rollout, mobile 390×844 and desktop 1366×900 Chromium: HTTP 200, two country search inputs, both native country selectors retain 251 options, both passport native selects remain, “Egypt” query resolves, Escape closes search, Form 6 visible, no page errors.
- Exact original live SHA256 on both sites: `4f17fcbd8707d25d0c31465dfb5ec5221aa752a06c208cbea143700847b31a27`.
- Exact new live asset SHA256 on both sites: `46cbc239f9a040b881b8005ee19409bc8cc53f0871db3ff69a5e973ef80eb3f2`.

## Backups and release
- `/opt/mad4b-backups/header-country-search-catalog-20261124/source/original.js`
- `/opt/mad4b-backups/header-country-search-catalog-20261124/wayfa/original.js`

Verify backup hash before any isolated rollback; restore only this asset, recheck web-served SHA and keyboard/search behavior. Source code was reconciled into this Draft PR because the path was missing on the reviewed master snapshot. Owner release attestation still applies.

## Open issues
- 1,004 native option nodes in duplicate header country/passport copies remain. Do not silently delete or defer those authoritative country choices without an SSR/no-JS, keyboard, form-submission, identity/market synchronization and WPML browser proof.
- Elementor template 24784, Mega Menu, dynamic JetEngine and authenticated browser freeze remain separate performance work.
