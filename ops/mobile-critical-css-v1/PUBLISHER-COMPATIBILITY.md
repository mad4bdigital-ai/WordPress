# First-party mobile CSS publisher — runtime capability contract

The original publish.py publishes only fully browser-verified nonadaptive
Critical CSS variants. The original verify.js can also run an opt-in
MAD4B_CSS_ADAPTIVE_MAX experiment, using Critical CSS below the enrolled
mobile breakpoint and native full CSS above it.

An Adaptive QA pass is not a code release. Until the live consumer supports
the breakpoint through a separately signed original company code admission,
the publisher must explicitly record ADAPTIVE_RUNTIME_CAPABILITY_NOT_ENROLLED.
It may never drop that adaptive field and accidentally publish all-width CSS.

Ordinary EN/ES high/preload browser evidence remains supported. A company
with a v1-only consumer must first receive a v2-compatible native PHP engine
through original code governance. Dry-run alone is not runtime compatibility.
Retain backups and invalidate only verified public home FastCGI paths.

The publisher does not create grants, change Original313, enable public HTML
geo caching, or authorize financial/customer-data writes.
