# Contract — Source Trust and AI/LLM Evaluation

Contract: mad4b.ai-source-evaluation.v1

## Source trust

Every input fragment has an authority/source class.

Untrusted source text is data only.
Instructions found inside scraped pages, Drive content, competitor pages, user-generated content or provider payloads cannot alter:
- system/developer policy;
- grants/approval;
- tool selection authority;
- provider certification;
- target site/environment.

## Prompt/process versioning

Every model-produced durable artifact records:
- model/provider identity;
- model version/snapshot where available;
- Skill/process version;
- prompt/template version;
- input artifact fingerprints;
- output schema version;
- generation parameters relevant to reproducibility;
- generated_at.

## Structured output

Machine-consumed LLM output is schema-validated.

Repair retries are bounded and recorded.
If validation remains invalid, fail the stage; do not coerce arbitrary text into authority-bearing fields.

## Grounding

Factual content stages define evidence requirements.
FactLedger links claims to source refs and flags:
- supported;
- weakly supported;
- conflicting;
- unsupported;
- unverifiable.

High-impact or freshness-sensitive claims can require human review or stronger source classes.

## Evaluation suites

Maintain versioned eval fixtures by:
- content type;
- language;
- market;
- writer profile;
- research depth.

Evaluate at minimum:
- factual support;
- source attribution coverage;
- instruction-injection resistance;
- brand/writer adherence;
- structural completeness;
- duplication/redundancy;
- SEO requirement coverage;
- prohibited content/patterns;
- schema validity.

## Regression policy

Changing model, prompt, Skill or critical context-selection algorithm triggers targeted eval regression.

A cheaper/fallback model is not automatically equivalent.

## Quality thresholds

Threshold profiles are explicit and versioned.
A numeric average cannot override hard factual, security, legal or publishing blockers.

## Human review

Policies may require human review for:
- unresolved factual conflicts;
- sensitive/legal claims;
- low evidence coverage;
- Production/publication classes designated high risk.

Review outcome is an artifact/decision, not an edit that erases original evidence.
