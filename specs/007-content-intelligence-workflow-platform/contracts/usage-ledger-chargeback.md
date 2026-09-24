# Contract — Usage Ledger, Quotas and Economic Chargeback

Contract: mad4b.usage-ledger.v1

## Purpose

Attribute platform/provider/model/storage usage to job/site/tenant for budget enforcement, reconciliation and optional internal chargeback.

## Usage event

Fields:
- usage_event_id;
- tenant/site/job;
- capability/stage;
- provider/model;
- quantity/unit;
- provider-reported cost optional;
- internal rate-card version optional;
- currency;
- occurred_at;
- correlation/request ID;
- source evidence;
- usage_sha256.

Events are append-only or corrected by explicit adjustment entries.

## Units

Examples:
- model tokens/requests;
- SERP/search calls;
- scrape pages/bytes;
- workflow executions;
- storage GB-day;
- image/media generation;
- host/API operations.

## Budgets

Budgets can be:
- hard stop;
- soft warning;
- approval threshold.

Scope:
- job;
- stage;
- site;
- tenant;
- provider;
- day/month/custom window.

## Reconciliation

Provider invoices/usage reports MAY be reconciled against the internal ledger.
Differences are explicit and do not rewrite historical events.

## Rate cards

Internal chargeback rate is versioned and independent of provider raw pricing.

## Privacy

Usage ledger avoids storing prompt/content payloads when IDs/counts suffice.

## Fairness

Usage/quota data feeds scheduling but never creates authority.
