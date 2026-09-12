# Alpha10 JetEngine Query Identity Contract

## Purpose

Alpha10 hardens the boundary between JetSmartFilters route authority and JetEngine Query Builder internal storage identity.

A configured/profile route `query_id` is a JetSmartFilters **custom Query ID**. It MUST NOT be passed to an API that resolves JetEngine's internal numeric Query Builder record ID.

## Authoritative resolution

All route-identity consumers MUST use `ETG\DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver`.

The resolver MUST:

1. read Query Builder inventory through `Jet_Engine\Query_Builder\Manager::instance()->get_queries()`;
2. compare the requested route identity only with each query object's `query_id` property after the existing `sanitize_key()` normalization;
3. return the matched object's internal `id` as evidence only;
4. never compare, coerce or fall back from the requested custom Query ID to internal `id`;
5. fail closed when inventory is unavailable, no exact custom-ID match exists, or more than one exact custom-ID match exists.

Required failure reasons:

- `missing_query_id`
- `query_identity_inventory_unavailable`
- `query_identity_not_found`
- `query_identity_ambiguous`

A numeric-looking custom Query ID such as `123` remains in the custom-ID namespace. If internal ID `123` belongs to a different custom Query ID, that record MUST NOT be selected.

## Shared consumers

One resolver instance is created by Bootstrap and injected into:

- `Runtime\PostTypeObserver`
- `SEO\JetEngineResultCountAdapter`
- `SEO\PublicationResultCountProbe`

Those consumers MUST continue exposing/observing the custom Query ID as provider/profile authority. `internal_query_id` is diagnostic evidence only.

The three consumers MUST NOT call `get_query_by_id()` for route identity resolution.

## Safety boundary

Identity resolution does not enable a profile, approve a route, approve a filter combination, enable the Global bridge, emit Rank Math metadata, or include a URL in the sitemap.

Global bridge remains OFF by default and the built-in Tours profile remains disabled. Missing or ambiguous identity evidence can only deny authority.

## Acceptance

Static/PHP acceptance requires:

- PHP 7.4 and current lint;
- unique custom-ID resolution;
- missing custom-ID fail-closed behavior;
- duplicate custom-ID fail-closed behavior;
- numeric-looking custom-ID namespace separation;
- invalid inventory fail-closed behavior;
- static proof that the three route consumers use the shared resolver and do not use `get_query_by_id()`.

Real WordPress runtime evidence is still required before any Production activation decision, including the exact Tours archive custom Query ID, bounded Post Type evidence, provider observation and result-count parity.

`merge_authorized=false`

`production_activation_authorized=false`
