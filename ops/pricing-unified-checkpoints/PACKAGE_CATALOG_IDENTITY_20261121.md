# Package catalog identity checkpoint — 21 November 2026

## Diagnosed failure mode
The prior report of **0** Tours.dev Packages was not valid evidence of an absent catalog. The native `MAD4B_Package_Pricing_Lab_Readiness_V1::packages()` method restricts candidates through `current_user_can('edit_post', $tour)`. Running WordPress CLI without `--user` produces ID 0 and therefore **0 Packages**, even if the source meta has the full dataset. Do **not** interpret that result as empty company inventory.

A historical zero was recorded in an earlier check, but do not assert that an unauthenticated CLI context was the only possible cause for that prior reading: the sites have concurrent reconciliations. The currently observed behavior is reproducible.

## Live evidence (read-only)
| Context | Source plan meta candidate records | Authorized editable canonical Packages | Package Levels |
| --- | ---: | ---: | ---: |
| Tours.dev, WordPress administrator ID 1 | 8 | 8 | 3 |
| Tours.dev, QA user `lab_jetengine_qa` ID 25 | 8 | 8 | 3 |
| Wayfatours, authorized administrator ID 1 | 16 | 8 | 3 |
| Either site, WP-CLI ID 0 | 8/16 raw | 0 **(not a valid inventory check)** | 3 |

Production's 16 meta rows include 8 WPML language aliases, resolving to the same 8 canonical English Package IDs. Expected canonical IDs: `57562,60437,60439,60441,60443,60623,60627,60629`.

For `57562` on **both sites**, the read-only `inspect()` completes for Silver `2353`, Gold `2354`, and Diamond `2356`, each with 3 stage records and no errors.

## Repeatable, no-write acceptance
Use `tests/verify-package-catalog-identity-readonly.php` with the original WP-CLI and a real authorized WordPress user:
```sh
MAD4B_EXPECTED_PACKAGE_IDS=57562,60437,60439,60441,60443,60623,60627,60629 \
  wp --user=lab_jetengine_qa eval-file ops/pricing-unified-checkpoints/tests/verify-package-catalog-identity-readonly.php
```
Use a legitimate pricing-authorized user on each site; the script refuses anonymous invocation with `PACKAGE_CATALOG_NOT_CHECKED`, instead of returning a false zero. The optional expected-ID set ensures all eight cohort Packages are returned. The test is read-only and never accesses prices, customer data or login cookies.

## UI
When `packages.length === 0`, distinguish an **empty list of editable tours with valid saved plans for the signed-in user** from genuinely missing Package Level taxonomy terms. Do not copy data between sites or relax `edit_post` permissions to make a diagnostic turn green.

## Non-goals
This checkpoint does not approve supplier costs, Rates, bookings or commercial readiness. It does not fabricate a signed-in browser Save certificate. Source price revision was unchanged at `fa4c09e6816a` (Staging) and `3cdd6aaa2188` (Production) in the live checks.
