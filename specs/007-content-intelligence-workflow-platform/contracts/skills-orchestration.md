# Contract — Skills Orchestration

Contract: mad4b.skills-orchestration.v1

## Role
Skills provide reusable reasoning/orchestration recipes above capabilities and artifacts.

Skills are not:
- credential stores;
- provider certification;
- grants;
- approval systems;
- mutation shortcuts;
- long-lived source-of-truth state.

## Inputs
A Skill consumes references to:
- ContentJob;
- current artifacts;
- provider availability/certification;
- policy;
- user/operator instruction;
- bounded context.

## Outputs
A Skill may produce:
- a plan;
- typed artifact;
- quality-gate recommendation;
- exact capability request;
- workflow definition/plan;
- clarification/blocker reason.

## Rules
- every mutation still uses exact MAD4B capability;
- every provider operation remains adapter-bound;
- Skills must use artifact IDs/SHAs rather than hidden mutable scratch state for durable steps;
- Skills are versioned;
- changing a Skill version is recorded in produced artifact metadata;
- Skills can be provider-neutral and selected by domain stage.

## Suggested domain Skills
- content-intake
- knowledge-dispatch
- writer-profile-distill
- research-plan
- keyword-research
- serp-research
- competitor-selection
- competitive-analysis
- information-gain
- blueprint
- blueprint-review
- section-writer
- fact-check
- editorial-review
- seo-review
- media-plan
- publishing-plan
- growth-review

## Workflow relationship
Bit Flows/n8n/native workflow engines may schedule or connect Skills, but workflow mechanics do not inherit the Skill's requested mutation authority.
