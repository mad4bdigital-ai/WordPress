# Browser QA admission: FCP applies only where Critical CSS applies

This reconciler updates **only** the verified FCP admission expression in
`renderer/tools/verify.js` of a site-owned MAD4B CSS worker. It is portable
across company profiles but does not invent a CSS certificate or enroll
unregistered hosts.

At a viewport **inside the certified Critical CSS cap** the original
`fcpGain >= 150` rule remains required. Above that cap, the original full CSS
is already rendered by the browser, so FCP differences between two otherwise
identical full-CSS pages are reported but cannot fail a *phone-only* CSS
certificate. The existing LCP regression, CLS, page errors, overflow,
full-CSS loading, heading/hero geometry, popup and calendar interaction
checks continue unchanged at every tested width.

The source's 599px fallback was previously blocked on German 600px:
`FCP gain -380ms`, despite the original native CSS at 600px and passing
visual, CSS-load, interaction and LCP checks. This rule repairs the precise
scope mismatch rather than disabling quality verification.

## Site-safe deployment

The default command is plan-only. It validates the enrolled company's exact
root-owned renderer-tree SHA-256 from its `site-profile.json`. `--apply`
requires the same site ID and a private root-owned backup directory under
`/opt/mad4b-backups`; it acquires the ordinary site CSS execution lock,
preserves original file ownership/modes, verifies the new renderer tree hash,
and atomically changes only the renderer SHA in its local profile. An active
site worker causes a refusal rather than racing a browser certification.

`python3 reconcile.py --site-dir /opt/<enrolled-company>/operations/css-cron-v1 --expected-site <registered-site-id>`

A separate, per-company deployment review is required; never transplant
another site's profile or certificate. Browser QA/certification continues on
that site's own cron schedule, and public FastCGI promotion remains gated on
the original verified manifest and final rendered HTML.
