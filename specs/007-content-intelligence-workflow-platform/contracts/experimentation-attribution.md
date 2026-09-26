# Contract — Experimentation and Attribution

Contract: mad4b.experimentation.v1

## Purpose

Support controlled content/UX experiments without turning experimentation into uncontrolled canonical/SEO mutation.

## Experiment

Fields:
- experiment_id;
- site/tenant;
- hypothesis;
- owner;
- eligible population/pages;
- variants;
- assignment method;
- start/end;
- primary metric;
- guardrail metrics;
- stopping policy;
- statistical/decision method;
- content/SEO restrictions;
- status.

## Variant identity

Every variant has an immutable artifact/config fingerprint.

Exposure logs bind user/session/cohort according to privacy policy without requiring personal identity where unnecessary.

## SEO safety

For publicly crawlable content:
- canonical/indexability strategy is explicit;
- experiments MUST NOT accidentally create duplicate indexable URLs or conflicting canonicals;
- server/client rendering behavior is verified;
- search-engine-sensitive experiments may require a dedicated policy or be prohibited.

## Attribution

Outcome analysis records:
- exposure definition;
- observation window;
- exclusions;
- data completeness;
- metric version;
- uncertainty/limitations.

## Automation

Experiments may be proposed automatically but activation requires configured authority.

Winning variant does not auto-publish permanently without a promotion plan and normal quality/publishing gates.

## Interaction

Concurrent experiments declare exclusion/interaction groups where interference would invalidate interpretation.
