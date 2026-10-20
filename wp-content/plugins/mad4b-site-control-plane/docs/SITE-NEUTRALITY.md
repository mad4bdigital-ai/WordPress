# Site-neutral runtime and CI

The Control Plane derives origin/environment from the enrolled Site Profile and discovers post types, taxonomies and metadata through WordPress. A shop, school, professional service or other business does not need a tourism plugin, tourism taxonomy, or a named pilot domain.

## Elementor

elementor/set-dynamic-tag copies an exact existing source binding. The installed manager must parse and canonically round-trip that binding, its tag must be registered, and the site filter mad4b_scp_elementor_source_tag_allowed must return exactly true. Unregistered names, name spoofing in settings, concatenated bindings, missing managers and oversized strings fail closed. Source/target SHA checks, provider certification, permissions, exact approval and rollback remain required.

The existing elementor/set-etg-dynamic-tag identifier is a compatibility API for an optional named provider, not a business model requirement. Its legacy settings are isolated under includes/integrations/; its writer is registered only when that provider's installed identity exists. Generic source-copy operations do not use those settings or require that provider.

## Public HTTP evidence

tools/staging-public-http-smoke.py has no embedded target sites. The workflow reads owner-configured repository variable MAD4B_STAGING_PUBLIC_PROBE_TARGETS_JSON, for example:

    [
      {
        "id": "shop-preview",
        "environment": "staging",
        "origin": "https://preview.example.org",
        "routes": [["home", "/"], ["catalog", "/products/"]]
      }
    ]

Replace the example with the independently verified public Staging origin. Empty configuration performs zero requests and reports NOT_CONFIGURED. This is configuration for bounded external observation, not proof of WordPress enrollment/environment or an authorization grant.

The whole target batch validates before any I/O: unique safe IDs, exact HTTPS origins, Staging label, at most five targets/twenty total routes, and no credentials, ports, admin routes, queries, fragments or path escapes. DNS answers must all be public; the connection pins the validated address while retaining TLS hostname verification. Requests are GET-only, carry no cookies/authorization and do not follow redirects. HTTP failures remain observations, never live acceptance or certification.

## Cache/WAF diagnostics

Core passive diagnostics use MAD4B_SCP_EXTERNAL_WAF_RATE_LIMIT_VERIFIED. A provider-specific constant does not control core evidence. This setting remains an operator assertion, not proof of atomic counters, distributed rate limiting, or a grant.

## CI protection

Site-neutral plugin contracts runs on PHP 7.4, 8.3 and 8.5. It runs tenant-neutral source checks, retail/education/service tag and HTTP fixtures, dynamic content/environment contracts and cache diagnostics.

The source guard scans includes/config/assets. It blocks pilot tenant domains, unreviewed literal hosts (including future client domains), fixed tourism defaults, named tag lists outside the optional integration, and the old provider WAF dependency. Fixed vendor/service endpoints and reserved examples are scoped to an explicit per-file reviewed inventory. Adding an endpoint requires deliberate inventory review; this inventory never authorizes network access or site operations.

Self-tests inject forbidden defaults to prove the guard fails, including new domains in an already-reviewed provider file. Optional provider API names and historical fixtures/reports are retained where they identify a real integration or evidence. CI is not proof of compatibility with every third-party plugin or live acceptance on every business website.
