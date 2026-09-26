# Contract — Localization, Accessibility and Internal Link Graph

Contract: mad4b.localization-accessibility-linkgraph.v1

## Localization cluster

A content identity may have locale variants linked by:
- cluster_id;
- source/master variant optional;
- locale/language;
- market;
- translation/transcreation relation;
- canonical policy;
- hreflang relation;
- translation status;
- source/target artifact versions.

Translation is not assumed to be literal; transcreation can use a separate blueprint while retaining lineage.

## Locale correctness

Normalize:
- language tags;
- locale-specific dates/numbers/currency;
- RTL/LTR direction;
- slug/path policy;
- punctuation/typography rules;
- translated taxonomies/entities where applicable.

Missing translation never silently falls back into an indexable wrong-language canonical.

## Accessibility QA

Configurable accessibility profile evaluates content/site output such as:
- heading hierarchy;
- meaningful link text;
- image alternative text policy;
- table/list semantics;
- language/direction attributes;
- media transcript/caption requirements where applicable;
- form/control labels when generated content includes them;
- duplicate/non-descriptive anchors;
- color/visual checks only where rendered-style evidence is available.

Accessibility hard blockers/warnings are profile-driven.

## Internal Link Graph

Maintain graph nodes for:
- content URL/object;
- entity/topic;
- locale variant;
- intent claim.

Edges include:
- internal hyperlink;
- canonical;
- hreflang;
- redirect;
- semantic/topic relation.

## Link recommendation

Recommendation considers:
- intent ownership;
- topical/entity relevance;
- locale;
- existing inbound/outbound distribution;
- anchor diversity;
- orphan risk;
- canonical/indexability status.

A recommendation is non-authorizing and must not create circular/irrelevant link spam.

## Publishing verification

Published link/hreflang/canonical expectations are checked by Publication Verification.
