# Safe Tour Rate Season inheritance — source review, 21 November 2026

Scope: native shared Product Core Rate date eligibility, used by Planner,
Form 6, Booking V2 and pricing policy. No new database column, taxonomy,
per-company parallel season registry, published Rate, invoice or booking.

## Native rules

- Complete custom Rate dates (UTC epoch or valid ISO date) take precedence.
- Partially specified, reversed, or invalid dates fail closed, not Season fallback.
- If both custom dates are absent, inherit exactly ONE assigned Season term.
  The existing MAD4B_JetEngine_Rate_Season_Term_Matcher_V1::window() resolves
  enabled term meta and the verified WPML default-language calendar mapping.
- ONE effective native window must cover the entire booking interval.
  Two separate winter instances of the same term cannot cover the summer gap.
- Missing/ambiguous term, missing matcher, invalid date or out-of-season: block.
- The original single-date helper remains; both existing Rate Planner
  selection and reuse now share the bounded full-window helper.

## Actual evidence

- 22/22 hermetic PHP positive/negative checks PASS: custom precedence, partial
  override, malformed date, cross-year winter, missing/ambiguous policy,
  out-of-season use and disjoint winter windows.
- Both original Planner call sites use the full-window helper.
- PHP syntax for both changed source files passes.
- Existing Commercial Source Intake nonregression fixture passes.
- CI workflow retains exact pull-request head checkout and now runs this
  hermetic PHP fixture on the original source-regression route.

Draft source review only: no WordPress live deployment, Site or pricing mutation,
commercial cost receipt, rate publication or owner release approval.
