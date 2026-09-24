# Contract — Content Job

Contract: mad4b.content-job.v1

## Identity
ContentJob is a durable, site-scoped business-domain object.

Required:
- job_id
- site_uuid
- brand_id
- subject
- language
- country/market
- content_type
- state
- stage
- revision

Optional:
- primary_keyword
- writer_profile_id/version
- research_depth
- automation_level
- target_post_type/id
- desired_publish_at

## State
Allowed lifecycle:
- NEW
- QUEUED
- RUNNING
- WAITING_REVIEW
- BLOCKED
- FAILED
- COMPLETED
- CANCELLED

State answers "what is the lifecycle condition?"

## Stage
Initial stage registry:
- INTAKE
- SITE_DISCOVERY
- KNOWLEDGE_DISPATCH
- KEYWORD_RESEARCH
- SERP_RESEARCH
- COMPETITOR_SELECTION
- SCRAPING
- COMPETITOR_ANALYSIS
- INFORMATION_GAIN
- BLUEPRINT
- BLUEPRINT_QA
- WRITING
- FACT_QA
- EDITORIAL_QA
- MEDIA
- SEO
- FINAL_QA
- DRAFT
- SCHEDULING
- PUBLISHING
- POST_PUBLISH

Stage answers "what work is happening?"

## Rules
- state and stage are independent;
- transitions require optimistic revision;
- every transition appends JobEvent;
- cancellation stops future work but does not erase evidence;
- retry resumes explicit checkpoint;
- completed/cancelled history is immutable;
- target site/tenant cannot be changed by workflow mechanics;
- provider-specific fields are forbidden in the core job schema.

## Generic abilities
Read:
- content-job-list
- content-job-get
- content-job-events

Write:
- content-job-create
- content-job-transition
- content-job-cancel

Abilities use existing MAD4B authorization/audit rules.
