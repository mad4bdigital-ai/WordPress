# Contract — Research Providers

Contract: mad4b.research-provider.v1

## Generic interfaces

KeywordProvider:
- keyword.expand
- keyword.metrics.read
- keyword.related.read

SERPProvider:
- serp.snapshot

SearchProvider:
- search.web

ScrapeProvider:
- scrape.page

One vendor MAY implement multiple interfaces.

## Request envelope
- provider_id
- operation
- job_id/correlation_id
- query/topic
- language
- market/location scope
- freshness
- limits
- budget
- request_sha256

## Response envelope
- normalized_data
- provider_id
- provider/API version
- collected_at
- source refs
- raw evidence ref optional
- usage/cost metadata
- warnings
- response_sha256

## Error taxonomy
At minimum:
- provider_unavailable
- authentication_unavailable
- rate_limited
- budget_exceeded
- timeout
- invalid_request
- no_results
- upstream_error
- blocked_by_policy
- normalization_failed

## Rules
- provider-specific payloads stay in adapters/evidence;
- pipeline consumes normalized data;
- research never grants publication authority;
- retries are budget-aware;
- freshness is explicit;
- scrape failure is not represented as empty successful content;
- legal/robots/policy constraints are configurable and evidence-backed;
- credentials never enter job/artifact ordinary payloads.
