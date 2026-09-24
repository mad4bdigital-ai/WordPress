# Quickstart — Feature 007

Branch: spec/007-content-intelligence-workflow-platform-20260924

This is a specification branch based on the current rc.59 integration candidate at feature inception. It is not a Production release and authorizes no runtime mutation.

## Read order
1. constitution.md
2. spec.md
3. research.md
4. data-model.md
5. contracts/architecture-boundaries.md
6. contracts/release-lineage.md
7. contracts/workflow-provider.md
8. contracts/provider-certification.md
9. contracts/content-job.md
10. contracts/context-dispatch.md
11. contracts/research-provider.md
12. contracts/artifact-and-quality-gates.md
13. contracts/host-connector.md
14. plan.md
15. tasks.md
16. traceability.md
17. runbook.md

## First execution
Do not start Content OS runtime code first.

Phase 0:
- reconcile PR #45-only commits against PR #47;
- require REQUIRED=0;
- freeze final rc.59;
- deploy exact Staging artifact;
- run live acceptance;
- canonicalize rc.59.

## First provider work
After canonicalization:
- inspect exact installed Bit Flows package;
- certify reads;
- certify run-flow canary under exact workflow/plan fingerprints;
- prove native MCP is not an unmanaged privileged path.

Do not downgrade only to match 1.24 certification.
Do not auto-upgrade only because upstream has a newer version.

## First Content OS vertical slice
Use ETG as a validation profile, not the generic schema.

Flow:
1. create ContentJob;
2. resolve knowledge requirements;
3. build ContextPack;
4. select exact WriterProfile;
5. collect keyword/SERP research;
6. select/scrape competitors;
7. build coverage matrices and InformationGainPlan;
8. build ContentBlueprint;
9. write ArticleDraft;
10. Fact/Editorial/SEO QA;
11. MediaManifest + PublishManifest;
12. create/update WordPress draft through existing governed abilities;
13. verify readback;
14. stop before public publish unless separately approved.

## Never do
- second privileged Bit Flows MCP gateway;
- direct Bit Flows table writes as shortcut;
- vendor-specific fields in ContentJob;
- raw Drive-folder context injection by default;
- host SSH inside WordPress filesystem authority;
- QA average overriding hard blockers;
- large runtime merge before Phase 0 closure.

## Governed tooling quickstart

For host/CLI work, read:
1. contracts/governed-tool-execution.md
2. contracts/cli-host-runner.md
3. contracts/host-connector.md
4. references/host-provider-validation-profile.md

First implementation proof:
- expose one read-only `wp mad4b diagnostics ...` command;
- expose the same semantic diagnostic through MCP;
- prove normalized output equivalence;
- run the same operation through a Host Runner;
- prove no WordPress Write/Developer grant implicitly creates Host Execution;
- prove no arbitrary command text is accepted.

Do not automate Hostinger Terminal text as the platform API. Map provider/API/CLI/runner channels to semantic operations instead.
