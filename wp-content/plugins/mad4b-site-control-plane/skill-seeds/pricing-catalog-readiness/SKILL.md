---
name: pricing-catalog-readiness
description: "Verify tour prices, Tour Rate precedence, language/market filters and archive conversion prices against real quote evidence across MAD4B companies."
---

# MAD4B — Pricing Catalog Readiness

This Skill is company-scoped, advisory and read-first. It does not add WordPress permissions, MCP abilities, payment powers, API secrets or arbitrary shell authority.

## Procedure

1. Resolve the canonical tour, company, locale, market, currency, selected date, occupancy and tour mode; never assume all tours use Tour Rates.
2. Identify authoritative direct price versus Tour Rate, inherited policy versus override, supplier inputs and rate generation versus final quote.
3. Inspect accommodations and options for multi-stage tours, adult/child/infant pricing, seasonal validity and occupancy applicability.
4. For archives, compare JetSmartFilters currentQuery/result membership with rate-aware minimum-price REST evidence. Unknown or unsupported filters must hide the price teaser, not show an unrelated global lowest rate.
5. Validate WCML currency and price display for each language; reject zero/stale/uncertified quote evidence.
6. Inspect original Elementor/JetEngine presentation and WooCommerce downstream invoice separately, including tax-source ownership and readback.
7. Return a matrix per tour × market × date × occupancy: quoted amount, source, eligibility, publication readiness, UI and unresolved gaps.

## Result contract

Return the exact company/environment, selected native data sources, operation owner, evidence freshness, proposed action, authorization status, verification method and reversible rollback path. Unknowns remain explicit.
