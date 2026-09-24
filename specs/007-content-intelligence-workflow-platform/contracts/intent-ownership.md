# Contract — Intent Ownership and Cannibalization Model

Contract: mad4b.intent-ownership.v1

## Relationship

Intent ownership is many-to-many, not a global one-intent/one-page lock.

An intent can relate to multiple content items with roles:
- PRIMARY_OWNER;
- SUPPORTING;
- INFORMATIONAL_OWNER;
- TRANSACTIONAL_OWNER;
- LOCALIZED_OWNER;
- COMPARISON;
- FAQ_SUPPORT;
- HISTORICAL/RETIRED.

A content item can participate in multiple intents.

## IntentRelation

Fields:
- relation_id;
- intent_id;
- content_id;
- site/locale/market;
- role;
- confidence;
- evidence refs;
- valid_from;
- valid_to optional;
- source: observed|operator|model|imported;
- revision.

## Conflict analysis

Cannibalization is a derived analysis, not a fact implied by multiple relations.

Analyzer considers:
- same locale/market;
- intent similarity;
- role compatibility;
- SERP evidence;
- canonical/indexability;
- query/page performance where available;
- page purpose/content type.

Outcomes:
- NO_CONFLICT;
- HEALTHY_SUPPORT;
- POSSIBLE_OVERLAP;
- CANNIBALIZATION_RISK;
- HUMAN_REVIEW.

## Planning

Content Planner cannot treat inferred intent ownership as absolute when confidence/evidence is weak.
Low-confidence conflicts lead to review or conservative update/support decisions.

## History

Intent relations are versioned so ownership can change as the site/search market evolves.
