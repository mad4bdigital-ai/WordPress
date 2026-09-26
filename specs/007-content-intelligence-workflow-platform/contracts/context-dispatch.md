# Contract — Knowledge Dispatch and Writer Profiles

Contract: mad4b.context-dispatch.v1

## Goal
Turn governed source assets into bounded job-specific context without coupling jobs to Google Drive or raw folders.

## JobRequirementsResolver
Input:
- ContentJob
- site/brand profile
- content type
- stage
- policy version

Output:
- required knowledge classes
- conditional knowledge classes
- exclusions
- freshness requirements
- language/site constraints
- requirements_sha256

## Knowledge classes
Examples:
- brand.core
- brand.positioning
- audience.primary
- voice.language
- seo.strategy
- product.knowledge
- service.knowledge
- destination.knowledge
- destination.blueprint
- pricing
- evidence
- legal.compliance
- writer.profile

Registry is extensible.

## KnowledgeDispatcher
- queries Context Authority;
- selects eligible assets;
- enforces site/brand/language/review state;
- never reads arbitrary source folders by job request;
- emits ContextPack.

## ContextPack
Contains:
- requirements SHA
- source_id
- asset_id/version
- knowledge_class
- review/authority state
- fingerprint
- content reference/excerpt
- missing required classes
- pack SHA

ContextPack is immutable by version.

## Invalidation
When required source version/authority changes:
- create new ContextPack;
- compare SHA;
- invalidate dependent gates/artifacts only when effective context changed.

## WriterProfile
Separate reusable identity with immutable versions.

WriterProfileVersion captures:
- language
- sentence structure
- paragraph density
- rhythm
- opening/argument/narrative style
- evidence usage
- vocabulary preferences
- do rules
- do-not rules
- source asset refs
- distillation process/model
- profile SHA

Jobs pin exact WriterProfile version.

Raw writer corpus is not required on each job if an approved distilled profile is sufficient.
