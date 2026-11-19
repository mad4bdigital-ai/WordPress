# Automatic public listing discovery (safe, read-only)

The discovery worker reads published WPML languages, WCML currency configuration,
public pages in rotating batches, nested Elementor templates and their JetEngine
terms-listing references. No locale, widget, query, page, or listing ID is embedded
in the discovery algorithm.

Each run samples real SSR page layouts at 390px and 1920px. It classifies
current certified contexts, newly eligible contexts requiring browser parity,
low-volume grids for which SSR is the correct default, and conditional widgets
that are not rendered. The queue is saved in server-side reports.

Discovery does not issue new certificates or change public page rendering.
A separate signed native AJAX QA must independently pass seven responsive
viewports before any unapproved context becomes eligible for automatic Lazy.
A configured WCML currency is not proof of a reproducible market context.

2026-11-16 source evidence: 91 published pages, six published language homepages,
12 discovered conditional listing contexts, two certified contexts, eight
low-volume SSR contexts, one hidden conditional widget, and one Italian
candidate requiring a complete signed browser certificate. Italian passed
six viewport samples; the seventh (1440px) was not certified. No incomplete
proof has been installed.

This worker is scoped to Tours.dev Staging. Wayfatours Production is unchanged.
